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
}
