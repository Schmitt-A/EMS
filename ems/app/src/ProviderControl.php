<?php
declare(strict_types=1);

/**
 * Der Stromanbieter plant das Laden des Autos in einem Zeitraum (etwa Octopus Intelligent: nachts günstig, Octopus
 * startet und stoppt das Auto über dessen API). Einstellungen in kv.provider (on, window tariff oder time, from, to)
 * und mapping.provider_boost (Schalter „Boost Charge“, Sofortladen beim Anbieter) und mapping.provider_control
 * („Smart Control“, ohne die es Boost Charge nicht gibt).
 * - Im Zeitraum gibt EMS die Wallbox mit vollem Strom frei (ChargePlan::effective(), Grund provider; der Speicher gibt
 *   wie beim Netzladen nichts ans Auto ab) und schaltet Boost Charge aus: Wann das Auto lädt, entscheidet der Anbieter.
 * - Außerhalb schaltet EMS Boost Charge ein, damit der Anbieter das Auto nicht sperrt und EMS mit Sonne regeln kann.
 *   Boost Charge kann beim Anbieter von selbst enden, etwa wenn das Auto voll ist; dann setzt EMS ihn bei angestecktem
 *   Auto alle 15 min wieder.
 * sync() schreibt nur bei aktivem EMS; Zustand, Fehler und Protokoll in kv.provider_state.
 */
final class ProviderControl
{
    public const STATE = 'provider_state';
    private const REASSERT_S = 900;

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    /** Einstellungen aus kv.provider und mapping. */
    public static function settings(array $cfg): array
    {
        $p = is_array($cfg['provider'] ?? null) ? $cfg['provider'] : [];
        $m = is_array($cfg['mapping'] ?? null) ? $cfg['mapping'] : [];
        return [
            'on' => !empty($p['on']),
            'window' => ($p['window'] ?? 'tariff') === 'time' ? 'time' : 'tariff',
            'from' => Tariff::clock($p['from'] ?? null) ?? '00:00',
            'to' => Tariff::clock($p['to'] ?? null) ?? '06:00',
            'boost' => (string) ($m['provider_boost'] ?? ''),
            'control' => (string) ($m['provider_control'] ?? ''),
        ];
    }

    /**
     * Liegt $ts im Zeitraum des Anbieters? Mit window tariff die Zeitfenster des statischen Tarifs fürs Auto (mit
     * dynamischem Tarif gibt es keine), mit time die Uhrzeit von bis, auch über Mitternacht.
     */
    public static function inWindow(array $s, array $tariff, int $ts): bool
    {
        if (!$s['on']) {
            return false;
        }
        if ($s['window'] === 'tariff') {
            return ($tariff['mode'] ?? 'static') === 'static' && Tariff::windowAt($tariff, $ts, 'car') !== null;
        }
        $hm = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');
        return $s['from'] < $s['to'] ? ($hm >= $s['from'] && $hm < $s['to']) : ($hm >= $s['from'] || $hm < $s['to']);
    }

    /** Der nächste Wechsel in oder aus dem Zeitraum ab $now, je Viertelstunde, höchstens acht Tage voraus. */
    public static function nextChange(array $s, Tariff $tariff, int $now): ?int
    {
        if (!$s['on']) {
            return null;
        }
        $inside = self::inWindow($s, $tariff->tariffAt($now), $now);
        for ($q = intdiv($now, 900) * 900 + 900; $q < $now + 8 * 86400; $q += 900) {
            if (self::inWindow($s, $tariff->tariffAt($q), $q) !== $inside) {
                return $q;
            }
        }
        return null;
    }

    /** Soll Boost Charge an sein? Außerhalb des Zeitraums ja; im Zeitraum nur, solange EMS selbst „Jetzt“ lädt. */
    public static function wantBoost(bool $inWindow, string $grid): bool
    {
        return !$inWindow || $grid === 'now';
    }

    public function state(): array
    {
        $state = $this->store->get(self::STATE, []);
        return array_merge(['want' => null, 'connected' => false, 'written_at' => 0, 'control_at' => 0, 'error' => null, 'error_at' => 0, 'log' => []], is_array($state) ? $state : []);
    }

    /**
     * Recorder (im Demo-Modus /api/live): Smart Control an, Boost Charge nach Zeitraum. Geschrieben wird beim Wechsel
     * des Zeitraums, beim Anstecken und, wenn der Schalter abweicht, höchstens alle 15 min; Boost Charge an nur bei
     * angestecktem Auto, das nicht fertig geladen ist.
     */
    public function sync(array $snap, int $now): array
    {
        $cfg = $snap['cfg'];
        $s = self::settings($cfg);
        $state = $this->state();
        if (!$s['on'] || $s['boost'] === '' || !Controller::active($cfg)) {
            return $state;
        }
        try {
            $index = $this->ha->index();
        } catch (Throwable) {
            return $state;
        }
        $tariff = new Tariff($this->store);
        $inside = self::inWindow($s, $tariff->tariffAt($now), $now);
        $want = self::wantBoost($inside, Energy::grid($cfg['charge']['grid'] ?? 'off')) ? 'on' : 'off';
        $connected = !empty($snap['vehicle']['connected']);
        $complete = Sessions::carState($snap['values']['wallbox_car_raw'] ?? null) === 'complete';
        $changed = $state['want'] !== null && $state['want'] !== $want;
        $plugged = $connected && empty($state['connected']);
        $state['want'] = $want;
        $state['connected'] = $connected;
        $waiting = (int) $state['error_at'] > 0 && $now - (int) $state['error_at'] < self::REASSERT_S;
        try {
            // Ohne Smart Control gibt es Boost Charge nicht: erst einschalten, Boost Charge in der nächsten Runde. Der
            // Anbieter bestätigt oft erst nach einer Weile; bis dahin höchstens alle 15 min neu.
            if ($s['control'] !== '' && ($index[$s['control']]['state'] ?? '') === 'off' && !$waiting) {
                if ($now - (int) $state['control_at'] < self::REASSERT_S) {
                    $this->store->put(self::STATE, $state);
                    return $state;
                }
                $this->turn($s['control'], true);
                $state['control_at'] = $now;
                $state['log'] = self::log($state['log'], $now, 'Smart Control war aus, eingeschaltet.');
                $state['error'] = null;
                $state['error_at'] = 0;
                $this->store->put(self::STATE, $state);
                return $state;
            }
            $current = (string) ($index[$s['boost']]['state'] ?? 'unavailable');
            $due = $changed || $plugged || $now - (int) $state['written_at'] >= self::REASSERT_S;
            $useful = $want === 'off' || ($connected && !$complete);
            if (in_array($current, ['on', 'off'], true) && $current !== $want && $due && $useful && !$waiting) {
                $this->turn($s['boost'], $want === 'on');
                $state['written_at'] = $now;
                $state['error'] = null;
                $state['error_at'] = 0;
                $state['log'] = self::log($state['log'], $now, match (true) {
                    $want === 'off' => 'Zeitraum des Anbieters: Boost Charge aus, der Anbieter plant das Laden.',
                    $inside => 'Netzladen jetzt: Boost Charge an.',
                    $plugged => 'Auto angesteckt: Boost Charge an, EMS regelt mit Sonne.',
                    $changed => 'Zeitraum vorbei: Boost Charge an, EMS regelt mit Sonne.',
                    default => 'Boost Charge war aus, wieder an.',
                });
            }
        } catch (Throwable $e) {
            $state['error'] = 'Schalten fehlgeschlagen: ' . $e->getMessage();
            $state['error_at'] = $now;
        }
        $this->store->put(self::STATE, $state);
        return $state;
    }

    /** Schalter oder input_boolean ein oder aus. */
    private function turn(string $entity, bool $on): void
    {
        $this->ha->service(explode('.', $entity, 2)[0], $on ? 'turn_on' : 'turn_off', ['entity_id' => $entity]);
    }

    /**
     * Stand für Einstellungen und Karte: ob der Zeitraum gerade läuft, der nächste Wechsel und ein Satz dazu.
     *
     * @return array{inside: bool, next: ?int, text: string}
     */
    public static function view(array $cfg, Tariff $tariff, int $now): array
    {
        $s = self::settings($cfg);
        if (!$s['on']) {
            return ['inside' => false, 'next' => null, 'text' => ''];
        }
        $inside = self::inWindow($s, $tariff->tariffAt($now), $now);
        $next = self::nextChange($s, $tariff, $now);
        $when = $next === null ? '' : (date('Y-m-d', $next) === date('Y-m-d', $now) ? 'heute ' : (date('Y-m-d', $next) === date('Y-m-d', $now + 86400) ? 'morgen ' : day_label(date('Y-m-d', $next)) . ' ')) . date('H:i', $next);
        $text = match (true) {
            $next === null && !$inside => $s['window'] === 'tariff' ? 'Kein Zeitfenster fürs Auto im Tarif; eintragen unter Einstellungen → Tarif oder eine eigene Zeit wählen.' : '',
            $inside => 'Jetzt plant der Anbieter' . ($when !== '' ? ', bis ' . $when : '') . ', wann das Auto lädt. Die Wallbox ist dafür frei' . ($s['boost'] !== '' ? ', Boost Charge aus' : '') . '.',
            default => 'Jetzt regelt EMS' . ($s['boost'] !== '' ? ' mit Boost Charge an' : '') . '; der Anbieter plant ab ' . $when . '.',
        };
        return ['inside' => $inside, 'next' => $next, 'text' => $text];
    }

    /** @param list<array{t:int, text:string}> $log */
    private static function log(array $log, int $now, string $text): array
    {
        array_unshift($log, ['t' => $now, 'text' => $text]);
        return array_slice($log, 0, 8);
    }
}
