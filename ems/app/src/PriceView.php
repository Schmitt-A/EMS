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
        $last = $market->lastEnd();
        $ahead = [];
        for ($q = intdiv($now, 900) * 900; $q < $last; $q += 900) {
            $ct = $tariff->dynamicAt($q);
            if ($ct !== null) {
                $ahead[$q] = $ct;
            }
        }
        $span = isset(self::SPANS[$span]) ? $span : 'jahr';
        [$from, $to] = self::spanRange($span, $now);
        return [
            'now' => $tariff->now($now),
            'today' => self::stats($tariff, $today->getTimestamp(), $tomorrow->getTimestamp()),
            'tomorrow' => self::stats($tariff, $tomorrow->getTimestamp(), $tomorrow->modify('+1 day')->getTimestamp()),
            'cheap' => [
                'hour' => self::cheapest($ahead, 4),
                'three' => self::cheapest($ahead, 12),
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
     * Günstigste (oder teuerste) zusammenhängende Zeit aus $length Viertelstunden.
     *
     * @param array<int, float> $prices Viertelstunde => ct, aufsteigend
     * @return ?array{start: int, end: int, ct: float}
     */
    public static function cheapest(array $prices, int $length, bool $dearest = false): ?array
    {
        ksort($prices);
        $keys = array_keys($prices);
        $best = null;
        for ($i = 0; $i + $length <= count($keys); $i++) {
            if ($keys[$i + $length - 1] - $keys[$i] !== ($length - 1) * 900) {
                continue;
            }
            $sum = 0.0;
            for ($j = $i; $j < $i + $length; $j++) {
                $sum += $prices[$keys[$j]];
            }
            $avg = $sum / $length;
            if ($best === null || ($dearest ? $avg > $best['ct'] : $avg < $best['ct'])) {
                $best = ['start' => $keys[$i], 'end' => $keys[$i] + $length * 900, 'ct' => $avg];
            }
        }
        return $best;
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
