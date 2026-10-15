<?php
declare(strict_types=1);

final class Actions
{
    public const STEPS = [
        'verbindung' => 'Verbindung',
        'photovoltaik' => 'Photovoltaik',
        'speicher' => 'Speicher',
        'netz' => 'Netz',
        'haus' => 'Haus',
        'wallbox' => 'Wallbox',
        'wetter' => 'Wetter',
        'pruefen' => 'Prüfen',
    ];

    public const SUGGEST = [
        'pv_power' => 'sensor.total_dc_power',
        'pv_energy' => 'sensor.daily_pv_generation_battery_discharge',
        'house_power' => 'sensor.sonnenbatterie_382994_state_consumption_current',
        'grid_import' => 'sensor.sonnenbatterie_382994_state_grid_in',
        'grid_export' => 'sensor.sonnenbatterie_382994_state_grid_out',
        'battery_soc' => 'sensor.sonnenbatterie_382994_state_battery_percentage_user',
        'battery_charge' => 'sensor.sonnenbatterie_382994_state_battery_in',
        'battery_discharge' => 'sensor.sonnenbatterie_382994_state_battery_out',
        'battery_capacity' => 'sensor.sonnenbatterie_382994_battery_remaining_capacity_usable',
        'battery_total' => 'sensor.sonnenbatterie_382994_battery_installed_capacity_usable',
        'wallbox_power' => 'sensor.go_echarger_506181_power_total',
        'wallbox_car' => 'sensor.go_echarger_506181_car',
        'wallbox_amps' => 'number.go_echarger_506181_amp',
        'wallbox_amps_max' => 'number.go_echarger_506181_ama',
        'wallbox_phases' => 'select.go_echarger_506181_psm',
        'wallbox_force' => 'select.go_echarger_506181_frc',
    ];

    /** Alle Zuordnungen, die eine Entität aufnehmen; Formulare und Import nehmen nur diese an. */
    private const ENTITY_KEYS = [
        'pv_power', 'pv_energy', 'battery_soc', 'battery_charge', 'battery_discharge', 'battery_signed', 'battery_capacity', 'battery_total', 'battery_reserve',
        'battery_operating_mode', 'battery_force_charge',
        'car_soc', 'car_capacity', 'car_range', 'car_odometer', 'car_limit', 'car_wakeup', 'grid_import', 'grid_export', 'grid_signed', 'house_power',
        'wallbox_power', 'wallbox_car', 'wallbox_amps', 'wallbox_amps_max', 'wallbox_phases', 'wallbox_force', 'wallbox_energy',
        'price_import',
    ];

    /**
     * Muster für Entitäten, die nicht in SUGGEST stehen: der Backup-Puffer der sonnenBatterie (aus der Integration oder
     * als eigene Template-Number, etwa number.sonnenbatterie_backup_puffer), die Sensoren von ESPHome Tesla BLE
     * (englisch und mit deutschem Sprachpaket) und die go-e der Integration „go-eCharger API v2“ mit ihren
     * Standard-Kennungen. [Zuordnung => [Muster für die ID, Einheiten]]
     */
    private const PATTERNS = [
        'wallbox_power' => ['/^sensor\.goe_\w+_nrg_11$/', ['w', 'kw']],
        'wallbox_car' => ['/^sensor\.goe_\w+_car_value$/', ['']],
        'wallbox_amps' => ['/^number\.goe_\w+_amp$/', ['a']],
        'wallbox_amps_max' => ['/^number\.goe_\w+_ama$/', ['a']],
        'wallbox_phases' => ['/^select\.goe_\w+_psm$/', ['']],
        'wallbox_force' => ['/^select\.goe_\w+_frc$/', ['']],
        'battery_reserve' => ['/^number\.\w*(battery_reserve|backup_buffer|backup_reserve|backup_puffer)$/', ['%']],
        'battery_operating_mode' => ['/^select\.sonnenbatterie_\w+_operating_mode$/', ['']],
        'battery_force_charge' => ['/^number\.sonnenbatterie_\w+_number_charge$/', ['w', 'kw']],
        'car_soc' => ['/^sensor\.\w*tesla\w*_(charge_level|battery_level|ladezustand(_\d)?)$/', ['%']],
        'car_range' => ['/^sensor\.\w*tesla\w*_(range|battery_range|reichweite)$/', ['km', 'mi']],
        'car_odometer' => ['/^sensor\.\w*tesla\w*_(odometer|kilometerstand)$/', ['km', 'mi']],
        'car_limit' => ['/^sensor\.\w*tesla\w*_(charge_limit|ladelimit)$/', ['%']],
        'car_wakeup' => ['/^button\.\w*tesla\w*_(wake_up|wake|aufwecken|wecken)$/', ['']],
        'wallbox_energy' => ['/^sensor\.(go_echarger|goe)_\w+_wh$/', ['kwh', 'wh']],
    ];

    /**
     * Vorschläge zum Übernehmen: erst die festen Kennungen aus SUGGEST, dann die Muster. Ein Muster zählt nur mit
     * passender Einheit, so fällt beim deutschen Sprachpaket der Ladestatus „Ladezustand“ ohne % heraus.
     *
     * @param array<string, array> $index Zustände nach Entität
     */
    public static function suggestions(array $index): array
    {
        $suggest = [];
        foreach (self::SUGGEST as $key => $id) {
            if (isset($index[$id])) {
                $suggest[$key] = $id;
            }
        }
        foreach (self::PATTERNS as $key => [$pattern, $units]) {
            if (isset($suggest[$key])) {
                continue;
            }
            foreach ($index as $id => $row) {
                $unit = strtolower((string) ($row['attributes']['unit_of_measurement'] ?? ''));
                if (preg_match($pattern, (string) $id) && in_array($unit, $units, true)) {
                    $suggest[$key] = (string) $id;
                    break;
                }
            }
        }
        return $suggest;
    }

    public static function nextStep(string $step): string
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($step, $keys, true);
        if ($index === false || $index >= count($keys) - 1) {
            return 'pruefen';
        }
        return $keys[$index + 1];
    }

    public static function missing(array $mapping): array
    {
        $missing = [];
        foreach (['pv_power' => 'PV-Leistung', 'battery_soc' => 'Ladestand', 'house_power' => 'Hausverbrauch', 'wallbox_power' => 'Wallbox'] as $key => $label) {
            if (empty($mapping[$key])) {
                $missing[] = $label;
            }
        }
        $gridOk = !empty($mapping['grid_import']) || (($mapping['grid_mode'] ?? '') === 'signed' && !empty($mapping['grid_signed']));
        if (!$gridOk) {
            $missing[] = 'Netz';
        }
        return $missing;
    }

    public static function saveWizard(string $step): void
    {
        csrf_check();
        if ($step === 'verbindung') {
            $url = trim((string) ($_POST['url'] ?? ''));
            $token = trim((string) ($_POST['token'] ?? ''));
            if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                flash('Die Adresse muss mit http:// oder https:// beginnen.');
                redirect('/einrichten/verbindung');
            }
            if (connection()['source'] !== 'supervisor') {
                save_connection($url !== '' ? $url : connection()['url'], $token !== '' ? $token : null);
            }
            redirect('/einrichten/' . self::nextStep($step));
        }

        $mapping = store()->get('mapping', []);
        if (!is_array($mapping)) {
            $mapping = [];
        }
        $keys = match ($step) {
            'photovoltaik' => ['pv_power', 'pv_energy'],
            'speicher' => ['battery_soc', 'battery_mode', 'battery_charge', 'battery_discharge', 'battery_signed', 'battery_sign', 'battery_capacity', 'battery_total'],
            'netz' => ['grid_mode', 'grid_import', 'grid_export', 'grid_signed', 'grid_sign'],
            'haus' => ['house_power'],
            'wallbox' => ['wallbox_power', 'wallbox_car', 'wallbox_amps', 'wallbox_amps_max', 'wallbox_phases', 'wallbox_force', 'car_soc', 'car_capacity'],
            default => [],
        };
        if ($step === 'wetter') {
            $url = trim((string) ($_POST['weather_url'] ?? ''));
            if ($url === '') {
                $url = WeatherFeed::DEFAULT_URL;
            }
            try {
                WeatherFeed::assertUrl($url);
                store()->merge('weather', ['url' => $url]);
            } catch (Throwable $e) {
                flash($e->getMessage());
                redirect('/einrichten/wetter');
            }
            redirect('/einrichten/' . self::nextStep($step));
        }
        foreach ($keys as $key) {
            if (in_array($key, ['battery_mode', 'battery_sign', 'grid_mode', 'grid_sign', 'weather_station'], true)) {
                $mapping[$key] = (string) ($_POST[$key] ?? $mapping[$key] ?? '');
                continue;
            }
            $mapping[$key] = post_entity($key);
        }
        if ($step === 'haus') {
            $mapping['house_includes_wallbox'] = ($_POST['house_includes_wallbox'] ?? '0') === '1';
        }
        store()->put('mapping', $mapping);

        if ($step === 'pruefen') {
            $missing = self::missing($mapping);
            if ($missing) {
                flash('Noch offen: ' . implode(', ', $missing) . '.');
                redirect('/einrichten/pruefen');
            }
            store()->put('wizard_done', true);
            flash('Die Anlage ist zugeordnet.');
            redirect('/');
        }
        redirect('/einrichten/' . self::nextStep($step));
    }

    public static function saveSettings(): void
    {
        csrf_check();
        $section = (string) ($_POST['section'] ?? '');
        if ($section === 'tariffs') {
            self::saveTariff();
        } elseif ($section === 'tariff_delete') {
            $tariff = new Tariff(store());
            $from = (string) ($_POST['from'] ?? '');
            $tariff->saveHistory(Tariff::withoutEntry($tariff->history(), $from), time());
            flash('Preis ab ' . self::dateText($from) . ' gelöscht.');
        } elseif ($section === 'plant') {
            $plant = store()->get('plant', []);
            $factor = post_float('factor', 0.3, 1.8, (float) ($plant['factor'] ?? 0.93));
            $a = post_float('regress_a', -20, 20, (float) ($plant['regress_a'] ?? 0));
            $b = post_float('regress_b', -2, 3, (float) ($plant['regress_b'] ?? 1));
            store()->merge('plant', [
                'kwp' => post_float('kwp', 0.1, 100, 10),
                'inverter_kw' => post_float('inverter_kw', 0.1, 100, 10),
                'tilt' => post_float('tilt', 0, 90, 13),
                'azimuth' => post_float('azimuth', 0, 360, 270),
                'n_days' => post_int('n_days', 3, 30, 7),
                'factor' => $factor,
                'factor_locked' => !isset($_POST['unlock_factor']) && (abs($factor - (float) ($plant['factor'] ?? $factor)) > 0.0001 || !empty($plant['factor_locked'])),
                'regress_a' => $a,
                'regress_b' => $b,
                'regress_locked' => !isset($_POST['unlock_regress']) && (abs($a - (float) ($plant['regress_a'] ?? $a)) > 0.0001 || abs($b - (float) ($plant['regress_b'] ?? $b)) > 0.0001 || !empty($plant['regress_locked'])),
            ]);
            if (isset($_POST['unlock_factor'])) {
                store()->merge('plant', ['factor_locked' => false]);
            }
            if (isset($_POST['unlock_regress'])) {
                store()->merge('plant', ['regress_locked' => false]);
                store()->put('last_calibration', '');
            }
        } elseif ($section === 'battery') {
            $current = store()->get('battery_strategy', []);
            if (!is_array($current)) {
                $current = [];
            }
            $patch = [];
            if (array_key_exists('priority_soc', $_POST)) {
                $patch['priority_soc'] = post_float('priority_soc', 0, 100, (float) ($current['priority_soc'] ?? 80));
            }
            if (array_key_exists('reserve_soc', $_POST)) {
                $patch['reserve_soc'] = post_float('reserve_soc', 0, 100, (float) ($current['reserve_soc'] ?? 100));
            }
            if (array_key_exists('car_buffer_soc', $_POST)) {
                $patch['car_buffer_soc'] = post_float('car_buffer_soc', 0, 100, (float) ($current['car_buffer_soc'] ?? 100));
            }
            if (array_key_exists('car_auto_soc', $_POST)) {
                $patch['car_auto_soc'] = post_float('car_auto_soc', 0, 100, (float) ($current['car_auto_soc'] ?? 100));
            }
            if (isset($patch['priority_soc']) || isset($patch['car_buffer_soc']) || isset($patch['car_auto_soc'])) {
                $patch = array_merge($patch, self::zonesFrom($current, $patch));
            }
            if (array_key_exists('discharge_kw', $_POST)) {
                $patch['discharge_kw'] = self::optional('discharge_kw', 0.1, 50);
            }
            if ($patch) {
                store()->merge('battery_strategy', $patch);
            }
        } elseif ($section === 'reserve') {
            // Backup-Puffer: Entität und Standardwert. Der Standardwert geht gleich an den Speicher.
            $mapping = store()->get('mapping', []);
            $mapping = is_array($mapping) ? $mapping : [];
            if (array_key_exists('battery_reserve', $_POST)) {
                $mapping['battery_reserve'] = post_entity('battery_reserve');
                store()->put('mapping', $mapping);
            }
            $patch = [];
            if (array_key_exists('backup_soc', $_POST)) {
                $value = self::optional('backup_soc', 0, 100);
                $patch['backup_soc'] = $value === null ? null : (float) round($value);
            }
            store()->merge('battery_strategy', $patch);
            $reserve = new Reserve(store(), ha());
            // Fehlt jetzt Entität oder Standardwert, aber der Puffer ist noch angehoben, erst zurücksetzen.
            $snap = (new Snapshot(store(), ha()))->build();
            $reserve->sync($snap['values'], $snap['cfg'], time(), (new Sessions(store()))->open() !== null);
            flash($reserve->apply(cfg(), time()));
            self::redirectBack();
        } elseif ($section === 'car') {
            $mapping = store()->get('mapping', []);
            if (!is_array($mapping)) {
                $mapping = [];
            }
            $mapping['car_soc'] = post_entity('car_soc');
            $mapping['car_capacity'] = post_entity('car_capacity');
            store()->put('mapping', $mapping);
            $current = store()->get('battery_strategy', []);
            if (!is_array($current)) {
                $current = [];
            }
            $patch = [];
            if (array_key_exists('car_buffer_soc', $_POST)) {
                $patch['car_buffer_soc'] = post_float('car_buffer_soc', 0, 100, (float) ($current['car_buffer_soc'] ?? 100));
            }
            if (array_key_exists('car_auto_soc', $_POST)) {
                $patch['car_auto_soc'] = post_float('car_auto_soc', 0, 100, (float) ($current['car_auto_soc'] ?? 100));
            }
            if ($patch) {
                store()->merge('battery_strategy', array_merge($patch, self::zonesFrom($current, $patch)));
            }
        } elseif ($section === 'charge') {
            store()->merge('charge', self::chargePatch());
            (new Controller(store(), ha()))->kick(time());
        } elseif ($section === 'control') {
            flash(self::switchControl(($_POST['active'] ?? '0') === '1'));
            self::redirectBack();
        } elseif ($section === 'target') {
            flash(self::saveTarget());
            self::redirectBack();
        } elseif ($section === 'vehicle') {
            $mapping = store()->get('mapping', []);
            $mapping = is_array($mapping) ? $mapping : [];
            foreach (['car_soc', 'car_capacity', 'car_range', 'car_odometer', 'car_limit'] as $key) {
                if (array_key_exists($key, $_POST)) {
                    $mapping[$key] = post_entity($key);
                }
            }
            store()->put('mapping', $mapping);
            $patch = [];
            if (array_key_exists('vehicle_name', $_POST)) {
                $patch['name'] = self::name((string) $_POST['vehicle_name'], 'Auto');
            }
            if (array_key_exists('capacity_kwh', $_POST)) {
                $patch['capacity_kwh'] = self::optional('capacity_kwh', 1, 250);
            }
            if (array_key_exists('consumption_kwh', $_POST)) {
                $patch['consumption_kwh'] = self::optional('consumption_kwh', 5, 60);
            }
            if (array_key_exists('charge_kw', $_POST)) {
                $patch['charge_kw'] = self::optional('charge_kw', 1, 22);
            }
            if (array_key_exists('limit_soc', $_POST)) {
                $patch['limit_soc'] = max(20.0, snap_percent(post_float('limit_soc', 20, 100, 80)));
            }
            if ($patch) {
                self::mergeVehicle($patch);
            }
        } elseif ($section === 'chargepoint') {
            store()->merge('chargepoint', ['name' => self::name((string) ($_POST['chargepoint_name'] ?? ''), 'Wallbox')]);
        } elseif ($section === 'mapping') {
            $mapping = store()->get('mapping', []);
            if (!is_array($mapping)) {
                $mapping = [];
            }
            $enums = [
                'battery_mode' => ['split', 'signed'],
                'battery_sign' => ['positive_charge', 'positive_discharge'],
                'grid_mode' => ['split', 'signed'],
                'grid_sign' => ['positive_import', 'positive_export'],
                'weather_station' => ['soonwald', 'hahn', 'kreuznach'],
            ];
            $entities = self::ENTITY_KEYS;
            foreach ($enums as $key => $allowed) {
                $value = (string) ($_POST[$key] ?? ($mapping[$key] ?? ''));
                $mapping[$key] = in_array($value, $allowed, true) ? $value : (string) ($mapping[$key] ?? $allowed[0]);
            }
            foreach ($entities as $key) {
                if (!array_key_exists($key, $_POST)) {
                    continue;
                }
                $mapping[$key] = post_entity($key);
            }
            if (array_key_exists('house_includes_wallbox', $_POST)) {
                $mapping['house_includes_wallbox'] = $_POST['house_includes_wallbox'] === '1';
            }
            store()->put('mapping', $mapping);
        } elseif ($section === 'pvgis') {
            $check = new YearCheck(store(), ha());
            if (($_POST['do'] ?? '') === 'refresh') {
                $check->forget();
                flash(demo_mode() ? 'Im Demo-Modus rechnet EMS mit Beispielwerten.' : 'PVGIS wird neu abgefragt, gleich im Hintergrund.');
            } else {
                $lat = self::optional('lat', -90, 90);
                $lon = self::optional('lon', -180, 180);
                if (($lat === null) !== ($lon === null)) {
                    flash('Nicht gespeichert: Für einen eigenen Standort Breite und Länge eintragen, sonst beide leer lassen.');
                } else {
                    store()->merge('plant', [
                        'lat' => $lat === null ? null : round($lat, 4),
                        'lon' => $lon === null ? null : round($lon, 4),
                        'pvgis_loss' => post_float('pvgis_loss', 0, 50, YearCheck::LOSS_PCT),
                        'pvgis_year' => post_int('pvgis_year', YearCheck::FIRST_YEAR, YearCheck::LAST_YEAR, YearCheck::LAST_YEAR),
                    ]);
                    flash($lat === null ? 'Gespeichert. Der Standort kommt aus Home Assistant.' : 'Gespeichert. PVGIS rechnet für ' . YearCheck::place(['lat' => $lat, 'lon' => $lon]) . '.');
                }
            }
        } elseif ($section === 'weather') {
            $url = trim((string) ($_POST['weather_url'] ?? ''));
            try {
                WeatherFeed::assertUrl($url);
                store()->merge('weather', ['url' => $url]);
                $meta = (new WeatherFeed(store()))->refresh(true);
                flash(empty($meta['ok']) ? (string) ($meta['error'] ?? 'Die Wetterdatei konnte nicht geladen werden.') : 'Wetterdatei übernommen.');
                self::redirectBack();
            } catch (Throwable $e) {
                flash($e->getMessage());
                self::redirectBack();
            }
        } elseif ($section === 'import') {
            self::importConfig();
        } elseif ($section === 'theme') {
            $theme = (string) ($_POST['theme'] ?? 'system');
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                $theme = 'system';
            }
            store()->merge('ui', ['theme' => $theme]);
        } elseif ($section === 'flow_view') {
            $view = (string) ($_POST['flow_view'] ?? 'bar');
            store()->merge('ui', ['flow_view' => in_array($view, ['bar', 'graph'], true) ? $view : 'bar']);
        } elseif (str_starts_with($section, 'notify_')) {
            $message = self::saveNotify($section);
            if (wants_json()) {
                json_out(['ok' => true, 'message' => $message]);
            }
            flash($message);
            self::redirectBack();
        } elseif (in_array($section, ['windows', 'window_range', 'window_toggle', 'window_add', 'window_delete'], true)) {
            flash(self::saveWindows($section));
            (new Controller(store(), ha()))->kick(time());
            self::redirectBack();
        } elseif ($section === 'grid_windows') {
            flash(self::saveGridWindows());
            self::redirectBack();
        } elseif ($section === 'grid_plan') {
            flash(self::saveGridPlan());
            self::redirectBack();
        } elseif ($section === 'battery_grid') {
            flash(self::saveBatteryGrid());
            self::redirectBack();
        } elseif ($section === 'battery_boost') {
            flash(self::saveBoost());
            self::redirectBack();
        }
        flash('Gespeichert.');
        self::redirectBack();
    }

    /**
     * Unter Einstellungen → Speicher: die Entitäten für das Laden aus dem Netz, Ladeleistung, Wirkungsgrad und
     * Verschleiß. Nur gesendete Felder.
     */
    private static function saveBatteryGrid(): string
    {
        $patch = [];
        if (array_key_exists('grid_kw', $_POST)) {
            $patch['grid_kw'] = self::optional('grid_kw', 0.1, 50);
        }
        foreach (['grid_efficiency_pct' => [50, 100, 90], 'grid_wear_ct' => [0, 50, 2]] as $key => [$min, $max, $fallback]) {
            if (array_key_exists($key, $_POST)) {
                $patch[$key] = post_float($key, $min, $max, $fallback);
            }
        }
        if ($patch) {
            store()->merge('battery_strategy', $patch);
        }
        $mapping = store()->get('mapping', []);
        $mapping = is_array($mapping) ? $mapping : [];
        $mapped = false;
        foreach (['battery_operating_mode', 'battery_force_charge'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $mapping[$key] = post_entity($key);
                $mapped = true;
            }
        }
        if ($mapped) {
            store()->put('mapping', $mapping);
        }
        return 'Gespeichert.';
    }

    /**
     * Energie-Boost unter Speicher → Ausblick: aus der Empfehlung übernehmen (do=recommend), mit Fenster und Ladestand
     * setzen (do=set) oder abbrechen (do=clear). Danach gleich ein Schritt, damit ein Boost im laufenden Fenster sofort
     * startet und Abbrechen ein laufendes Laden beendet.
     */
    private static function saveBoost(): string
    {
        $now = time();
        $do = (string) ($_POST['do'] ?? 'set');
        $battery = new BatteryPlan(store(), ha());
        $snap = (new Snapshot(store(), ha()))->build();
        if ($do === 'clear') {
            store()->put(BatteryPlan::BOOST, []);
            $battery->sync($snap, $now);
            return 'Energie-Boost abgebrochen.';
        }
        if ($do === 'recommend') {
            $plan = $snap['battery_plan'] ?? null;
            if (!is_array($plan) || ($plan['reason'] ?? '') !== 'charge') {
                return 'Gerade empfiehlt EMS kein Laden aus dem Netz.';
            }
            $window = $plan['window'];
            $target = (float) $plan['target_soc'];
        } else {
            $key = (string) ($_POST['window'] ?? '');
            $window = null;
            foreach ((new PriceWindows(store()))->offered($now, 20) as $w) {
                if ($w['start'] . '-' . $w['end'] === $key) {
                    $window = $w;
                }
            }
            if ($window === null) {
                return 'Bitte ein aktives Ladefenster wählen.';
            }
            $target = (float) post_int('target_soc', 20, 100, 80);
        }
        store()->put(BatteryPlan::BOOST, ['start' => (int) $window['start'], 'end' => (int) $window['end'], 'target_soc' => round($target), 'set_at' => $now]);
        $battery->sync($snap, $now);
        try {
            $blocker = BatteryPlan::blocker(cfg(), ha()->index());
        } catch (Throwable) {
            $blocker = 'Home Assistant ist nicht erreichbar.';
        }
        return 'Energie-Boost: im Fenster ' . PriceWindows::label($window, $now) . ' bis ' . pct($target) . ' aus dem Netz laden.' . ($blocker !== '' ? ' Noch schaltet EMS nicht: ' . $blocker : '');
    }

    /**
     * Ladefenster unter Prognose → Strompreis: Länge des Vorschlags (windows), einen Tag an- oder abschalten
     * (window_toggle), ein eigenes Fenster anlegen (window_add) oder löschen (window_delete). Gibt die Rückmeldung zurück.
     */
    private static function saveWindows(string $section): string
    {
        $windows = new PriceWindows(store());
        $s = $windows->config();
        $now = time();
        if ($section === 'windows') {
            $length = (int) ($_POST['length_h'] ?? 0);
            $s['length_h'] = in_array($length, PriceWindows::LENGTHS, true) ? $length : $s['length_h'];
            $windows->save($s, $now);
            return 'Ladefenster: je Tag die günstigsten ' . $s['length_h'] . ($s['length_h'] === 1 ? ' Stunde.' : ' Stunden.');
        }
        if ($section === 'window_range') {
            $clear = !empty($_POST['clear']);
            $s['range_from'] = $clear ? null : self::optional('range_from', -100, 200);
            $s['range_to'] = $clear ? null : self::optional('range_to', -100, 200);
            $windows->save($s, $now);
            $saved = $windows->config();
            if ($saved['range_to'] === null) {
                return 'Kein Preisbereich mehr.';
            }
            return 'Preisbereich: ' . ($saved['range_from'] === null ? 'bis ' : ct($saved['range_from']) . ' bis ') . ct($saved['range_to']) . '. Jede Zeit darin wird ein Ladefenster.';
        }
        if ($section === 'window_toggle' && is_numeric($_POST['start'] ?? null)) {
            // Ein Fenster im Preisbereich, bestimmt durch seinen Beginn.
            $start = (int) $_POST['start'];
            $on = ($_POST['on'] ?? '0') === '1';
            $found = null;
            foreach ($windows->list($now) as $w) {
                if ($w['kind'] === 'range' && $w['start'] === $start) {
                    $found = $w;
                }
            }
            $s['range_off'] = array_values(array_diff($s['range_off'], [$start]));
            if (!$on) {
                $s['range_off'][] = $start;
            }
            $windows->save($s, $now);
            return 'Ladefenster ' . ($found === null ? '' : PriceWindows::label($found, $now) . ' ') . ($on ? 'ist an.' : 'ist aus.');
        }
        if ($section === 'window_toggle') {
            $day = (string) ($_POST['day'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                return 'Unbekanntes Ladefenster.';
            }
            $on = ($_POST['on'] ?? '0') === '1';
            $s['off'] = array_values(array_diff($s['off'], [$day]));
            if (!$on) {
                $s['off'][] = $day;
            }
            $windows->save($s, $now);
            $label = (string) date('d.m.', (int) strtotime($day . ' 12:00:00'));
            return 'Ladefenster am ' . $label . ($on ? ' ist an.' : ' ist aus.');
        }
        if ($section === 'window_add') {
            if (count($s['custom']) >= 12) {
                return 'Es gibt schon 12 eigene Ladefenster. Bitte erst eines löschen.';
            }
            $built = PriceWindows::customFrom((string) ($_POST['day'] ?? ''), (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), $now);
            if ($built['error'] !== null) {
                return $built['error'];
            }
            $s['custom'][] = $built['window'];
            $windows->save($s, $now);
            return 'Eigenes Ladefenster ' . PriceWindows::label($built['window'], $now) . ' angelegt.';
        }
        $start = (int) ($_POST['start'] ?? 0);
        $end = (int) ($_POST['end'] ?? 0);
        $s['custom'] = array_values(array_filter($s['custom'], static fn (array $w): bool => !($w['start'] === $start && $w['end'] === $end)));
        $windows->save($s, $now);
        return 'Eigenes Ladefenster gelöscht.';
    }

    /**
     * Einstellungen → Mitteilungen: Geräte und Ruhezeit (notify_targets), die angekreuzten Meldungen
     * (notify_events), eine Meldung mit Titel, Text und Grenzen (notify_event, mit do=test als Probe und do=reset
     * für den Standardtext) und die Test-Mitteilung ohne JavaScript (notify_test). Gibt die Rückmeldung zurück.
     */
    private static function saveNotify(string $section): string
    {
        $settings = Notify::settings(cfg());
        if ($section === 'notify_test') {
            return self::testNotification()['message'];
        }
        if ($section === 'notify_targets') {
            $settings['targets'] = Notify::targets($_POST['targets'] ?? []);
            $settings['open_app'] = ($_POST['open_app'] ?? '0') === '1';
            $settings['quiet'] = ($_POST['quiet'] ?? '0') === '1';
            $settings['quiet_from'] = Notify::clock($_POST['quiet_from'] ?? null, $settings['quiet_from']);
            $settings['quiet_to'] = Notify::clock($_POST['quiet_to'] ?? null, $settings['quiet_to']);
            store()->put(Notify::KEY, Notify::stored($settings));
            $count = count($settings['targets']);
            return $count === 0 ? 'Gespeichert. Ohne Gerät gehen keine Mitteilungen raus.' : 'Gespeichert. Mitteilungen gehen an ' . ($count === 1 ? 'ein Gerät' : $count . ' Geräte') . '.';
        }
        if ($section === 'notify_events') {
            $on = array_map('strval', (array) ($_POST['events'] ?? []));
            foreach (array_keys($settings['events']) as $key) {
                $settings['events'][$key]['on'] = in_array($key, $on, true);
            }
            store()->put(Notify::KEY, Notify::stored($settings));
            return 'Gespeichert: ' . count(array_intersect(array_keys(Notify::EVENTS), $on)) . ' von ' . count(Notify::EVENTS) . ' Meldungen an.';
        }
        if ($section === 'notify_custom_on') {
            $on = array_map('strval', (array) ($_POST['custom'] ?? []));
            foreach ($settings['custom'] as $i => $rule) {
                $settings['custom'][$i]['on'] = in_array($rule['id'], $on, true);
            }
            store()->put(Notify::KEY, Notify::stored($settings));
            return 'Gespeichert: ' . count(array_filter($settings['custom'], static fn (array $rule): bool => $rule['on'])) . ' von ' . count($settings['custom']) . ' eigenen Mitteilungen an.';
        }
        if ($section === 'notify_custom') {
            return self::saveCustomNotification($settings);
        }
        $key = (string) ($_POST['event'] ?? '');
        if ($section !== 'notify_event' || !isset(Notify::EVENTS[$key])) {
            return 'Unbekannte Meldung.';
        }
        $label = Notify::EVENTS[$key]['label'];
        $do = (string) ($_POST['do'] ?? 'save');
        if ($do === 'test') {
            return self::testNotification()['message'];
        }
        if ($do === 'reset') {
            $settings['events'][$key]['title'] = Notify::EVENTS[$key]['title'];
            $settings['events'][$key]['message'] = Notify::EVENTS[$key]['message'];
            store()->put(Notify::KEY, Notify::stored($settings));
            return 'Standardtext für „' . $label . '“ wiederhergestellt.';
        }
        $title = Notify::text($_POST['title'] ?? null, Notify::TITLE_MAX) ?? Notify::EVENTS[$key]['title'];
        $message = Notify::text($_POST['message'] ?? null, Notify::MESSAGE_MAX, true) ?? Notify::EVENTS[$key]['message'];
        $settings['events'][$key] = array_merge($settings['events'][$key], [
            'on' => ($_POST['on'] ?? '0') === '1',
            'important' => ($_POST['important'] ?? '0') === '1',
            'title' => $title,
            'message' => $message,
        ]);
        foreach (Notify::EVENTS[$key]['params'] as $param) {
            if (!array_key_exists($param, $_POST)) {
                continue;
            }
            $def = Notify::PARAMS[$param];
            $settings[$param] = $param === 'report_time'
                ? Notify::clock($_POST[$param], $settings[$param])
                : post_float($param, (float) $def['min'], (float) $def['max'], (float) $settings[$param]);
        }
        store()->put(Notify::KEY, Notify::stored($settings));
        $unknown = Notify::unknown($title . ' ' . $message);
        return '„' . $label . '“ gespeichert.' . ($unknown ? ' Unbekannte Platzhalter bleiben stehen: ' . implode(', ', array_map(static fn (string $name): string => '{' . $name . '}', $unknown)) . '.' : '');
    }

    /**
     * Eigene Mitteilung aus dem Sheet (section notify_custom, rule ist die Kennung oder new): speichern, mit do=delete
     * löschen, mit do=test als Probe senden. Die Bedingungen kommen als Listen cond_var[], cond_op[] und je nach Art
     * cond_value[] (Zahl), cond_time[] (Uhrzeit) oder cond_enum[] („var:wert“).
     */
    private static function saveCustomNotification(array $settings): string
    {
        $id = (string) ($_POST['rule'] ?? 'new');
        $do = (string) ($_POST['do'] ?? 'save');
        if ($do === 'test') {
            return self::testNotification()['message'];
        }
        $index = null;
        foreach ($settings['custom'] as $i => $rule) {
            if ($rule['id'] === $id) {
                $index = $i;
            }
        }
        if ($do === 'delete') {
            if ($index === null) {
                return 'Diese Mitteilung gibt es nicht mehr.';
            }
            $name = $settings['custom'][$index]['name'];
            array_splice($settings['custom'], $index, 1);
            store()->put(Notify::KEY, Notify::stored($settings));
            return '„' . $name . '“ gelöscht.';
        }
        if ($index === null && count($settings['custom']) >= Notify::CUSTOM_MAX) {
            return 'Nicht gespeichert: höchstens ' . Notify::CUSTOM_MAX . ' eigene Mitteilungen.';
        }
        $vars = (array) ($_POST['cond_var'] ?? []);
        $conditions = [];
        foreach ($vars as $i => $var) {
            $def = Notify::CONDITIONS[(string) $var] ?? null;
            if ($def === null) {
                continue;
            }
            $value = match ($def['type']) {
                'num' => $_POST['cond_value'][$i] ?? null,
                'time' => $_POST['cond_time'][$i] ?? null,
                'enum' => str_starts_with((string) ($_POST['cond_enum'][$i] ?? ''), $var . ':') ? substr((string) $_POST['cond_enum'][$i], strlen((string) $var) + 1) : null,
                default => null,
            };
            $conditions[] = ['var' => (string) $var, 'op' => (string) ($_POST['cond_op'][$i] ?? ''), 'value' => $value];
        }
        $rule = Notify::cleanRule([
            'id' => $index === null ? null : $id,
            'on' => ($_POST['on'] ?? '0') === '1',
            'name' => $_POST['name'] ?? null,
            'match' => $_POST['match'] ?? 'all',
            'conditions' => $conditions,
            'hold_min' => $_POST['hold_min'] ?? 0,
            'repeat' => $_POST['repeat'] ?? 'edge',
            'important' => ($_POST['important'] ?? '0') === '1',
            'title' => $_POST['title'] ?? null,
            'message' => $_POST['message'] ?? null,
        ]);
        if ($rule === null) {
            return 'Nicht gespeichert: Es braucht mindestens eine vollständige Bedingung, etwa „Speicher < 20 %“.';
        }
        if ($index === null) {
            $settings['custom'][] = $rule;
        } else {
            $settings['custom'][$index] = $rule;
        }
        store()->put(Notify::KEY, Notify::stored($settings));
        $unknown = Notify::unknown($rule['title'] . ' ' . $rule['message']);
        return '„' . $rule['name'] . '“ gespeichert.' . ($unknown ? ' Unbekannte Platzhalter bleiben stehen: ' . implode(', ', array_map(static fn (string $name): string => '{' . $name . '}', $unknown)) . '.' : '');
    }

    /**
     * Test-Mitteilung (event test), Probe einer Meldung oder einer eigenen Mitteilung (event custom) mit den Werten
     * von jetzt, an die angekreuzten oder die gespeicherten Geräte (POST /api/mitteilung und die Formulare). Der Text
     * der Test-Mitteilung bleibt gespeichert.
     *
     * @return array{ok: bool, message: string, row: ?array}
     */
    public static function testNotification(): array
    {
        $event = (string) ($_POST['event'] ?? 'test');
        $event = isset(Notify::EVENTS[$event]) || $event === 'custom' ? $event : 'test';
        $settings = Notify::settings(cfg());
        $base = match ($event) {
            'test' => $settings['test'],
            'custom' => ['title' => '{regel}', 'message' => ''],
            default => $settings['events'][$event],
        };
        $title = Notify::text($_POST['title'] ?? null, Notify::TITLE_MAX) ?? $base['title'];
        $message = Notify::text($_POST['message'] ?? null, Notify::MESSAGE_MAX, true) ?? $base['message'];
        $targets = array_key_exists('targets', $_POST) ? Notify::targets($_POST['targets']) : $settings['targets'];
        if ($targets === []) {
            return ['ok' => false, 'message' => 'Erst unter Geräte ein Handy ankreuzen.', 'row' => null];
        }
        if ($event === 'test') {
            $settings['test'] = ['title' => $title, 'message' => $message];
            store()->put(Notify::KEY, Notify::stored($settings));
        }
        $now = time();
        $notify = new Notify(store(), ha());
        $snap = (new Snapshot(store(), ha()))->build();
        if ($event === 'custom') {
            // Eigene Mitteilung: Name, Wichtig und Kennung kommen aus dem Sheet, das noch nicht gespeichert sein muss.
            $rule = (string) ($_POST['rule'] ?? '');
            $context = $notify->contextFor('custom', ['regel' => Notify::text($_POST['name'] ?? null, Notify::NAME_MAX) ?? 'Eigene Mitteilung'], $snap, $now);
            $important = in_array((string) ($_POST['important'] ?? '0'), ['1', 'true'], true);
            $logged = preg_match('/^r[a-f0-9]{8}$/', $rule) ? 'custom-' . $rule : 'custom';
        } else {
            $context = $notify->contextFor($event, Notify::sampleData($event, $now), $snap, $now);
            $important = $event !== 'test' && $settings['events'][$event]['important'];
            $logged = $event;
        }
        $result = $notify->deliver($logged, Notify::render($title, $context), Notify::render($message, $context), $settings, $important, $now, $targets, true);
        try {
            $index = ha()->index();
        } catch (Throwable) {
            $index = [];
        }
        $names = implode(', ', array_map(static fn (string $service): string => Notify::deviceName($service, $index), $result['sent']));
        $text = match (true) {
            $result['ok'] && $result['status'] === 'demo' => 'Demo-Modus: nur ins Protokoll, an ' . $names . '.',
            $result['ok'] => 'Gesendet an ' . $names . '.',
            $result['sent'] !== [] => 'Gesendet an ' . $names . ', aber nicht überall. ' . $result['error'],
            default => 'Nicht gesendet. ' . $result['error'],
        };
        return ['ok' => $result['ok'], 'message' => $text, 'row' => $notify->log(1)[0] ?? null];
    }

    public static function portable(): array
    {
        $cfg = cfg();
        return [
            // 2: seit 1.10 mit den Modi Schnell und Netzladen (netz); 3: seit 1.13 Netzladen als charge.grid. Ältere
            // Sicherungen stellt applyPortable() um.
            'version' => 3,
            'mapping' => $cfg['mapping'],
            'tariffs' => $cfg['tariffs'],
            'tariff_history' => (new Tariff(store()))->history(),
            'plant' => $cfg['plant'],
            'charge' => $cfg['charge'],
            'price_windows' => ['length_h' => (new PriceWindows(store()))->config()['length_h']],
            'battery_strategy' => $cfg['battery_strategy'],
            'vehicle' => $cfg['vehicle'],
            'chargepoint' => $cfg['chargepoint'],
            'weather' => ['url' => (new WeatherFeed(store()))->url()],
            'ui' => ['theme' => $cfg['ui']['theme'] ?? 'system', 'flow_view' => $cfg['ui']['flow_view'] ?? 'bar'],
            'notify' => Notify::stored(Notify::settings($cfg)),
            'grid_plans' => GridPlans::all(store()),
        ];
    }

    public static function importConfig(): void
    {
        csrf_check();
        $raw = '';
        if (!empty($_FILES['config_file']['tmp_name']) && is_uploaded_file($_FILES['config_file']['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES['config_file']['tmp_name']);
        }
        if (trim($raw) === '') {
            $raw = (string) ($_POST['config_json'] ?? '');
        }
        if (strlen($raw) > 262144) {
            flash('Die Konfiguration ist zu groß.');
            self::redirectBack();
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        }
        if (!is_array($data)) {
            flash('Das ist kein gültiges JSON.');
            self::redirectBack();
        }
        self::applyPortable($data);
        if (!self::missing(cfg()['mapping'])) {
            store()->put('wizard_done', true);
        }
        flash('Konfiguration übernommen.');
        $back = (string) ($_POST['back'] ?? '/einstellungen');
        if (!self::localPath($back) || (store()->get('wizard_done', false) && str_starts_with($back, '/einrichten'))) {
            $back = '/';
        }
        redirect($back);
    }

    private static function applyPortable(array $data): void
    {
        if (isset($data['mapping']) && is_array($data['mapping'])) {
            $current = store()->get('mapping', []);
            if (!is_array($current)) {
                $current = [];
            }
            $enums = [
                'battery_mode' => ['split', 'signed'],
                'battery_sign' => ['positive_charge', 'positive_discharge'],
                'grid_mode' => ['split', 'signed'],
                'grid_sign' => ['positive_import', 'positive_export'],
                'weather_station' => ['soonwald', 'hahn', 'kreuznach'],
            ];
            $entities = self::ENTITY_KEYS;
            foreach ($enums as $key => $allowed) {
                if (!array_key_exists($key, $data['mapping'])) {
                    continue;
                }
                $value = (string) $data['mapping'][$key];
                if (in_array($value, $allowed, true)) {
                    $current[$key] = $value;
                }
            }
            foreach ($entities as $key) {
                if (!array_key_exists($key, $data['mapping'])) {
                    continue;
                }
                $id = strtolower(trim((string) $data['mapping'][$key]));
                $current[$key] = $id === '' || is_entity_id($id) ? $id : ($current[$key] ?? '');
            }
            if (array_key_exists('house_includes_wallbox', $data['mapping'])) {
                $current['house_includes_wallbox'] = (bool) $data['mapping']['house_includes_wallbox'];
            }
            store()->put('mapping', $current);
        }
        if (isset($data['tariffs']) && is_array($data['tariffs'])) {
            store()->put('tariffs', Tariff::normalize($data['tariffs']));
        }
        // Tarif-Verlauf (seit 1.11); ältere Sicherungen haben nur den jetzigen Tarif, der dann seit jeher gilt.
        if (isset($data['tariff_history']) && is_array($data['tariff_history'])) {
            $history = [];
            foreach ($data['tariff_history'] as $entry) {
                if (is_array($entry) && is_string($entry['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry['from']) && is_array($entry['tariffs'] ?? null)) {
                    $history = Tariff::withEntry($history, $entry['from'], $entry['tariffs']);
                }
            }
            if ($history) {
                (new Tariff(store()))->saveHistory($history, time());
            }
        } elseif (isset($data['tariffs']) && is_array($data['tariffs'])) {
            store()->put(Tariff::HISTORY, [['from' => Tariff::ORIGIN, 'tariffs' => Tariff::normalize($data['tariffs'])]]);
        }
        if (isset($data['vehicle']) && is_array($data['vehicle'])) {
            $capacity = $data['vehicle']['capacity_kwh'] ?? null;
            $consumption = $data['vehicle']['consumption_kwh'] ?? null;
            self::mergeVehicle([
                'name' => self::name((string) ($data['vehicle']['name'] ?? ''), 'Auto'),
                'limit_soc' => max(20.0, snap_percent(self::clamped($data['vehicle']['limit_soc'] ?? null, 20, 100, 80))),
                'capacity_kwh' => is_numeric($capacity) ? clamp_float((float) $capacity, 1, 250) : null,
                'consumption_kwh' => is_numeric($consumption) ? clamp_float((float) $consumption, 5, 60) : null,
                'charge_kw' => is_numeric($data['vehicle']['charge_kw'] ?? null) ? clamp_float((float) $data['vehicle']['charge_kw'], 1, 22) : null,
            ]);
        }
        if (isset($data['grid_plans']) && is_array($data['grid_plans'])) {
            $plans = [];
            foreach (array_slice($data['grid_plans'], 0, GridPlans::MAX) as $raw) {
                $plan = is_array($raw) ? GridPlans::clean($raw, (string) ($raw['id'] ?? ''))['plan'] : null;
                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }
            store()->put(GridPlans::KEY, $plans);
        }
        if (isset($data['chargepoint']['name'])) {
            store()->merge('chargepoint', ['name' => self::name((string) $data['chargepoint']['name'], 'Wallbox')]);
        }
        if (isset($data['plant']) && is_array($data['plant'])) {
            $plant = $data['plant'];
            store()->merge('plant', [
                'kwp' => self::clamped($plant['kwp'] ?? null, 0.1, 100, 10),
                'inverter_kw' => self::clamped($plant['inverter_kw'] ?? null, 0.1, 100, 10),
                'tilt' => self::clamped($plant['tilt'] ?? null, 0, 90, 13),
                'azimuth' => self::clamped($plant['azimuth'] ?? null, 0, 360, 270),
                'n_days' => (int) round(self::clamped($plant['n_days'] ?? null, 3, 30, 7)),
                'factor' => self::clamped($plant['factor'] ?? null, 0.3, 1.8, 0.93),
                'factor_locked' => !empty($plant['factor_locked']),
                'regress_a' => self::clamped($plant['regress_a'] ?? null, -20, 20, 2.1),
                'regress_b' => self::clamped($plant['regress_b'] ?? null, -2, 3, 0.86),
                'regress_locked' => !empty($plant['regress_locked']),
                'lat' => is_numeric($plant['lat'] ?? null) && is_numeric($plant['lon'] ?? null) ? round(clamp_float((float) $plant['lat'], -90, 90), 4) : null,
                'lon' => is_numeric($plant['lat'] ?? null) && is_numeric($plant['lon'] ?? null) ? round(clamp_float((float) $plant['lon'], -180, 180), 4) : null,
                'pvgis_loss' => self::clamped($plant['pvgis_loss'] ?? null, 0, 50, YearCheck::LOSS_PCT),
                'pvgis_year' => (int) round(self::clamped($plant['pvgis_year'] ?? null, YearCheck::FIRST_YEAR, YearCheck::LAST_YEAR, YearCheck::LAST_YEAR)),
            ]);
        }
        if (isset($data['charge']) && is_array($data['charge'])) {
            $charge = $data['charge'];
            // Sicherungen vor 1.10 kennen „schnell“ noch als Netzladen, siehe Energy::migratedMode().
            if ((int) ($data['version'] ?? 1) < 2) {
                $old = is_array($data['battery_strategy'] ?? null) ? $data['battery_strategy'] : [];
                $protect = !array_key_exists('grid_protect', $old) || !empty($old['grid_protect']);
                foreach (['mode', 'after_unplug'] as $field) {
                    if (isset($charge[$field])) {
                        $charge[$field] = Energy::migratedMode($charge[$field], $protect);
                    }
                }
            }
            // Sicherungen vor 1.13 kennen Netzladen als Modus: daraus wird Intelligent mit Netzladen „Jetzt“.
            if (($charge['mode'] ?? null) === 'netz') {
                $charge['mode'] = 'smart';
                $charge['grid'] = 'now';
            }
            if (($charge['after_unplug'] ?? null) === 'netz') {
                $charge['after_unplug'] = '';
            }
            $phase = (string) ($charge['phase_mode'] ?? 'auto');
            $mode = Energy::userMode($charge['mode'] ?? 'smart');
            $last = Energy::userMode($charge['last_mode'] ?? ($mode === 'aus' ? 'smart' : $mode));
            store()->merge('charge', [
                'mode' => $mode,
                'last_mode' => $last === 'aus' ? 'smart' : $last,
                'grid' => Energy::grid($charge['grid'] ?? 'off'),
                'grid_windows' => array_key_exists('grid_windows', $charge) && $charge['grid_windows'] !== null ? (bool) $charge['grid_windows'] : null,
                'phase_mode' => in_array($phase, ['auto', '1p', '3p'], true) ? $phase : 'auto',
                'solar_share' => self::clamped($charge['solar_share'] ?? null, 0, 100, 100),
                'reserve_w' => self::clamped($charge['reserve_w'] ?? null, 0, 2000, 200),
                'min_a' => (int) round(self::clamped($charge['min_a'] ?? null, 6, 16, 6)),
                'max_a' => (int) round(self::clamped($charge['max_a'] ?? null, 6, 16, 16)),
                'switch_s' => (int) round(self::clamped($charge['switch_s'] ?? null, 60, 600, 60)),
                'on_delay_s' => (int) round(self::clamped($charge['on_delay_s'] ?? null, 60, 600, 60)),
                'off_delay_s' => (int) round(self::clamped($charge['off_delay_s'] ?? null, 60, 600, 180)),
                'then_mode' => in_array($charge['then_mode'] ?? '', Target::THEN, true) ? (string) $charge['then_mode'] : 'smart',
                'after_unplug' => in_array($charge['after_unplug'] ?? '', ['', 'aus', ...Energy::SUN_MODES], true) ? (string) $charge['after_unplug'] : '',
            ]);
        }
        if (isset($data['price_windows']['length_h'])) {
            $windows = new PriceWindows(store());
            $windows->save(['length_h' => $data['price_windows']['length_h']] + $windows->config(), time());
        }
        if (isset($data['battery_strategy']) && is_array($data['battery_strategy'])) {
            $incoming = $data['battery_strategy'];
            $strategy = [
                'priority_soc' => self::clamped($incoming['priority_soc'] ?? null, 0, 100, 80),
                'reserve_soc' => self::clamped($incoming['reserve_soc'] ?? null, 0, 100, 100),
            ];
            if (array_key_exists('car_buffer_soc', $incoming)) {
                $strategy['car_buffer_soc'] = self::clamped($incoming['car_buffer_soc'], 0, 100, 100);
            }
            if (array_key_exists('car_auto_soc', $incoming)) {
                $strategy['car_auto_soc'] = self::clamped($incoming['car_auto_soc'], 0, 100, 100);
            }
            if (array_key_exists('backup_soc', $incoming)) {
                $strategy['backup_soc'] = is_numeric($incoming['backup_soc']) ? (float) round(clamp_float((float) $incoming['backup_soc'], 0, 100)) : null;
            }
            if (array_key_exists('discharge_kw', $incoming)) {
                $strategy['discharge_kw'] = is_numeric($incoming['discharge_kw']) && (float) $incoming['discharge_kw'] > 0 ? clamp_float((float) $incoming['discharge_kw'], 0.1, 50) : null;
            }
            if (array_key_exists('grid_kw', $incoming)) {
                $strategy['grid_kw'] = is_numeric($incoming['grid_kw']) && (float) $incoming['grid_kw'] > 0 ? clamp_float((float) $incoming['grid_kw'], 0.1, 50) : null;
            }
            foreach (['grid_efficiency_pct' => [50, 100, 90], 'grid_wear_ct' => [0, 50, 2]] as $key => [$min, $max, $fallback]) {
                if (array_key_exists($key, $incoming)) {
                    $strategy[$key] = self::clamped($incoming[$key], $min, $max, $fallback);
                }
            }
            $current = store()->get('battery_strategy', []);
            if (!is_array($current)) {
                $current = [];
            }
            if (array_key_exists('priority_soc', $incoming) || array_key_exists('car_buffer_soc', $incoming) || array_key_exists('car_auto_soc', $incoming)) {
                $strategy = array_merge($strategy, self::zonesFrom($current, $strategy));
            }
            store()->merge('battery_strategy', $strategy);
        }
        if (isset($data['weather']['url'])) {
            try {
                $url = trim((string) $data['weather']['url']);
                WeatherFeed::assertUrl($url);
                store()->merge('weather', ['url' => $url]);
            } catch (Throwable) {
                // Eine fremde Adresse bleibt draußen, der Rest der Datei gilt.
            }
        }
        if (isset($data['ui']['theme'])) {
            $theme = (string) $data['ui']['theme'];
            if (in_array($theme, ['system', 'light', 'dark'], true)) {
                store()->merge('ui', ['theme' => $theme]);
            }
        }
        if (isset($data['ui']['flow_view']) && in_array($data['ui']['flow_view'], ['bar', 'graph'], true)) {
            store()->merge('ui', ['flow_view' => (string) $data['ui']['flow_view']]);
        }
        if (isset($data['notify']) && is_array($data['notify'])) {
            store()->put(Notify::KEY, Notify::clean($data['notify']));
        }
    }

    /**
     * Tarif aus Einstellungen → Tarif: beide Tarife, welcher aktiv ist, Einspeisung und CO₂, gültig ab einem Datum.
     * Gleiches Datum ersetzt den Satz, ein anderes kommt in den Verlauf; frühere Zeiten behalten ihre Preise.
     */
    private static function saveTariff(): void
    {
        $tariff = new Tariff(store());
        $now = time();
        $from = self::validFrom((string) ($_POST['valid_from'] ?? ''), $now);
        $base = Tariff::at($tariff->history(), (int) strtotime($from . ' 12:00:00 Europe/Berlin'));
        $windows = [];
        $fromTimes = (array) ($_POST['win_from'] ?? []);
        $toTimes = (array) ($_POST['win_to'] ?? []);
        $cts = (array) ($_POST['win_ct'] ?? []);
        for ($i = 0; $i < 3; $i++) {
            $windows[] = ['from' => (string) ($fromTimes[$i] ?? ''), 'to' => (string) ($toTimes[$i] ?? ''), 'ct' => str_replace(',', '.', trim((string) ($cts[$i] ?? '')))];
        }
        $next = [
            'mode' => ($_POST['mode'] ?? '') === 'dynamic' ? 'dynamic' : 'static',
            'import_ct' => post_float('import_ct', 0, 200, (float) $base['import_ct']),
            'base_eur' => self::optional('base_eur', 0, 500),
            'dyn_base_eur' => self::optional('dyn_base_eur', 0, 500),
            'dyn_grid_ct' => post_float('dyn_grid_ct', 0, 100, (float) $base['dyn_grid_ct']),
            'dyn_levies_ct' => post_float('dyn_levies_ct', 0, 100, (float) $base['dyn_levies_ct']),
            'dyn_supplier_ct' => post_float('dyn_supplier_ct', -20, 100, (float) $base['dyn_supplier_ct']),
            'dyn_vat_pct' => post_float('dyn_vat_pct', 0, 30, (float) $base['dyn_vat_pct']),
            'dyn_windows' => ($_POST['modul3'] ?? '0') === '1' ? $windows : [],
            'export_ct' => post_float('export_ct', 0, 200, (float) $base['export_ct']),
            'co2_g_kwh' => post_float('co2_g_kwh', 0, 1500, (float) $base['co2_g_kwh']),
        ];
        $tariff->saveHistory(Tariff::withEntry($tariff->history(), $from, $next), $now);
        if (array_key_exists('price_import', $_POST)) {
            store()->merge('mapping', ['price_import' => post_entity('price_import')]);
        }
        flash('Tarif gespeichert, gültig ab ' . self::dateText($from) . '.');
    }

    /** „gültig ab“ als Y-m-d; ohne gültiges Datum heute. */
    private static function validFrom(string $raw, int $now): string
    {
        $raw = trim($raw);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Europe/Berlin'));
        if ($date === false || $date->format('Y-m-d') !== $raw || $raw < '1990-01-01') {
            return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        }
        return $raw;
    }

    private static function dateText(string $day): string
    {
        return $day === Tariff::ORIGIN ? 'Beginn' : (string) date('d.m.Y', (int) strtotime($day . ' 12:00:00'));
    }

    /** Ladeparameter aus dem Formular: nur gesendete Felder, damit ein einzelner Modus die übrigen Werte behält. */
    private static function chargePatch(): array
    {
        $current = store()->get('charge', []);
        $current = is_array($current) ? $current : [];
        $patch = [];
        if (array_key_exists('mode', $_POST)) {
            $patch['mode'] = Energy::userMode((string) $_POST['mode']);
            if ($patch['mode'] !== 'aus') {
                $patch['last_mode'] = $patch['mode'];
            }
        }
        if (array_key_exists('phase_mode', $_POST)) {
            $phase = (string) $_POST['phase_mode'];
            $patch['phase_mode'] = in_array($phase, ['auto', '1p', '3p'], true) ? $phase : 'auto';
        }
        if (array_key_exists('then_mode', $_POST)) {
            $then = (string) $_POST['then_mode'];
            $patch['then_mode'] = in_array($then, Target::THEN, true) ? $then : 'smart';
        }
        if (array_key_exists('after_unplug', $_POST)) {
            $after = (string) $_POST['after_unplug'];
            $patch['after_unplug'] = in_array($after, ['', 'aus', ...Energy::SUN_MODES], true) ? $after : '';
        }

        $ranges = [
            'solar_share' => [0, 100, 100, false],
            'reserve_w' => [0, 2000, 200, false],
            'min_a' => [6, 16, 6, true],
            'max_a' => [6, 16, 16, true],
            'switch_s' => [60, 600, 60, true],
            'on_delay_s' => [60, 600, 60, true],
            'off_delay_s' => [60, 600, 180, true],
        ];
        foreach ($ranges as $key => [$min, $max, $fallback, $int]) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $patch[$key] = $int ? post_int($key, $min, $max, $fallback) : post_float($key, $min, $max, $fallback);
        }
        $minA = (int) ($patch['min_a'] ?? $current['min_a'] ?? 6);
        $maxA = (int) ($patch['max_a'] ?? $current['max_a'] ?? 16);
        if ($maxA < $minA) {
            $patch['max_a'] = $minA;
        }
        if (!empty($_POST['standard_times'])) {
            // Standardzeiten: Einschalten nach 1 min, Ausschalten nach 3 min, 60 s Beruhigung nach dem Schalten.
            $patch = ['on_delay_s' => 60, 'off_delay_s' => 180, 'switch_s' => 60] + $patch;
        }
        return $patch;
    }

    /**
     * Hauptschalter: Einschalten prüft erst die Entitäten der Wallbox und übernimmt beim nächsten Schritt ihren
     * Stand. Ausschalten gibt die Wallbox frei (frc 0) und setzt einen angehobenen Backup-Puffer zurück.
     */
    public static function switchControl(bool $active): string
    {
        $cfg = cfg();
        $controller = new Controller(store(), ha());
        $now = time();
        if ($active) {
            try {
                $problems = Controller::problems($cfg, ha()->index());
            } catch (Throwable $e) {
                $problems = ['Home Assistant ist nicht erreichbar: ' . $e->getMessage()];
            }
            if ($problems) {
                return 'EMS regelt noch nicht. ' . implode(' ', $problems);
            }
            store()->merge('control', ['active' => true]);
            $controller->resume($now);
            return 'EMS regelt jetzt die Wallbox. Kein anderer Regler darf sie in der Zeit steuern.';
        }
        store()->merge('control', ['active' => false]);
        $message = $controller->release(cfg(), $now);
        $snap = (new Snapshot(store(), ha()))->build();
        (new Reserve(store(), ha()))->sync($snap['values'], $snap['cfg'], $now, (new Sessions(store()))->open() !== null);
        // Lädt der Speicher gerade aus dem Netz, endet das jetzt; der Betriebsmodus geht zurück.
        (new BatteryPlan(store(), ha()))->sync($snap, $now);
        return $message;
    }

    /**
     * Ladeziel aus dem Sheet setzen oder löschen, für Sonne und Speicher oder (scope grid) fürs Netzladen; gibt die
     * Rückmeldung zurück.
     */
    public static function saveTarget(): string
    {
        $controller = new Controller(store(), ha());
        $scope = in_array($_POST['scope'] ?? '', ['grid', 'now'], true) ? (string) $_POST['scope'] : 'sun';
        $key = Target::key($scope);
        $name = match ($scope) {
            'grid' => 'Ziel für die Ladefenster',
            'now' => 'Netzladen „Jetzt“',
            default => 'Ladeziel',
        };
        if (!empty($_POST['clear'])) {
            store()->put($key, []);
            if ($scope === 'grid') {
                store()->put(ChargePlan::KEY, []);
            }
            if ($scope === 'now') {
                return self::stopNow();
            }
            $controller->kick(time());
            return $name . ' gelöscht.';
        }
        $snap = (new Snapshot(store(), ha()))->build();
        $built = Target::build($_POST, time(), $snap['vehicle']['soc'] ?? null, $snap['vehicle']['range_km'] ?? null, $scope);
        if ($built['error'] !== null) {
            return $built['error'];
        }
        store()->put($key, $built['target']);
        if ($scope === 'now') {
            // „Jetzt“ startet erst mit seinem Ziel.
            store()->merge('charge', ['grid' => 'now']);
            store()->put(ChargePlan::KEY, []);
            $controller->kick(time());
            $view = Target::view($built['target'], Target::inputs($snap), time());
            return 'Netzladen „Jetzt“ läuft: ' . $view['label'] . ', danach ' . (ChargePlan::afterNow(cfg()['charge']) === 'windows' ? 'weiter in den Ladefenstern.' : 'ohne Netz.');
        }
        // Mit „fertig bis“ steht der Plan gleich, nicht erst beim nächsten Recorder-Schritt.
        $plan = $scope === 'grid' ? ChargePlan::refresh(store(), $snap, time(), true) : null;
        $controller->kick(time());
        $view = Target::view($built['target'], Target::inputs($snap), time());
        $planText = ChargePlan::text($plan);
        $then = $scope === 'grid' ? ', danach ohne Netz' : ', danach ' . ($built['target']['then'] === 'aus' ? 'Laden aus' : Energy::modeLabel((string) $built['target']['then']));
        return $name . ' gesetzt: ' . $view['label'] . $then . '.' . ($planText !== '' ? ' ' . $planText : '');
    }

    /** Netzladen „Jetzt“ beenden: Ziel weg, danach gelten die Ladefenster, wenn ihr Schalter an ist. */
    public static function stopNow(): string
    {
        store()->put(Target::NOW_KEY, []);
        $after = ChargePlan::afterNow(cfg()['charge']);
        store()->merge('charge', ['grid' => $after]);
        (new Controller(store(), ha()))->kick(time());
        return $after === 'windows' ? 'Netzladen „Jetzt“ beendet, die Ladefenster gelten weiter.' : 'Netzladen „Jetzt“ beendet.';
    }

    /** Ladeplan fürs Netzladen speichern oder löschen (section grid_plan, id, do save oder delete). */
    private static function saveGridPlan(): string
    {
        $plans = GridPlans::all(store());
        $id = (string) ($_POST['id'] ?? '');
        $index = null;
        foreach ($plans as $i => $plan) {
            if ($plan['id'] === $id) {
                $index = $i;
            }
        }
        if (($_POST['do'] ?? '') === 'delete') {
            if ($index === null) {
                return 'Diesen Ladeplan gibt es nicht mehr.';
            }
            array_splice($plans, $index, 1);
            store()->put(GridPlans::KEY, $plans);
            store()->put(ChargePlan::KEY, []);
            return 'Ladeplan gelöscht.';
        }
        if ($index === null && count($plans) >= GridPlans::MAX) {
            return 'Höchstens ' . GridPlans::MAX . ' Ladepläne.';
        }
        $type = (string) ($_POST['type'] ?? 'soc');
        $built = GridPlans::clean([
            'on' => ($_POST['on'] ?? '0') === '1',
            'days' => (array) ($_POST['days'] ?? []),
            'time' => (string) ($_POST['time'] ?? ''),
            'type' => $type,
            'value' => $_POST[match ($type) { 'soc' => 'soc', 'range' => 'km', default => 'kwh' }] ?? null,
            'soc_below' => ($_POST['soc_below_on'] ?? '0') === '1' ? ($_POST['soc_below'] ?? null) : null,
            'pv_below' => ($_POST['pv_below_on'] ?? '0') === '1' ? ($_POST['pv_below'] ?? null) : null,
        ], $id);
        if ($built['error'] !== null) {
            return $built['error'];
        }
        if ($index === null) {
            $plans[] = $built['plan'];
        } else {
            $plans[$index] = $built['plan'];
        }
        store()->put(GridPlans::KEY, $plans);
        $now = time();
        // Wer einen Plan ändert, will ihn auch für die nächste Uhrzeit, selbst wenn sie schon erledigt war.
        store()->put(GridPlans::DONE, 0);
        $snap = (new Snapshot(store(), ha()))->build();
        $result = ChargePlan::refresh(store(), $snap, $now, true);
        (new Controller(store(), ha()))->kick($now);
        $next = GridPlans::next($built['plan'], $now);
        $text = 'Ladeplan gespeichert: ' . GridPlans::label($built['plan']) . ($built['plan']['on'] ? '' : ', ausgeschaltet') . '.';
        if ($built['plan']['on'] && $next !== null) {
            $text .= ' Nächstes Mal ' . day_label(date('Y-m-d', $next)) . ' ' . date('H:i', $next) . '.';
        }
        $planText = ChargePlan::text($result);
        return $planText === '' ? $text : $text . ' ' . $planText;
    }

    /** Die Fenster fürs Auto aus der Ladepunkt-Karte übernehmen; nur aktive Fenster, die noch kommen. */
    private static function saveGridWindows(): string
    {
        $now = time();
        $offered = [];
        foreach ((new PriceWindows(store()))->offered($now, 20) as $w) {
            $offered[$w['start'] . '-' . $w['end']] = ['start' => (int) $w['start'], 'end' => (int) $w['end']];
        }
        foreach (ChargePlan::chosen(store(), $now) as $w) {
            $offered[$w['start'] . '-' . $w['end']] = $w;
        }
        $chosen = [];
        foreach ((array) ($_POST['windows'] ?? []) as $key) {
            if (is_string($key) && isset($offered[$key])) {
                $chosen[] = $offered[$key];
            }
        }
        usort($chosen, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        store()->put(ChargePlan::CHOICE, ['windows' => $chosen, 'at' => $now]);
        store()->put(ChargePlan::DONE, 0);
        $snap = (new Snapshot(store(), ha()))->build();
        $plan = ChargePlan::refresh(store(), $snap, $now, true);
        (new Controller(store(), ha()))->kick($now);
        if ($chosen === []) {
            return 'Kein Fenster gewählt, das Auto lädt in keinem Ladefenster aus dem Netz.';
        }
        $text = 'Das Auto lädt aus dem Netz: ' . implode(', ', array_map(static fn (array $w): string => PriceWindows::label($w, $now), $chosen)) . '.';
        $planText = ChargePlan::text($plan);
        return $planText === '' ? $text : $text . ' ' . $planText;
    }

    /**
     * Schalter „Laden“, Modus und Netzladen aus der Ladepunkt-Karte (POST /api/modus mit mode und/oder grid). Ein Modus
     * schaltet Laden an und merkt sich als last_mode, womit der Schalter es wieder anschaltet.
     */
    public static function saveChargeMode(): void
    {
        $patch = [];
        if (array_key_exists('mode', $_POST)) {
            $mode = (string) $_POST['mode'];
            if ($mode === 'netz') {
                // Eine Seite von vor 1.13 kennt Netzladen noch als Modus.
                $current = store()->get('charge', []);
                $mode = Energy::userMode(is_array($current) ? ($current['last_mode'] ?? 'smart') : 'smart');
                $mode = $mode === 'aus' ? 'smart' : $mode;
                $patch['grid'] = 'now';
            }
            if ($mode !== 'aus' && !in_array($mode, Energy::SUN_MODES, true)) {
                json_out(['error' => 'Unbekannter Modus.'], 422);
            }
            $patch['mode'] = $mode;
            if ($mode !== 'aus') {
                $patch['last_mode'] = $mode;
            }
        }
        $charge = cfg()['charge'];
        if (array_key_exists('windows', $_POST)) {
            // Schalter „Ladefenster nutzen“: gilt dauerhaft; läuft gerade „Jetzt“, gilt er danach.
            $on = in_array((string) $_POST['windows'], ['1', 'true', 'on'], true);
            if ($on && !(new PriceWindows(store()))->enabled(time())) {
                json_out(['error' => 'Ladefenster gibt es nur mit dynamischem Tarif.'], 422);
            }
            $patch['grid_windows'] = $on;
            if (Energy::grid($charge['grid'] ?? 'off') !== 'now') {
                $patch['grid'] = $on ? 'windows' : 'off';
            }
        }
        if (array_key_exists('grid', $_POST)) {
            $grid = (string) $_POST['grid'];
            if ($grid === 'stop') {
                self::stopNow();
                return;
            }
            if (!in_array($grid, Energy::GRID, true)) {
                json_out(['error' => 'Unbekannte Auswahl für Netzladen.'], 422);
            }
            if ($grid === 'windows' && !(new PriceWindows(store()))->enabled(time())) {
                json_out(['error' => 'Ladefenster gibt es nur mit dynamischem Tarif.'], 422);
            }
            if ($grid === 'now' && Target::get(store(), 'now') === null) {
                json_out(['error' => 'Für „Jetzt“ bitte zuerst ein Ziel setzen.'], 422);
            }
            $patch['grid'] = $grid;
            if ($grid !== 'now') {
                $patch['grid_windows'] = $grid === 'windows';
            }
        }
        if ($patch === []) {
            json_out(['error' => 'Nichts zu ändern.'], 422);
        }
        store()->merge('charge', $patch);
        if (isset($patch['grid'])) {
            // Wer Netzladen neu wählt, will es wieder, auch wenn ein Ziel in diesem Ladevorgang schon erreicht war.
            store()->put(ChargePlan::DONE, 0);
        }
    }

    /** Ladelimit aus der Marke im Ladebalken (POST /api/limit), auf 5 % gerastert. */
    public static function saveLimit(): void
    {
        store()->merge('vehicle', ['limit_soc' => max(20.0, snap_percent(post_float('limit', 20, 100, 80)))]);
    }

    /** Hell, Dunkel oder System (POST /api/darstellung). */
    public static function saveTheme(): void
    {
        $theme = (string) ($_POST['theme'] ?? 'system');
        store()->merge('ui', ['theme' => in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system']);
    }

    /** Grenzen der Speicher-Säule (POST /api/grenzen). Gibt die geordneten Werte zurück. */
    public static function saveZones(): array
    {
        $current = store()->get('battery_strategy', []);
        $current = is_array($current) ? $current : [];
        $zones = zone_thresholds(
            post_float('priority_soc', 0, 100, (float) ($current['priority_soc'] ?? 80)),
            post_float('car_buffer_soc', 0, 100, (float) ($current['car_buffer_soc'] ?? 100)),
            post_float('car_auto_soc', 0, 100, (float) ($current['car_auto_soc'] ?? 100)),
        );
        store()->merge('battery_strategy', $zones);
        return $zones;
    }

    /** Speichert Fahrzeugwerte; ein neuer Name gilt auch für die bisherigen Vorgänge des Recorders. */
    private static function mergeVehicle(array $patch): void
    {
        $before = (string) (cfg()['vehicle']['name'] ?? 'Auto');
        store()->merge('vehicle', $patch);
        if (isset($patch['name'])) {
            (new Sessions(store()))->renameVehicle($before, (string) $patch['name']);
        }
    }

    private static function name(string $value, string $fallback): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return $value === '' ? $fallback : mb_substr($value, 0, 40);
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $overlay */
    private static function zonesFrom(array $current, array $overlay): array
    {
        $merged = array_merge($current, $overlay);

        return zone_thresholds(
            (float) ($merged['priority_soc'] ?? 80),
            (float) ($merged['car_buffer_soc'] ?? 100),
            (float) ($merged['car_auto_soc'] ?? 100),
        );
    }

    /** Zahl aus dem Formular, leer oder ungültig wird null (etwa „unbekannt“). */
    private static function optional(string $key, float $min, float $max): ?float
    {
        $raw = str_replace(',', '.', trim((string) ($_POST[$key] ?? '')));
        return $raw === '' || !is_numeric($raw) ? null : clamp_float((float) $raw, $min, $max);
    }

    private static function clamped(mixed $value, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        return clamp_float((float) $value, $min, $max);
    }

    private static function redirectBack(): never
    {
        $back = (string) ($_POST['back'] ?? '/einstellungen');
        if (!self::localPath($back)) {
            $back = '/einstellungen';
        }
        redirect($back);
    }

    /** Nur Pfade dieser App, kein //andere-seite.de. */
    private static function localPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\');
    }
}
