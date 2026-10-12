<?php
declare(strict_types=1);

/**
 * Netzladen fürs Auto (charge.grid): „Jetzt“ lädt sofort voll aus dem Netz, „In Ladefenstern“ in den aktiven
 * Ladefenstern (PriceWindows), jeweils bis das Ziel erreicht ist. Für Regelung und Backup-Puffer gilt dann Netzladen
 * (effective()), die Modusauswahl bleibt. Hat das Ladeziel ein „fertig bis“, prüft der Plan, ob die Fenster davor
 * reichen, mit der Sonne, die bis dahin zu erwarten ist; reichen sie nicht, nimmt er die günstigsten weiteren
 * Viertelstunden vor der Uhrzeit dazu. Kennt EMS die Preise für morgen noch nicht und reicht die Zeit danach, wartet
 * der Rest auf sie (gegen 13 Uhr). Der Plan liegt in kv.charge_plan; der Recorder rechnet ihn alle 15 min neu, nach
 * neuen Preisen und wenn sich Ziel, Modus oder Fenster ändern.
 */
final class ChargePlan
{
    public const KEY = 'charge_plan';
    /** Ziel in Ladefenstern erreicht: bis zum Abstecken lädt das Auto in Fenstern nicht mehr aus dem Netz. */
    public const DONE = 'grid_done';
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

    /** Volle Ladeleistung in kW aus Höchststrom und Phasen. */
    public static function powerKw(array $charge): float
    {
        $maxA = min(16, max(6, (int) ($charge['max_a'] ?? 16)));
        return Energy::KW_PER_AMP * $maxA * (($charge['phase_mode'] ?? 'auto') === '1p' ? 1 : 3);
    }

    /** Ziel in Ladefenstern schon erreicht (bis zum Abstecken)? */
    public static function done(ConfigStore $store): bool
    {
        return (int) $store->get(self::DONE, 0) > 0;
    }

    /**
     * Recorder: Plan neu rechnen, wenn Netzladen in Ladefenstern gilt, das Ziel ein „fertig bis“ hat und sich etwas
     * geändert hat oder 15 min vorbei sind. Ohne das, nach dem Ziel oder ohne Auto verschwindet er.
     */
    public static function refresh(ConfigStore $store, array $snap, int $now, bool $force = false, ?PriceWindows $windows = null): ?array
    {
        $target = Target::get($store);
        $cfg = $snap['cfg'];
        $mode = (string) ($cfg['charge']['mode'] ?? 'smart');
        $grid = Energy::grid($cfg['charge']['grid'] ?? 'off');
        $connected = !empty($snap['vehicle']['connected']);
        $view = $target === null ? null : Target::view($target, Target::inputs($snap), $now);
        if ($target === null || empty($target['ready_by']) || !$connected || $grid !== 'windows' || $mode === 'aus' || self::done($store)
            || ($view['reached'] ?? false) || (int) $target['ready_by'] <= $now) {
            if (self::get($store) !== null) {
                $store->put(self::KEY, []);
            }
            return null;
        }
        $tariff = new Tariff($store);
        $windows ??= new PriceWindows($store, $tariff);
        $horizon = $tariff->market()->lastEnd();
        $deadline = (int) $target['ready_by'];
        $slots = $windows->slots($now, $deadline, $now);
        $old = self::get($store);
        $fresh = $old !== null
            && (int) ($old['target_set_at'] ?? 0) === (int) $target['set_at']
            && (int) ($old['horizon'] ?? 0) === $horizon
            && ($old['mode'] ?? '') === $mode
            && ($old['windows'] ?? '') === md5(implode(',', $slots))
            && $now - (int) ($old['computed_at'] ?? 0) < self::REPLAN_S;
        if ($fresh && !$force) {
            return $old;
        }
        $need = Target::neededKwh($target, Target::inputs($snap));
        $prices = [];
        for ($q = intdiv($now, 900) * 900; $q < $deadline; $q += 900) {
            $prices[$q] = $tariff->importCt($q);
        }
        $sun = in_array($mode, self::SUN_MODES, true) ? self::sun($store, $cfg, $snap, $now, $deadline) : [];
        $power = self::powerKw($cfg['charge']);
        $plan = $need === null
            ? ['slots' => $slots, 'extra' => [], 'need_kwh' => null, 'sun_kwh' => 0.0, 'window_kwh' => round(count($slots) * $power * 0.25, 2), 'grid_kwh' => null, 'avg_ct' => null, 'status' => 'unknown', 'deadline' => $deadline]
            : self::plan($need, $power, $now, $deadline, $prices, $sun, $slots);
        $plan += ['target_set_at' => (int) $target['set_at'], 'horizon' => $horizon, 'mode' => $mode, 'windows' => md5(implode(',', $slots)), 'computed_at' => $now, 'power_kw' => $power];
        $store->put(self::KEY, $plan);
        return $plan;
    }

    /**
     * Sonne fürs Auto je Viertelstunde: Prognose der Anlage minus Ø Haus der letzten 30 Tage, davon SUN_SHARE.
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
        return match ((string) $plan['status']) {
            'done' => 'Ziel erreicht.',
            'sun' => 'Bis ' . $until . ' reicht voraussichtlich die Sonne (' . $need . ' fehlen' . $sun . ').',
            'windows' => 'Bis ' . $until . ' reichen die Ladefenster: ' . $need . ' fehlen, sie schaffen bis zu ' . kwh((float) ($plan['window_kwh'] ?? 0), 0) . $sun . '.',
            'waiting' => 'Die Ladefenster reichen bis ' . $until . ' nicht. Den Rest plant EMS, sobald die Preise für morgen da sind (gegen 13 Uhr).',
            'tight' => 'Die Zeit bis ' . $until . ' ist knapp: Das Auto lädt durchgehend voll aus dem Netz.',
            'unknown' => 'Ohne Ladestand oder Akku des Autos lässt sich nicht rechnen, ob die Fenster bis ' . $until . ' reichen.',
            'partial' => 'Die Ladefenster reichen bis ' . $until . ' nicht. Dazu aus dem Netz: ' . $list . ', den Rest nach den Preisen für morgen.',
            default => 'Die Ladefenster reichen bis ' . $until . ' nicht. Dazu aus dem Netz: ' . $list . (($plan['extra_ct'] ?? null) !== null ? ' (Ø ' . ct((float) $plan['extra_ct']) . ')' : '') . '.',
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
