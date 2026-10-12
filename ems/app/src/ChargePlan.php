<?php
declare(strict_types=1);

/**
 * Laden nach Preis (Schritt 2 des dynamischen Tarifs), nur in den Modi Solar und Min+Solar:
 * - Ladeplan: Hat das Ladeziel ein „fertig bis“, sucht EMS die günstigsten Viertelstunden davor, zieht die erwartete
 *   Sonne ab und lädt in diesen Viertelstunden voll aus dem Netz. Bei gleichem Preis (statischer Tarif) nimmt es die
 *   späteren, damit die Sonne vorher noch helfen kann. Kennt EMS die Preise für morgen noch nicht und reicht die Zeit
 *   danach, wartet der Plan auf sie (gegen 13 Uhr) statt auf Verdacht zu laden.
 * - Preisgrenze (charge.price_limit_ct): Liegt der dynamische Preis jetzt darunter, lädt EMS voll aus dem Netz.
 * In beiden Fällen gilt für die Regelung und den Backup-Puffer Netzladen (effective()), die Modusauswahl bleibt.
 * Der Plan liegt in kv.charge_plan; der Recorder rechnet ihn alle 15 min neu, nach neuen Preisen und wenn sich das
 * Ziel ändert.
 */
final class ChargePlan
{
    public const KEY = 'charge_plan';
    private const REPLAN_S = 900;
    /** Von der vorhergesagten Sonne zählt fürs Auto nur dieser Teil, der Rest bleibt Puffer für Wolken. */
    private const SUN_SHARE = 0.7;
    /** Modi, in denen Plan und Preisgrenze gelten. */
    public const MODES = ['smart', 'smart_dauerhaft'];

    public static function get(ConfigStore $store): ?array
    {
        $plan = $store->get(self::KEY, []);
        return is_array($plan) && isset($plan['slots']) ? $plan : null;
    }

    /**
     * Der Plan selbst, ohne Seiteneffekte.
     *
     * @param array<int, ?float> $prices Viertelstunde bis zur Uhrzeit => ct/kWh, null, solange der Preis fehlt
     * @param array<int, float> $sun Viertelstunde => kW Sonne, die fürs Auto zu erwarten ist
     * @return array{slots: list<int>, need_kwh: float, sun_kwh: float, grid_kwh: float, avg_ct: ?float, status: string, deadline: int}
     */
    public static function plan(float $needKwh, float $powerKw, int $now, int $deadline, array $prices, array $sun = []): array
    {
        $quarters = [];
        for ($q = intdiv($now, 900) * 900; $q < $deadline; $q += 900) {
            $quarters[] = $q;
        }
        $perQuarter = max(0.5, $powerKw) * 0.25;
        $sunKwh = 0.0;
        foreach ($quarters as $q) {
            $sunKwh += min($powerKw, max(0.0, (float) ($sun[$q] ?? 0.0))) * 0.25;
        }
        $sunKwh = min($sunKwh, $needKwh);
        $grid = max(0.0, $needKwh - $sunKwh);
        $count = (int) ceil($grid / $perQuarter - 1e-9);
        $known = [];
        $unknown = 0;
        foreach ($quarters as $q) {
            if (isset($prices[$q]) && $prices[$q] !== null) {
                $known[$q] = (float) $prices[$q];
            } else {
                $unknown++;
            }
        }
        $status = 'ok';
        if ($count === 0) {
            $status = $needKwh <= 0.05 ? 'done' : 'sun';
        } elseif ($count > count($quarters)) {
            // Die Zeit reicht nicht: jede Viertelstunde bis zur Uhrzeit.
            $status = 'tight';
        } elseif ($unknown >= $count) {
            // Die Viertelstunden ohne Preis (meist die Nacht) reichen allein; auf ihre Preise warten.
            $status = 'waiting';
        } elseif ($unknown > 0) {
            // Nur so viel aus den bekannten Preisen, wie die Zeit ohne Preis nicht schafft; der Rest später.
            $status = 'partial';
        }
        $slots = [];
        if ($status === 'tight') {
            $slots = $quarters;
        } elseif ($status === 'ok' || $status === 'partial') {
            $take = $status === 'partial' ? $count - $unknown : $count;
            $order = array_keys($known);
            // Günstigste zuerst, bei gleichem Preis die spätere, damit die Sonne vorher noch helfen kann.
            usort($order, static fn (int $a, int $b): int => [$known[$a], $b] <=> [$known[$b], $a]);
            $slots = array_slice($order, 0, max(0, $take));
            sort($slots);
        }
        $prices = array_values(array_filter(array_map(static fn (int $q): ?float => $known[$q] ?? null, $slots), static fn (?float $v): bool => $v !== null));
        return [
            'slots' => array_values($slots),
            'need_kwh' => round($needKwh, 2),
            'sun_kwh' => round($sunKwh, 2),
            'grid_kwh' => round($grid, 2),
            'avg_ct' => $prices ? round(array_sum($prices) / count($prices), 2) : null,
            'status' => $status,
            'deadline' => $deadline,
        ];
    }

    /**
     * Was jetzt gilt: in Solar und Min+Solar Netzladen, wenn jetzt eine Viertelstunde des Plans ist (plan) oder der
     * dynamische Preis höchstens die Preisgrenze ist (limit). Sonst der eingestellte Modus.
     *
     * @return array{mode: string, reason: ?string}
     */
    public static function effective(string $mode, ?array $plan, ?float $priceCt, ?float $limitCt, bool $dynamic, bool $connected, int $now): array
    {
        if (!in_array($mode, self::MODES, true) || !$connected) {
            return ['mode' => $mode, 'reason' => null];
        }
        if ($plan !== null && in_array(intdiv($now, 900) * 900, (array) $plan['slots'], true)) {
            return ['mode' => 'netz', 'reason' => 'plan'];
        }
        if ($dynamic && $limitCt !== null && $priceCt !== null && $priceCt <= $limitCt) {
            return ['mode' => 'netz', 'reason' => 'limit'];
        }
        return ['mode' => $mode, 'reason' => null];
    }

    /** Preisgrenze aus den Ladeparametern, null ohne. */
    public static function limit(array $charge): ?float
    {
        return is_numeric($charge['price_limit_ct'] ?? null) ? (float) $charge['price_limit_ct'] : null;
    }

    /** Volle Ladeleistung in kW aus Höchststrom und Phasen. */
    public static function powerKw(array $charge): float
    {
        $maxA = min(16, max(6, (int) ($charge['max_a'] ?? 16)));
        return Energy::KW_PER_AMP * $maxA * (($charge['phase_mode'] ?? 'auto') === '1p' ? 1 : 3);
    }

    /**
     * Recorder: Plan neu rechnen, wenn das Ziel ein „fertig bis“ hat und sich etwas geändert hat oder 15 min vorbei
     * sind; ohne Ziel, nach dem Ziel oder ohne Auto verschwindet er.
     */
    public static function refresh(ConfigStore $store, array $snap, int $now, bool $force = false): ?array
    {
        $target = Target::get($store);
        $cfg = $snap['cfg'];
        $mode = (string) ($cfg['charge']['mode'] ?? 'smart');
        $connected = !empty($snap['vehicle']['connected']);
        $view = $target === null ? null : Target::view($target, Target::inputs($snap), $now);
        if ($target === null || empty($target['ready_by']) || !$connected || ($view['reached'] ?? false) || (int) $target['ready_by'] <= $now) {
            if (self::get($store) !== null) {
                $store->put(self::KEY, []);
            }
            return null;
        }
        $tariff = new Tariff($store);
        $horizon = $tariff->market()->lastEnd();
        $old = self::get($store);
        $fresh = $old !== null
            && (int) ($old['target_set_at'] ?? 0) === (int) $target['set_at']
            && (int) ($old['horizon'] ?? 0) === $horizon
            && ($old['mode'] ?? '') === $mode
            && $now - (int) ($old['computed_at'] ?? 0) < self::REPLAN_S;
        if ($fresh && !$force) {
            return $old;
        }
        $need = Target::neededKwh($target, Target::inputs($snap));
        $deadline = (int) $target['ready_by'];
        $prices = [];
        for ($q = intdiv($now, 900) * 900; $q < $deadline; $q += 900) {
            $prices[$q] = $tariff->importCt($q);
        }
        $sun = in_array($mode, self::MODES, true) ? self::sun($store, $cfg, $snap, $now, $deadline) : [];
        $plan = $need === null
            ? ['slots' => [], 'need_kwh' => null, 'sun_kwh' => 0.0, 'grid_kwh' => null, 'avg_ct' => null, 'status' => 'unknown', 'deadline' => $deadline]
            : self::plan($need, self::powerKw($cfg['charge']), $now, $deadline, $prices, $sun);
        $plan += ['target_set_at' => (int) $target['set_at'], 'horizon' => $horizon, 'mode' => $mode, 'computed_at' => $now, 'power_kw' => self::powerKw($cfg['charge'])];
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
        $until = 'fertig bis ' . $time((int) $plan['deadline']);
        $windows = self::windows((array) $plan['slots']);
        $list = implode(', ', array_map(static fn (array $w): string => $time($w[0]) . '–' . $time($w[1]), array_slice($windows, 0, 3))) . (count($windows) > 3 ? ' …' : '');
        $sun = (float) ($plan['sun_kwh'] ?? 0) >= 0.5 ? ', ' . kwh((float) $plan['sun_kwh'], 1) . ' von der Sonne erwartet' : '';
        $avg = $plan['avg_ct'] !== null ? ' zu Ø ' . ct((float) $plan['avg_ct']) : '';
        return match ((string) $plan['status']) {
            'done' => 'Ziel erreicht.',
            'sun' => 'Plan: Die Sonne reicht bis zur Uhrzeit' . $sun . ', ' . $until . '.',
            'waiting' => 'Plan: Wartet auf die Preise für morgen (gegen 13 Uhr), ' . $until . $sun . '.',
            'tight' => 'Plan: Die Zeit ist knapp, lädt bis zur Uhrzeit durchgehend voll aus dem Netz' . $sun . '.',
            'unknown' => 'Plan: Ohne Ladestand oder Akku des Autos lässt sich die Menge nicht rechnen.',
            'partial' => 'Plan: ' . $list . ' aus dem Netz' . $avg . ', den Rest nach den Preisen für morgen, ' . $until . $sun . '.',
            default => 'Plan: ' . $list . ' aus dem Netz' . $avg . ', ' . $until . $sun . '.',
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
