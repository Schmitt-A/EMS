<?php
declare(strict_types=1);

final class ConfigStore
{
    private \PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=4000');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY,
                started_at TEXT NOT NULL UNIQUE,
                ended_at TEXT,
                vehicle TEXT,
                loadpoint TEXT,
                energy_kwh REAL NOT NULL DEFAULT 0,
                solar_kwh REAL NOT NULL DEFAULT 0,
                grid_kwh REAL NOT NULL DEFAULT 0,
                duration_s INTEGER NOT NULL DEFAULT 0,
                source TEXT NOT NULL DEFAULT "recorder"
            )'
        );
        $columns = [];
        foreach ($this->pdo->query('PRAGMA table_info(sessions)') ?: [] as $column) {
            $columns[] = (string) $column['name'];
        }
        // plug_at: wann das Auto angesteckt wurde; Vorgänge mit demselben Wert bilden einen Ladevorgang.
        foreach (['odometer' => 'REAL', 'meter_start' => 'REAL', 'meter_end' => 'REAL', 'plug_at' => 'TEXT'] as $name => $type) {
            if (!in_array($name, $columns, true)) {
                $this->pdo->exec('ALTER TABLE sessions ADD COLUMN ' . $name . ' ' . $type);
            }
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS daily (
                day TEXT PRIMARY KEY,
                actual_kwh REAL,
                model_kwh REAL
            )'
        );
        $daily = [];
        foreach ($this->pdo->query('PRAGMA table_info(daily)') ?: [] as $column) {
            $daily[] = (string) $column['name'];
        }
        if (!in_array('model_mode', $daily, true)) {
            $this->pdo->exec('ALTER TABLE daily ADD COLUMN model_mode TEXT');
        }
    }

    public function defaults(): array
    {
        return [
            'wizard_done' => false,
            'mapping' => [
                'pv_power' => '',
                'pv_energy' => '',
                'battery_soc' => '',
                'battery_mode' => 'split',
                'battery_charge' => '',
                'battery_discharge' => '',
                'battery_signed' => '',
                'battery_sign' => 'positive_charge',
                'battery_capacity' => '',
                'battery_total' => '',
                'battery_reserve' => '',
                'car_soc' => '',
                'car_capacity' => '',
                'car_range' => '',
                'car_odometer' => '',
                'car_limit' => '',
                'grid_mode' => 'split',
                'grid_import' => '',
                'grid_export' => '',
                'grid_signed' => '',
                'grid_sign' => 'positive_import',
                'house_power' => '',
                'house_includes_wallbox' => true,
                'wallbox_power' => '',
                'wallbox_car' => '',
                'wallbox_amps' => '',
                'wallbox_amps_max' => '',
                'wallbox_phases' => '',
                'wallbox_force' => '',
                'wallbox_energy' => '',
                'car_wakeup' => '',
                'weather_radiation' => '',
                'weather_cloud' => '',
                'weather_sunshine' => '',
                'weather_temp' => '',
                'weather_station' => 'soonwald',
            ],
            'tariffs' => ['import_ct' => 34.7, 'export_ct' => 11.0, 'co2_g_kwh' => 380.0],
            'plant' => [
                'kwp' => 10.03,
                'inverter_kw' => 10.0,
                'tilt' => 13.0,
                'azimuth' => 270.0,
                'n_days' => 7,
                'factor' => 0.93,
                'factor_locked' => false,
                'regress_a' => 2.1,
                'regress_b' => 0.86,
                'regress_locked' => false,
                'regress_days' => 0,
            ],
            'charge' => [
                'mode' => 'smart',
                'phase_mode' => 'auto',
                'solar_share' => 100.0,
                'reserve_w' => 200.0,
                'min_a' => 6,
                'max_a' => 16,
                // Wie evcc: Einschalten nach 1 min, Ausschalten nach 3 min, 60 s zwischen zwei Schaltvorgängen.
                'switch_s' => 60,
                'on_delay_s' => 60,
                'off_delay_s' => 180,
                // Nach einem erreichten Ladeziel und nach dem Abstecken ('' = Modus behalten).
                'then_mode' => 'smart',
                'after_unplug' => '',
            ],
            // Hauptschalter: Nur wenn aktiv, schreibt EMS an Wallbox und Speicher.
            'control' => ['active' => false],
            // backup_soc: Standardwert des Backup-Puffers (null: nicht gesetzt), grid_protect: Netzladen hebt den Puffer an,
            // discharge_kw: höchste Entladeleistung des Speichers (null: unbekannt).
            'battery_strategy' => [
                'priority_soc' => 80.0,
                'reserve_soc' => 100.0,
                'car_buffer_soc' => 100.0,
                'car_auto_soc' => 100.0,
                'backup_soc' => null,
                'grid_protect' => true,
                'discharge_kw' => null,
            ],
            'chargepoint' => ['name' => 'Wallbox'],
            // capacity_kwh: Akku des Autos, wenn keine Entität ihn meldet (Tesla BLE meldet keinen).
            'vehicle' => ['name' => 'Auto', 'limit_soc' => 80.0, 'capacity_kwh' => null],
            'weather' => ['url' => 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/F9519/kml/MOSMIX_L_LATEST_F9519.kmz'],
            'ui' => ['theme' => 'system'],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT v FROM kv WHERE k = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $default ?? ($this->defaults()[$key] ?? null);
        }
        return json_decode((string) $row['v'], true);
    }

    public function put(string $key, mixed $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    public function merge(string $key, array $patch): array
    {
        $current = $this->get($key, []);
        if (!is_array($current)) {
            $current = [];
        }
        $defaults = $this->defaults()[$key] ?? [];
        $next = array_merge(is_array($defaults) ? $defaults : [], $current, $patch);
        $this->put($key, $next);
        return $next;
    }

    public function all(): array
    {
        $out = $this->defaults();
        foreach (array_keys($out) as $key) {
            $value = $this->get($key, $out[$key]);
            if (is_array($out[$key]) && is_array($value)) {
                $out[$key] = array_merge($out[$key], $value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
