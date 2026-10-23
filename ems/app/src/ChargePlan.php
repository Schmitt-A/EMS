<?php
declare(strict_types=1);

/**
 * Netzladen fürs Auto (charge.grid): „Jetzt“ lädt sofort voll aus dem Netz, „In Ladefenstern“ in den Fenstern, die
 * man in der Ladepunkt-Karte gewählt und bestätigt hat (kv.grid_choice, aus PriceWindows::offered()), jeweils bis das
 * Ziel fürs Netzladen erreicht ist (Target, Bereich grid). Für Regelung und Backup-Puffer gilt dann Netzladen
 * (effective()), die Modusauswahl bleibt. Hat das Ziel fürs Netzladen ein „fertig bis“, prüft der Plan, ob die
 * gewählten Fenster davor reichen, mit der Sonne, die bis dahin zu erwarten ist; reichen sie nicht, nimmt er die
 * günstigsten weiteren Viertelstunden vor der Uhrzeit dazu. Kennt EMS die Preise für morgen noch nicht und reicht die
 * Zeit danach, wartet der Rest auf sie (gegen 13 Uhr). Der Plan liegt in kv.charge_plan; der Recorder rechnet ihn alle
 * 15 min neu, nach neuen Preisen und wenn sich Ziel, Modus oder Fenster ändern.
 */
final class ChargePlan
{
    public const KEY = 'charge_plan';
    /** Ziel in Ladefenstern erreicht: bis zum Abstecken lädt das Auto in Fenstern nicht mehr aus dem Netz. */
    public const DONE = 'grid_done';
    /** Die Fenster, die man fürs Auto gewählt hat: windows (start, end), at. Beim Abstecken verfällt die Wahl. */
    public const CHOICE = 'grid_choice';
    private const REPLAN_S = 900;
    /** Von der vorhergesagten Sonne zählt fürs Auto nur dieser Teil, der Rest bleibt Puffer für Wolken. */
    private const SUN_SHARE = 0.7;
    /** Modi, in denen das Auto zwischen den Fenstern Sonne bekommt. */
    private const SUN_MODES = ['smart', 'smart_dauerhaft'];

    public static function get(ConfigStore $store): ?array
    {
        $plan = $store->get(self::KEY, []);
        return is_array($plan) && isset($plan['slots']) ? $plan : null;
    }

    /**
     * Der Plan selbst, ohne Seiteneffekte. In einer Viertelstunde eines Fensters lädt das Auto voll, sonst bekommt es
     * die erwartete Sonne. Reicht das nicht, kommen die günstigsten übrigen Viertelstunden dazu (extra), bei gleichem
     * Preis die späteren, damit die Sonne vorher noch helfen kann.
     *
     * @param array<int, ?float> $prices Viertelstunde bis zur Uhrzeit => ct/kWh, null, solange der Preis fehlt
     * @param array<int, float> $sun Viertelstunde => kW Sonne, die fürs Auto zu erwarten ist
     * @param list<int> $windowSlots Viertelstunden aktiver Ladefenster
     * @return array{slots: list<int>, extra: list<int>, need_kwh: float, sun_kwh: float, window_kwh: float, grid_kwh: float, avg_ct: ?float, status: string, deadline: int}
     */
    public static function plan(float $needKwh, float $powerKw, int $now, int $deadline, array $prices, array $sun = [], array $windowSlots = []): array
    {
        $quarters = [];
        for ($q = intdiv($now, 900) * 900; $q < $deadline; $q += 900) {
            $quarters[] = $q;
        }
        $perQuarter = max(0.5, $powerKw) * 0.25;
        $inWindow = array_fill_keys(array_values(array_intersect($quarters, $windowSlots)), true);
        $sunQ = [];
        foreach ($quarters as $q) {
            $sunQ[$q] = isset($inWindow[$q]) ? 0.0 : min($powerKw, max(0.0, (float) ($sun[$q] ?? 0.0))) * 0.25;
        }
        $windowKwh = count($inWindow) * $perQuarter;
        $got = $windowKwh + array_sum($sunQ);
        $extra = [];
        $status = 'ok';
        if ($needKwh <= 0.05) {
            $status = 'done';
        } elseif ($got >= $needKwh - 1e-6) {
            $status = $inWindow ? 'windows' : 'sun';
        } else {
            $remaining = $needKwh - $got;
            $known = [];
            $unknownGain = 0.0;
            foreach ($quarters as $q) {
                if (isset($inWindow[$q])) {
                    continue;
                }
                if (isset($prices[$q]) && $prices[$q] !== null) {
                    $known[$q] = (float) $prices[$q];
                } else {
                    $unknownGain += $perQuarter - $sunQ[$q];
                }
            }
            // Was die Viertelstunden ohne Preis (meist morgen) noch schaffen können, darf auf deren Preise warten.
            $mustNow = max(0.0, $remaining - $unknownGain);
            $order = array_keys($known);
            usort($order, static fn (int $a, int $b): int => [$known[$a], $b] <=> [$known[$b], $a]);
            foreach ($order as $q) {
                if ($mustNow <= 1e-6) {
                    break;
                }
                $extra[] = $q;
                $mustNow -= $perQuarter - $sunQ[$q];
            }
            if ($mustNow > 1e-6) {
                // Die Zeit reicht nicht: jede Viertelstunde bis zur Uhrzeit.
                $status = 'tight';
                $extra = array_values(array_diff($quarters, array_keys($inWindow)));
            } elseif ($extra === []) {
                $status = 'waiting';
            } elseif ($remaining - array_sum(array_map(static fn (int $q): float => $perQuarter - $sunQ[$q], $extra)) > 1e-6) {
                $status = 'partial';
            }
        }
        sort($extra);
        $slots = array_values(array_unique(array_merge(array_keys($inWindow), $extra)));
        sort($slots);
        $sunKwh = 0.0;
        foreach ($sunQ as $q => $kwh) {
            if (!in_array($q, $extra, true)) {
                $sunKwh += $kwh;
            }
        }
        $avg = static function (array $quarters) use ($prices): ?float {
            $known = array_values(array_filter(array_map(static fn (int $q): ?float => isset($prices[$q]) ? (float) $prices[$q] : null, $quarters), static fn (?float $v): bool => $v !== null));
            return $known ? round(array_sum($known) / count($known), 2) : null;
        };
        return [
            'slots' => $slots,
            'extra' => $extra,
            'need_kwh' => round($needKwh, 2),
            'sun_kwh' => round(min($sunKwh, $needKwh), 2),
            'window_kwh' => round($windowKwh, 2),
            'grid_kwh' => round(count($slots) * $perQuarter, 2),
            'avg_ct' => $avg($slots),
            'extra_ct' => $avg($extra),
            'status' => $status,
            'deadline' => $deadline,
        ];
    }

    /**
     * Was jetzt gilt. Aus bleibt aus. Mit Netzladen „Jetzt“ Netzladen, mit „In Ladefenstern“ in einem aktiven Fenster
     * (window) oder einer zusätzlich geplanten Viertelstunde (plan), bis das Ziel erreicht ist ($done). Sonst der
     * eingestellte Modus.
     *
     * @return array{mode: string, reason: ?string}
     */
    public static function effective(string $mode, string $grid, bool $connected, bool $inWindow, ?array $plan, bool $done, int $now): array
    {
        if ($mode === 'aus' || !$connected) {
            return ['mode' => $mode, 'reason' => null];
        }
        if ($grid === 'now') {
            return ['mode' => 'netz', 'reason' => 'now'];
        }
        // Ein Ladeplan (GridPlans) gilt unabhängig von den Ladefenstern, auch mit statischem Tarif.
        if ($plan !== null && ($plan['source'] ?? 'target') === 'schedule' && in_array(intdiv($now, 900) * 900, (array) ($plan['extra'] ?? []), true)) {
            return ['mode' => 'netz', 'reason' => 'plan'];
        }
        if ($grid === 'windows' && !$done) {
            if ($plan !== null && in_array(intdiv($now, 900) * 900, (array) ($plan['extra'] ?? []), true)) {
                return ['mode' => 'netz', 'reason' => 'plan'];
            }
            if ($inWindow) {
                return ['mode' => 'netz', 'reason' => 'window'];
            }
        }
        return ['mode' => $mode, 'reason' => null];
    }

    /**
     * Volle Ladeleistung in kW aus Höchststrom und Phasen; nimmt das Auto weniger (fester Wert unter Fahrzeug oder beim
     * Netzladen gelernt, GridPlans::carKw()), gilt das.
     */
    public static function powerKw(array $charge, ?float $carKw = null): float
    {
        $maxA = min(16, max(6, (int) ($charge['max_a'] ?? 16)));
        $wallbox = Energy::KW_PER_AMP * $maxA * (($charge['phase_mode'] ?? 'auto') === '1p' ? 1 : 3);
        return $carKw !== null && $carKw > 0.5 ? min($wallbox, $carKw) : $wallbox;
    }

    /** Ob die Ladefenster genutzt werden sollen (Schalter in der Karte); von früher gilt grid windows. */
    public static function windowsWanted(array $charge): bool
    {
        return array_key_exists('grid_windows', $charge) && $charge['grid_windows'] !== null
            ? (bool) $charge['grid_windows']
            : Energy::grid($charge['grid'] ?? 'off') === 'windows';
    }

    /** Was nach Netzladen „Jetzt“ gilt: die Ladefenster, wenn ihr Schalter an ist, sonst aus. */
    public static function afterNow(array $charge): string
    {
        return self::windowsWanted($charge) ? 'windows' : 'off';
    }

    /** Ziel in Ladefenstern schon erreicht (bis zum Abstecken)? */
    public static function done(ConfigStore $store): bool
    {
        return (int) $store->get(self::DONE, 0) > 0;
    }

    /**
     * Die fürs Auto gewählten Fenster, die noch laufen oder kommen.
     *
     * @return list<array{start: int, end: int}>
     */
    public static function chosen(ConfigStore $store, int $now): array
    {
        $choice = $store->get(self::CHOICE, []);
        $out = [];
        foreach ((array) (is_array($choice) ? ($choice['windows'] ?? []) : []) as $w) {
            if (is_array($w) && is_numeric($w['start'] ?? null) && is_numeric($w['end'] ?? null) && (int) $w['end'] > $now) {
                $out[] = ['start' => (int) $w['start'], 'end' => (int) $w['end']];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        return $out;
    }

    /**
     * Was jetzt gilt, mit den Einstellungen von eben (nach einem erreichten Ziel oder dem Abstecken).
     *
     * @return array{mode: string, reason: ?string}
     */
    public static function effectiveNow(ConfigStore $store, array $snap, int $now): array
    {
        $charge = $store->all()['charge'];
        $values = $snap['values'] ?? [];
        $connected = Sessions::carConnected($values['wallbox_car_raw'] ?? null, isset($values['wallbox_kw']) ? (float) $values['wallbox_kw'] : null) !== false;
        $plan = self::get($store);
        $enabled = (new PriceWindows($store))->enabled($now);
        $schedule = $plan !== null && ($plan['source'] ?? 'target') === 'schedule';
        return self::effective(
            Energy::userMode($charge['mode'] ?? 'smart'),
            Energy::grid($charge['grid'] ?? 'off'),
            $connected,
            $enabled && PriceWindows::at(self::chosen($store, $now), $now) !== null,
            $enabled || $schedule ? $plan : null,
            self::done($store),
            $now
        );
    }

    /**
     * Recorder: Plan neu rechnen. Zuerst das Ziel der Ladefenster mit „fertig bis“ (nur mit dynamischem Tarif und
     * eingeschalteten Fenstern), sonst der nächste Ladeplan (GridPlans) in den kommenden 24 Stunden, dessen
     * Bedingungen gelten. Neu, wenn sich etwas geändert hat oder 15 min vorbei sind; ohne beides, nach dem Ziel, bei
     * „Jetzt“ oder ohne Auto verschwindet er. Nebenbei lernt EMS die Ladeleistung beim Netzladen.
     */
    public static function refresh(ConfigStore $store, array $snap, int $now, bool $force = false, ?PriceWindows $windows = null): ?array
    {
        GridPlans::learn($store, $snap, $now);
        $cfg = $snap['cfg'];
        $mode = (string) ($cfg['charge']['mode'] ?? 'smart');
        $grid = Energy::grid($cfg['charge']['grid'] ?? 'off');
        $connected = !empty($snap['vehicle']['connected']);
        $tariff = new Tariff($store);
        $windows ??= new PriceWindows($store, $tariff);
        $inputs = Target::inputs($snap);
        $source = null;
        $target = Target::get($store, 'grid');
        $view = $target === null ? null : Target::view($target, $inputs, $now);
        if ($target !== null && !empty($target['ready_by']) && $grid === 'windows' && !self::done($store) && $windows->enabled($now)
            && !($view['reached'] ?? false) && (int) $target['ready_by'] > $now) {
            $source = ['source' => 'target', 'target' => $target, 'key' => (int) $target['set_at']];
        } elseif ($grid !== 'now') {
            $due = GridPlans::upcoming(GridPlans::all($store), $now, (int) $store->get(GridPlans::DONE, 0));
            if ($due !== null && $due['deadline'] - $now <= GridPlans::HORIZON_S) {
                $car = $snap['vehicle'];
                $carSoc = isset($car['soc']) ? (float) $car['soc'] : null;
                $carRange = isset($car['range_km']) ? (float) $car['range_km'] : null;
                $planTarget = GridPlans::target($due, $due['deadline'], $carSoc, $carRange, !empty($car['limit_from_car']) && isset($car['limit']) ? (float) $car['limit'] : null);
                $planView = Target::view($planTarget, $inputs, $now);
                $cond = GridPlans::armed($store, $due, $due['deadline'], GridPlans::conditions($due, isset($snap['values']['battery_soc']) ? (float) $snap['values']['battery_soc'] : null,
                    self::sunKwh($store, $cfg, $snap, $now, $due['deadline']), $carSoc, $carRange), $connected);
                if ($planView['reached'] ?? false) {
                    // Erreicht: bis zur nächsten Uhrzeit dieses Plans erledigt.
                    $store->put(GridPlans::DONE, $due['deadline']);
                } elseif ($cond['ok']) {
                    $source = ['source' => 'schedule', 'target' => $planTarget, 'key' => $due['deadline'], 'plan_id' => $due['id'], 'conditions' => $cond['text']];
                }
            }
        }
        if ($source === null || !$connected || $mode === 'aus') {
            if (self::get($store) !== null) {
                $store->put(self::KEY, []);
            }
            return null;
        }
        $target = $source['target'];
        $horizon = $tariff->horizon();
        $deadline = (int) $target['ready_by'];
        $slots = $grid === 'windows' && $windows->enabled($now) ? PriceWindows::slotsOf(self::chosen($store, $now), $now, $deadline) : [];
        $power = self::powerKw($cfg['charge'], GridPlans::carKw($cfg, $store));
        $old = self::get($store);
        $fresh = $old !== null
            && ($old['source'] ?? 'target') === $source['source']
            && (int) ($old['target_set_at'] ?? 0) === $source['key']
            && (int) ($old['horizon'] ?? 0) === $horizon
            && ($old['mode'] ?? '') === $mode
            && ($old['windows'] ?? '') === md5(implode(',', $slots))
            && abs((float) ($old['power_kw'] ?? 0) - $power) < 0.05
            && $now - (int) ($old['computed_at'] ?? 0) < self::REPLAN_S;
        if ($fresh && !$force) {
            return $old;
        }
        $need = Target::neededKwh($target, $inputs);
        // Mit statischem Tarif kostet jede Viertelstunde gleich viel; plan() nimmt dann die spätesten.
        $prices = [];
        for ($q = intdiv($now, 900) * 900; $q < $deadline; $q += 900) {
            $prices[$q] = $tariff->importCt($q, 'car');
        }
        $sun = in_array($mode, self::SUN_MODES, true) ? self::sun($store, $cfg, $snap, $now, $deadline) : [];
        $plan = $need === null
            ? ['slots' => $slots, 'extra' => [], 'need_kwh' => null, 'sun_kwh' => 0.0, 'window_kwh' => round(count($slots) * $power * 0.25, 2), 'grid_kwh' => null, 'avg_ct' => null, 'status' => 'unknown', 'deadline' => $deadline]
            : self::plan($need, $power, $now, $deadline, $prices, $sun, $slots);
        $plan += [
            'source' => $source['source'],
            'plan_id' => $source['plan_id'] ?? null,
            'conditions' => $source['conditions'] ?? '',
            'target_set_at' => $source['key'],
            'horizon' => $horizon,
            'mode' => $mode,
            'windows' => md5(implode(',', $slots)),
            'computed_at' => $now,
            'power_kw' => $power,
        ];
        $store->put(self::KEY, $plan);
        return $plan;
    }

    /** Prognose der Anlage von jetzt bis zur Uhrzeit in kWh, für die Bedingung eines Plans; null ohne Prognose. */
    public static function sunKwh(ConfigStore $store, array $cfg, array $snap, int $now, int $deadline): ?float
    {
        $rows = Forecast::fromRadiation((new WeatherFeed($store))->hours(intdiv($now, 3600) * 3600, $deadline), (array) $cfg['plant']);
        if ($rows === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            // Die laufende Stunde nur mit dem Rest.
            $from = max($now, (int) $row['t']);
            $to = min($deadline, (int) $row['t'] + 3600);
            if ($to > $from) {
                $sum += (float) $row['kw'] * ($to - $from) / 3600;
            }
        }
        return round($sum, 1);
    }

    /**
     * Sonne fürs Auto je Viertelstunde: Prognose der Anlage minus üblicher Hausverbrauch, davon SUN_SHARE.
     *
     * @return array<int, float>
     */
    private static function sun(ConfigStore $store, array $cfg, array $snap, int $now, int $deadline): array
    {
        $rows = Forecast::fromRadiation((new WeatherFeed($store))->hours(intdiv($now, 3600) * 3600 - 3600, $deadline + 3600), (array) $cfg['plant']);
        $house = (float) ($snap['house_mean_kw'] ?? 0.5);
        $out = [];
        foreach ($rows as $row) {
            $surplus = max(0.0, (float) $row['kw'] - $house) * self::SUN_SHARE;
            for ($q = (int) $row['t']; $q < (int) $row['t'] + 3600; $q += 900) {
                if ($q >= intdiv($now, 900) * 900 && $q < $deadline && $surplus > 0.05) {
                    $out[$q] = $surplus;
                }
            }
        }
        return $out;
    }

    /** Ein Satz zum Plan für die Ladepunkt-Karte, leer ohne Plan. */
    public static function text(?array $plan): string
    {
        if ($plan === null) {
            return '';
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $time = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('H:i');
        $until = $time((int) $plan['deadline']);
        $extra = self::windows((array) ($plan['extra'] ?? []));
        $list = implode(', ', array_map(static fn (array $w): string => $time($w[0]) . '–' . $time($w[1]), array_slice($extra, 0, 3))) . (count($extra) > 3 ? ' …' : '');
        $need = ($plan['need_kwh'] ?? null) === null ? '' : kwh((float) $plan['need_kwh'], 1);
        $sun = (float) ($plan['sun_kwh'] ?? 0) >= 0.5 ? ', ' . kwh((float) $plan['sun_kwh'], 1) . ' Sonne erwartet' : '';
        // Ohne gewähltes Fenster plant EMS die Viertelstunden allein.
        $short = (float) ($plan['window_kwh'] ?? 0) > 0 ? 'Die gewählten Fenster reichen bis ' . $until . ' nicht.' : 'Ohne gewähltes Fenster bis ' . $until . ':';
        if (($plan['source'] ?? 'target') === 'schedule') {
            $day = (new DateTimeImmutable('@' . (int) $plan['deadline']))->setTimezone($tz)->format('Y-m-d') === (new DateTimeImmutable('now', $tz))->format('Y-m-d') ? '' : day_label((new DateTimeImmutable('@' . (int) $plan['deadline']))->setTimezone($tz)->format('Y-m-d')) . ' ';
            $short = 'Ladeplan bis ' . $day . $until . ':';
            if (in_array((string) $plan['status'], ['sun', 'windows'], true)) {
                return $short . ' ' . ((string) $plan['status'] === 'sun' ? 'Die Sonne reicht voraussichtlich' : 'Die gewählten Fenster reichen') . ', ' . $need . ' fehlen' . $sun . '.';
            }
        }
        return match ((string) $plan['status']) {
            'done' => 'Ziel erreicht.',
            'sun' => 'Bis ' . $until . ' reicht voraussichtlich die Sonne (' . $need . ' fehlen' . $sun . ').',
            'windows' => 'Bis ' . $until . ' reichen die gewählten Fenster: ' . $need . ' fehlen, sie schaffen bis zu ' . kwh((float) ($plan['window_kwh'] ?? 0), 0) . $sun . '.',
            'waiting' => $short . ' Den Rest plant EMS, sobald die Preise für morgen da sind (gegen 13 Uhr).',
            'tight' => 'Die Zeit bis ' . $until . ' ist knapp: Das Auto lädt durchgehend voll aus dem Netz.',
            'unknown' => 'Ohne Ladestand oder Akku des Autos lässt sich nicht rechnen, ob die Fenster bis ' . $until . ' reichen.',
            'partial' => $short . ' Dazu aus dem Netz: ' . $list . ', den Rest nach den Preisen für morgen.',
            default => $short . ' Dazu aus dem Netz: ' . $list . (($plan['extra_ct'] ?? null) !== null ? ' (Ø ' . ct((float) $plan['extra_ct']) . ')' : '') . '.',
        };
    }

    /**
     * Zusammenhängende Zeitfenster aus Viertelstunden.
     *
     * @param list<int> $slots
     * @return list<array{0: int, 1: int}>
     */
    public static function windows(array $slots): array
    {
        sort($slots);
        $out = [];
        foreach ($slots as $q) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][1] === $q) {
                $out[$last][1] = $q + 900;
            } else {
                $out[] = [$q, $q + 900];
            }
        }
        return $out;
    }
}
