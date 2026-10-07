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
        'house_power' => 'sensor.sonnenbatterie_382994_state_consumption_current',
        'grid_import' => 'sensor.sonnenbatterie_382994_state_grid_in',
        'grid_export' => 'sensor.sonnenbatterie_382994_state_grid_out',
        'battery_soc' => 'sensor.sonnenbatterie_382994_state_battery_percentage_user',
        'battery_charge' => 'sensor.sonnenbatterie_382994_state_battery_in',
        'battery_discharge' => 'sensor.sonnenbatterie_382994_state_battery_out',
        'battery_capacity' => 'sensor.sonnenbatterie_382994_battery_remaining_capacity_usable',
        'wallbox_power' => 'sensor.go_echarger_506181_power_total',
        'wallbox_car' => 'sensor.go_echarger_506181_car',
        'wallbox_amps' => 'number.go_echarger_506181_amp',
        'wallbox_amps_max' => 'number.go_echarger_506181_ama',
        'wallbox_phases' => 'select.go_echarger_506181_psm',
        'wallbox_force' => 'select.go_echarger_506181_frc',
        'weather_radiation' => 'sensor.soonwald_west_4_sonneneinstrahlung',
        'weather_cloud' => 'sensor.soonwald_west_4_bewolkungsgrad',
        'weather_sunshine' => 'sensor.soonwald_west_4_sonnenscheindauer',
        'weather_temp' => 'sensor.soonwald_west_4_temperatur',
    ];

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
            'speicher' => ['battery_soc', 'battery_mode', 'battery_charge', 'battery_discharge', 'battery_signed', 'battery_sign', 'battery_capacity'],
            'netz' => ['grid_mode', 'grid_import', 'grid_export', 'grid_signed', 'grid_sign'],
            'haus' => ['house_power'],
            'wallbox' => ['wallbox_power', 'wallbox_car', 'wallbox_amps', 'wallbox_amps_max', 'wallbox_phases', 'wallbox_force'],
            'wetter' => ['weather_station', 'weather_radiation', 'weather_cloud', 'weather_sunshine', 'weather_temp'],
            default => [],
        };
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
            store()->merge('tariffs', [
                'import_ct' => post_float('import_ct', 0, 200, 34.7),
                'export_ct' => post_float('export_ct', 0, 200, 11),
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
            store()->merge('battery_strategy', [
                'priority_soc' => post_float('priority_soc', 0, 100, 80),
                'reserve_soc' => post_float('reserve_soc', 0, 100, 100),
            ]);
        } elseif ($section === 'charge') {
            $min = post_int('min_a', 6, 32, 6);
            $max = post_int('max_a', 6, 32, 16);
            if ($max < $min) {
                $max = $min;
            }
            $mode = (string) ($_POST['mode'] ?? 'smart');
            if (!in_array($mode, ['aus', 'smart', 'smart_dauerhaft', 'schnell'], true)) {
                $mode = 'smart';
            }
            $phase = (string) ($_POST['phase_mode'] ?? 'auto');
            if (!in_array($phase, ['auto', '1p', '3p'], true)) {
                $phase = 'auto';
            }
            store()->merge('charge', [
                'mode' => $mode,
                'phase_mode' => $phase,
                'solar_share' => post_float('solar_share', 0, 100, 100),
                'reserve_w' => post_float('reserve_w', 0, 2000, 200),
                'min_a' => $min,
                'max_a' => $max,
                'switch_s' => post_int('switch_s', 60, 600, 60),
                'on_delay_s' => post_int('on_delay_s', 60, 600, 60),
                'off_delay_s' => post_int('off_delay_s', 60, 600, 60),
            ]);
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
            $entities = ['pv_power', 'pv_energy', 'battery_soc', 'battery_charge', 'battery_discharge', 'battery_signed', 'battery_capacity', 'grid_import', 'grid_export', 'grid_signed', 'house_power', 'wallbox_power', 'wallbox_car', 'wallbox_amps', 'wallbox_amps_max', 'wallbox_phases', 'wallbox_force', 'weather_radiation', 'weather_cloud', 'weather_sunshine', 'weather_temp'];
            foreach ($enums as $key => $allowed) {
                $value = (string) ($_POST[$key] ?? ($mapping[$key] ?? ''));
                $mapping[$key] = in_array($value, $allowed, true) ? $value : (string) ($mapping[$key] ?? $allowed[0]);
            }
            foreach ($entities as $key) {
                $mapping[$key] = post_entity($key);
            }
            $mapping['house_includes_wallbox'] = ($_POST['house_includes_wallbox'] ?? '0') === '1';
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
        }
        flash('Gespeichert.');
        self::redirectBack();
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
            'weather' => ['url' => (new WeatherFeed(store()))->url()],
            'ui' => ['theme' => $cfg['ui']['theme'] ?? 'system'],
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
        if (!str_starts_with($back, '/') || (store()->get('wizard_done', false) && str_starts_with($back, '/einrichten'))) {
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
            $entities = ['pv_power', 'pv_energy', 'battery_soc', 'battery_charge', 'battery_discharge', 'battery_signed', 'battery_capacity', 'grid_import', 'grid_export', 'grid_signed', 'house_power', 'wallbox_power', 'wallbox_car', 'wallbox_amps', 'wallbox_amps_max', 'wallbox_phases', 'wallbox_force', 'weather_radiation', 'weather_cloud', 'weather_sunshine', 'weather_temp'];
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
            ]);
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
                'min_a' => (int) round(self::clamped($charge['min_a'] ?? null, 6, 32, 6)),
                'max_a' => (int) round(self::clamped($charge['max_a'] ?? null, 6, 32, 16)),
                'switch_s' => (int) round(self::clamped($charge['switch_s'] ?? null, 60, 600, 60)),
                'on_delay_s' => (int) round(self::clamped($charge['on_delay_s'] ?? null, 60, 600, 60)),
                'off_delay_s' => (int) round(self::clamped($charge['off_delay_s'] ?? null, 60, 600, 60)),
            ]);
        }
        if (isset($data['battery_strategy']) && is_array($data['battery_strategy'])) {
            store()->merge('battery_strategy', [
                'priority_soc' => self::clamped($data['battery_strategy']['priority_soc'] ?? null, 0, 100, 80),
                'reserve_soc' => self::clamped($data['battery_strategy']['reserve_soc'] ?? null, 0, 100, 100),
            ]);
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
        if (!str_starts_with($back, '/')) {
            $back = '/einstellungen';
        }
        redirect($back);
    }
}
