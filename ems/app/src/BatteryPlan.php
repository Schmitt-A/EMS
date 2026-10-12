<?php
declare(strict_types=1);

/**
 * Hausspeicher in Ladefenstern aus dem Netz laden, nur wenn die Sonne nicht reicht (Speicher → Ausblick, an mit
 * battery_strategy.grid_charge). decide() rechnet je Viertelstunde vom Fensterbeginn bis 24 Stunden nach dem Fenster:
 * Sonne laut Prognose, Haus mit dem Mittel der letzten 30 Tage, Preise im aktiven Tarif (unbekannte wie am Vortag).
 * Es probiert, bis zu welchem Stand der Speicher im Fenster laden sollte, und nimmt den mit den geringsten Kosten:
 * Netzbezug im Fenster und danach, minus Einspeisung, plus Verschleiß je gespeicherter kWh, mit Verlusten. Füllt die
 * Sonne den Speicher ohnehin, oder ist der Preisunterschied zu klein, lädt er nicht.
 * sync() schaltet über Home Assistant: Betriebsmodus manuell und „Laden erzwingen“ mit der Ladeleistung, danach
 * Leistung 0 und der Betriebsmodus von vorher. Nur bei aktivem EMS. Zustand und Protokoll in kv.battery_grid.
 */
final class BatteryPlan
{
    public const KEY = 'battery_grid';
    /** Darunter lohnt sich ein Fenster nicht, damit kleine Rechenunterschiede nicht schalten. */
    private const MIN_SAVING_EUR = 0.05;
    private const STEP_KWH = 0.25;
    private const RETRY_S = 60;
    private const HORIZON_S = 86400;
    /** Betriebsmodi der sonnenBatterie nach Bedeutung, wie Controller::OPTIONS. */
    private const MODES = ['manual' => ['manual', '1'], 'auto' => ['automatic', 'auto', '2']];

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    /** Einstellungen aus battery_strategy und mapping. */
    public static function settings(array $cfg): array
    {
        $b = is_array($cfg['battery_strategy'] ?? null) ? $cfg['battery_strategy'] : [];
        $m = is_array($cfg['mapping'] ?? null) ? $cfg['mapping'] : [];
        $power = is_numeric($b['grid_kw'] ?? null) && (float) $b['grid_kw'] > 0 ? (float) $b['grid_kw']
            : (is_numeric($b['discharge_kw'] ?? null) && (float) $b['discharge_kw'] > 0 ? (float) $b['discharge_kw'] : 2.5);
        return [
            'on' => !empty($b['grid_charge']),
            'mode_entity' => (string) ($m['battery_operating_mode'] ?? ''),
            'power_entity' => (string) ($m['battery_force_charge'] ?? ''),
            'power_kw' => clamp_float($power, 0.1, 50),
            'power_set' => is_numeric($b['grid_kw'] ?? null) && (float) $b['grid_kw'] > 0,
            'max_soc' => clamp_float((float) ($b['grid_max_soc'] ?? 100), 10, 100),
            'efficiency' => clamp_float((float) ($b['grid_efficiency_pct'] ?? 90), 50, 100) / 100,
            'wear_ct' => clamp_float((float) ($b['grid_wear_ct'] ?? 2), 0, 50),
            'floor_soc' => is_numeric($b['backup_soc'] ?? null) ? clamp_float((float) $b['backup_soc'], 0, 100) : 0.0,
        ];
    }

    /**
     * Die Entscheidung für ein Fenster, ohne Seiteneffekte.
     * $in: soc (%), capacity_kwh, floor_soc, max_soc, power_kw, efficiency (0…1), wear_ct, export_ct,
     * window {start, end}, quarters: Liste [t, pv kW, Haus kW, ct/kWh] ab jetzt bis 24 h nach dem Fenster.
     *
     * @return array{reason: string, kwh: float, target_soc: ?float, start_soc: ?float, saving_eur: float, need_kwh: float, later_ct: ?float, window_ct: ?float, cost_ct: ?float, sun_kwh: float}
     */
    public static function decide(array $in): array
    {
        $cap = (float) ($in['capacity_kwh'] ?? 0);
        $soc = isset($in['soc']) && is_numeric($in['soc']) ? (float) $in['soc'] : null;
        $out = ['reason' => 'unknown', 'kwh' => 0.0, 'target_soc' => null, 'start_soc' => null, 'saving_eur' => 0.0, 'need_kwh' => 0.0, 'later_ct' => null, 'window_ct' => null, 'cost_ct' => null, 'sun_kwh' => 0.0];
        if ($cap <= 0.1 || $soc === null || !$in['quarters']) {
            return $out;
        }
        $floor = $cap * (float) $in['floor_soc'] / 100;
        $max = max($floor, $cap * (float) $in['max_soc'] / 100);
        $eta = max(0.5, min(1.0, (float) $in['efficiency']));
        $power = max(0.1, (float) $in['power_kw']);
        $start = (int) $in['window']['start'];
        $end = (int) $in['window']['end'];
        // Bis zum Fenster: Eigenverbrauch wie immer, für alle Varianten gleich.
        $e = $cap * $soc / 100;
        $rest = [];
        foreach ($in['quarters'] as [$t, $pv, $house, $ct]) {
            if ($t < $start) {
                $e = max(min($e, $cap), min($cap, max($floor, $e + ($pv - $house) * 0.25)));
                continue;
            }
            $rest[] = [$t, max(0.0, (float) $pv), max(0.0, (float) $house), $ct === null ? null : (float) $ct];
        }
        $e0 = $e;
        $out['start_soc'] = round($e0 / $cap * 100, 1);
        $prices = array_values(array_filter(array_map(static fn (array $r): ?float => $r[0] < $end ? $r[3] : null, $rest), static fn (?float $v): bool => $v !== null));
        $out['window_ct'] = $prices ? round(array_sum($prices) / count($prices), 2) : null;
        $out['cost_ct'] = $out['window_ct'] === null ? null : round($out['window_ct'] / $eta + (float) $in['wear_ct'], 2);
        foreach ($rest as [$t, $pv, $house]) {
            if ($t >= $end) {
                $out['sun_kwh'] += max(0.0, $pv - $house) * 0.25;
            }
        }
        $out['sun_kwh'] = round($out['sun_kwh'], 1);
        $base = self::simulate($rest, $e0, $e0, $cap, $floor, $eta, $power, $end, (float) $in['export_ct'], (float) $in['wear_ct']);
        $out['need_kwh'] = round($base['later_kwh'], 1);
        $out['later_ct'] = $base['later_kwh'] > 0.05 ? round($base['later_cost'] / $base['later_kwh'], 2) : null;
        if ($e0 >= $max - 0.05) {
            $out['reason'] = 'full';
            return $out;
        }
        $reach = min($max, $e0 + $power * $eta * max(0, $end - max($start, (int) $rest[0][0])) / 3600);
        $best = ['target' => $e0, 'cost' => $base['cost']];
        for ($target = $e0 + self::STEP_KWH; $target <= $reach + 1e-9; $target += self::STEP_KWH) {
            $run = self::simulate($rest, $e0, $target, $cap, $floor, $eta, $power, $end, (float) $in['export_ct'], (float) $in['wear_ct']);
            if ($run['cost'] < $best['cost'] - 1e-9) {
                $best = ['target' => $target, 'cost' => $run['cost']];
            }
        }
        $saving = ($base['cost'] - $best['cost']) / 100;
        if ($best['target'] > $e0 + 0.1 && $saving >= self::MIN_SAVING_EUR) {
            $out['reason'] = 'charge';
            $out['kwh'] = round(($best['target'] - $e0) / $eta, 1);
            $out['target_soc'] = round(min(100.0, $best['target'] / $cap * 100), 0);
            $out['saving_eur'] = round($saving, 2);
            return $out;
        }
        $out['reason'] = $base['later_kwh'] < 0.3 ? 'sun' : 'price';
        return $out;
    }

    /**
     * Eine Variante: im Fenster bis $target laden (Haus und Laden aus Sonne, dann Netz), sonst Eigenverbrauch.
     * Kosten in ct: Bezug zum Preis der Viertelstunde, minus Einspeisung, plus Verschleiß der gespeicherten kWh.
     * later_kwh und later_cost: Bezug nach dem Fenster, den der Speicher nicht mehr deckt.
     *
     * @param list<array{0:int, 1:float, 2:float, 3:?float}> $rest
     */
    private static function simulate(array $rest, float $e, float $target, float $cap, float $floor, float $eta, float $power, int $end, float $exportCt, float $wearCt): array
    {
        $cost = $laterKwh = $laterCost = $stored = 0.0;
        $fallback = null;
        foreach ($rest as [$t, $pv, $house, $ct]) {
            $ct ??= $fallback ?? 0.0;
            $fallback = $ct;
            $sun = $pv * 0.25;
            $use = $house * 0.25;
            if ($t < $end && $e < $target - 1e-9) {
                $add = min($power * 0.25 * $eta, $target - $e, $cap - $e);
                $need = $use + $add / $eta;
                $fromSun = min($sun, $need);
                $cost += ($need - $fromSun) * $ct - ($sun - $fromSun) * $exportCt;
                $e += $add;
                $stored += $add;
                continue;
            }
            $net = $sun - $use;
            if ($net >= 0) {
                $keep = min($net, $cap - $e);
                $e += $keep;
                $cost -= ($net - $keep) * $exportCt;
            } else {
                $take = min(-$net, max(0.0, $e - $floor));
                $e -= $take;
                $import = -$net - $take;
                $cost += $import * $ct;
                if ($t >= $end) {
                    $laterKwh += $import;
                    $laterCost += $import * $ct;
                }
            }
        }
        return ['cost' => $cost + $stored * $wearCt, 'later_kwh' => $laterKwh, 'later_cost' => $laterCost];
    }

    /**
     * Viertelstunden für decide(): Sonne aus der stündlichen Prognose, Haus als Mittel, Preis im aktiven Tarif; ohne
     * Preis der vom Vortag zur selben Zeit.
     *
     * @param array<int, array{t:int, kw:float}> $series stündliche Prognose (Forecast::fromRadiation)
     */
    public static function quarters(array $series, float $houseKw, Tariff $tariff, int $from, int $to): array
    {
        $pv = [];
        foreach ($series as $point) {
            $pv[(int) $point['t']] = max(0.0, (float) $point['kw']);
        }
        $out = [];
        for ($q = intdiv($from, 900) * 900; $q < $to; $q += 900) {
            $hour = intdiv($q, 3600) * 3600;
            $ct = $tariff->importCt($q) ?? $tariff->importCt($q - 86400);
            $out[] = [$q, $pv[$hour] ?? 0.0, $houseKw, $ct];
        }
        return $out;
    }

    /**
     * Plan für den Ausblick und sync(): das laufende oder nächste aktive Ladefenster mit der Entscheidung. null ohne
     * Fenster, ohne Ladestand oder ohne Hausverbrauch.
     */
    public static function plan(array $cfg, array $values, array $series, ?float $houseKw, PriceWindows $windows, Tariff $tariff, int $now): ?array
    {
        $window = $windows->current($now) ?? ($windows->upcoming($now, 1)[0] ?? null);
        $capacity = is_numeric($values['battery_total_kwh'] ?? null) && (float) $values['battery_total_kwh'] > 0 ? (float) $values['battery_total_kwh']
            : (is_numeric($values['battery_capacity_kwh'] ?? null) && is_numeric($values['battery_soc'] ?? null) && (float) $values['battery_soc'] > 1
                ? (float) $values['battery_capacity_kwh'] / ((float) $values['battery_soc'] / 100) : null);
        if ($window === null || $houseKw === null || $capacity === null || !is_numeric($values['battery_soc'] ?? null)) {
            return $window === null ? null : ['window' => $window, 'reason' => 'unknown', 'kwh' => 0.0, 'target_soc' => null];
        }
        $s = self::settings($cfg);
        $t = $tariff->tariffAt($now);
        $decision = self::decide([
            'soc' => (float) $values['battery_soc'],
            'capacity_kwh' => $capacity,
            'floor_soc' => $s['floor_soc'],
            'max_soc' => $s['max_soc'],
            'power_kw' => $s['power_kw'],
            'efficiency' => $s['efficiency'],
            'wear_ct' => $s['wear_ct'],
            'export_ct' => (float) $t['export_ct'],
            'window' => $window,
            'quarters' => self::quarters($series, $houseKw, $tariff, $now, (int) $window['end'] + self::HORIZON_S),
        ]);
        return ['window' => $window] + $decision;
    }

    /**
     * Die nächsten Tage für den Ausblick: Sonne laut Prognose (heute der Rest, wie auf der Seite Prognose), Haus mit
     * dem Mittel der letzten 30 Tage ohne Wallbox und was übrig bleibt oder fehlt.
     *
     * @return list<array{day: string, label: string, sun_kwh: ?float, house_kwh: ?float, balance_kwh: ?float}>
     */
    public static function days(array $series, array $plant, ?float $houseKw, int $now, ?array $brief, int $count = 3): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $totals = [];
        foreach (Forecast::dailyTotals($series, $plant) as $row) {
            $totals[$row['day']] = (float) $row['kwh'];
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $day = $today->modify('+' . $i . ' day');
            $next = $day->modify('+1 day');
            $key = $day->format('Y-m-d');
            $hours = ($next->getTimestamp() - ($i === 0 ? max($now, $day->getTimestamp()) : $day->getTimestamp())) / 3600;
            $sun = match ($i) {
                0 => isset($brief['remaining_kwh']) ? (float) $brief['remaining_kwh'] : null,
                1 => isset($brief['tomorrow_kwh']) ? (float) $brief['tomorrow_kwh'] : ($totals[$key] ?? null),
                default => $totals[$key] ?? null,
            };
            $house = $houseKw === null ? null : max(0.0, $houseKw) * max(0.0, $hours);
            $out[] = [
                'day' => $key,
                'label' => $i === 0 ? 'Heute, Rest' : ($i === 1 ? 'Morgen' : short_day_label($day)),
                'sun_kwh' => $sun === null ? null : round($sun, 1),
                'house_kwh' => $house === null ? null : round($house, 1),
                'balance_kwh' => $sun === null || $house === null ? null : round($sun - $house, 1),
            ];
        }
        return $out;
    }

    public function state(): array
    {
        $state = $this->store->get(self::KEY, []);
        return array_merge(
            ['active' => false, 'since' => null, 'until' => null, 'target_soc' => null, 'restore' => null, 'error' => null, 'error_at' => null, 'log' => []],
            is_array($state) ? $state : []
        );
    }

    /** Bedeutung einer Option des Betriebsmodus: manual, auto oder null. */
    public static function meaning(mixed $raw): ?string
    {
        $value = strtolower(trim((string) ($raw ?? '')));
        foreach (self::MODES as $meaning => $candidates) {
            if (in_array($value, $candidates, true)) {
                return $meaning;
            }
        }
        return null;
    }

    /** Die Option der Entität für eine Bedeutung. */
    public static function option(string $meaning, array $options): ?string
    {
        foreach ($options as $option) {
            if (in_array(strtolower(trim((string) $option)), self::MODES[$meaning], true)) {
                return (string) $option;
            }
        }
        return null;
    }

    /**
     * Was jetzt zu tun ist, ohne zu schreiben: start (Fenster läuft, laden lohnt, unter dem Ziel), stop (Ende des
     * Fensters, Ziel erreicht, abgeschaltet) oder none.
     *
     * @return array{action: string, why?: string}
     */
    public static function step(array $state, ?array $plan, bool $ready, ?float $soc, int $now): array
    {
        $active = !empty($state['active']);
        if ($active) {
            $why = match (true) {
                !$ready => 'off',
                $now >= (int) $state['until'] => 'end',
                $soc !== null && $state['target_soc'] !== null && $soc >= (float) $state['target_soc'] => 'target',
                default => null,
            };
            return $why === null ? ['action' => 'none'] : ['action' => 'stop', 'why' => $why];
        }
        $running = $plan !== null && (int) $plan['window']['start'] <= $now && $now < (int) $plan['window']['end'];
        if ($ready && $running && ($plan['reason'] ?? '') === 'charge' && $soc !== null && $plan['target_soc'] !== null && $soc < (float) $plan['target_soc'] - 0.5) {
            return ['action' => 'start'];
        }
        return ['action' => 'none'];
    }

    /** Lässt sich gerade schalten? Leer heißt ja, sonst der Grund. */
    public static function blocker(array $cfg, array $index): string
    {
        $s = self::settings($cfg);
        return match (true) {
            !$s['on'] => 'aus',
            !Controller::active($cfg) => 'EMS regelt noch nicht; der Hauptschalter steht unter Einstellungen.',
            $s['mode_entity'] === '' || $s['power_entity'] === '' => 'Betriebsmodus und „Laden erzwingen“ sind noch nicht zugeordnet (Einstellungen → Speicher).',
            in_array((string) ($index[$s['mode_entity']]['state'] ?? 'unavailable'), ['unavailable', 'unknown', ''], true) => 'Der Betriebsmodus (' . $s['mode_entity'] . ') ist in Home Assistant nicht verfügbar.',
            in_array((string) ($index[$s['power_entity']]['state'] ?? 'unavailable'), ['unavailable', 'unknown', ''], true) => '„Laden erzwingen“ (' . $s['power_entity'] . ') ist in Home Assistant nicht verfügbar.',
            self::option('manual', (array) ($index[$s['mode_entity']]['attributes']['options'] ?? [])) === null => 'Der Betriebsmodus kennt keine Option „manual“.',
            default => '',
        };
    }

    /**
     * Recorder (im Demo-Modus /api/live): startet und beendet das Laden aus dem Netz. Ein Fehler wartet 60 s; ein
     * laufendes Laden wird auch beendet, wenn EMS oder die Funktion inzwischen aus ist.
     */
    public function sync(array $snap, int $now): array
    {
        $state = $this->state();
        $cfg = $snap['cfg'];
        $plan = $snap['battery_plan'] ?? null;
        $soc = isset($snap['values']['battery_soc']) ? (float) $snap['values']['battery_soc'] : null;
        try {
            $index = $this->ha->index();
        } catch (Throwable) {
            return $state;
        }
        $blocker = self::blocker($cfg, $index);
        $step = self::step($state, $plan, $blocker === '', $soc, $now);
        if ($step['action'] === 'none') {
            return $state;
        }
        if (!empty($state['error_at']) && $now - (int) $state['error_at'] < self::RETRY_S) {
            return $state;
        }
        $s = self::settings($cfg);
        $modeEntity = (string) ($state['active'] ? ($state['mode_entity'] ?? $s['mode_entity']) : $s['mode_entity']);
        $powerEntity = (string) ($state['active'] ? ($state['power_entity'] ?? $s['power_entity']) : $s['power_entity']);
        $options = (array) ($index[$modeEntity]['attributes']['options'] ?? []);
        try {
            if ($step['action'] === 'start') {
                $current = (string) ($index[$modeEntity]['state'] ?? '');
                $restore = self::meaning($current) === 'manual' || $current === '' ? self::option('auto', $options) : $current;
                $manual = self::option('manual', $options);
                if ($manual === null) {
                    throw new RuntimeException('Der Betriebsmodus kennt keine Option „manual“.');
                }
                $this->ha->service('select', 'select_option', ['entity_id' => $modeEntity, 'option' => $manual]);
                $this->ha->setNumber($powerEntity, round($s['power_kw'] * 1000, -2));
                $state = array_merge($state, [
                    'active' => true, 'since' => $now, 'until' => (int) $plan['window']['end'], 'target_soc' => (float) $plan['target_soc'],
                    'restore' => $restore, 'mode_entity' => $modeEntity, 'power_entity' => $powerEntity, 'error' => null, 'error_at' => null,
                ]);
                $state['log'] = self::log($state['log'], $now, 'Lädt aus dem Netz mit ' . kw($s['power_kw']) . ' bis ' . pct((float) $plan['target_soc']) . ', längstens bis ' . date('H:i', (int) $plan['window']['end']) . '.');
            } else {
                $this->ha->setNumber($powerEntity, 0.0);
                $restore = (string) (($state['restore'] ?? '') ?: (self::option('auto', $options) ?? 'automatic'));
                $this->ha->service('select', 'select_option', ['entity_id' => $modeEntity, 'option' => $restore]);
                $why = match ($step['why'] ?? '') {
                    'target' => 'Ziel ' . pct((float) $state['target_soc']) . ' erreicht',
                    'end' => 'Ladefenster vorbei',
                    default => 'abgeschaltet',
                };
                $state = array_merge($state, ['active' => false, 'since' => null, 'until' => null, 'target_soc' => null, 'error' => null, 'error_at' => null]);
                $state['log'] = self::log($state['log'], $now, $why . ', zurück auf ' . $restore . '.');
            }
        } catch (Throwable $e) {
            $state = array_merge($state, ['error' => ($step['action'] === 'start' ? 'Starten' : 'Beenden') . ' fehlgeschlagen: ' . $e->getMessage(), 'error_at' => $now]);
        }
        $this->store->put(self::KEY, $state);
        return $state;
    }

    /** @param list<array{t:int, text:string}> $log */
    private static function log(array $log, int $now, string $text): array
    {
        array_unshift($log, ['t' => $now, 'text' => $text]);
        return array_slice($log, 0, 8);
    }

    /**
     * Sätze für Speicher → Ausblick: was EMS im nächsten Fenster tut und warum, mit der Rechnung. $state aus state(),
     * $blocker aus blocker().
     */
    public static function text(?array $plan, array $settings, array $state, string $blocker, int $now): array
    {
        $lines = [];
        if (!empty($state['active'])) {
            $lines[] = 'Lädt seit ' . date('H:i', (int) $state['since']) . ' aus dem Netz bis ' . pct((float) $state['target_soc']) . ', längstens bis ' . date('H:i', (int) $state['until']) . '.';
        }
        if ($plan === null) {
            $lines[] = 'Kein aktives Ladefenster bekannt. Die Fenster stehen unter Prognose → Strompreis.';
            return ['head' => $lines[0], 'lines' => array_slice($lines, 1)];
        }
        $label = PriceWindows::label($plan['window'], $now);
        $head = match ((string) $plan['reason']) {
            'charge' => 'Im Fenster ' . $label . ' lohnt es sich, ' . kwh((float) $plan['kwh'], 1) . ' aus dem Netz zu laden, bis ' . pct((float) $plan['target_soc']) . '.',
            'sun' => 'Im Fenster ' . $label . ' ist Laden aus dem Netz nicht nötig: Speicher und Sonne reichen voraussichtlich bis einen Tag danach.',
            'price' => 'Im Fenster ' . $label . ' lohnt Laden aus dem Netz nicht: Der Preisunterschied deckt Verluste und Verschleiß nicht.',
            'full' => 'Im Fenster ' . $label . ' ist der Speicher voraussichtlich schon so voll, wie er aus dem Netz laden darf.',
            default => 'Für das Fenster ' . $label . ' fehlen Ladestand, Kapazität oder der Hausverbrauch der letzten 30 Tage.',
        };
        if ($plan['reason'] !== 'unknown') {
            $sun = 'Danach erwartet: ' . kwh((float) $plan['sun_kwh'], 1) . ' Sonnenüberschuss';
            $sun .= (float) $plan['need_kwh'] >= 0.1 ? ', ohne Laden kämen ' . kwh((float) $plan['need_kwh'], 1) . ' aus dem Netz' . ($plan['later_ct'] !== null ? ' zu Ø ' . ct((float) $plan['later_ct']) : '') . '.' : ', der Speicher reicht.';
            $lines[] = $sun;
            if ($plan['window_ct'] !== null) {
                $lines[] = 'Im Fenster kostet die kWh Ø ' . ct((float) $plan['window_ct']) . ', mit ' . pct((1 - $settings['efficiency']) * 100) . ' Verlust und ' . ct($settings['wear_ct']) . ' Verschleiß ' . ct((float) $plan['cost_ct']) . ' je gespeicherter kWh.'
                    . ((float) $plan['saving_eur'] > 0 ? ' Spart etwa ' . euro((float) $plan['saving_eur']) . '.' : '');
            }
        }
        if ($blocker !== '' && $blocker !== 'aus' && ($plan['reason'] ?? '') === 'charge') {
            $lines[] = 'Geschaltet wird nicht: ' . $blocker;
        }
        return ['head' => $head, 'lines' => $lines];
    }
}
