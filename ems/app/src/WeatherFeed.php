<?php
declare(strict_types=1);

final class WeatherFeed
{
    public const DEFAULT_URL = 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/F9519/kml/MOSMIX_L_LATEST_F9519.kmz';

    public function __construct(private ConfigStore $store)
    {
        $this->store->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS weather_hours (
                t INTEGER PRIMARY KEY,
                radiation REAL,
                cloud REAL,
                sunshine_s REAL,
                temp_c REAL
            )'
        );
    }

    public function url(): string
    {
        $weather = $this->store->get('weather', []);
        $url = is_array($weather) ? (string) ($weather['url'] ?? '') : '';
        return $url !== '' ? $url : self::DEFAULT_URL;
    }

    public function meta(): array
    {
        $meta = $this->store->get('weather_meta', []);
        return is_array($meta) ? $meta : [];
    }

    public function stale(): bool
    {
        $meta = $this->meta();
        $age = time() - (int) ($meta['fetched_at'] ?? 0);
        return $age > 1800 || empty($meta['ok']);
    }

    /** @return array<int, array{t:int, radiation:?float, cloud:?float, sunshine_s:?float, temp_c:?float}> */
    public function hours(int $start, int $end): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT t, radiation, cloud, sunshine_s, temp_c FROM weather_hours WHERE t >= ? AND t < ? ORDER BY t ASC');
        $stmt->execute([$start, $end]);
        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[] = [
                't' => (int) $row['t'],
                'radiation' => $row['radiation'] !== null ? (float) $row['radiation'] : null,
                'cloud' => $row['cloud'] !== null ? (float) $row['cloud'] : null,
                'sunshine_s' => $row['sunshine_s'] !== null ? (float) $row['sunshine_s'] : null,
                'temp_c' => $row['temp_c'] !== null ? (float) $row['temp_c'] : null,
            ];
        }
        return $rows;
    }

    public function refresh(bool $force = false): array
    {
        if (!$force && !$this->stale()) {
            return $this->meta();
        }
        try {
            $bytes = $this->download($this->url());
            $parsed = self::parse(self::kmlFromKmz($bytes));
            $pdo = $this->store->pdo();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO weather_hours (t, radiation, cloud, sunshine_s, temp_c) VALUES (?, ?, ?, ?, ?) ON CONFLICT(t) DO UPDATE SET radiation = excluded.radiation, cloud = excluded.cloud, sunshine_s = excluded.sunshine_s, temp_c = excluded.temp_c');
            foreach ($parsed['hours'] as $hour) {
                $stmt->execute([$hour['t'], $hour['radiation'], $hour['cloud'], $hour['sunshine_s'], $hour['temp_c']]);
            }
            $pdo->prepare('DELETE FROM weather_hours WHERE t < ?')->execute([time() - 14 * 86400]);
            $pdo->commit();
            $this->rememberForecast($parsed['hours'], isset($parsed['issue']) ? (int) $parsed['issue'] : 0);
            $meta = [
                'ok' => true,
                'fetched_at' => time(),
                'issue' => $parsed['issue'],
                'station' => $parsed['station'],
                'name' => $parsed['name'],
                'hours' => count($parsed['hours']),
                'url' => $this->url(),
                'error' => null,
            ];
        } catch (Throwable $e) {
            if ($this->store->pdo()->inTransaction()) {
                $this->store->pdo()->rollBack();
            }
            $meta = array_merge($this->meta(), [
                'ok' => false,
                'fetched_at' => time(),
                'error' => $e->getMessage(),
                'url' => $this->url(),
            ]);
        }
        $this->store->put('weather_meta', $meta);
        return $meta;
    }

    /** @param array<int, array{t:int, radiation:?float, cloud:?float, sunshine_s:?float, temp_c:?float}> $hours */
    private function rememberForecast(array $hours, int $issue): void
    {
        $plant = $this->store->all()['plant'] ?? null;
        if (!is_array($plant) || !$hours) {
            return;
        }
        if ($issue > 0) {
            Forecast::rememberIssue($this->store->pdo(), $hours, $plant, $issue);
        }
        Forecast::rememberDays($this->store->pdo(), Forecast::fromRadiation($hours, $plant), $plant);
    }

    /** Rad1h ist die Stundensumme in kJ/m². W/m² = Wert / 3,6. */
    public static function radiationWm2(float $kilojoule): float
    {
        return $kilojoule / 3.6;
    }

    public static function parse(string $kml): array
    {
        if (preg_match('//u', $kml) !== 1 && function_exists('iconv')) {
            $converted = iconv('ISO-8859-1', 'UTF-8//IGNORE', $kml);
            if (is_string($converted) && $converted !== '') {
                $kml = $converted;
            }
        }
        if (!preg_match('/<dwd:ForecastTimeSteps>(.*?)<\/dwd:ForecastTimeSteps>/s', $kml, $block)) {
            throw new RuntimeException('In der DWD-Datei fehlen die Zeitstempel.');
        }
        preg_match_all('/<dwd:TimeStep>([^<]+)<\/dwd:TimeStep>/', $block[1], $stamps);
        $times = $stamps[1] ?? [];
        if (!$times) {
            throw new RuntimeException('In der DWD-Datei fehlen die Zeitstempel.');
        }
        $values = [];
        foreach (['Rad1h', 'Neff', 'SunD1', 'TTT'] as $name) {
            if (!preg_match('/elementName="' . $name . '"[^>]*>\s*<dwd:value>([^<]*)<\/dwd:value>/s', $kml, $match)) {
                $values[$name] = [];
                continue;
            }
            $values[$name] = preg_split('/\s+/', trim($match[1])) ?: [];
        }
        $hours = [];
        foreach ($times as $index => $stamp) {
            $end = strtotime($stamp);
            if ($end === false) {
                continue;
            }
            $hours[] = [
                't' => $end - 3600,
                'radiation' => self::number($values['Rad1h'][$index] ?? null, true),
                'cloud' => self::number($values['Neff'][$index] ?? null, false),
                'sunshine_s' => self::number($values['SunD1'][$index] ?? null, false),
                'temp_c' => self::celsius($values['TTT'][$index] ?? null),
            ];
        }
        $station = 'F9519';
        if (preg_match('/<kml:name>([^<]+)<\/kml:name>/', $kml, $name)) {
            $station = trim($name[1]);
        }
        $place = $station;
        if (preg_match('/<kml:description>([^<]+)<\/kml:description>/', $kml, $desc)) {
            $place = trim($desc[1]);
        }
        $issue = null;
        if (preg_match('/<dwd:IssueTime>([^<]+)<\/dwd:IssueTime>/', $kml, $issued)) {
            $issue = strtotime($issued[1]) ?: null;
        }
        return ['issue' => $issue, 'station' => $station, 'name' => $place, 'hours' => $hours];
    }

    private static function number(?string $raw, bool $radiation): ?float
    {
        if ($raw === null || $raw === '' || $raw === '-') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $value = (float) $raw;
        return $radiation ? self::radiationWm2($value) : $value;
    }

    private static function celsius(?string $raw): ?float
    {
        $value = self::number($raw, false);
        if ($value === null) {
            return null;
        }
        return $value > 150 ? $value - 273.15 : $value;
    }

    private function download(string $url): string
    {
        self::assertUrl($url);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Die Wetterdatei lässt sich nicht laden.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'EMS-Home-Assistant',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code >= 400) {
            throw new RuntimeException('Der DWD antwortet mit Status ' . ($code ?: 'ohne Verbindung') . '.');
        }
        if (strlen($body) > 4000000) {
            throw new RuntimeException('Die Wetterdatei ist unerwartet groß.');
        }
        return $body;
    }

    public static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || $host !== 'opendata.dwd.de' || !str_ends_with(strtolower($path), '.kmz')) {
            throw new RuntimeException('Die Adresse muss eine https-KMZ-Datei auf opendata.dwd.de sein.');
        }
    }

    private static function kmlFromKmz(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mos');
        if ($tmp === false) {
            throw new RuntimeException('Temporäre Wetterdatei nicht möglich.');
        }
        file_put_contents($tmp, $bytes);
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['unzip', '-p', $tmp], $spec, $pipes);
        $kml = '';
        if (is_resource($proc)) {
            $kml = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        unlink($tmp);
        if (!str_contains($kml, 'TimeStep')) {
            throw new RuntimeException('Die KMZ-Datei enthält keine MOSMIX-Prognose.');
        }
        return $kml;
    }
}
