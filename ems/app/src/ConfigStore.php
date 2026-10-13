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
        // Statistik aus Home Assistant für abgeschlossene Zeiträume der Heatmap, eine Woche lang.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS stat_cache (k TEXT PRIMARY KEY, at INTEGER NOT NULL, v TEXT NOT NULL)');
        // Gesendete Mitteilungen (Notify), die neuesten 200.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY,
                at INTEGER NOT NULL,
                event TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                targets TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL,
                error TEXT,
                test INTEGER NOT NULL DEFAULT 0
            )'
        );
        // Börsenstrompreis DE-LU je Viertelstunde (vor Oktober 2025 je Stunde), EUR/MWh netto; siehe MarketPrices.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS market_prices (start INTEGER PRIMARY KEY, "end" INTEGER NOT NULL, eur_mwh REAL NOT NULL, source TEXT NOT NULL)');
        // Preise der Anbieter-Entität (Tibber, Octopus …), ct/kWh brutto, wie der Recorder sie gesehen hat.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS provider_prices (start INTEGER PRIMARY KEY, "end" INTEGER NOT NULL, ct REAL NOT NULL, source TEXT NOT NULL)');
        // Netzbezug und Einspeisung je Viertelstunde, von EMS selbst gemessen (GridMeter).
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS grid_quarters (start INTEGER PRIMARY KEY, import_kwh REAL NOT NULL, export_kwh REAL NOT NULL, seconds INTEGER NOT NULL)');
        $this->migrateModes();
        $this->migrateGrid();
    }

    /**
     * Seit 1.13 ist Netzladen kein Modus mehr, sondern ein eigener Schalter (charge.grid: off, now, windows); die Modi
     * für Sonne und Speicher heißen Intelligent, Min+Sonne und Schnell. Einmal umstellen: Wer Netzladen eingestellt hat,
     * lädt weiter mit Intelligent und Netzladen „Jetzt“, also genauso. „Nach dem Abstecken: Netzladen“ wird zu
     * „Modus behalten“. Auch die Mitteilungen merken sich den Modus, sonst sähen sie einen Wechsel, den es nicht gab.
     */
    private function migrateGrid(): void
    {
        if ($this->get('modes_v3') !== null) {
            return;
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->get('modes_v3') === null) {
                $charge = $this->get('charge', []);
                if (is_array($charge) && $charge) {
                    if (($charge['mode'] ?? null) === 'netz') {
                        $charge['mode'] = 'smart';
                        $charge['grid'] = 'now';
                    }
                    if (($charge['after_unplug'] ?? null) === 'netz') {
                        $charge['after_unplug'] = '';
                    }
                    $charge['last_mode'] = in_array($charge['mode'] ?? null, Energy::SUN_MODES, true) ? $charge['mode'] : 'smart';
                    unset($charge['price_limit_ct']);
                    $this->put('charge', $charge);
                }
                $state = $this->get('notify_state', []);
                if (is_array($state) && ($state['mode'] ?? null) === 'netz') {
                    $state['mode'] = 'smart';
                    $this->put('notify_state', $state);
                }
                $this->put('modes_v3', 1);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Seit 1.10 sind Schnell (Sonne, Speicher, Netz) und Netzladen (Netz, der Speicher gibt nichts ab) zwei Modi. Bis
     * dahin hieß Netzladen intern „schnell“ und schonte den Speicher nur mit battery_strategy.grid_protect. Einmal
     * umstellen, damit jeder weiter so lädt wie vorher: mit Schonen wird „schnell“ zu „netz“, ohne bleibt es Schnell.
     * Auch die Regelung und die Mitteilungen merken sich den Modus, sonst sähen sie einen Wechsel, den es nicht gab.
     */
    private function migrateModes(): void
    {
        if ($this->get('modes_v2') !== null) {
            return;
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->get('modes_v2') === null) {
                $strategy = $this->get('battery_strategy', []);
                $strategy = is_array($strategy) ? $strategy : [];
                $protect = !array_key_exists('grid_protect', $strategy) || !empty($strategy['grid_protect']);
                $charge = $this->get('charge', []);
                if (is_array($charge) && $charge) {
                    foreach (['mode', 'after_unplug'] as $field) {
                        if (isset($charge[$field])) {
                            $charge[$field] = Energy::migratedMode($charge[$field], $protect);
                        }
                    }
                    $this->put('charge', $charge);
                }
                foreach (['control_state', 'notify_state'] as $key) {
                    $state = $this->get($key, []);
                    if (is_array($state) && isset($state['mode'])) {
                        $state['mode'] = Energy::migratedMode($state['mode'], $protect);
                        $this->put($key, $state);
                    }
                }
                if (array_key_exists('grid_protect', $strategy)) {
                    unset($strategy['grid_protect']);
                    $this->put('battery_strategy', $strategy);
                }
                $this->put('modes_v2', 1);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
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
                // Laden aus dem Netz (BatteryPlan): Betriebsmodus (select, manual/automatic) und „Laden erzwingen“ (number, W).
                'battery_operating_mode' => '',
                'battery_force_charge' => '',
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
                // Preis-Entität des Anbieters (Tibber, Octopus …), optional; ohne sie rechnet EMS Börse plus Aufschläge.
                'price_import' => '',
                'car_wakeup' => '',
                'weather_radiation' => '',
                'weather_cloud' => '',
                'weather_sunshine' => '',
                'weather_temp' => '',
                'weather_station' => 'soonwald',
            ],
            // Tarif, siehe Tariff: aktiv statisch oder dynamisch; statisch Arbeitspreis und Grundpreis brutto, dynamisch
            // Börse plus Netzentgelt, Steuern und Umlagen, Anbieter (netto ct/kWh), MwSt, Grundpreis, optional Modul-3-Fenster.
            'tariffs' => [
                'mode' => 'static',
                'import_ct' => 34.7,
                'base_eur' => null,
                'dyn_base_eur' => null,
                'dyn_grid_ct' => Tariff::TYPICAL['dyn_grid_ct'],
                'dyn_levies_ct' => Tariff::TYPICAL['dyn_levies_ct'],
                'dyn_supplier_ct' => Tariff::TYPICAL['dyn_supplier_ct'],
                'dyn_vat_pct' => Tariff::TYPICAL['dyn_vat_pct'],
                'dyn_windows' => [],
                'export_ct' => 11.0,
                'co2_g_kwh' => 380.0,
            ],
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
                // Für PVGIS („Lohnt sich dynamisch?“): Standort von Hand (null: der von Home Assistant), Systemverluste
                // in Prozent und das Wetterjahr der Stundenwerte.
                'lat' => null,
                'lon' => null,
                'pvgis_loss' => 14.0,
                'pvgis_year' => 2023,
            ],
            'charge' => [
                // Aus oder ein Modus für Sonne und Speicher (Energy::SUN_MODES); last_mode gilt beim Einschalten.
                'mode' => 'smart',
                'last_mode' => 'smart',
                // Netzladen: off, now (bis Ziel oder Abstecken) oder windows (in den Ladefenstern, PriceWindows).
                'grid' => 'off',
                'phase_mode' => 'auto',
                'solar_share' => 100.0,
                'reserve_w' => 200.0,
                'min_a' => 6,
                'max_a' => 16,
                // Standard: Einschalten nach 1 min, Ausschalten nach 3 min, 60 s zwischen zwei Schaltvorgängen.
                'switch_s' => 60,
                'on_delay_s' => 60,
                'off_delay_s' => 180,
                // Nach einem erreichten Ladeziel und nach dem Abstecken ('' = Modus behalten).
                'then_mode' => 'smart',
                'after_unplug' => '',
            ],
            // Hauptschalter: Nur wenn aktiv, schreibt EMS an Wallbox und Speicher.
            'control' => ['active' => false],
            // backup_soc: Standardwert des Backup-Puffers (null: nicht gesetzt; Netzladen hebt den Puffer an),
            // discharge_kw: höchste Entladeleistung des Speichers (null: unbekannt).
            // Laden aus dem Netz (BatteryPlan, Energie-Boost): grid_kw (Ladeleistung, null: Entladeleistung oder
            // 2,5 kW), grid_max_soc (Obergrenze der Empfehlung), Wirkungsgrad hin und zurück, Verschleiß je kWh.
            'battery_strategy' => [
                'priority_soc' => 80.0,
                'reserve_soc' => 100.0,
                'car_buffer_soc' => 100.0,
                'car_auto_soc' => 100.0,
                'backup_soc' => null,
                'discharge_kw' => null,
                'grid_kw' => null,
                'grid_max_soc' => 100.0,
                'grid_efficiency_pct' => 90.0,
                'grid_wear_ct' => 2.0,
            ],
            'chargepoint' => ['name' => 'Wallbox'],
            // capacity_kwh: Akku des Autos, wenn keine Entität ihn meldet (Tesla BLE meldet keinen). consumption_kwh:
            // Verbrauch in kWh/100 km, für die Reichweite, wenn keine Entität sie meldet.
            'vehicle' => ['name' => 'Auto', 'limit_soc' => 80.0, 'capacity_kwh' => null, 'consumption_kwh' => null],
            'weather' => ['url' => 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/F9519/kml/MOSMIX_L_LATEST_F9519.kmz'],
            // flow_view: Energiefluss auf Laden als Balken ('bar') oder Energie-Flow ('graph').
            'ui' => ['theme' => 'system', 'flow_view' => 'bar'],
            // Mitteilungen über die Home-Assistant-App: Geräte (notify-Dienste), Ruhezeit, Grenzen und je Ereignis
            // an/aus, wichtig, Titel und Text (null = Standard aus Notify::EVENTS).
            'notify' => [
                'targets' => [],
                'open_app' => true,
                'quiet' => false,
                'quiet_from' => '22:00',
                'quiet_to' => '07:00',
                'report_time' => '20:00',
                'full_soc' => 100.0,
                'low_soc' => 20.0,
                'surplus_kw' => 2.0,
                'sunny_kwh' => 25.0,
                'events' => [],
                'test' => ['title' => null, 'message' => null],
            ],
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
