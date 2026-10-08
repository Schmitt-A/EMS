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
        $key = $start . ':' . (int) floor($end / 60) . ':' . trim((string) ($mapping['pv_energy'] ?? ''));
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
        $energy = $this->energyEntity($mapping);
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
                $kwh = $this->energyKwh($sum, $unit);
                return $kwh > 0 ? $kwh : null;
            }
            return null;
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
        $this->repairKnownDays();
        $feed = new WeatherFeed($this->store);
        $horizon = $feed->hours($today->modify('-1 day')->getTimestamp(), $now + 12 * 86400);
        $forecast = Forecast::fromRadiation($horizon, $plant);
        Forecast::rememberDays($this->store->pdo(), $forecast, $plant);
        $future = [];
        $last = $now;
        $yMax = 0.0;
        foreach ($actual as $point) {
            $yMax = max($yMax, (float) $point['y']);
        }
        foreach ($forecast as $point) {
            if ($point['t'] < $today->getTimestamp()) {
                continue;
            }
            $last = max($last, $point['t']);
            $y = round($point['kw'], 3);
            $yMax = max($yMax, $y);
            $future[] = ['x' => $point['t'] * 1000, 'y' => $y];
        }
        $firstMs = $actual[0]['x'] ?? ($today->getTimestamp() * 1000);
        $viewStart = $today->getTimestamp();
        $viewEnd = $today->modify('+3 days')->getTimestamp();
        $boundStart = (new DateTimeImmutable('@' . (int) floor($firstMs / 1000)))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
        $boundEnd = (new DateTimeImmutable('@' . $last))->setTimezone($tz)->modify('+1 day')->setTime(0, 0)->getTimestamp();
        $boundStart = min($boundStart, $viewStart);
        $boundEnd = max($boundEnd, $viewEnd);
        $captions = Forecast::captions($this->store->pdo(), $plant);
        $scale = Forecast::energyScale($yMax);
        $marks = [];
        $cursor = (new DateTimeImmutable('@' . $boundStart))->setTimezone($tz)->setTime(0, 0);
        $endMark = (new DateTimeImmutable('@' . $boundEnd))->setTimezone($tz);
        while ($cursor < $endMark) {
            $day = $cursor->format('Y-m-d');
            $caption = $captions[$day] ?? null;
            $marks[] = [
                'start' => $cursor->getTimestamp() * 1000,
                'x' => $cursor->modify('+12 hours')->getTimestamp() * 1000,
                'label' => self::dayLabel($day),
                'text' => $caption ? Forecast::captionText($caption['kwh'], $caption['sd']) : null,
            ];
            $cursor = $cursor->modify('+1 day');
        }
        $span = max(1, (int) round(($boundEnd - $boundStart) / 86400));
        return $this->withNote([
            'days' => $span,
            'today' => [$today->getTimestamp() * 1000, $today->modify('+1 day')->getTimestamp() * 1000],
            'view' => [$viewStart * 1000, $viewEnd * 1000],
            'bounds' => [$boundStart * 1000, $boundEnd * 1000],
            'marks' => $marks,
            'yLock' => true,
            'yStep' => $scale['step'],
            'yDataMax' => $scale['dataMax'],
            'yMax' => $scale['max'],
            'yTitle' => 'Energie (kWh)',
            'series' => [
                ['key' => 'actual', 'label' => 'PV gemessen', 'color' => 'pv', 'axis' => 'y', 'data' => $actual],
                ['key' => 'forecast', 'label' => 'Prognose', 'color' => 'export', 'axis' => 'y', 'data' => $future],
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
        Forecast::rememberDays($this->store->pdo(), $series, $plant);
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
            $brief = Forecast::brief($series, $plant, $now, $actualToday, Forecast::locked($this->store->pdo(), $todayKey));
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
            $value = $byForecast[$day]['kwh'] ?? null;
            $forecast[] = ['x' => $x, 'y' => $value === null ? null : round((float) $value, 2)];
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
        $rows = $this->store->pdo()->query('SELECT day, actual_kwh, model_kwh, model_mode FROM daily ORDER BY day ASC')->fetchAll() ?: [];
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $todayKey = $today->format('Y-m-d');
        $actualToday = null;
        try {
            $mapping = $this->store->all()['mapping'] ?? [];
            $actualToday = $this->yieldToday(is_array($mapping) ? $mapping : []);
        } catch (Throwable) {
            $actualToday = null;
        }
        $lockedToday = Forecast::locked($this->store->pdo(), $todayKey);
        $labels = $keys = $actual = $model = $gute = $raw = $fitted = $regress = [];
        $seenToday = false;
        $complete = [];
        foreach ($rows as $row) {
            $day = (string) $row['day'];
            $mode = isset($row['model_mode']) && $row['model_mode'] !== null ? (string) $row['model_mode'] : null;
            if ($mode === 'drop') {
                continue;
            }
            $isToday = $day === $todayKey;
            $seenToday = $seenToday || $isToday;
            $a = $row['actual_kwh'] !== null ? round((float) $row['actual_kwh'], 2) : null;
            $m = $row['model_kwh'] !== null ? (float) $row['model_kwh'] : null;
            if ($isToday) {
                $a = $actualToday !== null ? round($actualToday, 2) : $a;
                $predicted = $lockedToday;
            } else {
                $predicted = Forecast::displayedModel($mode, $m, $plant);
            }
            $shown = $predicted !== null ? round($predicted, 2) : null;
            $labels[] = self::dayLabel($day);
            $keys[] = $day;
            $actual[] = $a;
            $model[] = $shown;
            $gute[] = ($a && $predicted) ? round($predicted / $a, 3) : null;
            $pinned = $mode === 'pin';
            $raw[] = (!$pinned && $m !== null) ? round($m, 2) : null;
            $fitted[] = (!$pinned && $m !== null) ? round($m * (float) $plant['factor'], 2) : null;
            $regress[] = (!$pinned && $m !== null) ? round(max(0, (float) $plant['regress_a'] + (float) $plant['regress_b'] * $m), 2) : null;
            if (!$isToday && $a !== null && $a > 0 && $shown !== null) {
                $complete[$day] = ['actual' => $a, 'model' => $shown];
            }
        }
        if (!$seenToday) {
            $labels[] = self::dayLabel($todayKey);
            $keys[] = $todayKey;
            $actual[] = $actualToday !== null ? round($actualToday, 2) : null;
            $model[] = $lockedToday !== null ? round($lockedToday, 2) : null;
            $gute[] = ($actualToday && $lockedToday) ? round($lockedToday / $actualToday, 3) : null;
            $raw[] = null;
            $fitted[] = null;
            $regress[] = null;
        }
        $labels = array_reverse($labels);
        $keys = array_reverse($keys);
        $actual = array_reverse($actual);
        $model = array_reverse($model);
        $gute = array_reverse($gute);
        $raw = array_reverse($raw);
        $fitted = array_reverse($fitted);
        $regress = array_reverse($regress);
        $count = count($labels);
        $last = max(0, $count - 1);
        $frame = [
            'axis' => 'category',
            'pan' => 'index',
            'labels' => $labels,
            'days' => max(1, $count),
            'view' => [0, min(2, $last)],
            'bounds' => [0, $last],
            'xTitle' => 'Tag',
            'yTitle' => 'Energie (kWh)',
        ];
        $noted = $this->withNote(['series' => [
            ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'data' => array_map(fn ($y) => ['x' => 0, 'y' => $y], array_filter($actual, fn ($y) => $y !== null))],
        ]]);
        $error = $noted['error'] ?? null;
        $compare = $frame + [
            'series' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'data' => $actual],
                ['key' => 'model', 'label' => 'Modell roh', 'color' => 'muted', 'data' => $raw],
                ['key' => 'fitted', 'label' => 'Starrer Faktor', 'color' => 'house', 'data' => $fitted],
                ['key' => 'regress', 'label' => 'Regression', 'color' => 'export', 'data' => $regress],
            ],
        ];
        $dailyLabels = $dailyActual = $dailyModel = $dailyGute = $dailyKeys = [];
        foreach ($keys as $i => $day) {
            if (!isset($complete[$day])) {
                continue;
            }
            $dailyKeys[] = $day;
            $dailyLabels[] = self::dayLabel($day);
            $dailyActual[] = $actual[$i];
            $dailyModel[] = $model[$i];
            $dailyGute[] = $gute[$i];
        }
        $windows = [];
        $scores = [];
        foreach (['3', '7', 'month', 'quarter'] as $name) {
            $wanted = Forecast::windowDays($dailyKeys, $todayKey, $name);
            $windows[$name] = $this->indexSpan($dailyKeys, $wanted);
            $scores[$name] = $this->scoreDays($complete, $wanted);
        }
        $opening = $windows['3'] ?? [0, 0];
        $daily = [
            'axis' => 'category',
            'pan' => 'index',
            'grouped' => true,
            'labels' => $dailyLabels,
            'days' => max(1, count($dailyKeys)),
            'view' => $opening,
            'bounds' => $opening,
            'windows' => $windows,
            'goodness' => $scores,
            'xTitle' => 'Tag',
            'yTitle' => 'Energie (kWh)',
            'y1Title' => 'Güte',
            'series' => [
                ['key' => 'actual', 'label' => 'Ist', 'color' => 'pv', 'type' => 'bar', 'data' => $dailyActual],
                ['key' => 'model', 'label' => 'Modell', 'color' => 'export', 'type' => 'bar', 'data' => $dailyModel],
                ['key' => 'k', 'label' => 'Güte', 'color' => 'wallbox', 'axis' => 'y1', 'data' => $dailyGute],
                ['key' => 'ideal', 'label' => 'Güte 1,0', 'color' => 'muted', 'axis' => 'y1', 'dash' => true, 'data' => array_fill(0, count($dailyKeys), 1)],
            ],
        ];
        if ($error) {
            $daily['error'] = $error;
            $compare['error'] = $error;
        }
        $table = [];
        foreach ($keys as $i => $day) {
            $table[] = [
                'day' => $day,
                'actual' => $actual[$i] ?? null,
                'model' => $model[$i] ?? null,
                'gute' => $gute[$i] ?? null,
                'raw' => $raw[$i] ?? null,
                'fitted' => $fitted[$i] ?? null,
                'regress' => $regress[$i] ?? null,
            ];
        }
        $board = Forecast::modelBoard(
            $todayKey,
            $rows,
            Forecast::captions($this->store->pdo(), $plant),
            Forecast::issueStats($this->store->pdo()),
            $actualToday,
            $plant,
            $lockedToday
        );
        return ['daily' => $daily, 'compare' => $compare, 'table' => $table, 'board' => $board, 'goodness' => $scores];
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
        $origin = $today->modify('-2 days')->getTimestamp();
        $span = max(1, (int) ceil(($last - $origin) / 86400));
        $viewStart = $today->getTimestamp();
        $viewEnd = $today->modify('+3 days')->getTimestamp();
        $boundStart = (new DateTimeImmutable('@' . min($origin, $rows[0]['t'] ?? $origin)))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
        $boundEnd = (new DateTimeImmutable('@' . max($last, $viewEnd)))->setTimezone($tz)->modify('+1 day')->setTime(0, 0)->getTimestamp();
        $payload = [
            'days' => $span,
            'today' => [$today->getTimestamp() * 1000, $today->modify('+1 day')->getTimestamp() * 1000],
            'view' => [$viewStart * 1000, $viewEnd * 1000],
            'bounds' => [min($boundStart, $viewStart) * 1000, max($boundEnd, $viewEnd) * 1000],
            'yTitle' => 'Strahlung (W/m²), Temperatur (°C)',
            'y1Title' => 'Sonnenschein (min), Bewölkung (%)',
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
        $this->repairKnownDays();
        $last = (string) $this->store->get('last_calibration', '');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $storedPlant = $this->store->get('plant', []);
        $paired = (int) (is_array($storedPlant) ? ($storedPlant['regress_days'] ?? 0) : 0);
        $locked = is_array($storedPlant) && !empty($storedPlant['factor_locked']);
        $factorNow = (float) (is_array($storedPlant) ? ($storedPlant['factor'] ?? 0.93) : 0.93);
        $needsReset = $paired < 5 && !$locked && abs($factorNow - 0.93) > 0.001;
        $version = '5';
        if (!$force && $last === $today && !$needsReset && (string) $this->store->get('calibration_version', '') === $version) {
            return;
        }
        if (!$this->ha->configured()) {
            return;
        }
        $cfg = $this->store->all();
        $map = $cfg['mapping'];
        $power = (string) ($map['pv_power'] ?? '');
        if ($power === '' && $this->energyEntity($map) === '') {
            return;
        }
        $this->rememberActuals($map);
        $n = max(3, (int) $plant['n_days']);
        $tz = new DateTimeZone('Europe/Berlin');
        $end = (new DateTimeImmutable('today', $tz))->getTimestamp();
        $start = $end - $n * 86400;
        $energy = $this->energyEntity($map);
        $byDay = [];
        if ($energy === '') {
            $actualRows = $this->statisticRows($power, $start, $end, 'hour');
            $powerUnit = $this->unitOf($power);
            foreach ($actualRows as $row) {
                if ($row['mean'] === null) {
                    continue;
                }
                $day = (new DateTimeImmutable('@' . $row['start']))->setTimezone($tz)->format('Y-m-d');
                $byDay[$day]['actual'] = ($byDay[$day]['actual'] ?? 0) + $this->meanKw($row['mean'], $powerUnit);
            }
        } else {
            foreach ($this->store->pdo()->query('SELECT day, actual_kwh FROM daily') ?: [] as $saved) {
                if ($saved['actual_kwh'] === null) {
                    continue;
                }
                $byDay[(string) $saved['day']]['actual'] = (float) $saved['actual_kwh'];
            }
        }
        $rawPlant = $plant;
        $rawPlant['factor'] = 1;
        foreach ((new WeatherFeed($this->store))->hours($start, $end) as $row) {
            if ($row['radiation'] === null) {
                continue;
            }
            $local = (new DateTimeImmutable('@' . $row['t']))->setTimezone($tz);
            $day = $local->format('Y-m-d');
            $kw = Forecast::powerKw(max(0, (float) $row['radiation']), (int) $local->format('G'), $rawPlant);
            $byDay[$day]['model'] = ($byDay[$day]['model'] ?? 0) + $kw;
            $t = (int) $row['t'];
            $byDay[$day]['earliest'] = isset($byDay[$day]['earliest']) ? min($byDay[$day]['earliest'], $t) : $t;
        }
        $held = [];
        foreach ($this->store->pdo()->query('SELECT day, model_mode FROM daily') ?: [] as $saved) {
            if ($saved['model_mode'] !== null && $saved['model_mode'] !== '') {
                $held[(string) $saved['day']] = (string) $saved['model_mode'];
            }
        }
        $stmt = $this->store->pdo()->prepare('INSERT INTO daily (day, actual_kwh, model_kwh) VALUES (?, ?, ?) ON CONFLICT(day) DO UPDATE SET actual_kwh = excluded.actual_kwh, model_kwh = excluded.model_kwh');
        $stmtModel = $this->store->pdo()->prepare('INSERT INTO daily (day, model_kwh) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET model_kwh = excluded.model_kwh');
        foreach ($byDay as $day => $row) {
            if ($day === $today || isset($held[$day])) {
                continue;
            }
            $model = $row['model'] ?? null;
            $startOfDay = (new DateTimeImmutable($day . ' 00:00:00', $tz))->getTimestamp();
            $covered = isset($row['earliest']) && $row['earliest'] <= $startOfDay + 5400 && $model !== null && $model > 0.05;
            if (!$covered) {
                continue;
            }
            if ($energy !== '') {
                $stmtModel->execute([$day, $model]);
            } else {
                $stmt->execute([$day, $row['actual'] ?? null, $model]);
            }
        }
        $xs = [];
        $ys = [];
        $ratios = [];
        foreach ($this->store->pdo()->query('SELECT day, actual_kwh, model_kwh, model_mode FROM daily') ?: [] as $saved) {
            if ((string) $saved['day'] === $today) {
                continue;
            }
            $mode = (string) ($saved['model_mode'] ?? '');
            if ($mode === 'drop' || $mode === 'pin') {
                continue;
            }
            $actual = $saved['actual_kwh'];
            $model = $saved['model_kwh'];
            if ($actual !== null && $model !== null && (float) $model > 1) {
                $xs[] = (float) $model;
                $ys[] = (float) $actual;
                $ratios[] = (float) $actual / (float) $model;
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
        $this->store->put('calibration_version', '5');
    }

    /** Der 7.10.2026 fehlt in der DWD-Datei ab Mitternacht. Home Assistant hat den Tageswert um 00:01 Uhr festgehalten. */
    private function repairKnownDays(): void
    {
        if ((string) $this->store->get('model_repair', '') === '2026-10') {
            return;
        }
        $pdo = $this->store->pdo();
        $names = [];
        foreach ($pdo->query('PRAGMA table_info(daily)') ?: [] as $column) {
            $names[] = (string) $column['name'];
        }
        if (!in_array('model_mode', $names, true)) {
            $pdo->exec('ALTER TABLE daily ADD COLUMN model_mode TEXT');
        }
        $drop = $pdo->prepare("INSERT INTO daily (day, model_kwh, model_mode) VALUES (?, NULL, 'drop') ON CONFLICT(day) DO UPDATE SET model_kwh = NULL, model_mode = 'drop'");
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'] as $day) {
            $drop->execute([$day]);
        }
        $current = $pdo->prepare('SELECT actual_kwh FROM daily WHERE day = ?');
        $current->execute(['2026-10-07']);
        $actual = $current->fetchColumn();
        if ($actual === false || $actual === null) {
            $actual = 25.7;
        }
        $pdo->prepare("INSERT INTO daily (day, actual_kwh, model_kwh, model_mode) VALUES (?, ?, 28.0, 'pin') ON CONFLICT(day) DO UPDATE SET actual_kwh = COALESCE(daily.actual_kwh, excluded.actual_kwh), model_kwh = 28.0, model_mode = 'pin'")->execute(['2026-10-07', $actual]);
        $this->store->put('model_repair', '2026-10');
    }

    /** @param array<int, string> $ordered
     *  @param array<int, string> $wanted
     *  @return array{0:int, 1:int}
     */
    private function indexSpan(array $ordered, array $wanted): array
    {
        $lookup = array_flip($wanted);
        $idx = [];
        foreach ($ordered as $i => $day) {
            if (isset($lookup[$day])) {
                $idx[] = $i;
            }
        }
        if (!$idx) {
            return [0, 0];
        }
        return [min($idx), max($idx)];
    }

    /** @param array<string, array{actual:float, model:float}> $by
     *  @param array<int, string> $days
     *  @return array{ratio:?float, days:int, text:string}
     */
    private function scoreDays(array $by, array $days): array
    {
        $actual = 0.0;
        $model = 0.0;
        $count = 0;
        foreach ($days as $day) {
            if (!isset($by[$day])) {
                continue;
            }
            $actual += $by[$day]['actual'];
            $model += $by[$day]['model'];
            $count++;
        }
        $ratio = $actual > 0 ? $model / $actual : null;
        return [
            'ratio' => $ratio,
            'days' => $count,
            'text' => $ratio === null ? '—' : num($ratio, 2),
        ];
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

    private function rememberActuals(array $mapping): void
    {
        $energy = $this->energyEntity($mapping);
        if ($energy !== '') {
            $this->rememberEnergy($energy);
            return;
        }
        $power = (string) ($mapping['pv_power'] ?? '');
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

    private function rememberEnergy(string $energy): void
    {
        $rows = $this->statisticRows($energy, time() - 420 * 86400, time() + 3600, 'hour');
        $unit = $this->unitOf($energy);
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $byDay = [];
        foreach ($rows as $row) {
            if ($row['change'] === null) {
                continue;
            }
            $day = (new DateTimeImmutable('@' . $row['start']))->setTimezone($tz)->format('Y-m-d');
            if ($day === $today) {
                continue;
            }
            $byDay[$day] = ($byDay[$day] ?? 0) + $this->energyKwh(max(0, (float) $row['change']), $unit);
        }
        $stmt = $this->store->pdo()->prepare('INSERT INTO daily (day, actual_kwh) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET actual_kwh = excluded.actual_kwh');
        foreach ($byDay as $day => $kwh) {
            if ($kwh > 0) {
                $stmt->execute([$day, round($kwh, 3)]);
            }
        }
    }

    private function energyEntity(array $mapping): string
    {
        $set = trim((string) ($mapping['pv_energy'] ?? ''));
        if ($set !== '') {
            return $set;
        }
        $candidate = 'sensor.daily_pv_generation_battery_discharge';
        if (!$this->ha->configured()) {
            return '';
        }
        try {
            $row = $this->ha->index()[$candidate] ?? null;
        } catch (Throwable) {
            return '';
        }
        if (!is_array($row)) {
            return '';
        }
        $attrs = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
        $class = strtolower((string) ($attrs['device_class'] ?? ''));
        $unit = strtolower(str_replace([' ', '·'], '', (string) ($attrs['unit_of_measurement'] ?? '')));
        if ($class !== 'energy' && !in_array($unit, ['kwh', 'wh', 'mwh'], true)) {
            return '';
        }
        $this->store->merge('mapping', ['pv_energy' => $candidate]);
        return $candidate;
    }

    private function energyKwh(float $amount, string $unit): float
    {
        return match ($unit) {
            'wh' => $amount / 1000,
            'mwh' => $amount * 1000,
            '' => $amount > 200 ? $amount / 1000 : $amount,
            default => $amount,
        };
    }

    private static function dayLabel(string $day): string
    {
        return day_label($day);
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
