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
        $strategy = zone_thresholds(
            (float) ($cfg['battery_strategy']['priority_soc'] ?? 80),
            (float) ($cfg['battery_strategy']['car_buffer_soc'] ?? 100),
            (float) ($cfg['battery_strategy']['car_auto_soc'] ?? 100),
        );
        $reported = Energy::reportedPhases($values['wallbox_phases_raw'] ?? null);
        $suggestion = Energy::suggest($live, $cfg['charge'], $reported, $strategy);
        // Was die drei Modi jetzt täten, für die Regelung unter der Ladepunkt-Karte.
        $modes = [];
        foreach (['smart', 'smart_dauerhaft', 'schnell'] as $mode) {
            $modes[$mode] = $mode === $suggestion['mode'] ? $suggestion : Energy::suggest($live, ['mode' => $mode] + $cfg['charge'], $reported, $strategy);
        }
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
        $base['forecast'] = $brief;
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
        $base['control'] = self::control($live, $cfg['charge'], $strategy, $latched, $modes);
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
            // Erklärliste des Speichers
            'house_mean' => kw($snap['house_mean_kw'] ?? null),
            'battery_full' => $this->fullText($snap['storage'] ?? []),
            'battery_priority' => $this->priorityText($snap['storage'] ?? [], (float) ($v['priority_soc'] ?? 80)),
            'battery_buffer' => $this->bufferText($snap['storage'] ?? [], (float) ($snap['car_buffer_soc'] ?? 100)),
            'battery_surplus' => $this->surplusText($snap['storage'] ?? [], isset($snap['house_mean_kw']) && $snap['house_mean_kw'] !== null, $snap['house_mean_kw'] ?? null),
            'remaining_kwh' => $f['remaining_kwh'] ?? null,
            'flow' => Energy::flowBar($v, $b),
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
     * Regelung unter der Ladepunkt-Karte: mit welcher Stufe die Wallbox jetzt laden würde (verriegelt, mit
     * anstehendem Wechsel), woher die Leistung käme und wohin der übrige Überschuss ginge, was die drei Modi
     * täten, die Phasen-Leiter und der Rechenweg. Geschrieben wird nichts.
     *
     * @param array $live Messwerte und Bilanz
     * @param array $strategy geordnete Grenzen aus zone_thresholds()
     * @param array $setpoint Vorschlag des aktiven Modus nach Energy::latch()
     * @param array<string, array> $modes Vorschläge für smart, smart_dauerhaft und schnell
     */
    public static function control(array $live, array $charge, array $strategy, array $setpoint, array $modes): array
    {
        $active = (string) ($setpoint['mode'] ?? ($charge['mode'] ?? 'smart'));
        $zone = (string) ($setpoint['zone'] ?? 'none');
        $amps = (int) ($setpoint['latched_amps'] ?? 0);
        $phases = (int) ($setpoint['latched_phases'] ?? 1);
        $kw = (float) ($setpoint['latched_kw'] ?? 0);
        $flows = Energy::allot($kw, $active, $zone, $live);
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
                'level_text' => self::levelText((int) $mode['amps'], (int) $mode['phases']),
                'text' => self::decisionText($key, $mode, $strategy),
                'flows' => $mode['flows'],
            ];
        }
        $soc = isset($live['battery_soc']) ? (float) $live['battery_soc'] : null;
        $in = (float) ($live['battery_charge_kw'] ?? 0);
        $out = (float) ($live['battery_discharge_kw'] ?? 0);
        $battery = $soc === null ? 'ohne Ladestand' : pct($soc) . ($in > 0.05 ? ', lädt ' . kw($in) : ($out > 0.05 ? ', entlädt ' . kw($out) : ', ruht'));
        $target = $setpoint['target_kw'] ?? null;
        return [
            'mode' => $active,
            'mode_label' => Energy::modeLabel($active),
            'zone' => $zone,
            'amps' => $amps,
            'phases' => $amps === 0 ? 0 : $phases,
            'kw' => round($kw, 3),
            'kw_text' => $amps === 0 ? 'aus' : kw($kw),
            'headline' => $amps === 0 ? 'Würde jetzt nicht laden' : 'Würde jetzt laden mit',
            'level_text' => self::levelText($amps, $phases),
            'reason' => self::zoneText($active, $zone, $strategy, $soc, $minA),
            'pending' => self::pendingText($setpoint),
            'flows' => $flows,
            'car_text' => kw((float) $flows['car_kw']),
            'split_text' => self::splitText($flows),
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
                'battery' => $battery,
                'solar' => kw($modes['smart']['solar_kw'] ?? null, 2),
                'meter' => kw($setpoint['p_soll_kw'] ?? null, 2),
                'target' => $target === null ? '—' : kw((float) $target, 2) . ' → ' . ($amps === 0 ? 'aus' : self::levelText($amps, $phases) . ' = ' . kw($kw, 2)),
                'delta' => kw($setpoint['delta_kw'] ?? null, 2),
            ],
        ];
    }

    /** Stufe als „6 A · 3-phasig“, „aus“ ohne Strom. */
    public static function levelText(int $amps, int $phases): string
    {
        return $amps === 0 ? 'aus' : $amps . NNBSP . 'A · ' . $phases . '-phasig';
    }

    /** Warum der aktive Modus so entscheidet, aus Sicht der Speicherzone. */
    public static function zoneText(string $mode, string $zone, array $strategy, ?float $soc, int $minA): string
    {
        if ($mode === 'aus') {
            return 'Modus Aus: Die Wallbox bliebe aus, der Überschuss ginge in den Speicher und danach ins Netz.';
        }
        if ($mode === 'schnell') {
            return 'Schnell lädt mit voller Leistung. Die Grenzen des Speichers gelten dabei nicht.';
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
        $wait = (int) ($setpoint['wait_s'] ?? 0);
        if ($wait <= 0 || !isset($setpoint['pending_amps'])) {
            return '';
        }
        $next = (int) $setpoint['pending_amps'];
        $level = self::levelText($next, (int) ($setpoint['pending_phases'] ?? 1));
        return match (true) {
            $next === 0 => 'Stoppt in ' . $wait . NNBSP . 's (Ausschaltverzögerung).',
            (int) ($setpoint['latched_amps'] ?? 0) === 0 => 'Startet in ' . $wait . NNBSP . 's mit ' . $level . ' (Einschaltverzögerung).',
            default => 'Wechselt in ' . $wait . NNBSP . 's auf ' . $level . ' (Schütz-Schutzzeit).',
        };
    }

    /** Ein Satz zur Aufteilung: woher das Auto die Leistung bekäme und was der Speicher täte. */
    public static function splitText(array $flows, string $lead = 'Auto: '): string
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
            $text .= ' Speicher lädt nicht.';
        }
        if ((float) $flows['export_kw'] >= 0.05) {
            $text .= ' ' . kw((float) $flows['export_kw']) . ' gehen ins Netz.';
        }
        return $text;
    }

    /** Entscheidung eines Modus in einem Satz. */
    public static function decisionText(string $key, array $mode, array $strategy): string
    {
        if ((int) $mode['amps'] === 0) {
            $solar = (float) ($mode['solar_kw'] ?? 0);
            return match ((string) ($mode['zone'] ?? '')) {
                'house' => 'Lädt nicht: Der Speicher unter ' . pct((float) $strategy['priority_soc']) . ' nimmt den Überschuss' . ($solar >= 0.05 ? ', fürs Auto blieben ' . kw($solar) : '') . '.',
                'boost' => 'Lädt nicht: Ohne genug Sonne startet keine neue Ladung' . ($solar >= 0.05 ? ', ' . kw($solar) . ' reichen nicht' : '') . '.',
                default => 'Lädt nicht: ' . ($solar >= 0.05 ? kw($solar) . ' Überschuss reichen nicht für die kleinste Stufe.' : 'Kein Überschuss fürs Auto.'),
            };
        }
        return self::splitText($mode['flows'], '');
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
