<?php
declare(strict_types=1);

/** Home Assistant zum Ausprobieren: Zustände, Verlauf und Statistik kommen aus DemoModel. */
final class DemoHaClient implements HaSource
{
    /** Entität => [Feld im Modell, Einheit, Name, Faktor zur Einheit] */
    private const ENTITIES = [
        'sensor.demo_pv_power' => ['pv', 'W', 'PV-Leistung', 1000],
        'sensor.demo_pv_energy' => ['pv', 'kWh', 'PV-Energie', 1],
        'sensor.demo_battery_soc' => ['soc', '%', 'Speicher Ladestand', 1],
        'sensor.demo_battery_charge' => ['charge', 'W', 'Speicher laden', 1000],
        'sensor.demo_battery_discharge' => ['discharge', 'W', 'Speicher entladen', 1000],
        'sensor.demo_battery_capacity' => ['stored', 'kWh', 'Speicher Restkapazität', 1],
        'sensor.demo_battery_total' => [null, 'kWh', 'Speicher Gesamtkapazität', 1],
        'sensor.demo_grid_import' => ['import', 'W', 'Netzbezug', 1000],
        'sensor.demo_grid_export' => ['export', 'W', 'Einspeisung', 1000],
        'sensor.demo_house_power' => ['house_total', 'W', 'Hausverbrauch', 1000],
        'sensor.demo_wallbox_power' => ['wall', 'W', 'Wallbox Leistung', 1000],
        'sensor.demo_wallbox_car' => [null, '', 'Wallbox Fahrzeug', 1],
        'number.demo_wallbox_amp' => [null, 'A', 'Wallbox Ladestrom', 1],
        'number.demo_wallbox_ama' => [null, 'A', 'Wallbox Maximalstrom', 1],
        'select.demo_wallbox_psm' => [null, '', 'Wallbox Phasen', 1],
        'select.demo_wallbox_frc' => [null, '', 'Wallbox Zwang', 1],
        'sensor.demo_car_soc' => ['car', '%', 'Auto Ladestand', 1],
        'sensor.demo_car_capacity' => [null, 'kWh', 'Auto Kapazität', 1],
        'sensor.demo_car_range' => [null, 'km', 'Auto Reichweite', 1],
        'sensor.demo_car_odometer' => [null, 'km', 'Auto Kilometerstand', 1],
        'sensor.demo_car_limit' => [null, '%', 'Auto Ladelimit', 1],
        'number.demo_battery_reserve' => [null, '%', 'Speicher Backup-Puffer', 1],
    ];

    public function __construct(private DemoModel $model) {}

    /** Zuordnung der Demo-Entitäten für bin/demo-seed.php. */
    public static function mapping(): array
    {
        return [
            'pv_power' => 'sensor.demo_pv_power',
            'pv_energy' => 'sensor.demo_pv_energy',
            'battery_soc' => 'sensor.demo_battery_soc',
            'battery_mode' => 'split',
            'battery_charge' => 'sensor.demo_battery_charge',
            'battery_discharge' => 'sensor.demo_battery_discharge',
            'battery_capacity' => 'sensor.demo_battery_capacity',
            'battery_total' => 'sensor.demo_battery_total',
            'grid_mode' => 'split',
            'grid_import' => 'sensor.demo_grid_import',
            'grid_export' => 'sensor.demo_grid_export',
            'house_power' => 'sensor.demo_house_power',
            'house_includes_wallbox' => true,
            'wallbox_power' => 'sensor.demo_wallbox_power',
            'wallbox_car' => 'sensor.demo_wallbox_car',
            'wallbox_amps' => 'number.demo_wallbox_amp',
            'wallbox_amps_max' => 'number.demo_wallbox_ama',
            'wallbox_phases' => 'select.demo_wallbox_psm',
            'wallbox_force' => 'select.demo_wallbox_frc',
            'car_soc' => 'sensor.demo_car_soc',
            'car_capacity' => 'sensor.demo_car_capacity',
            'car_range' => 'sensor.demo_car_range',
            'car_odometer' => 'sensor.demo_car_odometer',
            'battery_reserve' => 'number.demo_battery_reserve',
        ];
    }

    /** Merkt sich den Wert, damit Puffer und Regelung im Demo-Modus sichtbar werden. */
    public function setNumber(string $entityId, float $value): void
    {
        $this->service('number', 'set_value', ['entity_id' => $entityId, 'value' => $value]);
    }

    public function service(string $domain, string $service, array $data): void
    {
        $id = (string) ($data['entity_id'] ?? '');
        if (!isset(self::ENTITIES[$id]) || !str_starts_with($id, $domain . '.')) {
            throw new InvalidArgumentException('Entität ' . $id . ' gibt es im Demo-Modus nicht.');
        }
        if ($domain === 'button') {
            return;
        }
        $writes = store()->get('demo_writes', []);
        $writes = is_array($writes) ? $writes : [];
        $writes[$id] = $domain === 'select' ? (string) ($data['option'] ?? '') : (float) ($data['value'] ?? 0);
        store()->put('demo_writes', $writes);
    }

    /** Zwei Handys mit der Home-Assistant-App; Mitteilungen landen nur im Protokoll von Notify. */
    public function notifyServices(): array
    {
        return ['mobile_app_iphone_15', 'mobile_app_pixel_8'];
    }

    public function notify(string $service, array $payload): void
    {
        if (!in_array($service, $this->notifyServices(), true)) {
            throw new InvalidArgumentException('Das Gerät notify.' . $service . ' gibt es im Demo-Modus nicht.');
        }
    }

    /**
     * Regelt EMS im Demo-Modus (frc 1 oder 2 geschrieben), folgt die Wallbox: Strom mal Phasen, solange ein Auto
     * steckt; Speicher und Netz gleichen den Rest aus wie im Modell, der Speicher bis zum Backup-Puffer.
     */
    private function follow(array $s, array $writes, array $data): array
    {
        $frc = (string) ($writes['select.demo_wallbox_frc'] ?? '0');
        if ($frc === '0') {
            return $s;
        }
        $connected = $s['status'] !== 'idle';
        $phases = ($writes['select.demo_wallbox_psm'] ?? '2') === '1' ? 1 : 3;
        $amps = (int) round((float) ($writes['number.demo_wallbox_amp'] ?? 6));
        $full = $s['car'] >= (float) $data['fahrzeug']['limit'];
        $wall = $frc === '2' && $connected && !$full ? $amps * $phases * 0.23 : 0.0;
        $maxKw = (float) ($data['anlage']['speicher_max_kw'] ?? 5);
        $reserve = (float) ($writes['number.demo_battery_reserve'] ?? 10);
        $net = $s['pv'] - $s['house'] - $wall;
        $charge = $discharge = $import = $export = 0.0;
        if ($net >= 0) {
            $charge = $s['soc'] < 99.5 ? min($net, $maxKw) : 0.0;
            $export = $net - $charge;
        } else {
            $discharge = $s['soc'] > $reserve + 0.5 ? min(-$net, $maxKw) : 0.0;
            $import = -$net - $discharge;
        }
        $status = !$connected ? 'idle' : ($wall > 0 ? 'charging' : ($full ? 'complete' : 'wait_car'));
        return ['wall' => $wall, 'phases' => $phases, 'charge' => $charge, 'discharge' => $discharge, 'import' => $import, 'export' => $export, 'status' => $status] + $s;
    }

    public function configured(): bool
    {
        return true;
    }

    public function ping(): array
    {
        return ['ok' => true, 'error' => null, 'version' => 'Demo', 'location' => 'Beispielhaus', 'timezone' => 'Europe/Berlin'];
    }

    public function states(): array
    {
        return array_values($this->index());
    }

    public function state(string $entityId): ?array
    {
        return $this->index()[$entityId] ?? null;
    }

    public function index(): array
    {
        $data = $this->model->data();
        $now = $this->model->now();
        $writes = store()->get('demo_writes', []);
        $writes = is_array($writes) ? $writes : [];
        $s = $this->follow($this->model->at($now), $writes, $data);
        $car = $data['fahrzeug'];
        $values = [
            'sensor.demo_pv_power' => round($s['pv'] * 1000),
            'sensor.demo_pv_energy' => 18432.7,
            'sensor.demo_battery_soc' => round($s['soc'], 1),
            'sensor.demo_battery_charge' => round($s['charge'] * 1000),
            'sensor.demo_battery_discharge' => round($s['discharge'] * 1000),
            'sensor.demo_battery_capacity' => round($s['stored'], 2),
            'sensor.demo_battery_total' => (float) $data['anlage']['speicher_kwh'],
            'sensor.demo_grid_import' => round($s['import'] * 1000),
            'sensor.demo_grid_export' => round($s['export'] * 1000),
            'sensor.demo_house_power' => round(($s['house'] + $s['wall']) * 1000),
            'sensor.demo_wallbox_power' => round($s['wall'] * 1000),
            'sensor.demo_wallbox_car' => $s['status'],
            'number.demo_wallbox_amp' => $writes['number.demo_wallbox_amp'] ?? ($s['wall'] > 0 ? (int) round($s['wall'] / (0.23 * $s['phases'])) : 16),
            'number.demo_wallbox_ama' => 16,
            // Wie die go-e: 1 einphasig, 2 dreiphasig, 0 automatisch; frc 0 neutral, 1 gesperrt, 2 frei.
            'select.demo_wallbox_psm' => $writes['select.demo_wallbox_psm'] ?? ($s['phases'] === 3 ? '2' : '1'),
            'select.demo_wallbox_frc' => $writes['select.demo_wallbox_frc'] ?? '0',
            'sensor.demo_car_soc' => round($s['car'], 0),
            'sensor.demo_car_capacity' => (float) $car['kapazitaet_kwh'],
            'sensor.demo_car_range' => round($s['car'] / 100 * (float) $car['reichweite_voll_km']),
            // Etwa 38 km am Tag seit Jahresbeginn, auf ganze Kilometer.
            'sensor.demo_car_odometer' => 18400 + round(max(0, $now - (int) strtotime(date('Y', $now) . '-01-01')) / 86400 * 38),
            'sensor.demo_car_limit' => (float) $car['limit'],
            'number.demo_battery_reserve' => (float) ($writes['number.demo_battery_reserve'] ?? 10),
        ];
        $stamp = gmdate('Y-m-d\TH:i:s\Z', $now);
        $index = [];
        foreach (self::ENTITIES as $id => [, $unit, $name]) {
            $attributes = ['friendly_name' => $name];
            if ($unit !== '') {
                $attributes['unit_of_measurement'] = $unit;
            }
            if ($unit === 'kWh') {
                $attributes['device_class'] = 'energy';
            }
            if (str_starts_with($id, 'select.')) {
                $attributes['options'] = ['0', '1', '2'];
            }
            $index[$id] = [
                'entity_id' => $id,
                'state' => (string) $values[$id],
                'attributes' => $attributes,
                'last_changed' => $stamp,
                'last_updated' => $stamp,
            ];
        }
        return $index;
    }

    public function history(string $entityId, int $start, ?int $end = null): array
    {
        $out = [];
        foreach ($this->samples($entityId, $start, min($end ?? time(), time())) as $t => $kw) {
            $out[] = ['t' => $t, 'v' => $kw * self::ENTITIES[$entityId][3]];
        }
        return $out;
    }

    /** Fahrzeugstatus aus der Simulation, nur die Wechsel. */
    public function stateHistory(string $entityId, int $start, ?int $end = null): array
    {
        if ($entityId !== 'sensor.demo_wallbox_car') {
            return [];
        }
        $out = [];
        $last = null;
        foreach ($this->model->series('status', $start, min($end ?? time(), time())) as $t => $index) {
            $state = ['idle', 'wait_car', 'charging', 'complete'][(int) $index] ?? 'idle';
            if ($state !== $last) {
                $out[] = ['t' => $t, 's' => $state];
                $last = $state;
            }
        }
        return $out;
    }

    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array
    {
        $field = self::ENTITIES[$entityId][0] ?? null;
        if ($field === null) {
            return [];
        }
        $end = min($end, time());
        $energy = self::ENTITIES[$entityId][1] === 'kWh' && $field === 'pv';
        $scale = self::ENTITIES[$entityId][3];
        $buckets = [];
        foreach ($this->samples($entityId, $start, $end) as $t => $value) {
            $key = match ($period) {
                'day' => strtotime(date('Y-m-d', $t) . ' 00:00:00'),
                '5minute' => intdiv($t, 300) * 300,
                default => intdiv($t, 3600) * 3600,
            };
            $buckets[$key][$t] = $value;
        }
        $rows = [];
        foreach ($buckets as $bucket => $values) {
            $times = array_keys($values);
            $step = count($times) > 1 ? $times[1] - $times[0] : 300;
            $rows[] = [
                'start' => $bucket,
                'mean' => $energy ? null : array_sum($values) / count($values) * $scale,
                'min' => $energy ? null : min($values) * $scale,
                'max' => $energy ? null : max($values) * $scale,
                'change' => $energy ? array_sum($values) * $step / 3600 : null,
            ];
        }
        return $rows;
    }

    /** @return array<int, float> Werte in Modell-Einheiten (kW, %, kWh) */
    private function samples(string $entityId, int $start, int $end): array
    {
        $field = self::ENTITIES[$entityId][0] ?? null;
        if ($field === null || $end <= $start) {
            return [];
        }
        $simFrom = $this->model->simulatedFrom();
        if ($field === 'pv' && $start < $simFrom) {
            return $this->model->pvSeries($start, $simFrom) + $this->model->series('pv', $simFrom, $end);
        }
        if ($field === 'house_total') {
            $house = $this->model->series('house', $start, $end);
            $wall = $this->model->series('wall', $start, $end);
            foreach ($house as $t => $kw) {
                $house[$t] = $kw + ($wall[$t] ?? 0.0);
            }
            return $house;
        }
        return $this->model->series($field, $start, $end);
    }

    public function search(string $query, int $limit = 20): array
    {
        $q = strtolower(trim($query));
        if (strlen($q) < 2) {
            return [];
        }
        $out = [];
        foreach ($this->index() as $id => $row) {
            $name = (string) $row['attributes']['friendly_name'];
            if (!str_contains(strtolower($id), $q) && !str_contains(strtolower($name), $q)) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name, 'unit' => $row['attributes']['unit_of_measurement'] ?? '', 'state' => (string) $row['state']];
        }
        return array_slice($out, 0, $limit);
    }
}
