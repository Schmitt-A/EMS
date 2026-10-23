<?php
declare(strict_types=1);

/**
 * Ladepläne des Hausspeichers (kv.battery_plans), wie die Ladepläne fürs Auto (GridPlans): an gewählten Wochentagen
 * soll der Speicher zu einer Uhrzeit einen Ladestand erreicht haben, optional nur, wenn er unter einem Ladestand liegt
 * und/oder bis dahin wenig Sonne erwartet wird. Liegt die nächste Uhrzeit eines eingeschalteten Plans in den kommenden
 * 24 Stunden, plant refresh() die Viertelstunden davor: die günstigsten zum Preis des Speichers (im statischen Tarif in
 * seinen Zeitfenstern, im dynamischen der Preis der Viertelstunde), bei gleichem Preis die späteren. schedule() rechnet
 * dafür den Speicher Viertelstunde für Viertelstunde bis zur Uhrzeit durch: Sonne lädt, das Haus nimmt bis zum
 * Backup-Puffer, in geplanten Viertelstunden lädt das Netz; es nimmt so lange die nächstgünstige dazu, bis der
 * Ladestand zur Uhrzeit reicht. Gespeichert in kv.battery_plan_state; BatteryPlan::sync() lädt in diesen
 * Viertelstunden aus dem Netz, nur bei aktivem EMS. Gelten die Bedingungen einmal, bleibt der Plan für diese Uhrzeit
 * dabei (kv.battery_plan_armed); erreicht ist er bis zu seiner nächsten Uhrzeit erledigt (kv.battery_plan_done).
 */
final class BatteryPlans
{
    public const KEY = 'battery_plans';
    public const STATE = 'battery_plan_state';
    public const DONE = 'battery_plan_done';
    public const ARMED = 'battery_plan_armed';
    public const MAX = 10;
    private const REPLAN_S = 900;
    /** Von der erwarteten Sonne zählt nur ein Teil, wie beim Auto. */
    private const SUN_SHARE = 0.7;

    /**
     * Die Viertelstunden zum Laden aus dem Netz, ohne Seiteneffekte. $in: e_kwh (jetzt im Speicher), capacity_kwh,
     * floor_kwh (Backup-Puffer), target_kwh, power_kw (aus dem Netz), efficiency (0…1), quarters: Liste [t, Sonne kW,
     * Haus kW, ct/kWh oder null] ab jetzt bis zur Uhrzeit. Sonne lädt erst das Haus, dann den Speicher; fehlt sie, nimmt
     * das Haus aus dem Speicher bis zum Puffer. In einer geplanten Viertelstunde lädt das Netz mit voller Leistung, das
     * Haus kommt dann aus Sonne und Netz.
     *
     * @return array{status: string, slots: list<int>, grid_kwh: float, sun_kwh: float, drain_kwh: float, avg_ct: ?float, end_kwh: float}
     */
    public static function schedule(array $in): array
    {
        $cap = max(0.1, (float) $in['capacity_kwh']);
        $floor = clamp_float((float) $in['floor_kwh'], 0, $cap);
        $target = min($cap, (float) $in['target_kwh']);
        $add = max(0.1, (float) $in['power_kw']) * max(0.5, min(1.0, (float) $in['efficiency'])) * 0.25;
        $quarters = (array) $in['quarters'];
        $run = static function (array $charge) use ($in, $cap, $floor, $add, $quarters): array {
            $e = clamp_float((float) $in['e_kwh'], 0, $cap);
            $sun = $drain = 0.0;
            foreach ($quarters as [$t, $pv, $house]) {
                if (isset($charge[$t])) {
                    $e = min($cap, $e + $add);
                    continue;
                }
                $net = (max(0.0, (float) $pv) * self::SUN_SHARE - max(0.0, (float) $house)) * 0.25;
                if ($net >= 0) {
                    $keep = min($net, $cap - $e);
                    $e += $keep;
                    $sun += $keep;
                } else {
                    $take = min(-$net, max(0.0, $e - $floor));
                    $e -= $take;
                    $drain += $take;
                }
            }
            return ['e' => $e, 'sun' => $sun, 'drain' => $drain];
        };
        $base = $run([]);
        $out = ['status' => 'sun', 'slots' => [], 'grid_kwh' => 0.0, 'sun_kwh' => round($base['sun'], 1), 'drain_kwh' => round($base['drain'], 1), 'avg_ct' => null, 'end_kwh' => round($base['e'], 1)];
        if ((float) $in['e_kwh'] >= $target - 0.05) {
            return ['status' => 'done'] + $out;
        }
        if ($base['e'] >= $target - 0.05) {
            return $out;
        }
        // Günstigste zuerst, bei gleichem Preis die späteren; Viertelstunden ohne Preis zuletzt.
        $order = $quarters;
        usort($order, static fn (array $a, array $b): int => [$a[3] === null ? 1 : 0, $a[3] ?? 0.0, -$a[0]] <=> [$b[3] === null ? 1 : 0, $b[3] ?? 0.0, -$b[0]]);
        $charge = [];
        $result = $base;
        foreach ($order as $quarter) {
            $charge[$quarter[0]] = true;
            $result = $run($charge);
            if ($result['e'] >= $target - 0.05) {
                break;
            }
        }
        $slots = array_keys($charge);
        sort($slots);
        $prices = array_values(array_filter(array_map(static fn (array $q): ?float => isset($charge[$q[0]]) ? $q[3] : null, $quarters), static fn (?float $v): bool => $v !== null));
        return [
            'status' => $result['e'] >= $target - 0.05 ? 'ok' : 'tight',
            'slots' => $slots,
            'grid_kwh' => round(count($slots) * $add / max(0.5, min(1.0, (float) $in['efficiency'])), 1),
            'sun_kwh' => round($result['sun'], 1),
            'drain_kwh' => round($result['drain'], 1),
            'avg_ct' => $prices ? round(array_sum($prices) / count($prices), 2) : null,
            'end_kwh' => round($result['e'], 1),
        ];
    }

    /** @return list<array> gespeicherte Pläne, gültig */
    public static function all(ConfigStore $store): array
    {
        $out = [];
        foreach ((array) $store->get(self::KEY, []) as $raw) {
            if (is_array($raw)) {
                $plan = self::clean($raw, (string) ($raw['id'] ?? ''))['plan'];
                if ($plan !== null) {
                    $out[] = $plan;
                }
            }
        }
        return $out;
    }

    /**
     * Plan aus Formular oder Speicher: id, on, days (1–7), time „HH:MM“, target_soc (%), optional soc_below (Speicher
     * unter %) und pv_below (Sonne bis zur Uhrzeit unter kWh).
     *
     * @return array{plan: ?array, error: ?string}
     */
    public static function clean(array $raw, string $id = ''): array
    {
        $number = static function (mixed $value): ?float {
            $text = str_replace(',', '.', trim((string) $value));
            return $text === '' || !is_numeric($text) ? null : (float) $text;
        };
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($raw['days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        if ($days === []) {
            return ['plan' => null, 'error' => 'Bitte mindestens einen Wochentag wählen.'];
        }
        $time = Tariff::clock(trim((string) ($raw['time'] ?? '')));
        if ($time === null) {
            return ['plan' => null, 'error' => 'Bitte eine Uhrzeit wie 06:00 angeben.'];
        }
        $target = $number($raw['target_soc'] ?? null);
        if ($target === null || $target < 10 || $target > 100) {
            return ['plan' => null, 'error' => 'Bitte einen Ladestand zwischen 10 und 100 % angeben.'];
        }
        $socBelow = $number($raw['soc_below'] ?? null);
        $pvBelow = $number($raw['pv_below'] ?? null);
        if ($socBelow !== null && ($socBelow < 1 || $socBelow > 100)) {
            return ['plan' => null, 'error' => 'Der Ladestand der Bedingung muss zwischen 1 und 100 % liegen.'];
        }
        if ($pvBelow !== null && ($pvBelow < 0 || $pvBelow > 500)) {
            return ['plan' => null, 'error' => 'Die Sonne muss zwischen 0 und 500 kWh liegen.'];
        }
        $id = preg_match('/^b[0-9a-f]{8}$/', $id) ? $id : 'b' . bin2hex(random_bytes(4));
        return ['plan' => [
            'id' => $id,
            'on' => !array_key_exists('on', $raw) || filter_var($raw['on'], FILTER_VALIDATE_BOOL),
            'days' => $days,
            'time' => $time,
            'target_soc' => round($target),
            'soc_below' => $socBelow === null ? null : round($socBelow),
            'pv_below' => $pvBelow === null ? null : round($pvBelow, 1),
        ], 'error' => null];
    }

    /** Ziel mit Uhrzeit als Überschrift: „Bis 80 % um 06:00“. */
    public static function headline(array $plan): string
    {
        return 'Bis ' . pct((float) $plan['target_soc']) . ' um ' . $plan['time'];
    }

    /** Bedingungen kurz: „Speicher < 40 %, Sonne < 10 kWh“, leer ohne. */
    public static function conditionsShort(array $plan): string
    {
        $parts = [];
        if ($plan['soc_below'] !== null) {
            $parts[] = 'Speicher < ' . pct((float) $plan['soc_below']);
        }
        if ($plan['pv_below'] !== null) {
            $parts[] = 'Sonne < ' . kwh((float) $plan['pv_below'], 0);
        }
        return implode(', ', $parts);
    }

    /**
     * Gilt der Plan? Ohne Bedingung immer; mit soc_below nur unter diesem Ladestand, mit pv_below nur, wenn bis zur
     * Uhrzeit weniger Sonne erwartet wird. Fehlt ein Wert, gilt der Plan.
     *
     * @return array{ok: bool, text: string}
     */
    public static function conditions(array $plan, ?float $soc, ?float $sunKwh): array
    {
        $parts = [];
        $ok = true;
        if ($plan['soc_below'] !== null) {
            $hit = $soc === null || $soc < (float) $plan['soc_below'];
            $ok = $ok && $hit;
            $parts[] = 'Speicher ' . ($soc === null ? 'unbekannt' : pct($soc)) . ($hit ? ' unter ' : ' nicht unter ') . pct((float) $plan['soc_below']);
        }
        if ($plan['pv_below'] !== null) {
            $hit = $sunKwh === null || $sunKwh < (float) $plan['pv_below'];
            $ok = $ok && $hit;
            $parts[] = 'Sonne bis dahin ' . ($sunKwh === null ? 'unbekannt' : '≈ ' . kwh($sunKwh, 1)) . ($hit ? ' unter ' : ', nicht unter ') . kwh((float) $plan['pv_below'], 0);
        }
        return ['ok' => $ok, 'text' => implode(', ', $parts)];
    }

    /** Gesamtgröße des Speichers in kWh: gemeldet, sonst aus nutzbarem Inhalt und Ladestand; null ohne. */
    public static function capacity(array $values): ?float
    {
        if (is_numeric($values['battery_total_kwh'] ?? null) && (float) $values['battery_total_kwh'] > 0) {
            return (float) $values['battery_total_kwh'];
        }
        if (is_numeric($values['battery_capacity_kwh'] ?? null) && is_numeric($values['battery_soc'] ?? null) && (float) $values['battery_soc'] > 1) {
            return (float) $values['battery_capacity_kwh'] / ((float) $values['battery_soc'] / 100);
        }
        return null;
    }

    /** Der laufende oder letzte Plan aus kv.battery_plan_state, null ohne. */
    public static function state(ConfigStore $store): ?array
    {
        $state = $store->get(self::STATE, []);
        return is_array($state) && isset($state['plan_id'], $state['deadline']) ? $state : null;
    }

    /**
     * Der Lauf von Viertelstunden, der jetzt geplant ist, als {start, end, target_soc}; null, wenn jetzt keiner ist.
     * BatteryPlan::sync() lädt damit wie mit einem Energie-Boost.
     */
    public static function current(?array $state, int $now): ?array
    {
        if ($state === null || in_array($state['status'] ?? '', ['done', 'unknown'], true)) {
            return null;
        }
        foreach (ChargePlan::windows((array) ($state['slots'] ?? [])) as [$a, $b]) {
            if ($a <= $now && $now < $b) {
                return ['start' => $a, 'end' => $b, 'target_soc' => (float) $state['target_soc'], 'plan_id' => (string) $state['plan_id'], 'deadline' => (int) $state['deadline']];
            }
        }
        return null;
    }

    /**
     * Recorder und Demo: den nächsten fälligen Plan planen. Neu, wenn Plan, Uhrzeit oder Preise sich geändert haben oder
     * 15 min vorbei sind; ohne fälligen Plan verschwindet der Stand.
     */
    public static function refresh(ConfigStore $store, array $snap, int $now, bool $force = false): ?array
    {
        $cfg = $snap['cfg'];
        $values = $snap['values'];
        $soc = is_numeric($values['battery_soc'] ?? null) ? (float) $values['battery_soc'] : null;
        $due = GridPlans::upcoming(self::all($store), $now, (int) $store->get(self::DONE, 0));
        if ($due === null || $due['deadline'] - $now > GridPlans::HORIZON_S) {
            if (self::state($store) !== null) {
                $store->put(self::STATE, []);
            }
            return null;
        }
        $deadline = (int) $due['deadline'];
        $old = self::state($store);
        $tariff = new Tariff($store);
        $horizon = $tariff->horizon();
        if (!$force && $old !== null && $old['plan_id'] === $due['id'] && (int) $old['deadline'] === $deadline && (int) ($old['horizon'] ?? 0) === $horizon
            && ($old['sig'] ?? '') === md5((string) json_encode($due)) && $now - (int) ($old['computed_at'] ?? 0) < self::REPLAN_S) {
            return $old;
        }
        $sunTotal = ChargePlan::sunKwh($store, $cfg, $snap, $now, $deadline);
        $key = $due['id'] . '@' . $deadline;
        $cond = self::conditions($due, $soc, $sunTotal);
        if ((string) $store->get(self::ARMED, '') === $key) {
            $cond['ok'] = true;
        } elseif ($cond['ok'] && ($due['soc_below'] !== null || $due['pv_below'] !== null)) {
            $store->put(self::ARMED, $key);
        }
        $s = BatteryPlan::settings($cfg);
        $capacity = self::capacity($values);
        $base = ['plan_id' => $due['id'], 'deadline' => $deadline, 'target_soc' => (float) $due['target_soc'], 'horizon' => $horizon, 'sig' => md5((string) json_encode($due)),
            'computed_at' => $now, 'conditions' => $cond['text'], 'slots' => [], 'need_kwh' => null, 'grid_kwh' => null, 'sun_kwh' => 0.0, 'avg_ct' => null, 'power_kw' => $s['power_kw']];
        if (!$cond['ok']) {
            $state = $base + ['status' => 'skip'];
        } elseif ($soc === null || $capacity === null) {
            $state = $base + ['status' => 'unknown'];
        } elseif ($soc >= (float) $due['target_soc'] - 0.5) {
            // Schon da: bis zur nächsten Uhrzeit erledigt.
            $store->put(self::DONE, $deadline);
            $state = $base + ['status' => 'done'];
        } else {
            $series = Forecast::fromRadiation((new WeatherFeed($store))->hours(intdiv($now, 3600) * 3600 - 3600, $deadline + 3600), (array) $cfg['plant']);
            $profile = is_array($snap['house_profile'] ?? null) ? $snap['house_profile'] : array_fill(0, 24, (float) ($snap['house_mean_kw'] ?? 0.5));
            $plan = self::schedule([
                'e_kwh' => $capacity * $soc / 100,
                'capacity_kwh' => $capacity,
                'floor_kwh' => $capacity * BatteryPlan::floorSoc($cfg, $values) / 100,
                'target_kwh' => $capacity * (float) $due['target_soc'] / 100,
                'power_kw' => $s['power_kw'],
                'efficiency' => $s['efficiency'],
                'quarters' => BatteryPlan::quarters($series, $profile, $tariff, $now, $deadline),
            ]);
            if ($plan['status'] === 'done') {
                $store->put(self::DONE, $deadline);
            }
            $state = array_merge($base, $plan, ['need_kwh' => round(((float) $due['target_soc'] - $soc) / 100 * $capacity, 1)]);
        }
        $store->put(self::STATE, $state);
        return $state;
    }

    /**
     * Ein Satz zum geplanten Laden für Speicher → Ausblick, leer ohne fälligen Plan.
     */
    public static function text(?array $state, int $now): string
    {
        if ($state === null) {
            return '';
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $time = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('H:i');
        $deadline = (int) $state['deadline'];
        $day = match (date('Y-m-d', $deadline)) {
            date('Y-m-d', $now) => 'heute',
            date('Y-m-d', strtotime('+1 day', $now)) => 'morgen',
            default => day_label(date('Y-m-d', $deadline)),
        };
        $until = $day . ' ' . $time($deadline);
        $runs = ChargePlan::windows((array) $state['slots']);
        $list = implode(', ', array_map(static fn (array $w): string => $time($w[0]) . '–' . $time($w[1]), array_slice($runs, 0, 3))) . (count($runs) > 3 ? ' …' : '');
        $price = $state['avg_ct'] === null ? '' : ' zu Ø ' . ct((float) $state['avg_ct']);
        $sun = ((float) $state['sun_kwh'] >= 0.5 ? ', ' . kwh((float) $state['sun_kwh'], 1) . ' kommen erwartbar aus der Sonne' : '')
            . ((float) ($state['drain_kwh'] ?? 0) >= 0.5 ? ', bis dahin nimmt das Haus etwa ' . kwh((float) $state['drain_kwh'], 1) . ' aus dem Speicher' : '');
        return match ((string) $state['status']) {
            'skip' => 'Ladeplan bis ' . $until . ' lädt diesmal nicht: ' . $state['conditions'] . '.',
            'unknown' => 'Für den Ladeplan bis ' . $until . ' fehlen Ladestand oder Größe des Speichers.',
            'done' => 'Ladeplan bis ' . $until . ': Der Speicher hat ' . pct((float) $state['target_soc']) . ' schon erreicht.',
            'sun' => 'Ladeplan bis ' . $until . ': Die Sonne schafft ' . pct((float) $state['target_soc']) . ' voraussichtlich allein.',
            'tight' => 'Ladeplan bis ' . $until . ': Die Zeit ist knapp, der Speicher lädt ab jetzt durchgehend aus dem Netz' . $price . '.',
            default => 'Ladeplan bis ' . $until . ': lädt ' . $list . ' aus dem Netz, etwa ' . kwh((float) $state['grid_kwh'], 1) . $price . $sun . '.',
        };
    }

    /** Pläne für die Liste auf Speicher: Überschrift, Tage, nächster Termin, Bedingungen, an/aus, plant gerade. */
    public static function rows(array $plans, ?array $state, int $now): array
    {
        $out = [];
        foreach ($plans as $p) {
            $next = GridPlans::next($p, $now);
            $cond = self::conditionsShort($p);
            $out[] = [
                'id' => (string) $p['id'],
                'label' => self::headline($p),
                'detail' => implode(' · ', array_filter([GridPlans::daysText((array) $p['days']), !$p['on'] ? 'aus' : ($next === null ? '' : 'als Nächstes ' . GridPlans::nextText($p, $next, $now)), $cond === '' ? '' : 'nur bei ' . $cond])),
                'on' => (bool) $p['on'],
                'active' => $state !== null && $state['plan_id'] === $p['id'] && !in_array($state['status'] ?? '', ['done', 'skip', 'unknown'], true),
            ];
        }
        return $out;
    }
}
