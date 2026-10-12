<?php
declare(strict_types=1);

/**
 * Hausspeicher in einem Ladefenster aus dem Netz laden (Speicher → Ausblick). Die Empfehlung rechnet decide() für das
 * laufende oder nächste aktive Fenster, je Viertelstunde bis 24 Stunden danach: Sonne laut Prognose, Haus aus dem
 * üblichen Tagesprofil, Preise im aktiven Tarif (unbekannte wie am Vortag). Es probiert, bis zu welchem Stand der
 * Speicher im Fenster laden sollte, und nimmt den mit den geringsten Kosten: Netzbezug im Fenster und danach, minus
 * Einspeisung, plus Verschleiß je gespeicherter kWh, mit Verlusten. Füllt die Sonne den Speicher ohnehin, oder ist der
 * Preisunterschied zu klein, rät es ab.
 * Geladen wird nur mit einem Energie-Boost (kv.battery_boost): einmalig in einem gewählten Fenster bis zu einem
 * Ladestand, von Hand oder aus der Empfehlung übernommen; am Ziel oder am Ende des Fensters verfällt er. sync()
 * schaltet über Home Assistant: Betriebsmodus manuell und „Laden erzwingen“ mit der Ladeleistung, danach Leistung 0
 * und der Betriebsmodus von vorher. Nur bei aktivem EMS. Zustand und Protokoll in kv.battery_grid.
 */
final class BatteryPlan
{
    public const KEY = 'battery_grid';
    /** Der Energie-Boost: start, end (das Fenster), target_soc, set_at. */
    public const BOOST = 'battery_boost';
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
     * Viertelstunden für decide(): Sonne aus der stündlichen Prognose, Haus aus dem üblichen Tagesprofil, Preis im
     * aktiven Tarif; ohne Preis der vom Vortag zur selben Zeit.
     *
     * @param array<int, array{t:int, kw:float}> $series stündliche Prognose (Forecast::fromRadiation)
     * @param array<int, float> $profile Haus in kW je Stunde des Tages (Energy::houseProfile())
     */
    public static function quarters(array $series, array $profile, Tariff $tariff, int $from, int $to): array
    {
        $pv = [];
        foreach ($series as $point) {
            $pv[(int) $point['t']] = max(0.0, (float) $point['kw']);
        }
        $out = [];
        for ($q = intdiv($from, 900) * 900; $q < $to; $q += 900) {
            $hour = intdiv($q, 3600) * 3600;
            $ct = $tariff->importCt($q) ?? $tariff->importCt($q - 86400);
            $out[] = [$q, $pv[$hour] ?? 0.0, self::houseAt($profile, $q), $ct];
        }
        return $out;
    }

    /**
     * Die Empfehlung für den Ausblick: von den aktiven Fenstern, die laufen oder kommen, das, in dem Laden aus dem Netz am
     * meisten spart; lohnt keines, die Entscheidung fürs nächste mit ihrem Grund. null ohne Fenster (mit statischem
     * Tarif gibt es keine).
     */
    public static function plan(array $cfg, array $values, array $series, ?array $profile, PriceWindows $windows, Tariff $tariff, int $now): ?array
    {
        $candidates = $windows->offered($now, 6);
        $window = $candidates[0] ?? null;
        $capacity = is_numeric($values['battery_total_kwh'] ?? null) && (float) $values['battery_total_kwh'] > 0 ? (float) $values['battery_total_kwh']
            : (is_numeric($values['battery_capacity_kwh'] ?? null) && is_numeric($values['battery_soc'] ?? null) && (float) $values['battery_soc'] > 1
                ? (float) $values['battery_capacity_kwh'] / ((float) $values['battery_soc'] / 100) : null);
        if ($window === null || $profile === null || $capacity === null || !is_numeric($values['battery_soc'] ?? null)) {
            return $window === null ? null : ['window' => $window, 'reason' => 'unknown', 'kwh' => 0.0, 'target_soc' => null];
        }
        $s = self::settings($cfg);
        $t = $tariff->tariffAt($now);
        $best = null;
        $first = null;
        foreach ($candidates as $candidate) {
            $result = ['window' => $candidate] + self::decide([
                'soc' => (float) $values['battery_soc'],
                'capacity_kwh' => $capacity,
                'floor_soc' => $s['floor_soc'],
                'max_soc' => $s['max_soc'],
                'power_kw' => $s['power_kw'],
                'efficiency' => $s['efficiency'],
                'wear_ct' => $s['wear_ct'],
                'export_ct' => (float) $t['export_ct'],
                'window' => $candidate,
                'quarters' => self::quarters($series, $profile, $tariff, $now, (int) $candidate['end'] + self::HORIZON_S),
            ]);
            $first ??= $result;
            if ($result['reason'] === 'charge' && ($best === null || $result['saving_eur'] > $best['saving_eur'])) {
                $best = $result;
            }
        }
        return $best ?? $first;
    }

    /**
     * Die nächsten Tage für den Ausblick: Sonne laut Prognose (heute der Rest, wie auf der Seite Prognose), Haus aus dem
     * üblichen Tagesprofil ohne Wallbox (heute ab jetzt) und was übrig bleibt oder fehlt.
     *
     * @param ?array<int, float> $profile Haus in kW je Stunde des Tages
     * @return list<array{day: string, label: string, sun_kwh: ?float, house_kwh: ?float, balance_kwh: ?float}>
     */
    public static function days(array $series, array $plant, ?array $profile, int $now, ?array $brief, int $count = 3): array
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
            $from = $i === 0 ? max($now, $day->getTimestamp()) : $day->getTimestamp();
            $sun = match ($i) {
                0 => isset($brief['remaining_kwh']) ? (float) $brief['remaining_kwh'] : null,
                1 => isset($brief['tomorrow_kwh']) ? (float) $brief['tomorrow_kwh'] : ($totals[$key] ?? null),
                default => $totals[$key] ?? null,
            };
            $house = $profile === null ? null : self::houseKwh($profile, $from, $next->getTimestamp());
            $out[] = [
                'day' => $key,
                'label' => $i === 0 ? 'Heute ab ' . date('H:i', intdiv($now, 900) * 900) : ($i === 1 ? 'Morgen' : short_day_label($day)),
                'sun_kwh' => $sun === null ? null : round($sun, 1),
                'house_kwh' => $house === null ? null : round($house, 1),
                'balance_kwh' => $sun === null || $house === null ? null : round($sun - $house, 1),
            ];
        }
        return $out;
    }

    /** Haus in kW zu einem Zeitpunkt aus dem Tagesprofil. */
    public static function houseAt(array $profile, int $ts): float
    {
        return max(0.0, (float) ($profile[(int) date('G', $ts)] ?? (array_sum($profile) / max(1, count($profile)))));
    }

    /** Haus in kWh zwischen zwei Zeitpunkten aus dem Tagesprofil, je Viertelstunde. */
    public static function houseKwh(array $profile, int $from, int $to): float
    {
        $kwh = 0.0;
        for ($q = $from; $q < $to; $q += 900) {
            $kwh += self::houseAt($profile, $q) * min(900, $to - $q) / 3600;
        }
        return $kwh;
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

    /** Der Energie-Boost, solange sein Fenster nicht vorbei ist, sonst null. */
    public static function boost(ConfigStore $store, int $now): ?array
    {
        $boost = $store->get(self::BOOST, []);
        if (!is_array($boost) || !is_numeric($boost['start'] ?? null) || !is_numeric($boost['end'] ?? null) || !is_numeric($boost['target_soc'] ?? null) || (int) $boost['end'] <= $now) {
            return null;
        }
        return ['start' => (int) $boost['start'], 'end' => (int) $boost['end'], 'target_soc' => (float) $boost['target_soc'], 'set_at' => (int) ($boost['set_at'] ?? 0)];
    }

    /**
     * Was jetzt zu tun ist, ohne zu schreiben: start (das Fenster des Boosts läuft, unter dem Ziel), stop (Ende des
     * Fensters, Ziel erreicht, Boost oder EMS aus) oder none.
     *
     * @return array{action: string, why?: string}
     */
    public static function step(array $state, ?array $boost, bool $ready, ?float $soc, int $now): array
    {
        if (!empty($state['active'])) {
            $why = match (true) {
                !$ready || $boost === null => 'off',
                $now >= (int) $state['until'] => 'end',
                $soc !== null && $state['target_soc'] !== null && $soc >= (float) $state['target_soc'] => 'target',
                default => null,
            };
            return $why === null ? ['action' => 'none'] : ['action' => 'stop', 'why' => $why];
        }
        if ($ready && $boost !== null && $boost['start'] <= $now && $now < $boost['end'] && $soc !== null && $soc < $boost['target_soc'] - 0.5) {
            return ['action' => 'start'];
        }
        return ['action' => 'none'];
    }

    /** Lässt sich gerade schalten? Leer heißt ja, sonst der Grund. */
    public static function blocker(array $cfg, array $index): string
    {
        $s = self::settings($cfg);
        return match (true) {
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
        $boost = self::boost($this->store, $now);
        if ($boost === null && $this->store->get(self::BOOST, []) !== []) {
            // Das Fenster ist vorbei, ohne dass geladen wurde (etwa weil EMS nicht regelte): der Boost verfällt.
            $this->store->put(self::BOOST, []);
        }
        $soc = isset($snap['values']['battery_soc']) ? (float) $snap['values']['battery_soc'] : null;
        try {
            $index = $this->ha->index();
        } catch (Throwable) {
            return $state;
        }
        $blocker = self::blocker($cfg, $index);
        $step = self::step($state, $boost, $blocker === '', $soc, $now);
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
                    'active' => true, 'since' => $now, 'until' => $boost['end'], 'target_soc' => $boost['target_soc'],
                    'restore' => $restore, 'mode_entity' => $modeEntity, 'power_entity' => $powerEntity, 'error' => null, 'error_at' => null,
                ]);
                $state['log'] = self::log($state['log'], $now, 'Energie-Boost: lädt aus dem Netz mit ' . kw($s['power_kw']) . ' bis ' . pct($boost['target_soc']) . ', längstens bis ' . date('H:i', $boost['end']) . '.');
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
                if (in_array($step['why'] ?? '', ['target', 'end'], true)) {
                    // Einmalig: Am Ziel oder am Ende des Fensters ist der Boost verbraucht.
                    $this->store->put(self::BOOST, []);
                }
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
     * Die Empfehlung für Speicher → Ausblick: ein Satz zum laufenden oder nächsten Fenster, ob und bis wohin Laden aus
     * dem Netz lohnt, und die Rechnung dazu. charge sagt, ob sich die Empfehlung als Energie-Boost übernehmen lässt.
     *
     * @return array{head: string, lines: list<string>, charge: bool}
     */
    public static function recommendation(?array $plan, array $settings, int $now): array
    {
        if ($plan === null) {
            return ['head' => 'Kein aktives Ladefenster bekannt, die Fenster stehen unter Prognose → Strompreis.', 'lines' => [], 'charge' => false];
        }
        $label = PriceWindows::label($plan['window'], $now);
        $head = match ((string) $plan['reason']) {
            'charge' => 'Empfehlung: im Fenster ' . $label . ' bis ' . pct((float) $plan['target_soc']) . ' aus dem Netz laden, etwa ' . kwh((float) $plan['kwh'], 1) . '. Spart etwa ' . euro((float) $plan['saving_eur']) . '.',
            'sun' => 'Empfehlung: im Fenster ' . $label . ' nicht aus dem Netz laden. Speicher und Sonne reichen voraussichtlich bis einen Tag danach.',
            'price' => 'Empfehlung: im Fenster ' . $label . ' nicht aus dem Netz laden. Der Preisunterschied deckt Verluste und Verschleiß nicht.',
            'full' => 'Empfehlung: im Fenster ' . $label . ' nicht laden, der Speicher ist dann voraussichtlich schon voll genug.',
            default => 'Für das Fenster ' . $label . ' fehlen Ladestand, Kapazität oder der übliche Hausverbrauch.',
        };
        $lines = [];
        if ($plan['reason'] !== 'unknown') {
            $lines[] = 'Bis einen Tag nach dem Fenster erwartet: ' . kwh((float) $plan['sun_kwh'], 1) . ' Sonnenüberschuss'
                . ((float) $plan['need_kwh'] >= 0.1 ? '; ohne Laden kämen ' . kwh((float) $plan['need_kwh'], 1) . ' aus dem Netz' . ($plan['later_ct'] !== null ? ' zu Ø ' . ct((float) $plan['later_ct']) : '') . '.' : '; der Speicher reicht.');
            if ($plan['window_ct'] !== null) {
                $lines[] = 'Im Fenster kostet die kWh Ø ' . ct((float) $plan['window_ct']) . ', mit ' . pct((1 - $settings['efficiency']) * 100) . ' Verlust und ' . ct($settings['wear_ct']) . ' Verschleiß ' . ct((float) $plan['cost_ct']) . ' je gespeicherter kWh.';
            }
        }
        return ['head' => $head, 'lines' => $lines, 'charge' => ($plan['reason'] ?? '') === 'charge'];
    }
}
