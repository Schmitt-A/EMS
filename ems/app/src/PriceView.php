<?php
declare(strict_types=1);

/**
 * Daten für Prognose → Strompreis: Preis jetzt, heute und morgen, die günstigsten Zeiten und der Vergleich statisch ↔
 * dynamisch über einen Monat oder zwölf Monate (Tariff::compare). Der Netzbezug kommt aus den Viertelstunden von EMS
 * (GridMeter), sonst aus den Stunden von Home Assistant; das Auto aus den Ladevorgängen.
 */
final class PriceView
{
    public const SPANS = ['monat' => 'Dieser Monat', 'vormonat' => 'Letzter Monat', 'jahr' => '12 Monate'];

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    public function build(string $span, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $tariff = new Tariff($this->store);
        $market = $tariff->market();
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $last = $tariff->horizon();
        $ahead = [];
        for ($q = intdiv($now, 900) * 900; $q < $last; $q += 900) {
            $ct = $tariff->dynamicAt($q);
            if ($ct !== null) {
                $ahead[$q] = $ct;
            }
        }
        $span = isset(self::SPANS[$span]) ? $span : 'jahr';
        [$from, $to] = self::spanRange($span, $now);
        $windows = new PriceWindows($this->store, $tariff);
        $enabled = $windows->enabled($now);
        $plan = ChargePlan::get($this->store);
        $target = Target::get($this->store, 'grid');
        if ($plan !== null && (!$enabled || $target === null || (int) ($plan['target_set_at'] ?? 0) !== (int) ($target['set_at'] ?? -1))) {
            $plan = null;
        }
        $config = $windows->config();
        $cfg = $this->store->all();
        return [
            'plan' => $plan,
            'windows' => $windows->list($now),
            'windows_enabled' => $enabled,
            'windows_config' => $config,
            'users' => [
                'car' => Energy::grid($cfg['charge']['grid'] ?? 'off'),
                'car_chosen' => ChargePlan::chosen($this->store, $now),
                'battery' => BatteryPlan::boost($this->store, $now),
            ],
            'months' => $this->months($tariff, $config['length_h'], $now),
            'now' => $tariff->now($now),
            'today' => self::stats($tariff, $today->getTimestamp(), $tomorrow->getTimestamp()),
            'tomorrow' => self::stats($tariff, $tomorrow->getTimestamp(), $tomorrow->modify('+1 day')->getTimestamp()),
            'cheap' => [
                'hour' => self::cheapest($ahead, 4),
                'dear' => self::cheapest($ahead, 4, true),
            ],
            'horizon' => $last,
            'span' => $span,
            'compare' => $this->compare($tariff, $from, $to),
            'meta' => $market->meta(),
            'since' => (new GridMeter($this->store))->since(),
        ];
    }

    /** Zeitraum des Vergleichs: dieser Monat bis jetzt, der letzte ganze Monat oder die letzten zwölf Monate. */
    public static function spanRange(string $span, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $month = $local->modify('first day of this month')->setTime(0, 0);
        return match ($span) {
            'monat' => [$month->getTimestamp(), $now],
            'vormonat' => [$month->modify('-1 month')->getTimestamp(), $month->getTimestamp()],
            default => [$local->setTime(0, 0)->modify('-1 year')->modify('+1 day')->getTimestamp(), $now],
        };
    }

    /** Mittel, günstigster und teuerster dynamischer Preis eines Tages; null, solange die Preise fehlen. */
    public static function stats(Tariff $tariff, int $from, int $to): ?array
    {
        $prices = [];
        for ($q = $from; $q < $to; $q += 900) {
            $ct = $tariff->dynamicAt($q);
            if ($ct !== null) {
                $prices[$q] = $ct;
            }
        }
        if (count($prices) < 4) {
            return null;
        }
        return ['avg' => array_sum($prices) / count($prices), 'min' => min($prices), 'max' => max($prices), 'min_at' => array_search(min($prices), $prices, true), 'max_at' => array_search(max($prices), $prices, true)];
    }

    /**
     * Günstigste (oder teuerste) zusammenhängende Zeit aus $length Viertelstunden, siehe PriceWindows::cheapest().
     *
     * @param array<int, float> $prices Viertelstunde => ct
     * @return ?array{start: int, end: int, ct: float}
     */
    public static function cheapest(array $prices, int $length, bool $dearest = false): ?array
    {
        return PriceWindows::cheapest($prices, $length, $dearest);
    }

    /**
     * Entscheidungshilfe: je Monat mit Börsenpreisen, bis 24 Monate zurück, der dynamische Preis im Mittel über den
     * Tag und im täglichen Ladefenster (je Tag das günstigste Fenster der eingestellten Länge), beides mit deinen
     * Aufschlägen von heute, dazu der statische Preis von heute. Abgeschlossene Monate liegen in kv.price_months,
     * solange Tarif und Fensterlänge gleich bleiben; der laufende Monat wird jedes Mal gerechnet.
     *
     * @return array{rows: list<array{month: string, label: string, days: int, flat_ct: float, window_ct: float, spot_ct: float, negative: int}>, static_ct: float, base_eur: ?float, dyn_base_eur: ?float, length_h: int}
     */
    public function months(Tariff $tariff, int $lengthH, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $t = $tariff->tariffAt($now);
        $key = md5(json_encode($t) . '|' . $lengthH . '|2');
        $cache = $this->store->get('price_months', []);
        $cached = is_array($cache) && ($cache['key'] ?? '') === $key ? (array) ($cache['months'] ?? []) : [];
        $market = $tariff->market();
        $first = $market->firstStart();
        $current = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->modify('first day of this month')->setTime(0, 0);
        $rows = [];
        $store = [];
        if ($first !== null) {
            $month = max($current->modify('-24 months'), (new DateTimeImmutable('@' . $first))->setTimezone($tz)->modify('first day of this month')->setTime(0, 0));
            for (; $month <= $current; $month = $month->modify('+1 month')) {
                $id = $month->format('Y-m');
                $closed = $month < $current;
                $row = $closed && isset($cached[$id]) ? $cached[$id] : self::month($market, $t, $month, min($now, $month->modify('+1 month')->getTimestamp()), $lengthH * 4);
                if ($row === null) {
                    continue;
                }
                if ($closed) {
                    $store[$id] = $row;
                }
                $rows[] = $row + ['month' => $id, 'closed' => $closed, 'label' => month_label($id) . ($closed ? '' : ' bis heute')];
            }
        }
        if ($store !== $cached) {
            $this->store->put('price_months', ['key' => $key, 'months' => $store]);
        }
        return [
            'rows' => $rows,
            'static_ct' => (float) $t['import_ct'],
            'base_eur' => $t['base_eur'] === null ? null : (float) $t['base_eur'],
            'dyn_base_eur' => $t['dyn_base_eur'] === null ? null : (float) $t['dyn_base_eur'],
            'length_h' => $lengthH,
        ];
    }

    /**
     * Ein Jahr statisch gegen dynamisch aus den letzten bis zu zwölf ganzen Monaten: $windowKwh im Ladefenster (Auto und
     * Speicher), $restKwh übriger Netzbezug, über den Tag gleich verteilt. Dazu, ab wie vielen kWh im Ladefenster
     * dynamisch günstiger ist (null, wenn das Fenster nicht unter dem statischen Preis liegt). components/decide.js
     * rechnet beim Tippen genauso.
     */
    public static function decision(array $months, float $windowKwh, float $restKwh): ?array
    {
        $rows = array_slice(array_values(array_filter($months['rows'], static fn (array $r): bool => !empty($r['closed']))), -12);
        if ($rows === []) {
            return null;
        }
        $flat = array_sum(array_column($rows, 'flat_ct')) / count($rows);
        $window = array_sum(array_column($rows, 'window_ct')) / count($rows);
        $static = (float) $months['static_ct'];
        $base = (float) ($months['base_eur'] ?? 0) * 12;
        $dynBase = (float) ($months['dyn_base_eur'] ?? 0) * 12;
        $dynamic = ($windowKwh * $window + $restKwh * $flat) / 100 + $dynBase;
        $fixed = ($windowKwh + $restKwh) * $static / 100 + $base;
        $saved = $static - $window;
        return [
            'months' => count($rows),
            'flat_ct' => round($flat, 2),
            'window_ct' => round($window, 2),
            'static_ct' => $static,
            'base_eur' => $base,
            'dyn_base_eur' => $dynBase,
            'dynamic_eur' => round($dynamic, 2),
            'static_eur' => round($fixed, 2),
            'difference' => round($fixed - $dynamic, 2),
            'break_even_kwh' => $saved > 0.01 ? max(0.0, round(($restKwh * ($flat - $static) / 100 + $dynBase - $base) / ($saved / 100))) : null,
            'base_missing' => $months['base_eur'] === null || $months['dyn_base_eur'] === null,
        ];
    }

    /** Ein Monat bis $to: Mittel über alle Viertelstunden, Mittel der täglichen Ladefenster, Börse, Viertelstunden unter 0. */
    private static function month(MarketPrices $market, array $t, DateTimeImmutable $month, int $to, int $length): ?array
    {
        $spots = $market->quarters($month->getTimestamp(), $to);
        $byDay = [];
        foreach ($spots as $q => $spot) {
            $byDay[(new DateTimeImmutable('@' . $q))->setTimezone($month->getTimezone())->format('Y-m-d')][$q] = $spot;
        }
        $flat = $window = $spotSum = 0.0;
        $quarters = $days = $negative = 0;
        foreach ($byDay as $prices) {
            $dynamic = [];
            foreach ($prices as $q => $spot) {
                $dynamic[$q] = Tariff::dynamicCt($spot, $t, $q);
                $flat += $dynamic[$q];
                $spotSum += $spot;
                $quarters++;
                $negative += $spot < 0 ? 1 : 0;
            }
            $best = PriceWindows::cheapest($dynamic, $length);
            if ($best !== null && count($prices) >= 80) {
                $window += $best['ct'];
                $days++;
            }
        }
        if ($days === 0 || $quarters === 0) {
            return null;
        }
        return ['days' => $days, 'flat_ct' => round($flat / $quarters, 2), 'window_ct' => round($window / $days, 2), 'spot_ct' => round($spotSum / $quarters, 2), 'negative' => $negative];
    }

    /** Vergleich mit dem Netzbezug des Zeitraums und dem Netzstrom des Autos je Viertelstunde. */
    private function compare(Tariff $tariff, int $from, int $to): array
    {
        $energy = [];
        $note = null;
        try {
            $hours = (new Heatmap($this->store, $this->ha))->importHours($from, $to);
            $energy = GridMeter::merge($hours, (new GridMeter($this->store))->quarters($from, $to));
        } catch (Throwable $e) {
            $note = 'Home Assistant liefert keinen Netzbezug: ' . $e->getMessage();
        }
        $cfg = $this->store->all();
        $charge = $cfg['charge'];
        $carKw = Energy::KW_PER_AMP * min(16, max(6, (int) ($charge['max_a'] ?? 16))) * (($charge['phase_mode'] ?? 'auto') === '1p' ? 1 : 3);
        $car = [];
        foreach ((new Sessions($this->store))->overlapping($from, $to) as $row) {
            $grid = (float) ($row['grid_kwh'] ?? 0);
            $start = (int) strtotime((string) $row['started_at']);
            $end = $start + max(60, (int) $row['duration_s']);
            if ($grid <= 0 || $end <= $from || $start >= $to) {
                continue;
            }
            foreach (GridMeter::split($start, $end) as [$q, $seconds]) {
                $car[$q] = ($car[$q] ?? 0.0) + $grid * $seconds / ($end - $start);
            }
        }
        return $tariff->compare($from, $to, $energy, $car, $carKw) + ['note' => $note, 'car_kw' => $carKw];
    }
}
