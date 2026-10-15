<?php
declare(strict_types=1);

/**
 * Ladepläne fürs Netzladen (kv.grid_plans): an gewählten Wochentagen soll das Auto zu einer Uhrzeit einen Ladestand,
 * eine Reichweite oder eine Energiemenge seit dem Anstecken erreicht haben. Steckt das Auto und liegt die nächste
 * Uhrzeit eines eingeschalteten Plans in den kommenden 24 Stunden, plant ChargePlan die Viertelstunden davor: mit
 * dynamischem Tarif die günstigsten, mit statischem die spätesten, damit die Sonne vorher noch hilft. Optional gilt ein
 * Plan nur, wenn der Hausspeicher unter einem Ladestand liegt, bis zur Uhrzeit weniger Sonne erwartet wird und/oder
 * Ladestand oder Reichweite des Autos unter einem Wert liegen. Gelten die Bedingungen einmal, bleibt der Plan für diese
 * Uhrzeit dabei (kv.grid_plan_armed), auch wenn das Auto beim Laden über die Grenze kommt. Erreicht ist ein Plan bis zu
 * seiner nächsten Uhrzeit erledigt (kv.grid_plan_done). Ein Ziel nach Ladestand endet am Ladelimit, das das Auto meldet.
 *
 * Die Ladeleistung des Autos lernt EMS beim Netzladen (kv.charge_rate, gleitender Mittelwert der gemessenen Leistung);
 * ein fester Wert unter Fahrzeug (vehicle.charge_kw) gilt davor.
 */
final class GridPlans
{
    public const KEY = 'grid_plans';
    public const DONE = 'grid_plan_done';
    public const ARMED = 'grid_plan_armed';
    public const RATE = 'charge_rate';
    public const MAX = 10;
    public const HORIZON_S = 86400;
    public const TYPES = ['soc', 'range', 'energy'];
    public const CAR_TYPES = ['soc', 'range'];
    private const DAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
    /** Gelernte Leistung: eine Messung je Minute, neue Messungen zählen mit 5 %. */
    private const RATE_EVERY_S = 60;
    private const RATE_WEIGHT = 0.05;
    private const RATE_MIN_SAMPLES = 5;

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
     * Plan aus Formular oder Speicher: id, on, days (1–7), time „HH:MM“, type soc/range/energy mit value, optional
     * soc_below (Hausspeicher unter %), pv_below (Sonne bis zur Uhrzeit unter kWh) und car_below mit car_below_type
     * (Ladestand des Autos unter % oder Reichweite unter km).
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
        $time = trim((string) ($raw['time'] ?? ''));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return ['plan' => null, 'error' => 'Bitte eine Uhrzeit wie 07:00 angeben.'];
        }
        $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        $type = (string) ($raw['type'] ?? 'soc');
        if (!in_array($type, self::TYPES, true)) {
            return ['plan' => null, 'error' => 'Unbekannte Zielart.'];
        }
        $value = $number($raw['value'] ?? ($raw[$type === 'soc' ? 'soc' : ($type === 'range' ? 'km' : 'kwh')] ?? null));
        [$min, $max, $error] = match ($type) {
            'soc' => [1, 100, 'Bitte einen Ladestand zwischen 1 und 100 % angeben.'],
            'range' => [10, 1500, 'Bitte eine Reichweite zwischen 10 und 1500 km angeben.'],
            default => [1, 150, 'Bitte eine Energiemenge zwischen 1 und 150 kWh angeben.'],
        };
        if ($value === null || $value < $min || $value > $max) {
            return ['plan' => null, 'error' => $error];
        }
        $socBelow = $number($raw['soc_below'] ?? null);
        $pvBelow = $number($raw['pv_below'] ?? null);
        if ($socBelow !== null && ($socBelow < 1 || $socBelow > 100)) {
            return ['plan' => null, 'error' => 'Der Ladestand des Speichers muss zwischen 1 und 100 % liegen.'];
        }
        if ($pvBelow !== null && ($pvBelow < 0 || $pvBelow > 500)) {
            return ['plan' => null, 'error' => 'Die Sonne muss zwischen 0 und 500 kWh liegen.'];
        }
        $carBelow = $number($raw['car_below'] ?? null);
        $carType = in_array($raw['car_below_type'] ?? 'soc', self::CAR_TYPES, true) ? (string) ($raw['car_below_type'] ?? 'soc') : 'soc';
        if ($carBelow !== null && $carType === 'soc' && ($carBelow < 1 || $carBelow > 100)) {
            return ['plan' => null, 'error' => 'Der Ladestand des Autos muss zwischen 1 und 100 % liegen.'];
        }
        if ($carBelow !== null && $carType === 'range' && ($carBelow < 1 || $carBelow > 1500)) {
            return ['plan' => null, 'error' => 'Die Reichweite des Autos muss zwischen 1 und 1500 km liegen.'];
        }
        $id = preg_match('/^p[0-9a-f]{8}$/', $id) ? $id : 'p' . bin2hex(random_bytes(4));
        return ['plan' => [
            'id' => $id,
            'on' => !array_key_exists('on', $raw) || filter_var($raw['on'], FILTER_VALIDATE_BOOL),
            'days' => $days,
            'time' => $time,
            'type' => $type,
            'value' => round($value, $type === 'energy' ? 1 : 0),
            'soc_below' => $socBelow === null ? null : round($socBelow),
            'pv_below' => $pvBelow === null ? null : round($pvBelow, 1),
            'car_below' => $carBelow === null ? null : round($carBelow),
            'car_below_type' => $carType,
        ], 'error' => null];
    }

    /** Die nächste Uhrzeit des Plans nach $now, höchstens eine Woche weit. */
    public static function next(array $plan, int $now): ?int
    {
        [$h, $m] = array_map('intval', explode(':', (string) $plan['time']));
        $tz = new DateTimeZone('Europe/Berlin');
        $day = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime($h, $m);
        for ($i = 0; $i <= 7; $i++) {
            $at = $day->modify('+' . $i . ' day');
            if ($at->getTimestamp() > $now && in_array((int) $at->format('N'), (array) $plan['days'], true)) {
                return $at->getTimestamp();
            }
        }
        return null;
    }

    /**
     * Der eingeschaltete Plan, dessen Uhrzeit als nächste kommt, mit deadline; erledigte Uhrzeiten zählen nicht.
     *
     * @param list<array> $plans
     */
    public static function upcoming(array $plans, int $now, int $done = 0): ?array
    {
        $best = null;
        foreach ($plans as $plan) {
            if (empty($plan['on'])) {
                continue;
            }
            $at = self::next($plan, $now);
            if ($at !== null && $at === $done) {
                $at = self::next($plan, $at);
            }
            if ($at !== null && ($best === null || $at < $best['deadline'])) {
                $best = $plan + ['deadline' => $at];
            }
        }
        return $best;
    }

    /**
     * Gilt der Plan heute? Ohne Bedingung immer; mit soc_below nur, wenn der Hausspeicher darunter liegt, mit pv_below
     * nur, wenn bis zur Uhrzeit weniger Sonne erwartet wird, mit car_below nur, wenn Ladestand oder Reichweite des
     * Autos darunter liegen. Fehlt ein Wert, gilt der Plan.
     *
     * @return array{ok: bool, text: string}
     */
    public static function conditions(array $plan, ?float $batterySoc, ?float $sunKwh, ?float $carSoc = null, ?float $carRange = null): array
    {
        $parts = [];
        $ok = true;
        if ($plan['soc_below'] !== null) {
            $hit = $batterySoc === null || $batterySoc < (float) $plan['soc_below'];
            $ok = $ok && $hit;
            $parts[] = 'Speicher ' . ($batterySoc === null ? 'unbekannt' : pct($batterySoc)) . ($hit ? ' unter ' : ' nicht unter ') . pct((float) $plan['soc_below']);
        }
        if ($plan['pv_below'] !== null) {
            $hit = $sunKwh === null || $sunKwh < (float) $plan['pv_below'];
            $ok = $ok && $hit;
            $parts[] = 'Sonne bis dahin ' . ($sunKwh === null ? 'unbekannt' : '≈ ' . kwh($sunKwh, 1)) . ($hit ? ' unter ' : ', nicht unter ') . kwh((float) $plan['pv_below'], 0);
        }
        if (($plan['car_below'] ?? null) !== null) {
            $range = ($plan['car_below_type'] ?? 'soc') === 'range';
            $car = $range ? $carRange : $carSoc;
            $hit = $car === null || $car < (float) $plan['car_below'];
            $ok = $ok && $hit;
            $fmt = static fn (float $v): string => $range ? with_unit($v, 0, 'km') : pct($v);
            $parts[] = ($range ? 'Reichweite ' : 'Auto ') . ($car === null ? 'unbekannt' : $fmt($car)) . ($hit ? ' unter ' : ' nicht unter ') . $fmt((float) $plan['car_below']);
        }
        return ['ok' => $ok, 'text' => implode(', ', $parts)];
    }

    /**
     * Bedingungen mit Merker: Gelten sie einmal für eine Uhrzeit (bei angestecktem Auto), bleibt der Plan dafür
     * dabei, sonst stoppte „Auto unter 50 %“ das Laden, sobald das Auto 50 % erreicht. Ohne Auto wird nichts gemerkt.
     *
     * @return array{ok: bool, text: string}
     */
    public static function armed(ConfigStore $store, array $plan, int $deadline, array $cond, bool $connected): array
    {
        $key = $plan['id'] . '@' . $deadline;
        if ((string) $store->get(self::ARMED, '') === $key) {
            return ['ok' => true, 'text' => $cond['text']];
        }
        if ($cond['ok'] && $connected && self::hasConditions($plan)) {
            $store->put(self::ARMED, $key);
        }
        return $cond;
    }

    public static function hasConditions(array $plan): bool
    {
        return ($plan['soc_below'] ?? null) !== null || ($plan['pv_below'] ?? null) !== null || ($plan['car_below'] ?? null) !== null;
    }

    /** Bedingungen kurz für die Liste: „Speicher < 50 %, Auto < 40 %“; leer ohne. */
    public static function conditionsShort(array $plan): string
    {
        $parts = [];
        if (($plan['soc_below'] ?? null) !== null) {
            $parts[] = 'Speicher < ' . pct((float) $plan['soc_below']);
        }
        if (($plan['pv_below'] ?? null) !== null) {
            $parts[] = 'Sonne < ' . kwh((float) $plan['pv_below'], 0);
        }
        if (($plan['car_below'] ?? null) !== null) {
            $parts[] = ($plan['car_below_type'] ?? 'soc') === 'range'
                ? 'Reichweite < ' . with_unit((float) $plan['car_below'], 0, 'km')
                : 'Auto < ' . pct((float) $plan['car_below']);
        }
        return implode(', ', $parts);
    }

    /**
     * Das Ziel des Plans in der Form von Target (then grid, fertig bis zur Uhrzeit). Ein Ladestand über dem Ladelimit,
     * das das Auto meldet, endet am Limit: Darüber lädt das Auto nicht, und EMS ändert sein Limit nicht.
     */
    public static function target(array $plan, int $deadline, ?float $carSoc, ?float $carRange, ?float $carLimit = null): array
    {
        $value = (float) $plan['value'];
        if ($plan['type'] === 'soc' && $carLimit !== null && $carLimit > 0) {
            $value = min($value, $carLimit);
        }
        $target = ['type' => (string) $plan['type'], 'value' => $value, 'then' => 'grid', 'set_at' => $deadline, 'ready_by' => $deadline];
        if ($plan['type'] === 'soc' && $carSoc !== null) {
            $target['start_soc'] = $carSoc;
        }
        if ($plan['type'] === 'range' && $carRange !== null) {
            $target['start_range'] = $carRange;
        }
        return $target;
    }

    /** Wochentage kurz: „Mo–Fr“, „Sa, So“, „täglich“. */
    public static function daysText(array $days): string
    {
        $days = array_values(array_map('intval', $days));
        sort($days);
        if (count($days) === 7) {
            return 'täglich';
        }
        $runs = [];
        foreach ($days as $d) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][1] === $d - 1) {
                $runs[$last][1] = $d;
            } else {
                $runs[] = [$d, $d];
            }
        }
        return implode(', ', array_map(static fn (array $r): string => $r[1] - $r[0] >= 2
            ? self::DAYS[$r[0]] . '–' . self::DAYS[$r[1]]
            : implode(', ', array_map(static fn (int $d): string => self::DAYS[$d], range($r[0], $r[1]))), $runs));
    }

    /** Ziel des Plans als Text: „bis 80 %“, „bis 300 km“, „20 kWh laden“. */
    public static function goalText(array $plan): string
    {
        return match ((string) $plan['type']) {
            'soc' => 'bis ' . pct((float) $plan['value']),
            'range' => 'bis ' . with_unit((float) $plan['value'], 0, 'km'),
            default => kwh((float) $plan['value'], 0) . ' laden',
        };
    }

    /** Ziel mit Uhrzeit als Überschrift: „Bis 80 % um 07:00“, „10 kWh bis 12:00“. */
    public static function headline(array $plan): string
    {
        return match ((string) $plan['type']) {
            'soc' => 'Bis ' . pct((float) $plan['value']) . ' um ' . $plan['time'],
            'range' => 'Bis ' . with_unit((float) $plan['value'], 0, 'km') . ' um ' . $plan['time'],
            default => kwh((float) $plan['value'], 0) . ' bis ' . $plan['time'],
        };
    }

    /** Eine Zeile für Meldungen: „Mo–Fr · Bis 80 % um 07:00“. */
    public static function label(array $plan): string
    {
        return self::daysText((array) $plan['days']) . ' · ' . self::headline($plan);
    }

    /**
     * Wann als Nächstes, ohne die Uhrzeit, die schon in der Überschrift steht: „heute“, „morgen“, „Mi 21.10.“; mit nur
     * einem Wochentag ohne ihn noch einmal: „am 21.10.“.
     */
    public static function nextText(array $plan, int $next, int $now): string
    {
        $day = date('Y-m-d', $next);
        return match ($day) {
            date('Y-m-d', $now) => 'heute',
            date('Y-m-d', strtotime('+1 day', $now)) => 'morgen',
            default => count((array) $plan['days']) === 1 ? 'am ' . date('d.m.', $next) : day_label($day),
        };
    }

    /** Wochentage zur Auswahl. */
    public static function dayNames(): array
    {
        return self::DAYS;
    }

    /** Recorder: beim Netzladen einmal je Minute die Ladeleistung in den gleitenden Mittelwert nehmen. */
    public static function learn(ConfigStore $store, array $snap, int $now): void
    {
        $kw = (float) ($snap['values']['wallbox_kw'] ?? 0);
        if (($snap['cfg']['charge']['effective_mode'] ?? '') !== 'netz' || $kw < 1.0) {
            return;
        }
        $rate = $store->get(self::RATE, []);
        $rate = is_array($rate) ? $rate : [];
        if ($now - (int) ($rate['at'] ?? 0) < self::RATE_EVERY_S) {
            return;
        }
        $n = (int) ($rate['n'] ?? 0);
        $old = (float) ($rate['kw'] ?? 0);
        // Anfangs der Mittelwert aller Messungen, ab der zwanzigsten zählt jede neue mit 5 %.
        $weight = max(self::RATE_WEIGHT, 1 / ($n + 1));
        $store->put(self::RATE, ['kw' => round($old + ($kw - $old) * $weight, 3), 'n' => $n + 1, 'at' => $now]);
    }

    /** Gelernte Ladeleistung, null bis genug Messungen da sind. */
    public static function learned(ConfigStore $store): ?array
    {
        $rate = $store->get(self::RATE, []);
        return is_array($rate) && (int) ($rate['n'] ?? 0) >= self::RATE_MIN_SAMPLES && (float) ($rate['kw'] ?? 0) > 0.5
            ? ['kw' => (float) $rate['kw'], 'n' => (int) $rate['n']] : null;
    }

    /** Ladeleistung des Autos: fester Wert unter Fahrzeug, sonst die gelernte, sonst null. */
    public static function carKw(array $cfg, ConfigStore $store): ?float
    {
        $fixed = $cfg['vehicle']['charge_kw'] ?? null;
        if (is_numeric($fixed) && (float) $fixed > 0) {
            return (float) $fixed;
        }
        return self::learned($store)['kw'] ?? null;
    }
}
