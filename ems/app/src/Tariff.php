<?php
declare(strict_types=1);

/**
 * Stromtarif mit Verlauf. Statisch: Arbeitspreis und Grundpreis brutto, optional Zeitfenster (windows), in denen das
 * Laden des Autos und/oder des Speichers anders kostet: Arbeitspreis minus einen Betrag oder ein fester Preis, an
 * gewählten Wochentagen, etwa bei zeitvariablem Netzentgelt nach §14a. Den Arbeitspreis kann eine Entität liefern
 * (mapping.price_static); der Recorder trägt eine Änderung dann ab heute in den Verlauf ein. Dynamisch: Börsenpreis plus Netzentgelt,
 * Steuern und Umlagen und Anbieteraufschlag (netto), darauf die MwSt, dazu ein Grundpreis; mit §14a Modul 3 gilt das
 * Netzentgelt in bis zu drei Zeitfenstern anders. Dazu die Einspeisevergütung nach EEG. Ein Satz enthält immer beide
 * Tarife und welcher aktiv ist, damit die Statistik jede Zeit in beiden rechnen kann. Jede Änderung gilt ab einem
 * Datum (kv.tariff_history); Ladevorgänge und Statistik nehmen für jede Zeit den Satz, der damals galt.
 * Hat Home Assistant eine Preis-Entität des Anbieters (mapping.price_import, etwa Tibber oder Octopus), zeichnet der
 * Recorder ihre Preise auf (provider_prices); im dynamischen Tarif gelten sie vor Börse plus Aufschlägen.
 */
final class Tariff
{
    public const HISTORY = 'tariff_history';
    /** Ohne Verlauf gilt der jetzige Tarif seit jeher. */
    public const ORIGIN = '2000-01-01';
    /**
     * Werte zum Vorbelegen, netto in ct/kWh, Stand 2026. Netzentgelt und Konzessionsabgabe hängen vom Netzgebiet ab,
     * die Umlagen ändern sich zum Jahreswechsel; die echten Werte stehen im Preisblatt von Netzbetreiber und Anbieter.
     * Steuern und Umlagen: Stromsteuer 2,05, Konzessionsabgabe 1,32 bis 2,39, KWKG-, Offshore- und §19-Umlage gut 2,9.
     */
    public const TYPICAL = ['dyn_grid_ct' => 9.0, 'dyn_levies_ct' => 6.7, 'dyn_supplier_ct' => 1.5, 'dyn_vat_pct' => 19.0];
    /** Wofür ein Zeitfenster des statischen Tarifs gilt. */
    public const USES = ['car' => 'Auto', 'battery' => 'Speicher'];
    public const WINDOWS_MAX = 6;

    private ?array $history = null;
    private ?MarketPrices $market = null;
    /** @var array<int, float> aufgezeichnete Preise des Anbieters je Viertelstunde in ct/kWh brutto */
    private array $provider = [];
    /** @var array<string, true> */
    private array $providerLoaded = [];

    public function __construct(private ConfigStore $store) {}

    /** Ein Satz vollständig und in Grenzen; Grundpreise bleiben null, solange niemand sie einträgt. */
    public static function normalize(array $t): array
    {
        $num = static fn (mixed $v, float $min, float $max, ?float $fallback): ?float => is_numeric($v) ? round(clamp_float((float) $v, $min, $max), 4) : $fallback;
        $windows = [];
        foreach ((array) ($t['dyn_windows'] ?? []) as $w) {
            if (!is_array($w)) {
                continue;
            }
            $from = self::clock($w['from'] ?? null);
            $to = self::clock($w['to'] ?? null);
            if ($from === null || $to === null || $from === $to || !is_numeric($w['ct'] ?? null)) {
                continue;
            }
            $windows[] = ['from' => $from, 'to' => $to, 'ct' => round(clamp_float((float) $w['ct'], 0, 100), 4)];
        }
        return [
            'mode' => ($t['mode'] ?? 'static') === 'dynamic' ? 'dynamic' : 'static',
            'import_ct' => $num($t['import_ct'] ?? null, 0, 200, 34.7),
            'windows' => self::staticWindows((array) ($t['windows'] ?? [])),
            'base_eur' => $num($t['base_eur'] ?? null, 0, 500, null),
            'dyn_base_eur' => $num($t['dyn_base_eur'] ?? null, 0, 500, null),
            'dyn_grid_ct' => $num($t['dyn_grid_ct'] ?? null, 0, 100, self::TYPICAL['dyn_grid_ct']),
            'dyn_levies_ct' => $num($t['dyn_levies_ct'] ?? null, 0, 100, self::TYPICAL['dyn_levies_ct']),
            'dyn_supplier_ct' => $num($t['dyn_supplier_ct'] ?? null, -20, 100, self::TYPICAL['dyn_supplier_ct']),
            'dyn_vat_pct' => $num($t['dyn_vat_pct'] ?? null, 0, 30, self::TYPICAL['dyn_vat_pct']),
            'dyn_windows' => array_slice($windows, 0, 3),
            'export_ct' => $num($t['export_ct'] ?? null, 0, 200, 11.0),
            'co2_g_kwh' => $num($t['co2_g_kwh'] ?? null, 0, 1500, 380.0),
        ];
    }

    /**
     * Zeitfenster des statischen Tarifs, bereinigt: from und to „HH:MM“ (über Mitternacht erlaubt), days 1–7 (leer
     * heißt alle), for Teilmenge von USES, kind minus (Arbeitspreis minus ct) oder price (fester Preis ct), ct brutto.
     *
     * @return list<array{from: string, to: string, days: list<int>, for: list<string>, kind: string, ct: float}>
     */
    public static function staticWindows(array $raw): array
    {
        $out = [];
        foreach ($raw as $w) {
            if (!is_array($w)) {
                continue;
            }
            $from = self::clock($w['from'] ?? null);
            $to = self::clock($w['to'] ?? null);
            $ct = str_replace(',', '.', trim((string) ($w['ct'] ?? '')));
            $for = array_values(array_intersect(array_keys(self::USES), array_map('strval', (array) ($w['for'] ?? []))));
            if ($from === null || $to === null || $from === $to || !is_numeric($ct) || $for === []) {
                continue;
            }
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($w['days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
            sort($days);
            $out[] = [
                'from' => $from,
                'to' => $to,
                'days' => count($days) === 7 ? [] : $days,
                'for' => $for,
                'kind' => ($w['kind'] ?? 'minus') === 'price' ? 'price' : 'minus',
                'ct' => round(clamp_float((float) $ct, 0, 200), 4),
            ];
        }
        return array_slice($out, 0, self::WINDOWS_MAX);
    }

    /**
     * Das Zeitfenster für einen Verbraucher zu einem Zeitpunkt, null außerhalb. Ein Fenster über Mitternacht gehört zum
     * Wochentag, an dem es beginnt. Passen mehrere, gilt das günstigste.
     */
    public static function windowAt(array $t, int $ts, string $use): ?array
    {
        $local = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $hm = $local->format('H:i');
        $today = (int) $local->format('N');
        $best = null;
        foreach ((array) ($t['windows'] ?? []) as $w) {
            if (!in_array($use, (array) $w['for'], true)) {
                continue;
            }
            $wraps = $w['from'] > $w['to'];
            $in = $wraps ? ($hm >= $w['from'] || $hm < $w['to']) : ($hm >= $w['from'] && $hm < $w['to']);
            $day = $wraps && $hm < $w['to'] ? ($today === 1 ? 7 : $today - 1) : $today;
            if (!$in || ((array) $w['days'] !== [] && !in_array($day, (array) $w['days'], true))) {
                continue;
            }
            if ($best === null || self::windowCt($t, $w) < self::windowCt($t, $best)) {
                $best = $w;
            }
        }
        return $best;
    }

    /** Preis brutto in einem Zeitfenster: fester Preis oder Arbeitspreis minus Betrag, nie unter 0. */
    public static function windowCt(array $t, array $w): float
    {
        return $w['kind'] === 'price' ? (float) $w['ct'] : max(0.0, (float) $t['import_ct'] - (float) $w['ct']);
    }

    /** Statischer Preis brutto für Haus, Auto oder Speicher zu einem Zeitpunkt. */
    public static function staticCt(array $t, int $ts, string $use = 'house'): float
    {
        $w = $use === 'house' ? null : self::windowAt($t, $ts, $use);
        return $w === null ? (float) $t['import_ct'] : self::windowCt($t, $w);
    }

    /** Fenster als Text: „22:00–06:00, Mo–Fr“. */
    public static function windowLabel(array $w): string
    {
        return $w['from'] . '–' . $w['to'] . ((array) $w['days'] === [] ? '' : ', ' . GridPlans::daysText((array) $w['days']));
    }

    /** Uhrzeit als „HH:MM“, null bei Unsinn; „24:00“ ist Mitternacht. */
    public static function clock(mixed $raw): ?string
    {
        if (!is_string($raw) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($raw), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h === 24 && $i === 0) {
            return '00:00';
        }
        return $h > 23 || $i > 59 ? null : sprintf('%02d:%02d', $h, $i);
    }

    /**
     * Verlauf aufsteigend nach „gültig ab“. Fehlt er, gilt der jetzige Tarif seit jeher.
     *
     * @return list<array{from: string, tariffs: array}>
     */
    public function history(): array
    {
        if ($this->history !== null) {
            return $this->history;
        }
        $raw = $this->store->get(self::HISTORY, null);
        $list = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_array($entry) && is_string($entry['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry['from'])) {
                $list[] = ['from' => $entry['from'], 'tariffs' => self::normalize((array) ($entry['tariffs'] ?? []))];
            }
        }
        if (!$list) {
            $list[] = ['from' => self::ORIGIN, 'tariffs' => self::normalize((array) $this->store->all()['tariffs'])];
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
        return $this->history = $list;
    }

    /** Ein Satz ab $from: gleiches Datum ersetzt, sonst kommt er dazu; der Verlauf bleibt sortiert. */
    public static function withEntry(array $history, string $from, array $tariffs): array
    {
        $history = array_values(array_filter($history, static fn (array $e): bool => $e['from'] !== $from));
        $history[] = ['from' => $from, 'tariffs' => self::normalize($tariffs)];
        usort($history, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
        return $history;
    }

    /** Ohne den Satz ab $from; der letzte Satz bleibt immer. */
    public static function withoutEntry(array $history, string $from): array
    {
        $rest = array_values(array_filter($history, static fn (array $e): bool => $e['from'] !== $from));
        return $rest ?: $history;
    }

    /** Speichert den Verlauf und hält kv.tariffs auf dem Satz, der heute gilt. */
    public function saveHistory(array $history, int $now): void
    {
        $this->store->put(self::HISTORY, array_map(static fn (array $e): array => ['from' => $e['from'], 'tariffs' => self::normalize($e['tariffs'])], $history));
        $this->history = null;
        $this->store->put('tariffs', $this->tariffAt($now));
    }

    /** Der Satz, der an einem Zeitpunkt galt (Tag in Berliner Zeit). */
    public static function at(array $history, int $ts): array
    {
        $day = self::day($ts);
        $found = $history[0]['tariffs'];
        foreach ($history as $entry) {
            if ($entry['from'] > $day) {
                break;
            }
            $found = $entry['tariffs'];
        }
        return $found;
    }

    public function tariffAt(int $ts): array
    {
        return self::at($this->history(), $ts);
    }

    /** Netzentgelt netto in ct/kWh zu einem Zeitpunkt: im Modul-3-Fenster dessen Preis, sonst der feste. */
    public static function gridCt(array $t, int $ts): float
    {
        if (!empty($t['dyn_windows'])) {
            $hm = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');
            foreach ($t['dyn_windows'] as $w) {
                $in = $w['from'] < $w['to'] ? ($hm >= $w['from'] && $hm < $w['to']) : ($hm >= $w['from'] || $hm < $w['to']);
                if ($in) {
                    return (float) $w['ct'];
                }
            }
        }
        return (float) $t['dyn_grid_ct'];
    }

    /**
     * Eine kWh im dynamischen Tarif in ct: Börse, Netz, Steuern und Umlagen, Anbieter (netto), MwSt und brutto.
     *
     * @return array{spot: float, grid: float, levies: float, supplier: float, vat: float, total: float}
     */
    public static function parts(float $spotCt, array $t, int $ts): array
    {
        $net = ['spot' => $spotCt, 'grid' => self::gridCt($t, $ts), 'levies' => (float) $t['dyn_levies_ct'], 'supplier' => (float) $t['dyn_supplier_ct']];
        $sum = array_sum($net);
        $vat = $sum * (float) $t['dyn_vat_pct'] / 100;
        return $net + ['vat' => $vat, 'total' => $sum + $vat];
    }

    /** Endpreis brutto in ct/kWh aus dem Börsenpreis netto. */
    public static function dynamicCt(float $spotCt, array $t, int $ts): float
    {
        return self::parts($spotCt, $t, $ts)['total'];
    }

    /** Aufschlag brutto in ct/kWh auf den Börsenpreis zu einem Zeitpunkt (ohne die MwSt auf die Börse selbst). */
    public static function surchargeCt(array $t, int $ts): float
    {
        return self::dynamicCt(0.0, $t, $ts);
    }

    public function market(): MarketPrices
    {
        return $this->market ??= new MarketPrices($this->store);
    }

    /**
     * Dynamischer Preis brutto zu einem Zeitpunkt: was der Anbieter gemeldet hat, sonst Börse plus Aufschläge des
     * Satzes, der damals galt. null ohne Börsenpreis.
     */
    public function dynamicAt(int $ts, ?array $t = null, bool $provider = true): ?float
    {
        $q = intdiv($ts, 900) * 900;
        if ($provider) {
            $own = $this->providerAt($q);
            if ($own !== null) {
                return $own;
            }
        }
        $spot = $this->market()->ctAt($q);
        return $spot === null ? null : self::dynamicCt($spot, $t ?? $this->tariffAt($q), $q);
    }

    /**
     * Bezugspreis brutto im damals aktiven Tarif für Haus, Auto oder Speicher ($use); im statischen gelten dessen
     * Zeitfenster, im dynamischen für alle derselbe Preis. null, wenn im dynamischen der Preis fehlt.
     */
    public function importCt(int $ts, string $use = 'house'): ?float
    {
        $t = $this->tariffAt($ts);
        return $t['mode'] === 'static' ? self::staticCt($t, $ts, $use) : $this->dynamicAt($ts, $t);
    }

    /** Zeitgewichtetes Mittel des Bezugspreises über [from, to), null ohne Preis. */
    public function averageImportCt(int $from, int $to, string $use = 'house'): ?float
    {
        $t = $this->tariffAt($from);
        if ($t['mode'] === 'static' && ($use === 'house' || $t['windows'] === []) && self::day($from) === self::day(max($from, $to - 1))) {
            return (float) $t['import_ct'];
        }
        $sum = $weight = 0.0;
        for ($q = intdiv($from, 900) * 900; $q < $to; $q += 900) {
            $part = min($to, $q + 900) - max($from, $q);
            $ct = $this->importCt(max($from, $q), $use);
            if ($part <= 0 || $ct === null) {
                continue;
            }
            $sum += $ct * $part;
            $weight += $part;
        }
        return $weight > 0 ? $sum / $weight : null;
    }

    /**
     * Preise für einen Ladevorgang (Sessions::cost): Netzstrom zum Mittel der Ladezeit im Tarif, der damals galt,
     * Sonnenstrom zur damaligen Einspeisevergütung.
     */
    public function forRow(array $row): array
    {
        $start = (int) strtotime((string) ($row['started_at'] ?? 'now'));
        $end = $start + max(60, (int) ($row['duration_s'] ?? 0));
        $t = $this->tariffAt($start);
        // Das Auto zahlt im statischen Tarif in seinen Zeitfenstern deren Preis.
        $import = $this->averageImportCt($start, $end, 'car') ?? (float) $t['import_ct'];
        return ['import_ct' => $import, 'export_ct' => (float) $t['export_ct'], 'co2_g_kwh' => (float) $t['co2_g_kwh']];
    }

    /**
     * Für Laden, Energie-Flow, Einstellungen und die Preis-Ansicht: Preis jetzt im aktiven Tarif, beide Tarife,
     * Börsenpreis und Einspeisung.
     */
    public function now(int $now): array
    {
        $t = $this->tariffAt($now);
        $spot = $this->market()->ctAt($now);
        $dynamic = $this->dynamicAt($now, $t);
        $static = $t['mode'] === 'static';
        return [
            'mode' => $t['mode'],
            'import_ct' => $static ? (float) $t['import_ct'] : $dynamic,
            'car_ct' => $static ? self::staticCt($t, $now, 'car') : $dynamic,
            'battery_ct' => $static ? self::staticCt($t, $now, 'battery') : $dynamic,
            'windows' => $static ? $t['windows'] : [],
            'static_ct' => (float) $t['import_ct'],
            'dynamic_ct' => $dynamic,
            'spot_ct' => $spot,
            'export_ct' => (float) $t['export_ct'],
            'surcharge_ct' => self::surchargeCt($t, $now),
        ];
    }

    /**
     * Ende der bekannten Preise: Börse oder die aufgezeichnete Preis-Entität des Anbieters (Tibber, Octopus …), je
     * nachdem, was weiter in die Zukunft reicht. Ladefenster, Ladeplan, Speicher und Diagramm rechnen bis hierhin.
     */
    public function horizon(): int
    {
        $provider = (int) ($this->store->pdo()->query('SELECT MAX("end") FROM provider_prices')->fetchColumn() ?: 0);
        return max($this->market()->lastEnd(), $provider);
    }

    /**
     * Viertelstunden mit aufgezeichnetem Anbieterpreis zwischen $from und $to.
     *
     * @return list<int>
     */
    public function providerQuarters(int $from, int $to): array
    {
        $out = [];
        for ($q = intdiv($from, 900) * 900; $q < $to; $q += 900) {
            if ($this->providerAt($q) !== null) {
                $out[] = $q;
            }
        }
        return $out;
    }

    /**
     * Viertelstunden des aufgezeichneten Anbieterpreises (ct/kWh brutto).
     */
    public function providerAt(int $q): ?float
    {
        $month = intdiv($q, 86400 * 28);
        $key = (string) $month;
        if (!isset($this->providerLoaded[$key])) {
            $this->providerLoaded[$key] = true;
            $stmt = $this->store->pdo()->prepare('SELECT start, "end", ct FROM provider_prices WHERE start >= ? AND start < ?');
            $stmt->execute([$month * 86400 * 28 - 3600, ($month + 1) * 86400 * 28]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                for ($t = (int) $row['start']; $t < (int) $row['end']; $t += 900) {
                    $this->provider[$t] = (float) $row['ct'];
                }
            }
        }
        return $this->provider[$q] ?? null;
    }

    /**
     * Preis-Entität des Anbieters lesen: der Zustand ist der Preis jetzt, Listen in den Attributen die Preise davor und
     * danach. Verstanden werden die üblichen Formen (Tibber, Octopus, EPEX Spot, Nord Pool): Einträge mit Beginn
     * (start, startsAt, start_time …), optional Ende und einem Preis (total, price, value, price_per_kwh,
     * value_inc_vat …). Die Einheit kommt aus unit_of_measurement (EUR/kWh, ct/kWh, EUR/MWh); ohne sie gelten Werte
     * unter 2 als Euro.
     *
     * @return array{now: ?float, rows: list<array{start:int, end:int, ct:float}>}
     */
    public static function parseEntity(?array $state, int $now): array
    {
        if (!is_array($state)) {
            return ['now' => null, 'rows' => []];
        }
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : [];
        $unit = strtolower(str_replace([' ', '€'], ['', 'eur'], (string) ($attrs['unit_of_measurement'] ?? '')));
        $toCt = static function (float $v) use ($unit): float {
            return match (true) {
                str_contains($unit, 'mwh') => $v / 10,
                str_starts_with($unit, 'ct'), str_starts_with($unit, 'cent'), str_starts_with($unit, 'p/') => $v,
                str_starts_with($unit, 'eur') => $v * 100,
                default => abs($v) < 2 ? $v * 100 : $v,
            };
        };
        $rows = [];
        foreach ($attrs as $value) {
            if (!is_array($value) || !array_is_list($value)) {
                continue;
            }
            $list = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $start = self::firstTime($item, ['start', 'startsAt', 'starts_at', 'start_time', 'startTime', 'from', 'valid_from', 'time']);
                $price = null;
                foreach (['total', 'price', 'value', 'price_per_kwh', 'value_inc_vat', 'price_ct_per_kwh', 'marketprice'] as $key) {
                    if (is_numeric($item[$key] ?? null)) {
                        $price = $key === 'price_ct_per_kwh' ? (float) $item[$key] : $toCt((float) $item[$key]);
                        break;
                    }
                }
                if ($start === null || $price === null) {
                    continue;
                }
                $end = self::firstTime($item, ['end', 'endsAt', 'ends_at', 'end_time', 'endTime', 'to', 'valid_to']);
                $list[] = ['start' => $start, 'end' => $end, 'ct' => round($price, 4)];
            }
            usort($list, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            foreach ($list as $i => $row) {
                $next = $list[$i + 1]['start'] ?? null;
                $gap = $next !== null ? $next - $row['start'] : ($i > 0 ? $row['start'] - $list[$i - 1]['start'] : 3600);
                $row['end'] ??= $row['start'] + (in_array($gap, [900, 1800, 3600], true) ? $gap : 3600);
                if ($row['end'] > $row['start'] && $row['end'] - $row['start'] <= 86400) {
                    $rows[$row['start']] = $row;
                }
            }
        }
        ksort($rows);
        $current = is_numeric($state['state'] ?? null) ? round($toCt((float) $state['state']), 4) : null;
        return ['now' => $current, 'rows' => array_values($rows)];
    }

    /**
     * Recorder: den Arbeitspreis des statischen Tarifs aus einer Entität übernehmen (mapping.price_static). Weicht er
     * vom Satz von heute ab, gilt der neue ab heute; frühere Tage behalten ihren Preis. Gibt den Preis zurück, null ohne.
     */
    public function syncStaticPrice(HaSource $ha, string $entity, int $now): ?float
    {
        if ($entity === '') {
            return null;
        }
        $ct = self::parseEntity($ha->state($entity), $now)['now'];
        if ($ct === null || $ct <= 0 || $ct > 200) {
            return null;
        }
        $t = $this->tariffAt($now);
        if (abs((float) $t['import_ct'] - $ct) >= 0.01) {
            $this->saveHistory(self::withEntry($this->history(), self::day($now), ['import_ct' => round($ct, 2)] + $t), $now);
        }
        return $ct;
    }

    /** Recorder: Preise der Anbieter-Entität aufzeichnen, die Viertelstunde von jetzt aus dem Zustand. */
    public function recordProvider(HaSource $ha, string $entity, int $now): void
    {
        if ($entity === '') {
            return;
        }
        $parsed = self::parseEntity($ha->state($entity), $now);
        $rows = $parsed['rows'];
        $q = intdiv($now, 900) * 900;
        $covered = false;
        foreach ($rows as $row) {
            $covered = $covered || ($row['start'] <= $now && $row['end'] > $now);
        }
        if (!$covered && $parsed['now'] !== null) {
            $rows[] = ['start' => $q, 'end' => $q + 900, 'ct' => $parsed['now']];
        }
        if (!$rows) {
            return;
        }
        $stmt = $this->store->pdo()->prepare('INSERT INTO provider_prices (start, "end", ct, source) VALUES (?, ?, ?, ?) ON CONFLICT(start) DO UPDATE SET "end" = excluded."end", ct = excluded.ct, source = excluded.source');
        foreach ($rows as $row) {
            $stmt->execute([$row['start'], $row['end'], $row['ct'], $entity]);
        }
        $this->provider = [];
        $this->providerLoaded = [];
    }

    private static function firstTime(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $item[$key] ?? null;
            if (is_numeric($value)) {
                $n = (int) $value;
                return $n > 20000000000 ? intdiv($n, 1000) : $n;
            }
            if (is_string($value) && $value !== '') {
                $t = strtotime($value);
                if ($t !== false) {
                    return $t;
                }
            }
        }
        return null;
    }

    private static function day(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }
}
