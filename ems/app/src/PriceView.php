<?php
declare(strict_types=1);

/**
 * Daten für Prognose → Strompreis: Preis jetzt, heute und morgen, die Ladefenster mit ihren Nutzern und die günstigste
 * und teuerste Stunde ab jetzt; im statischen Tarif die Zeitfenster für Auto und Speicher mit Preis jetzt und dem
 * nächsten Wechsel. „Welcher Tarif lohnt sich?“ rechnet YearCheck.
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
        $t = $tariff->tariffAt($now);
        return [
            'static_uses' => $t['mode'] === 'static' ? self::staticUses($t, $now) : [],
            'static_windows' => $t['mode'] === 'static' ? self::staticWindows($t, $now) : [],
            'plan' => $plan,
            'windows' => $windows->list($now),
            'windows_enabled' => $enabled,
            'windows_config' => $config,
            'users' => [
                'car' => Energy::grid($cfg['charge']['grid'] ?? 'off'),
                'car_chosen' => ChargePlan::chosen($this->store, $now),
                'battery' => BatteryPlans::text(BatteryPlans::state($this->store), $now),
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

    /**
     * Je Verbraucher mit Zeitfenster der Preis jetzt und ein Satz zum nächsten Wechsel: „im Zeitfenster bis 06:00“ oder
     * „ab heute 22:00 22,7 ct/kWh“.
     *
     * @return array<string, array{ct: float, text: string}>
     */
    public static function staticUses(array $t, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $when = static function (int $ts) use ($tz, $now): string {
            $local = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
            $day = match ($local->format('Y-m-d')) {
                (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d') => 'heute',
                (new DateTimeImmutable('@' . ($now + 86400)))->setTimezone($tz)->format('Y-m-d') => 'morgen',
                default => short_day_label($local),
            };
            return $day . ' ' . $local->format('H:i');
        };
        $out = [];
        foreach (array_keys(Tariff::USES) as $use) {
            if (!array_filter((array) $t['windows'], static fn (array $w): bool => in_array($use, (array) $w['for'], true))) {
                continue;
            }
            $ct = Tariff::staticCt($t, $now, $use);
            $text = 'kein Zeitfenster in den nächsten Tagen';
            $start = intdiv($now, 900) * 900;
            $inside = Tariff::windowAt($t, $now, $use) !== null;
            for ($q = $start + 900; $q < $now + 8 * 86400; $q += 900) {
                $in = Tariff::windowAt($t, $q, $use) !== null;
                if ($in !== $inside) {
                    $text = $inside ? 'im Zeitfenster bis ' . (new DateTimeImmutable('@' . $q))->setTimezone($tz)->format('H:i') : 'ab ' . $when($q) . ' ' . ct(Tariff::staticCt($t, $q, $use));
                    break;
                }
            }
            $out[$use] = ['ct' => $ct, 'text' => $text];
        }
        return $out;
    }

    /**
     * Die Zeitfenster als Liste, gleiche Zeiten zusammen: „22:00–06:00, täglich“ mit „Auto 22,7 ct/kWh, 12 ct weniger ·
     * Speicher 19,7 ct/kWh, 15 ct weniger“ und ob es gerade läuft.
     *
     * @return list<array{label: string, text: string, running: bool}>
     */
    public static function staticWindows(array $t, int $now): array
    {
        $groups = [];
        foreach ((array) $t['windows'] as $w) {
            $key = $w['from'] . $w['to'] . implode(',', (array) $w['days']);
            $groups[$key] ??= ['label' => Tariff::windowLabel($w), 'parts' => [], 'running' => false];
            foreach ((array) $w['for'] as $use) {
                $groups[$key]['parts'][] = Tariff::USES[$use] . ' ' . ct(Tariff::windowCt($t, $w)) . ($w['kind'] === 'minus' ? ' (−' . with_unit((float) $w['ct'], 1, 'ct') . ')' : ' (fest)');
                $groups[$key]['running'] = $groups[$key]['running'] || Tariff::windowAt($t, $now, $use) === $w;
            }
        }
        return array_values(array_map(static fn (array $g): array => [
            'label' => str_contains($g['label'], ',') ? $g['label'] : $g['label'] . ', täglich',
            'text' => implode(' · ', $g['parts']),
            'running' => $g['running'],
        ], $groups));
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
