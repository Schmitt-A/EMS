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
        } elseif ($section === 'theme') {
            $theme = (string) ($_POST['theme'] ?? 'system');
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                $theme = 'system';
            }
            store()->merge('ui', ['theme' => $theme]);
        }
        flash('Gespeichert.');
        $back = (string) ($_POST['back'] ?? '/einstellungen');
        if (!str_starts_with($back, '/')) {
            $back = '/einstellungen';
        }
        redirect($back);
    }
}
