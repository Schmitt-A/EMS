<?php
declare(strict_types=1);

final class Snapshot
{
    public function __construct(
        private ConfigStore $store,
        private HaSource $ha,
    ) {}

    public function build(): array
    {
        $cfg = $this->store->all();
        $now = time();
        $base = [
            'connected' => false,
            'error' => null,
            'fetched_at' => date('H:i:s', $now),
            'cfg' => $cfg,
            'missing' => [],
            'warnings' => [],
            'values' => [],
        ];
        if (!$this->ha->configured()) {
            $base['error'] = 'Home Assistant ist noch nicht verbunden.';
            return $this->finish($base, $cfg, $now);
        }
        try {
            $index = $this->ha->index();
        } catch (Throwable $e) {
            $base['error'] = $e->getMessage();
            return $this->finish($base, $cfg, $now);
        }
        $base['connected'] = true;
        $map = (new Series($this->store, $this->ha))->adoptBatteryTotal($cfg['mapping'], $index);
        $cfg['mapping'] = $map;
        $base['cfg'] = $cfg;
        $read = function (string $key) use ($index, $map, &$base): ?array {
            $id = (string) ($map[$key] ?? '');
            if ($id === '') {
                return null;
            }
            if (!isset($index[$id])) {
                $base['missing'][] = $key;
                $base['warnings'][] = $id . ' ist nicht erreichbar.';
                return null;
            }
            $row = $index[$id];
            $state = (string) ($row['state'] ?? '');
            if (in_array($state, ['unavailable', 'unknown', ''], true)) {
                $base['missing'][] = $key;
                $base['warnings'][] = ($row['attributes']['friendly_name'] ?? $id) . ' hat keinen Messwert.';
                return ['num' => null, 'state' => $state, 'unit' => $row['attributes']['unit_of_measurement'] ?? '', 'name' => $row['attributes']['friendly_name'] ?? $id, 'attributes' => $row['attributes'] ?? []];
            }
            return [
                'num' => is_numeric($state) ? (float) $state : null,
                'state' => $state,
                'unit' => $row['attributes']['unit_of_measurement'] ?? '',
                'name' => $row['attributes']['friendly_name'] ?? $id,
                'attributes' => $row['attributes'] ?? [],
            ];
        };

        $pvRow = $read('pv_power');
        $pv = $this->kw($pvRow, $base);
        $charge = $discharge = null;
        if (($map['battery_mode'] ?? 'split') === 'signed') {
            [$signed] = $this->kw($read('battery_signed'), $base);
            [$charge, $discharge] = Energy::splitSigned($signed, (string) ($map['battery_sign'] ?? 'positive_charge'));
        } else {
            $charge = $this->kw($read('battery_charge'), $base)[0];
            $discharge = $this->kw($read('battery_discharge'), $base)[0];
        }
        $import = $export = null;
        if (($map['grid_mode'] ?? 'split') === 'signed') {
            [$signed] = $this->kw($read('grid_signed'), $base);
            [$import, $export] = Energy::splitSigned($signed, (string) ($map['grid_sign'] ?? 'positive_import'));
        } else {
            $import = $this->kw($read('grid_import'), $base)[0];
            $export = $this->kw($read('grid_export'), $base)[0];
        }
        $houseRow = $read('house_power');
        $house = $this->kw($houseRow, $base)[0];
        $wallboxRow = $read('wallbox_power');
        $wallbox = $this->kw($wallboxRow, $base)[0];
        $socRow = $read('battery_soc');
        $soc = $socRow['num'] ?? null;
        $capRow = $read('battery_capacity');
        $cap = Energy::energyToKwh(is_array($capRow) ? $capRow['num'] : null, is_array($capRow) ? ($capRow['unit'] ?? null) : null);
        if ($cap[1]) {
            $base['warnings'][] = $cap[1];
        }
        $totalRow = $read('battery_total');
        $total = Energy::energyToKwh(is_array($totalRow) ? $totalRow['num'] : null, is_array($totalRow) ? ($totalRow['unit'] ?? null) : null);
        if ($total[1]) {
            $base['warnings'][] = $total[1];
        }
        $carSocRow = $read('car_soc');
        $carCapRow = $read('car_capacity');
        $carRangeRow = $read('car_range');
        $carCap = Energy::energyToKwh(is_array($carCapRow) ? $carCapRow['num'] : null, is_array($carCapRow) ? ($carCapRow['unit'] ?? null) : null);
        if ($carCap[1]) {
            $base['warnings'][] = $carCap[1];
        }
        // Reichweite und Kilometerstand kommen von Tesla BLE oft in Meilen.
        $distance = function (?array $row) use (&$base): ?float {
            [$km, $warn] = Energy::distanceKm(is_array($row) ? $row['num'] : null, is_array($row) ? ($row['unit'] ?? null) : null);
            if ($warn) {
                $base['warnings'][] = ($row['name'] ?? 'Sensor') . ': ' . $warn;
            }
            return $km;
        };
        $carLimitRow = $read('car_limit');
        $reserveRow = $read('battery_reserve');
        $car = $read('wallbox_car');
        $amps = $read('wallbox_amps');
        $phases = $read('wallbox_phases');
        $force = $read('wallbox_force');
        $energyRow = $read('wallbox_energy');
        $sessionEnergy = Energy::energyToKwh(is_array($energyRow) ? $energyRow['num'] : null, is_array($energyRow) ? ($energyRow['unit'] ?? null) : null);

        $values = [
            'pv_kw' => $pv[0],
            'battery_soc' => $soc,
            'battery_charge_kw' => $charge,
            'battery_discharge_kw' => $discharge,
            'battery_capacity_kwh' => $cap[0],
            'battery_total_kwh' => $total[0],
            'car_soc' => is_array($carSocRow) ? ($carSocRow['num'] ?? null) : null,
            'car_capacity_kwh' => $carCap[0],
            'car_range_km' => $distance($carRangeRow),
            'car_odometer_km' => $distance($read('car_odometer')),
            'car_limit_soc' => is_array($carLimitRow) && $carLimitRow['num'] !== null ? clamp_float((float) $carLimitRow['num'], 0, 100) : null,
            'battery_reserve_soc' => is_array($reserveRow) ? ($reserveRow['num'] ?? null) : null,
            'grid_import_kw' => $import,
            'grid_export_kw' => $export,
            'house_kw' => $house,
            'house_includes_wallbox' => (bool) ($map['house_includes_wallbox'] ?? true),
            'wallbox_kw' => $wallbox,
            'wallbox_car_raw' => $car['state'] ?? null,
            'wallbox_amps' => $amps['num'] ?? null,
            'wallbox_phases_raw' => $phases['state'] ?? null,
            'wallbox_force_raw' => $force['state'] ?? null,
            'wallbox_force_options' => (array) ($force['attributes']['options'] ?? []),
            'wallbox_phases_options' => (array) ($phases['attributes']['options'] ?? []),
            'wallbox_session_kwh' => $sessionEnergy[0],
            'priority_soc' => (float) $cfg['battery_strategy']['priority_soc'],
            'radiation' => null,
            'radiation_rows' => [],
            'cloud' => null,
            'sunshine' => null,
            'sunshine_unit' => '',
            'temp' => null,
        ];
        $base['values'] = $values;
        $base['names'] = [
            'pv' => is_array($pvRow) ? ($pvRow['name'] ?? '') : '',
            'soc' => is_array($socRow) ? ($socRow['name'] ?? '') : '',
            'house' => is_array($houseRow) ? ($houseRow['name'] ?? '') : '',
            'wallbox' => is_array($wallboxRow) ? ($wallboxRow['name'] ?? '') : '',
        ];
        return $this->finish($base, $cfg, $now);
    }

    private function kw(?array $row, array &$base): array
    {
        if ($row === null) {
            return [null, null];
        }
        [$kw, $warn] = Energy::powerToKw($row['num'], $row['unit'] ?? null);
        if ($warn) {
            $base['warnings'][] = ($row['name'] ?? 'Sensor') . ': ' . $warn;
        }
        return [$kw, $warn];
    }

    private function finish(array $base, array $cfg, int $now): array
    {
        $values = array_merge([
            'pv_kw' => null, 'battery_soc' => null, 'battery_charge_kw' => null, 'battery_discharge_kw' => null,
            'battery_capacity_kwh' => null, 'battery_total_kwh' => null, 'car_soc' => null, 'car_capacity_kwh' => null, 'car_range_km' => null,
            'car_odometer_km' => null, 'car_limit_soc' => null, 'battery_reserve_soc' => null,
            'grid_import_kw' => null, 'grid_export_kw' => null, 'house_kw' => null,
            'house_includes_wallbox' => true, 'wallbox_kw' => null, 'wallbox_amps' => null, 'wallbox_car_raw' => null,
            'wallbox_phases_raw' => null, 'wallbox_force_raw' => null, 'wallbox_force_options' => [], 'wallbox_phases_options' => [], 'wallbox_session_kwh' => null, 'priority_soc' => 80, 'radiation_rows' => [],
        ], $base['values'] ?: []);
        $base['values'] = $values;
        $balance = Energy::balance($values);
        $live = array_merge($values, $balance);
        $strategy = zone_thresholds(
            (float) ($cfg['battery_strategy']['priority_soc'] ?? 80),
            (float) ($cfg['battery_strategy']['car_buffer_soc'] ?? 100),
            (float) ($cfg['battery_strategy']['car_auto_soc'] ?? 100),
        );
        $reported = Energy::reportedPhases($values['wallbox_phases_raw'] ?? null);
        // Backup-Puffer, Schonen beim Netzladen und Entladeleistung: woher dem Auto fehlende Leistung käme.
        $battery = Reserve::battery($cfg);
        $suggestion = Energy::suggest($live, $cfg['charge'], $reported, $strategy, $battery);
        // Was die drei Modi jetzt täten, für die Regelung unter der Ladepunkt-Karte.
        $modes = [];
        foreach (['smart', 'smart_dauerhaft', 'schnell'] as $mode) {
            $modes[$mode] = $mode === $suggestion['mode'] ? $suggestion : Energy::suggest($live, ['mode' => $mode] + $cfg['charge'], $reported, $strategy, $battery);
        }
        // Regelung: der Zustand, den der Recorder alle 30 s fortschreibt. Ist er nicht frisch oder gilt schon ein
        // anderer Modus, rechnet die Anzeige einen Schritt voraus, ohne ihn zu speichern.
        $controller = (new Controller($this->store, $this->ha))->state();
        $controlIn = Controller::input($values, $cfg, $suggestion);
        if ($controller['step_at'] === null || $now - (int) $controller['step_at'] > 90 || $controller['mode'] !== $controlIn['mode']) {
            $controller = Controller::step($controller, $controlIn, $now)['state'];
        }
        $decision = Controller::decision($controller, $controlIn, $now);
        $latched = [
            'mode' => $suggestion['mode'],
            'zone' => $suggestion['zone'],
            'latched_amps' => $decision['amps'],
            'latched_phases' => $decision['phases'],
            'latched_kw' => $decision['kw'],
            'target_kw' => $suggestion['target_kw'],
            'p_soll_kw' => $suggestion['p_soll_kw'],
            'delta_kw' => $suggestion['delta_kw'],
            'timer' => $decision['timer'],
            'guard_s' => $decision['guard_s'],
            'note' => $decision['note'],
            'min_a' => $controlIn['min_a'],
        ];
        $heartbeat = (int) $this->store->get('control_heartbeat', 0);
        $ems = [
            'active' => Controller::active($cfg),
            'paused' => $controller['paused'],
            // Ohne Recorder steht die Regelung; im Demo-Modus übernimmt /api/live den Takt.
            'stale' => Controller::active($cfg) && !demo_mode() && $now - $heartbeat > 60,
            'log' => array_map(static fn (array $row): array => ['time' => date('d.m. H:i', (int) $row['t']), 'text' => (string) $row['text']], array_slice((array) $controller['log'], 0, 6)),
        ];
        $feed = new WeatherFeed($this->store);
        if ($feed->stale()) {
            $meta = $feed->refresh();
            if (empty($meta['ok']) && !empty($meta['error'])) {
                $base['warnings'][] = (string) $meta['error'];
            }
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $todayStart = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
        $hours = $feed->hours($todayStart - 14 * 86400, $now + 12 * 86400);
        $series = Forecast::fromRadiation($hours, $cfg['plant']);
        $issue = (int) ($feed->meta()['issue'] ?? 0);
        if ($issue > 0 && $hours) {
            Forecast::rememberIssue($this->store->pdo(), $hours, $cfg['plant'], $issue);
        }
        if ($series) {
            Forecast::rememberDays($this->store->pdo(), $series, $cfg['plant']);
        }
        $mean = null;
        try {
            $mean = (new Series($this->store, $this->ha))->houseMean($cfg['mapping']);
        } catch (Throwable) {
            $mean = null;
        }
        $bufferSoc = (float) ($cfg['battery_strategy']['car_buffer_soc'] ?? 100);
        $outlook = Forecast::storageOutlook(
            $series,
            $now,
            isset($values['battery_soc']) ? (float) $values['battery_soc'] : null,
            isset($values['battery_capacity_kwh']) ? (float) $values['battery_capacity_kwh'] : null,
            $mean,
            (float) ($values['priority_soc'] ?? 80),
            isset($values['battery_total_kwh']) ? (float) $values['battery_total_kwh'] : null,
            $bufferSoc
        );
        if ($mean === null) {
            $outlook['house_missing'] = true;
            $outlook['surplus_kwh'] = null;
            if (empty($outlook['already_full'])) {
                $outlook['full_at'] = null;
                $outlook['reachable'] = false;
            }
            if (empty($outlook['priority_reached'])) {
                $outlook['priority_at'] = null;
            }
            if (empty($outlook['buffer_reached'])) {
                $outlook['buffer_at'] = null;
            }
        }
        $charging = ((float) ($values['wallbox_kw'] ?? 0) > 0.05)
            || strtolower((string) ($values['wallbox_car_raw'] ?? '')) === 'charging';
        $actualToday = null;
        try {
            $actualToday = (new Series($this->store, $this->ha))->yieldToday($cfg['mapping']);
        } catch (Throwable) {
            $actualToday = null;
        }
        $todayKey = (new DateTimeImmutable('@' . $todayStart))->setTimezone($tz)->format('Y-m-d');
        $lockedToday = Forecast::locked($this->store->pdo(), $todayKey);
        $brief = $series ? Forecast::brief($series, $cfg['plant'], $now, $actualToday, $lockedToday) : null;
        if (is_array($brief)) {
            $captions = Forecast::captions($this->store->pdo(), $cfg['plant']);
            if (isset($captions[$todayKey]['kwh'])) {
                $brief['today_kwh'] = round((float) $captions[$todayKey]['kwh'], 2);
                $brief['today_sd'] = isset($captions[$todayKey]['sd']) ? round((float) $captions[$todayKey]['sd'], 2) : null;
            }
            $tomorrowKey = (new DateTimeImmutable('@' . $todayStart))->setTimezone($tz)->modify('+1 day')->format('Y-m-d');
            if (isset($captions[$tomorrowKey]['kwh'])) {
                $brief['tomorrow_kwh'] = round((float) $captions[$tomorrowKey]['kwh'], 2);
                $brief['tomorrow_sd'] = isset($captions[$tomorrowKey]['sd']) ? round((float) $captions[$tomorrowKey]['sd'], 2) : null;
            }
        }
        $sessions = new Sessions($this->store);
        $session = $sessions->open() ?: $sessions->latest();
        $base['balance'] = $balance;
        $base['setpoint'] = $latched;
        $base['suggestion'] = $suggestion;
        $base['ems'] = $ems;
        if ($ems['paused']) {
            $base['warnings'][] = (string) $ems['paused'];
        }
        if ($ems['stale']) {
            $base['warnings'][] = 'EMS soll regeln, aber der Recorder läuft nicht. Die Wallbox bleibt, wie sie ist.';
        }
        $base['forecast'] = $brief;
        $base['yield_today'] = $actualToday;
        $base['storage'] = $outlook;
        $base['house_mean_kw'] = $mean;
        $base['car_charging'] = $charging;
        $base['car_buffer_soc'] = $bufferSoc;
        $base['house_known'] = $balance['house_base_kw'] !== null;
        $base['session'] = $session;
        $base['plug_group'] = is_array($session) && !empty($session['plug_at']) ? $sessions->group((int) $session['id']) : null;
        $base['car_label'] = Energy::carLabel($values['wallbox_car_raw'] ?? null);
        $base['phase_label'] = Energy::phaseLabel($values['wallbox_phases_raw'] ?? null);
        $base['activity'] = Energy::activity($values['battery_charge_kw'] ?? null, $values['battery_discharge_kw'] ?? null);
        $base['vehicle'] = $this->vehicleView($base, $cfg);
        $base['chargepoint'] = $this->chargepointView($base, $cfg);
        $base['reserve'] = (new Reserve($this->store, $this->ha))->view($cfg, isset($values['battery_reserve_soc']) ? (float) $values['battery_reserve_soc'] : null);
        if ($base['reserve']['error']) {
            $base['warnings'][] = 'Backup-Puffer: ' . $base['reserve']['error'];
        }
        $base['target'] = Target::view(Target::get($this->store), Target::inputs($base), $now);
        if ($base['target'] !== null && $base['target']['remaining_s'] !== null && !$base['target']['reached']) {
            // Mit Ziel zählt die Restzeit bis zum Ziel, nicht bis zum Limit.
            $base['chargepoint']['remaining_s'] = $base['target']['remaining_s'];
            $base['chargepoint']['remaining_text'] = duration_clock((int) $base['target']['remaining_s']);
        }
        $base['session_view'] = self::sessionView($base, $cfg['tariffs'], $now);
        $base['control'] = self::control($live, $cfg['charge'], $strategy, $latched, $modes, $battery, $base['reserve'], $ems);
        $base['warnings'] = array_values(array_unique($base['warnings']));
        return $base;
    }

    public function livePayload(array $snap): array
    {
        $v = $snap['values'];
        $b = $snap['balance'];
        $f = $snap['forecast'];
        $flow = match ($snap['activity'] ?? '') {
            'Laden' => 'laden',
            'Entladen' => 'entladen',
            default => 'ruhe',
        };
        return [
            'connected' => $snap['connected'],
            'error' => $snap['error'],
            'fetched_at' => $snap['fetched_at'],
            'ts' => time(),
            // Regelung unter der Ladepunkt-Karte
            'control' => $snap['control'],
            // Backup-Puffer: Satz für den Speicher, angehoben beim Netzladen
            'reserve' => ['text' => $snap['reserve']['text'], 'raised' => $snap['reserve']['raised'], 'value' => $snap['reserve']['value']],
            // Ladeziel und Übersicht des laufenden Ladevorgangs in der Ladepunkt-Karte
            'target' => $snap['target'] === null ? ['active' => false] : ['active' => true] + $snap['target'] + ['remaining_text' => $snap['target']['text']],
            'session_view' => $snap['session_view'],
            // Erklärliste des Speichers
            'house_mean' => kw($snap['house_mean_kw'] ?? null),
            'battery_full' => $this->fullText($snap['storage'] ?? []),
            'battery_priority' => $this->priorityText($snap['storage'] ?? [], (float) ($v['priority_soc'] ?? 80)),
            'battery_buffer' => $this->bufferText($snap['storage'] ?? [], (float) ($snap['car_buffer_soc'] ?? 100)),
            'battery_surplus' => $this->surplusText($snap['storage'] ?? [], isset($snap['house_mean_kw']) && $snap['house_mean_kw'] !== null, $snap['house_mean_kw'] ?? null),
            'remaining_kwh' => $f['remaining_kwh'] ?? null,
            'flow' => Energy::flowBar($v, $b),
            'flow_graph' => Energy::flowGraph($v, $b, self::flowForecast($snap)),
            'flow_rows' => self::flowRows($v, $b, $f),
            'chargepoint' => $snap['chargepoint'],
            'vehicle' => $snap['vehicle'],
            'battery' => [
                'soc' => isset($v['battery_soc']) ? round((float) $v['battery_soc'], 1) : null,
                'flow' => $flow,
                'soc_text' => pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null),
                'stored_text' => self::storedText($v['battery_capacity_kwh'] ?? null, $v['battery_total_kwh'] ?? null),
                'activity_text' => match ($flow) {
                    'laden' => 'Lädt mit ' . kw($v['battery_charge_kw'] ?? null) . '.',
                    'entladen' => 'Entlädt mit ' . kw($v['battery_discharge_kw'] ?? null) . '.',
                    default => $snap['connected'] ? 'Ruht.' : '',
                },
            ],
            'say' => self::sayText($snap),
        ];
    }

    /**
     * Fahrzeug unter der Ladepunkt-Karte: Name, Status, Ladestand, Reichweite, Kilometerstand und Limit. Meldet das
     * Auto sein Ladelimit (Tesla BLE), gilt das statt des Reglers; die Kapazität kann von Hand kommen.
     */
    private function vehicleView(array $base, array $cfg): array
    {
        $v = $base['values'];
        $raw = strtolower(str_replace([' ', '-'], '_', (string) ($v['wallbox_car_raw'] ?? '')));
        $soc = $v['car_soc'] !== null ? (float) $v['car_soc'] : null;
        $fromCar = $v['car_limit_soc'] !== null;
        $limit = $fromCar ? (float) $v['car_limit_soc'] : (float) ($cfg['vehicle']['limit_soc'] ?? 80);
        $range = $v['car_range_km'] !== null ? round((float) $v['car_range_km']) : null;
        $capacity = $v['car_capacity_kwh'] ?? (is_numeric($cfg['vehicle']['capacity_kwh'] ?? null) && (float) $cfg['vehicle']['capacity_kwh'] > 0 ? (float) $cfg['vehicle']['capacity_kwh'] : null);
        return [
            'name' => trim((string) ($cfg['vehicle']['name'] ?? '')) ?: 'Auto',
            'status' => self::vehicleStatus($raw, (bool) $base['car_charging']),
            'connected' => (bool) $base['car_charging'] || in_array($raw, ['charging', 'wait_car', 'waitcar', 'complete', 'completed'], true),
            'soc' => $soc === null ? null : round($soc, 1),
            'capacity_kwh' => $capacity,
            'range_km' => $range,
            'odometer_km' => $v['car_odometer_km'] !== null ? round((float) $v['car_odometer_km']) : null,
            'has_odometer' => (string) ($cfg['mapping']['car_odometer'] ?? '') !== '',
            'limit' => $limit,
            'limit_from_car' => $fromCar,
            'range_at_limit' => Energy::rangeAt($range, $soc, $limit),
        ];
    }

    public static function vehicleStatus(string $raw, bool $charging): string
    {
        if ($charging || $raw === 'charging') {
            return 'Lädt …';
        }
        return match ($raw) {
            'wait_car', 'waitcar' => 'Verbunden',
            'complete', 'completed' => 'Bereit, Ladung beendet',
            'error' => 'Fehler an der Wallbox',
            'initializing' => 'Wallbox startet …',
            'idle' => 'Nicht verbunden',
            '', 'unknown' => 'Kein Fahrzeugstatus',
            default => $raw,
        };
    }

    /** Ladepunkt-Karte: Leistung, aktive Phasen, Sitzung, Restzeit und Vorschlag. Geschrieben wird nichts. */
    private function chargepointView(array $base, array $cfg): array
    {
        $v = $base['values'];
        $setpoint = $base['setpoint'];
        $charging = (bool) $base['car_charging'];
        $vehicle = $base['vehicle'];
        $session = $base['session'] ?? null;
        $open = is_array($session) && empty($session['ended_at']);
        $power = $v['wallbox_kw'] === null ? null : (float) $v['wallbox_kw'];
        $phases = $charging ? (Energy::reportedPhases($v['wallbox_phases_raw'] ?? null) ?? (int) ($setpoint['latched_phases'] ?? 1)) : 0;
        $energy = null;
        if ($vehicle['connected'] && $v['wallbox_session_kwh'] !== null) {
            // Meldet die Wallbox die Lademenge seit dem Anstecken (go-e „wh“), gilt ihr Zähler.
            $energy = round((float) $v['wallbox_session_kwh'], 3);
        } elseif ($open || ($vehicle['connected'] && is_array($session))) {
            // Geladen seit dem Anstecken: alle Zyklen dieses Ladevorgangs.
            $energy = round((float) ($base['plug_group']['energy_kwh'] ?? $session['energy_kwh']), 3);
        } elseif ($power !== null) {
            $energy = 0.0;
        }
        $remaining = $charging ? Energy::timeToLimit($vehicle['soc'], $vehicle['limit'], $vehicle['capacity_kwh'], $power) : null;
        return [
            'name' => trim((string) ($cfg['chargepoint']['name'] ?? '')) ?: 'Wallbox',
            'mode' => (string) ($cfg['charge']['mode'] ?? 'smart'),
            'charging' => $charging,
            'solar_only' => $charging && (float) ($v['grid_import_kw'] ?? 0) < 0.05 && (float) ($v['battery_discharge_kw'] ?? 0) < 0.05,
            'power_kw' => $power === null ? null : round($power, 3),
            'phases' => $phases,
            'session_kwh' => $energy,
            'session_s' => $open ? (int) $session['duration_s'] : null,
            'remaining_s' => $remaining,
            'remaining_text' => $remaining === null ? '—' : duration_clock($remaining),
        ];
    }

    /**
     * Übersicht des Ladevorgangs, solange er läuft oder das Auto noch steckt: seit wann, wie lange geladen,
     * Energie, Ø Leistung, Sonnenanteil und Kosten bisher.
     */
    public static function sessionView(array $base, array $tariffs, int $now): array
    {
        $session = $base['session'] ?? null;
        $group = $base['plug_group'] ?? null;
        $open = is_array($session) && empty($session['ended_at']);
        if (!$open && !(!empty($base['vehicle']['connected']) && is_array($session))) {
            return ['open' => false];
        }
        $row = is_array($group) ? $group : $session;
        $energy = (float) $row['energy_kwh'];
        $duration = (int) $row['duration_s'];
        $start = (int) strtotime((string) (($row['plug_at'] ?? '') ?: $row['started_at']));
        $cost = Sessions::cost($row, $tariffs);
        return [
            'open' => true,
            'since' => 'seit ' . date('H:i', $start),
            'duration' => duration_clock($duration),
            'kwh' => kwh($energy),
            'avg' => $duration > 60 ? kw($energy / ($duration / 3600)) : '—',
            'solar' => pct((float) $cost['solar_pct']),
            'cost' => euro((float) $cost['cost']),
        ];
    }

    /**
     * Regelung unter der Ladepunkt-Karte: mit welcher Stufe die Wallbox jetzt laden würde (verriegelt, mit
     * anstehendem Wechsel), woher die Leistung käme und wohin der übrige Überschuss ginge, was die drei Modi
     * täten, die Phasen-Leiter und der Rechenweg. Geschrieben wird nichts.
     *
     * @param array $live Messwerte und Bilanz
     * @param array $strategy geordnete Grenzen aus zone_thresholds()
     * @param array $setpoint Stand der Regelung aus Controller::decision() mit Zielwerten von Energy::suggest()
     * @param array<string, array> $modes Vorschläge für smart, smart_dauerhaft und schnell
     * @param array $battery Backup-Puffer, Schonen und Entladeleistung aus Reserve::battery()
     * @param array $reserve Stand des Puffers aus Reserve::view()
     * @param array $ems Hauptschalter: active, paused, stale, log
     */
    public static function control(array $live, array $charge, array $strategy, array $setpoint, array $modes, array $battery = [], array $reserve = [], array $ems = []): array
    {
        $active = (string) ($setpoint['mode'] ?? ($charge['mode'] ?? 'smart'));
        $zone = (string) ($setpoint['zone'] ?? 'none');
        $amps = (int) ($setpoint['latched_amps'] ?? 0);
        $phases = (int) ($setpoint['latched_phases'] ?? 1);
        $kw = (float) ($setpoint['latched_kw'] ?? 0);
        $flows = Energy::allot($kw, $active, $live, Energy::batteryFor($active, $battery));
        $minA = min(16, max(6, (int) ($charge['min_a'] ?? 6)));
        $maxA = min(16, max($minA, (int) ($charge['max_a'] ?? 16)));
        $phaseMode = (string) ($charge['phase_mode'] ?? 'auto');
        $maxKw = Energy::KW_PER_AMP * $maxA * ($phaseMode === '1p' ? 1 : 3);
        $scale = max($maxKw, (float) $flows['spare_kw'], ...array_values(array_map(static fn (array $m): float => (float) ($m['offered_kw'] ?? 0), $modes)));
        $rows = [];
        foreach ($modes as $key => $mode) {
            $rows[$key] = [
                'label' => Energy::modeLabel($key),
                'active' => $key === $active,
                'kw' => round((float) $mode['offered_kw'], 3),
                'kw_text' => (int) $mode['amps'] === 0 ? 'aus' : kw((float) $mode['offered_kw']),
                // Ohne Strom keine Stufe, damit „aus“ nicht zweimal dasteht.
                'level_text' => (int) $mode['amps'] === 0 ? '' : self::levelText((int) $mode['amps'], (int) $mode['phases']),
                'text' => self::decisionText($key, $mode, $strategy, !empty(Energy::batteryFor($key, $battery)['protect'])),
                'flows' => $mode['flows'],
            ];
        }
        $soc = isset($live['battery_soc']) ? (float) $live['battery_soc'] : null;
        $in = (float) ($live['battery_charge_kw'] ?? 0);
        $out = (float) ($live['battery_discharge_kw'] ?? 0);
        $batteryText = $soc === null ? 'ohne Ladestand' : pct($soc) . ($in > 0.05 ? ', lädt ' . kw($in) : ($out > 0.05 ? ', entlädt ' . kw($out) : ', ruht'));
        $own = Energy::batteryFor($active, $battery);
        $target = $setpoint['target_kw'] ?? null;
        return [
            'mode' => $active,
            'mode_label' => Energy::modeLabel($active),
            'zone' => $zone,
            'amps' => $amps,
            'phases' => $amps === 0 ? 0 : $phases,
            'kw' => round($kw, 3),
            // Der Modus steht schon im Kopf; ohne Ladung zeigt die große Zahl 0 kW und keine Stufe.
            'kw_text' => kw($amps === 0 ? 0.0 : $kw),
            'headline' => self::headline($amps, $ems),
            'level_text' => $amps === 0 ? '' : self::levelText($amps, $phases),
            'reason' => self::zoneText($active, $zone, $strategy, $soc, $minA, $reserve),
            'pending' => self::pendingText($setpoint),
            // Hauptschalter: regelt EMS die Wallbox oder zeigt es nur an?
            'ems_label' => !empty($ems['active']) ? (!empty($ems['paused']) ? 'pausiert' : 'regelt') : 'nur Anzeige',
            'ems_active' => !empty($ems['active']) && empty($ems['paused']),
            'log' => $ems['log'] ?? [],
            'flows' => $flows,
            'car_text' => kw((float) $flows['car_kw']),
            'split_text' => self::splitText($flows, 'Auto: ', !empty($own['protect']) ? 'Der Speicher bleibt geschont.' : 'Speicher lädt nicht.'),
            'scale_kw' => round($scale, 3),
            'modes' => $rows,
            'ladder' => [
                'level_kw' => round($kw, 3),
                'level_phases' => $amps === 0 ? 0 : $phases,
                'sun_kw' => round(max(0.0, (float) ($modes['smart']['solar_kw'] ?? 0)), 3),
                'max_kw' => round(Energy::KW_PER_AMP * $maxA * ($phaseMode === '1p' ? 1 : 3), 3),
            ],
            'details' => [
                'pv' => kw($live['pv_kw'] ?? null),
                'house' => kw($live['house_base_kw'] ?? null),
                'spare' => kw((float) $flows['spare_kw']),
                'battery' => $batteryText,
                'solar' => kw($modes['smart']['solar_kw'] ?? null, 2),
                'meter' => kw($setpoint['p_soll_kw'] ?? null, 2),
                'target' => $target === null ? '—' : kw((float) $target, 2) . ' → ' . ($amps === 0 ? 'aus' : self::levelText($amps, $phases) . ' = ' . kw($kw, 2)),
                'delta' => kw($setpoint['delta_kw'] ?? null, 2),
                'cover' => self::coverText($own, $soc),
            ],
        ];
    }

    /** Wer deckt, was dem Auto an Sonne fehlt: der Speicher bis zum Backup-Puffer, dann das Netz. */
    public static function coverText(array $battery, ?float $soc): string
    {
        $floor = isset($battery['reserve_soc']) ? (float) $battery['reserve_soc'] : null;
        $max = isset($battery['max_discharge_kw']) && (float) $battery['max_discharge_kw'] > 0 ? (float) $battery['max_discharge_kw'] : null;
        return match (true) {
            !empty($battery['protect']) => 'Das Netz, der Speicher bleibt geschont.',
            $soc === null => 'Das Netz, ohne Ladestand des Speichers.',
            $soc <= ($floor ?? 0) + 0.5 => 'Das Netz, der Speicher steht am Backup-Puffer von ' . pct($floor ?? 0) . '.',
            default => 'Der Speicher' . ($floor !== null ? ' bis ' . pct($floor) : '') . ($max !== null ? ' mit höchstens ' . kw($max) : '') . ', dann das Netz.',
        };
    }

    /** Stufe als „6 A · 3-phasig“, „aus“ ohne Strom. */
    public static function levelText(int $amps, int $phases): string
    {
        return $amps === 0 ? 'aus' : $amps . NNBSP . 'A · ' . $phases . '-phasig';
    }

    /** Warum der aktive Modus so entscheidet, aus Sicht der Speicherzone; beim Netzladen, was der Puffer tut. */
    public static function zoneText(string $mode, string $zone, array $strategy, ?float $soc, int $minA, array $reserve = []): string
    {
        if ($mode === 'aus') {
            return 'Die Wallbox bliebe aus, der Überschuss ginge in den Speicher und danach ins Netz.';
        }
        if ($mode === 'schnell') {
            return match (true) {
                !empty($reserve['raised']) => 'Volle Leistung aus Sonne und Netz. ' . (string) $reserve['text'],
                !empty($reserve['active']) => 'Volle Leistung aus Sonne und Netz. Lädt das Auto, hebt die App den Backup-Puffer auf den Ladestand, damit der Speicher geschont bleibt.',
                default => 'Volle Leistung. Was die Sonne nicht schafft, deckt zuerst der Speicher bis zu seinem Backup-Puffer, dann das Netz. Schonen lässt er sich unter Einstellungen → Speicher.',
            };
        }
        $p = pct((float) $strategy['priority_soc']);
        $b = pct((float) $strategy['car_buffer_soc']);
        $a = pct((float) $strategy['car_auto_soc']);
        $now = $soc === null ? '' : pct($soc);
        $text = match ($zone) {
            'house' => 'Der Speicher liegt mit ' . $now . ' unter der Hausgrenze von ' . $p . ' und lädt zuerst. Das Auto bekommt, was übrig bleibt.',
            'car' => 'Der Speicher liegt mit ' . $now . ' über der Hausgrenze von ' . $p . '. Das Auto hat den Überschuss, der Speicher bekommt den Rest.',
            'boost' => 'Der Speicher liegt mit ' . $now . ' über ' . $b . ' und darf eine laufende Ladung stützen.',
            'start' => 'Der Speicher liegt mit ' . $now . ' über ' . $a . '. Die Ladung startet auch ohne Sonne und endet bei ' . $b . '.',
            default => 'Ohne Ladestand des Speichers zählt nur der Überschuss am Zähler.',
        };
        return $mode === 'smart_dauerhaft' ? $text . ' Mindestens ' . $minA . NNBSP . 'A bleiben an.' : $text;
    }

    /** Anstehender Wechsel der Verriegelung, leer ohne Wechsel. */
    public static function pendingText(array $setpoint): string
    {
        $timer = $setpoint['timer'] ?? null;
        $phases = (int) ($setpoint['latched_phases'] ?? 1);
        if (is_array($timer)) {
            $left = (int) $timer['remaining_s'];
            $in = $left > 0 ? 'in ' . self::wait($left) : 'gleich';
            return match ((string) $timer['action']) {
                'enable' => 'Startet ' . $in . ' mit ' . self::levelText((int) ($setpoint['min_a'] ?? 6), $phases) . ' (Einschaltverzögerung).',
                'disable' => 'Stoppt ' . $in . ' (Ausschaltverzögerung), bis dahin mit dem Mindeststrom.',
                'scale3p' => 'Schaltet ' . $in . ' auf drei Phasen.',
                'scale1p' => 'Schaltet ' . $in . ' auf eine Phase.',
                default => '',
            };
        }
        return match ($setpoint['note'] ?? null) {
            'guard' => 'Wartet die Schütz-Schutzzeit ab, noch ' . self::wait((int) ($setpoint['guard_s'] ?? 0)) . '.',
            'no_values' => 'Ohne Zählerwerte schaltet die Regelung nichts.',
            'offline' => 'Die Wallbox meldet keinen Zustand, die Regelung wartet.',
            default => '',
        };
    }

    /** Wartezeit als „45 s“ oder „2:10 min“. */
    public static function wait(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return $seconds < 60 ? $seconds . NNBSP . 's' : intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . NNBSP . 'min';
    }

    /** Überschrift über der Stufe: was EMS einstellt oder, nur angezeigt, einstellen würde. */
    public static function headline(int $amps, array $ems): string
    {
        if (!empty($ems['active']) && empty($ems['paused'])) {
            return $amps === 0 ? 'Wallbox gesperrt' : 'Regelt auf';
        }
        return $amps === 0 ? 'Würde jetzt nicht laden' : 'Würde jetzt laden mit';
    }

    /**
     * Ein Satz zur Aufteilung: woher das Auto die Leistung bekäme und was der Speicher täte. $idle steht da,
     * wenn der Speicher weder lädt noch ans Auto abgibt.
     */
    public static function splitText(array $flows, string $lead = 'Auto: ', string $idle = 'Speicher lädt nicht.'): string
    {
        $from = [];
        foreach (['sun_kw' => 'aus der Sonne', 'battery_kw' => 'aus dem Speicher', 'grid_kw' => 'aus dem Netz', 'mixed_kw' => 'aus Speicher und Netz'] as $key => $where) {
            if ((float) $flows[$key] >= 0.05) {
                $from[] = kw((float) $flows[$key]) . ' ' . $where;
            }
        }
        $text = $from ? $lead . implode(', ', $from) . '.' : 'Das Auto bekäme nichts.';
        if ((float) $flows['charge_kw'] >= 0.05) {
            $text .= ' Speicher lädt ' . kw((float) $flows['charge_kw']) . '.';
        } elseif ((float) $flows['battery_kw'] + (float) $flows['mixed_kw'] < 0.05) {
            $text .= ' ' . $idle;
        }
        if ((float) $flows['export_kw'] >= 0.05) {
            $text .= ' ' . kw((float) $flows['export_kw']) . ' gehen ins Netz.';
        }
        return $text;
    }

    /** Entscheidung eines Modus in einem Satz; $protect: Netzladen schont den Speicher. */
    public static function decisionText(string $key, array $mode, array $strategy, bool $protect = false): string
    {
        if ((int) $mode['amps'] === 0) {
            $solar = (float) ($mode['solar_kw'] ?? 0);
            return match ((string) ($mode['zone'] ?? '')) {
                'house' => 'Lädt nicht: Der Speicher unter ' . pct((float) $strategy['priority_soc']) . ' nimmt den Überschuss' . ($solar >= 0.05 ? ', fürs Auto blieben ' . kw($solar) : '') . '.',
                'boost' => 'Lädt nicht: Ohne genug Sonne startet keine neue Ladung' . ($solar >= 0.05 ? ', ' . kw($solar) . ' reichen nicht' : '') . '.',
                default => 'Lädt nicht: ' . ($solar >= 0.05 ? kw($solar) . ' Überschuss reichen nicht für die kleinste Stufe.' : 'Kein Überschuss fürs Auto.'),
            };
        }
        return self::splitText($mode['flows'], '', $protect ? 'Der Speicher bleibt geschont.' : 'Speicher lädt nicht.');
    }

    /** Prognose für den Energie-Flow: ganzer Tag, Rest und heute gemessen. */
    public static function flowForecast(array $snap): ?array
    {
        $f = $snap['forecast'] ?? null;
        if (!is_array($f)) {
            return null;
        }
        return ['today_kwh' => $f['today_kwh'] ?? null, 'remaining_kwh' => $f['remaining_kwh'] ?? null, 'done_kwh' => $snap['yield_today'] ?? null];
    }

    /** Leistungen für die Detail-Liste „Rein“ und „Raus“ unter dem Energiefluss-Balken, dazu die Prognose für heute. */
    public static function flowRows(array $v, array $b, ?array $f = null): array
    {
        $kw = static fn (mixed $value): ?float => $value === null ? null : round(max(0.0, (float) $value), 3);
        return [
            'in_kw' => $kw($b['in_kw'] ?? null),
            'out_kw' => $kw($b['out_kw'] ?? null),
            'in' => ['pv' => $kw($v['pv_kw'] ?? null), 'battery' => $kw($v['battery_discharge_kw'] ?? null), 'grid' => $kw($v['grid_import_kw'] ?? null)],
            'out' => ['house' => $kw($b['house_base_kw'] ?? null), 'wallbox' => $kw($v['wallbox_kw'] ?? null), 'battery' => $kw($v['battery_charge_kw'] ?? null), 'grid' => $kw($v['grid_export_kw'] ?? null)],
            'forecast_value' => self::forecastValue($f),
            'forecast_text' => self::forecastRest($f),
        ];
    }

    /** Prognose-Zeile im Energiefluss: der ganze Tag laut Prognose. */
    public static function forecastValue(?array $f): string
    {
        $day = $f['today_kwh'] ?? null;
        return $day === null ? '—' : kwh((float) $day);
    }

    /** Darunter, was die Prognose für den Rest des Tages noch erwartet. */
    public static function forecastRest(?array $f): string
    {
        $rest = $f['remaining_kwh'] ?? null;
        if ($rest === null) {
            return $f === null ? 'Noch keine Prognose' : 'Rest offen';
        }
        return 'Rest ' . kwh((float) $rest);
    }

    public static function storedText(?float $stored, ?float $total): string
    {
        if ($stored === null) {
            return $total !== null ? 'Gesamt ' . kwh($total) : 'Kapazität unbekannt';
        }
        return $total !== null && $total > 0 ? num($stored, 1) . ' von ' . kwh($total) : kwh($stored);
    }

    /** Kurze Ansage für Screenreader, höchstens alle 30 s. */
    public static function sayText(array $snap): string
    {
        if (empty($snap['connected'])) {
            return 'Keine Verbindung zu Home Assistant.';
        }
        $v = $snap['values'];
        $parts = ['PV ' . kw($v['pv_kw'] ?? null), 'Haus ' . kw($snap['balance']['house_base_kw'] ?? null)];
        if (($v['wallbox_kw'] ?? 0) > 0.05) {
            $parts[] = 'Auto ' . kw($v['wallbox_kw']);
        }
        if (isset($v['battery_soc'])) {
            $parts[] = 'Speicher ' . pct((float) $v['battery_soc']);
        }
        return implode(', ', $parts) . '.';
    }

    /** @param array{already_full?:bool, reachable?:bool, full_at?:?int, house_missing?:bool} $storage */
    private function fullText(array $storage): string
    {
        if (!empty($storage['already_full'])) {
            return 'Batterie ist voll.';
        }
        if (!empty($storage['house_missing'])) {
            return '100 % bleibt offen, bis der Hausverbrauch der letzten 30 Tage da ist.';
        }
        if (empty($storage['reachable'])) {
            return 'Zeit bis 100 % braucht Ladestand und nutzbare Energie.';
        }
        if (empty($storage['full_at'])) {
            return '100 % in den nächsten vier Tagen mit diesem Überschuss nicht erreicht.';
        }
        return '100 % voraussichtlich ' . when_label((int) $storage['full_at']) . '.';
    }

    /** @param array{priority_open?:bool, priority_reached?:bool, reachable?:bool, priority_at?:?int, house_missing?:bool} $storage */
    private function priorityText(array $storage, float $priority): string
    {
        if (empty($storage['priority_open'])) {
            return '';
        }
        $mark = num($priority, 0) . NNBSP . '%';
        if (!empty($storage['priority_reached'])) {
            return 'Speicher-Vorrang ' . $mark . ' ist erreicht.';
        }
        if (!empty($storage['house_missing'])) {
            return 'Speicher-Vorrang ' . $mark . ' bleibt offen, bis der Hausverbrauch der letzten 30 Tage da ist.';
        }
        if (empty($storage['reachable'])) {
            return '';
        }
        if (empty($storage['priority_at'])) {
            return 'Speicher-Vorrang ' . $mark . ' in den nächsten vier Tagen nicht erreicht.';
        }
        return 'Speicher-Vorrang ' . $mark . ' voraussichtlich ' . when_label((int) $storage['priority_at']) . '.';
    }

    /** @param array{buffer_open?:bool, buffer_reached?:bool, reachable?:bool, buffer_at?:?int, house_missing?:bool} $storage */
    private function bufferText(array $storage, float $buffer): string
    {
        $mark = num($buffer, 0) . NNBSP . '%';
        if ($buffer >= 99.5 || empty($storage['buffer_open'])) {
            return 'Batteriegestütztes Laden ist bei ' . $mark . '. Der Hausspeicher bleibt fürs Haus.';
        }
        if (!empty($storage['buffer_reached'])) {
            return 'Batteriegestütztes Laden ab ' . $mark . ' ist erreicht.';
        }
        if (!empty($storage['house_missing'])) {
            return 'Batteriegestütztes Laden ab ' . $mark . ' bleibt offen, bis der Hausverbrauch der letzten 30 Tage da ist.';
        }
        if (empty($storage['reachable'])) {
            return 'Batteriegestütztes Laden ab ' . $mark . ' braucht Ladestand und Kapazität.';
        }
        if (empty($storage['buffer_at'])) {
            return 'Batteriegestütztes Laden ab ' . $mark . ' in den nächsten vier Tagen nicht erreicht.';
        }
        return 'Batteriegestütztes Laden ab ' . $mark . ' voraussichtlich ' . when_label((int) $storage['buffer_at']) . '.';
    }

    /** @param array{surplus_kwh?:?float, house_missing?:bool} $storage */
    private function surplusText(array $storage, bool $houseKnown, ?float $houseKw): string
    {
        if (!empty($storage['house_missing']) || !$houseKnown || $houseKw === null) {
            return 'Überschuss bis Mitternacht bleibt offen. Der Hausverbrauch der letzten 30 Tage fehlt noch.';
        }
        if (!isset($storage['surplus_kwh']) || $storage['surplus_kwh'] === null) {
            return '';
        }
        return 'Überschuss bis Mitternacht ' . kwh((float) $storage['surplus_kwh'], 1) . '. Hausverbrauch angesetzt mit ' . kw($houseKw) . '.';
    }
}
