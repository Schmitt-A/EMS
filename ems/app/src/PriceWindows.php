<?php
declare(strict_types=1);

/**
 * Ladefenster, eingestellt unter Prognose → Strompreis: je Tag das günstigste zusammenhängende Fenster fester Länge
 * nach dem dynamischen Preis (Börse plus Aufschläge oder die Preis-Entität des Anbieters), optional jede Zeit in einem
 * Preisbereich, dazu eigene Fenster; jedes einzeln abschaltbar. Es gibt sie nur mit dynamischem Tarif (enabled()), mit
 * statischem fehlen sie überall. Aus den aktiven Fenstern (offered()) wählt man in der Ladepunkt-Karte
 * die fürs Auto und unter Speicher → Ausblick das für den Energie-Boost.
 * kv.price_windows: length_h (Länge des Vorschlags), off (Tage Y-m-d, deren Vorschlag aus ist), range_from und
 * range_to (Preisbereich in ct/kWh brutto, ohne range_to aus), range_off (Beginn abgeschalteter Fenster im
 * Preisbereich), custom (eigene Fenster als start/end in Sekunden, auf die Viertelstunde).
 */
final class PriceWindows
{
    public const KEY = 'price_windows';
    /** Wählbare Längen des Vorschlags in Stunden. */
    public const LENGTHS = [1, 2, 3, 4, 5, 6, 8];
    /** Eigene Fenster höchstens so lang und so viele. */
    private const CUSTOM_MAX_S = 86400;
    private const CUSTOM_COUNT = 12;

    private ?array $list = null;
    private ?int $listAt = null;

    public function __construct(private ConfigStore $store, private ?Tariff $tariff = null)
    {
        $this->tariff ??= new Tariff($store);
    }

    /** Gespeicherte Einstellungen, geprüft. */
    public static function settings(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $length = (int) round((float) ($raw['length_h'] ?? 3));
        $off = [];
        foreach ((array) ($raw['off'] ?? []) as $day) {
            if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $off[] = $day;
            }
        }
        $custom = [];
        foreach ((array) ($raw['custom'] ?? []) as $w) {
            if (!is_array($w) || !is_numeric($w['start'] ?? null) || !is_numeric($w['end'] ?? null)) {
                continue;
            }
            $start = intdiv((int) $w['start'], 900) * 900;
            $end = intdiv((int) $w['end'], 900) * 900;
            if ($end > $start && $end - $start <= self::CUSTOM_MAX_S) {
                $custom[] = ['start' => $start, 'end' => $end];
            }
        }
        usort($custom, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $price = static fn (mixed $v): ?float => is_numeric($v) ? round(clamp_float((float) $v, -100, 200), 2) : null;
        $from = $price($raw['range_from'] ?? null);
        $to = $price($raw['range_to'] ?? null);
        if ($to !== null && $from !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $rangeOff = array_values(array_unique(array_map('intval', array_filter((array) ($raw['range_off'] ?? []), 'is_numeric'))));
        return [
            'length_h' => in_array($length, self::LENGTHS, true) ? $length : 3,
            'off' => array_values(array_unique($off)),
            'range_from' => $to === null ? null : $from,
            'range_to' => $to,
            'range_off' => $rangeOff,
            'custom' => array_slice($custom, 0, self::CUSTOM_COUNT),
        ];
    }

    public function config(): array
    {
        return self::settings($this->store->get(self::KEY, []));
    }

    /** Speichert und räumt auf: abgeschaltete Tage und eigene Fenster, die vor gestern endeten, fallen weg. */
    public function save(array $settings, int $now): void
    {
        $settings = self::settings($settings);
        $yesterday = self::dayKey($now - 86400);
        $settings['off'] = array_values(array_filter($settings['off'], static fn (string $day): bool => $day >= $yesterday));
        $settings['custom'] = array_values(array_filter($settings['custom'], static fn (array $w): bool => $w['end'] > $now - 86400));
        $settings['range_off'] = array_values(array_filter($settings['range_off'], static fn (int $start): bool => $start > $now - 2 * 86400));
        $this->store->put(self::KEY, $settings);
        $this->list = null;
    }

    /**
     * Günstigste (oder teuerste) zusammenhängende Zeit aus $length Viertelstunden, nur ohne Lücke; bei gleichem Mittel
     * die frühere.
     *
     * @param array<int, float> $prices Viertelstunde => ct
     * @return ?array{start: int, end: int, ct: float}
     */
    public static function cheapest(array $prices, int $length, bool $dearest = false): ?array
    {
        ksort($prices);
        $keys = array_keys($prices);
        $values = array_values($prices);
        $best = null;
        $sum = 0.0;
        for ($i = 0; $i < count($keys); $i++) {
            $sum += $values[$i];
            if ($i >= $length) {
                $sum -= $values[$i - $length];
            }
            if ($i < $length - 1) {
                continue;
            }
            $first = $i - $length + 1;
            if ($keys[$i] - $keys[$first] !== ($length - 1) * 900) {
                continue;
            }
            $avg = $sum / $length;
            if ($best === null || ($dearest ? $avg > $best['ct'] + 1e-9 : $avg < $best['ct'] - 1e-9)) {
                $best = ['start' => $keys[$first], 'end' => $keys[$i] + 900, 'ct' => $avg];
            }
        }
        return $best;
    }

    /**
     * Die Fenster von heute bis zum Ende der bekannten Preise: je Tag der Vorschlag (kind auto), die Zeiten im
     * Preisbereich (kind range) und die eigenen Fenster (kind custom), nach Beginn sortiert. Neue Preise (gegen 13 Uhr
     * die für morgen) bringen ihre Fenster von selbst mit. Je Fenster id, kind, day (Y-m-d des Beginns), start, end, ct
     * (Ø dynamisch brutto, null ohne Preise), on.
     *
     * @return list<array{id: string, kind: string, day: string, start: int, end: int, ct: ?float, on: bool}>
     */
    public function list(int $now): array
    {
        if ($this->list !== null && $this->listAt === $now) {
            return $this->list;
        }
        $s = $this->config();
        $tz = new DateTimeZone('Europe/Berlin');
        $day = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $last = $this->tariff->horizon();
        $length = $s['length_h'] * 4;
        $out = [];
        $all = [];
        for (; $day->getTimestamp() < $last; $day = $day->modify('+1 day')) {
            $next = $day->modify('+1 day')->getTimestamp();
            $prices = $this->prices($day->getTimestamp(), $next);
            $all += $prices;
            $best = self::cheapest($prices, $length);
            if ($best === null) {
                continue;
            }
            $key = $day->format('Y-m-d');
            $out[] = ['id' => 'auto-' . $key, 'kind' => 'auto', 'day' => $key, 'start' => $best['start'], 'end' => $best['end'], 'ct' => round($best['ct'], 2), 'on' => !in_array($key, $s['off'], true)];
        }
        if ($s['range_to'] !== null) {
            foreach (self::runs($all, $s['range_from'], (float) $s['range_to']) as $run) {
                $out[] = ['id' => 'range-' . $run['start'], 'kind' => 'range', 'day' => self::dayKey($run['start'])] + $run + ['on' => !in_array($run['start'], $s['range_off'], true)];
            }
        }
        $todayStart = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
        foreach ($s['custom'] as $w) {
            if ($w['end'] <= $todayStart) {
                continue;
            }
            $prices = $this->prices($w['start'], $w['end']);
            $out[] = [
                'id' => 'custom-' . $w['start'] . '-' . $w['end'],
                'kind' => 'custom',
                'day' => self::dayKey($w['start']),
                'start' => $w['start'],
                'end' => $w['end'],
                'ct' => $prices ? round(array_sum($prices) / count($prices), 2) : null,
                'on' => true,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['start'], $a['kind']] <=> [$b['start'], $b['kind']]);
        $this->list = $out;
        $this->listAt = $now;
        return $out;
    }

    /**
     * Zusammenhängende Zeiten, in denen der Preis im Bereich liegt (ohne Untergrenze bis $to), je mit Ø-Preis.
     *
     * @param array<int, float> $prices Viertelstunde => ct
     * @return list<array{start: int, end: int, ct: float}>
     */
    public static function runs(array $prices, ?float $from, float $to): array
    {
        ksort($prices);
        $out = [];
        $run = null;
        foreach ($prices as $q => $ct) {
            $in = $ct <= $to + 1e-9 && ($from === null || $ct >= $from - 1e-9);
            if ($in && $run !== null && $run['end'] === $q) {
                $run['end'] = $q + 900;
                $run['sum'] += $ct;
                $run['n']++;
                continue;
            }
            if ($run !== null) {
                $out[] = ['start' => $run['start'], 'end' => $run['end'], 'ct' => round($run['sum'] / $run['n'], 2)];
                $run = null;
            }
            if ($in) {
                $run = ['start' => $q, 'end' => $q + 900, 'sum' => $ct, 'n' => 1];
            }
        }
        if ($run !== null) {
            $out[] = ['start' => $run['start'], 'end' => $run['end'], 'ct' => round($run['sum'] / $run['n'], 2)];
        }
        return $out;
    }

    /** Ladefenster gibt es nur mit dynamischem Tarif; mit statischem kostet jede Viertelstunde gleich viel. */
    public function enabled(int $now): bool
    {
        return ($this->tariff->tariffAt($now)['mode'] ?? 'static') === 'dynamic';
    }

    /**
     * Die aktiven Fenster, die noch laufen oder kommen, zum Auswählen fürs Auto und den Speicher; leer mit
     * statischem Tarif. Überlappende Fenster bleiben getrennt, jedes lässt sich einzeln wählen.
     *
     * @return list<array{id: string, kind: string, day: string, start: int, end: int, ct: ?float, on: bool}>
     */
    public function offered(int $now, int $limit = 8): array
    {
        if (!$this->enabled($now)) {
            return [];
        }
        $out = [];
        foreach ($this->list($now) as $w) {
            if ($w['on'] && $w['end'] > $now) {
                $out[] = $w;
            }
        }
        return array_slice($out, 0, $limit);
    }

    /** Das Fenster aus $windows, in dem $now liegt, sonst null. */
    public static function at(array $windows, int $now): ?array
    {
        foreach ($windows as $w) {
            if ((int) $w['start'] <= $now && $now < (int) $w['end']) {
                return $w;
            }
        }
        return null;
    }

    /**
     * Viertelstunden der Fenster $windows in [$from, $to).
     *
     * @return list<int>
     */
    public static function slotsOf(array $windows, int $from, int $to): array
    {
        $slots = [];
        foreach ($windows as $w) {
            for ($q = max((int) $w['start'], intdiv($from, 900) * 900); $q < min((int) $w['end'], $to); $q += 900) {
                $slots[$q] = true;
            }
        }
        $keys = array_keys($slots);
        sort($keys);
        return $keys;
    }

    /** Dynamischer Preis je Viertelstunde in [$from, $to), nur bekannte. */
    private function prices(int $from, int $to): array
    {
        $prices = [];
        for ($q = intdiv($from, 900) * 900; $q < $to; $q += 900) {
            $ct = $this->tariff->dynamicAt($q);
            if ($ct !== null) {
                $prices[$q] = $ct;
            }
        }
        return $prices;
    }

    /** „heute 02:00–05:00“, „morgen …“ oder „Mi 02:00–05:00“; ein Ende um Mitternacht als 24:00. */
    public static function label(array $w, int $now): string
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $start = (new DateTimeImmutable('@' . (int) $w['start']))->setTimezone($tz);
        $end = (new DateTimeImmutable('@' . (int) $w['end']))->setTimezone($tz);
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $word = static fn (DateTimeImmutable $d): string => match ($d->format('Y-m-d')) {
            $today->format('Y-m-d') => 'heute',
            $today->modify('+1 day')->format('Y-m-d') => 'morgen',
            $today->modify('-1 day')->format('Y-m-d') => 'gestern',
            default => short_day_label($d),
        };
        $day = $start->format('Y-m-d');
        $endText = $end->format('H:i') === '00:00' && $end->format('Y-m-d') !== $day ? '24:00' : $end->format('H:i');
        if ($end->format('Y-m-d') !== $day && $endText !== '24:00') {
            $endText = $word($end) . ' ' . $endText;
        }
        return $word($start) . ' ' . $start->format('H:i') . '–' . $endText;
    }

    /** Stand eines Fensters: vorbei, läuft (bis …) oder kommt. */
    public static function status(array $w, int $now): string
    {
        return match (true) {
            (int) $w['end'] <= $now => 'past',
            (int) $w['start'] <= $now => 'running',
            default => 'coming',
        };
    }

    public static function dayKey(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }

    /**
     * Ein eigenes Fenster aus Tag (Y-m-d) und Uhrzeiten „HH:MM“; ein Ende vor dem Beginn meint den nächsten Tag.
     *
     * @return array{window: ?array{start: int, end: int}, error: ?string}
     */
    public static function customFrom(string $day, string $from, string $to, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($day), $tz);
        $a = Tariff::clock($from);
        $b = Tariff::clock($to);
        if ($date === false || $a === null || $b === null) {
            return ['window' => null, 'error' => 'Bitte Tag und Uhrzeiten wie 01:00 und 04:00 angeben.'];
        }
        [$ah, $am] = array_map('intval', explode(':', $a));
        [$bh, $bm] = array_map('intval', explode(':', $b));
        $start = $date->setTime($ah, $am);
        $end = $date->setTime($bh, $bm);
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        $s = intdiv($start->getTimestamp(), 900) * 900;
        $e = (int) ceil($end->getTimestamp() / 900) * 900;
        if ($e <= $now) {
            return ['window' => null, 'error' => 'Das Fenster liegt schon in der Vergangenheit.'];
        }
        if ($e - $s > self::CUSTOM_MAX_S) {
            return ['window' => null, 'error' => 'Ein Fenster ist höchstens 24 Stunden lang.'];
        }
        return ['window' => ['start' => $s, 'end' => $e], 'error' => null];
    }
}
