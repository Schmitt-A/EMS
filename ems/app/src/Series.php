<?php
declare(strict_types=1);

final class Series
{
    private ?string $statNote = null;

    public function __construct(private ConfigStore $store, private HaClient $ha) {}

    public function yieldToday(array $mapping, ?string $powerUnit = 'W'): ?float
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $start = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->getTimestamp();
        return $this->yieldBetween($start, time(), $mapping);
    }

    public function yieldBetween(int $start, int $end, array $mapping): ?float
    {
        $cache = $this->store->get('yield_cache', []);
        $entries = is_array($cache) && isset($cache['entries']) && is_array($cache['entries']) ? $cache['entries'] : [];
        $key = $start . ':' . (int) floor($end / 60);
        if (isset($entries[$key]) && (time() - (int) ($entries[$key]['t'] ?? 0)) < 60) {
            return $entries[$key]['kwh'];
        }
        $kwh = $this->computeYield($start, $end, $mapping);
        $entries[$key] = ['t' => time(), 'kwh' => $kwh];
        $this->store->put('yield_cache', ['entries' => array_slice($entries, -4, null, true)]);
        return $kwh;
    }

    private function computeYield(int $start, int $end, array $mapping): ?float
    {
        $energy = (string) ($mapping['pv_energy'] ?? '');
        if ($energy !== '' && $this->ha->configured()) {
            try {
                $rows = $this->ha->statistics($energy, $start, $end, 'hour');
            } catch (Throwable) {
                $rows = [];
            }
            $sum = 0.0;
            $seen = false;
            $unit = $this->unitOf($energy);
            foreach ($rows as $row) {
                if ($row['change'] === null) {
                    continue;
                }
                $seen = true;
                $sum += max(0, (float) $row['change']);
            }
            if ($seen) {
                $kwh = match ($unit) {
                    'wh' => $sum / 1000,
                    'mwh' => $sum * 1000,
                    '' => $sum > 200 ? $sum / 1000 : $sum,
                    default => $sum,
                };
                return $kwh > 0 ? $kwh : null;
            }
        }
        $power = (string) ($mapping['pv_power'] ?? '');
        if ($power === '' || !$this->ha->configured()) {
            return null;
        }
        $rows = $this->statisticRows($power, $start, $end, 'hour');
        $unit = $this->unitOf($power);
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += $this->meanKw($row['mean'], $unit);
        }
        return $sum > 0 ? $sum : null;
    }

    public function battery(int $hours, array $mapping, array $strategy): array
    {
        $end = time();
        $start = $end - $hours * 3600;
        $period = $hours <= 48 ? '5minute' : 'hour';
        $soc = $this->points((string) ($mapping['battery_soc'] ?? ''), $start, $end, $period, 1);
        $capId = (string) ($mapping['battery_capacity'] ?? '');
        $cap = $this->points($capId, $start, $end, $period, $this->energyScale($capId));
        return $this->withNote([
            'series' => [
                ['key' => 'soc', 'label' => 'Ladestand', 'color' => 'pv', 'axis' => 'y', 'data' => $soc],
                ['key' => 'cap', 'label' => 'Kapazität', 'color' => 'house', 'axis' => 'y1', 'data' => $cap],
                ['key' => 'priority', 'label' => 'Speicher-Vorrang', 'color' => 'export', 'axis' => 'y', 'dash' => true, 'data' => $this->flat($soc, (float) $strategy['priority_soc'])],
                ['key' => 'reserve', 'label' => 'Mindestreserve', 'color' => 'import', 'axis' => 'y', 'dash' => true, 'data' => $this->flat($soc, (float) $strategy['reserve_soc'])],
            ],
        ]);
    }

    public function power(array $mapping, array $plant): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $now = time();
        $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $historyStart = $today->modify('-45 days')->getTimestamp();
        $pvId = (string) ($mapping['pv_power'] ?? '');
        $actual = $this->points($pvId, $historyStart, $now, 'hour', $this->wattScale($pvId));
        $feed = new WeatherFeed($this->store);
        $horizon = $feed->hours($today->getTimestamp(), $now + 12 * 86400);
        $forecast = Forecast::fromRadiation($horizon, $plant);
        $future = [];
        $radiation = [];
        $last = $now;
        foreach ($forecast as $point) {
            if ($point['t'] < $today->getTimestamp()) {
                continue;
            }
            $last = max($last, $point['t']);
            $future[] = ['x' => $point['t'] * 1000, 'y' => round($point['kw'], 3)];
            $radiation[] = ['x' => $point['t'] * 1000, 'y' => round($point['g'], 0)];
        }
        $first = $actual[0]['x'] ?? ($today->getTimestamp() * 1000);
        $span = max(1, (int) ceil(($last - (int) ($first / 1000)) / 86400));
        return $this->withNote([
            'days' => $span,
            'today' => [$today->getTimestamp() * 1000, $today->modify('+1 day')->getTimestamp() * 1000],
            'series' => [
                ['key' => 'actual', 'label' => 'PV gemessen', 'color' => 'pv', 'axis' => 'y', 'data' => $actual],
                ['key' => 'forecast', 'label' => 'Prognose', 'color' => 'export', 'axis' => 'y', 'data' => $future],
                ['key' => 'radiation', 'label' => 'Strahlung', 'color' => 'wallbox', 'axis' => 'y1', 'data' => $radiation],
            ],
        ]);
    }

    public function outlook(array $plant): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $now = time();
        $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $todayKey = $today->format('Y-m-d');
        $feed = new WeatherFeed($this->store);
        $hours = $feed->hours($today->modify('-1 day')->getTimestamp(), $now + 12 * 86400);
        $series = Forecast::fromRadiation($hours, $plant);
        $byForecast = [];
        foreach (Forecast::dailyTotals($series, $plant) as $row) {
            $byForecast[$row['day']] = $row;
        }
        $mapping = $this->store->all()['mapping'] ?? [];
        $actualToday = null;
        try {
            $actualToday = $this->yieldToday(is_array($mapping) ? $mapping : []);
        } catch (Throwable) {
            $actualToday = null;
        }
        if ($series) {
            $brief = Forecast::brief($series, $plant, $now, $actualToday);
            $byForecast[$todayKey] = [
                'day' => $todayKey,
                'start' => $today->getTimestamp(),
                'kwh' => $brief['today_kwh'],
            ];
        }
        $stored = [];
        foreach ($this->store->pdo()->query('SELECT day, actual_kwh FROM daily') ?: [] as $row) {
            if ($row['actual_kwh'] === null) {
                continue;
            }
            $stored[(string) $row['day']] = round((float) $row['actual_kwh'], 2);
        }
        if ($actualToday !== null) {
            $stored[$todayKey] = round($actualToday, 2);
        }
        $days = array_keys($stored + $byForecast);
        sort($days);
        $forecast = $actual = [];
        foreach ($days as $day) {
            $start = (new DateTimeImmutable($day . ' 00:00:00', $tz))->getTimestamp();
            $x = ($start + 43200) * 1000;
            $forecast[] = ['x' => $x, 'y' => isset($byForecast[$day]) ? round((float) $byForecast[$day]['kwh'], 2) : null];
            $actual[] = ['x' => $x, 'y' => $stored[$day] ?? null];
        }
        return [
            'days' => max(1, count($days)),
            'today' => [$today->getTimestamp() * 1000, $today->modify('+1 day')->getTimestamp() * 1000],
            'series' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'type' => 'bar', 'data' => $actual],
                ['key' => 'forecast', 'label' => 'Prognose', 'color' => 'export', 'type' => 'bar', 'data' => $forecast],
            ],
        ];
    }

    public function days(array $plant): array
    {
        try {
            $this->calibrate($plant, false);
        } catch (Throwable) {
            // Vorhandene Tage bleiben sichtbar, auch wenn die Statistik gerade nicht antwortet.
        }
        $stored = $this->store->get('plant', []);
        if (is_array($stored)) {
            $plant = array_merge($plant, $stored);
        }
        $rows = $this->store->pdo()->query('SELECT day, actual_kwh, model_kwh FROM daily ORDER BY day ASC')->fetchAll() ?: [];
        $actual = $model = $factor = $fitted = $regress = [];
        $f = (float) $plant['factor'];
        foreach ($rows as $row) {
            $x = strtotime($row['day'] . ' 12:00:00 Europe/Berlin') * 1000;
            $a = $row['actual_kwh'] !== null ? round((float) $row['actual_kwh'], 2) : null;
            $m = $row['model_kwh'] !== null ? round((float) $row['model_kwh'], 2) : null;
            $actual[] = ['x' => $x, 'y' => $a];
            $model[] = ['x' => $x, 'y' => $m];
            $factor[] = ['x' => $x, 'y' => ($a && $m) ? round($m / max(0.1, $a), 3) : null];
            $fitted[] = ['x' => $x, 'y' => $m !== null ? round($m * $f, 2) : null];
            $regress[] = ['x' => $x, 'y' => $m !== null ? round(max(0, (float) $plant['regress_a'] + (float) $plant['regress_b'] * $m), 2) : null];
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $frame = [
            'days' => max(1, count($rows)),
            'today' => [$today->getTimestamp() * 1000, $today->modify('+1 day')->getTimestamp() * 1000],
        ];
        $noted = $this->withNote(['series' => [
            ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'data' => $actual],
        ]]);
        $error = $noted['error'] ?? null;
        $daily = $frame + [
            'series' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'type' => 'bar', 'data' => $actual],
                ['key' => 'model', 'label' => 'Modell roh', 'color' => 'house', 'type' => 'bar', 'data' => $model],
                ['key' => 'k', 'label' => 'Güte k', 'color' => 'wallbox', 'axis' => 'y1', 'data' => $factor],
                ['key' => 'factor', 'label' => 'Eichfaktor', 'color' => 'export', 'axis' => 'y1', 'dash' => true, 'data' => $this->span($rows, $f)],
                ['key' => 'ideal', 'label' => 'Ideal 1,0', 'color' => 'muted', 'axis' => 'y1', 'dash' => true, 'data' => $this->span($rows, 1)],
            ],
        ];
        $compare = $frame + [
            'series' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'data' => $actual],
                ['key' => 'model', 'label' => 'Modell roh', 'color' => 'muted', 'data' => $model],
                ['key' => 'fitted', 'label' => 'Starrer Faktor', 'color' => 'house', 'data' => $fitted],
                ['key' => 'regress', 'label' => 'Regression', 'color' => 'export', 'data' => $regress],
            ],
        ];
        if ($error) {
            $daily['error'] = $error;
            $compare['error'] = $error;
        }
        return ['daily' => $daily, 'compare' => $compare];
    }

    public function weather(array $mapping): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $rows = (new WeatherFeed($this->store))->hours($today->modify('-2 days')->getTimestamp(), time() + 12 * 86400);
        $sun = $cloud = $temp = $radiation = [];
        $last = time();
        foreach ($rows as $row) {
            $x = $row['t'] * 1000;
            $last = max($last, $row['t']);
            if ($row['sunshine_s'] !== null) {
                $sun[] = ['x' => $x, 'y' => round($row['sunshine_s'] / 60, 2)];
            }
            if ($row['cloud'] !== null) {
                $cloud[] = ['x' => $x, 'y' => round($row['cloud'], 1)];
            }
            if ($row['temp_c'] !== null) {
                $temp[] = ['x' => $x, 'y' => round($row['temp_c'], 1)];
            }
            if ($row['radiation'] !== null) {
                $radiation[] = ['x' => $x, 'y' => round($row['radiation'], 0)];
            }
        }
        $span = max(1, (int) ceil(($last - $today->modify('-2 days')->getTimestamp()) / 86400));
        $payload = [
            'days' => $span,
            'today' => [$today->getTimestamp() * 1000, $today->modify('+1 day')->getTimestamp() * 1000],
            'series' => [
                ['key' => 'radiation', 'label' => 'Strahlung', 'color' => 'pv', 'axis' => 'y', 'data' => $radiation],
                ['key' => 'sun', 'label' => 'Sonnenschein', 'color' => 'export', 'axis' => 'y1', 'data' => $sun],
                ['key' => 'cloud', 'label' => 'Bewölkung', 'color' => 'house', 'axis' => 'y1', 'data' => $cloud],
                ['key' => 'temp', 'label' => 'Temperatur', 'color' => 'import', 'axis' => 'y', 'data' => $temp],
            ],
        ];
        if (!$radiation && !$sun && !$cloud && !$temp) {
            $error = (new WeatherFeed($this->store))->meta()['error'] ?? null;
            if ($error) {
                $payload['error'] = $error;
            }
        }
        return $payload;
    }

    public function calibrate(array $plant, bool $force): void
    {
        $last = (string) $this->store->get('last_calibration', '');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $storedPlant = $this->store->get('plant', []);
        $paired = (int) (is_array($storedPlant) ? ($storedPlant['regress_days'] ?? 0) : 0);
        $locked = is_array($storedPlant) && !empty($storedPlant['factor_locked']);
        $factorNow = (float) (is_array($storedPlant) ? ($storedPlant['factor'] ?? 0.93) : 0.93);
        $needsReset = $paired < 5 && !$locked && abs($factorNow - 0.93) > 0.001;
        if (!$force && $last === $today && !$needsReset) {
            return;
        }
        if (!$this->ha->configured()) {
            return;
        }
        $cfg = $this->store->all();
        $map = $cfg['mapping'];
        $power = (string) ($map['pv_power'] ?? '');
        $radiation = (string) ($map['weather_radiation'] ?? '');
        if ($power === '') {
            return;
        }
        $this->rememberActuals($power);
        if ($radiation === '') {
            $this->store->put('last_calibration', $today);
            return;
        }
        $n = max(3, (int) $plant['n_days']);
        $tz = new DateTimeZone('Europe/Berlin');
        $end = (new DateTimeImmutable('today', $tz))->getTimestamp();
        $start = $end - $n * 86400;
        $actualRows = $this->statisticRows($power, $start, $end, 'hour');
        $modelRows = $this->statisticRows($radiation, $start, $end, 'hour');
        $byDay = [];
        $powerUnit = $this->unitOf($power);
        foreach ($actualRows as $row) {
            if ($row['mean'] === null) {
                continue;
            }
            $day = (new DateTimeImmutable('@' . $row['start']))->setTimezone($tz)->format('Y-m-d');
            $byDay[$day]['actual'] = ($byDay[$day]['actual'] ?? 0) + $this->meanKw($row['mean'], $powerUnit);
        }
        foreach ($modelRows as $row) {
            if ($row['mean'] === null) {
                continue;
            }
            $local = (new DateTimeImmutable('@' . $row['start']))->setTimezone($tz);
            $day = $local->format('Y-m-d');
            $rawPlant = $plant;
            $rawPlant['factor'] = 1;
            $kw = Forecast::powerKw(max(0, $row['mean']), (int) $local->format('G'), $rawPlant);
            $byDay[$day]['model'] = ($byDay[$day]['model'] ?? 0) + $kw;
        }
        $stmt = $this->store->pdo()->prepare('INSERT INTO daily (day, actual_kwh, model_kwh) VALUES (?, ?, ?) ON CONFLICT(day) DO UPDATE SET actual_kwh = excluded.actual_kwh, model_kwh = excluded.model_kwh');
        $xs = [];
        $ys = [];
        $ratios = [];
        foreach ($byDay as $day => $row) {
            if ($day === $today) {
                continue;
            }
            $actual = $row['actual'] ?? null;
            $model = $row['model'] ?? null;
            $stmt->execute([$day, $actual, $model]);
            if ($actual !== null && $model !== null && $model > 1) {
                $xs[] = $model;
                $ys[] = $actual;
                $ratios[] = $actual / $model;
            }
        }
        $patch = ['regress_days' => count($xs)];
        if (count($xs) >= 5) {
            if ($ratios && empty($plant['factor_locked'])) {
                $patch['factor'] = round(array_sum($ratios) / count($ratios), 3);
            }
            $fit = Forecast::regression($xs, $ys);
            if ($fit['a'] !== null && empty($plant['regress_locked'])) {
                $patch['regress_a'] = round($fit['a'], 3);
                $patch['regress_b'] = round($fit['b'], 3);
            }
        } elseif (empty($plant['factor_locked'])) {
            $patch['factor'] = (float) ($this->store->defaults()['plant']['factor'] ?? 0.93);
        }
        $this->store->merge('plant', $patch);
        $this->store->put('last_calibration', $today);
    }

    private function unitOf(string $entity): string
    {
        if ($entity === '' || !$this->ha->configured()) {
            return '';
        }
        try {
            $row = $this->ha->index()[$entity] ?? null;
        } catch (Throwable) {
            return '';
        }
        if (!is_array($row)) {
            return '';
        }
        return strtolower(str_replace([' ', '·'], '', (string) ($row['attributes']['unit_of_measurement'] ?? '')));
    }

    private function wattScale(string $entity): float
    {
        return $this->unitOf($entity) === 'kw' ? 1.0 : 0.001;
    }

    private function energyScale(string $entity): float
    {
        return match ($this->unitOf($entity)) {
            'kwh' => 1.0,
            'mwh' => 1000.0,
            default => 0.001,
        };
    }

    private function meanKw(?float $mean, string $unit): float
    {
        if ($mean === null) {
            return 0.0;
        }
        if ($unit === 'kw') {
            return max(0, $mean);
        }
        if ($unit === 'w') {
            return max(0, $mean / 1000);
        }
        if ($unit === '') {
            return $mean > 30 ? $mean / 1000 : max(0, $mean);
        }
        return 0.0;
    }

    private function rememberActuals(string $power): void
    {
        $rows = $this->statisticRows($power, time() - 420 * 86400, time(), 'day');
        $unit = $this->unitOf($power);
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $stmt = $this->store->pdo()->prepare('INSERT INTO daily (day, actual_kwh) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET actual_kwh = excluded.actual_kwh');
        foreach ($rows as $row) {
            if ($row['mean'] === null) {
                continue;
            }
            $day = (new DateTimeImmutable('@' . $row['start']))->setTimezone($tz)->format('Y-m-d');
            if ($day === $today) {
                continue;
            }
            $stmt->execute([$day, round($this->meanKw($row['mean'], $unit) * 24, 3)]);
        }
    }

    private function statisticRows(string $entity, int $start, int $end, string $period): array
    {
        if ($entity === '' || !$this->ha->configured()) {
            return [];
        }
        try {
            return $this->ha->statistics($entity, $start, $end, $period);
        } catch (Throwable $e) {
            $this->statNote = $e->getMessage();
            return $this->historyBuckets($entity, $start, $end, $period);
        }
    }

    private function withNote(array $payload): array
    {
        foreach ($payload['series'] as $series) {
            if (!empty($series['data'])) {
                return $payload;
            }
        }
        if ($this->statNote) {
            $payload['error'] = $this->statNote;
        }
        return $payload;
    }

    private function historyBuckets(string $entity, int $start, int $end, string $period): array
    {
        try {
            $samples = $this->ha->history($entity, $start, $end);
        } catch (Throwable) {
            return [];
        }
        $bucket = match ($period) {
            'day' => 86400,
            'hour' => 3600,
            default => 300,
        };
        $groups = [];
        foreach ($samples as $sample) {
            $key = intdiv($sample['t'], $bucket) * $bucket;
            $groups[$key][] = $sample['v'];
        }
        $out = [];
        foreach ($groups as $t => $values) {
            $out[] = ['start' => $t, 'mean' => array_sum($values) / count($values), 'change' => null];
        }
        return $out;
    }

    private function points(string $entity, int $start, int $end, string $period, float $scale): array
    {
        $rows = $this->statisticRows($entity, $start, $end, $period);
        $out = [];
        foreach ($rows as $row) {
            if ($row['mean'] === null) {
                continue;
            }
            $out[] = ['x' => $row['start'] * 1000, 'y' => round($row['mean'] * $scale, 3)];
        }
        return $out;
    }

    private function flat(array $points, float $value): array
    {
        if (!$points) {
            $now = time() * 1000;
            return [['x' => $now - 3600000, 'y' => $value], ['x' => $now, 'y' => $value]];
        }
        return [
            ['x' => $points[0]['x'], 'y' => $value],
            ['x' => $points[count($points) - 1]['x'], 'y' => $value],
        ];
    }

    private function span(array $rows, float $factor): array
    {
        if (!$rows) {
            return [];
        }
        $first = strtotime($rows[0]['day'] . ' 12:00:00 Europe/Berlin') * 1000;
        $last = strtotime($rows[count($rows) - 1]['day'] . ' 12:00:00 Europe/Berlin') * 1000;
        return [['x' => $first, 'y' => $factor], ['x' => $last, 'y' => $factor]];
    }
}
