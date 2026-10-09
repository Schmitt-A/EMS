<?php
declare(strict_types=1);

/**
 * Rechenmodell hinter EMS_DEMO=1. Sonne, Haus, Wallbox und Speicher sind feste Funktionen der Zeit,
 * der Speicher wird in 5-Minuten-Schritten über gut zwei Monate simuliert. So passen Live-Werte,
 * Statistik und Verlauf zueinander, ohne dass Home Assistant läuft.
 */
final class DemoModel
{
    private const STEP = 300;
    private const FIELDS = ['pv', 'house', 'wall', 'phases', 'charge', 'discharge', 'import', 'export', 'soc', 'stored', 'car', 'status'];
    private const STATUS = ['idle', 'wait_car', 'charging', 'complete'];

    private static ?self $instance = null;

    private array $data;
    /** @var array<string, array<string, mixed>> */
    private array $days = [];
    /** @var array<string, array<string, mixed>> */
    private array $info = [];
    private int $simStart = 0;
    private int $simEnd = 0;
    /** @var array<string, array<int, float>> */
    private array $sim = [];

    public static function load(): self
    {
        return self::$instance ??= new self(EMS_APP . '/demo/beispieldaten.json');
    }

    public function __construct(string $file)
    {
        $json = json_decode((string) @file_get_contents($file), true);
        if (!is_array($json)) {
            throw new RuntimeException('Beispieldaten fehlen: ' . $file);
        }
        $this->data = $json;
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        foreach ($json['tage'] ?? [] as $row) {
            $this->days[$today->modify(sprintf('%+d days', (int) $row['offset']))->format('Y-m-d')] = $row;
        }
    }

    public function data(): array
    {
        return $this->data;
    }

    /** @return array{kwp: float, inverter_kw: float, tilt: float, azimuth: float, factor: float} */
    public function plant(): array
    {
        $a = $this->data['anlage'];
        return [
            'kwp' => (float) $a['kwp'],
            'inverter_kw' => (float) $a['wechselrichter_kw'],
            'tilt' => (float) $a['neigung'],
            'azimuth' => (float) $a['ausrichtung'],
            'factor' => 0.93,
        ];
    }

    /** Uhr der Live-Werte. EMS_DEMO_CLOCK=HH:MM hält sie auf diese Zeit von heute fest. */
    public function now(): int
    {
        $clock = (string) (getenv('EMS_DEMO_CLOCK') ?: '');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $m)) {
            return (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->setTime((int) $m[1], (int) $m[2])->getTimestamp();
        }
        return time();
    }

    private static function noise(string $key): float
    {
        return hexdec(hash('crc32b', $key)) / 0xFFFFFFFF;
    }

    /** @return array{0: string, 1: float} lokaler Tag und Stunde mit Bruchteil */
    private static function local(int $t): array
    {
        [$day, $hour, $minute, $second] = explode('|', date('Y-m-d|G|i|s', $t));
        return [$day, (int) $hour + ((int) $minute) / 60 + ((int) $second) / 3600];
    }

    private function day(string $day): array
    {
        if (isset($this->info[$day])) {
            return $this->info[$day];
        }
        $stamp = strtotime($day . ' 12:00:00');
        $doy = (int) date('z', $stamp);
        $season = sin(2 * M_PI * ($doy - 80) / 365);
        $row = $this->days[$day] ?? null;
        $sun = $row !== null
            ? (float) $row['sonne']
            : max(0.08, min(1.0, 0.55 + 0.25 * sin(2 * M_PI * ($doy - 100) / 365) + (self::noise('sun' . $day) - 0.5) * 0.7));
        $wiggle = [];
        for ($h = 0; $h <= 24; $h++) {
            $wiggle[] = 0.82 + 0.32 * self::noise('h' . $day . $h);
        }
        $house = [];
        for ($q = 0; $q <= 96; $q++) {
            $house[] = 0.85 + 0.3 * self::noise('q' . $day . $q);
        }
        $weekday = (int) date('N', $stamp);
        return $this->info[$day] = [
            'sun' => $sun,
            'err' => $row !== null ? (float) $row['prognose_fehler'] : 0.9 + self::noise('err' . $day) * 0.22,
            'temp' => $row !== null ? (float) $row['temp_mittel'] : 2 + 17 * (0.5 + 0.5 * $season),
            'peak' => 980 * sin(deg2rad(40.0 + 23.44 * $season)),
            'length' => 12 + 4.3 * $season,
            'noon' => 12.3 + (int) date('I', $stamp),
            'wiggle' => $wiggle,
            'house' => $house,
            'connected' => $weekday >= 6 || self::noise('car' . $day) < 0.55,
            'car' => (float) ($this->data['fahrzeug']['soc_morgens'] ?? 40) + (self::noise('soc' . $day) - 0.5) * 24,
        ];
    }

    private function clearSky(array $d, float $hour): float
    {
        $x = ($hour - ($d['noon'] - $d['length'] / 2)) / $d['length'];
        if ($x <= 0 || $x >= 1) {
            return 0.0;
        }
        return $d['peak'] * sin(M_PI * $x) ** 1.35;
    }

    private function radiationAt(array $d, float $hour): float
    {
        $i = max(0, min(23, (int) floor($hour)));
        $f = $hour - $i;
        $wiggle = $d['wiggle'][$i] * (1 - $f) + $d['wiggle'][$i + 1] * $f;
        return max(0.0, $this->clearSky($d, $hour) * (0.12 + 0.88 * $d['sun']) * min(1.12, $wiggle));
    }

    /** Tatsächliche Globalstrahlung in W/m². */
    public function radiation(int $t): float
    {
        [$day, $hour] = self::local($t);
        return $this->radiationAt($this->day($day), $hour);
    }

    /** Was der DWD für die Stunde ab $t vorhersagt, gerechnet zum Lauf $issue. */
    public function forecastRadiation(int $t, int $issue): float
    {
        [$day] = self::local($t);
        $d = $this->day($day);
        $lead = max(0, ($t - $issue) / 86400);
        $run = 1 + (self::noise('run' . $issue . $day) - 0.5) * 0.08 * (1 + $lead / 3);
        return max(0.0, $this->radiation($t + 1800) / $d['err'] * $run);
    }

    public function pvKw(int $t): float
    {
        $plant = $this->plant();
        return min($plant['inverter_kw'], max(0.0, $this->radiation($t) * Forecast::chain($plant['kwp'], 0.95)));
    }

    private function houseAt(array $d, float $hour): float
    {
        $profile = $this->data['haus']['profil'];
        $i = (int) floor($hour) % 24;
        $f = $hour - floor($hour);
        $p = $profile[$i] * (1 - $f) + $profile[($i + 1) % 24] * $f;
        return (float) $this->data['haus']['grundlast_kw'] + $p * $d['house'][max(0, min(96, (int) floor($hour * 4)))];
    }

    /** @return array{0: float, 1: int} Ladeleistung in kW und Phasen, gerastert wie die Wallbox */
    private static function quantize(float $kw): array
    {
        if ($kw < 1.38) {
            return [0.0, 1];
        }
        if ($kw < 4.14) {
            return [max(6, min(16, (int) floor($kw / 0.23))) * 0.23, 1];
        }
        return [max(6, min(16, (int) floor($kw / 0.69))) * 0.69, 3];
    }

    private function simulate(int $until): void
    {
        $start = intdiv($this->now() - 65 * 86400, self::STEP) * self::STEP;
        $until = max($until, $this->now());
        if ($this->sim && $this->simStart === $start && $this->simEnd >= $until) {
            return;
        }
        $total = (float) $this->data['anlage']['speicher_kwh'];
        $maxKw = (float) ($this->data['anlage']['speicher_max_kw'] ?? 5);
        $limit = (float) $this->data['fahrzeug']['limit'];
        $carCap = (float) $this->data['fahrzeug']['kapazitaet_kwh'];
        $plant = $this->plant();
        $chain = Forecast::chain($plant['kwp'], 0.95);
        $h = self::STEP / 3600;
        $stored = $total * 0.5;
        $car = 0.0;
        $carDay = '';
        $sim = array_fill_keys(self::FIELDS, []);
        for ($t = $start; $t <= $until + self::STEP; $t += self::STEP) {
            [$dayKey, $hour] = self::local($t + intdiv(self::STEP, 2));
            $d = $this->day($dayKey);
            if ($dayKey !== $carDay) {
                $carDay = $dayKey;
                $car = $d['car'];
            }
            $pv = min($plant['inverter_kw'], max(0.0, $this->radiationAt($d, $hour) * $chain));
            $house = $this->houseAt($d, $hour);
            $connected = $d['connected'] && $hour >= 10 && $hour < 16.5;
            [$wall, $phases] = ($connected && $car < $limit) ? self::quantize($pv - $house - 0.25) : [0.0, 1];
            $car = min(100.0, $car + $wall * $h * 0.92 / $carCap * 100);
            $net = $pv - $house - $wall;
            $charge = $discharge = $import = $export = 0.0;
            if ($net >= 0) {
                $charge = min($net, $maxKw, max(0.0, ($total - $stored) / $h));
                $export = $net - $charge;
            } else {
                $discharge = min(-$net, $maxKw, max(0.0, ($stored - $total * 0.05) / $h));
                $import = -$net - $discharge;
            }
            $stored = min($total, max(0.0, $stored + ($charge * 0.95 - $discharge / 0.95) * $h));
            $status = !$connected ? 0 : ($wall > 0.05 ? 2 : ($car >= $limit ? 3 : 1));
            foreach ([
                'pv' => $pv, 'house' => $house, 'wall' => $wall, 'phases' => $phases, 'charge' => $charge,
                'discharge' => $discharge, 'import' => $import, 'export' => $export,
                'soc' => $stored / $total * 100, 'stored' => $stored, 'car' => $car, 'status' => $status,
            ] as $key => $value) {
                $sim[$key][] = (float) $value;
            }
        }
        $this->sim = $sim;
        $this->simStart = $start;
        $this->simEnd = $until;
    }

    /** Alle Größen im 5-Minuten-Schritt, der $t enthält. */
    public function at(int $t): array
    {
        $this->simulate($t);
        $i = max(0, min(count($this->sim['pv']) - 1, intdiv($t - $this->simStart, self::STEP)));
        $row = [];
        foreach (self::FIELDS as $key) {
            $row[$key] = $this->sim[$key][$i];
        }
        $row['phases'] = (int) $row['phases'];
        $row['status'] = self::STATUS[(int) $row['status']];
        return $row;
    }

    /** @return array<int, float> Zeitstempel => Wert im 5-Minuten-Raster, nur innerhalb der Simulation */
    public function series(string $field, int $start, int $end): array
    {
        $this->simulate($end);
        $from = max($this->simStart, intdiv($start, self::STEP) * self::STEP);
        $out = [];
        for ($t = $from; $t < $end; $t += self::STEP) {
            $i = intdiv($t - $this->simStart, self::STEP);
            if (!isset($this->sim[$field][$i])) {
                break;
            }
            $out[$t] = $this->sim[$field][$i];
        }
        return $out;
    }

    public function simulatedFrom(): int
    {
        $this->simulate($this->now());
        return $this->simStart;
    }

    /** PV-Leistung außerhalb der Simulation, im 15-Minuten-Raster. @return array<int, float> */
    public function pvSeries(int $start, int $end): array
    {
        $out = [];
        for ($t = intdiv($start, 900) * 900; $t < $end; $t += 900) {
            $out[$t] = $this->pvKw($t + 450);
        }
        return $out;
    }

    public function dayActualKwh(string $day): float
    {
        $start = strtotime($day . ' 00:00:00');
        $end = strtotime($day . ' 00:00:00 +1 day');
        $sum = 0.0;
        for ($t = $start; $t < $end; $t += self::STEP) {
            $sum += $this->pvKw($t + intdiv(self::STEP, 2)) * self::STEP / 3600;
        }
        return $sum;
    }

    /** Rohmodell eines Tages (Eichfaktor 1) aus dem Lauf vom Vorabend. */
    public function dayModelKwh(string $day): float
    {
        $start = strtotime($day . ' 00:00:00');
        $end = strtotime($day . ' 00:00:00 +1 day');
        $issue = $start - 6 * 3600;
        $plant = $this->plant();
        $plant['factor'] = 1;
        $sum = 0.0;
        for ($t = $start; $t < $end; $t += 3600) {
            $sum += Forecast::powerKw($this->forecastRadiation($t, $issue), (int) date('G', $t), $plant);
        }
        return $sum;
    }

    /** Eine MOSMIX-Datei, wie sie WeatherFeed::parse liefern würde: 14 Tage zurück, 10 Tage voraus. */
    public function weatherFile(int $now): array
    {
        $issue = intdiv($now, 10800) * 10800;
        $today = strtotime(date('Y-m-d', $now) . ' 00:00:00');
        $hours = [];
        for ($t = $today - 14 * 86400; $t < $today + 10 * 86400; $t += 3600) {
            [$dayKey, $hour] = self::local($t + 1800);
            $d = $this->day($dayKey);
            $runIssue = $t < $issue ? strtotime($dayKey . ' 00:00:00') - 6 * 3600 : $issue;
            $clear = $this->clearSky($d, $hour);
            $hours[] = [
                't' => $t,
                'radiation' => round($this->forecastRadiation($t, $runIssue), 1),
                'cloud' => round(max(0, min(100, 100 * (1 - $d['sun']) + (self::noise('c' . $t) - 0.5) * 30)), 0),
                'sunshine_s' => $clear > 0 ? round(3600 * $d['sun'] ** 1.3 * min(1, $d['wiggle'][(int) floor($hour)]), 0) : 0.0,
                'temp_c' => round($d['temp'] + 4 * sin(2 * M_PI * ($hour - 9) / 24), 1),
            ];
        }
        return ['issue' => $issue, 'station' => 'F9519', 'name' => 'BEISPIELORT', 'hours' => $hours];
    }
}
