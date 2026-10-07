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
        $shape = self::shape((float) $plant['azimuth'])[$hour] ?? 1;
        $kw = $radiation * self::chain((float) $plant['kwp'], (float) $plant['factor']) * self::geometry((float) $plant['tilt'], (float) $plant['azimuth']) * $shape;
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

    public static function brief(array $series, array $plant, int $now): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $after = $today->modify('+2 days');
        $modelToday = self::sumBetween($series, $today->getTimestamp(), $tomorrow->getTimestamp());
        $modelTomorrow = self::sumBetween($series, $tomorrow->getTimestamp(), $after->getTimestamp());
        $futureModel = self::sumBetween($series, $now, $tomorrow->getTimestamp());
        $adjustedToday = self::dailyAdjusted($modelToday, $plant);
        $remaining = $modelToday > 0.05 ? $adjustedToday * ($futureModel / $modelToday) : 0.0;
        return [
            'model_today_kwh' => $modelToday,
            'today_kwh' => $adjustedToday,
            'remaining_kwh' => $remaining,
            'tomorrow_kwh' => self::dailyAdjusted($modelTomorrow, $plant),
            'model_tomorrow_kwh' => $modelTomorrow,
            'method' => self::methodLabel($plant),
        ];
    }
}
