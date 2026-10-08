<?php
declare(strict_types=1);

final class Forecast
{
    public static function geometry(float $tilt, float $azimuth): float
    {
        $southness = cos(deg2rad($azimuth - 180));
        $gain = $southness * sin(deg2rad($tilt)) * (0.15 / sin(deg2rad(30)));
        return max(0.75, 1 + $gain);
    }

    /** @return array<int, float> hour => weight, average 1 */
    public static function shape(float $azimuth): array
    {
        $peak = 13 + (($azimuth - 180) / 90) * 3;
        $weights = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $delta = abs($hour + 0.5 - $peak);
            $weights[$hour] = exp(-($delta * $delta) / 40);
        }
        $avg = array_sum($weights) / 24;
        foreach ($weights as $hour => $weight) {
            $weights[$hour] = $avg > 0 ? $weight / $avg : 1;
        }
        return $weights;
    }

    public static function chain(float $kwp, float $factor): float
    {
        return ($kwp * 1.04 * 0.90 * 0.975 / 1000) * $factor;
    }

    public static function powerKw(float $radiation, int $hour, array $plant): float
    {
        unset($hour);
        $kw = $radiation * self::chain((float) $plant['kwp'], (float) $plant['factor']);
        return min((float) $plant['inverter_kw'], max(0, $kw));
    }

    public static function regression(array $xs, array $ys): array
    {
        $n = count($xs);
        if ($n < 2 || $n !== count($ys)) {
            return ['a' => null, 'b' => null];
        }
        $sx = $sy = $sxx = $sxy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sx += $xs[$i];
            $sy += $ys[$i];
            $sxx += $xs[$i] * $xs[$i];
            $sxy += $xs[$i] * $ys[$i];
        }
        $den = $n * $sxx - $sx * $sx;
        if (abs($den) < 1e-9) {
            return ['a' => null, 'b' => null];
        }
        $b = ($n * $sxy - $sx * $sy) / $den;
        $a = ($sy - $b * $sx) / $n;
        return ['a' => $a, 'b' => $b];
    }

    public static function dailyAdjusted(float $modelKwh, array $plant): float
    {
        $days = (int) ($plant['regress_days'] ?? 0);
        if ($days >= 5) {
            return max(0, (float) $plant['regress_a'] + (float) $plant['regress_b'] * $modelKwh);
        }
        return max(0, $modelKwh);
    }

    public static function methodLabel(array $plant): string
    {
        return ((int) ($plant['regress_days'] ?? 0) >= 5) ? 'Regression' : 'Eichfaktor';
    }

    /** Modellwert, den die Tagesansicht zeigt: Regression ab fünf Tagen, sonst Rohmodell mal Eichfaktor. */
    public static function predicted(float $rawModel, array $plant): float
    {
        if ((int) ($plant['regress_days'] ?? 0) >= 5) {
            return self::dailyAdjusted($rawModel, $plant);
        }
        return max(0, $rawModel * (float) ($plant['factor'] ?? 1));
    }

    /** pin zeigt den gespeicherten kWh-Wert selbst. drop bleibt leer. Sonst predicted(). */
    public static function displayedModel(?string $mode, ?float $model, array $plant): ?float
    {
        if ($mode === 'drop' || $model === null) {
            return null;
        }
        if ($mode === 'pin') {
            return max(0, $model);
        }
        return self::predicted($model, $plant);
    }

    public static function captionText(?float $kwh, ?float $sd): ?string
    {
        if ($kwh === null) {
            return null;
        }
        if ($sd === null) {
            return kwh($kwh, 1);
        }
        return num($kwh, 1) . ' ± ' . num($sd, 1) . ' kWh';
    }

    /** Feste 0,5-kWh-Schritte. Zwei Schritte bleiben über dem höchsten Punkt frei, dort steht die Tageszahl. */
    public static function energyScale(float $peak, float $step = 0.5): array
    {
        $step = $step > 0 ? $step : 0.5;
        if ($peak <= 0) {
            return ['step' => $step, 'dataMax' => $step, 'max' => round($step * 3, 3)];
        }
        $dataMax = round(ceil(($peak - 1e-9) / $step) * $step, 3);
        return ['step' => $step, 'dataMax' => $dataMax, 'max' => round($dataMax + $step * 2, 3)];
    }

    /**
     * Modelle-Tabelle: kommende Tage, der laufende Tag, dann vergangene Modelltage.
     *
     * @param array<int, array<string, mixed>> $daily
     * @param array<string, array{kwh:float, sd:?float, pinned?:bool}> $captions
     * @param array<string, array{mean?:float}> $stats
     * @return array<int, array{day:string, today:bool, actual:?float, model:?float, gute:?float, raw:?float, fitted:?float, regress:?float}>
     */
    public static function modelBoard(
        string $today,
        array $daily,
        array $captions,
        array $stats,
        ?float $actualToday,
        array $plant,
        ?float $lockedToday = null,
        int $ahead = 5,
        int $back = 5
    ): array {
        $tz = new DateTimeZone('Europe/Berlin');
        $origin = new DateTimeImmutable($today, $tz);
        $future = [];
        for ($i = max(1, $ahead); $i >= 1; $i--) {
            $day = $origin->modify('+' . $i . ' days')->format('Y-m-d');
            if (!isset($captions[$day]) && !isset($stats[$day])) {
                continue;
            }
            $future[] = self::forecastBoardRow($day, $captions, $stats, $plant, null, false);
        }
        $todayActual = $actualToday;
        if ($todayActual === null) {
            foreach ($daily as $row) {
                if ((string) ($row['day'] ?? '') === $today && $row['actual_kwh'] !== null && $row['actual_kwh'] !== '') {
                    $todayActual = (float) $row['actual_kwh'];
                    break;
                }
            }
        }
        $todayRow = self::forecastBoardRow($today, $captions, $stats, $plant, $todayActual, true);
        if ($todayRow['model'] === null && $lockedToday !== null) {
            $todayRow['model'] = round($lockedToday, 2);
        }
        $past = [];
        foreach ($daily as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day === '' || $day >= $today) {
                continue;
            }
            $mode = isset($row['model_mode']) && $row['model_mode'] !== null && $row['model_mode'] !== '' ? (string) $row['model_mode'] : null;
            if ($mode === 'drop') {
                continue;
            }
            $stored = $row['model_kwh'] !== null && $row['model_kwh'] !== '' ? (float) $row['model_kwh'] : null;
            $shown = self::displayedModel($mode, $stored, $plant);
            if ($shown === null) {
                continue;
            }
            $actual = $row['actual_kwh'] !== null && $row['actual_kwh'] !== '' ? round((float) $row['actual_kwh'], 2) : null;
            [$raw, $fitted, $regress] = ($mode === 'pin') ? [null, null, null] : self::factorParts($stored, $plant);
            $past[] = [
                'day' => $day,
                'today' => false,
                'actual' => $actual,
                'model' => round($shown, 2),
                'gute' => ($actual !== null && $actual > 0) ? round($shown / $actual, 3) : null,
                'raw' => $raw,
                'fitted' => $fitted,
                'regress' => $regress,
            ];
        }
        usort($past, static fn (array $a, array $b): int => strcmp($b['day'], $a['day']));
        if ($back > 0) {
            $past = array_slice($past, 0, $back);
        }
        return array_merge($future, [$todayRow], $past);
    }

    /** @param array<string, array{kwh:float, sd:?float, pinned?:bool}> $captions
     *  @param array<string, array{mean?:float}> $stats
     *  @return array{day:string, today:bool, actual:?float, model:?float, gute:?float, raw:?float, fitted:?float, regress:?float}
     */
    private static function forecastBoardRow(string $day, array $captions, array $stats, array $plant, ?float $actual, bool $today): array
    {
        $caption = $captions[$day] ?? null;
        $pinned = is_array($caption) && !empty($caption['pinned']);
        $mean = isset($stats[$day]['mean']) ? (float) $stats[$day]['mean'] : null;
        [$raw, $fitted, $regress] = ($pinned || $mean === null) ? [null, null, null] : self::factorParts($mean, $plant);
        $model = null;
        if (is_array($caption) && isset($caption['kwh'])) {
            $model = round((float) $caption['kwh'], 2);
        } elseif ($mean !== null) {
            $model = round(self::predicted($mean, $plant), 2);
        }
        return [
            'day' => $day,
            'today' => $today,
            'actual' => $actual !== null ? round($actual, 2) : null,
            'model' => $model,
            'gute' => (!$today && $actual !== null && $actual > 0 && $model !== null) ? round($model / $actual, 3) : null,
            'raw' => $raw,
            'fitted' => $fitted,
            'regress' => $regress,
        ];
    }

    /** @return array{0:?float, 1:?float, 2:?float} */
    private static function factorParts(?float $raw, array $plant): array
    {
        if ($raw === null) {
            return [null, null, null];
        }
        $factor = (float) ($plant['factor'] ?? 1);
        $regress = max(0, (float) ($plant['regress_a'] ?? 0) + (float) ($plant['regress_b'] ?? 0) * $raw);
        return [round($raw, 2), round($raw * $factor, 2), round($regress, 2)];
    }

    /** @param array<int, string> $days
     *  @return array<int, string>
     */
    public static function windowDays(array $days, string $today, string $window): array
    {
        $end = new DateTimeImmutable($today, new DateTimeZone('Europe/Berlin'));
        if ($window === '3' || $window === '7') {
            $n = $window === '3' ? 3 : 7;
            $from = $end->modify('-' . $n . ' days')->format('Y-m-d');
            $until = $end->modify('-1 day')->format('Y-m-d');
            return array_values(array_filter($days, static fn (string $day): bool => $day >= $from && $day <= $until));
        }
        if ($window === 'month') {
            $prefix = $end->format('Y-m');
            return array_values(array_filter($days, static fn (string $day): bool => str_starts_with($day, $prefix) && $day < $today));
        }
        $month = (int) $end->format('n');
        $startMonth = intdiv($month - 1, 3) * 3 + 1;
        $from = sprintf('%04d-%02d-01', (int) $end->format('Y'), $startMonth);
        return array_values(array_filter($days, static fn (string $day): bool => $day >= $from && $day < $today));
    }

    /** @param array<int, array{t:int,v:float}> $points kW */
    public static function integrate(array $points): float
    {
        $energy = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $dt = $points[$i]['t'] - $points[$i - 1]['t'];
            if ($dt <= 0 || $dt > 1800) {
                continue;
            }
            $energy += max(0, $points[$i - 1]['v']) * ($dt / 3600);
        }
        return $energy;
    }

    /** @param array<int, array{t:int, radiation:?float}> $rows */
    public static function fromRadiation(array $rows, array $plant): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if (!isset($row['t']) || $row['radiation'] === null) {
                continue;
            }
            $mapped[] = ['datetime' => gmdate('c', (int) $row['t']), 'value' => (float) $row['radiation']];
        }
        return self::fromAttribute($mapped, $plant);
    }

    /** @param array<int, array{datetime:string,value:float|int}> $rows */
    public static function fromAttribute(array $rows, array $plant): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['datetime'])) {
                continue;
            }
            $t = strtotime((string) $row['datetime']);
            if ($t === false) {
                continue;
            }
            $hour = (int) (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('G');
            $g = (float) ($row['value'] ?? 0);
            $out[] = ['t' => $t, 'g' => $g, 'kw' => self::powerKw($g, $hour, $plant)];
        }
        usort($out, fn ($a, $b) => $a['t'] <=> $b['t']);
        return $out;
    }

    public static function sumBetween(array $series, int $start, int $end): float
    {
        $sum = 0.0;
        foreach ($series as $point) {
            if ($point['t'] >= $start && $point['t'] < $end) {
                $sum += $point['kw'];
            }
        }
        return $sum;
    }

    public static function brief(array $series, array $plant, int $now, ?float $actualToday = null, ?float $lockedToday = null): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $after = $today->modify('+2 days');
        $modelToday = self::sumBetween($series, $today->getTimestamp(), $tomorrow->getTimestamp());
        $modelTomorrow = self::sumBetween($series, $tomorrow->getTimestamp(), $after->getTimestamp());
        $futureModel = self::sumBetween($series, $now, $tomorrow->getTimestamp());
        $earliest = null;
        foreach ($series as $point) {
            if ($point['t'] >= $today->getTimestamp() && $point['t'] < $tomorrow->getTimestamp()) {
                $earliest = $earliest === null ? $point['t'] : min($earliest, $point['t']);
            }
        }
        $coversDay = $earliest !== null && $earliest <= $today->getTimestamp() + 5400;
        if ($coversDay && $modelToday > 0.05) {
            $adjustedToday = self::dailyAdjusted($modelToday, $plant);
            $remaining = $adjustedToday * ($futureModel / $modelToday);
            $total = $adjustedToday;
        } else {
            // Die Stundenleistung enthält den Eichfaktor schon. Ohne die Morgenstunden bleibt die Tagessumme der zuletzt vollständige Wert.
            $remaining = max(0, $futureModel);
            if ((int) ($plant['regress_days'] ?? 0) >= 5) {
                $remaining = max(0, $futureModel * (float) $plant['regress_b']);
            }
            $total = $lockedToday;
        }
        return [
            'model_today_kwh' => $modelToday,
            'today_kwh' => $total,
            'remaining_kwh' => $remaining,
            'tomorrow_kwh' => self::dailyAdjusted($modelTomorrow, $plant),
            'model_tomorrow_kwh' => $modelTomorrow,
            'method' => self::methodLabel($plant),
        ];
    }

    /** @return array<int, array{day:string, start:int, kwh:float}> */
    public static function dailyTotals(array $series, array $plant): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $byDay = [];
        foreach ($series as $point) {
            $local = (new DateTimeImmutable('@' . $point['t']))->setTimezone($tz);
            $day = $local->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $point['kw'];
        }
        $out = [];
        foreach ($byDay as $day => $model) {
            $start = (new DateTimeImmutable($day . ' 00:00:00', $tz))->getTimestamp();
            $out[] = ['day' => $day, 'start' => $start, 'kwh' => self::dailyAdjusted($model, $plant)];
        }
        return $out;
    }

    public static function ensureDays(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS forecast_days (day TEXT PRIMARY KEY, kwh REAL NOT NULL)');
    }

    /** @param array<int, array{t:int, kw:float}> $series */
    public static function rememberDays(PDO $pdo, array $series, array $plant): void
    {
        self::ensureDays($pdo);
        $stmt = $pdo->prepare('INSERT INTO forecast_days (day, kwh) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET kwh = excluded.kwh');
        foreach (self::slices($series) as $day => $slice) {
            if (!$slice['covered'] || $slice['kwh'] <= 0.05) {
                continue;
            }
            $stmt->execute([$day, round(self::dailyAdjusted($slice['kwh'], $plant), 3)]);
        }
    }

    /** @return array<int, array{day:string, kwh:float}> */
    public static function lockedFrom(PDO $pdo, string $day): array
    {
        self::ensureDays($pdo);
        $stmt = $pdo->prepare('SELECT day, kwh FROM forecast_days WHERE day >= ? ORDER BY day ASC');
        $stmt->execute([$day]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[] = ['day' => (string) $row['day'], 'kwh' => (float) $row['kwh']];
        }
        return $out;
    }

    public static function locked(PDO $pdo, string $day): ?float
    {
        self::ensureDays($pdo);
        $stmt = $pdo->prepare('SELECT kwh FROM forecast_days WHERE day = ?');
        $stmt->execute([$day]);
        $row = $stmt->fetch();
        return $row ? (float) $row['kwh'] : null;
    }

    /**
     * @param array<int, array{t:int, kw:float}> $series
     * @return array<string, array{start:int, kwh:float, earliest:int, peak_t:int, peak_kw:float, covered:bool}>
     */
    public static function slices(array $series): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $by = [];
        foreach ($series as $point) {
            if (!isset($point['t'])) {
                continue;
            }
            $local = (new DateTimeImmutable('@' . (int) $point['t']))->setTimezone($tz);
            $day = $local->format('Y-m-d');
            $kw = max(0, (float) ($point['kw'] ?? 0));
            if (!isset($by[$day])) {
                $by[$day] = [
                    'start' => $local->setTime(0, 0)->getTimestamp(),
                    'kwh' => 0.0,
                    'earliest' => (int) $point['t'],
                    'peak_t' => (int) $point['t'],
                    'peak_kw' => $kw,
                ];
            }
            $by[$day]['kwh'] += $kw;
            $by[$day]['earliest'] = min($by[$day]['earliest'], (int) $point['t']);
            if ($kw >= $by[$day]['peak_kw']) {
                $by[$day]['peak_kw'] = $kw;
                $by[$day]['peak_t'] = (int) $point['t'];
            }
        }
        foreach ($by as &$slice) {
            $slice['covered'] = $slice['earliest'] <= $slice['start'] + 5400;
        }
        unset($slice);
        return $by;
    }

    public static function ensureIssues(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS forecast_issues (
                issue INTEGER NOT NULL,
                day TEXT NOT NULL,
                kwh REAL NOT NULL,
                radiation REAL,
                sunshine_s REAL,
                cloud REAL,
                temp_c REAL,
                hours INTEGER NOT NULL,
                PRIMARY KEY (issue, day)
            )'
        );
    }

    /** @param array<int, array{t:int, radiation?:?float, cloud?:?float, sunshine_s?:?float, temp_c?:?float}> $hours */
    public static function rememberIssue(PDO $pdo, array $hours, array $plant, int $issue): void
    {
        if ($issue <= 0 || !$hours) {
            return;
        }
        self::ensureIssues($pdo);
        $raw = $plant;
        $raw['factor'] = 1;
        $tz = new DateTimeZone('Europe/Berlin');
        $by = [];
        foreach ($hours as $hour) {
            if (!isset($hour['t'])) {
                continue;
            }
            $t = (int) $hour['t'];
            $local = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
            $day = $local->format('Y-m-d');
            if (!isset($by[$day])) {
                $by[$day] = [
                    'start' => $local->setTime(0, 0)->getTimestamp(),
                    'earliest' => $t,
                    'kwh' => 0.0,
                    'radiation' => 0.0,
                    'rad_n' => 0,
                    'sunshine' => 0.0,
                    'sun_n' => 0,
                    'cloud' => 0.0,
                    'cloud_n' => 0,
                    'temp' => 0.0,
                    'temp_n' => 0,
                    'hours' => 0,
                ];
            }
            $by[$day]['earliest'] = min($by[$day]['earliest'], $t);
            $by[$day]['hours']++;
            if (array_key_exists('radiation', $hour) && $hour['radiation'] !== null) {
                $g = max(0, (float) $hour['radiation']);
                $by[$day]['radiation'] += $g;
                $by[$day]['rad_n']++;
                $by[$day]['kwh'] += self::powerKw($g, (int) $local->format('G'), $raw);
            }
            if (array_key_exists('sunshine_s', $hour) && $hour['sunshine_s'] !== null) {
                $by[$day]['sunshine'] += (float) $hour['sunshine_s'];
                $by[$day]['sun_n']++;
            }
            if (array_key_exists('cloud', $hour) && $hour['cloud'] !== null) {
                $by[$day]['cloud'] += (float) $hour['cloud'];
                $by[$day]['cloud_n']++;
            }
            if (array_key_exists('temp_c', $hour) && $hour['temp_c'] !== null) {
                $by[$day]['temp'] += (float) $hour['temp_c'];
                $by[$day]['temp_n']++;
            }
        }
        $stmt = $pdo->prepare('INSERT INTO forecast_issues (issue, day, kwh, radiation, sunshine_s, cloud, temp_c, hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(issue, day) DO UPDATE SET kwh = excluded.kwh, radiation = excluded.radiation, sunshine_s = excluded.sunshine_s, cloud = excluded.cloud, temp_c = excluded.temp_c, hours = excluded.hours');
        foreach ($by as $day => $slice) {
            if ($slice['earliest'] > $slice['start'] + 5400 || $slice['kwh'] <= 0.05) {
                continue;
            }
            $stmt->execute([
                $issue,
                $day,
                round($slice['kwh'], 3),
                $slice['rad_n'] ? round($slice['radiation'], 1) : null,
                $slice['sun_n'] ? round($slice['sunshine'], 0) : null,
                $slice['cloud_n'] ? round($slice['cloud'] / $slice['cloud_n'], 1) : null,
                $slice['temp_n'] ? round($slice['temp'] / $slice['temp_n'], 2) : null,
                $slice['hours'],
            ]);
        }
    }

    /** @return array<string, array{n:int, mean:float, sd:?float, radiation:?float, sunshine_s:?float, cloud:?float, temp_c:?float}> */
    public static function issueStats(PDO $pdo): array
    {
        self::ensureIssues($pdo);
        $grouped = [];
        foreach ($pdo->query('SELECT day, kwh, radiation, sunshine_s, cloud, temp_c FROM forecast_issues') ?: [] as $row) {
            $grouped[(string) $row['day']][] = $row;
        }
        $out = [];
        foreach ($grouped as $day => $items) {
            $values = array_map(static fn (array $row): float => (float) $row['kwh'], $items);
            $n = count($values);
            $mean = array_sum($values) / $n;
            $sd = null;
            if ($n >= 2) {
                $acc = 0.0;
                foreach ($values as $value) {
                    $acc += ($value - $mean) ** 2;
                }
                $sd = sqrt($acc / ($n - 1));
            }
            $out[$day] = [
                'n' => $n,
                'mean' => $mean,
                'sd' => $sd,
                'radiation' => self::avgField($items, 'radiation'),
                'sunshine_s' => self::avgField($items, 'sunshine_s'),
                'cloud' => self::avgField($items, 'cloud'),
                'temp_c' => self::avgField($items, 'temp_c'),
            ];
        }
        return $out;
    }

    /** @return array<string, array{kwh:float, sd:?float, pinned:bool}> */
    public static function captions(PDO $pdo, array $plant): array
    {
        $stats = self::issueStats($pdo);
        $regress = (int) ($plant['regress_days'] ?? 0) >= 5;
        $scale = $regress ? abs((float) ($plant['regress_b'] ?? 1)) : (float) ($plant['factor'] ?? 1);
        $out = [];
        foreach ($stats as $day => $stat) {
            $out[$day] = [
                'kwh' => self::predicted((float) $stat['mean'], $plant),
                'sd' => $stat['sd'] !== null ? (float) $stat['sd'] * $scale : null,
                'pinned' => false,
            ];
        }
        foreach (self::pinnedModels($pdo) as $day => $kwh) {
            $out[$day] = ['kwh' => $kwh, 'sd' => null, 'pinned' => true];
        }
        return $out;
    }

    /** @param array<string, ?float> $actual
     *  @return array<int, array{day:string, actual:?float, mean:?float, sd:?float, n:int, radiation:?float, sunshine_s:?float, cloud:?float, temp_c:?float}>
     */
    public static function archiveRows(PDO $pdo, array $plant, array $actual): array
    {
        $stats = self::issueStats($pdo);
        $captions = self::captions($pdo, $plant);
        $days = array_values(array_unique(array_merge(array_keys($stats), array_keys($captions), array_keys($actual))));
        sort($days);
        $rows = [];
        foreach ($days as $day) {
            $stat = $stats[$day] ?? null;
            $caption = $captions[$day] ?? null;
            $rows[] = [
                'day' => $day,
                'actual' => $actual[$day] ?? null,
                'mean' => $caption['kwh'] ?? null,
                'sd' => $caption['sd'] ?? null,
                'n' => !empty($caption['pinned']) ? 1 : ($stat['n'] ?? 0),
                'radiation' => $stat['radiation'] ?? null,
                'sunshine_s' => $stat['sunshine_s'] ?? null,
                'cloud' => $stat['cloud'] ?? null,
                'temp_c' => $stat['temp_c'] ?? null,
            ];
        }
        return $rows;
    }

    /** @return array<string, float> */
    private static function pinnedModels(PDO $pdo): array
    {
        $names = [];
        foreach ($pdo->query('PRAGMA table_info(daily)') ?: [] as $column) {
            $names[] = (string) $column['name'];
        }
        if (!in_array('model_mode', $names, true)) {
            return [];
        }
        $out = [];
        foreach ($pdo->query("SELECT day, model_kwh FROM daily WHERE model_mode = 'pin' AND model_kwh IS NOT NULL") ?: [] as $row) {
            $out[(string) $row['day']] = (float) $row['model_kwh'];
        }
        return $out;
    }

    /**
     * @param array<int, array{t:int, kw:float}> $series
     * @return array{full_at:?int, priority_at:?int, priority_open:bool, priority_reached:bool, already_full:bool, surplus_kwh:?float, reachable:bool}
     */
    public static function storageOutlook(array $series, int $now, ?float $soc, ?float $storedKwh, ?float $houseKw, float $prioritySoc): array
    {
        $empty = [
            'full_at' => null,
            'priority_at' => null,
            'priority_open' => $prioritySoc < 99.5,
            'priority_reached' => false,
            'already_full' => false,
            'surplus_kwh' => null,
            'reachable' => false,
        ];
        $soc = $soc === null ? null : max(0, min(100, $soc));
        $fullKwh = null;
        if ($soc !== null && $soc >= 99.5) {
            $empty['already_full'] = true;
            $empty['priority_reached'] = true;
        } elseif ($soc !== null && $soc > 1 && $storedKwh !== null && $storedKwh > 0) {
            $fullKwh = $storedKwh / ($soc / 100);
        }
        $needFull = $fullKwh === null ? null : max(0, $fullKwh - (float) $storedKwh);
        $needPriority = null;
        if ($fullKwh !== null && $prioritySoc < 99.5) {
            $needPriority = max(0, $fullKwh * ($prioritySoc / 100) - (float) $storedKwh);
            $empty['priority_reached'] = $needPriority <= 0.05;
        }
        if ($needFull !== null && $needFull <= 0.05) {
            $empty['already_full'] = true;
            $empty['priority_reached'] = true;
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $todayEnd = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0)->modify('+1 day')->getTimestamp();
        $house = max(0, (float) ($houseKw ?? 0));
        $acc = 0.0;
        $surplus = 0.0;
        $seen = false;
        $fullAt = null;
        $priorityAt = null;
        foreach ($series as $point) {
            if (!isset($point['t'])) {
                continue;
            }
            $t = (int) $point['t'];
            $end = $t + 3600;
            if ($end <= $now || $t > $now + 4 * 86400) {
                continue;
            }
            $from = max($t, $now);
            $frac = ($end - $from) / 3600;
            $gain = max(0, (float) ($point['kw'] ?? 0) * $frac - $house * $frac);
            $seen = true;
            if ($from < $todayEnd) {
                $todayFrac = (min($end, $todayEnd) - $from) / 3600;
                $surplus += max(0, (float) ($point['kw'] ?? 0) * $todayFrac - $house * $todayFrac);
            }
            $before = $acc;
            $acc += $gain;
            if ($needPriority !== null && $needPriority > 0.05 && $priorityAt === null && $acc >= $needPriority) {
                $priorityAt = self::crossAt($from, $end, $before, $acc, $needPriority);
            }
            if ($needFull !== null && $needFull > 0.05 && $fullAt === null && $acc >= $needFull) {
                $fullAt = self::crossAt($from, $end, $before, $acc, $needFull);
            }
        }
        $empty['full_at'] = $empty['already_full'] ? $now : $fullAt;
        $empty['priority_at'] = $empty['priority_reached'] ? $now : $priorityAt;
        $empty['surplus_kwh'] = $seen ? $surplus : null;
        $empty['reachable'] = $fullKwh !== null;
        return $empty;
    }

    private static function crossAt(int $from, int $to, float $before, float $after, float $need): int
    {
        $gain = $after - $before;
        if ($gain <= 0.000001) {
            return $to;
        }
        $frac = max(0, min(1, ($need - $before) / $gain));
        return $from + (int) round(($to - $from) * $frac);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function avgField(array $items, string $field): ?float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($items as $item) {
            if (!isset($item[$field]) || $item[$field] === null) {
                continue;
            }
            $sum += (float) $item[$field];
            $n++;
        }
        return $n ? $sum / $n : null;
    }
}
