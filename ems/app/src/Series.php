<?php
declare(strict_types=1);

final class Series
{
    public function __construct(private ConfigStore $store, private HaClient $ha) {}

    public function yieldToday(array $mapping, ?string $powerUnit = 'W'): ?float
    {
        $cache = $this->store->get('yield_cache', null);
        if (is_array($cache) && (time() - (int) ($cache['t'] ?? 0)) < 60) {
            return $cache['kwh'];
        }
        $kwh = $this->computeYieldToday($mapping);
        $this->store->put('yield_cache', ['t' => time(), 'kwh' => $kwh]);
        return $kwh;
    }

    private function computeYieldToday(array $mapping): ?float
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $start = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->getTimestamp();
        $end = time();
        $energy = (string) ($mapping['pv_energy'] ?? '');
        if ($energy !== '' && $this->ha->configured()) {
            $rows = $this->ha->statistics($energy, $start, $end, 'hour');
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
        $rows = $this->ha->statistics($power, $start, $end, 'hour');
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
        return [
            'series' => [
                ['key' => 'soc', 'label' => 'Ladestand', 'color' => 'pv', 'axis' => 'y', 'data' => $soc],
                ['key' => 'cap', 'label' => 'Kapazität', 'color' => 'house', 'axis' => 'y1', 'data' => $cap],
                ['key' => 'priority', 'label' => 'Speicher-Vorrang', 'color' => 'export', 'axis' => 'y', 'dash' => true, 'data' => $this->flat($soc, (float) $strategy['priority_soc'])],
                ['key' => 'reserve', 'label' => 'Mindestreserve', 'color' => 'import', 'axis' => 'y', 'dash' => true, 'data' => $this->flat($soc, (float) $strategy['reserve_soc'])],
            ],
        ];
    }

    public function power(array $mapping, array $plant): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $start = (new DateTimeImmutable('now', $tz))->modify('-24 hours')->getTimestamp();
        $end = (new DateTimeImmutable('now', $tz))->modify('+1 day')->setTime(22, 0)->getTimestamp();
        $pvId = (string) ($mapping['pv_power'] ?? '');
        $actual = $this->points($pvId, $start, time(), '5minute', $this->wattScale($pvId));
        $state = $this->ha->configured() ? $this->ha->state((string) ($mapping['weather_radiation'] ?? '')) : null;
        $forecast = Forecast::fromAttribute(is_array($state) ? ($state['attributes']['data'] ?? []) : [], $plant);
        $future = [];
        $radiation = [];
        foreach ($forecast as $point) {
            if ($point['t'] < $start || $point['t'] > $end) {
                continue;
            }
            $future[] = ['x' => $point['t'] * 1000, 'y' => round($point['kw'], 3)];
            $radiation[] = ['x' => $point['t'] * 1000, 'y' => round($point['g'], 0)];
        }
        return [
            'series' => [
                ['key' => 'actual', 'label' => 'PV gemessen', 'color' => 'pv', 'axis' => 'y', 'data' => $actual],
                ['key' => 'forecast', 'label' => 'Prognose', 'color' => 'export', 'axis' => 'y', 'data' => $future],
                ['key' => 'radiation', 'label' => 'Strahlung', 'color' => 'wallbox', 'axis' => 'y1', 'data' => $radiation],
            ],
        ];
    }

    public function days(array $plant): array
    {
        $this->calibrate($plant, false);
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
        return [
            'daily' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'type' => 'bar', 'data' => $actual],
                ['key' => 'model', 'label' => 'Modell roh', 'color' => 'house', 'type' => 'bar', 'data' => $model],
                ['key' => 'k', 'label' => 'Güte k', 'color' => 'wallbox', 'axis' => 'y1', 'data' => $factor],
                ['key' => 'factor', 'label' => 'Eichfaktor', 'color' => 'export', 'axis' => 'y1', 'dash' => true, 'data' => $this->span($rows, $f)],
                ['key' => 'ideal', 'label' => 'Ideal 1,0', 'color' => 'muted', 'axis' => 'y1', 'dash' => true, 'data' => $this->span($rows, 1)],
            ],
            'compare' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'data' => $actual],
                ['key' => 'model', 'label' => 'Modell roh', 'color' => 'muted', 'data' => $model],
                ['key' => 'fitted', 'label' => 'Starrer Faktor', 'color' => 'house', 'data' => $fitted],
                ['key' => 'regress', 'label' => 'Regression', 'color' => 'export', 'data' => $regress],
            ],
            'goodness' => $factor,
        ];
    }

    public function weather(array $mapping): array
    {
        $start = time() - 24 * 3600;
        $end = time() + 36 * 3600;
        $sunId = (string) ($mapping['weather_sunshine'] ?? '');
        $sunUnit = $this->unitOf($sunId);
        $sunScale = str_contains($sunUnit, 'min') ? 1.0 : (1 / 60);
        $sun = $this->points($sunId, $start, time(), 'hour', $sunScale);
        $cloud = $this->points((string) ($mapping['weather_cloud'] ?? ''), $start, time(), 'hour', 1);
        $temp = $this->points((string) ($mapping['weather_temp'] ?? ''), $start, time(), 'hour', 1);
        return [
            'series' => [
                ['key' => 'sun', 'label' => 'Sonnenschein', 'color' => 'pv', 'axis' => 'y', 'data' => $sun],
                ['key' => 'cloud', 'label' => 'Bewölkung', 'color' => 'house', 'axis' => 'y1', 'data' => $cloud],
                ['key' => 'temp', 'label' => 'Temperatur', 'color' => 'import', 'axis' => 'y', 'data' => $temp],
            ],
        ];
    }

    public function calibrate(array $plant, bool $force): void
    {
        $last = (string) $this->store->get('last_calibration', '');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        if (!$force && $last === $today) {
            return;
        }
        if (!$this->ha->configured()) {
            return;
        }
        $cfg = $this->store->all();
        $map = $cfg['mapping'];
        $power = (string) ($map['pv_power'] ?? '');
        $radiation = (string) ($map['weather_radiation'] ?? '');
        if ($power === '' || $radiation === '') {
            return;
        }
        $n = max(3, (int) $plant['n_days']);
        $tz = new DateTimeZone('Europe/Berlin');
        $end = (new DateTimeImmutable('today', $tz))->getTimestamp();
        $start = $end - $n * 86400;
        $actualRows = $this->ha->statistics($power, $start, $end, 'hour');
        $modelRows = $this->ha->statistics($radiation, $start, $end, 'hour');
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
        if ($ratios && empty($plant['factor_locked'])) {
            $patch['factor'] = round(array_sum($ratios) / count($ratios), 3);
        }
        $fit = Forecast::regression($xs, $ys);
        if ($fit['a'] !== null && empty($plant['regress_locked'])) {
            $patch['regress_a'] = round($fit['a'], 3);
            $patch['regress_b'] = round($fit['b'], 3);
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

    private function points(string $entity, int $start, int $end, string $period, float $scale): array
    {
        if ($entity === '' || !$this->ha->configured()) {
            return [];
        }
        try {
            $rows = $this->ha->statistics($entity, $start, $end, $period);
        } catch (Throwable) {
            return [];
        }
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
