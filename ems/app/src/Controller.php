<?php
declare(strict_types=1);

/**
 * Regelung der Wallbox nach der Logik von evcc (core/loadpoint.go): Timer auf Bedingungen, beim Ausschalten
 * erst Mindeststrom, Phasen hoch nach der Einschalt-, runter nach der Ausschaltverzögerung, Strom auf ganze
 * Ampere abgerundet, ohne Auto gesperrt. step() entscheidet ohne Seiteneffekte, apply() schreibt an die go-e
 * (frc 2/1, amp, psm 1/2), nur bei aktivem EMS und nur Unterschiede. Zustand und Protokoll in kv.control_state.
 */
final class Controller
{
    public const KEY = 'control_state';
    /** Regelintervall wie evcc; Timer laufen auf Uhrzeit, ein Moduswechsel stößt sofort einen Schritt an. */
    public const INTERVAL_S = 30;
    /** So lange darf die Wallbox nach einem Schreibzugriff noch den alten Wert melden. */
    public const GRACE_S = 60;
    /** Fremdzugriffe in diesem Fenster, bis die Regelung pausiert. */
    private const CONFLICTS = 3;
    private const CONFLICT_WINDOW_S = 600;
    /** Timer gilt als abgelaufen: die nächste Entscheidung fällt sofort (evcc: elapsePVTimer). */
    private const ELAPSED = 1;
    private const KW_PER_A = Energy::KW_PER_AMP;

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    public static function active(array $cfg): bool
    {
        return !empty($cfg['control']['active']);
    }

    public static function initial(): array
    {
        return [
            'enabled' => false, 'amps' => 6, 'phases' => 3,
            'pv_timer' => null, 'pv_action' => null, 'phase_timer' => null, 'phase_action' => null,
            'switched_at' => null, 'connected' => null, 'mode' => null, 'step_at' => null, 'note' => null,
            'written' => [], 'conflicts' => [], 'paused' => null, 'woken_at' => null, 'log' => [],
        ];
    }

    public function state(): array
    {
        $state = $this->store->get(self::KEY, []);
        return array_merge(self::initial(), is_array($state) ? $state : []);
    }

    /** Eingang für step() aus Snapshot-Werten, Einstellungen und Energy::suggest() des aktiven Modus. */
    public static function input(array $values, array $cfg, array $suggestion): array
    {
        $c = $cfg['charge'];
        $power = isset($values['wallbox_kw']) ? (float) $values['wallbox_kw'] : null;
        $raw = isset($values['wallbox_car_raw']) ? (string) $values['wallbox_car_raw'] : null;
        $minA = min(16, max(6, (int) ($c['min_a'] ?? 6)));
        $available = $suggestion['p_soll_kw'] ?? null;
        return [
            'mode' => (string) ($c['mode'] ?? 'smart'),
            // Ohne Fahrzeugstatus gilt das Auto als angesteckt, sonst könnte nie geladen werden.
            'connected' => Sessions::carConnected($raw, $power) !== false,
            'charging' => ($power ?? 0.0) > 0.2 || strtolower((string) $raw) === 'charging',
            'complete' => in_array(strtolower(str_replace([' ', '-'], '_', (string) $raw)), ['complete', 'completed'], true),
            'available_kw' => $available === null ? null : (float) $available,
            'zone' => (string) ($suggestion['zone'] ?? 'none'),
            'min_a' => $minA,
            'max_a' => min(16, max($minA, (int) ($c['max_a'] ?? 16))),
            'phase_mode' => (string) ($c['phase_mode'] ?? 'auto'),
            'share' => clamp_float((float) ($c['solar_share'] ?? 100), 0, 100) / 100,
            'on_s' => max(0, (int) ($c['on_delay_s'] ?? 60)),
            'off_s' => max(0, (int) ($c['off_delay_s'] ?? 180)),
            'guard_s' => max(0, (int) ($c['switch_s'] ?? 60)),
            'reported' => self::reported($values),
        ];
    }

    /** Was die Wallbox meldet: Freigabe (frc), Strom (amp) und Phasen (psm, bei der go-e 1 = einphasig, 2 = dreiphasig). */
    public static function reported(array $values): array
    {
        // Offline meldet Home Assistant unavailable oder unknown; das ist kein Wert, sonst sähe es nach Fremdzugriff aus.
        $state = static function (mixed $raw): ?string {
            $text = trim((string) ($raw ?? ''));
            return in_array(strtolower($text), ['', 'unavailable', 'unknown'], true) ? null : $text;
        };
        return [
            'frc' => $state($values['wallbox_force_raw'] ?? null),
            'amp' => isset($values['wallbox_amps']) && $values['wallbox_amps'] !== null ? (int) round((float) $values['wallbox_amps']) : null,
            'psm' => $state($values['wallbox_phases_raw'] ?? null),
        ];
    }

    /**
     * Ein Regelschritt. Gibt den neuen Zustand und die Ereignisse für das Protokoll zurück.
     *
     * @param array $in aus input()
     * @return array{state: array, events: list<string>}
     */
    public static function step(array $s, array $in, int $now): array
    {
        $s = array_merge(self::initial(), $s);
        $events = [];
        $mode = (string) $in['mode'];
        $minA = (int) $in['min_a'];
        $maxA = (int) $in['max_a'];
        $s['note'] = null;

        // Erster Schritt: den Stand der Wallbox übernehmen, wie evcc beim Start.
        if ($s['step_at'] === null) {
            $r = $in['reported'] ?? [];
            $s['enabled'] = ($r['frc'] ?? null) === '2' || !empty($in['charging']);
            $s['amps'] = isset($r['amp']) && $r['amp'] !== null ? max($minA, min($maxA, (int) $r['amp'])) : $minA;
            $s['phases'] = match ($r['psm'] ?? null) {
                '1' => 1,
                '2' => 3,
                default => (int) ($s['phases'] ?: 3),
            };
        }

        // Moduswechsel setzt die Timer zurück wie evcc; nach Netzladen darf Nur Solar sofort stoppen.
        if ($s['mode'] !== null && $s['mode'] !== $mode) {
            if ($mode === 'aus' || $mode === 'schnell') {
                self::reset($s, 'pv');
                self::reset($s, 'phase');
            } elseif ($mode === 'smart_dauerhaft') {
                self::reset($s, 'pv');
            } elseif ($s['mode'] === 'schnell') {
                $s['pv_timer'] = self::ELAPSED;
                $s['pv_action'] = null;
            }
        }
        $s['mode'] = $mode;

        // Anstecken: Nur Solar darf sofort entscheiden (evcc: elapsePVTimer).
        if ($in['connected'] && $s['connected'] === false) {
            $s['pv_timer'] = self::ELAPSED;
            $s['pv_action'] = null;
            $events[] = 'Auto angesteckt.';
        }
        $s['connected'] = (bool) $in['connected'];
        $guard = self::guardLeft($s, $in, $now);

        if (!$in['connected']) {
            // Ohne Auto immer gesperrt, damit Anstecken nicht ungeregelt lädt.
            if ($s['enabled']) {
                $s['enabled'] = false;
                $s['switched_at'] = $now;
                $events[] = 'Kein Auto angesteckt, Wallbox gesperrt.';
            }
            self::reset($s, 'pv');
            self::reset($s, 'phase');
            $s['step_at'] = $now;
            return ['state' => $s, 'events' => $events];
        }

        $fixed = match ((string) $in['phase_mode']) {
            '1p' => 1,
            '3p' => 3,
            default => null,
        };
        if ($fixed !== null && $s['phases'] !== $fixed) {
            if ($guard === 0) {
                $s['phases'] = $fixed;
                $s['switched_at'] = $now;
                $events[] = 'Fest ' . ($fixed === 1 ? 'einphasig' : 'dreiphasig') . '.';
            } else {
                $s['note'] = 'guard';
            }
        }

        if ($mode === 'aus') {
            if ($s['enabled']) {
                $s['enabled'] = false;
                $s['switched_at'] = $now;
                $events[] = 'Modus Aus, Wallbox gesperrt.';
            }
        } elseif ($mode === 'schnell') {
            self::fast($s, $in, $now, $fixed, $events);
        } elseif ($in['available_kw'] === null) {
            // Ohne Zählerwerte nichts schalten, den Stand halten.
            $s['note'] = 'no_values';
        } else {
            self::pv($s, $in, $now, $fixed, $events);
        }
        $s['step_at'] = $now;
        return ['state' => $s, 'events' => $events];
    }

    /** Netzladen: sofort frei, Höchststrom; dreiphasig, beim Laden nach der Einschaltverzögerung (evcc fastChargingPhases). */
    private static function fast(array &$s, array $in, int $now, ?int $fixed, array &$events): void
    {
        $minA = (int) $in['min_a'];
        if ($fixed === null && $s['phases'] === 1) {
            if (self::due($s, 'phase', 'scale3p', (int) $in['on_s'], !empty($in['charging']), $now)) {
                if (self::guardLeft($s, $in, $now) === 0) {
                    $s['phases'] = 3;
                    $s['amps'] = $minA;
                    $s['switched_at'] = $now;
                    self::reset($s, 'phase');
                    $events[] = 'Netzladen: dreiphasig.';
                } else {
                    $s['note'] = 'guard';
                }
            }
        } else {
            self::reset($s, 'phase');
        }
        self::reset($s, 'pv');
        $s['amps'] = (int) $in['max_a'];
        if (!$s['enabled']) {
            $s['enabled'] = true;
            $s['switched_at'] = $now;
            $events[] = 'Netzladen: frei mit ' . Snapshot::levelText($s['amps'], $s['phases']) . '.';
        }
    }

    /** Nur Solar und Min+Solar wie evcc pvMaxCurrent und pvScalePhases. */
    private static function pv(array &$s, array $in, int $now, ?int $fixed, array &$events): void
    {
        $available = (float) $in['available_kw'];
        $minA = (int) $in['min_a'];
        $maxA = (int) $in['max_a'];
        $charging = !empty($in['charging']);
        $always = $in['mode'] === 'smart_dauerhaft';
        // Der Speicher hält die Ladung: ab dem Start ohne Sonne, ab der Stützung, solange schon geladen wird.
        $battery = $in['zone'] === 'start' || ($in['zone'] === 'boost' && $charging);
        $mayDisable = !$always && !$battery;
        $minKw = static fn (int $phases): float => $minA * self::KW_PER_A * $phases;

        if ($fixed === null) {
            if ($s['phases'] === 3) {
                $insufficient = $available < $minKw(3);
                $useful = !$s['enabled'] || !$charging || !$mayDisable || $available >= $minKw(1);
                if ($insufficient && $useful) {
                    if (self::due($s, 'phase', 'scale1p', (int) $in['off_s'], $charging, $now)) {
                        if (self::guardLeft($s, $in, $now) === 0) {
                            $s['phases'] = 1;
                            $s['switched_at'] = $now;
                            self::reset($s, 'phase');
                            $events[] = 'Zu wenig für drei Phasen, einphasig.';
                        } else {
                            $s['note'] = 'guard';
                        }
                    }
                } elseif ($s['phase_action'] === 'scale1p') {
                    self::reset($s, 'phase');
                }
            } else {
                $up = $available > $maxA * self::KW_PER_A && $available >= $minKw(3);
                if ($up) {
                    if (self::due($s, 'phase', 'scale3p', (int) $in['on_s'], $charging, $now)) {
                        if (self::guardLeft($s, $in, $now) === 0) {
                            $s['phases'] = 3;
                            // Vor dem Hochschalten auf Mindeststrom, damit der einphasige Strom nicht auf drei Phasen geht.
                            $s['amps'] = $minA;
                            $s['switched_at'] = $now;
                            self::reset($s, 'phase');
                            $events[] = 'Genug für drei Phasen, dreiphasig.';
                        } else {
                            $s['note'] = 'guard';
                        }
                    }
                } elseif ($s['phase_action'] === 'scale3p') {
                    self::reset($s, 'phase');
                }
            }
        }

        $phases = (int) $s['phases'];
        // Ganze Ampere, abgerundet wie evcc.
        $target = (int) floor($available / (self::KW_PER_A * $phases) + 1e-9);

        if (($always || $battery) && $target < $minA) {
            self::reset($s, 'pv');
            $s['amps'] = $minA;
            if (!$s['enabled']) {
                if (self::guardLeft($s, $in, $now) === 0) {
                    $s['enabled'] = true;
                    $s['switched_at'] = $now;
                    $events[] = ($always ? 'Min+Solar' : 'Der Speicher stützt') . ': frei mit ' . Snapshot::levelText($minA, $phases) . '.';
                } else {
                    $s['note'] = 'guard';
                }
            }
            return;
        }

        if ($s['enabled']) {
            if ($target < $minA) {
                // Reicht es einphasig, läuft nur der Phasen-Timer und kein Aus-Timer (evcc projectPhaseSwitch).
                $projected = $fixed === null && $phases === 3 && $available >= $minKw(1) ? 1 : $phases;
                // Mit Sonnenanteil unter 100 % darf beim Mindeststrom etwas Netz dazukommen.
                $allowed = (1 - (float) $in['share']) * $minKw($projected);
                $import = $minKw($projected) - $available;
                if ($import >= $allowed) {
                    if (self::due($s, 'pv', 'disable', (int) $in['off_s'], true, $now)) {
                        if (self::guardLeft($s, $in, $now) === 0) {
                            $s['enabled'] = false;
                            $s['switched_at'] = $now;
                            self::reset($s, 'pv');
                            $events[] = 'Zu wenig Sonne, gestoppt.';
                            return;
                        }
                        $s['note'] = 'guard';
                    }
                } elseif ($s['pv_action'] === 'disable') {
                    self::reset($s, 'pv');
                }
                // Bis zum Stoppen nur der Mindeststrom.
                $s['amps'] = $minA;
                return;
            }
            self::reset($s, 'pv');
            $s['amps'] = min($target, $maxA);
            return;
        }

        // Gesperrt: starten, wenn der Sonnenanteil des Mindeststroms die ganze Einschaltverzögerung da ist.
        $availableA = $available / (self::KW_PER_A * $phases);
        if ($availableA >= (float) $in['share'] * $minA) {
            if (self::due($s, 'pv', 'enable', (int) $in['on_s'], true, $now)) {
                if (self::guardLeft($s, $in, $now) === 0) {
                    $s['enabled'] = true;
                    $s['amps'] = $minA;
                    $s['switched_at'] = $now;
                    self::reset($s, 'pv');
                    $events[] = 'Genug Sonne, gestartet mit ' . Snapshot::levelText($minA, $phases) . '.';
                } else {
                    $s['note'] = 'guard';
                }
            }
        } elseif ($s['pv_action'] === 'enable') {
            self::reset($s, 'pv');
        }
    }

    /**
     * Läuft der Timer für diese Aktion und ist er abgelaufen? Wechselt die Aktion, beginnt er neu, außer er war
     * schon abgelaufen markiert. Phasen schalten sofort, solange nicht geladen wird (evcc phaseTimerElapsed).
     */
    private static function due(array &$s, string $kind, string $action, int $delay, bool $running, int $now): bool
    {
        $timer = $kind . '_timer';
        $what = $kind . '_action';
        if ($kind === 'phase' && !$running) {
            $s[$timer] = self::ELAPSED;
        } elseif ($s[$timer] === null || ($s[$what] !== null && $s[$what] !== $action && $s[$timer] !== self::ELAPSED)) {
            $s[$timer] = $now;
        }
        $s[$what] = $action;
        return $now - (int) $s[$timer] >= $delay;
    }

    private static function reset(array &$s, string $kind): void
    {
        $s[$kind . '_timer'] = null;
        $s[$kind . '_action'] = null;
    }

    /** Restliche Schütz-Schutzzeit seit dem letzten Schalten. */
    private static function guardLeft(array $s, array $in, int $now): int
    {
        if ($s['switched_at'] === null) {
            return 0;
        }
        return max(0, (int) $in['guard_s'] - ($now - (int) $s['switched_at']));
    }

    /** Was die Regelung gerade einstellt, für die Karte und den Payload. */
    public static function decision(array $s, array $in, int $now): array
    {
        $s = array_merge(self::initial(), $s);
        $amps = $s['enabled'] ? (int) $s['amps'] : 0;
        $phases = (int) $s['phases'];
        $timer = null;
        // Der Phasenwechsel kommt vor dem Stoppen, darum zuerst sein Timer.
        foreach (['phase', 'pv'] as $kind) {
            $action = $s[$kind . '_action'];
            if ($action === null || $s[$kind . '_timer'] === null) {
                continue;
            }
            $delay = in_array($action, ['enable', 'scale3p'], true) ? (int) $in['on_s'] : (int) $in['off_s'];
            $timer = ['action' => $action, 'remaining_s' => max(0, $delay - ($now - (int) $s[$kind . '_timer']))];
            break;
        }
        return [
            'enabled' => (bool) $s['enabled'],
            'amps' => $amps,
            'phases' => $phases,
            'kw' => $amps === 0 ? 0.0 : $amps * $phases * self::KW_PER_A,
            'timer' => $timer,
            'guard_s' => self::guardLeft($s, $in, $now),
            'note' => $s['note'],
        ];
    }

    /**
     * Schreibt an die Wallbox, was vom gemeldeten Stand abweicht: beim Freigeben erst Phasen und Strom, dann frc 2,
     * beim Sperren nur frc 1. Ein gemeldeter Wert, der nach der Karenzzeit nicht mehr dem Geschriebenen entspricht,
     * zählt als Fremdzugriff; nach drei in zehn Minuten pausiert die Regelung, statt gegen evcc zu schalten.
     */
    public function apply(array $s, array $cfg, array $reported, int $now): array
    {
        if ($reported['frc'] === null) {
            // Wallbox offline oder ohne Zwangszustand: nichts schreiben, den Stand halten.
            $s['note'] = 'offline';
            return $s;
        }
        $m = $cfg['mapping'];
        $ids = ['frc' => (string) ($m['wallbox_force'] ?? ''), 'amp' => (string) ($m['wallbox_amps'] ?? ''), 'psm' => (string) ($m['wallbox_phases'] ?? '')];
        $want = ['frc' => $s['enabled'] ? '2' : '1', 'amp' => (int) $s['amps'], 'psm' => (int) $s['phases'] === 3 ? '2' : '1'];
        $foreign = [];
        foreach ($want as $key => $value) {
            $written = $s['written'][$key] ?? null;
            if (!is_array($written) || $reported[$key] === null || $now - (int) $written['t'] < self::GRACE_S) {
                continue;
            }
            if (!self::same($key, $reported[$key], $written['v'])) {
                $foreign[] = $key;
                unset($s['written'][$key]);
            }
        }
        if ($foreign) {
            $s['conflicts'][] = $now;
            $s['log'] = self::log($s['log'], $now, 'Fremder Wert an der Wallbox (' . implode(', ', $foreign) . '), ein anderer Regler?');
        }
        $s['conflicts'] = array_values(array_filter($s['conflicts'], static fn (int $t): bool => $now - $t < self::CONFLICT_WINDOW_S));
        if (count($s['conflicts']) >= self::CONFLICTS) {
            $s['paused'] = 'Ein anderer Regler schreibt ebenfalls an die Wallbox, etwa evcc. EMS hat die Regelung pausiert. evcc vom Ladepunkt lösen und EMS neu einschalten.';
            $s['log'] = self::log($s['log'], $now, 'Regelung pausiert, Fremdzugriffe an der Wallbox.');
            return $s;
        }

        // Nie kurz zu viel: hoch auf drei Phasen erst den Strom senken, runter auf eine erst die Phase wechseln.
        $before = match ($reported['psm']) {
            '2' => 3,
            '1' => 1,
            default => (int) $s['phases'],
        };
        $order = !$s['enabled'] ? ['frc'] : ((int) $s['phases'] > $before ? ['amp', 'psm', 'frc'] : ['psm', 'amp', 'frc']);
        foreach ($order as $key) {
            if ($ids[$key] === '' || self::same($key, $reported[$key], $want[$key])) {
                continue;
            }
            $written = $s['written'][$key] ?? null;
            if (is_array($written) && self::same($key, $written['v'], $want[$key]) && $now - (int) $written['t'] < self::GRACE_S) {
                continue;
            }
            try {
                $this->write($ids[$key], $key, $want[$key]);
            } catch (Throwable $e) {
                $s['log'] = self::log($s['log'], $now, 'Schreiben fehlgeschlagen (' . $key . '): ' . $e->getMessage());
                break;
            }
            $s['written'][$key] = ['v' => $want[$key], 't' => $now];
            $s['log'] = self::log($s['log'], $now, self::writeText($key, $want[$key]));
        }
        return $s;
    }

    /** Gibt die Wallbox frei (frc 0), wenn EMS ausgeschaltet wird; danach regeln evcc oder die go-e selbst. */
    public function release(array $cfg, int $now): string
    {
        $state = $this->state();
        $id = (string) ($cfg['mapping']['wallbox_force'] ?? '');
        $state['written'] = [];
        $state['paused'] = null;
        $state['conflicts'] = [];
        $state['step_at'] = null;
        $message = 'EMS regelt nicht mehr.';
        if ($id !== '') {
            try {
                $this->ha->service('select', 'select_option', ['entity_id' => $id, 'option' => '0']);
                $state['log'] = self::log($state['log'], $now, 'EMS aus, Wallbox freigegeben (neutral).');
                $message = 'EMS regelt nicht mehr, die Wallbox ist freigegeben.';
            } catch (Throwable $e) {
                $state['log'] = self::log($state['log'], $now, 'Freigeben fehlgeschlagen: ' . $e->getMessage());
                $message = 'EMS regelt nicht mehr, aber die Wallbox ließ sich nicht freigeben: ' . $e->getMessage();
            }
        }
        $this->store->put(self::KEY, $state);
        return $message;
    }

    /** Neu einschalten: Pause und Fremdzugriffe vergessen, beim nächsten Schritt den Stand der Wallbox übernehmen. */
    public function resume(int $now): void
    {
        $state = $this->state();
        $state['paused'] = null;
        $state['conflicts'] = [];
        $state['written'] = [];
        $state['step_at'] = null;
        $state['log'] = self::log($state['log'], $now, 'EMS regelt die Wallbox.');
        $this->store->put(self::KEY, $state);
        $this->store->put('control_kick', $now);
    }

    /** Nächster Recorder-Schritt sofort, etwa nach einem Moduswechsel. */
    public function kick(int $now): void
    {
        $this->store->put('control_kick', $now);
    }

    /**
     * Recorder-Schritt alle 10 s: Herzschlag, Ziel und Abstecken, alle 30 s (oder angestoßen) ein Regelschritt,
     * bei aktivem EMS die Schreibzugriffe und das Wecken des Autos. Gibt den Zustand zurück.
     */
    public function tick(array $snap, int $now, bool $force = false): array
    {
        $this->store->put('control_heartbeat', $now);
        $state = $this->state();
        if (empty($snap['connected'])) {
            return $state;
        }
        $cfg = $snap['cfg'];
        $kick = (int) $this->store->get('control_kick', 0);
        if (!$force && $kick <= (int) $state['step_at'] && $state['step_at'] !== null && $now - (int) $state['step_at'] < self::INTERVAL_S) {
            return $state;
        }
        $values = $snap['values'];
        $mode = (string) $cfg['charge']['mode'];
        // Ziel erreicht oder Auto abgesteckt: Folgemodus, Ziel löschen.
        $switch = Target::check($this->store, $snap, $now, $state['connected']);
        if ($switch !== null) {
            $mode = $switch['mode'];
            $state['log'] = self::log($state['log'], $now, $switch['text']);
        }
        $suggestion = $switch === null ? ($snap['suggestion'] ?? []) : Energy::suggest(
            array_merge($values, $snap['balance'] ?? []),
            ['mode' => $mode] + $cfg['charge'],
            Energy::reportedPhases($values['wallbox_phases_raw'] ?? null),
            zone_thresholds((float) $cfg['battery_strategy']['priority_soc'], (float) $cfg['battery_strategy']['car_buffer_soc'], (float) $cfg['battery_strategy']['car_auto_soc']),
            Reserve::battery($cfg),
        );
        $in = self::input($values, ['charge' => ['mode' => $mode] + $cfg['charge']] + $cfg, $suggestion);
        $result = self::step($state, $in, $now);
        $state = $result['state'];
        // Ins Protokoll nur, was wirklich geschaltet wird; ohne EMS rechnet die Regelung nur für die Anzeige.
        if (self::active($cfg)) {
            foreach ($result['events'] as $event) {
                $state['log'] = self::log($state['log'], $now, $event);
            }
        }
        if (self::active($cfg) && $state['paused'] === null) {
            $state = $this->apply($state, $cfg, $in['reported'], $now);
            $state = $this->wake($state, $cfg, $in, $now);
        }
        $this->store->put(self::KEY, $state);
        return $state;
    }

    /** Tesla schläft: 60 s nach dem Freigeben ohne Ladung einmal den Weck-Button drücken (evcc: Wake-up). */
    private function wake(array $s, array $cfg, array $in, int $now): array
    {
        $id = (string) ($cfg['mapping']['car_wakeup'] ?? '');
        if ($id === '' || !$s['enabled'] || !empty($in['charging']) || !$in['connected'] || !empty($in['complete'])) {
            if (!empty($in['charging']) || !$s['enabled']) {
                $s['woken_at'] = null;
            }
            return $s;
        }
        if ($s['woken_at'] !== null || $now - (int) $s['switched_at'] < 60) {
            return $s;
        }
        try {
            $this->ha->service('button', 'press', ['entity_id' => $id]);
            $s['log'] = self::log($s['log'], $now, 'Auto lädt nicht, geweckt.');
        } catch (Throwable $e) {
            $s['log'] = self::log($s['log'], $now, 'Wecken fehlgeschlagen: ' . $e->getMessage());
        }
        $s['woken_at'] = $now;
        return $s;
    }

    private function write(string $entity, string $key, int|string $value): void
    {
        if ($key === 'amp') {
            $this->ha->setNumber($entity, (float) $value);
            return;
        }
        $this->ha->service('select', 'select_option', ['entity_id' => $entity, 'option' => (string) $value]);
    }

    private static function same(string $key, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        return $key === 'amp' ? (int) round((float) $a) === (int) round((float) $b) : (string) $a === (string) $b;
    }

    private static function writeText(string $key, int|string $value): string
    {
        return match ($key) {
            'frc' => $value === '2' ? 'Wallbox freigegeben.' : 'Wallbox gesperrt.',
            'amp' => 'Ladestrom ' . $value . NNBSP . 'A.',
            default => $value === '2' ? 'Dreiphasig.' : 'Einphasig.',
        };
    }

    /** @param list<array{t:int, text:string}> $log */
    public static function log(array $log, int $now, string $text): array
    {
        array_unshift($log, ['t' => $now, 'text' => $text]);
        return array_slice($log, 0, 12);
    }

    /** Vorbedingungen fürs Einschalten: Entitäten da und mit den Werten der go-e. Leer heißt alles gut. */
    public static function problems(array $cfg, array $index): array
    {
        $m = $cfg['mapping'];
        $out = [];
        $check = static function (string $key, string $label, ?array $options) use ($m, $index, &$out): void {
            $id = (string) ($m[$key] ?? '');
            if ($id === '') {
                $out[] = $label . ' ist nicht zugeordnet.';
                return;
            }
            if (!isset($index[$id])) {
                $out[] = $label . ' (' . $id . ') ist in Home Assistant nicht erreichbar.';
                return;
            }
            if ($options !== null) {
                $have = array_map('strval', (array) ($index[$id]['attributes']['options'] ?? []));
                if (array_diff($options, $have)) {
                    $out[] = $label . ' (' . $id . ') kennt die Werte ' . implode(', ', $options) . ' nicht.';
                }
            }
        };
        $check('wallbox_force', 'Der Zwangszustand (frc)', ['1', '2']);
        $check('wallbox_amps', 'Der Ladestrom (amp)', null);
        if (($cfg['charge']['phase_mode'] ?? 'auto') === 'auto' && (string) ($m['wallbox_phases'] ?? '') !== '') {
            $check('wallbox_phases', 'Die Phasenumschaltung (psm)', ['1', '2']);
        }
        if ((string) ($m['wallbox_power'] ?? '') === '') {
            $out[] = 'Die Ladeleistung der Wallbox ist nicht zugeordnet.';
        }
        return $out;
    }
}
