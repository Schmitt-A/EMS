<?php
declare(strict_types=1);

/**
 * Daten für Prognose → Strompreis: Preis jetzt, heute und morgen, die Ladefenster mit ihren Nutzern und die günstigste
 * und teuerste Stunde ab jetzt. „Lohnt sich dynamisch?“ rechnet YearCheck.
 */
final class PriceView
{
    public function __construct(private ConfigStore $store) {}

    public function build(int $now): array
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
            'now' => $tariff->now($now),
            'today' => self::stats($tariff, $today->getTimestamp(), $tomorrow->getTimestamp()),
            'tomorrow' => self::stats($tariff, $tomorrow->getTimestamp(), $tomorrow->modify('+1 day')->getTimestamp()),
            'cheap' => [
                'hour' => self::cheapest($ahead, 4),
                'dear' => self::cheapest($ahead, 4, true),
            ],
            'horizon' => $last,
            'meta' => $market->meta(),
        ];
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
}
