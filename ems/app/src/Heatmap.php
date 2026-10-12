<?php
declare(strict_types=1);

/**
 * Statistik als Heatmap: eine Kennzahl je Stunde, Tag oder Monat über einen Zeitraum. params() prüft die Filter
 * (Kennzahl, Tag, Monat, Quartal, Jahr, Gesamt oder eigener Zeitraum, im Monat Raster Tage oder Stunden), range()
 * rechnet daraus Fenster, Raster und Blättern, payload() liefert die Zellen mit Wert, Text und Farbstärke 0…1.
 * Quellen: Leistungen und Ladestand aus der Langzeitstatistik von Home Assistant (vergangene Zeiträume zwischengespeichert
 * in stat_cache), Ladevorgänge aus sessions (über ihre Ladezeit verteilt), Wetter aus weather_hours und forecast_issues
 * (DWD), die Prognose aus forecast_days und dem Strahlungsmodell.
 */
final class Heatmap
{
    public const SPANS = ['day' => 'Tag', 'month' => 'Monat', 'quarter' => 'Quartal', 'year' => 'Jahr', 'all' => 'Gesamt', 'custom' => 'Eigener'];

    public const GROUPS = [
        'sonne' => 'Sonne und Prognose',
        'haus' => 'Haus und Netz',
        'speicher' => 'Speicher',
        'laden' => 'Laden',
        'wetter' => 'Wetter (DWD)',
    ];

    /**
     * Kennzahlen. kind bestimmt Einheit, Summe und Skala: energy (kWh), euro, co2 (kg), radiation (kWh/m²),
     * duration (Sekunden, als min oder h gezeigt), count, ratio (Prozent aus Summen), level (Prozent als Mittel),
     * temp (°C). color: Farbe aus tokens.css (data-… oder success, info).
     */
    public const METRICS = [
        'pv' => ['group' => 'sonne', 'label' => 'PV gemessen', 'kind' => 'energy', 'color' => 'solar'],
        'forecast' => ['group' => 'sonne', 'label' => 'PV-Prognose', 'kind' => 'energy', 'color' => 'solar'],
        'quality' => ['group' => 'sonne', 'label' => 'Güte der Prognose', 'kind' => 'ratio', 'color' => 'success'],
        'house' => ['group' => 'haus', 'label' => 'Hausverbrauch', 'kind' => 'energy', 'color' => 'grid-in'],
        'import' => ['group' => 'haus', 'label' => 'Netzbezug', 'kind' => 'energy', 'color' => 'grid-in'],
        'import_cost' => ['group' => 'haus', 'label' => 'Kosten Netzbezug', 'kind' => 'euro', 'color' => 'price'],
        'export' => ['group' => 'haus', 'label' => 'Einspeisung', 'kind' => 'energy', 'color' => 'grid-out'],
        'export_pay' => ['group' => 'haus', 'label' => 'Vergütung Einspeisung', 'kind' => 'euro', 'color' => 'grid-out'],
        'autarky' => ['group' => 'haus', 'label' => 'Autarkie', 'kind' => 'ratio', 'color' => 'success'],
        'selfuse' => ['group' => 'haus', 'label' => 'Eigenverbrauch der PV', 'kind' => 'ratio', 'color' => 'success'],
        'co2' => ['group' => 'haus', 'label' => 'CO₂ gespart', 'kind' => 'co2', 'color' => 'success'],
        'soc' => ['group' => 'speicher', 'label' => 'Speicherstand', 'kind' => 'level', 'color' => 'battery'],
        'bat_in' => ['group' => 'speicher', 'label' => 'Speicher geladen', 'kind' => 'energy', 'color' => 'battery'],
        'bat_out' => ['group' => 'speicher', 'label' => 'Speicher entladen', 'kind' => 'energy', 'color' => 'battery'],
        'charge' => ['group' => 'laden', 'label' => 'Geladen', 'kind' => 'energy', 'color' => 'vehicle-1'],
        'charge_time' => ['group' => 'laden', 'label' => 'Ladedauer', 'kind' => 'duration', 'color' => 'vehicle-1'],
        'charge_solar' => ['group' => 'laden', 'label' => 'Sonnenanteil beim Laden', 'kind' => 'ratio', 'color' => 'solar'],
        'charge_cost' => ['group' => 'laden', 'label' => 'Ladekosten', 'kind' => 'euro', 'color' => 'price'],
        'charge_count' => ['group' => 'laden', 'label' => 'Ladevorgänge', 'kind' => 'count', 'color' => 'vehicle-2'],
        'radiation' => ['group' => 'wetter', 'label' => 'Strahlung', 'kind' => 'radiation', 'color' => 'solar'],
        'sunshine' => ['group' => 'wetter', 'label' => 'Sonnenschein', 'kind' => 'duration', 'color' => 'solar'],
        'cloud' => ['group' => 'wetter', 'label' => 'Bewölkung', 'kind' => 'level', 'color' => 'info'],
        'temp' => ['group' => 'wetter', 'label' => 'Temperatur', 'kind' => 'temp', 'color' => 'price'],
    ];

    /** Kennzahlen, deren Zellen eine Summe sind; die übrigen sind Anteile oder Mittel. */
    private const SUMS = ['energy', 'euro', 'co2', 'radiation', 'duration', 'count'];

    /** Im Raster Stunden höchstens so viele Tage (eigener Zeitraum). */
    public const HOUR_DAYS = 62;
    /** Eigener Zeitraum höchstens so viele Tage. */
    public const MAX_DAYS = 731;
    private const CACHE_S = 7 * 86400;

    private DateTimeZone $tz;
    private ?array $index = null;
    private array $notes = [];

    public function __construct(private ConfigStore $store, private HaSource $ha)
    {
        $this->tz = new DateTimeZone('Europe/Berlin');
    }

    /** Filter aus der Adresse, geprüft: metric, span, date (Anker je Zeitraum), from, to, raster. */
    public static function params(array $q, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $metric = isset(self::METRICS[$q['metric'] ?? '']) ? (string) $q['metric'] : 'pv';
        $span = isset(self::SPANS[$q['span'] ?? '']) ? (string) $q['span'] : 'month';
        // Der Anker darf aus einem anderen Zeitraum stammen: aus 2025-10 wird im Jahr 2025, im Quartal 2025-4, am Tag
        // der 1. Oktober; liegt heute im Zeitraum des Ankers, gilt heute.
        $period = self::anchor(trim((string) ($q['date'] ?? '')), $tz);
        [$first, $last] = $period ?? [$today, $today];
        $ref = $today >= $first && $today <= $last ? $today : $first;
        $quarter = static fn (DateTimeImmutable $d): string => $d->format('Y') . '-' . (intdiv((int) $d->format('n') - 1, 3) + 1);
        $date = match ($span) {
            'day' => $ref->format('Y-m-d'),
            'month' => $ref->format('Y-m'),
            'quarter' => $quarter($ref),
            'year' => $ref->format('Y'),
            default => '',
        };
        $valid = static fn (string $value): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $from = trim((string) ($q['from'] ?? ''));
        $to = trim((string) ($q['to'] ?? ''));
        // Eigener Zeitraum ohne Daten: der Zeitraum des Ankers, sonst die letzten 30 Tage.
        $from = $valid($from) ? $from : ($period !== null ? $first->format('Y-m-d') : $today->modify('-29 days')->format('Y-m-d'));
        $to = $valid($to) ? $to : ($period !== null ? $last->format('Y-m-d') : $today->format('Y-m-d'));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $days = (int) round((strtotime($to . ' 12:00') - strtotime($from . ' 12:00')) / 86400) + 1;
        if ($days > self::MAX_DAYS) {
            $from = (new DateTimeImmutable($to, $tz))->modify('-' . (self::MAX_DAYS - 1) . ' days')->format('Y-m-d');
            $days = self::MAX_DAYS;
        }
        $raster = (string) ($q['raster'] ?? 'days') === 'hours' && ($span === 'month' || ($span === 'custom' && $days <= self::HOUR_DAYS)) ? 'hours' : 'days';
        return ['metric' => $metric, 'span' => $span, 'date' => $date, 'from' => $from, 'to' => $to, 'raster' => $raster, 'days' => $days];
    }

    /**
     * Erster und letzter Tag eines Ankers: 2026-10-08 (Tag), 2026-10 (Monat), 2026-4 oder 2026-Q4 (Quartal), 2026 (Jahr).
     *
     * @return ?array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function anchor(string $date, DateTimeZone $tz): ?array
    {
        try {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $day = new DateTimeImmutable($date, $tz);
                return self::sane($day) ? [$day, $day] : null;
            }
            if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $date)) {
                $first = new DateTimeImmutable($date . '-01', $tz);
                return self::sane($first) ? [$first, $first->modify('last day of this month')] : null;
            }
            if (preg_match('/^(\d{4})-Q?([1-4])$/i', $date, $m)) {
                $first = new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $m[1], ((int) $m[2] - 1) * 3 + 1), $tz);
                return self::sane($first) ? [$first, $first->modify('+2 months')->modify('last day of this month')] : null;
            }
            if (preg_match('/^\d{4}$/', $date)) {
                $first = new DateTimeImmutable($date . '-01-01', $tz);
                return self::sane($first) ? [$first, $first->modify('last day of december this year')] : null;
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    private static function sane(DateTimeImmutable $day): bool
    {
        return (int) $day->format('Y') >= 2000 && (int) $day->format('Y') <= 2100;
    }

    /** Anker eines Zeitraums für die Adresse, etwa beim Blättern. */
    public static function query(array $p, array $patch = []): string
    {
        $base = ['metric' => $p['metric'], 'span' => $p['span']];
        if ($p['span'] === 'custom') {
            $base += ['from' => $p['from'], 'to' => $p['to']];
        } elseif ($p['span'] !== 'all') {
            $base['date'] = $p['date'];
        }
        if ($p['raster'] === 'hours') {
            $base['raster'] = 'hours';
        }
        return '?' . http_build_query(array_filter(array_merge($base, $patch), static fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    /** Ob im gewählten Zeitraum das Raster Stunden geht. */
    public static function hoursAllowed(array $p): bool
    {
        return $p['span'] === 'month' || ($p['span'] === 'custom' && $p['days'] <= self::HOUR_DAYS);
    }

    /**
     * Fenster [start, end), Abschnitt (hour, day, month), Aufbau (hours, calendar, matrix, years), Titel und die Anker
     * zum Blättern. Gesamt beginnt zehn Jahre zurück; payload() schneidet auf Jahre mit Daten.
     */
    public static function range(array $p, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $months = ['', 'Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'];
        $long = static fn (DateTimeImmutable $d): string => $d->format('j') . '. ' . $months[(int) $d->format('n')] . ' ' . $d->format('Y');
        switch ($p['span']) {
            case 'day':
                $start = new DateTimeImmutable($p['date'] . ' 00:00:00', $tz);
                $end = $start->modify('+1 day');
                return [
                    'layout' => 'hours', 'period' => 'hour', 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                    'title' => short_day_label($start) . ' ' . $start->format('Y'),
                    'prev' => $start->modify('-1 day')->format('Y-m-d'), 'next' => $end->format('Y-m-d'),
                    'current' => $p['date'] === $today->format('Y-m-d'), 'today' => $today->format('Y-m-d'),
                ];
            case 'quarter':
                [$year, $q] = array_map('intval', explode('-', $p['date']));
                $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, ($q - 1) * 3 + 1), $tz);
                $end = $start->modify('+3 months');
                $last = $end->modify('-1 day');
                $prev = $start->modify('-3 months');
                return [
                    'layout' => 'calendar', 'period' => 'day', 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                    'title' => 'Q' . $q . ' ' . $year . ' · ' . $months[(int) $start->format('n')] . ' bis ' . $months[(int) $last->format('n')],
                    'prev' => $prev->format('Y') . '-' . (intdiv((int) $prev->format('n') - 1, 3) + 1), 'next' => $end->format('Y') . '-' . (intdiv((int) $end->format('n') - 1, 3) + 1),
                    'current' => $now >= $start->getTimestamp() && $now < $end->getTimestamp(),
                    'today' => $today->format('Y') . '-' . (intdiv((int) $today->format('n') - 1, 3) + 1),
                ];
            case 'year':
                $start = new DateTimeImmutable($p['date'] . '-01-01 00:00:00', $tz);
                $end = $start->modify('+1 year');
                return [
                    'layout' => 'calendar', 'period' => 'day', 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                    'title' => $p['date'], 'prev' => (string) ((int) $p['date'] - 1), 'next' => (string) ((int) $p['date'] + 1),
                    'current' => $p['date'] === $today->format('Y'), 'today' => $today->format('Y'),
                ];
            case 'all':
                $start = new DateTimeImmutable(((int) $today->format('Y') - 9) . '-01-01 00:00:00', $tz);
                $end = new DateTimeImmutable(((int) $today->format('Y') + 1) . '-01-01 00:00:00', $tz);
                return [
                    'layout' => 'years', 'period' => 'month', 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                    'title' => 'Alle Jahre', 'prev' => null, 'next' => null, 'current' => true, 'today' => '',
                ];
            case 'custom':
                $start = new DateTimeImmutable($p['from'] . ' 00:00:00', $tz);
                $end = (new DateTimeImmutable($p['to'] . ' 00:00:00', $tz))->modify('+1 day');
                $days = (int) $p['days'];
                return [
                    'layout' => $p['raster'] === 'hours' ? 'matrix' : 'calendar', 'period' => $p['raster'] === 'hours' ? 'hour' : 'day',
                    'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                    'title' => $long($start) . ' bis ' . $long($end->modify('-1 day')),
                    'prev' => ['from' => $start->modify('-' . $days . ' days')->format('Y-m-d'), 'to' => $start->modify('-1 day')->format('Y-m-d')],
                    'next' => ['from' => $end->format('Y-m-d'), 'to' => $end->modify('+' . ($days - 1) . ' days')->format('Y-m-d')],
                    'current' => $now >= $start->getTimestamp() && $now < $end->getTimestamp(), 'today' => '',
                ];
            default:
                $start = new DateTimeImmutable($p['date'] . '-01 00:00:00', $tz);
                $end = $start->modify('+1 month');
                return [
                    'layout' => $p['raster'] === 'hours' ? 'matrix' : 'calendar', 'period' => $p['raster'] === 'hours' ? 'hour' : 'day',
                    'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                    'title' => month_label($p['date']), 'prev' => $start->modify('-1 month')->format('Y-m'), 'next' => $end->format('Y-m'),
                    'current' => $p['date'] === $today->format('Y-m'), 'today' => $today->format('Y-m'),
                ];
        }
    }

    /** Zellen, Skala, Zusammenfassung und Hinweise für /api/series?chart=heatmap. */
    public function payload(array $q, int $now): array
    {
        $p = self::params($q, $now);
        $r = self::range($p, $now);
        $def = self::METRICS[$p['metric']];
        $result = $this->series($p['metric'], $r['period'], $r['start'], $r['end'], $now);
        $values = $result['values'];
        $out = [
            'metric' => ['key' => $p['metric'], 'label' => $def['label'], 'color' => $def['color'], 'kind' => $def['kind']],
            'layout' => $r['layout'],
            'title' => $r['title'],
        ];
        $slots = $this->slots($r, $now, $values);
        [$lo, $hi] = self::scale($def['kind'], array_values(array_filter(array_map(static fn (array $s): ?float => $values[$s['k']] ?? null, $slots), static fn (?float $v): bool => $v !== null)));
        $cell = function (array $slot) use ($values, $lo, $hi, $def, $r): array {
            $value = $values[$slot['k']] ?? null;
            return $slot + [
                'v' => $value === null ? null : round(($hi - $lo) > 1e-9 ? max(0.0, min(1.0, ($value - $lo) / ($hi - $lo))) : ($value > 0 ? 1.0 : 0.0), 3),
                't' => $value === null ? null : self::text($def['kind'], $value, $r['period']),
                'n' => $value === null ? null : self::short($def['kind'], $value, $r['period']),
            ];
        };
        $cells = array_map($cell, $slots);
        $out += $this->arrange($r, $cells);
        $out['scale'] = ['lo' => self::text($def['kind'], $lo, $r['period']), 'hi' => self::text($def['kind'], $hi, $r['period'])];
        $out['summary'] = self::summary($def['kind'], $r['period'], $cells, $values, $result['total']);
        $out['empty'] = !array_filter($cells, static fn (array $c): bool => $c['v'] !== null);
        $out['notes'] = array_values(array_unique($this->notes));
        return $out;
    }

    /** Alle Zellen des Fensters mit Schlüssel, Beschriftung, Zukunft (f) und außerhalb (o, Kalender im eigenen Zeitraum). */
    private function slots(array $r, int $now, array $values): array
    {
        $slots = [];
        $start = (new DateTimeImmutable('@' . $r['start']))->setTimezone($this->tz);
        $end = (new DateTimeImmutable('@' . $r['end']))->setTimezone($this->tz);
        if ($r['period'] === 'month') {
            for ($m = $start; $m < $end; $m = $m->modify('+1 month')) {
                $slots[] = ['k' => $m->format('Y-m'), 'l' => month_label($m->format('Y-m')), 'f' => $m->getTimestamp() > $now];
            }
            return $slots;
        }
        if ($r['period'] === 'hour') {
            for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
                for ($h = 0; $h < 24; $h++) {
                    $at = $d->setTime($h, 0);
                    $slots[] = ['k' => $d->format('Y-m-d') . ' ' . sprintf('%02d', $h), 'l' => short_day_label($d) . ', ' . $h . '–' . ($h + 1) . ' Uhr', 'f' => $at->getTimestamp() > $now, 'h' => $h, 'd' => $d->format('Y-m-d')];
                }
            }
            return $slots;
        }
        // Tage; im Kalender ganze Monate, Tage außerhalb des Fensters bleiben leer.
        $from = $r['layout'] === 'calendar' ? $start->modify('first day of this month') : $start;
        $until = $r['layout'] === 'calendar' ? $end->modify('-1 day')->modify('first day of next month') : $end;
        for ($d = $from; $d < $until; $d = $d->modify('+1 day')) {
            $slots[] = ['k' => $d->format('Y-m-d'), 'l' => short_day_label($d) . ' ' . $d->format('Y'), 'f' => $d->getTimestamp() > $now, 'o' => $d < $start || $d >= $end, 'd' => (int) $d->format('j'), 'm' => $d->format('Y-m'), 'w' => (int) $d->format('N') - 1];
        }
        return $slots;
    }

    /** Aufbau je Ansicht: hours (24 Felder), calendar (Monatsblöcke), matrix (Tage × Stunden), years (Jahre × Monate). */
    private function arrange(array $r, array $cells): array
    {
        if ($r['layout'] === 'hours') {
            return ['cells' => array_map(static fn (array $c): array => array_diff_key($c, ['d' => 1]), $cells)];
        }
        if ($r['layout'] === 'matrix') {
            $rows = [];
            foreach ($cells as $c) {
                $day = $c['d'];
                if (!isset($rows[$day])) {
                    $local = new DateTimeImmutable($day . ' 12:00', $this->tz);
                    $rows[$day] = ['label' => ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][(int) $local->format('N') - 1] . ' ' . $local->format('j') . '.', 'cells' => []];
                }
                $rows[$day]['cells'][] = array_diff_key($c, ['d' => 1, 'h' => 1]);
            }
            return ['rows' => array_values($rows)];
        }
        if ($r['layout'] === 'years') {
            $rows = [];
            foreach ($cells as $c) {
                $rows[substr($c['k'], 0, 4)][] = $c;
            }
            // Gesamt: vom ersten Jahr mit Daten bis heute.
            $keep = [];
            $seen = false;
            foreach ($rows as $year => $list) {
                $seen = $seen || array_filter($list, static fn (array $c): bool => $c['v'] !== null);
                if ($seen || $year === array_key_last($rows)) {
                    $keep[] = ['label' => (string) $year, 'cells' => $list];
                }
            }
            return ['rows' => $keep];
        }
        $groups = [];
        foreach ($cells as $c) {
            $month = $c['m'];
            if (!isset($groups[$month])) {
                $groups[$month] = ['key' => $month, 'label' => month_label($month), 'offset' => $c['w'], 'cells' => []];
            }
            $groups[$month]['cells'][] = array_diff_key($c, ['m' => 1, 'w' => 1]);
        }
        return ['groups' => array_values($groups)];
    }

    /** Untere und obere Grenze der Farbskala: Anteile 0–100 %, Temperatur von Min bis Max, sonst ab 0. */
    private static function scale(string $kind, array $values): array
    {
        if (in_array($kind, ['ratio', 'level'], true)) {
            return [0.0, 100.0];
        }
        if (!$values) {
            return [0.0, 0.0];
        }
        if ($kind === 'temp') {
            return [min($values), max($values)];
        }
        return [0.0, max(0.0, max($values))];
    }

    /**
     * Werte je Zelle und der Wert des ganzen Zeitraums (Summe, Anteil aus den Summen oder Mittel).
     *
     * @return array{values: array<string, float>, total: ?float}
     */
    private function series(string $metric, string $period, int $start, int $end, int $now): array
    {
        $cfg = $this->store->all();
        $m = $cfg['mapping'];
        $tariffs = $cfg['tariffs'];
        $sum = static fn (array $values): ?float => $values ? array_sum($values) : null;
        $ratio = static function (array $part, array $whole, bool $invert) {
            $out = [];
            foreach ($whole as $key => $w) {
                if ($w < 0.01) {
                    continue;
                }
                $share = min(1.0, max(0.0, ($part[$key] ?? 0.0) / $w));
                $out[$key] = round(($invert ? 1 - $share : $share) * 100, 2);
            }
            $total = array_sum($whole);
            $share = $total >= 0.01 ? min(1.0, max(0.0, array_sum(array_intersect_key($part, $whole)) / $total)) : null;
            return ['values' => $out, 'total' => $share === null ? null : ($invert ? 1 - $share : $share) * 100];
        };
        switch ($metric) {
            case 'pv':
                $v = $this->pv($m, $period, $start, $end);
                return ['values' => $v, 'total' => $sum($v)];
            case 'forecast':
                $v = $this->forecast($cfg, $period, $start, $end);
                return ['values' => $v, 'total' => $sum($v)];
            case 'quality':
                // Güte = 100 % minus Abweichung zum größeren Wert; Monate nur aus Tagen mit beiden Werten.
                $grain = $period === 'month' ? 'day' : $period;
                $actual = $this->pv($m, $grain, $start, $end);
                $model = $this->forecast($cfg, $grain, $start, $end);
                $pairs = [];
                foreach ($model as $key => $f) {
                    $a = $actual[$key] ?? null;
                    if ($a === null || max($a, $f) < ($grain === 'hour' ? 0.05 : 0.3)) {
                        continue;
                    }
                    $group = $period === 'month' ? substr($key, 0, 7) : $key;
                    $pairs[$group] = [($pairs[$group][0] ?? 0.0) + $a, ($pairs[$group][1] ?? 0.0) + $f];
                }
                $score = static fn (float $a, float $f): float => max(0.0, 1 - abs($a - $f) / max($a, $f, 1e-9)) * 100;
                $out = array_map(static fn (array $pair): float => round($score($pair[0], $pair[1]), 2), $pairs);
                $sa = array_sum(array_column($pairs, 0));
                $sf = array_sum(array_column($pairs, 1));
                return ['values' => $out, 'total' => $pairs ? $score($sa, $sf) : null];
            case 'house':
                $v = $this->house($m, $period, $start, $end, false);
                return ['values' => $v, 'total' => $sum($v)];
            case 'import':
            case 'import_cost':
                $v = $this->grid($m, 'import', $period, $start, $end);
                if ($metric === 'import_cost') {
                    $v = array_map(static fn (float $kwh): float => $kwh * (float) $tariffs['import_ct'] / 100, $v);
                }
                return ['values' => $v, 'total' => $sum($v)];
            case 'export':
            case 'export_pay':
                $v = $this->grid($m, 'export', $period, $start, $end);
                if ($metric === 'export_pay') {
                    $v = array_map(static fn (float $kwh): float => $kwh * (float) $tariffs['export_ct'] / 100, $v);
                }
                return ['values' => $v, 'total' => $sum($v)];
            case 'autarky':
                return $ratio($this->grid($m, 'import', $period, $start, $end), $this->house($m, $period, $start, $end, true), true);
            case 'selfuse':
                return $ratio($this->grid($m, 'export', $period, $start, $end), $this->pv($m, $period, $start, $end), true);
            case 'co2':
                $pv = $this->pv($m, $period, $start, $end);
                $export = $this->grid($m, 'export', $period, $start, $end);
                $factor = (float) ($tariffs['co2_g_kwh'] ?? 380) / 1000;
                $v = [];
                foreach ($pv as $key => $kwh) {
                    $v[$key] = max(0.0, $kwh - ($export[$key] ?? 0.0)) * $factor;
                }
                return ['values' => $v, 'total' => $sum($v)];
            case 'soc':
                $v = $this->mean((string) ($m['battery_soc'] ?? ''), $period, $start, $end);
                return ['values' => $v, 'total' => $v ? array_sum($v) / count($v) : null];
            case 'bat_in':
            case 'bat_out':
                $v = $this->battery($m, $metric === 'bat_in' ? 'charge' : 'discharge', $period, $start, $end);
                return ['values' => $v, 'total' => $sum($v)];
            case 'charge':
            case 'charge_time':
            case 'charge_cost':
            case 'charge_count':
                $field = ['charge' => 'energy', 'charge_time' => 'seconds', 'charge_cost' => 'cost', 'charge_count' => 'count'][$metric];
                $v = array_filter(array_map(static fn (array $s): float => $s[$field], $this->charging($tariffs, $period, $start, $end)), static fn (float $x): bool => $x > 0);
                return ['values' => $v, 'total' => $sum($v) ?? 0.0];
            case 'charge_solar':
                $c = $this->charging($tariffs, $period, $start, $end);
                return $ratio(array_map(static fn (array $s): float => $s['solar'], $c), array_map(static fn (array $s): float => $s['energy'], $c), false);
            default:
                $field = ['radiation' => 'radiation', 'sunshine' => 'sunshine', 'cloud' => 'cloud', 'temp' => 'temp'][$metric] ?? 'radiation';
                $v = $this->weather($field, $period, $start, $end);
                $mean = in_array($field, ['cloud', 'temp'], true);
                return ['values' => $v, 'total' => $v ? ($mean ? array_sum($v) / count($v) : array_sum($v)) : null];
        }
    }

    /** Erzeugte PV-Energie: aus dem Energiezähler (Zuwachs), sonst aus der Leistung. */
    private function pv(array $m, string $period, int $start, int $end): array
    {
        $energy = (string) ($m['pv_energy'] ?? '');
        if ($energy !== '') {
            $notes = count($this->notes);
            $v = $this->counterKwh($energy, $period, $start, $end);
            if ($v) {
                return $v;
            }
            // Ohne Zuwachs gilt die Leistung; ein Hinweis zum Zähler wäre dann irreführend.
            array_splice($this->notes, $notes);
        }
        return $this->powerKwh((string) ($m['pv_power'] ?? ''), $period, $start, $end);
    }

    /** Haus ohne Wallbox; mit $total der ganze Verbrauch samt Wallbox (für die Autarkie). */
    private function house(array $m, string $period, int $start, int $end, bool $total): array
    {
        $house = $this->powerKwh((string) ($m['house_power'] ?? ''), $period, $start, $end);
        $includes = !empty($m['house_includes_wallbox']);
        if ($includes === $total) {
            return $house;
        }
        $wall = $this->wallbox($m, $period, $start, $end);
        $out = [];
        foreach ($house as $key => $kwh) {
            $out[$key] = max(0.0, $total ? $kwh + ($wall[$key] ?? 0.0) : $kwh - ($wall[$key] ?? 0.0));
        }
        return $out;
    }

    /**
     * Wallbox in kWh je Abschnitt: aus der Langzeitstatistik ihrer Leistung (Monate tageweise abgefragt) und, wo Home
     * Assistant keine hat (Entität ohne state_class, Zeit vor dem Wechsel auf eine neue Entität), aus den Ladevorgängen
     * von EMS, über ihre Ladezeit verteilt. So stimmt das Haus ohne Auto auch über einen Wechsel der Entität hinweg.
     */
    private function wallbox(array $m, string $period, int $start, int $end): array
    {
        $fine = $period === 'month' ? 'day' : $period;
        $notes = count($this->notes);
        $stats = $this->powerKwh((string) ($m['wallbox_power'] ?? ''), $fine, $start, $end);
        // Ohne Statistik springen die Ladevorgänge ein; der Hinweis auf die fehlende Statistik wäre irreführend.
        array_splice($this->notes, $notes);
        $sessions = array_map(static fn (array $s): float => $s['energy'], $this->charging([], $fine, $start, $end));
        $out = [];
        foreach ($stats + $sessions as $key => $kwh) {
            $bucket = $fine === $period ? (string) $key : substr((string) $key, 0, 7);
            $out[$bucket] = ($out[$bucket] ?? 0.0) + $kwh;
        }
        return $out;
    }

    /** Netzbezug oder Einspeisung, aus zwei Entitäten oder einer mit Vorzeichen. */
    private function grid(array $m, string $way, string $period, int $start, int $end): array
    {
        if (($m['grid_mode'] ?? 'split') === 'signed') {
            $positive = ($m['grid_sign'] ?? 'positive_import') === 'positive_import' ? 'import' : 'export';
            return $this->powerKwh((string) ($m['grid_signed'] ?? ''), $period, $start, $end, $way === $positive ? 'pos' : 'neg');
        }
        return $this->powerKwh((string) ($m[$way === 'import' ? 'grid_import' : 'grid_export'] ?? ''), $period, $start, $end);
    }

    /** Speicher geladen oder entladen, aus zwei Entitäten oder einer mit Vorzeichen. */
    private function battery(array $m, string $way, string $period, int $start, int $end): array
    {
        if (($m['battery_mode'] ?? 'split') === 'signed') {
            $positive = ($m['battery_sign'] ?? 'positive_charge') === 'positive_charge' ? 'charge' : 'discharge';
            return $this->powerKwh((string) ($m['battery_signed'] ?? ''), $period, $start, $end, $way === $positive ? 'pos' : 'neg');
        }
        return $this->powerKwh((string) ($m[$way === 'charge' ? 'battery_charge' : 'battery_discharge'] ?? ''), $period, $start, $end);
    }

    /**
     * PV-Prognose: Tage und Monate aus forecast_days (die Prognose des Tages, kalibriert), Stunden aus dem
     * Strahlungsmodell, je Tag auf dessen Prognose skaliert.
     */
    private function forecast(array $cfg, string $period, int $start, int $end): array
    {
        $days = [];
        $stmt = $this->store->pdo()->prepare('SELECT day, kwh FROM forecast_days WHERE day >= ? AND day < ?');
        try {
            $stmt->execute([$this->slot($start, 'day'), $this->slot($end, 'day')]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $days[(string) $row['day']] = (float) $row['kwh'];
            }
        } catch (Throwable) {
            $days = [];
        }
        if ($period === 'day') {
            return $days;
        }
        if ($period === 'month') {
            $out = [];
            foreach ($days as $day => $kwh) {
                $out[substr($day, 0, 7)] = ($out[substr($day, 0, 7)] ?? 0.0) + $kwh;
            }
            return $out;
        }
        $hours = (new WeatherFeed($this->store))->hours($start, $end);
        $out = [];
        foreach (Forecast::fromRadiation($hours, $cfg['plant']) as $point) {
            $key = $this->slot((int) $point['t'], 'hour');
            $out[$key] = ($out[$key] ?? 0.0) + max(0.0, (float) $point['kw']);
        }
        $byDay = [];
        foreach ($out as $key => $kwh) {
            $byDay[substr($key, 0, 10)] = ($byDay[substr($key, 0, 10)] ?? 0.0) + $kwh;
        }
        foreach ($out as $key => $kwh) {
            $day = substr($key, 0, 10);
            if (isset($days[$day]) && $byDay[$day] > 0.05) {
                $out[$key] = $kwh * $days[$day] / $byDay[$day];
            }
        }
        if (!$out) {
            $this->notes[] = 'Die stündliche Prognose gibt es nur für Stunden, deren DWD-Daten EMS aufgehoben hat.';
        }
        return $out;
    }

    /**
     * Ladezyklen über ihre Ladezeit (Beginn plus Ladedauer) verteilt: je Abschnitt energy, solar, cost, seconds;
     * ein Ladevorgang (alle Zyklen eines Ansteckens) zählt beim Beginn seines ersten Zyklus.
     *
     * @return array<string, array{energy: float, solar: float, cost: float, seconds: float, count: float}>
     */
    private function charging(array $tariffs, string $period, int $start, int $end): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT * FROM sessions WHERE started_at >= ? AND started_at < ? ORDER BY started_at ASC');
        $stmt->execute([date('c', $start - 3 * 86400), date('c', $end + 86400)]);
        $out = [];
        $add = function (string $key, string $field, float $value) use (&$out): void {
            $out[$key] ??= ['energy' => 0.0, 'solar' => 0.0, 'cost' => 0.0, 'seconds' => 0.0, 'count' => 0.0];
            $out[$key][$field] += $value;
        };
        $firsts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $t0 = (int) strtotime((string) $row['started_at']);
            $t1 = $t0 + max(60, (int) $row['duration_s']);
            $group = (string) (($row['plug_at'] ?? '') ?: 'id:' . $row['id']);
            $firsts[$group] = min($firsts[$group] ?? PHP_INT_MAX, $t0);
            if ($t1 <= $start || $t0 >= $end) {
                continue;
            }
            $energy = (float) $row['energy_kwh'];
            $solar = (float) $row['solar_kwh'];
            $cost = Sessions::cost($row, $tariffs)['cost'];
            for ($cur = $t0; $cur < $t1; $cur = $next) {
                $next = min($t1, $this->next($cur, $period));
                if ($next <= $start || $cur >= $end) {
                    continue;
                }
                $share = ($next - $cur) / ($t1 - $t0);
                $key = $this->slot($cur, $period);
                $add($key, 'energy', $energy * $share);
                $add($key, 'solar', $solar * $share);
                $add($key, 'cost', $cost * $share);
                $add($key, 'seconds', (float) ($next - $cur));
            }
        }
        foreach ($firsts as $t) {
            if ($t >= $start && $t < $end) {
                $add($this->slot($t, $period), 'count', 1.0);
            }
        }
        return $out;
    }

    /**
     * Wetter des DWD: Stunden aus weather_hours; Tage aus den Stunden, wenn der Tag fast ganz da ist, sonst aus dem
     * letzten Lauf vor Tagesende (forecast_issues); Monate aus den Tagen. radiation in kWh/m², sunshine in Sekunden.
     */
    private function weather(string $field, string $period, int $start, int $end): array
    {
        $column = ['radiation' => 'radiation', 'sunshine' => 'sunshine_s', 'cloud' => 'cloud', 'temp' => 'temp_c'][$field];
        $mean = in_array($field, ['cloud', 'temp'], true);
        $scale = $field === 'radiation' ? 0.001 : 1.0;
        $hours = [];
        foreach ((new WeatherFeed($this->store))->hours($start, $end) as $row) {
            if ($row[$column] !== null) {
                $hours[$this->slot((int) $row['t'], 'hour')] = (float) $row[$column] * $scale;
            }
        }
        if ($period === 'hour') {
            if (!$hours) {
                $this->notes[] = 'Stündliches Wetter gibt es ab jetzt laufend; ältere Stunden hat EMS nicht aufgehoben.';
            }
            return $hours;
        }
        $days = [];
        $count = [];
        foreach ($hours as $key => $value) {
            $day = substr($key, 0, 10);
            $days[$day] = ($days[$day] ?? 0.0) + $value;
            $count[$day] = ($count[$day] ?? 0) + 1;
        }
        foreach ($days as $day => $value) {
            if ($count[$day] < 20) {
                unset($days[$day]);
            } elseif ($mean) {
                $days[$day] = $value / $count[$day];
            }
        }
        // Ältere Tage aus den gespeicherten DWD-Läufen: je Tag der letzte Lauf vor Tagesende.
        $stmt = $this->store->pdo()->prepare('SELECT issue, day, radiation, sunshine_s, cloud, temp_c FROM forecast_issues WHERE day >= ? AND day < ? ORDER BY issue ASC');
        try {
            $stmt->execute([$this->slot($start, 'day'), $this->slot($end, 'day')]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            $rows = [];
        }
        $latest = [];
        foreach ($rows as $row) {
            $day = (string) $row['day'];
            if ($row[$column] === null || isset($days[$day]) || (int) $row['issue'] >= (int) strtotime($day . ' 00:00 Europe/Berlin') + 86400) {
                continue;
            }
            $latest[$day] = (float) $row[$column] * $scale;
        }
        $days += $latest;
        if ($period === 'day') {
            return $days;
        }
        $months = [];
        $n = [];
        foreach ($days as $day => $value) {
            $key = substr($day, 0, 7);
            $months[$key] = ($months[$key] ?? 0.0) + $value;
            $n[$key] = ($n[$key] ?? 0) + 1;
        }
        if ($mean) {
            foreach ($months as $key => $value) {
                $months[$key] = $value / $n[$key];
            }
        }
        return $months;
    }

    /** kWh je Abschnitt aus der Statistik einer Leistungs-Entität: Mittel mal Dauer; $part pos oder neg bei Vorzeichen. */
    private function powerKwh(string $entity, string $period, int $start, int $end, ?string $part = null): array
    {
        if ($entity === '') {
            return [];
        }
        $unit = $this->unit($entity);
        $out = [];
        foreach ($this->stats($entity, $period, $start, $end) as $row) {
            if ($row['mean'] === null) {
                continue;
            }
            $kw = match ($unit) {
                'kw' => (float) $row['mean'],
                'mw' => (float) $row['mean'] * 1000,
                'w' => (float) $row['mean'] / 1000,
                default => abs((float) $row['mean']) > 30 ? (float) $row['mean'] / 1000 : (float) $row['mean'],
            };
            $kw = $part === 'neg' ? max(0.0, -$kw) : max(0.0, $kw);
            $key = $this->slot((int) $row['start'], $period);
            $out[$key] = ($out[$key] ?? 0.0) + $kw * $this->hours((int) $row['start'], $period);
        }
        return $out;
    }

    /** kWh je Abschnitt aus dem Zuwachs eines Energiezählers. */
    private function counterKwh(string $entity, string $period, int $start, int $end): array
    {
        $unit = $this->unit($entity);
        $out = [];
        foreach ($this->stats($entity, $period, $start, $end) as $row) {
            if ($row['change'] === null) {
                continue;
            }
            $amount = max(0.0, (float) $row['change']);
            $kwh = match ($unit) {
                'wh' => $amount / 1000,
                'mwh' => $amount * 1000,
                'kwh' => $amount,
                default => $amount > 200 ? $amount / 1000 : $amount,
            };
            $key = $this->slot((int) $row['start'], $period);
            $out[$key] = ($out[$key] ?? 0.0) + $kwh;
        }
        return $out;
    }

    /** Mittelwert je Abschnitt, etwa der Ladestand in %. */
    private function mean(string $entity, string $period, int $start, int $end): array
    {
        if ($entity === '') {
            return [];
        }
        $out = [];
        foreach ($this->stats($entity, $period, $start, $end) as $row) {
            if ($row['mean'] !== null) {
                $out[$this->slot((int) $row['start'], $period)] = (float) $row['mean'];
            }
        }
        return $out;
    }

    /** Langzeitstatistik; abgeschlossene Zeiträume liegen eine Woche in stat_cache. */
    private function stats(string $entity, string $period, int $start, int $end): array
    {
        if ($entity === '' || !$this->ha->configured()) {
            return [];
        }
        $done = $end <= (new DateTimeImmutable('today', $this->tz))->getTimestamp() && !demo_mode();
        $key = $entity . '|' . $period . '|' . $start . '|' . $end;
        $pdo = $this->store->pdo();
        if ($done) {
            $stmt = $pdo->prepare('SELECT v FROM stat_cache WHERE k = ? AND at > ?');
            $stmt->execute([$key, time() - self::CACHE_S]);
            $hit = $stmt->fetchColumn();
            if (is_string($hit)) {
                $rows = json_decode($hit, true);
                if (is_array($rows)) {
                    return $rows;
                }
            }
        }
        try {
            $rows = $this->ha->statistics($entity, $start, $end, $period);
        } catch (Throwable $e) {
            $this->notes[] = 'Home Assistant liefert keine Statistik: ' . $e->getMessage();
            return [];
        }
        if (!$rows && $start < time()) {
            // Ohne state_class führt Home Assistant keine Langzeitstatistik; dann bleibt die Heatmap leer.
            $this->notes[] = 'Home Assistant hat für ' . $entity . ' in diesem Zeitraum keine Langzeitstatistik.';
        }
        if ($done && $rows) {
            $pdo->prepare('INSERT INTO stat_cache (k, at, v) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET at = excluded.at, v = excluded.v')
                ->execute([$key, time(), json_encode($rows)]);
            $pdo->prepare('DELETE FROM stat_cache WHERE at < ?')->execute([time() - self::CACHE_S]);
        }
        return $rows;
    }

    private function unit(string $entity): string
    {
        if ($this->index === null) {
            try {
                $this->index = $this->ha->index();
            } catch (Throwable) {
                $this->index = [];
            }
        }
        return strtolower(str_replace([' ', '·'], '', (string) ($this->index[$entity]['attributes']['unit_of_measurement'] ?? '')));
    }

    /** Stunden eines Abschnitts; der laufende Tag oder Monat zählt nur bis jetzt (volle Stunden). */
    private function hours(int $start, string $period): float
    {
        if ($period === 'hour') {
            return 1.0;
        }
        $end = $this->next($start, $period);
        $until = min($end, intdiv(time(), 3600) * 3600);
        return max(0.0, ($until - $start) / 3600);
    }

    /** Beginn des nächsten Abschnitts nach $t (Ortszeit). */
    private function next(int $t, string $period): int
    {
        if ($period === 'hour') {
            return (intdiv($t, 3600) + 1) * 3600;
        }
        $local = (new DateTimeImmutable('@' . $t))->setTimezone($this->tz);
        return $period === 'day'
            ? $local->setTime(0, 0)->modify('+1 day')->getTimestamp()
            : $local->modify('first day of next month')->setTime(0, 0)->getTimestamp();
    }

    /** Schlüssel eines Abschnitts in Ortszeit: „2026-10-10 14“, „2026-10-10“ oder „2026-10“. */
    private function slot(int $t, string $period): string
    {
        $local = (new DateTimeImmutable('@' . $t))->setTimezone($this->tz);
        return match ($period) {
            'hour' => $local->format('Y-m-d H'),
            'day' => $local->format('Y-m-d'),
            default => $local->format('Y-m'),
        };
    }

    /** Wert mit Einheit für Details und Zusammenfassung. */
    public static function text(string $kind, float $value, string $period): string
    {
        return match ($kind) {
            'energy' => kwh($value, $period === 'hour' ? 2 : ($period === 'month' ? 0 : 1)),
            'euro' => euro($value),
            'co2' => with_unit($value, $period === 'hour' ? 2 : ($period === 'month' ? 0 : 1), 'kg'),
            'radiation' => with_unit($value, $period === 'hour' ? 2 : ($period === 'month' ? 0 : 1), 'kWh/m²'),
            'duration' => $period === 'hour' ? with_unit($value / 60, 0, 'min') : with_unit($value / 3600, $period === 'month' ? 0 : 1, 'h'),
            'count' => num($value, 0),
            'temp' => with_unit($value, 1, '°C'),
            default => pct($value),
        };
    }

    /** Zahl ohne Einheit für das Feld selbst. */
    public static function short(string $kind, float $value, string $period): string
    {
        return match ($kind) {
            'energy', 'co2', 'radiation' => num($value, $period === 'hour' ? 1 : ($period === 'month' ? 0 : 1)),
            'euro' => num($value, $value >= 10 ? 0 : 2),
            'duration' => $period === 'hour' ? num($value / 60, 0) : num($value / 3600, 1),
            'count' => num($value, 0),
            'temp' => num($value, 0),
            default => num($value, 0),
        };
    }

    /** Zusammenfassung: Summe oder Gesamtanteil, Mittel je Feld, höchster und niedrigster Wert mit Datum. */
    private static function summary(string $kind, string $period, array $cells, array $values, ?float $total): array
    {
        $filled = array_values(array_filter($cells, static fn (array $c): bool => $c['v'] !== null));
        if (!$filled) {
            return [];
        }
        $per = ['hour' => 'Stunde', 'day' => 'Tag', 'month' => 'Monat'][$period];
        $value = static fn (array $c): float => (float) $values[$c['k']];
        usort($filled, static fn (array $a, array $b): int => $value($b) <=> $value($a));
        $best = $filled[0];
        $worst = $filled[count($filled) - 1];
        $mean = array_sum(array_map($value, $filled)) / count($filled);
        $out = [];
        if (in_array($kind, self::SUMS, true)) {
            $out[] = ['label' => 'Summe', 'text' => self::text($kind, (float) $total, $period === 'hour' ? 'day' : $period)];
            $out[] = ['label' => 'Ø je ' . $per, 'text' => self::text($kind, $mean, $period)];
            $out[] = ['label' => 'Höchster Wert', 'text' => self::text($kind, $value($best), $period), 'sub' => $best['l']];
        } elseif ($kind === 'ratio') {
            $out[] = ['label' => 'Ganzer Zeitraum', 'text' => $total === null ? '—' : pct($total)];
            $out[] = ['label' => 'Am besten', 'text' => pct($value($best)), 'sub' => $best['l']];
            $out[] = ['label' => 'Am schwächsten', 'text' => pct($value($worst)), 'sub' => $worst['l']];
        } else {
            $out[] = ['label' => 'Mittel', 'text' => self::text($kind, $mean, $period)];
            $out[] = ['label' => 'Höchster Wert', 'text' => self::text($kind, $value($best), $period), 'sub' => $best['l']];
            $out[] = ['label' => 'Niedrigster Wert', 'text' => self::text($kind, $value($worst), $period), 'sub' => $worst['l']];
        }
        return $out;
    }
}
