<?php
declare(strict_types=1);

/**
 * „Welcher Tarif lohnt sich?“ unter Prognose → Strompreis: ein Jahr Tag für Tag. Preise: die Börse der letzten 365 Tage
 * mit deinen Aufschlägen von heute (Einstellungen → Tarif). Sonne: der Ertrag deiner Anlage in einem üblichen Jahr am
 * Standort (PVGIS der EU-Kommission: stündlich aus einem echten Wetterjahr, sonst Monatsmittel). Haus und Auto
 * verbrauchen gleichmäßig über das Jahr, das Haus nach seinem üblichen Tagesverlauf. Stunde für Stunde nimmt das Auto
 * zuerst Sonnenüberschuss (oder keinen, wenn es nur im Fenster lädt), der Speicher den Rest bis voll und gibt ihn abends
 * und nachts ab; Überschuss trägt er nie über Wochen. Was dem Auto danach fehlt, kommt aus dem Netz, dynamisch im
 * günstigsten Fenster des Tages, so weit es in dessen Länge passt; den Netzbezug des Hauses lädt der Speicher dynamisch
 * an Tagen, an denen es lohnt, im Fenster vor. Je Kalenderwoche für die Heatmap: Ø Preis des Tages, Ø Preis im Fenster,
 * Ersparnis im Fenster gegenüber statisch, Sonne und Deckung von Haus und Auto.
 */
final class YearCheck
{
    public const SOURCE = 'Sonne: PVGIS, © Europäische Union';
    /** Stündlicher Ertrag eines Wetterjahres: PVGIS 5.3 kennt 2005 bis 2023, 5.2 als Ersatz bis 2020. */
    private const SERIES = [['https://re.jrc.ec.europa.eu/api/v5_3/seriescalc', 2023], ['https://re.jrc.ec.europa.eu/api/v5_2/seriescalc', 2020]];
    private const PVGIS = ['https://re.jrc.ec.europa.eu/api/v5_3/PVcalc', 'https://re.jrc.ec.europa.eu/api/v5_2/PVcalc'];
    /** Wetterjahre zur Wahl unter Einstellungen → Prognose und die Systemverluste, die PVGIS empfiehlt. */
    public const FIRST_YEAR = 2005;
    public const LAST_YEAR = 2023;
    public const LOSS_PCT = 14.0;
    /** Wie das Auto lädt: Sonnenüberschuss zuerst, den Rest im Fenster; oder nur im Fenster (tagsüber unterwegs). */
    public const CAR_MODES = ['sonne' => 'Sonne zuerst', 'fenster' => 'Nur im Fenster'];
    /** Anteil der Monate an einem Jahresertrag in Mitteldeutschland, wenn PVGIS nicht erreichbar ist. */
    private const SHARE = [1 => 0.025, 0.045, 0.08, 0.11, 0.13, 0.135, 0.135, 0.12, 0.09, 0.06, 0.03, 0.02];
    private const SPECIFIC_KWH = 950.0;

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    /**
     * Alles für die Karte: Wochen der Heatmap, das Jahr, Sonne, Vorschläge und die Annahmen. $query: haus (kWh im
     * Jahr), km (im Jahr), verbrauch (kWh/100 km) und auto (CAR_MODES) aus der Adresse, sonst die Vorschläge.
     */
    public function build(array $snap, array $query, int $now): array
    {
        $cfg = $snap['cfg'];
        $tariff = new Tariff($this->store);
        $t = $tariff->tariffAt($now);
        $length = (new PriceWindows($this->store, $tariff))->config()['length_h'];
        $days = $this->days($tariff, $length, $now);
        $pv = $this->pv((array) $cfg['plant'], $now);
        $suggest = $this->suggest($snap, $now);
        $number = static function (string $key, float $fallback, float $min, float $max) use ($query): float {
            $raw = str_replace(',', '.', trim((string) ($query[$key] ?? '')));
            return $raw !== '' && is_numeric($raw) ? clamp_float((float) $raw, $min, $max) : $fallback;
        };
        $inputs = [
            'house_kwh' => $number('haus', $suggest['house_kwh'], 0, 100000),
            'car_km' => $number('km', $suggest['car_km'], 0, 200000),
            'car_kwh100' => $number('verbrauch', $suggest['car_kwh100'], 5, 60),
            'car_mode' => isset(self::CAR_MODES[$query['auto'] ?? '']) ? (string) $query['auto'] : 'sonne',
        ];
        $carKwh = $inputs['car_km'] * $inputs['car_kwh100'] / 100 / Energy::CHARGE_EFFICIENCY;
        $values = $snap['values'];
        $capacity = is_numeric($values['battery_total_kwh'] ?? null) && (float) $values['battery_total_kwh'] > 0 ? (float) $values['battery_total_kwh'] : 0.0;
        $battery = BatteryPlan::settings($cfg);
        // Unter den Backup-Puffer (Notstrom) entlädt der Speicher nicht; nutzbar ist nur, was darüber liegt.
        $floorSoc = BatteryPlan::floorSoc($cfg, $values);
        $usable = $capacity * (1 - $floorSoc / 100);
        $coords = $this->location((array) $cfg['plant'], $now) ?? ['lat' => 50.0, 'lon' => 8.0];
        $result = self::simulate($days, $pv, [
            'house_kwh' => $inputs['house_kwh'],
            'car_kwh' => $carKwh,
            'profile' => is_array($snap['house_profile'] ?? null) ? $snap['house_profile'] : array_fill(0, 24, 1.0),
            'battery_kwh' => $usable,
            'battery_kw' => $battery['power_kw'],
            'efficiency' => $battery['efficiency'],
            'wear_ct' => $battery['wear_ct'],
            'car_kw' => ChargePlan::powerKw((array) $cfg['charge']),
            'car_mode' => $inputs['car_mode'],
            'length_h' => $length,
            'static_ct' => (float) $t['import_ct'],
            'static_windows' => self::windowPlan($t),
            'base_eur' => (float) ($t['base_eur'] ?? 0),
            'dyn_base_eur' => (float) ($t['dyn_base_eur'] ?? 0),
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'azimuth' => (float) ($cfg['plant']['azimuth'] ?? 180),
        ]);
        return $result + [
            'pv' => $pv,
            'suggest' => $suggest,
            'inputs' => $inputs + ['car_kwh' => round($carKwh)],
            'static_ct' => (float) $t['import_ct'],
            'base_eur' => (float) ($t['base_eur'] ?? 0),
            'dyn_base_eur' => (float) ($t['dyn_base_eur'] ?? 0),
            'base_missing' => $t['base_eur'] === null || $t['dyn_base_eur'] === null,
            'length_h' => $length,
            'car_kw' => ChargePlan::powerKw((array) $cfg['charge']),
            'battery_kwh' => $capacity,
            'battery_usable_kwh' => round($usable, 1),
            'floor_soc' => $floorSoc,
            'location' => $coords,
        ];
    }

    /**
     * Die letzten 365 ganzen Tage mit Preisen: je Tag die 24 Stundenmittel des dynamischen Preises mit den Aufschlägen
     * von heute, das Tagesmittel und das Mittel des günstigsten Fensters von $lengthH Stunden. Gespeichert in
     * kv.year_prices, solange Tarif, Fensterlänge und Tag gleich bleiben.
     *
     * @return list<array{date: string, week: int, month: int, hours: list<?float>, avg: ?float, window: ?float}>
     */
    public function days(Tariff $tariff, int $lengthH, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $t = $tariff->tariffAt($now);
        $key = md5(json_encode($t) . '|' . $lengthH . '|' . $today->format('Y-m-d') . '|1');
        $cache = $this->store->get('year_prices', []);
        if (is_array($cache) && ($cache['key'] ?? '') === $key && is_array($cache['days'] ?? null)) {
            return $cache['days'];
        }
        $start = $today->modify('-365 days');
        $spots = $tariff->market()->quarters($start->getTimestamp(), $today->getTimestamp());
        $days = [];
        for ($day = $start; $day < $today; $day = $day->modify('+1 day')) {
            $from = $day->getTimestamp();
            $to = $day->modify('+1 day')->getTimestamp();
            $quarters = [];
            $hours = array_fill(0, 24, []);
            for ($q = $from; $q < $to; $q += 900) {
                if (!isset($spots[$q])) {
                    continue;
                }
                $ct = Tariff::dynamicCt($spots[$q], $t, $q);
                $quarters[$q] = $ct;
                $hours[(int) (new DateTimeImmutable('@' . $q))->setTimezone($tz)->format('G')][] = $ct;
            }
            $best = count($quarters) >= 80 ? PriceWindows::cheapest($quarters, $lengthH * 4) : null;
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'week' => (int) $day->format('W'),
                'month' => (int) $day->format('n'),
                'hours' => array_map(static fn (array $v): ?float => $v ? round(array_sum($v) / count($v), 3) : null, $hours),
                'avg' => count($quarters) >= 80 ? round(array_sum($quarters) / count($quarters), 3) : null,
                'window' => $best === null ? null : round($best['ct'], 3),
            ];
        }
        $this->store->put('year_prices', ['key' => $key, 'days' => $days]);
        return $days;
    }

    /**
     * Ertrag der Anlage von PVGIS mit Standort (location()), kWp, Neigung, Ausrichtung und Systemverlusten
     * (plant.pvgis_loss, sonst 14 %): Stunde für Stunde aus dem Wetterjahr plant.pvgis_year (sonst 2023), sonst als
     * Monatsmittel; gespeichert in kv.pvgis, ein halbes Jahr lang oder bis sich etwas ändert. Abgefragt wird nur mit
     * $fetch (der Recorder), damit die Seite nie auf PVGIS wartet; bis dahin und wenn PVGIS nicht erreichbar ist, gilt
     * eine Schätzung aus 950 kWh je kWp und dem üblichen Verlauf über das Jahr.
     *
     * @return array{daily: array<int, float>, hours: ?array<string, list<float>>, weather_year: ?int, year: float, source: string, error: ?string}
     */
    public function pv(array $plant, int $now, bool $fetch = false): array
    {
        $kwp = (float) ($plant['kwp'] ?? 10);
        $tilt = (float) ($plant['tilt'] ?? 30);
        $azimuth = (float) ($plant['azimuth'] ?? 180);
        $estimate = function (?string $error, string $source = 'estimate') use ($kwp, $tilt, $azimuth): array {
            $year = self::SPECIFIC_KWH * $kwp * Forecast::geometry($tilt, $azimuth);
            $daily = [];
            foreach (self::SHARE as $month => $share) {
                $daily[$month] = round($year * $share / self::daysIn($month), 2);
            }
            return ['daily' => $daily, 'hours' => null, 'weather_year' => null, 'year' => round($year), 'source' => $source, 'error' => $error];
        };
        if (demo_mode()) {
            return $estimate(null, 'demo');
        }
        $coords = $this->location($plant, $now);
        if ($coords === null) {
            return $estimate('Home Assistant nennt keinen Standort; eintragen unter Einstellungen → Prognose.');
        }
        $settings = self::settings($plant);
        // PVGIS zählt die Ausrichtung von Süden: 0 Süd, 90 West, -90 Ost; EMS von Norden im Uhrzeigersinn.
        $aspect = round(fmod($azimuth - 180 + 540, 360) - 180);
        $params = ['lat' => round($coords['lat'], 3), 'lon' => round($coords['lon'], 3), 'peakpower' => round($kwp, 2), 'loss' => $settings['loss'], 'angle' => round($tilt), 'aspect' => $aspect, 'outputformat' => 'json'];
        $key = md5(json_encode($params) . '|2|' . $settings['year']);
        $cache = $this->store->get('pvgis', []);
        if (is_array($cache) && ($cache['key'] ?? '') === $key) {
            if (is_array($cache['daily'] ?? null) && $now - (int) ($cache['at'] ?? 0) < 180 * 86400) {
                return ['daily' => array_map('floatval', $cache['daily']), 'hours' => $cache['hours'] ?? null, 'weather_year' => $cache['weather_year'] ?? null, 'year' => (float) $cache['year'], 'source' => 'pvgis', 'error' => null];
            }
            if (!empty($cache['error']) && $now - (int) ($cache['at'] ?? 0) < 86400) {
                return $estimate((string) $cache['error']);
            }
        }
        if (!$fetch) {
            return $estimate('PVGIS wird gerade abgefragt, bis dahin gilt die Schätzung.', 'pending');
        }
        $error = null;
        // Am liebsten Stunde für Stunde aus einem echten Wetterjahr, mit trüben und sonnigen Tagen.
        $series = [[self::SERIES[0][0], $settings['year']], [self::SERIES[1][0], min($settings['year'], self::SERIES[1][1])]];
        foreach ($series as [$url, $weatherYear]) {
            try {
                $parsed = self::parseSeries(self::download($url . '?' . http_build_query($params + ['pvcalculation' => 1, 'startyear' => $weatherYear, 'endyear' => $weatherYear])));
                $this->store->put('pvgis', ['key' => $key, 'at' => $now, 'daily' => $parsed['daily'], 'hours' => $parsed['hours'], 'weather_year' => $weatherYear, 'year' => $parsed['year'], 'error' => null]);
                return $parsed + ['weather_year' => $weatherYear, 'source' => 'pvgis', 'error' => null];
            } catch (Throwable $e) {
                $error = 'PVGIS: ' . $e->getMessage();
            }
        }
        foreach (self::PVGIS as $url) {
            try {
                $parsed = self::parsePvgis(self::download($url . '?' . http_build_query($params)));
                $this->store->put('pvgis', ['key' => $key, 'at' => $now, 'daily' => $parsed['daily'], 'hours' => null, 'weather_year' => null, 'year' => $parsed['year'], 'error' => null]);
                return $parsed + ['hours' => null, 'weather_year' => null, 'source' => 'pvgis', 'error' => null];
            } catch (Throwable $e) {
                $error = 'PVGIS: ' . $e->getMessage();
            }
        }
        $this->store->put('pvgis', ['key' => $key, 'at' => $now, 'error' => $error]);
        return $estimate($error);
    }

    /**
     * Stundenwerte aus PVGIS seriescalc (P in W je Stunde, Zeit in UTC wie „20230101:0010“): je Kalendertag „m-d“ die
     * 24 Stunden in kWh nach Berliner Zeit, dazu kWh am Tag je Monat und im Jahr.
     *
     * @return array{hours: array<string, list<float>>, daily: array<int, float>, year: float}
     */
    public static function parseSeries(string $json): array
    {
        $data = json_decode($json, true);
        $rows = $data['outputs']['hourly'] ?? null;
        if (!is_array($rows) || count($rows) < 8000) {
            throw new RuntimeException(is_string($data['message'] ?? null) ? $data['message'] : 'Antwort ohne Stundenwerte.');
        }
        $utc = new DateTimeZone('UTC');
        $tz = new DateTimeZone('Europe/Berlin');
        $hours = [];
        foreach ($rows as $row) {
            $at = DateTimeImmutable::createFromFormat('Ymd:Hi', (string) ($row['time'] ?? ''), $utc);
            if ($at === false || !is_numeric($row['P'] ?? null)) {
                continue;
            }
            $local = $at->setTimezone($tz);
            $day = $local->format('m-d');
            $hours[$day] ??= array_fill(0, 24, 0.0);
            $hours[$day][(int) $local->format('G')] += max(0.0, (float) $row['P']) / 1000;
        }
        $byMonth = [];
        foreach ($hours as $day => $list) {
            $hours[$day] = array_map(static fn (float $v): float => round($v, 3), $list);
            $byMonth[(int) substr($day, 0, 2)][] = array_sum($list);
        }
        if (count($byMonth) !== 12) {
            throw new RuntimeException('Stundenwerte unvollständig.');
        }
        ksort($byMonth);
        $daily = array_map(static fn (array $sums): float => round(array_sum($sums) / count($sums), 2), $byMonth);
        return ['hours' => $hours, 'daily' => $daily, 'year' => round(array_sum(array_map('array_sum', $byMonth)))];
    }

    /**
     * Monatswerte aus der Antwort von PVGIS PVcalc: E_d (kWh am Tag) je Monat und E_y (kWh im Jahr).
     *
     * @return array{daily: array<int, float>, year: float}
     */
    public static function parsePvgis(string $json): array
    {
        $data = json_decode($json, true);
        $rows = $data['outputs']['monthly']['fixed'] ?? null;
        if (!is_array($rows) || count($rows) !== 12) {
            throw new RuntimeException(is_string($data['message'] ?? null) ? $data['message'] : 'Antwort ohne Monatswerte.');
        }
        $daily = [];
        foreach ($rows as $row) {
            $month = (int) ($row['month'] ?? 0);
            if ($month < 1 || $month > 12 || !is_numeric($row['E_d'] ?? null)) {
                throw new RuntimeException('Monatswerte unvollständig.');
            }
            $daily[$month] = round((float) $row['E_d'], 2);
        }
        ksort($daily);
        $year = $data['outputs']['totals']['fixed']['E_y'] ?? null;
        return ['daily' => $daily, 'year' => is_numeric($year) ? round((float) $year) : round(array_sum(array_map(static fn (int $m, float $d): float => $d * self::daysIn($m), array_keys($daily), $daily)))];
    }

    /** Tage eines Monats in einem Jahr ohne Schalttag. */
    private static function daysIn(int $month): int
    {
        return (int) (new DateTimeImmutable(sprintf('2025-%02d-01', $month)))->format('t');
    }

    /** Wetterjahr und Systemverluste für PVGIS aus plant, geprüft. @return array{year: int, loss: float} */
    public static function settings(array $plant): array
    {
        $year = is_numeric($plant['pvgis_year'] ?? null) ? (int) $plant['pvgis_year'] : self::LAST_YEAR;
        $loss = is_numeric($plant['pvgis_loss'] ?? null) ? (float) $plant['pvgis_loss'] : self::LOSS_PCT;
        return ['year' => max(self::FIRST_YEAR, min(self::LAST_YEAR, $year)), 'loss' => round(clamp_float($loss, 0, 50), 1)];
    }

    /**
     * Standort für PVGIS und den Sonnenbogen: von Hand unter Einstellungen → Prognose (plant.lat und plant.lon), sonst
     * der von Home Assistant. Mit source manual oder ha.
     *
     * @return ?array{lat: float, lon: float, source: string}
     */
    public function location(array $plant, int $now): ?array
    {
        if (is_numeric($plant['lat'] ?? null) && is_numeric($plant['lon'] ?? null)) {
            return ['lat' => (float) $plant['lat'], 'lon' => (float) $plant['lon'], 'source' => 'manual'];
        }
        $coords = $this->coordinates($now);
        return $coords === null ? null : ['lat' => $coords['lat'], 'lon' => $coords['lon'], 'source' => 'ha'];
    }

    /** Ein Standort lesbar, etwa „50,12° N, 8,52° O“. */
    public static function place(array $coords): string
    {
        return num(abs((float) $coords['lat']), 2) . '° ' . ((float) $coords['lat'] >= 0 ? 'N' : 'S') . ', ' . num(abs((float) $coords['lon']), 2) . '° ' . ((float) $coords['lon'] >= 0 ? 'O' : 'W');
    }

    /**
     * Stand der Abfrage für Einstellungen → Prognose: was gilt (pvgis, pending, error, estimate, demo), wann abgefragt,
     * Wetterjahr, kWh im Jahr, Fehler und Standort.
     */
    public function status(array $plant, int $now): array
    {
        $pv = $this->pv($plant, $now);
        $cache = $this->store->get('pvgis', []);
        return [
            'source' => $pv['source'],
            'at' => is_array($cache) && isset($cache['at']) ? (int) $cache['at'] : null,
            'weather_year' => $pv['weather_year'],
            'year_kwh' => (float) $pv['year'],
            'error' => $pv['error'],
            'location' => $this->location($plant, $now),
            'ha' => $this->coordinates($now),
        ];
    }

    /** Gespeicherten Ertrag verwerfen; der Recorder fragt PVGIS beim nächsten Schritt neu. */
    public function forget(): void
    {
        $this->store->put('pvgis', []);
    }

    /** Standort aus Home Assistant, einen Tag lang gemerkt. */
    public function coordinates(int $now): ?array
    {
        $cache = $this->store->get('ha_location', []);
        if (is_array($cache) && isset($cache['lat'], $cache['lon']) && $now - (int) ($cache['at'] ?? 0) < 86400) {
            return ['lat' => (float) $cache['lat'], 'lon' => (float) $cache['lon']];
        }
        $coords = $this->ha->coordinates();
        if ($coords !== null) {
            $this->store->put('ha_location', $coords + ['at' => $now]);
        }
        return $coords;
    }

    /**
     * Vorschläge aus deinen Daten: Haus im Jahr aus dem üblichen Tagesverlauf, Verbrauch des Autos aus Einstellungen
     * oder Auto, Kilometer im Jahr aus dem Kilometerstand der Ladevorgänge, sonst aus ihrer Energie.
     *
     * @return array{house_kwh: float, car_km: float, car_kwh100: float, notes: array<string, string>}
     */
    public function suggest(array $snap, int $now): array
    {
        $notes = [];
        $house = isset($snap['house_mean_kw']) && is_numeric($snap['house_mean_kw']) ? round((float) $snap['house_mean_kw'] * 8760 / 100) * 100 : null;
        $notes['house'] = $house === null ? 'Schätzwert, der übliche Hausverbrauch fehlt noch.' : 'Üblicher Tag der letzten 30 Tage mal 365.';
        $use = isset($snap['vehicle']['consumption_kwh']) && is_numeric($snap['vehicle']['consumption_kwh']) ? round((float) $snap['vehicle']['consumption_kwh'], 1) : null;
        $notes['use'] = $use === null ? 'Schätzwert, unter Einstellungen → Fahrzeug eintragen.' : (($snap['vehicle']['consumption_source'] ?? '') === 'car' ? 'So rechnet das Auto seine Reichweite.' : 'Aus Einstellungen → Fahrzeug.');
        $use ??= 18.0;
        $km = null;
        $pdo = $this->store->pdo();
        $since = date('c', $now - 365 * 86400);
        $stmt = $pdo->prepare('SELECT started_at, odometer FROM sessions WHERE odometer IS NOT NULL AND started_at >= ? ORDER BY started_at');
        $stmt->execute([$since]);
        $rows = $stmt->fetchAll() ?: [];
        if (count($rows) >= 2) {
            $first = $rows[0];
            $last = $rows[count($rows) - 1];
            $days = ((int) strtotime((string) $last['started_at']) - (int) strtotime((string) $first['started_at'])) / 86400;
            $driven = (float) $last['odometer'] - (float) $first['odometer'];
            if ($days >= 14 && $driven > 0) {
                $km = $driven / $days * 365;
                $notes['km'] = 'Aus dem Kilometerstand der Ladevorgänge, ' . num($days, 0) . ' Tage hochgerechnet.';
            }
        }
        if ($km === null) {
            $stmt = $pdo->prepare('SELECT MIN(started_at) AS first, SUM(energy_kwh) AS kwh FROM sessions WHERE started_at >= ?');
            $stmt->execute([date('c', $now - 90 * 86400)]);
            $row = $stmt->fetch() ?: [];
            $days = $row['first'] ? ($now - (int) strtotime((string) $row['first'])) / 86400 : 0;
            if ($days >= 14 && (float) $row['kwh'] > 0) {
                $km = (float) $row['kwh'] / $days * 365 * Energy::CHARGE_EFFICIENCY / $use * 100;
                $notes['km'] = 'Aus der Energie der Ladevorgänge, ' . num($days, 0) . ' Tage hochgerechnet.';
            }
        }
        $notes['km'] ??= 'Schätzwert, zu wenig Ladevorgänge zum Hochrechnen.';
        return [
            'house_kwh' => $house ?? 4000.0,
            'car_km' => $km === null ? 12000.0 : max(1000.0, round($km / 500) * 500),
            'car_kwh100' => $use,
            'notes' => $notes,
        ];
    }

    /**
     * Die Zeitfenster des statischen Tarifs für simulate(): je Verbraucher (car, battery) und Wochentag (1–7, der Tag,
     * an dem ein Fenster beginnt) die Fenster als [Stunden, ct/kWh], günstigste zuerst. Leer ohne Fenster.
     *
     * @return array<string, array<int, list<array{0: float, 1: float}>>>
     */
    public static function windowPlan(array $t): array
    {
        $out = [];
        foreach ((array) ($t['windows'] ?? []) as $w) {
            [$fh, $fm] = array_map('intval', explode(':', (string) $w['from']));
            [$th, $tm] = array_map('intval', explode(':', (string) $w['to']));
            $hours = ((($th * 60 + $tm) - ($fh * 60 + $fm) + 1440) % 1440) / 60.0;
            foreach ((array) $w['for'] as $use) {
                foreach ((array) $w['days'] === [] ? range(1, 7) : (array) $w['days'] as $day) {
                    $out[$use][(int) $day][] = [$hours, Tariff::windowCt($t, $w)];
                }
            }
        }
        foreach ($out as $use => $days) {
            foreach ($days as $day => $list) {
                usort($list, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
                $out[$use][$day] = $list;
            }
        }
        return $out;
    }

    /**
     * Das Jahr Tag für Tag, ohne Seiteneffekte. $in: house_kwh, car_kwh (an der Wallbox, mit Ladeverlust), profile (24
     * Gewichte des Hauses, Summe 1), battery_kwh, battery_kw, efficiency, wear_ct, car_kw, car_mode (CAR_MODES),
     * length_h (Länge des Fensters), static_ct, static_windows (windowPlan(), optional), base_eur und dyn_base_eur (je
     * Monat), lat und lon (für Sonnenauf- und -untergang), azimuth. Kosten nur für den Netzbezug; Arbeits- und Grundpreis
     * stehen getrennt. Mit Zeitfenstern kommt eine dritte Variante dazu: statisch, das Auto lädt in seinen Fenstern zu
     * deren Preis, so weit es in ihre Länge passt, und der Speicher lädt den Netzbezug des Hauses in seinen Fenstern vor,
     * wenn das nach Verlust und Verschleiß günstiger ist als der Arbeitspreis.
     *
     * @param list<array{date: string, week: int, month: int, hours: list<?float>, avg: ?float, window: ?float}> $days
     * @param array{daily: array<int, float>, hours?: ?array<string, list<float>>} $pv Sonne: Stunden je Tag „m-d“ in kWh,
     *   sonst kWh am Tag je Monat über den Sonnenbogen verteilt
     */
    public static function simulate(array $days, array $pv, array $in): array
    {
        $pvDaily = (array) $pv['daily'];
        $pvHours = is_array($pv['hours'] ?? null) ? $pv['hours'] : null;
        $tz = new DateTimeZone('Europe/Berlin');
        $profile = array_values($in['profile']);
        $sum = array_sum($profile);
        $profile = $sum > 0 ? array_map(static fn (float $w): float => $w / $sum, $profile) : array_fill(0, 24, 1 / 24);
        $houseDay = max(0.0, (float) $in['house_kwh']) / 365;
        $carDay = max(0.0, (float) $in['car_kwh']) / 365;
        $carKw = max(1.0, (float) $in['car_kw']);
        $carSun = ($in['car_mode'] ?? 'sonne') !== 'fenster';
        $lengthH = max(1, (int) ($in['length_h'] ?? 3));
        $capacity = max(0.0, (float) $in['battery_kwh']);
        $batteryKw = max(0.1, (float) $in['battery_kw']);
        $eta = max(0.5, min(1.0, (float) $in['efficiency']));
        $static = (float) $in['static_ct'];
        $plan = is_array($in['static_windows'] ?? null) ? $in['static_windows'] : [];
        $hasWindows = $plan !== [];
        $wear = (float) $in['wear_ct'];
        $soc = $capacity / 2;
        $typical = self::typicalDay($days);
        $weeks = [];
        $year = ['pv' => 0.0, 'house' => 0.0, 'car' => 0.0, 'grid_house' => 0.0, 'grid_car' => 0.0, 'export' => 0.0, 'cost_static' => 0.0, 'cost_dynamic' => 0.0,
            'cost_windows' => 0.0, 'tw_car' => 0.0, 'tw_shift' => 0.0,
            'grid_counted' => 0.0, 'car_window' => 0.0, 'car_rest' => 0.0, 'shift_kwh' => 0.0, 'shift_eur' => 0.0, 'shift_days' => 0, 'days' => 0, 'priced' => 0, 'counted' => 0];
        foreach ($days as $day) {
            $date = new DateTimeImmutable($day['date'] . ' 12:00:00', $tz);
            $md = substr($day['date'], 5);
            $hourly = $pvHours === null ? null : ($pvHours[$md] ?? ($md === '02-29' ? ($pvHours['02-28'] ?? null) : null));
            if ($hourly !== null) {
                $pvDay = array_sum($hourly);
                $shape = $pvDay > 0 ? array_map(static fn (float $v): float => $v / $pvDay, array_values($hourly)) : array_fill(0, 24, 0.0);
            } else {
                $pvDay = (float) ($pvDaily[$day['month']] ?? 0);
                $shape = self::sunShape($date, (float) $in['lat'], (float) $in['lon'], (float) $in['azimuth']);
            }
            $carNeed = $carDay;
            $gridHouse = $exported = 0.0;
            $importAt = [];
            for ($h = 0; $h < 24; $h++) {
                $pv = $pvDay * $shape[$h];
                $use = $houseDay * $profile[$h];
                $net = $pv - $use;
                if ($net > 0) {
                    $toCar = $carSun ? min($net, $carNeed, $carKw) : 0.0;
                    $carNeed -= $toCar;
                    $net -= $toCar;
                    $toBattery = min($net, ($capacity - $soc) / $eta, $batteryKw);
                    $soc += $toBattery * $eta;
                    $exported += $net - $toBattery;
                    continue;
                }
                $fromBattery = min(-$net, $soc, $batteryKw);
                $soc -= $fromBattery;
                $import = -$net - $fromBattery;
                if ($import > 1e-9) {
                    $gridHouse += $import;
                    $importAt[$h] = $import;
                }
            }
            $priced = $day['window'] !== null && $day['avg'] !== null;
            // Ein Tag ohne Börsenpreis rechnet mit dem Ø Tagesverlauf der Tage mit Preis.
            $prices = $priced ? $day : $typical;
            // Kosten nur für den Netzbezug; die Einspeisung ist in allen Tarifen gleich und zählt nicht mit.
            $staticCost = ($gridHouse + $carNeed) * $static;
            // Statisch mit Zeitfenstern: das Auto in seinen Fenstern, der Speicher lädt das Haus in seinen vor.
            $windowsCost = $staticCost;
            $twCar = $twShift = 0.0;
            if ($hasWindows) {
                $weekday = (int) $date->format('N');
                $rest = $carNeed;
                $carCost = 0.0;
                foreach ($plan['car'][$weekday] ?? [] as [$hours, $ct]) {
                    $take = min($rest, $carKw * $hours);
                    $carCost += $take * $ct;
                    $rest -= $take;
                    $twCar += $take;
                }
                $carCost += $rest * $static;
                $houseCost = $gridHouse * $static;
                $left = min($gridHouse, $capacity * $eta);
                foreach ($plan['battery'][$weekday] ?? [] as [$hours, $ct]) {
                    $cost = $ct / $eta + $wear;
                    if ($left <= 0 || $cost >= $static) {
                        break;
                    }
                    $shift = min($left, $batteryKw * $hours * $eta);
                    $houseCost -= $shift * ($static - $cost);
                    $left -= $shift;
                    $twShift += $shift;
                }
                $windowsCost = $houseCost + $carCost;
            }
            $dynamicCost = null;
            $shift = $shiftEur = $carWindow = $carRest = 0.0;
            if ($prices !== null) {
                $houseDyn = 0.0;
                foreach ($importAt as $h => $kwh) {
                    $houseDyn += $kwh * (float) ($prices['hours'][$h] ?? $prices['avg']);
                }
                // Lohnt es, lädt der Speicher den Netzbezug des Hauses im Fenster vor, so weit er und seine Leistung reichen.
                $window = (float) $prices['window'];
                $cost = $window / $eta + $wear;
                $avgImport = $gridHouse > 0 ? $houseDyn / $gridHouse : 0.0;
                if ($gridHouse > 0 && $avgImport > $cost) {
                    $shift = min($gridHouse, $capacity * $eta, $batteryKw * $lengthH * $eta);
                    $shiftEur = $shift * ($avgImport - $cost) / 100;
                }
                // Das Auto lädt im Fenster, so viel in dessen Länge passt; der Rest zum Ø Preis des Tages.
                $carWindow = min($carNeed, $carKw * $lengthH);
                $carRest = $carNeed - $carWindow;
                $dynamicCost = $houseDyn + $carWindow * $window + $carRest * (float) $prices['avg'] - $shiftEur * 100;
            }
            $w = $day['week'];
            $weeks[$w] ??= ['days' => 0, 'pv' => 0.0, 'demand' => 0.0, 'grid' => 0.0, 'avg' => [], 'window' => []];
            $weeks[$w]['days']++;
            $weeks[$w]['pv'] += $pvDay;
            $weeks[$w]['demand'] += $houseDay + $carDay;
            $weeks[$w]['grid'] += $gridHouse + $carNeed;
            if ($priced) {
                $weeks[$w]['avg'][] = (float) $day['avg'];
                $weeks[$w]['window'][] = (float) $day['window'];
            }
            $year['days']++;
            $year['pv'] += $pvDay;
            $year['house'] += $houseDay;
            $year['car'] += $carDay;
            $year['grid_house'] += $gridHouse;
            $year['grid_car'] += $carNeed;
            $year['export'] += $exported;
            $year['priced'] += $priced ? 1 : 0;
            if ($dynamicCost !== null) {
                $year['counted']++;
                $year['grid_counted'] += $gridHouse + $carNeed;
                $year['cost_static'] += $staticCost / 100;
                $year['cost_windows'] += $windowsCost / 100;
                $year['tw_car'] += $twCar;
                $year['tw_shift'] += $twShift;
                $year['cost_dynamic'] += (float) $dynamicCost / 100;
                $year['car_window'] += $carWindow;
                $year['car_rest'] += $carRest;
                $year['shift_kwh'] += $shift;
                $year['shift_eur'] += $shiftEur;
                $year['shift_days'] += $shift > 0 ? 1 : 0;
            }
        }
        // Aufs Jahr gerechnet (fehlt ein Tag ganz, zählen die übrigen anteilig); Grundpreise kommen für das ganze Jahr dazu.
        $scale = $year['counted'] > 0 ? 365 / $year['counted'] : 0.0;
        $out = [];
        ksort($weeks);
        foreach ($weeks as $w => $week) {
            $f = 7 / max(1, $week['days']);
            $dayAvg = $week['avg'] ? array_sum($week['avg']) / count($week['avg']) : null;
            $windowAvg = $week['window'] ? array_sum($week['window']) / count($week['window']) : null;
            $out[$w] = [
                'day' => $dayAvg === null ? null : round($dayAvg, 2),
                'window' => $windowAvg === null ? null : round($windowAvg, 2),
                'save' => $windowAvg === null ? null : round($static - $windowAvg, 2),
                'sun' => round($week['pv'] * $f, 1),
                'cover' => $week['demand'] > 0 ? round(max(0.0, 1 - $week['grid'] / $week['demand']) * 100, 1) : null,
            ];
        }
        $demand = $year['house'] + $year['car'];
        $staticEnergy = $year['cost_static'] * $scale;
        $dynamicEnergy = $year['cost_dynamic'] * $scale;
        $staticBase = 12 * (float) $in['base_eur'];
        $dynamicBase = 12 * (float) $in['dyn_base_eur'];
        $windowsEnergy = $year['cost_windows'] * $scale;
        return [
            'weeks' => $out,
            'year' => [
                'pv_kwh' => round($year['pv']),
                'demand_kwh' => round($demand),
                'house_kwh' => round($year['house']),
                'car_kwh' => round($year['car']),
                'car_sun_kwh' => round($year['car'] - $year['grid_car']),
                'grid_kwh' => round($year['grid_house'] + $year['grid_car']),
                'grid_house_kwh' => round($year['grid_house']),
                'grid_car_kwh' => round($year['grid_car']),
                'export_kwh' => round($year['export']),
                'cover_pct' => $demand > 0 ? round(max(0.0, 1 - ($year['grid_house'] + $year['grid_car']) / $demand) * 100) : null,
                'car_window_kwh' => round($year['car_window'] * $scale),
                'car_rest_kwh' => round($year['car_rest'] * $scale),
                'shift_kwh' => round($year['shift_kwh'] * $scale),
                'shift_eur' => round($year['shift_eur'] * $scale, 2),
                'shift_days' => (int) round($year['shift_days'] * $scale),
                'static_energy_eur' => round($staticEnergy, 2),
                'dynamic_energy_eur' => round($dynamicEnergy, 2),
                'static_base_eur' => round($staticBase, 2),
                'dynamic_base_eur' => round($dynamicBase, 2),
                'static_avg_ct' => $year['grid_counted'] > 0 ? round($year['cost_static'] * 100 / $year['grid_counted'], 2) : null,
                'dynamic_avg_ct' => $year['grid_counted'] > 0 ? round($year['cost_dynamic'] * 100 / $year['grid_counted'], 2) : null,
                'static_eur' => round($staticEnergy + $staticBase, 2),
                'dynamic_eur' => round($dynamicEnergy + $dynamicBase, 2),
                'difference' => round($staticEnergy + $staticBase - $dynamicEnergy - $dynamicBase, 2),
                'has_windows' => $hasWindows,
                'windows_energy_eur' => round($windowsEnergy, 2),
                'windows_base_eur' => round($staticBase, 2),
                'windows_eur' => round($windowsEnergy + $staticBase, 2),
                'windows_avg_ct' => $year['grid_counted'] > 0 ? round($year['cost_windows'] * 100 / $year['grid_counted'], 2) : null,
                'windows_car_kwh' => round($year['tw_car'] * $scale),
                'windows_shift_kwh' => round($year['tw_shift'] * $scale),
                'priced_days' => $year['priced'],
            ],
        ];
    }

    /**
     * Ø Tagesverlauf der Tage mit Börsenpreis (24 Stundenpreise, Tagesmittel, Fenster) für die Tage ohne; null ohne
     * einen Tag mit Preis. Rechnete das Jahr nur die bekannten Tage hoch, zählte bei fehlenden Wintertagen nur der Sommer.
     *
     * @param list<array{hours: list<?float>, avg: ?float, window: ?float}> $days
     * @return ?array{hours: list<?float>, avg: float, window: float}
     */
    public static function typicalDay(array $days): ?array
    {
        $known = array_values(array_filter($days, static fn (array $d): bool => $d['window'] !== null && $d['avg'] !== null));
        if (!$known) {
            return null;
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $values = array_values(array_filter(array_map(static fn (array $d): ?float => $d['hours'][$h] ?? null, $known), static fn (?float $v): bool => $v !== null));
            $hours[$h] = $values ? array_sum($values) / count($values) : null;
        }
        return [
            'hours' => $hours,
            'avg' => array_sum(array_column($known, 'avg')) / count($known),
            'window' => array_sum(array_column($known, 'window')) / count($known),
        ];
    }

    /**
     * Verlauf der Sonne über einen Tag als 24 Gewichte mit Summe 1: ein Sinusbogen von Sonnenauf- bis -untergang, bei
     * Ost- oder Westdächern um bis zu anderthalb Stunden verschoben.
     *
     * @return list<float>
     */
    public static function sunShape(DateTimeImmutable $date, float $lat, float $lon, float $azimuth): array
    {
        $sun = date_sun_info($date->getTimestamp(), $lat, $lon);
        $rise = is_int($sun['sunrise'] ?? null) ? $sun['sunrise'] : $date->setTime(7, 0)->getTimestamp();
        $set = is_int($sun['sunset'] ?? null) ? $sun['sunset'] : $date->setTime(19, 0)->getTimestamp();
        $shift = max(-1.5, min(1.5, ($azimuth - 180) / 90 * 1.5)) * 3600;
        $midnight = $date->setTime(0, 0)->getTimestamp();
        $weights = [];
        for ($h = 0; $h < 24; $h++) {
            $t = $midnight + $h * 3600 + 1800 - $shift;
            $weights[$h] = $t <= $rise || $t >= $set ? 0.0 : sin(M_PI * ($t - $rise) / ($set - $rise));
        }
        $sum = array_sum($weights);
        return $sum > 0 ? array_map(static fn (float $w): float => $w / $sum, $weights) : array_fill(0, 24, 1 / 24);
    }

    private static function download(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Keine Verbindung möglich.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'EMS-Home-Assistant',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code >= 400) {
            throw new RuntimeException('Status ' . ($code ?: 'ohne Verbindung') . '.');
        }
        return $body;
    }
}
