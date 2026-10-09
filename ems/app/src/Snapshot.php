<?php
declare(strict_types=1);

final class Snapshot
{
    public function __construct(
        private ConfigStore $store,
        private HaSource $ha,
    ) {}

    public function build(bool $persistLatch = true): array
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
            return $this->finish($base, $cfg, $now, $persistLatch);
        }
        try {
            $index = $this->ha->index();
        } catch (Throwable $e) {
            $base['error'] = $e->getMessage();
            return $this->finish($base, $cfg, $now, $persistLatch);
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
        $car = $read('wallbox_car');
        $amps = $read('wallbox_amps');
        $phases = $read('wallbox_phases');

        $values = [
            'pv_kw' => $pv[0],
            'battery_soc' => $soc,
            'battery_charge_kw' => $charge,
            'battery_discharge_kw' => $discharge,
            'battery_capacity_kwh' => $cap[0],
            'battery_total_kwh' => $total[0],
            'car_soc' => is_array($carSocRow) ? ($carSocRow['num'] ?? null) : null,
            'car_capacity_kwh' => $carCap[0],
            'car_range_km' => is_array($carRangeRow) ? ($carRangeRow['num'] ?? null) : null,
            'grid_import_kw' => $import,
            'grid_export_kw' => $export,
            'house_kw' => $house,
            'house_includes_wallbox' => (bool) ($map['house_includes_wallbox'] ?? true),
            'wallbox_kw' => $wallbox,
            'wallbox_car_raw' => $car['state'] ?? null,
            'wallbox_amps' => $amps['num'] ?? null,
            'wallbox_phases_raw' => $phases['state'] ?? null,
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
        return $this->finish($base, $cfg, $now, $persistLatch);
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

    private function finish(array $base, array $cfg, int $now, bool $persistLatch): array
    {
        $values = array_merge([
            'pv_kw' => null, 'battery_soc' => null, 'battery_charge_kw' => null, 'battery_discharge_kw' => null,
            'battery_capacity_kwh' => null, 'battery_total_kwh' => null, 'car_soc' => null, 'car_capacity_kwh' => null, 'car_range_km' => null,
            'grid_import_kw' => null, 'grid_export_kw' => null, 'house_kw' => null,
            'house_includes_wallbox' => true, 'wallbox_kw' => null, 'wallbox_amps' => null, 'wallbox_car_raw' => null,
            'wallbox_phases_raw' => null, 'priority_soc' => 80, 'radiation_rows' => [],
        ], $base['values'] ?: []);
        $base['values'] = $values;
        $balance = Energy::balance($values);
        $live = array_merge($values, $balance);
        $suggestion = Energy::suggest($live, $cfg['charge'], Energy::reportedPhases($values['wallbox_phases_raw'] ?? null));
        $latchState = $this->store->get('suggestion_latch', []);
        $latched = Energy::latch($suggestion, is_array($latchState) ? $latchState : [], $now, $cfg['charge']);
        if ($persistLatch) {
            $this->store->put('suggestion_latch', [
                'amps' => $latched['latched_amps'],
                'phases' => $latched['latched_phases'],
                'pending_amps' => $latched['pending_amps'],
                'pending_phases' => $latched['pending_phases'],
                'pending_since' => $latched['pending_since'],
            ]);
        }
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
            }
            $tomorrowKey = (new DateTimeImmutable('@' . $todayStart))->setTimezone($tz)->modify('+1 day')->format('Y-m-d');
            if (isset($captions[$tomorrowKey]['kwh'])) {
                $brief['tomorrow_kwh'] = round((float) $captions[$tomorrowKey]['kwh'], 2);
            }
        }
        $sessions = new Sessions($this->store);
        $session = $sessions->open() ?: $sessions->latest();
        $base['balance'] = $balance;
        $base['setpoint'] = $latched;
        $base['forecast'] = $brief;
        $base['storage'] = $outlook;
        $base['house_mean_kw'] = $mean;
        $base['car_charging'] = $charging;
        $base['car_buffer_soc'] = $bufferSoc;
        $base['house_known'] = $balance['house_base_kw'] !== null;
        $base['session'] = $session;
        $base['car_label'] = Energy::carLabel($values['wallbox_car_raw'] ?? null);
        $base['phase_label'] = Energy::phaseLabel($values['wallbox_phases_raw'] ?? null);
        $base['activity'] = Energy::activity($values['battery_charge_kw'] ?? null, $values['battery_discharge_kw'] ?? null);
        $base['vehicle'] = $this->vehicleView($base, $cfg);
        $base['chargepoint'] = $this->chargepointView($base, $cfg);
        $base['warnings'] = array_values(array_unique($base['warnings']));
        return $base;
    }

    public function livePayload(array $snap): array
    {
        $v = $snap['values'];
        $b = $snap['balance'];
        $s = $snap['setpoint'];
        $grid = $this->gridText($v['grid_import_kw'] ?? null, $v['grid_export_kw'] ?? null);
        $f = $snap['forecast'];
        return [
            'connected' => $snap['connected'],
            'error' => $snap['error'],
            'fetched_at' => $snap['fetched_at'],
            'connection' => $snap['connected'] ? 'verbunden' : 'getrennt',
            'pv' => kw($v['pv_kw'] ?? null),
            'soc' => pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null, 0),
            'house' => kw($b['house_base_kw'] ?? null),
            'grid' => $grid,
            'wallbox' => kw($v['wallbox_kw'] ?? null),
            'in' => kw($b['in_kw'] ?? null),
            'out' => kw($b['out_kw'] ?? null),
            'diff' => ($b['diff_kw'] ?? null) === null ? '—' : num((float) $b['diff_kw'], 2) . NNBSP . 'kW',
            'charge' => kw($v['battery_charge_kw'] ?? null),
            'discharge' => kw($v['battery_discharge_kw'] ?? null),
            'import' => kw($v['grid_import_kw'] ?? null),
            'export' => kw($v['grid_export_kw'] ?? null),
            'surplus' => kw($b['surplus_kw'] ?? null),
            'storage' => kw($b['storage_priority_kw'] ?? null),
            'psoll' => kw($s['p_soll_kw'] ?? null),
            'delta' => kw($s['delta_kw'] ?? null),
            'target' => kw($s['latched_kw'] ?? null),
            'proposal' => $this->proposal($s),
            'activity' => $snap['activity'],
            'battery_flow' => match ($snap['activity'] ?? '') {
                'Laden' => 'laden',
                'Entladen' => 'entladen',
                default => 'ruhe',
            },
            'car' => $snap['car_label'],
            'phase' => $snap['phase_label'],
            'amps' => amps(isset($v['wallbox_amps']) ? (float) $v['wallbox_amps'] : null),
            'remaining' => kwh($f['remaining_kwh'] ?? null, 1),
            'today_forecast' => kwh($f['today_kwh'] ?? null, 1),
            'capacity' => kwh($v['battery_capacity_kwh'] ?? null, 1),
            'capacity_line' => $this->capacityLine($v['battery_capacity_kwh'] ?? null, $v['battery_total_kwh'] ?? null),
            'soc_fill' => isset($v['battery_soc']) ? (string) max(0, min(100, round((float) $v['battery_soc']))) : '0',
            'battery_full' => $this->fullText($snap['storage'] ?? []),
            'battery_priority' => $this->priorityText($snap['storage'] ?? [], (float) ($v['priority_soc'] ?? 80)),
            'battery_buffer' => $this->bufferText($snap['storage'] ?? [], (float) ($snap['car_buffer_soc'] ?? 100)),
            'battery_surplus' => $this->surplusText($snap['storage'] ?? [], isset($snap['house_mean_kw']) && $snap['house_mean_kw'] !== null, $snap['house_mean_kw'] ?? null),
            'house_mean' => kw($snap['house_mean_kw'] ?? null),
            'plan_now' => $this->levelKwh($v['battery_capacity_kwh'] ?? null, isset($v['battery_soc']) ? (float) $v['battery_soc'] : null, $snap['storage']['full_kwh'] ?? null, true),
            'plan_priority_kwh' => $this->levelKwh(null, (float) ($v['priority_soc'] ?? 80), $snap['storage']['full_kwh'] ?? null, false),
            'plan_full_kwh' => $this->levelKwh(null, 100, $snap['storage']['full_kwh'] ?? null, false),
            'plan_buffer_kwh' => $this->levelKwh(null, (float) ($snap['car_buffer_soc'] ?? 100), $snap['storage']['full_kwh'] ?? null, false),
            'car_soc_fill' => isset($v['car_soc']) ? (string) max(0, min(100, (int) round((float) $v['car_soc']))) : '',
            'car_soc_label' => isset($v['car_soc']) ? pct((float) $v['car_soc'], 0) : '—',
            'car_capacity_line' => $this->carCapacityLine(isset($v['car_soc']) ? (float) $v['car_soc'] : null, $v['car_capacity_kwh'] ?? null),
            'car_flow' => !empty($snap['car_charging']) ? 'laden' : 'ruhe',
            'car_state' => $this->carState($v['car_soc'] ?? null, $v['car_capacity_kwh'] ?? null),
            'car_outlook' => !empty($snap['car_charging'])
                ? 'Die Wallbox lädt gerade ein Auto. Die Zeiten gelten wieder, sobald sie pausiert. Angesetzt ist kein Ladestrom.'
                : 'Kein Auto an der Wallbox. Angesetzt ist kein Ladestrom an der go-e.',
            'grid_kw' => $this->gridMagnitude($v['grid_import_kw'] ?? null, $v['grid_export_kw'] ?? null),
            'ts' => time(),
            'flow' => Energy::flowBar($v, $b),
            'flow_rows' => self::flowRows($v, $b),
            'chargepoint' => $snap['chargepoint'],
            'vehicle' => $snap['vehicle'],
            'battery' => [
                'soc' => isset($v['battery_soc']) ? round((float) $v['battery_soc'], 1) : null,
                'flow' => match ($snap['activity'] ?? '') {
                    'Laden' => 'laden',
                    'Entladen' => 'entladen',
                    default => 'ruhe',
                },
                'soc_text' => pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null),
                'stored_text' => self::storedText($v['battery_capacity_kwh'] ?? null, $v['battery_total_kwh'] ?? null),
                'activity_text' => match ($snap['activity'] ?? '') {
                    'Laden' => 'Lädt mit ' . kw($v['battery_charge_kw'] ?? null) . '.',
                    'Entladen' => 'Entlädt mit ' . kw($v['battery_discharge_kw'] ?? null) . '.',
                    default => $snap['connected'] ? 'Ruht.' : '',
                },
            ],
            'say' => self::sayText($snap),
            'flows' => [
                'pv' => max(0, (float) ($v['pv_kw'] ?? 0)),
                'bat_charge' => max(0, (float) ($v['battery_charge_kw'] ?? 0)),
                'bat_discharge' => max(0, (float) ($v['battery_discharge_kw'] ?? 0)),
                'grid_import' => max(0, (float) ($v['grid_import_kw'] ?? 0)),
                'grid_export' => max(0, (float) ($v['grid_export_kw'] ?? 0)),
                'house' => max(0, (float) ($b['house_base_kw'] ?? 0)),
                'wallbox' => max(0, (float) ($v['wallbox_kw'] ?? 0)),
            ],
        ];
    }

    /** Fahrzeug unter der Ladepunkt-Karte: Name, Status, Ladestand, Reichweite und Limit. */
    private function vehicleView(array $base, array $cfg): array
    {
        $v = $base['values'];
        $raw = strtolower(str_replace([' ', '-'], '_', (string) ($v['wallbox_car_raw'] ?? '')));
        $soc = $v['car_soc'] !== null ? (float) $v['car_soc'] : null;
        $limit = (float) ($cfg['vehicle']['limit_soc'] ?? 80);
        $range = $v['car_range_km'] !== null ? (float) $v['car_range_km'] : null;
        return [
            'name' => trim((string) ($cfg['vehicle']['name'] ?? '')) ?: 'Auto',
            'status' => self::vehicleStatus($raw, (bool) $base['car_charging']),
            'connected' => (bool) $base['car_charging'] || in_array($raw, ['charging', 'wait_car', 'waitcar', 'complete', 'completed'], true),
            'soc' => $soc === null ? null : round($soc, 1),
            'capacity_kwh' => $v['car_capacity_kwh'] ?? null,
            'range_km' => $range,
            'limit' => $limit,
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
        if ($open || ($vehicle['connected'] && is_array($session))) {
            $energy = round((float) $session['energy_kwh'], 3);
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
            'suggestion' => self::suggestionText($setpoint),
        ];
    }

    public static function suggestionText(array $setpoint): string
    {
        $amps = (int) ($setpoint['latched_amps'] ?? 0);
        $text = $amps === 0
            ? 'Vorschlag: aus'
            : 'Vorschlag: ' . $amps . NNBSP . 'A, ' . (int) ($setpoint['latched_phases'] ?? 1) . '-phasig (' . kw((float) ($setpoint['latched_kw'] ?? 0)) . ')';
        $wait = (int) ($setpoint['wait_s'] ?? 0);
        if ($wait > 0) {
            $text .= ', wechselt in ' . $wait . NNBSP . 's';
        }
        $reason = trim((string) ($setpoint['reason'] ?? ''));
        return $text . '.' . ($reason !== '' ? ' ' . $reason : '');
    }

    /** Leistungen für die Detail-Liste „Rein“ und „Raus“ unter dem Energiefluss-Balken. */
    public static function flowRows(array $v, array $b): array
    {
        $kw = static fn (mixed $value): ?float => $value === null ? null : round(max(0.0, (float) $value), 3);
        return [
            'in_kw' => $kw($b['in_kw'] ?? null),
            'out_kw' => $kw($b['out_kw'] ?? null),
            'in' => ['pv' => $kw($v['pv_kw'] ?? null), 'battery' => $kw($v['battery_discharge_kw'] ?? null), 'grid' => $kw($v['grid_import_kw'] ?? null)],
            'out' => ['house' => $kw($b['house_base_kw'] ?? null), 'wallbox' => $kw($v['wallbox_kw'] ?? null), 'battery' => $kw($v['battery_charge_kw'] ?? null), 'grid' => $kw($v['grid_export_kw'] ?? null)],
        ];
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

    private function capacityLine(?float $now, ?float $total): string
    {
        if ($now === null && ($total === null || $total <= 0)) {
            return 'Kapazität —';
        }
        if ($total === null || $total <= 0) {
            return 'aktuell ' . kwh($now, 1) . '. Gesamtkapazität fehlt in den Einstellungen.';
        }
        return 'aktuell ' . num($now, 1) . ' / ' . num($total, 1) . NNBSP . 'kWh';
    }

    private function levelKwh(?float $stored, ?float $soc, ?float $full, bool $preferStored): string
    {
        if ($preferStored && $stored !== null) {
            return num($stored, 1) . NNBSP . 'kWh';
        }
        if ($full !== null && $full > 0 && $soc !== null) {
            return num($full * max(0, min(100, $soc)) / 100, 1) . NNBSP . 'kWh';
        }
        if ($soc !== null) {
            return num($soc, 0) . NNBSP . '%';
        }
        return '—';
    }

    private function carCapacityLine(?float $soc, ?float $capacity): string
    {
        if ($soc !== null && $capacity !== null && $capacity > 0) {
            $stored = $capacity * max(0, min(100, $soc)) / 100;

            return num($stored, 1) . ' / ' . num($capacity, 1) . NNBSP . 'kWh';
        }
        if ($capacity !== null && $capacity > 0) {
            return 'Kapazität ' . num($capacity, 1) . NNBSP . 'kWh. Der Ladestand fehlt noch.';
        }
        if ($soc !== null) {
            return 'Der Ladestand ist da. Die Kapazität fehlt noch.';
        }

        return 'Kapazität folgt, sobald das Fahrzeug sie meldet.';
    }

    private function carState(?float $soc, ?float $capacity): string
    {
        if ($soc === null && ($capacity === null || $capacity <= 0)) {
            return 'Fahrzeugdaten fehlen noch. Ladestand und Kapazität des Autos lassen sich im Bereich Auto zuordnen.';
        }
        if ($soc !== null && $capacity !== null && $capacity > 0) {
            $stored = $capacity * max(0, min(100, $soc)) / 100;
            return 'Auto ' . num($soc, 0) . NNBSP . '% · ' . num($stored, 1) . ' / ' . num($capacity, 1) . NNBSP . 'kWh';
        }
        if ($soc !== null) {
            return 'Auto ' . num($soc, 0) . NNBSP . '%. Die Kapazität fehlt noch.';
        }
        return 'Kapazität ' . num($capacity, 1) . NNBSP . 'kWh. Der Ladestand fehlt noch.';
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

    private function gridText(?float $import, ?float $export): string
    {
        if ($import === null && $export === null) {
            return '—';
        }
        $import = $import ?? 0;
        $export = $export ?? 0;
        if ($export > $import) {
            return num($export, 2) . NNBSP . 'kW Einspeisung';
        }
        return num($import, 2) . NNBSP . 'kW Bezug';
    }

    private function gridMagnitude(?float $import, ?float $export): string
    {
        if ($import === null && $export === null) {
            return '—';
        }
        return kw(max($import ?? 0, $export ?? 0));
    }

    private function proposal(array $setpoint): string
    {
        $amps = (int) ($setpoint['latched_amps'] ?? 0);
        $phases = (int) ($setpoint['latched_phases'] ?? 1);
        $text = $amps === 0 ? 'Vorschlag: aus' : 'Vorschlag: ' . $amps . NNBSP . 'A, ' . $phases . '-phasig';
        $wait = (int) ($setpoint['wait_s'] ?? 0);
        if ($wait > 0) {
            $text .= ' in ' . $wait . ' s';
        }
        return $text;
    }
}
