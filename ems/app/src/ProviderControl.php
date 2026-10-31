<?php
declare(strict_types=1);

/**
 * Der Stromanbieter plant das Laden des Autos in einem Zeitraum (etwa Octopus Intelligent: nachts günstig, Octopus
 * startet und stoppt das Auto über dessen API). Einstellungen in kv.provider (on, window tariff oder time, from, to)
 * und mapping.provider_boost (Schalter „Boost Charge“, Sofortladen beim Anbieter) und mapping.provider_control
 * („Smart Control“, ohne die es Boost Charge nicht gibt).
 * - Im Zeitraum gibt EMS die Wallbox mit vollem Strom frei (ChargePlan::effective(), Grund provider; der Speicher gibt
 *   wie beim Netzladen nichts ans Auto ab) und schaltet Boost Charge aus: Wann das Auto lädt, entscheidet der Anbieter.
 *   Nur in den Viertelstunden eines Ladeplans oder bei „Jetzt“ bleibt Boost Charge an, dann entscheidet EMS.
 * - Außerhalb schaltet EMS Boost Charge ein, sobald es die Wallbox freigibt, damit der Anbieter das Auto nicht sperrt.
 *   Boost Charge kann beim Anbieter von selbst enden, etwa wenn das Auto voll ist; dann setzt EMS ihn wieder, solange es
 *   laden will, alle 15 min.
 * sync() schreibt nur bei aktivem EMS; Zustand, Fehler und Protokoll in kv.provider_state.
 */
final class ProviderControl
{
    public const STATE = 'provider_state';
    private const REASSERT_S = 900;
    private const RETRY_S = 60;

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

    /**
     * Soll Boost Charge an sein? Außerhalb des Zeitraums ja; im Zeitraum nur, solange EMS selbst lädt: mit „Jetzt“ oder in
     * einer Viertelstunde eines Ladeplans (Grund plan). Sonst hält der Anbieter das Auto an, auch wenn die Wallbox frei ist.
     */
    public static function wantBoost(bool $inWindow, string $grid, ?string $reason = null): bool
    {
        return !$inWindow || $grid === 'now' || $reason === 'plan';
    }

    /**
     * Lohnt sich das Schalten? Aus immer. An nur, wenn das Auto steckt, EMS die Wallbox gerade freigibt und das Auto nicht
     * voll ist. Voll sagt nur der Ladestand: „Abgeschlossen“ meldet die go-e auch, wenn EMS sie sperrt oder der Anbieter
     * das Auto anhält, und gerade dann braucht es Boost Charge.
     */
    public static function boostUseful(bool $wantOn, bool $connected, bool $enabled, ?float $soc, ?float $limit): bool
    {
        if (!$wantOn) {
            return true;
        }
        return $connected && $enabled && !($soc !== null && $limit !== null && $soc >= $limit - 1);
    }

    /** Wartezeit nach dem n-ten Fehler in Folge: 1, 2, 4, 8, dann alle 15 min. Ein frisch angestecktes Auto kennt der Anbieter oft noch nicht. */
    public static function retryS(int $errors): int
    {
        return $errors <= 0 ? 0 : min(self::REASSERT_S, self::RETRY_S * 2 ** min($errors - 1, 8));
    }

    public function state(): array
    {
        $state = $this->store->get(self::STATE, []);
        return array_merge(['want' => null, 'connected' => false, 'written_at' => 0, 'control_at' => 0, 'error' => null, 'error_at' => 0, 'errors' => 0, 'log' => []], is_array($state) ? $state : []);
    }

    /**
     * Recorder (im Demo-Modus /api/live): Smart Control an, Boost Charge nach Zeitraum. Geschrieben wird beim Wechsel
     * des Zeitraums, beim Anstecken und, wenn der Schalter abweicht, höchstens alle 15 min (boostUseful()). Nach einem
     * Fehler neu nach retryS(); der Fehler gilt, bis der Schalter stimmt oder nichts mehr zu schalten ist.
     */
    public function sync(array $snap, int $now): array
    {
        $cfg = $snap['cfg'];
        $s = self::settings($cfg);
        $state = $this->state();
        if (!$s['on'] || $s['boost'] === '' || !Controller::active($cfg)) {
            if ($state['error'] !== null) {
                $state = self::cleared($state);
                $this->store->put(self::STATE, $state);
            }
            return $state;
        }
        try {
            $index = $this->ha->index();
        } catch (Throwable) {
            return $state;
        }
        $tariff = new Tariff($this->store);
        $inside = self::inWindow($s, $tariff->tariffAt($now), $now);
        $reason = $cfg['charge']['effective_reason'] ?? null;
        $want = self::wantBoost($inside, Energy::grid($cfg['charge']['grid'] ?? 'off'), $reason) ? 'on' : 'off';
        $connected = !empty($snap['vehicle']['connected']);
        $enabled = !empty((new Controller($this->store, $this->ha))->state()['enabled']);
        $soc = is_numeric($snap['vehicle']['soc'] ?? null) ? (float) $snap['vehicle']['soc'] : null;
        $limit = is_numeric($snap['vehicle']['limit'] ?? null) ? (float) $snap['vehicle']['limit'] : null;
        $changed = $state['want'] !== null && $state['want'] !== $want;
        $plugged = $connected && empty($state['connected']);
        $state['want'] = $want;
        $state['connected'] = $connected;
        $waiting = (int) $state['error_at'] > 0 && $now - (int) $state['error_at'] < self::retryS((int) $state['errors']);
        try {
            // Ohne Smart Control gibt es Boost Charge nicht: erst einschalten, Boost Charge in der nächsten Runde. Der
            // Anbieter bestätigt oft erst nach einer Weile; bis dahin höchstens alle 15 min neu.
            $controlOff = $s['control'] !== '' && ($index[$s['control']]['state'] ?? '') === 'off';
            if ($controlOff && !$waiting) {
                if ($now - (int) $state['control_at'] < self::REASSERT_S) {
                    $this->store->put(self::STATE, $state);
                    return $state;
                }
                $this->turn($s['control'], true);
                $state['control_at'] = $now;
                $state['log'] = self::log($state['log'], $now, 'Smart Control war aus, eingeschaltet.');
                $state = self::cleared($state);
                $this->store->put(self::STATE, $state);
                return $state;
            }
            $current = (string) ($index[$s['boost']]['state'] ?? 'unavailable');
            $due = $changed || $plugged || (int) $state['errors'] > 0 || $now - (int) $state['written_at'] >= self::REASSERT_S;
            $useful = self::boostUseful($want === 'on', $connected, $enabled, $soc, $limit);
            // Ein Fehler zählt nur, solange EMS noch schalten will; steht der Schalter richtig, egal durch wen, ist er erledigt.
            if (!$controlOff && ($current === $want || !$useful)) {
                $state = self::cleared($state);
                $waiting = false;
            }
            if (in_array($current, ['on', 'off'], true) && $current !== $want && $due && $useful && !$waiting) {
                $this->turn($s['boost'], $want === 'on');
                $state['written_at'] = $now;
                $state = self::cleared($state);
                $state['log'] = self::log($state['log'], $now, match (true) {
                    $want === 'off' => 'Zeitraum des Anbieters: Boost Charge aus, der Anbieter plant das Laden.',
                    $inside && $reason === 'plan' => 'Ladeplan: Boost Charge an, damit das Auto bis zur Uhrzeit lädt.',
                    $inside => 'Netzladen jetzt: Boost Charge an.',
                    default => 'EMS lädt: Boost Charge an, damit der Anbieter das Auto nicht sperrt.',
                });
            }
        } catch (Throwable $e) {
            $state['errors'] = (int) $state['errors'] + 1;
            $state['error'] = 'Schalten fehlgeschlagen: ' . $e->getMessage();
            $state['error_at'] = $now;
            if ($state['errors'] === 1) {
                $state['log'] = self::log($state['log'], $now, 'Schalten fehlgeschlagen, EMS versucht es weiter.');
            }
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
            default => 'Jetzt regelt EMS' . ($s['boost'] !== '' ? ' und schaltet Boost Charge ein, sobald es lädt' : '') . '; der Anbieter plant ab ' . $when . '.',
        };
        return ['inside' => $inside, 'next' => $next, 'text' => $text];
    }

    private static function cleared(array $state): array
    {
        return ['error' => null, 'error_at' => 0, 'errors' => 0] + $state;
    }

    /** @param list<array{t:int, text:string}> $log */
    private static function log(array $log, int $now, string $text): array
    {
        array_unshift($log, ['t' => $now, 'text' => $text]);
        return array_slice($log, 0, 8);
    }
}
