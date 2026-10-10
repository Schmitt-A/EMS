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
        'car_soc', 'car_capacity', 'car_range', 'car_odometer', 'car_limit', 'car_wakeup', 'grid_import', 'grid_export', 'grid_signed', 'house_power',
        'wallbox_power', 'wallbox_car', 'wallbox_amps', 'wallbox_amps_max', 'wallbox_phases', 'wallbox_force', 'wallbox_energy',
    ];

    /**
     * Muster für Entitäten, die nicht in SUGGEST stehen: der Backup-Puffer der sonnenBatterie und die Sensoren von
     * ESPHome Tesla BLE (englisch und mit deutschem Sprachpaket). [Zuordnung => [Muster für die ID, Einheiten]]
     */
    private const PATTERNS = [
        'battery_reserve' => ['/^number\.\w*(battery_reserve|backup_buffer|backup_reserve)$/', ['%']],
        'car_soc' => ['/^sensor\.\w*tesla\w*_(charge_level|battery_level|ladezustand(_\d)?)$/', ['%']],
        'car_range' => ['/^sensor\.\w*tesla\w*_(range|battery_range|reichweite)$/', ['km', 'mi']],
        'car_odometer' => ['/^sensor\.\w*tesla\w*_(odometer|kilometerstand)$/', ['km', 'mi']],
        'car_limit' => ['/^sensor\.\w*tesla\w*_(charge_limit|ladelimit)$/', ['%']],
        'car_wakeup' => ['/^button\.\w*tesla\w*_(wake_up|wake|aufwecken|wecken)$/', ['']],
        'wallbox_energy' => ['/^sensor\.go_echarger_\w+_wh$/', ['kwh', 'wh']],
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
            $current = store()->get('tariffs', []);
            store()->merge('tariffs', [
                'import_ct' => post_float('import_ct', 0, 200, 34.7),
                'export_ct' => post_float('export_ct', 0, 200, 11),
                'co2_g_kwh' => post_float('co2_g_kwh', 0, 1500, (float) ($current['co2_g_kwh'] ?? 380)),
            ]);
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
            // Backup-Puffer: Entität, Standardwert und Schonen beim Netzladen. Der Standardwert geht gleich an den Speicher.
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
            if (array_key_exists('grid_protect', $_POST)) {
                $patch['grid_protect'] = $_POST['grid_protect'] === '1';
            }
            store()->merge('battery_strategy', $patch);
            $reserve = new Reserve(store(), ha());
            // Ist das Schonen jetzt aus, aber der Puffer noch angehoben, erst zurücksetzen, dann den Standardwert setzen.
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
        }
        flash('Gespeichert.');
        self::redirectBack();
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
     * Test-Mitteilung (event test) oder Probe einer Meldung mit den Werten von jetzt, an die angekreuzten oder die
     * gespeicherten Geräte (POST /api/mitteilung und die Formulare). Der Text der Test-Mitteilung bleibt gespeichert.
     *
     * @return array{ok: bool, message: string, row: ?array}
     */
    public static function testNotification(): array
    {
        $event = (string) ($_POST['event'] ?? 'test');
        $event = isset(Notify::EVENTS[$event]) ? $event : 'test';
        $settings = Notify::settings(cfg());
        $base = $event === 'test' ? $settings['test'] : $settings['events'][$event];
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
        $context = $notify->contextFor($event, Notify::sampleData($event, $now), $snap, $now);
        $important = $event !== 'test' && $settings['events'][$event]['important'];
        $result = $notify->deliver($event, Notify::render($title, $context), Notify::render($message, $context), $settings, $important, $now, $targets, true);
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
            'version' => 1,
            'mapping' => $cfg['mapping'],
            'tariffs' => $cfg['tariffs'],
            'plant' => $cfg['plant'],
            'charge' => $cfg['charge'],
            'battery_strategy' => $cfg['battery_strategy'],
            'vehicle' => $cfg['vehicle'],
            'chargepoint' => $cfg['chargepoint'],
            'weather' => ['url' => (new WeatherFeed(store()))->url()],
            'ui' => ['theme' => $cfg['ui']['theme'] ?? 'system', 'flow_view' => $cfg['ui']['flow_view'] ?? 'bar'],
            'notify' => Notify::stored(Notify::settings($cfg)),
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
            store()->merge('tariffs', [
                'import_ct' => self::clamped($data['tariffs']['import_ct'] ?? null, 0, 200, 34.7),
                'export_ct' => self::clamped($data['tariffs']['export_ct'] ?? null, 0, 200, 11),
                'co2_g_kwh' => self::clamped($data['tariffs']['co2_g_kwh'] ?? null, 0, 1500, 380),
            ]);
        }
        if (isset($data['vehicle']) && is_array($data['vehicle'])) {
            $capacity = $data['vehicle']['capacity_kwh'] ?? null;
            self::mergeVehicle([
                'name' => self::name((string) ($data['vehicle']['name'] ?? ''), 'Auto'),
                'limit_soc' => max(20.0, snap_percent(self::clamped($data['vehicle']['limit_soc'] ?? null, 20, 100, 80))),
                'capacity_kwh' => is_numeric($capacity) ? clamp_float((float) $capacity, 1, 250) : null,
            ]);
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
            ]);
        }
        if (isset($data['charge']) && is_array($data['charge'])) {
            $charge = $data['charge'];
            $mode = (string) ($charge['mode'] ?? 'smart');
            $phase = (string) ($charge['phase_mode'] ?? 'auto');
            store()->merge('charge', [
                'mode' => in_array($mode, ['aus', 'smart', 'smart_dauerhaft', 'schnell'], true) ? $mode : 'smart',
                'phase_mode' => in_array($phase, ['auto', '1p', '3p'], true) ? $phase : 'auto',
                'solar_share' => self::clamped($charge['solar_share'] ?? null, 0, 100, 100),
                'reserve_w' => self::clamped($charge['reserve_w'] ?? null, 0, 2000, 200),
                'min_a' => (int) round(self::clamped($charge['min_a'] ?? null, 6, 16, 6)),
                'max_a' => (int) round(self::clamped($charge['max_a'] ?? null, 6, 16, 16)),
                'switch_s' => (int) round(self::clamped($charge['switch_s'] ?? null, 60, 600, 60)),
                'on_delay_s' => (int) round(self::clamped($charge['on_delay_s'] ?? null, 60, 600, 60)),
                'off_delay_s' => (int) round(self::clamped($charge['off_delay_s'] ?? null, 60, 600, 180)),
                'then_mode' => in_array($charge['then_mode'] ?? '', Target::THEN, true) ? (string) $charge['then_mode'] : 'smart',
                'after_unplug' => in_array($charge['after_unplug'] ?? '', ['', 'aus', 'smart', 'smart_dauerhaft', 'schnell'], true) ? (string) $charge['after_unplug'] : '',
            ]);
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
            if (array_key_exists('grid_protect', $incoming)) {
                $strategy['grid_protect'] = (bool) $incoming['grid_protect'];
            }
            if (array_key_exists('discharge_kw', $incoming)) {
                $strategy['discharge_kw'] = is_numeric($incoming['discharge_kw']) && (float) $incoming['discharge_kw'] > 0 ? clamp_float((float) $incoming['discharge_kw'], 0.1, 50) : null;
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

    /** Ladeparameter aus dem Formular: nur gesendete Felder, damit ein einzelner Modus die übrigen Werte behält. */
    private static function chargePatch(): array
    {
        $current = store()->get('charge', []);
        $current = is_array($current) ? $current : [];
        $patch = [];
        if (array_key_exists('mode', $_POST)) {
            $mode = (string) $_POST['mode'];
            $patch['mode'] = in_array($mode, ['aus', 'smart', 'smart_dauerhaft', 'schnell'], true) ? $mode : 'smart';
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
            $patch['after_unplug'] = in_array($after, ['', 'aus', 'smart', 'smart_dauerhaft', 'schnell'], true) ? $after : '';
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
        if (!empty($_POST['evcc_defaults'])) {
            // Wie evcc: Einschalten nach 1 min, Ausschalten nach 3 min, 60 s Beruhigung nach dem Schalten.
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
            return 'EMS regelt jetzt die Wallbox. evcc darf sie in der Zeit nicht steuern.';
        }
        store()->merge('control', ['active' => false]);
        $message = $controller->release(cfg(), $now);
        $snap = (new Snapshot(store(), ha()))->build();
        (new Reserve(store(), ha()))->sync($snap['values'], $snap['cfg'], $now, (new Sessions(store()))->open() !== null);
        return $message;
    }

    /** Ladeziel aus dem Sheet setzen oder löschen; gibt die Rückmeldung zurück. */
    public static function saveTarget(): string
    {
        $controller = new Controller(store(), ha());
        if (!empty($_POST['clear'])) {
            store()->put(Target::KEY, []);
            $controller->kick(time());
            return 'Ladeziel gelöscht.';
        }
        $snap = (new Snapshot(store(), ha()))->build();
        $built = Target::build($_POST, time(), $snap['vehicle']['soc'] ?? null);
        if ($built['error'] !== null) {
            return $built['error'];
        }
        store()->put(Target::KEY, $built['target']);
        $controller->kick(time());
        $view = Target::view($built['target'], Target::inputs($snap), time());
        return 'Ladeziel gesetzt: ' . $view['label'] . ', danach ' . Energy::modeLabel((string) $built['target']['then']) . '.';
    }

    /** Modus aus dem Segment-Umschalter (POST /api/modus). */
    public static function saveChargeMode(): void
    {
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, ['aus', 'smart', 'smart_dauerhaft', 'schnell'], true)) {
            json_out(['error' => 'Unbekannter Modus.'], 422);
        }
        store()->merge('charge', ['mode' => $mode]);
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
