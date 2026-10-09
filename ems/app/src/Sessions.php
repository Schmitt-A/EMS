<?php
declare(strict_types=1);

final class Sessions
{
    public function __construct(private ConfigStore $store) {}

    public function open(): ?array
    {
        $row = $this->store->pdo()->query('SELECT * FROM sessions WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public function tick(array $live, array $charge, int $now): void
    {
        $power = $live['wallbox_kw'];
        if ($power === null) {
            return;
        }
        $car = strtolower((string) ($live['wallbox_car_raw'] ?? ''));
        $active = $power > 0.2 || $car === 'charging';
        $pdo = $this->store->pdo();
        $open = $this->open();
        $runtime = $this->store->get('session_runtime', ['idle_since' => null]);

        if ($active) {
            if (!$open) {
                $stmt = $pdo->prepare('INSERT INTO sessions (started_at, vehicle, loadpoint, source) VALUES (?, ?, ?, "recorder")');
                $stmt->execute([date('c', $now), $live['car_label'] ?? '', 'Wallbox']);
                $open = $this->open();
            }
            $runtime['idle_since'] = null;
            $this->store->put('session_runtime', $runtime);
            if (!$open) {
                return;
            }
            $last = $open['duration_s'] > 0 && !empty($runtime['last_sample']) ? (int) $runtime['last_sample'] : (int) strtotime((string) $open['started_at']);
            $dt = $now - $last;
            if ($dt > 0 && $dt <= 30) {
                $kwh = max(0, $power) * ($dt / 3600);
                $grid = min($kwh, max(0, (float) ($live['grid_import_kw'] ?? 0)) * ($dt / 3600));
                $solar = max(0, $kwh - $grid);
                $stmt = $pdo->prepare('UPDATE sessions SET energy_kwh = energy_kwh + ?, solar_kwh = solar_kwh + ?, grid_kwh = grid_kwh + ?, duration_s = duration_s + ?, vehicle = ? WHERE id = ?');
                $stmt->execute([$kwh, $solar, $grid, $dt, $live['car_label'] ?? $open['vehicle'], $open['id']]);
            }
            $runtime['last_sample'] = $now;
            $this->store->put('session_runtime', $runtime);
            return;
        }

        if (!$open) {
            return;
        }
        $idleSince = $runtime['idle_since'] ?? $now;
        $runtime['idle_since'] = $idleSince;
        $runtime['last_sample'] = $now;
        $this->store->put('session_runtime', $runtime);
        if ($now - (int) $idleSince >= (int) ($charge['off_delay_s'] ?? 60)) {
            $stmt = $pdo->prepare('UPDATE sessions SET ended_at = ? WHERE id = ?');
            $stmt->execute([date('c', $now), $open['id']]);
        }
    }

    public function latest(): ?array
    {
        $row = $this->store->pdo()->query('SELECT * FROM sessions ORDER BY started_at DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public function month(string $month): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
        return $this->between($start, $start->modify('+1 month'));
    }

    public function between(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT * FROM sessions WHERE started_at >= ? AND started_at < ? ORDER BY started_at DESC');
        $stmt->execute([$start->format('c'), $end->format('c')]);
        return $stmt->fetchAll() ?: [];
    }

    public function setOdometer(int $id, ?float $km): void
    {
        if ($id <= 0) {
            return;
        }
        $stmt = $this->store->pdo()->prepare('UPDATE sessions SET odometer = ? WHERE id = ?');
        $stmt->execute([$km, $id]);
    }

    public function import(HaSource $ha): array
    {
        $state = $ha->state('sensor.ems_ladelog_historie');
        $sessions = is_array($state) ? ($state['attributes']['sessions'] ?? null) : null;
        if (!is_array($sessions)) {
            return ['ok' => false, 'message' => 'Der Sensor sensor.ems_ladelog_historie hat keine Vorgänge.'];
        }
        $stmt = $this->store->pdo()->prepare(
            'INSERT OR IGNORE INTO sessions (started_at, ended_at, vehicle, loadpoint, energy_kwh, solar_kwh, grid_kwh, duration_s, source, odometer, meter_start, meter_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "import", ?, ?, ?)'
        );
        $count = 0;
        foreach ($sessions as $session) {
            if (!is_array($session) || empty($session['created'])) {
                continue;
            }
            $created = preg_replace('/\.(\d{6})\d+/', '.$1', (string) $session['created']) ?? (string) $session['created'];
            $parts = explode(':', (string) ($session['duration_fmt'] ?? '0:00'));
            $duration = ((int) ($parts[0] ?? 0)) * 3600 + ((int) ($parts[1] ?? 0)) * 60;
            $stmt->execute([
                $created,
                null,
                (string) ($session['vehicle'] ?? ''),
                (string) ($session['loadpoint'] ?? 'Wallbox'),
                (float) ($session['chargedEnergy'] ?? 0),
                (float) ($session['solarEnergy'] ?? 0),
                (float) ($session['gridEnergy'] ?? 0),
                $duration,
                self::optionalNumber($session['odometer'] ?? $session['vehicleOdometer'] ?? null),
                self::optionalNumber($session['meterStart'] ?? $session['meter_start'] ?? null),
                self::optionalNumber($session['meterEnd'] ?? $session['meter_end'] ?? null),
            ]);
            $count += $stmt->rowCount();
        }
        return ['ok' => true, 'message' => $count . ' Vorgänge übernommen.'];
    }

    private static function optionalNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    public static function cost(array $row, array $tariffs): array
    {
        $energy = (float) $row['energy_kwh'];
        $solar = (float) $row['solar_kwh'];
        $grid = (float) $row['grid_kwh'];
        $import = (float) ($tariffs['import_ct'] ?? 0) / 100;
        $export = (float) ($tariffs['export_ct'] ?? 0) / 100;
        $cost = $grid * $import + $solar * $export;
        $reference = $energy * $import;
        return [
            'cost' => $cost,
            'reference' => $reference,
            'saved' => $reference - $cost,
            'ct' => $energy > 0 ? ($cost / $energy) * 100 : 0,
            'solar_pct' => $energy > 0 ? ($solar / $energy) * 100 : 0,
        ];
    }
}
