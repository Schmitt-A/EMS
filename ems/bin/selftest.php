<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "ok {$message}\n";
}

[$kw, $warn] = Energy::powerToKw(930.0, 'W');
check(abs($kw - 0.93) < 0.0001 && $warn === null, 'Watt nach kW');
[$kw] = Energy::powerToKw(1.5, 'kW');
check(abs($kw - 1.5) < 0.0001, 'kW bleibt kW');
[$kwh] = Energy::energyToKwh(20240.0, 'Wh');
check(abs($kwh - 20.24) < 0.0001, 'Wh nach kWh');

$balance = Energy::balance([
    'pv_kw' => 3.0,
    'battery_discharge_kw' => 0.0,
    'battery_charge_kw' => 1.0,
    'grid_import_kw' => 0.0,
    'grid_export_kw' => 0.2,
    'house_kw' => 1.5,
    'house_includes_wallbox' => true,
    'wallbox_kw' => 0.5,
    'battery_soc' => 40.0,
    'priority_soc' => 80.0,
]);
check(abs($balance['house_base_kw'] - 1.0) < 0.0001, 'Haus ohne Wallbox');
check(abs($balance['surplus_kw'] - 1.0) < 0.0001, 'Überschuss mit Speichervorrang');
check(abs($balance['in_kw'] - 3.0) < 0.0001 && abs($balance['out_kw'] - 2.7) < 0.0001, 'Bilanz');

$live = [
    'wallbox_kw' => 0.0,
    'grid_import_kw' => 0.0,
    'grid_export_kw' => 2.0,
    'surplus_kw' => 2.2,
];
$charge = ['mode' => 'smart', 'phase_mode' => 'auto', 'solar_share' => 100, 'reserve_w' => 200, 'min_a' => 6, 'max_a' => 16];
$hint = Energy::suggest($live, $charge, 1);
check(abs($hint['p_soll_kw'] - 1.8) < 0.0001, 'Sollleistung bei 2 kW Einspeisung');
check($hint['amps'] === 8 && $hint['phases'] === 1, '8 A einphasig, ist ' . $hint['amps'] . '/' . $hint['phases']);

$live['surplus_kw'] = 0.2;
$hint = Energy::suggest($live, $charge, 1);
check($hint['amps'] === 0, 'Sonnenanteil deckelt unter 1,38 kW');

$fast = Energy::suggest($live, array_merge($charge, ['mode' => 'schnell']), 1);
check($fast['amps'] === 16 && $fast['phases'] === 3, 'Schnell lädt mit 16 A dreiphasig');
$grid = Energy::suggest($live, array_merge($charge, ['mode' => 'netz']), 1);
check($grid['amps'] === 16 && $grid['phases'] === 3 && $grid['reason'] === 'Netzladen mit voller Leistung.', 'Netzladen lädt ebenfalls mit voller Leistung');
check(Energy::MODES === ['aus', 'smart', 'smart_dauerhaft', 'schnell', 'netz'] && Energy::mode('netz') === 'netz' && Energy::mode('turbo') === 'smart' && Energy::modeLabel('smart') === 'Solar' && Energy::modeLabel('schnell') === 'Schnell' && Energy::modeLabel('netz') === 'Netzladen', 'Fünf Modi: Aus, Solar, Min+Solar, Schnell, Netzladen');
check(Energy::migratedMode('schnell', true) === 'netz' && Energy::migratedMode('schnell', false) === 'schnell' && Energy::migratedMode('smart', true) === 'smart' && Energy::migratedMode('', true) === '', 'Altes „schnell“ wird mit Schonen zu Netzladen, ohne bleibt es Schnell');

$off = Energy::suggest($live, array_merge($charge, ['mode' => 'aus']), 3);
check($off['amps'] === 0, 'Aus ist 0 A');

$gap = Energy::suggest([
    'wallbox_kw' => 3.9,
    'grid_import_kw' => 0,
    'grid_export_kw' => 0,
    'surplus_kw' => 5,
], array_merge($charge, ['reserve_w' => 0]), 1);
check($gap['phases'] === 1 && $gap['amps'] === 16, 'Lücke bleibt einphasig');

// Zonen des Hausspeichers: Hausgrenze 50 %, Stützung ab 80 %, Start ohne Sonne ab 90 %.
$zones = ['priority_soc' => 50, 'car_buffer_soc' => 80, 'car_auto_soc' => 90];
check(Energy::zone(30, $zones) === 'house' && Energy::zone(60, $zones) === 'car' && Energy::zone(85, $zones) === 'boost' && Energy::zone(95, $zones) === 'start' && Energy::zone(null, $zones) === 'none', 'Zonen nach Ladestand');
check(Energy::zone(100, ['priority_soc' => 80, 'car_buffer_soc' => 100, 'car_auto_soc' => 100]) === 'car', 'Grenzen auf 100 % schalten Stützung und Start ab');
$solarCfg = ['mode' => 'smart', 'phase_mode' => 'auto', 'solar_share' => 100, 'reserve_w' => 0, 'min_a' => 6, 'max_a' => 16];
$sunny = ['pv_kw' => 4.64, 'house_base_kw' => 0.5, 'battery_charge_kw' => 4.14, 'battery_discharge_kw' => 0.0, 'wallbox_kw' => 0.0, 'grid_import_kw' => 0.0, 'grid_export_kw' => 0.0];
$carFirst = Energy::suggest($sunny + ['battery_soc' => 60, 'surplus_kw' => 4.14], $solarCfg, 1, $zones);
check($carFirst['amps'] === 6 && $carFirst['phases'] === 3 && abs($carFirst['flows']['sun_kw'] - 4.14) < 0.001 && $carFirst['flows']['charge_kw'] < 0.001, 'Über der Hausgrenze nimmt das Auto, was der Speicher gerade lädt');
$batteryFirst = Energy::suggest($sunny + ['battery_soc' => 30, 'surplus_kw' => 0.0], $solarCfg, 1, $zones);
check($batteryFirst['amps'] === 0 && abs($batteryFirst['flows']['charge_kw'] - 4.14) < 0.001, 'Unter der Hausgrenze lädt der Speicher zuerst');
$cloudy = ['pv_kw' => 1.0, 'house_base_kw' => 0.5, 'battery_charge_kw' => 0.0, 'battery_discharge_kw' => 0.88, 'wallbox_kw' => 1.38, 'grid_import_kw' => 0.0, 'grid_export_kw' => 0.0, 'battery_soc' => 85, 'surplus_kw' => 0.5];
$held = Energy::suggest($cloudy, $solarCfg, 1, $zones);
check($held['amps'] === 6 && $held['phases'] === 1 && abs($held['flows']['sun_kw'] - 0.5) < 0.001 && abs($held['flows']['battery_kw'] - 0.88) < 0.001, 'Ab der Stützung hält der Speicher eine laufende Ladung');
$idle = Energy::suggest(['wallbox_kw' => 0.0, 'battery_discharge_kw' => 0.0, 'battery_charge_kw' => 0.5] + $cloudy, $solarCfg, 1, $zones);
check($idle['amps'] === 0, 'Ohne genug Sonne startet in der Stützung keine neue Ladung');
$night = ['pv_kw' => 0.0, 'house_base_kw' => 0.5, 'battery_charge_kw' => 0.0, 'battery_discharge_kw' => 0.5, 'wallbox_kw' => 0.0, 'grid_import_kw' => 0.0, 'grid_export_kw' => 0.0, 'surplus_kw' => 0.0];
$start = Energy::suggest($night + ['battery_soc' => 95], $solarCfg, 1, $zones);
check($start['amps'] === 6 && abs($start['flows']['battery_kw'] - 1.38) < 0.001, 'Ab dem Start lädt das Auto auch nachts aus dem Speicher');
$minimum = Energy::suggest($night + ['battery_soc' => 60], ['mode' => 'smart_dauerhaft'] + $solarCfg, 1, $zones);
check($minimum['amps'] === 6 && abs($minimum['flows']['battery_kw'] - 1.38) < 0.001 && $minimum['flows']['grid_kw'] < 0.001, 'Min+Solar: die Mindestleistung kommt zuerst aus dem Speicher');
$atReserve = Energy::suggest($night + ['battery_soc' => 20], ['mode' => 'smart_dauerhaft'] + $solarCfg, 1, $zones, ['reserve_soc' => 20]);
check(abs($atReserve['flows']['grid_kw'] - 1.38) < 0.001 && $atReserve['flows']['battery_kw'] < 0.001, 'Min+Solar: am Backup-Puffer kommt sie aus dem Netz');
$sunnyFull = ['pv_kw' => 5.0, 'house_base_kw' => 0.5, 'battery_charge_kw' => 0.0, 'battery_discharge_kw' => 0.0, 'wallbox_kw' => 0.0, 'grid_import_kw' => 0.0, 'grid_export_kw' => 4.5, 'battery_soc' => 60, 'surplus_kw' => 4.5];
$full = Energy::suggest($sunnyFull, ['mode' => 'schnell'] + $solarCfg, 1, $zones);
check($full['amps'] === 16 && $full['phases'] === 3 && abs($full['flows']['sun_kw'] - 4.5) < 0.001 && abs($full['flows']['mixed_kw'] - 6.54) < 0.001, 'Schnell ohne Entladeleistung: Sonne zuerst, der Rest erst Speicher, dann Netz');
$spared = Energy::suggest($sunnyFull, ['mode' => 'netz'] + $solarCfg, 1, $zones, ['reserve_soc' => 10, 'protect' => true]);
check(abs($spared['flows']['grid_kw'] - 6.54) < 0.001 && $spared['flows']['mixed_kw'] < 0.001 && $spared['flows']['battery_kw'] < 0.001, 'Netzladen: der Rest kommt nur aus dem Netz');
$capped = Energy::suggest($night + ['battery_soc' => 60], ['mode' => 'schnell'] + $solarCfg, 1, $zones, ['reserve_soc' => 10, 'max_discharge_kw' => 4.6]);
check(abs($capped['flows']['battery_kw'] - 4.1) < 0.001 && abs($capped['flows']['grid_kw'] - 6.94) < 0.001, 'Schnell: der Speicher gibt seine Entladeleistung abzüglich Haus, den Rest das Netz');
$keepSpare = Energy::suggest($sunnyFull, ['mode' => 'smart_dauerhaft'] + $solarCfg, 1, $zones, ['protect' => true]);
check($keepSpare['flows']['grid_kw'] < 0.001 && Energy::batteryFor('smart', ['protect' => true])['protect'] === false && Energy::batteryFor('schnell', ['protect' => true])['protect'] === false && Energy::batteryFor('netz', ['protect' => true])['protect'] === true && Energy::batteryFor('netz', [])['protect'] === false, 'Geschont wird nur beim Netzladen und nur mit Backup-Puffer');
$exporting = Energy::allot(1.38, 'smart_dauerhaft', ['pv_kw' => 5.0, 'house_base_kw' => 0.5, 'battery_charge_kw' => 3.0, 'battery_discharge_kw' => 0.0, 'grid_export_kw' => 1.5, 'battery_soc' => 40]);
check(abs($exporting['sun_kw'] - 1.38) < 0.001 && abs($exporting['charge_kw'] - 3.0) < 0.001 && abs($exporting['export_kw'] - 0.12) < 0.001, 'Lädt der Speicher schon voll, geht der Rest der Sonne ins Netz');
// Energie-Flow: Sonne zuerst in den Verbrauch, dann Speicher, Rest ins Netz; Haus und Auto teilen sich die Quellen.
$graph = Energy::flowGraph(['pv_kw' => 7.8, 'battery_charge_kw' => 1.6, 'battery_discharge_kw' => 0.0, 'grid_import_kw' => 0.0, 'grid_export_kw' => 1.3, 'wallbox_kw' => 4.1, 'battery_soc' => 64.0, 'car_soc' => 57.0], ['house_base_kw' => 0.8], ['today_kwh' => 29.4, 'remaining_kwh' => 6.2, 'done_kwh' => 23.1]);
check($graph['links']['sun_home'] === 0.8 && $graph['links']['sun_car'] === 4.1 && $graph['links']['sun_battery'] === 1.6 && abs($graph['links']['sun_grid'] - 1.3) < 0.001 && $graph['links']['grid_car'] === 0.0 && $graph['mix']['sun'] === 1.0, 'Energie-Flow mittags: Sonne direkt zu Haus und Auto, in den Speicher, der Rest ins Netz');
check($graph['nodes']['sun']['progress'] === 0.786 && $graph['nodes']['sun']['forecast_kwh'] === 29.4, 'Die Sonne trägt den Fortschritt der Tagesprognose');
check($graph['nodes']['battery_in']['mix'] === ['sun' => 1.0, 'battery' => 0.0, 'grid' => 0.0] && $graph['nodes']['grid_out']['mix']['sun'] === 1.0 && $graph['nodes']['car']['mix']['sun'] === 1.0, 'Ringe rechts: Herkunft je Ziel');
$facts = Energy::flowGraph(['battery_soc' => 64.0, 'battery_capacity_kwh' => 8.6, 'battery_total_kwh' => 13.4, 'car_soc' => 57.0], [], null, ['house_mean_kw' => 0.624, 'import_ct' => 34.7, 'export_ct' => 8.1, 'car_range_km' => 296.4])['nodes'];
check($facts['battery_out']['to_full_kwh'] === 4.8 && $facts['battery_in']['to_full_kwh'] === 4.8 && $facts['home']['mean_kw'] === 0.62 && $facts['grid_in']['price_ct'] === 34.7 && $facts['grid_out']['price_ct'] === 8.1 && $facts['car']['range_km'] === 296.0 && $graph['nodes']['grid_in']['price_ct'] === null, 'Zweite Zeile im Energie-Flow: bis voll, Ø Haus, Preise, Reichweite');
$evening = Energy::flowGraph(['pv_kw' => 0.0, 'battery_charge_kw' => 0.0, 'battery_discharge_kw' => 3.0, 'grid_import_kw' => 1.9, 'grid_export_kw' => 0.0, 'wallbox_kw' => 4.1, 'battery_soc' => 60.0], ['house_base_kw' => 0.8]);
check(abs($evening['links']['battery_car'] - 2.51) < 0.01 && abs($evening['links']['grid_car'] - 1.59) < 0.01 && abs($evening['links']['battery_home'] + $evening['links']['grid_home'] - 0.8) < 0.01 && abs($evening['mix']['battery'] - 0.6122) < 0.001, 'Energie-Flow abends: Auto direkt aus Speicher und Netz, anteilig wie das Haus');
check(abs(Energy::distanceKm(100.0, 'mi')[0] - 160.9344) < 0.0001 && Energy::distanceKm(42.0, 'km')[0] === 42.0 && Energy::distanceKm(1.0, 'kWh')[0] === null, 'Meilen werden Kilometer');

// Backup-Puffer beim Netzladen: anheben, halten, zurücksetzen
$rs = ['entity' => 'number.x', 'default' => 20, 'discharge_kw' => null, 'active' => true];
$down = ['raised' => false];
check(Reserve::decide($rs, $down, 'netz', 64.7, true, true, true) == ['action' => 'raise', 'value' => 64, 'entity' => 'number.x'], 'Netzladen hebt den Puffer auf den Ladestand');
check(Reserve::decide($rs, $down, 'smart', 64.7, true, true, true)['action'] === 'none' && Reserve::decide($rs, $down, 'netz', 64.7, false, false, true)['action'] === 'none', 'Andere Modi und Netzladen ohne Ladung heben nicht an');
check(Reserve::decide($rs, $down, 'netz', 18.0, true, true, true)['action'] === 'none' && Reserve::decide(['active' => false] + $rs, $down, 'netz', 64.7, true, true, true)['action'] === 'none', 'Unter dem Standardwert oder ohne Entität und Standardwert bleibt der Puffer');
check(Reserve::decide($rs, $down, 'schnell', 64.7, true, true, true)['action'] === 'none', 'Schnell hebt den Puffer nicht an, der Speicher gibt mit ab');
$up = ['raised' => true, 'value' => 64, 'restore' => 20, 'entity' => 'number.x'];
check(Reserve::decide($rs, $up, 'netz', 64.0, false, true, true)['action'] === 'none', 'In einer Ladepause bleibt der Puffer oben');
check(Reserve::decide($rs, $up, 'smart', 64.0, true, true, true) == ['action' => 'restore', 'value' => 20, 'entity' => 'number.x'], 'Der Moduswechsel setzt zurück');
check(Reserve::decide($rs, $up, 'netz', 64.0, false, false, true)['action'] === 'restore' && Reserve::decide($rs, $up, 'netz', 64.0, false, true, false)['action'] === 'restore', 'Ende des Ladevorgangs und Abstecken setzen zurück');
check(Reserve::decide(['entity' => '', 'default' => null, 'active' => false] + $rs, $up, 'netz', 64.0, true, true, true) == ['action' => 'restore', 'value' => 20, 'entity' => 'number.x'], 'Ohne Zuordnung setzt der gemerkte Wert auf der gemerkten Entität zurück');
check(Reserve::settings(['battery_strategy' => ['backup_soc' => '15', 'discharge_kw' => 0], 'mapping' => ['battery_reserve' => 'number.x']]) === ['entity' => 'number.x', 'default' => 15, 'discharge_kw' => null, 'active' => true] && Reserve::settings(['battery_strategy' => [], 'mapping' => ['battery_reserve' => 'number.x']])['active'] === false, 'Einstellungen des Puffers');
check(Snapshot::coverText(['protect' => true], 60.0) === 'Das Netz, der Speicher bleibt geschont.' && Snapshot::coverText(['reserve_soc' => 20, 'max_discharge_kw' => 4.6], 60.0) === 'Der Speicher bis 20' . NNBSP . '% mit höchstens 4,6' . NNBSP . 'kW, dann das Netz.', 'Wer fehlende Leistung deckt');
// sync schreibt nur bei einem Wechsel, merkt sich Fehler und versucht es nach einer Minute wieder.
$guardHa = new class implements HaSource {
    public array $writes = [];
    public bool $fail = false;
    public function setNumber(string $entityId, float $value): void
    {
        if ($this->fail) {
            throw new RuntimeException('nicht erreichbar');
        }
        $this->writes[] = [$entityId, $value];
    }
    public function service(string $domain, string $service, array $data): void
    {
        if ($this->fail) {
            throw new RuntimeException('nicht erreichbar');
        }
        $this->writes[] = [$data['entity_id'], $data['option'] ?? $data['value'] ?? null];
    }
    public function notifyServices(): array { return []; }
    public function notify(string $service, array $payload): void {}
    public function configured(): bool { return true; }
    public function ping(): array { return ['ok' => true]; }
    public function states(): array { return []; }
    public function state(string $entityId): ?array { return null; }
    public function index(): array { return []; }
    public function history(string $entityId, int $start, ?int $end = null): array { return []; }
    public function stateHistory(string $entityId, int $start, ?int $end = null): array { return []; }
    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array { return []; }
    public function search(string $query, int $limit = 20): array { return []; }
};
$guard = new Reserve(new ConfigStore(':memory:'), $guardHa);
$gridCfg = ['charge' => ['mode' => 'netz'], 'mapping' => ['battery_reserve' => 'number.x'], 'battery_strategy' => ['backup_soc' => 20], 'control' => ['active' => true]];
$charging = ['wallbox_kw' => 11.0, 'wallbox_car_raw' => 'charging', 'battery_soc' => 64.7];
$guard->sync($charging, $gridCfg, 1000, true);
$guard->sync($charging, $gridCfg, 1010, true);
check($guardHa->writes === [['number.x', 64.0]] && $guard->state()['raised'] === true, 'sync hebt beim Netzladen einmal an');
$guardHa->fail = true;
$guard->sync($charging, ['charge' => ['mode' => 'smart']] + $gridCfg, 1020, true);
check($guard->state()['raised'] === true && str_contains((string) $guard->state()['error'], 'nicht erreichbar'), 'Ein Fehler beim Zurücksetzen bleibt sichtbar, der Puffer gilt weiter als angehoben');
$guardHa->fail = false;
$guard->sync($charging, ['charge' => ['mode' => 'smart']] + $gridCfg, 1040, true);
check(count($guardHa->writes) === 1, 'Nach einem Fehler wartet sync eine Minute');
$guard->sync($charging, ['charge' => ['mode' => 'smart']] + $gridCfg, 1090, true);
check($guardHa->writes[1] === ['number.x', 20.0] && $guard->state()['raised'] === false && $guard->state()['error'] === null, 'Danach setzt sync auf den Standardwert zurück');

$found = Actions::suggestions([
    'number.sonnenbatterie_382994_battery_reserve' => ['attributes' => ['unit_of_measurement' => '%']],
    'sensor.tesla_ble_ladezustand' => ['attributes' => []],
    'sensor.tesla_ble_ladezustand_2' => ['attributes' => ['unit_of_measurement' => '%']],
    'sensor.tesla_ble_reichweite' => ['attributes' => ['unit_of_measurement' => 'km']],
    'sensor.tesla_ble_odometer' => ['attributes' => ['unit_of_measurement' => 'mi']],
    'sensor.tesla_ble_charge_limit' => ['attributes' => ['unit_of_measurement' => '%']],
]);
check(($found['battery_reserve'] ?? '') === 'number.sonnenbatterie_382994_battery_reserve' && ($found['car_soc'] ?? '') === 'sensor.tesla_ble_ladezustand_2' && ($found['car_range'] ?? '') === 'sensor.tesla_ble_reichweite' && ($found['car_odometer'] ?? '') === 'sensor.tesla_ble_odometer' && ($found['car_limit'] ?? '') === 'sensor.tesla_ble_charge_limit', 'Vorschläge für Backup-Puffer und Tesla BLE');
check(Sessions::carConnected('wait_for_car') === true && Sessions::carConnected('WaitCar') === true && Sessions::carConnected('idle') === false && Sessions::carConnected('unknown_error') === null && Sessions::carState('Wait for car') === 'waiting'
    && Snapshot::vehicleStatus('wait_for_car', false) === 'Verbunden' && Energy::carLabel('wait_for_car') === 'Wartet auf Auto' && Controller::input(['wallbox_car_raw' => 'complete'], ['charge' => []], [])['complete'], 'Fahrzeugstatus beider go-e-Integrationen, auch wait_for_car der MQTT-Integration, heißt angesteckt');
check((Actions::suggestions(['number.sonnenbatterie_backup_puffer' => ['attributes' => ['unit_of_measurement' => '%']]])['battery_reserve'] ?? '') === 'number.sonnenbatterie_backup_puffer', 'Eine eigene Template-Number für den Backup-Puffer wird vorgeschlagen');
// Integration „go-eCharger API v2“: Fahrzeugstatus als Text in der Sprache von Home Assistant oder als Code.
check(Sessions::carState('Warte auf Fahrzeug') === 'waiting' && Sessions::carState('Laden') === 'charging' && Sessions::carState('Abgeschlossen') === 'complete' && Sessions::carState('Inaktiv/Frei') === 'idle'
    && Sessions::carState('Unbekannt/Fehler') === 'error' && Sessions::carState('Charging') === 'charging' && Sessions::carState('Unknown/Error') === 'error' && Sessions::carState('3') === 'waiting' && Sessions::carState('1') === 'idle'
    && Sessions::carConnected('Warte auf Fahrzeug') === true && Sessions::carConnected('Inaktiv/Frei') === false && Snapshot::vehicleStatus('Laden', false) === 'Lädt …' && Energy::carLabel('Warte auf Fahrzeug') === 'Wartet auf Auto', 'Fahrzeugstatus der Integration von marq24, deutsch, englisch und als Code');
$goe = Actions::suggestions([
    'sensor.goe_506181_nrg_11' => ['attributes' => ['unit_of_measurement' => 'W']],
    'sensor.goe_506181_car_value' => ['attributes' => []],
    'number.goe_506181_amp' => ['attributes' => ['unit_of_measurement' => 'A']],
    'number.goe_506181_ama' => ['attributes' => ['unit_of_measurement' => 'A']],
    'select.goe_506181_psm' => ['attributes' => []],
    'select.goe_506181_frc' => ['attributes' => []],
    'sensor.goe_506181_wh' => ['attributes' => ['unit_of_measurement' => 'kWh']],
]);
check(($goe['wallbox_power'] ?? '') === 'sensor.goe_506181_nrg_11' && ($goe['wallbox_car'] ?? '') === 'sensor.goe_506181_car_value' && ($goe['wallbox_amps'] ?? '') === 'number.goe_506181_amp' && ($goe['wallbox_amps_max'] ?? '') === 'number.goe_506181_ama'
    && ($goe['wallbox_phases'] ?? '') === 'select.goe_506181_psm' && ($goe['wallbox_force'] ?? '') === 'select.goe_506181_frc' && ($goe['wallbox_energy'] ?? '') === 'sensor.goe_506181_wh', 'Vorschläge für die Integration „go-eCharger API v2“');
// Ladevorgänge je Stunde, wo Home Assistant keine Statistik der Wallbox hat: gleichmäßig über die Ladezeit.
$h0 = (int) strtotime('2026-10-11T10:00:00+00:00');
$byHour = Sessions::energyByHour([['started_at' => '2026-10-11T10:30:00+00:00', 'duration_s' => 3600, 'energy_kwh' => 2.0], ['started_at' => '2026-10-11T08:00:00+00:00', 'duration_s' => 600, 'energy_kwh' => 0.0]], $h0 - 86400, $h0 + 86400);
check(array_keys($byHour) === [$h0, $h0 + 3600] && abs($byHour[$h0] - 1.0) < 1e-9 && abs($byHour[$h0 + 3600] - 1.0) < 1e-9 && Sessions::energyByHour([['started_at' => '2026-10-11T10:30:00+00:00', 'duration_s' => 3600, 'energy_kwh' => 2.0]], $h0 + 3600, $h0 + 7200) === [$h0 + 3600 => 1.0], 'Ladeenergie je Stunde nach Anteil der Ladezeit, nur im Zeitraum');
// Einmalige Umstellung der Modi (1.10): mit Schonen wird „schnell“ zu Netzladen, ohne bleibt es Schnell; Regelung und Mitteilungen ziehen mit.
$migFile = tempnam(sys_get_temp_dir(), 'ems-mig');
foreach ([true, false] as $protectBefore) {
    @unlink($migFile);
    $mig = new ConfigStore($migFile);
    $mig->pdo()->exec("DELETE FROM kv WHERE k = 'modes_v2'");
    $mig->put('charge', ['mode' => 'schnell', 'after_unplug' => 'schnell', 'then_mode' => 'smart']);
    $mig->put('battery_strategy', ['backup_soc' => 20, 'grid_protect' => $protectBefore]);
    $mig->put('control_state', ['mode' => 'schnell', 'enabled' => true]);
    $mig->put('notify_state', ['mode' => 'schnell']);
    $mig = new ConfigStore($migFile);
    $want = $protectBefore ? 'netz' : 'schnell';
    check($mig->get('charge')['mode'] === $want && $mig->get('charge')['after_unplug'] === $want && $mig->get('charge')['then_mode'] === 'smart' && $mig->get('control_state')['mode'] === $want && $mig->get('notify_state')['mode'] === $want
        && !array_key_exists('grid_protect', (array) $mig->get('battery_strategy')) && (int) $mig->get('modes_v2') === 1, 'Umstellung der Modi ' . ($protectBefore ? 'mit' : 'ohne') . ' Schonen');
    $mig->put('charge', ['mode' => 'schnell']);
    check((new ConfigStore($migFile))->get('charge')['mode'] === 'schnell', 'Die Umstellung läuft nur einmal, ein neues Schnell bleibt Schnell');
}
@unlink($migFile);

$fit = Forecast::regression([10, 20, 30], [12, 22, 32]);
check(abs($fit['a'] - 2) < 0.01 && abs($fit['b'] - 1) < 0.01, 'Regression');
$west = Forecast::geometry(13, 270);
check(abs($west - 1) < 0.02, 'Westdach nahe Faktor 1');
$plantNoon = ['kwp' => 10.03, 'factor' => 1, 'tilt' => 13, 'azimuth' => 270, 'inverter_kw' => 10];
$noon = Forecast::powerKw(1000, 13, $plantNoon);
$morning = Forecast::powerKw(1000, 8, $plantNoon);
check($noon > 4 && $noon <= 10, '1000 W/m² bleibt unter dem Limit, ist ' . $noon);
check(abs($morning - $noon) < 0.001, 'die Stunde verändert die Leistung nicht');

// Regelung wie evcc: Timer auf Bedingungen, Mindeststrom vor dem Stoppen, Phasen mit Ein- und Ausschaltverzögerung.
$ctl = ['mode' => 'smart', 'connected' => true, 'charging' => false, 'available_kw' => 0.0, 'zone' => 'car', 'min_a' => 6, 'max_a' => 16, 'phase_mode' => 'auto', 'share' => 1.0, 'on_s' => 60, 'off_s' => 180, 'guard_s' => 60, 'reported' => ['frc' => 'off', 'amp' => 6, 'psm' => '1p']];
$run = static fn (array $state, array $patch, int $now): array => Controller::step($state, $patch + $ctl, $now)['state'];
$st = $run([], ['available_kw' => 2.0], 1000);
check($st['enabled'] === false && $st['phases'] === 1 && $st['pv_action'] === 'enable', 'Erster Schritt übernimmt die Wallbox, der Ein-Timer läuft');
$st = $run($st, ['available_kw' => 1.6], 1030);
check($st['enabled'] === false && $st['pv_timer'] === 1000, 'Schwankender Überschuss startet den Ein-Timer nicht neu');
$st = $run($st, ['available_kw' => 2.0], 1060);
check($st['enabled'] === true && $st['amps'] === 6 && $st['phases'] === 1, 'Nach 60 s gestartet mit dem Mindeststrom');
$st = $run($st, ['available_kw' => 3.0, 'charging' => true], 1090);
check($st['amps'] === 13, 'Laufend folgt der Strom sofort, abgerundet: 3 kW sind 13 A');
$st = $run($st, ['available_kw' => 0.5, 'charging' => true], 1100);
check($st['enabled'] === true && $st['amps'] === 6 && $st['pv_action'] === 'disable', 'Zu wenig Sonne: Mindeststrom, der Aus-Timer läuft');
$st = $run($st, ['available_kw' => 0.5, 'charging' => true], 1250);
check($st['enabled'] === true, 'Vor 180 s wird nicht gestoppt');
$st = $run($st, ['available_kw' => 0.5, 'charging' => true], 1280);
check($st['enabled'] === false && $st['pv_timer'] === null, 'Nach 180 s gestoppt');
$up = $run(['enabled' => true, 'amps' => 16, 'phases' => 1, 'step_at' => 1900, 'mode' => 'smart', 'connected' => true], ['available_kw' => 5.0, 'charging' => true], 2000);
check($up['phases'] === 1 && $up['phase_action'] === 'scale3p', '1p → 3p wartet die Einschaltverzögerung ab');
$up = $run($up, ['available_kw' => 5.0, 'charging' => true], 2060);
check($up['phases'] === 3 && $up['amps'] === 7, 'Nach 60 s dreiphasig mit 7 A');
$down = $run(['enabled' => true, 'amps' => 8, 'phases' => 3, 'step_at' => 2900, 'mode' => 'smart', 'connected' => true], ['available_kw' => 3.0, 'charging' => true], 3000);
$down = $run($down, ['available_kw' => 3.0, 'charging' => true], 3170);
check($down['phases'] === 3 && $down['enabled'] === true && $down['amps'] === 6, '3p → 1p erst nach der Ausschaltverzögerung, bis dahin Mindeststrom');
check($down['pv_action'] === null, 'Reicht es einphasig, läuft kein Aus-Timer');
$down = $run($down, ['available_kw' => 3.0, 'charging' => true], 3180);
check($down['phases'] === 1 && $down['enabled'] === true && $down['amps'] === 13, 'Nach 180 s einphasig weiter statt zu stoppen');
$guarded = $run(['enabled' => false, 'amps' => 6, 'phases' => 1, 'step_at' => 3990, 'mode' => 'smart', 'connected' => false, 'switched_at' => 3990], ['available_kw' => 2.0], 4000);
check($guarded['enabled'] === false && $guarded['note'] === 'guard', 'Angesteckt startet sofort, aber nicht vor der Schütz-Schutzzeit');
$guarded = $run($guarded, ['available_kw' => 2.0], 4050);
check($guarded['enabled'] === true, 'Nach der Schutzzeit ohne weitere Einschaltverzögerung');
$fast = $run(['enabled' => false, 'amps' => 6, 'phases' => 1, 'step_at' => 4990, 'mode' => 'smart', 'connected' => true], ['mode' => 'schnell'], 5000);
check($fast['enabled'] === true && $fast['amps'] === 16 && $fast['phases'] === 3, 'Schnell gibt sofort frei, dreiphasig mit Höchststrom');
$gridStep = Controller::step(['enabled' => false, 'amps' => 6, 'phases' => 3, 'step_at' => 4990, 'mode' => 'schnell', 'connected' => true], ['mode' => 'netz'] + $ctl, 5000);
check($gridStep['state']['enabled'] === true && $gridStep['state']['amps'] === 16 && in_array('Netzladen: frei mit 16' . NNBSP . 'A · 3-phasig.', $gridStep['events'], true), 'Netzladen gibt ebenso sofort frei und nennt sich im Protokoll');
$off = $run($fast, ['mode' => 'aus', 'charging' => true], 5010);
check($off['enabled'] === false, 'Aus sperrt sofort');
$min = $run(['enabled' => false, 'amps' => 6, 'phases' => 1, 'step_at' => 5990, 'mode' => 'smart_dauerhaft', 'connected' => true], ['mode' => 'smart_dauerhaft', 'available_kw' => 0.0], 6000);
check($min['enabled'] === true && $min['amps'] === 6, 'Min+Solar gibt sofort mit dem Mindeststrom frei');
$gone = $run($min, ['mode' => 'smart_dauerhaft', 'connected' => false], 6100);
check($gone['enabled'] === false, 'Ohne Auto gesperrt');
$start = $run(['enabled' => false, 'amps' => 6, 'phases' => 1, 'step_at' => 6990, 'mode' => 'smart', 'connected' => true], ['zone' => 'start', 'available_kw' => 0.0], 7000);
check($start['enabled'] === true, 'Ab dem Start ohne Sonne gibt der Speicher frei');
$share = $run(['enabled' => false, 'amps' => 6, 'phases' => 1, 'step_at' => 7990, 'mode' => 'smart', 'connected' => true, 'pv_timer' => 1, 'pv_action' => null], ['available_kw' => 0.8, 'share' => 0.5], 8000);
check($share['enabled'] === true, 'Mit 50 % Sonnenanteil reichen 0,8 kW für den Start');
$decision = Controller::decision(['enabled' => true, 'amps' => 6, 'phases' => 3, 'pv_timer' => 1000, 'pv_action' => 'disable'], $ctl, 1100);
check($decision['kw'] > 4.13 && $decision['timer'] === ['action' => 'disable', 'remaining_s' => 80], 'Anzeige mit Restzeit des Aus-Timers');
check(Energy::reportedPhases('2') === 3 && Energy::reportedPhases('1') === 1 && Energy::reportedPhases('0') === null, 'go-e psm 2 heißt dreiphasig');

// Schreiben an die go-e: nur Unterschiede, hoch auf drei Phasen erst der Strom; Fremdzugriffe pausieren.
$wallHa = new class implements HaSource {
    public array $calls = [];
    public function setNumber(string $entityId, float $value): void { $this->calls[] = [$entityId, (int) $value]; }
    public function service(string $domain, string $service, array $data): void { $this->calls[] = [$data['entity_id'], $data['option'] ?? $data['value'] ?? '']; }
    public function notifyServices(): array { return []; }
    public function notify(string $service, array $payload): void {}
    public function configured(): bool { return true; }
    public function ping(): array { return ['ok' => true]; }
    public function states(): array { return []; }
    public function state(string $entityId): ?array { return null; }
    public function index(): array { return []; }
    public function history(string $entityId, int $start, ?int $end = null): array { return []; }
    public function stateHistory(string $entityId, int $start, ?int $end = null): array { return []; }
    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array { return []; }
    public function search(string $query, int $limit = 20): array { return []; }
};
$wallCfg = ['mapping' => ['wallbox_force' => 'select.frc', 'wallbox_amps' => 'number.amp', 'wallbox_phases' => 'select.psm']];
$wall = new Controller(new ConfigStore(':memory:'), $wallHa);
$written = $wall->apply(['enabled' => true, 'amps' => 8, 'phases' => 3] + Controller::initial(), $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 1000);
check($wallHa->calls === [['number.amp', 8], ['select.psm', '2'], ['select.frc', '2']], 'Freigeben dreiphasig: erst Strom, dann Phasen, dann frc 2');
$wallHa->calls = [];
$again = $wall->apply($written, $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 1030);
check($wallHa->calls === [], 'Innerhalb der Karenzzeit kein zweites Schreiben');
$taken = $wall->apply($written, $wallCfg, ['frc' => 'on', 'amp' => 10, 'psm' => '3p'], 1070);
check(count($taken['conflicts']) === 1 && $wallHa->calls === [['number.amp', 8]], 'Ein fremder Strom zählt als Fremdzugriff und wird zurückgesetzt');
$taken = $wall->apply($taken, $wallCfg, ['frc' => 'on', 'amp' => 10, 'psm' => '3p'], 1140);
$taken = $wall->apply($taken, $wallCfg, ['frc' => 'on', 'amp' => 10, 'psm' => '3p'], 1210);
check($taken['paused'] !== null, 'Drei Fremdzugriffe in zehn Minuten pausieren die Regelung');
// Übernimmt die Wallbox den Befehl nie (go-e mit MQTT nur lesend), heißt es „nicht übernommen“, nicht evcc.
$deaf = Controller::initial();
foreach ([3000, 3070, 3140] as $at) {
    $deaf = $wall->apply(['enabled' => true, 'amps' => 6, 'phases' => 1] + $deaf, $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], $at);
}
$deaf = $wall->apply($deaf, $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 3210);
check($deaf['conflicts'] === [] && count($deaf['ignored']) === 3 && str_contains((string) $deaf['paused'], 'go-eCharger API v2') && str_contains($deaf['log'][1]['text'] ?? '', 'nicht übernommen'), 'Eine Wallbox, die Befehle nicht übernimmt, pausiert mit dem Hinweis auf MQTT nur lesen');
$seen = Controller::observe(['written' => ['frc' => ['v' => 'on', 't' => 4000, 'before' => 'off', 'seen' => false]]] + Controller::initial(), ['frc' => 'on', 'amp' => 6, 'psm' => '1p']);
$back = $wall->apply(['enabled' => true, 'amps' => 6, 'phases' => 1] + $seen, $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 4070);
check($seen['written']['frc']['seen'] === true && count($back['conflicts']) === 1 && $back['ignored'] === [], 'Hat die Wallbox den Wert gemeldet und springt dann zurück, war es ein anderer Regler');
// Gilt inzwischen etwas anderes und die Wallbox steht schon dort (Wechsel auf Aus), zählt der alte Befehl nicht mehr.
$wallHa->calls = [];
$stale = $wall->apply(['enabled' => true, 'amps' => 6, 'phases' => 1] + Controller::initial(), $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 5000);
$stale = $wall->apply(['enabled' => false] + $stale, $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 5030);
$stale = $wall->apply(['enabled' => false] + $stale, $wallCfg, ['frc' => 'off', 'amp' => 6, 'psm' => '1p'], 5100);
check($wallHa->calls === [['select.frc', '2']] && $stale['conflicts'] === [] && $stale['ignored'] === [] && !isset($stale['written']['frc']), 'Ein überholter Befehl zählt nach dem Wechsel auf Aus weder als fremd noch als nicht übernommen');
$wallHa->calls = [];
$wall->apply(['enabled' => false] + Controller::initial(), $wallCfg, ['frc' => 'on', 'amp' => 8, 'psm' => '3p'], 2000);
check($wallHa->calls === [['select.frc', '1']], 'Sperren schreibt nur frc 1');
// MQTT-Integration von syssi: dieselben Bedeutungen mit Namen statt Ziffern.
$mqtt = ['frc' => ['neutral', 'dont_charge', 'charge'], 'psm' => ['auto', 'one_phase', 'three_phases']];
$wallHa->calls = [];
$wall->apply(['enabled' => true, 'amps' => 16, 'phases' => 3] + Controller::initial(), $wallCfg, Controller::reported(['wallbox_force_raw' => 'dont_charge', 'wallbox_amps' => 16, 'wallbox_phases_raw' => 'one_phase']), 2500, $mqtt);
check($wallHa->calls === [['select.psm', 'three_phases'], ['select.frc', 'charge']], 'MQTT-Integration: schreibt three_phases und charge');
check(Controller::meaning('frc', 'dont_charge') === 'off' && Controller::meaning('psm', 'three_phases') === '3p' && Controller::meaning('frc', 'unavailable') === null, 'Gemeldete Namen werden zu Bedeutungen');
$mqttIndex = ['select.frc' => ['attributes' => ['options' => $mqtt['frc']]], 'number.amp' => ['attributes' => []], 'select.psm' => ['attributes' => ['options' => $mqtt['psm']]], 'sensor.power' => ['attributes' => []]];
$mqttCfg = ['charge' => ['phase_mode' => 'auto'], 'mapping' => $wallCfg['mapping'] + ['wallbox_power' => 'sensor.power']];
check(Controller::problems($mqttCfg, $mqttIndex) === [], 'Vorbedingungen passen auch zur MQTT-Integration');
check(count(Controller::problems($mqttCfg, ['select.frc' => ['attributes' => ['options' => ['a', 'b']]]] + $mqttIndex)) === 1, 'Unbekannte Werte nennen die gefundenen Optionen');
$wallHa->calls = [];
$offline = $wall->apply(['enabled' => true, 'amps' => 8, 'phases' => 3] + Controller::initial(), $wallCfg, Controller::reported(['wallbox_force_raw' => 'unavailable', 'wallbox_amps' => null, 'wallbox_phases_raw' => 'unknown']), 3000);
check($wallHa->calls === [] && $offline['note'] === 'offline' && $offline['conflicts'] === [], 'Offline schreibt EMS nichts und zählt keinen Fremdzugriff');

// Ladeziele: Energie, Uhrzeit, Ladestand; erreicht → Folgemodus, Abstecken löscht.
$noon = (int) strtotime('2026-10-10 12:00:00 Europe/Berlin');
$energyTarget = Target::build(['type' => 'energy', 'kwh' => '20', 'then' => 'aus'], $noon, null)['target'];
check($energyTarget['value'] === 20.0 && $energyTarget['then'] === 'aus', 'Ziel 20 kWh, danach Aus');
$view = Target::view($energyTarget, ['session_kwh' => 5.0, 'power_kw' => 11.0], $noon);
check($view['reached'] === false && abs($view['progress'] - 0.25) < 0.001 && $view['remaining_s'] === 4909, 'Noch 15 kWh bei 11 kW sind 1:21 h');
check(Target::view($energyTarget, ['session_kwh' => 20.0], $noon)['reached'] === true, 'Bei 20 kWh erreicht');
$timeTarget = Target::build(['type' => 'time', 'until' => '11:30'], $noon, null)['target'];
check($timeTarget['value'] === (int) strtotime('2026-10-11 11:30:00 Europe/Berlin'), 'Eine vergangene Uhrzeit meint morgen');
$soonTarget = Target::build(['type' => 'time', 'hours' => '1,5'], $noon, null)['target'];
check(Target::view($soonTarget, ['session_kwh' => 2.0], $noon + 3600)['text'] === 'noch 30:00 · bisher 2,0' . NNBSP . 'kWh', 'Countdown und bisher geladene kWh');
check(Target::build(['type' => 'soc', 'soc' => '80'], $noon, null)['error'] !== null && Target::build(['type' => 'soc', 'soc' => '80'], $noon, 55.0)['target']['start_soc'] === 55.0, 'Ladestand-Ziel braucht den Ladestand des Autos');
// Kilometer: 16 kWh/100 km, 8 % Ladeverlust; Verbrauch aus den Einstellungen oder so, wie das Auto seine Reichweite rechnet.
check(Energy::consumption(17.0, 280.0, 60.0, 75.0) === ['kwh' => 17.0, 'source' => 'setting'] && Energy::consumption(null, 280.0, 60.0, 75.0) === ['kwh' => 16.1, 'source' => 'car'] && Energy::consumption(null, null, 60.0, 75.0) === null && Energy::consumption(null, 30.0, 60.0, 75.0) === null, 'Verbrauch aus den Einstellungen oder aus Reichweite, Ladestand und Akku');
check(Energy::kmFromKwh(20.0, 16.0) === 115.0 && Energy::kwhForKm(40.0, 16.0) === 7.0 && Energy::kmFromKwh(20.0, null) === null && Energy::rangeFrom(60.0, 75.0, 16.0) === 281.0 && Energy::toFull(8.6, 13.4, 64.0) === 4.8 && Energy::toFull(null, 10.0, 75.0) === 2.5 && Energy::toFull(5.0, null, 50.0) === null, 'Kilometer aus kWh, kWh für Kilometer, Reichweite aus dem Verbrauch, Speicher bis voll');
$kmIn = ['session_kwh' => 12.4, 'power_kw' => 11.0, 'consumption_kwh' => 16.0, 'car_soc' => 60.0, 'capacity_kwh' => 75.0, 'range_km' => 280.0];
$energyKm = Target::view($energyTarget, $kmIn, $noon);
check($energyKm['label'] === 'Ziel 20,0' . NNBSP . 'kWh · ≈ 115' . NNBSP . 'km' && str_starts_with($energyKm['text'], 'bisher 12,4' . NNBSP . 'kWh ≈ 71' . NNBSP . 'km, noch ca.'), 'Energieziel nennt die Kilometer');
check(Target::view($soonTarget, ['session_kwh' => 2.0, 'power_kw' => 11.0, 'consumption_kwh' => 16.0], $noon + 3600)['label'] === 'Bis 13:30 · ≈ 43' . NNBSP . 'km', 'Uhrzeit-Ziel: Kilometer bis dahin bei der Leistung von jetzt');
$socKm = Target::view(Target::build(['type' => 'soc', 'soc' => '80'], $noon, 60.0)['target'], $kmIn, $noon);
check($socKm['label'] === 'Bis 80' . NNBSP . '% · ≈ 373' . NNBSP . 'km' && str_starts_with($socKm['text'], 'jetzt 60' . NNBSP . '% · noch ≈ 94' . NNBSP . 'km, ca. 1:28 h'), 'Ladestand-Ziel: Reichweite danach und Kilometer dazu');
check(Target::build(['type' => 'range', 'km' => '300'], $noon, 60.0, null)['error'] !== null && Target::build(['type' => 'range', 'km' => '5'], $noon, 60.0, 220.0)['error'] !== null, 'Reichweiten-Ziel braucht die Reichweite und einen sinnvollen Wert');
$rangeTarget = Target::build(['type' => 'range', 'km' => '300'], $noon, 60.0, 220.0)['target'];
$rangeView = Target::view($rangeTarget, ['range_km' => 260.0] + $kmIn, $noon);
check($rangeTarget['value'] === 300.0 && $rangeTarget['start_range'] === 220.0 && $rangeView['label'] === 'Bis 300' . NNBSP . 'km' && abs($rangeView['progress'] - 0.5) < 0.001
    && $rangeView['text'] === 'jetzt 260' . NNBSP . 'km · noch 40' . NNBSP . 'km, ca. 0:38 h' && $rangeView['remaining_s'] === 2291 && Target::view($rangeTarget, ['range_km' => 301.0] + $kmIn, $noon)['reached'], 'Ziel nach Reichweite: Fortschritt, Kilometer, Dauer, erreicht');
$targetStore = new ConfigStore(':memory:');
$targetStore->put(Target::KEY, $energyTarget);
$targetSnap = ['cfg' => ['charge' => ['mode' => 'schnell']], 'values' => ['wallbox_kw' => 11.0, 'wallbox_car_raw' => 'charging'], 'chargepoint' => ['session_kwh' => 20.2], 'suggestion' => [], 'vehicle' => []];
$switched = Target::check($targetStore, $targetSnap, $noon, true);
check($switched['mode'] === 'aus' && Target::get($targetStore) === null && $targetStore->get('charge')['mode'] === 'aus', 'Ziel erreicht: weiter mit Aus, Ziel gelöscht');
$targetStore->put(Target::KEY, $energyTarget);
$unplugged = Target::check($targetStore, ['values' => ['wallbox_kw' => 0.0, 'wallbox_car_raw' => 'idle']] + $targetSnap, $noon, true);
check(Target::get($targetStore) === null && str_contains((string) $unplugged['text'], 'abgesteckt'), 'Abstecken löscht das Ziel');

$sample = <<<'KML'
<?xml version="1.0" encoding="ISO-8859-1"?>
<kml:kml>
<kml:name>F9519</kml:name>
<kml:description>SOONWALD WEST 4</kml:description>
<dwd:IssueTime>2026-10-07T15:00:00.000Z</dwd:IssueTime>
<dwd:ForecastTimeSteps>
<dwd:TimeStep>2026-10-07T16:00:00.000Z</dwd:TimeStep>
<dwd:TimeStep>2026-10-07T17:00:00.000Z</dwd:TimeStep>
</dwd:ForecastTimeSteps>
<dwd:Forecast dwd:elementName="Rad1h"><dwd:value>0 1320</dwd:value></dwd:Forecast>
<dwd:Forecast dwd:elementName="Neff"><dwd:value>80 20</dwd:value></dwd:Forecast>
<dwd:Forecast dwd:elementName="SunD1"><dwd:value>0 1800</dwd:value></dwd:Forecast>
<dwd:Forecast dwd:elementName="TTT"><dwd:value>295.15 -</dwd:value></dwd:Forecast>
</kml:kml>
KML;
$parsed = WeatherFeed::parse($sample);
check(count($parsed['hours']) === 2 && $parsed['station'] === 'F9519', 'MOSMIX-Stunden');
check(abs($parsed['hours'][1]['radiation'] - (1320 / 3.6)) < 0.01, 'Rad1h nach W/m²');
check($parsed['hours'][1]['t'] === strtotime('2026-10-07T17:00:00.000Z') - 3600, 'Stunde beginnt eine Stunde vor dem Zeitstempel');
check(abs((float) $parsed['hours'][0]['temp_c'] - 22.0) < 0.02 && $parsed['hours'][1]['temp_c'] === null, 'Temperatur in Celsius, Fehlwert bleibt leer');
$rejected = false;
try {
    WeatherFeed::assertUrl('https://example.com/MOSMIX.kmz');
} catch (Throwable) {
    $rejected = true;
}
check($rejected, 'nur opendata.dwd.de');
WeatherFeed::assertUrl(WeatherFeed::DEFAULT_URL);

$plant = ['kwp' => 10, 'factor' => 1, 'tilt' => 13, 'azimuth' => 180, 'inverter_kw' => 10, 'regress_days' => 0, 'regress_a' => 0, 'regress_b' => 1];
$now = strtotime('2026-10-07 16:00:00 Europe/Berlin');
$partial = [
    ['t' => strtotime('2026-10-07 15:00:00 Europe/Berlin'), 'kw' => 2.0],
    ['t' => strtotime('2026-10-07 16:00:00 Europe/Berlin'), 'kw' => 1.5],
    ['t' => strtotime('2026-10-07 17:00:00 Europe/Berlin'), 'kw' => 1.0],
];
$brief = Forecast::brief($partial, $plant, $now, 6.5);
check($brief['today_kwh'] === null && abs($brief['remaining_kwh'] - 2.5) < 0.01, 'Ohne Morgenstunden bleibt die Tagessumme offen');
$kept = Forecast::brief($partial, $plant, $now, 6.5, 28.0);
check(abs((float) $kept['today_kwh'] - 28) < 0.01 && abs($kept['remaining_kwh'] - 2.5) < 0.01, 'Gespeicherte Tagessumme bleibt ohne Morgenstunden');
$scaled = $plant;
$scaled['factor'] = 0.5;
$once = Forecast::brief($partial, $scaled, $now, 6.5);
check(abs($once['remaining_kwh'] - 2.5) < 0.01, 'Eichfaktor wird nicht ein zweites Mal auf die Stunden gelegt');
$full = [
    ['t' => strtotime('2026-10-07 00:00:00 Europe/Berlin'), 'kw' => 0.0],
    ['t' => strtotime('2026-10-07 12:00:00 Europe/Berlin'), 'kw' => 4.0],
    ['t' => strtotime('2026-10-07 18:00:00 Europe/Berlin'), 'kw' => 1.0],
];
$covered = Forecast::brief($full, $plant, $now, 3.0);
check(abs($covered['today_kwh'] - 5.0) < 0.01 && abs($covered['remaining_kwh'] - 1.0) < 0.01, 'Gesamtprognose wenn der Tag in der Datei steht');
check(Forecast::displayedModel('pin', 28.0, $scaled) === 28.0, 'festgehaltener Modelltag bleibt 28 kWh');
check(Forecast::displayedModel('drop', 20.0, $plant) === null, 'verworfener Modelltag bleibt leer');
check(abs((float) Forecast::displayedModel(null, 10.0, $scaled) - 5) < 0.01, 'normaler Modelltag nimmt den Eichfaktor');
$three = Forecast::windowDays(['2026-10-05', '2026-10-06', '2026-10-07', '2026-09-30'], '2026-10-08', '3');
sort($three);
check($three === ['2026-10-05', '2026-10-06', '2026-10-07'], 'Gütefenster drei abgeschlossene Tage');
check(Forecast::windowDays(['2026-10-05', '2026-09-30'], '2026-10-08', 'month') === ['2026-10-05'], 'Gütefenster Monat');
check(Forecast::windowDays(['2026-10-05', '2026-09-30'], '2026-10-08', 'quarter') === ['2026-10-05'], 'Gütefenster Quartal');
check(Forecast::captionText(10.47, 0.04) === '10,5 ± 0,0' . NNBSP . 'kWh', 'Beschriftung rundet wie die Tabelle');
check(Forecast::captionText(10.7, null) === '10,7' . NNBSP . 'kWh', 'ohne Streuung nur der Prognosewert');
check(kw(11.0) === '11,0' . NNBSP . 'kW' && pct(57.0) === '57' . NNBSP . '%' && euro(500.7) === '500,70' . NNBSP . '€', 'Einheit mit schmalem Leerzeichen, Leistung mit einer Stelle');
// Energiefluss: jede Seite füllt die ganze Breite, auch wenn Rein und Raus nicht genau gleich sind.
$flow = Energy::flowBar(
    ['pv_kw' => 5.0, 'battery_discharge_kw' => 0.0, 'grid_import_kw' => 0.5, 'wallbox_kw' => 3.0, 'battery_charge_kw' => 1.0, 'grid_export_kw' => 0.8, 'battery_soc' => 62.4],
    ['house_base_kw' => 0.5]
);
$lastTo = static fn (array $items): float => (float) end($items)['to'];
check(abs($lastTo($flow['sources']) - 1) < 0.0001 && abs($lastTo($flow['sinks']) - 1) < 0.0001, 'Klammern oben und unten über die ganze Breite');
check(abs($flow['in_kw'] - 5.5) < 0.001 && abs($flow['out_kw'] - 5.3) < 0.001 && $flow['soc'] === 62.4, 'Rein, Raus und Ladestand im Energiefluss');
check(array_column($flow['sources'], 'key') === ['grid', 'pv'] && array_column($flow['sinks'], 'key') === ['house', 'wallbox', 'battery', 'grid'], 'Klammern nur für Flüsse über null');
$segments = array_column($flow['segments'], 'kw', 'key');
check(abs($segments['solar'] - 4.2) < 0.001 && abs($segments['grid_out'] - 0.8) < 0.001 && abs(array_sum($segments) - 5.5) < 0.001, 'Balken: Netzbezug, Eigenverbrauch und Einspeisung ergeben Rein');
$todayForecast = ['today_kwh' => 33.1, 'remaining_kwh' => 12.4];
check(Snapshot::forecastValue($todayForecast) === '33,1' . NNBSP . 'kWh' && Snapshot::forecastRest($todayForecast) === 'Rest 12,4' . NNBSP . 'kWh', 'Prognose-Zeile mit Tageswert und Rest');
check(Snapshot::forecastValue(null) === '—' && Snapshot::forecastRest(null) === 'Noch keine Prognose', 'Prognose-Zeile ohne Prognose');
check(Sessions::carConnected('WaitCar') === true && Sessions::carConnected('idle') === false && Sessions::carConnected('unknown') === null && Sessions::carConnected('idle', 3.0) === true, 'Angesteckt aus dem Wallbox-Status');
$periods = Sessions::plugPeriods([['t' => 100, 's' => 'idle'], ['t' => 200, 's' => 'wait_car'], ['t' => 300, 's' => 'charging'], ['t' => 400, 's' => 'unknown'], ['t' => 500, 's' => 'idle'], ['t' => 900, 's' => 'complete']]);
check($periods === [[200, 500], [900, null]], 'Ansteckzeiträume aus dem Statusverlauf');
$cycle = static fn (int $id, string $start, ?string $end, float $kwh, ?string $plug): array => ['id' => $id, 'started_at' => $start, 'ended_at' => $end, 'energy_kwh' => $kwh, 'solar_kwh' => $kwh / 2, 'grid_kwh' => $kwh / 2, 'duration_s' => 600, 'plug_at' => $plug, 'vehicle' => 'ID.3', 'odometer' => null, 'meter_start' => null, 'meter_end' => null];
$grouped = Sessions::groups([
    $cycle(3, '2026-10-08T16:40:00+02:00', '2026-10-08T17:20:00+02:00', 2.0, '2026-10-08T14:00:00+02:00'),
    $cycle(1, '2026-10-08T14:02:00+02:00', '2026-10-08T14:40:00+02:00', 3.0, '2026-10-08T14:00:00+02:00'),
    $cycle(4, '2026-10-09T09:00:00+02:00', null, 1.5, '2026-10-09T08:55:00+02:00'),
    $cycle(2, '2026-10-08T15:10:00+02:00', '2026-10-08T15:50:00+02:00', 4.0, '2026-10-08T14:00:00+02:00'),
    $cycle(5, '2026-10-07T10:00:00+02:00', '2026-10-07T11:00:00+02:00', 5.0, null),
]);
check(count($grouped) === 3 && $grouped[0]['id'] === 4 && $grouped[0]['ended_at'] === null, 'Neuester Ladevorgang zuerst, offen solange ein Zyklus läuft');
check($grouped[1]['id'] === 1 && count($grouped[1]['cycles']) === 3 && abs($grouped[1]['energy_kwh'] - 9.0) < 0.001 && $grouped[1]['ended_at'] === '2026-10-08T17:20:00+02:00', 'Drei Zyklen eines Ansteckens ergeben einen Ladevorgang');
check(count($grouped[2]['cycles']) === 1, 'Ohne Ansteckzeit bleibt ein Zyklus für sich');
// Diagramm der Ladevorgänge: heute ganz rechts, davor die Tage bis in den Vormonat.
$chartNow = strtotime('2026-10-10 12:00:00 Europe/Berlin');
$axis = Sessions::chartAxis('month', '2026-10', 2026, $chartNow);
check(count($axis['keys']) === 30 && $axis['keys'][0] === '2026-09-11' && end($axis['keys']) === '2026-10-10' && $axis['period'] === [20, 29] && $axis['today'] === 29, 'Laufender Monat endet heute und reicht 30 Tage zurück');
$past = Sessions::chartAxis('month', '2026-09', 2026, $chartNow);
check(count($past['keys']) === 30 && $past['period'] === [0, 29] && $past['today'] === null, 'Vergangener Monat zeigt genau seine Tage');
$yearAxis = Sessions::chartAxis('year', '2026-10', 2026, $chartNow);
check($yearAxis['keys'][0] === '2025-11' && end($yearAxis['keys']) === '2026-10' && $yearAxis['period'] === [2, 11] && $yearAxis['today'] === 11, 'Laufendes Jahr endet mit dem aktuellen Monat');
$chart = Sessions::chart([$cycle(9, '2026-09-30T10:00:00+02:00', '2026-09-30T11:00:00+02:00', 4.0, null), $cycle(10, '2026-10-09T10:00:00+02:00', '2026-10-09T11:00:00+02:00', 6.0, null)], 'month', 'energy', ['import_ct' => 30, 'export_ct' => 8], '2026-10', 2026, $chartNow);
check($chart['view'] === [20, 29] && abs($chart['series'][0]['data'][19] - 2.0) < 0.001 && abs($chart['series'][0]['data'][28] - 3.0) < 0.001, 'Tage aus dem Vormonat stehen links im Diagramm');
check(Snapshot::levelText(6, 3) === '6' . NNBSP . 'A · 3-phasig' && Snapshot::levelText(0, 1) === 'aus', 'Stufe als Text');
check(Snapshot::splitText(['sun_kw' => 4.14, 'battery_kw' => 0.0, 'grid_kw' => 0.0, 'mixed_kw' => 0.0, 'charge_kw' => 0.4, 'export_kw' => 0.0]) === 'Auto: 4,1' . NNBSP . 'kW aus der Sonne. Speicher lädt 0,4' . NNBSP . 'kW.', 'Aufteilung in einem Satz');
check(Sessions::summary(array_merge(...array_column($grouped, 'cycles')), [])['count'] === 3, 'Gezählt werden Ladevorgänge, nicht Zyklen');
check(ui_field_num(1500.0, 0) === '1500' && ui_field_num(10.03, 2) === '10,03' && ui_field_num(13.0, 1) === '13' && ui_field_num(-2.1, 2) === '-2,1', 'Zahlenfeld ohne Tausenderpunkt und ohne Nullen am Ende');
$_POST['co2_g_kwh'] = ui_field_num(1500.0, 0);
check(post_float('co2_g_kwh', 0, 1500, 380) === 1500.0, 'Zahlenfeld liest sich zurück');
unset($_POST['co2_g_kwh']);
check(kw(0.234, 2) === '0,23' . NNBSP . 'kW' && kw(null) === '—', 'Regelungsdetails mit zwei Stellen, fehlender Wert als Strich');
check(metric(57.84, '%', 1) === '<span class="num">57,8</span>' . NNBSP . '<span class="unit">%</span>', 'Kennzahl trennt Zahl und Einheit');
$scale = Forecast::energyScale(5.248);
check(abs($scale['dataMax'] - 5.5) < 0.001 && abs($scale['max'] - 6.5) < 0.001 && abs($scale['step'] - 0.5) < 0.001, 'Energieskala in 0,5-kWh-Schritten mit Platz über der Kurve');
$exact = Forecast::energyScale(5.0);
check(abs($exact['dataMax'] - 5) < 0.001 && abs($exact['max'] - 6) < 0.001, 'voller 0,5-Schritt bleibt auf dem Wert');
$boardPlant = ['factor' => 0.5, 'regress_a' => 1, 'regress_b' => 0.8, 'regress_days' => 0];
$board = Forecast::modelBoard('2026-10-08', [
    ['day' => '2026-10-04', 'actual_kwh' => 9, 'model_kwh' => 9, 'model_mode' => 'drop'],
    ['day' => '2026-10-05', 'actual_kwh' => 10, 'model_kwh' => 20, 'model_mode' => null],
    ['day' => '2026-10-06', 'actual_kwh' => 8, 'model_kwh' => 16, 'model_mode' => null],
    ['day' => '2026-10-07', 'actual_kwh' => 25.7, 'model_kwh' => 28, 'model_mode' => 'pin'],
], [
    '2026-10-08' => ['kwh' => 5.0, 'sd' => 0.1, 'pinned' => false],
    '2026-10-09' => ['kwh' => 6.0, 'sd' => 0.2, 'pinned' => false],
    '2026-10-10' => ['kwh' => 7.0, 'sd' => null, 'pinned' => false],
], [
    '2026-10-08' => ['mean' => 10.0],
    '2026-10-09' => ['mean' => 12.0],
    '2026-10-10' => ['mean' => 14.0],
], 4.2, $boardPlant, 5.0);
$boardDays = array_map(static fn (array $row): string => $row['day'], $board);
check($boardDays === ['2026-10-10', '2026-10-09', '2026-10-08', '2026-10-07', '2026-10-06', '2026-10-05'], 'Modelltafel: kommende Tage, heute, vergangene Modelltage');
$boardToday = $board[2];
check($boardToday['today'] === true && $boardToday['gute'] === null && $boardToday['regress'] === null && abs((float) $boardToday['model'] - 5) < 0.01 && abs((float) $boardToday['raw'] - 10) < 0.01 && abs((float) $boardToday['fitted'] - 5) < 0.01 && abs((float) $boardToday['sd'] - 0.1) < 0.001, 'heutiger Tag hat Rohmodell, Faktor und Abweichung, die Güte und die Regression bleiben leer');
$lesson = Forecast::lesson('2026-10-08', [
    '2026-10-08' => ['mean' => 10.0, 'sd' => 0.2, 'n' => 3],
], [
    '2026-10-08' => ['kwh' => 5.0, 'sd' => 0.1, 'pinned' => false],
], 4.2, ['factor' => 0.5, 'regress_days' => 2], [
    '2026-10-06' => ['actual' => 8.0, 'model' => 4.0],
    '2026-10-07' => ['actual' => 10.0, 'model' => 6.0],
], ['2026-10-07', '2026-10-06']);
check(abs((float) $lesson['raw'] - 10) < 0.01 && abs((float) $lesson['raw'] * (float) $lesson['factor'] - 5) < 0.01 && abs((float) $lesson['gute'] - (10 / 18)) < 0.001 && abs((float) $lesson['sd_raw'] - 0.2) < 0.001 && count($lesson['pairs']) === 2 && $lesson['issues'] === [] && $lesson['sample_mean'] === null, 'Rechenbeispiel trennt Eichfaktor, Abweichung und Güte');
$samples = Forecast::factorSamples([
    ['day' => '2026-10-08', 'actual_kwh' => 4, 'model_kwh' => 8, 'model_mode' => null],
    ['day' => '2026-10-07', 'actual_kwh' => 25.7, 'model_kwh' => 28, 'model_mode' => 'pin'],
    ['day' => '2026-10-06', 'actual_kwh' => 8, 'model_kwh' => 16, 'model_mode' => null],
    ['day' => '2026-10-04', 'actual_kwh' => 9, 'model_kwh' => 9, 'model_mode' => 'drop'],
    ['day' => '2026-10-05', 'actual_kwh' => 10, 'model_kwh' => 0.5, 'model_mode' => null],
], '2026-10-08');
check(count($samples) === 1 && $samples[0]['day'] === '2026-10-06' && abs($samples[0]['ratio'] - 0.5) < 0.001, 'Eichfaktor-Vorschau lässt heute, Pin, Drop und zu kleines Rohmodell weg');
$previewMean = ((31.7 / 32.9) + (31.3 / 33.1)) / 2;
$withRuns = Forecast::lesson('2026-10-08', [
    '2026-10-08' => ['mean' => 10.0, 'sd' => 0.2, 'n' => 2],
], [
    '2026-10-08' => ['kwh' => 5.0, 'sd' => 0.1, 'pinned' => false],
], 4.2, ['factor' => 0.5, 'factor_locked' => false, 'regress_days' => 2, 'regress_a' => 247.31, 'regress_b' => -3.59], [
    '2026-10-06' => ['actual' => 8.0, 'model' => 4.0],
    '2026-10-07' => ['actual' => 10.0, 'model' => 6.0],
], ['2026-10-06', '2026-10-07'], [
    ['issue' => strtotime('2026-10-08 06:00:00 Europe/Berlin'), 'kwh' => 9.9, 'radiation' => 2000, 'sunshine_s' => 3600, 'cloud' => 40, 'temp_c' => 12, 'hours' => 10],
    ['issue' => strtotime('2026-10-08 09:00:00 Europe/Berlin'), 'kwh' => 10.1, 'radiation' => 2100, 'sunshine_s' => 4000, 'cloud' => 30, 'temp_c' => 13, 'hours' => 10],
], [
    ['day' => '2026-10-05', 'actual' => 31.7, 'raw' => 32.9, 'ratio' => 31.7 / 32.9],
    ['day' => '2026-10-06', 'actual' => 31.3, 'raw' => 33.1, 'ratio' => 31.3 / 33.1],
]);
check(count($withRuns['issues']) === 2 && abs((float) $withRuns['sample_mean'] - $previewMean) < 0.0001, 'Rechnung reicht Läufe und ungenutzte Verhältnisse durch');
ob_start();
forecast_method_dialog($withRuns, ['kwp' => 10.03, 'inverter_kw' => 10, 'factor' => 0.5, 'tilt' => 13, 'azimuth' => 270], [
    ['day' => '2026-10-09', 'today' => false, 'actual' => null, 'model' => 6.0, 'sd' => 0.2, 'gute' => null, 'raw' => 12.0, 'fitted' => 6.0, 'regress' => null],
    ['day' => '2026-10-08', 'today' => true, 'actual' => 4.2, 'model' => 5.0, 'sd' => 0.1, 'gute' => null, 'raw' => 10.0, 'fitted' => 5.0, 'regress' => null],
]);
$methodHtml = ob_get_clean();
check(str_contains($methodHtml, 'id="forecast-method"') && str_contains($methodHtml, '<math') && str_contains($methodHtml, '10,00') && str_contains($methodHtml, 'noch ungenutzt') && str_contains($methodHtml, 'Die Güte fließt in die kommenden Tage nicht ein.') && !str_contains($methodHtml, '247,31'), 'Rechenfenster zeigt die Schritte und lässt die unfertige Regression weg');
$withRuns['regress_days'] = 5;
$withRuns['regress_a'] = 1.5;
$withRuns['regress_b'] = 0.8;
$withRuns['prognosis'] = 9.5;
ob_start();
forecast_method_dialog($withRuns, ['kwp' => 10.03, 'inverter_kw' => 10, 'factor' => 0.5], [
    ['day' => '2026-10-09', 'today' => false, 'model' => 9.5, 'sd' => 0.16, 'raw' => 12.0, 'fitted' => 6.0],
]);
$regressHtml = ob_get_clean();
check(str_contains($regressHtml, '1,50') && str_contains($regressHtml, '0,80') && str_contains($regressHtml, 'Die Güte fließt in die kommenden Tage nicht ein.') && !str_contains($regressHtml, 'noch ungenutzt'), 'Ab fünf Tagen steht die Regression in der Rechnung');
$readyPlant = $boardPlant;
$readyPlant['regress_days'] = 5;
$readyBoard = Forecast::modelBoard('2026-10-08', [
    ['day' => '2026-10-05', 'actual_kwh' => 10, 'model_kwh' => 20, 'model_mode' => null],
], [
    '2026-10-08' => ['kwh' => 5.0, 'sd' => 0.1, 'pinned' => false],
], [
    '2026-10-08' => ['mean' => 10.0],
], 4.2, $readyPlant, 5.0);
check(isset($readyBoard[0]['regress']) && abs((float) $readyBoard[0]['regress'] - 9) < 0.01, 'ab fünf Tagen steht die Regression in der Zeile');
$boardFuture = $board[0];
check(abs((float) $boardFuture['model'] - 7) < 0.01 && abs((float) $boardFuture['raw'] - 14) < 0.01 && abs((float) $boardFuture['fitted'] - 7) < 0.01, 'kommender Tag übernimmt die Diagrammprognose');
$boardPin = $board[3];
check($boardPin['raw'] === null && abs((float) $boardPin['model'] - 28) < 0.01 && abs((float) $boardPin['gute'] - round(28 / 25.7, 3)) < 0.001, 'festgehaltener vergangener Tag bleibt 28 kWh');
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $memory = new PDO('sqlite::memory:');
    Forecast::rememberDays($memory, $full, $plant);
    $storedDay = Forecast::locked($memory, '2026-10-07');
    check($storedDay !== null && abs($storedDay - 5) < 0.01, 'voller Tag wird gespeichert');
    Forecast::rememberDays($memory, $partial, $plant);
    $keptDay = Forecast::locked($memory, '2026-10-07');
    check($keptDay !== null && abs($keptDay - 5) < 0.01, 'gespeicherter Tag bleibt ohne Morgenstunden');
    check(count(Forecast::lockedFrom($memory, '2026-10-07')) === 1, 'gespeicherte Tage lassen sich lesen');
    $issueHours = static function (int $noon): array {
        return [
            ['t' => strtotime('2026-10-08 00:00:00 Europe/Berlin'), 'radiation' => 0, 'cloud' => 80, 'sunshine_s' => 0, 'temp_c' => 8],
            ['t' => strtotime('2026-10-08 12:00:00 Europe/Berlin'), 'radiation' => $noon, 'cloud' => 20, 'sunshine_s' => 3600, 'temp_c' => 16],
        ];
    };
    Forecast::rememberIssue($memory, $issueHours(1000), $plant, 1000);
    Forecast::rememberIssue($memory, $issueHours(500), $plant, 2000);
    Forecast::rememberIssue($memory, [
        ['t' => strtotime('2026-10-08 18:00:00 Europe/Berlin'), 'radiation' => 900, 'cloud' => 10, 'sunshine_s' => 0, 'temp_c' => 12],
    ], $plant, 3000);
    $stats = Forecast::issueStats($memory);
    check(($stats['2026-10-08']['n'] ?? 0) === 2 && $stats['2026-10-08']['sd'] > 0, 'Modellläufe bilden Mittelwert und Streuung, unvollständige Läufe bleiben draussen');
    $dayRuns = Forecast::dayIssues($memory, '2026-10-08');
    check(count($dayRuns) === 2 && $dayRuns[0]['issue'] === 1000 && $dayRuns[0]['kwh'] > $dayRuns[1]['kwh'] && (float) $dayRuns[0]['radiation'] > 0, 'Modellläufe eines Tages bleiben einzeln lesbar');
    $rows = Forecast::archiveRows($memory, $plant, ['2026-10-08' => 4.2]);
    check(count($rows) === 1 && abs((float) $rows[0]['actual'] - 4.2) < 0.01 && $rows[0]['mean'] > 0, 'Archiv verbindet Ertrag und Prognose');
    $memory->exec('CREATE TABLE daily (day TEXT PRIMARY KEY, actual_kwh REAL, model_kwh REAL, model_mode TEXT)');
    $memory->exec("INSERT INTO daily (day, actual_kwh, model_kwh, model_mode) VALUES ('2026-10-07', 25.7, 28.0, 'pin')");
    $pinned = null;
    foreach (Forecast::archiveRows($memory, $scaled, ['2026-10-07' => 25.7]) as $row) {
        if ($row['day'] === '2026-10-07') {
            $pinned = $row;
        }
    }
    check($pinned && abs((float) $pinned['mean'] - 28) < 0.01 && $pinned['sd'] === null, 'ergänzter 7. Oktober bleibt 28 kWh');
    $outlook = Forecast::storageOutlook([
        ['t' => strtotime('2026-10-08 12:00:00 Europe/Berlin'), 'kw' => 3],
        ['t' => strtotime('2026-10-08 13:00:00 Europe/Berlin'), 'kw' => 3],
        ['t' => strtotime('2026-10-08 14:00:00 Europe/Berlin'), 'kw' => 3],
    ], strtotime('2026-10-08 12:00:00 Europe/Berlin'), 50, 5, 0, 80);
    check($outlook['reachable'] && $outlook['full_at'] !== null && $outlook['surplus_kwh'] > 0 && abs((float) $outlook['full_kwh'] - 10) < 0.01, 'Speicherfüllung aus dem Sonnenüberschuss');
    $withTotal = Forecast::storageOutlook([
        ['t' => strtotime('2026-10-08 12:00:00 Europe/Berlin'), 'kw' => 4],
    ], strtotime('2026-10-08 12:00:00 Europe/Berlin'), 50, 5, 1, 80, 20, 90);
    check(abs((float) $withTotal['full_kwh'] - 20) < 0.01 && $withTotal['full_at'] === null && $withTotal['reachable'], 'Gesamtkapazität sticht die Restkapazität');
    $bufferHit = Forecast::storageOutlook([
        ['t' => strtotime('2026-10-08 12:00:00 Europe/Berlin'), 'kw' => 4],
    ], strtotime('2026-10-08 12:00:00 Europe/Berlin'), 50, 10, 0, 80, 20, 60);
    check($bufferHit['buffer_open'] && $bufferHit['buffer_at'] !== null && $bufferHit['priority_at'] === null, 'Auto-Puffer liegt vor dem Speicher-Vorrang');
}
$meanHouse = Energy::meanHouseBase(
    [['start' => 1, 'kw' => 2.0], ['start' => 2, 'kw' => 1.0]],
    [['start' => 1, 'kw' => 0.5], ['start' => 2, 'kw' => 0.0]],
    true
);
check($meanHouse !== null && abs($meanHouse - 1.25) < 0.0001, 'Hausmittel ohne Wallbox');
$axis = Series::capacityAxis(20.46, 16.5);
check(abs($axis['yMax'] - 20.46) < 0.001 && abs($axis['yStep'] - 2) < 0.001 && $axis['yTitle'] === 'Kapazität (kWh)', 'Kapazitätsachse bleibt bei der Gesamtkapazität');
$berlin = new DateTimeZone('Europe/Berlin');
$morning = (new DateTimeImmutable('2026-10-08 08:00:00', $berlin))->getTimestamp() * 1000;
$evening = (new DateTimeImmutable('2026-10-08 18:00:00', $berlin))->getTimestamp() * 1000;
$nextNoon = (new DateTimeImmutable('2026-10-09 12:00:00', $berlin))->getTimestamp() * 1000;
$extrema = Series::dayExtrema([
    ['x' => $morning, 'y' => 40],
    ['x' => $evening, 'min' => 30, 'max' => 70],
    ['x' => $nextNoon, 'y' => 55],
]);
check(count($extrema) === 2 && abs($extrema[0]['min']['y'] - 30) < 0.01 && abs($extrema[0]['max']['y'] - 70) < 0.01 && abs($extrema[1]['min']['y'] - 55) < 0.01, 'Tagesminimum und Tagesmaximum');
$sampleZones = zone_thresholds(50, 80, 90);
$orderedZones = zone_thresholds(82, 70, 60);
check(abs($sampleZones['priority_soc'] - 50) < 0.01 && abs($sampleZones['car_buffer_soc'] - 80) < 0.01 && abs($sampleZones['car_auto_soc'] - 90) < 0.01, 'Beispielzonen 50, 80 und 90 bleiben stehen');
check(abs($orderedZones['priority_soc'] - 80) < 0.01 && abs($orderedZones['car_buffer_soc'] - 80) < 0.01 && abs($orderedZones['car_auto_soc'] - 80) < 0.01, 'Zonen rasten auf 5 Prozent und bleiben geordnet');
$batteryIcon = icon('battery');
check(str_contains($batteryIcon, 'icons.svg') && str_contains($batteryIcon, '#battery"') && str_contains($batteryIcon, 'aria-hidden="true"'), 'Icon verweist ins Sprite');
$sprite = @file_get_contents(EMS_APP . '/public/assets/build/icons.svg');
if (is_string($sprite)) {
    preg_match('/<symbol id="battery"[^>]*>(.*?)<\/symbol>/s', $sprite, $batterySymbol);
    check(isset($batterySymbol[1]) && str_contains($batterySymbol[1], 'width="16"') && str_contains($batterySymbol[1], 'width="9"'), 'Batterie im Sprite behält die Flächen');
    check(str_contains($sprite, 'stroke-width="1.75"'), 'Sprite mit 1,75 px Strich');
}

$kmz = '/tmp/MOSMIX_L_LATEST_F9519.kmz';
if (is_file($kmz)) {
    $kml = shell_exec('unzip -p ' . escapeshellarg($kmz));
    $real = WeatherFeed::parse((string) $kml);
    check(count($real['hours']) > 200 && $real['station'] === 'F9519' && $real['name'] === 'SOONWALD WEST 4', 'echte Soonwald-Datei');
    $peak = 0.0;
    foreach ($real['hours'] as $hour) {
        $peak = max($peak, (float) ($hour['radiation'] ?? 0));
    }
    check($peak > 50 && $peak < 1400, 'Strahlung der echten Datei in W/m², Spitze ' . round($peak));
}

// Mitteilungen: Vorlagen, Platzhalter, Ruhezeit, Erkennung der Ereignisse und Versand.
check(Notify::render('{auto} lädt mit {leistung}, {foo}', ['auto' => 'ID.3', 'leistung' => '4,1 kW']) === 'ID.3 lädt mit 4,1 kW, {foo}' && Notify::unknown('{auto} {foo} {foo}') === ['foo'], 'Platzhalter eingesetzt, unbekannte bleiben stehen und werden genannt');
check(Notify::text("  Zeile\t1  \r\n\r\n\r\n Zeile 2 ", 500, true) === "Zeile 1\n\nZeile 2" && Notify::text("Titel\nmit Umbruch", 120) === 'Titel mit Umbruch' && Notify::text('   ', 120) === null, 'Vorlagen ohne Steuerzeichen, der Titel einzeilig, leer heißt Standard');
check(Notify::clock('7:05', 'x') === '07:05' && Notify::clock('25:00', '20:00') === '20:00' && Notify::targets('mobile_app_a, Mobile_App_B,send_message,../x,mobile_app_a') === ['mobile_app_a', 'mobile_app_b'], 'Uhrzeiten und Geräte geprüft');
$notifyDefaults = Notify::settings([]);
check($notifyDefaults['events']['start']['on'] && !$notifyDefaults['events']['plug']['on'] && $notifyDefaults['events']['fault']['important'] && !$notifyDefaults['events']['start']['important'] && $notifyDefaults['report_time'] === '20:00' && $notifyDefaults['targets'] === [], 'Mitteilungen ab Werk: Wichtiges und Laden an, Geräte leer');
$notifyCustom = Notify::settings(['notify' => ['events' => ['start' => ['title' => '⚡ {auto}', 'on' => false]], 'full_soc' => 300, 'report_time' => '7:5']]);
$notifyStored = Notify::stored($notifyCustom);
check($notifyCustom['events']['start']['custom'] && !$notifyCustom['events']['start']['on'] && $notifyCustom['full_soc'] === 100.0 && $notifyCustom['report_time'] === '20:00'
    && $notifyStored['events']['start']['title'] === '⚡ {auto}' && $notifyStored['events']['start']['message'] === null && $notifyStored['events']['plug']['title'] === null, 'Eigene Texte bleiben, Grenzen und Uhrzeit geprüft, Standardtexte als null gespeichert');
$quietCfg = ['quiet' => true, 'quiet_from' => '22:00', 'quiet_to' => '07:00'];
$at = static fn (string $time): int => (int) strtotime('2026-10-12 ' . $time . ':00 Europe/Berlin');
check(Notify::quiet($quietCfg, $at('23:30')) && Notify::quiet($quietCfg, $at('06:59')) && !Notify::quiet($quietCfg, $at('07:00')) && !Notify::quiet($quietCfg, $at('12:00')) && !Notify::quiet(['quiet' => false] + $quietCfg, $at('23:30')), 'Ruhezeit über Mitternacht');
$notifyCtx = Notify::context(
    ['values' => ['pv_kw' => 5.24, 'battery_soc' => 81.0], 'vehicle' => ['name' => 'ID.3', 'soc' => 64.0], 'cfg' => ['charge' => ['mode' => 'smart'], 'tariffs' => ['import_ct' => 30, 'export_ct' => 8]]],
    $at('14:05'),
    ['energy_kwh' => 12.4, 'solar_kwh' => 9.3, 'grid_kwh' => 3.1, 'duration_s' => 7800]
);
check($notifyCtx['auto'] === 'ID.3' && $notifyCtx['pv'] === '5,2' . NNBSP . 'kW' && $notifyCtx['geladen'] === '12,4' . NNBSP . 'kWh' && $notifyCtx['dauer'] === '2:10 h' && $notifyCtx['sonnenanteil'] === '75' . NNBSP . '%'
    && $notifyCtx['kosten'] === '1,67' . NNBSP . '€' && $notifyCtx['modus'] === 'Solar' && $notifyCtx['uhrzeit'] === '14:05' && $notifyCtx['ladestand'] === '64' . NNBSP . '%' && $notifyCtx['grund'] === '—', 'Werte der Platzhalter wie auf den Seiten');
$notifyState = [];
$notifyIn = ['connected' => false, 'charging' => false, 'full' => false, 'open_id' => null, 'latest_id' => null, 'latest_at' => null, 'battery_soc' => 50.0, 'export_kw' => 0.0, 'missing' => [], 'faults' => [], 'stuck' => false, 'target_done' => null, 'tomorrow_kwh' => 30.0];
$detect = static function (array $patch, int $now) use (&$notifyState, &$notifyIn, $notifyDefaults): array {
    $notifyIn = array_merge($notifyIn, $patch);
    $result = Notify::detect($notifyState, $notifyIn, $notifyDefaults, $now);
    $notifyState = $result['state'];
    return $result['events'];
};
$names = static fn (array $events): array => array_column($events, 'event');
$t = $at('09:00');
check($detect([], $t) === [], 'Der erste Lauf übernimmt nur den Stand');
check($names($detect(['connected' => true], $t + 10)) === ['plug'], 'Angesteckt');
check($names($detect(['open_id' => 1, 'latest_id' => 1, 'latest_at' => $t + 20, 'charging' => true], $t + 20)) === ['start'], 'Laden gestartet');
check($detect(['open_id' => null, 'charging' => false], $t + 600) === [] && $detect([], $t + 900) === [] && $detect(['open_id' => 2, 'latest_id' => 2, 'latest_at' => $t + 1000, 'charging' => true], $t + 1000) === [], 'Kurze Pause der Sonne meldet weder Ende noch Start');
check($detect(['open_id' => null, 'charging' => false], $t + 3000) === [] && $names($detect([], $t + 3000 + Notify::PAUSE_S)) === ['stop'], 'Laden beendet nach 10 Minuten Ruhe');
check($names($detect(['open_id' => 3, 'latest_id' => 3, 'latest_at' => $t + 5000, 'charging' => true], $t + 5000)) === ['start'], 'Nach langer Pause wieder ein Start');
$unplugged = $detect(['connected' => false, 'charging' => false], $t + 6000);
check($names($unplugged) === ['stop', 'unplug'] && $unplugged[0]['data']['session'] === 3 && $unplugged[1]['data']['session'] === 3, 'Abstecken mitten im Laden: erst das Ende, dann die Bilanz des Ladevorgangs');
check($detect(['open_id' => null], $t + 6200) === [], 'Schließt der Zyklus danach, kommt kein zweites Ende');
$detect(['connected' => true], $t + 7000);
$detect(['open_id' => 4, 'latest_id' => 4, 'latest_at' => $t + 7010, 'charging' => true], $t + 7010);
check($names($detect(['open_id' => null, 'charging' => false, 'full' => true], $t + 9000)) === ['stop'], 'Ist das Auto voll, kommt das Ende sofort');
$target = $detect(['target_done' => ['t' => $t + 9100, 'label' => 'Ziel 20 kWh']], $t + 9100);
check($names($target) === ['target'] && $target[0]['data']['ziel'] === 'Ziel 20 kWh' && $detect([], $t + 9110) === [], 'Ladeziel erreicht, einmal');
check($names($detect(['faults' => ['paused' => 'evcc schreibt']], $t + 9200)) === ['fault'] && $detect([], $t + 9210) === [] && $detect(['faults' => []], $t + 9220) === [] && $names($detect(['faults' => ['paused' => 'evcc schreibt']], $t + 9230)) === ['fault'], 'Eine Störung meldet sich einmal je Auftreten');
check($detect(['missing' => ['Wallbox']], $t + 9300) === [] && $detect([], $t + 9300 + Notify::OFFLINE_S - 10) === [] && $names($detect([], $t + 9300 + Notify::OFFLINE_S)) === ['offline'] && $detect([], $t + 9300 + 2 * Notify::OFFLINE_S) === [], 'Fehlende Messwerte nach 10 Minuten, einmal');
$detect(['missing' => []], $t + 11000);
check($detect(['stuck' => true], $t + 11100) === [] && $names($detect([], $t + 11100 + Notify::STUCK_S)) === ['stuck'] && $detect([], $t + 11100 + 2 * Notify::STUCK_S) === [], 'Auto lädt nicht trotz Freigabe, nach 5 Minuten einmal');
$detect(['stuck' => false], $t + 12000);
check($names($detect(['battery_soc' => 100.0], $t + 12100)) === ['battery_full'] && $detect(['battery_soc' => 97.0], $t + 12200) === [] && $detect(['battery_soc' => 89.0], $t + 12300) === [] && $detect(['battery_soc' => 100.0], $t + 12400) === [], 'Speicher voll höchstens einmal am Tag');
check($names($detect(['battery_soc' => 20.0], $t + 12500)) === ['battery_low'], 'Speicher fast leer');
$detect(['connected' => false], $t + 12600);
check($detect(['export_kw' => 3.2], $t + 12700) === [] && $names($detect([], $t + 12700 + Notify::SURPLUS_S)) === ['surplus'] && $detect(['export_kw' => 0.0], $t + 13500) === [] && $detect(['export_kw' => 3.2], $t + 13600) === [] && $detect([], $t + 13600 + Notify::SURPLUS_S) === [], 'Sonne übrig ohne Auto, höchstens einmal am Tag');
check($names($detect(['export_kw' => 0.0], $at('20:05'))) === ['daily', 'sunny'] && $detect([], $at('20:10')) === [], 'Tagesbericht und sonniger Tag zur Berichtszeit, einmal');
$monthly = $detect(['tomorrow_kwh' => 12.0], (int) strtotime('2026-11-01 20:00:30 Europe/Berlin'));
check($names($monthly) === ['daily', 'monthly'] && $monthly[1]['data']['month'] === '2026-10', 'Am Ersten der Monatsbericht für den Vormonat');
$later = (int) strtotime('2026-11-02 10:00:00 Europe/Berlin');
check($detect(['mode' => 'smart', 'control' => false], $later) === [], 'Modus und Hauptschalter aus einem alten Stand ohne Meldung übernommen');
$modeChange = $detect(['mode' => 'schnell'], $later + 10);
check($names($modeChange) === ['mode'] && $modeChange[0]['data']['vorher'] === 'Solar' && $detect([], $later + 20) === [] && $names($detect(['control' => true], $later + 30)) === ['control'], 'Lademodus geändert und Regelung ein');
$notifyHa = new class implements HaSource {
    public array $sent = [];
    public bool $fail = false;
    public function setNumber(string $entityId, float $value): void {}
    public function service(string $domain, string $service, array $data): void {}
    public function notifyServices(): array { return ['mobile_app_pixel_8']; }
    public function notify(string $service, array $payload): void
    {
        if ($this->fail) {
            throw new RuntimeException('nicht erreichbar');
        }
        $this->sent[] = [$service, $payload];
    }
    public function configured(): bool { return true; }
    public function ping(): array { return ['ok' => true]; }
    public function states(): array { return []; }
    public function state(string $entityId): ?array { return null; }
    public function index(): array { return ['device_tracker.pixel_8' => ['attributes' => ['friendly_name' => 'Pixel von Kim']]]; }
    public function history(string $entityId, int $start, ?int $end = null): array { return []; }
    public function stateHistory(string $entityId, int $start, ?int $end = null): array { return []; }
    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array { return []; }
    public function search(string $query, int $limit = 20): array { return []; }
};
$notifier = new Notify(new ConfigStore(':memory:'), $notifyHa);
$quiet = Notify::settings(['notify' => ['targets' => ['mobile_app_pixel_8'], 'open_app' => false] + $quietCfg]);
$sent = $notifier->deliver('start', 'ID.3 lädt', 'Mit 4,1 kW', $quiet, false, $at('23:30'));
check($sent['ok'] && $sent['status'] === 'silent' && $notifyHa->sent[0][0] === 'mobile_app_pixel_8' && $notifyHa->sent[0][1]['data']['push']['interruption-level'] === 'passive' && $notifyHa->sent[0][1]['data']['tag'] === 'ems-start' && $notifyHa->sent[0][1]['title'] === 'ID.3 lädt', 'In der Ruhezeit leise an die App');
$sent = $notifier->deliver('fault', 'EMS: Störung', 'evcc', $quiet, true, $at('23:31'));
check($sent['status'] === 'sent' && $notifyHa->sent[1][1]['data']['push']['interruption-level'] === 'time-sensitive' && $notifyHa->sent[1][1]['data']['priority'] === 'high' && $notifyHa->sent[1][1]['data']['channel'] === 'EMS wichtig', 'Wichtige Meldungen mit Ton, auch in der Ruhezeit');
$notifyHa->fail = true;
$sent = $notifier->deliver('stop', 'x', 'y', $quiet, false, $at('23:32'), null, true);
$notifyLog = $notifier->log(5);
check(!$sent['ok'] && $sent['status'] === 'error' && str_contains((string) $sent['error'], 'nicht erreichbar') && count($notifyLog) === 3 && $notifyLog[0]['event'] === 'Test · Laden beendet' && $notifyLog[2]['status_text'] === 'leise', 'Fehler beim Senden stehen im Protokoll, neueste zuerst');
check(Notify::panelPath('a0d7b954_ems', '2026.10.1') === '/app/a0d7b954_ems' && Notify::panelPath('a0d7b954_ems', '2026.2.0') === '/app/a0d7b954_ems' && Notify::panelPath('a0d7b954_ems', '2026.1.3') === '/hassio/ingress/a0d7b954_ems'
    && Notify::panelPath('a0d7b954_ems', '2025.12.4') === '/hassio/ingress/a0d7b954_ems' && Notify::panelPath('local_ems', null) === '/app/local_ems', 'Tippen öffnet die App: ab Home Assistant 2026.2 unter /app/, davor unter /hassio/ingress/');
$devices = $notifier->services(['mobile_app_alt']);
$panelStore = new ConfigStore(':memory:');
$panelStore->put('notify_slug', 'a0d7b954_ems');
$notifyHa->fail = false;
(new Notify($panelStore, $notifyHa))->deliver('test', 'EMS Test', 'x', Notify::settings(['notify' => ['targets' => ['mobile_app_pixel_8']]]), false, $at('12:00'), null, true);
$lastSent = end($notifyHa->sent);
check($lastSent[1]['data']['clickAction'] === '/app/a0d7b954_ems' && $lastSent[1]['data']['url'] === '/app/a0d7b954_ems', 'Ein Tippen auf die Mitteilung öffnet EMS in der App');
check(array_column($devices['list'], 'name') === ['Alt', 'Pixel von Kim'] && $devices['list'][0]['missing'] && !$devices['list'][1]['missing'] && Notify::deviceName('notify') === 'notify.notify' && Notify::deviceName('mobile_app_iphone_15') === 'iPhone 15', 'Gerätenamen aus der App, vergessene Geräte markiert');

// Statistik (Heatmap): Filter, Zeiträume, Werte aus der Langzeitstatistik und aus den Ladevorgängen.
$hmNow = (int) strtotime('2026-10-11 12:00:00 Europe/Berlin');
check(Heatmap::params(['span' => 'year', 'date' => '2025-10'], $hmNow)['date'] === '2025' && Heatmap::params(['span' => 'day', 'date' => '2026-10'], $hmNow)['date'] === '2026-10-11' && Heatmap::params(['span' => 'quarter', 'date' => '2026-10-08'], $hmNow)['date'] === '2026-4' && Heatmap::params(['span' => 'year', 'date' => '1990'], $hmNow)['date'] === '2026', 'Statistik: der Anker eines anderen Zeitraums bleibt, Unsinn wird heute');
$hmCustom = Heatmap::params(['span' => 'custom', 'from' => '2026-10-10', 'to' => '2026-09-01', 'raster' => 'hours'], $hmNow);
check($hmCustom['from'] === '2026-09-01' && $hmCustom['raster'] === 'hours' && Heatmap::params(['span' => 'custom', 'from' => '2026-01-01', 'to' => '2026-10-01', 'raster' => 'hours'], $hmNow)['raster'] === 'days' && Heatmap::params(['span' => 'custom', 'date' => '2026-09'], $hmNow)['to'] === '2026-09-30', 'Eigener Zeitraum: geordnet, Stunden bis 62 Tage, ohne Daten der Zeitraum des Ankers');
$hmQuarter = Heatmap::range(Heatmap::params(['span' => 'quarter', 'date' => '2026-4'], $hmNow), $hmNow);
check($hmQuarter['layout'] === 'calendar' && $hmQuarter['title'] === 'Q4 2026 · Okt. bis Dez.' && $hmQuarter['prev'] === '2026-3' && $hmQuarter['next'] === '2027-1' && Heatmap::range(Heatmap::params(['span' => 'all'], $hmNow), $hmNow)['layout'] === 'years', 'Quartal und Gesamt: Aufbau, Titel, Blättern');
check(Heatmap::text('energy', 1.234, 'hour') === '1,23' . NNBSP . 'kWh' && Heatmap::text('duration', 5400, 'day') === '1,5' . NNBSP . 'h' && Heatmap::text('duration', 1800, 'hour') === '30' . NNBSP . 'min' && Heatmap::text('ratio', 87.6, 'day') === '88' . NNBSP . '%', 'Werte der Statistik mit Einheit');
$hmHa = new class implements HaSource {
    public function setNumber(string $entityId, float $value): void {}
    public function service(string $domain, string $service, array $data): void {}
    public function notifyServices(): array { return []; }
    public function notify(string $service, array $payload): void {}
    public function configured(): bool { return true; }
    public function ping(): array { return ['ok' => true]; }
    public function states(): array { return []; }
    public function state(string $entityId): ?array { return null; }
    public function index(): array { return ['sensor.pv' => ['attributes' => ['unit_of_measurement' => 'W']]]; }
    public function history(string $entityId, int $start, ?int $end = null): array { return []; }
    public function stateHistory(string $entityId, int $start, ?int $end = null): array { return []; }
    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array
    {
        $rows = [];
        $tz = new DateTimeZone('Europe/Berlin');
        for ($d = (new DateTimeImmutable('@' . $start))->setTimezone($tz); $d->getTimestamp() < $end; $d = $d->modify('+1 day')) {
            $rows[] = ['start' => $d->getTimestamp(), 'mean' => 1000.0, 'min' => null, 'max' => null, 'change' => null];
        }
        return $entityId === 'sensor.pv' && $period === 'day' ? $rows : [];
    }
    public function search(string $query, int $limit = 20): array { return []; }
};
$hmStore = new ConfigStore(':memory:');
$hmStore->put('mapping', ['pv_power' => 'sensor.pv'] + $hmStore->defaults()['mapping']);
$hmMonth = (new Heatmap($hmStore, $hmHa))->payload(['metric' => 'pv', 'span' => 'month', 'date' => '2026-09'], $hmNow);
$hmCells = array_merge(...array_map(static fn (array $g): array => $g['cells'], $hmMonth['groups']));
check(count($hmMonth['groups']) === 1 && count($hmCells) === 30 && $hmCells[0]['t'] === '24,0' . NNBSP . 'kWh' && $hmMonth['summary'][0]['text'] === '720,0' . NNBSP . 'kWh' && $hmMonth['groups'][0]['offset'] === 1 && $hmCells[0]['v'] === 1.0, 'Statistik: kWh je Tag aus der mittleren Leistung, Summe, Kalender ab Dienstag');
$hmStore->pdo()->prepare('INSERT INTO sessions (started_at, ended_at, vehicle, energy_kwh, solar_kwh, grid_kwh, duration_s, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['2026-09-15T13:30:00+02:00', '2026-09-15T14:33:00+02:00', 'ID.3', 10.0, 6.0, 4.0, 3600, 'recorder']);
$hmDay = (new Heatmap($hmStore, $hmHa))->payload(['metric' => 'charge', 'span' => 'day', 'date' => '2026-09-15'], $hmNow);
check(count($hmDay['cells']) === 24 && $hmDay['cells'][13]['t'] === '5,00' . NNBSP . 'kWh' && $hmDay['cells'][14]['t'] === '5,00' . NNBSP . 'kWh' && $hmDay['cells'][12]['v'] === null, 'Ladevorgänge über ihre Ladezeit auf die Stunden verteilt');
$hmShare = (new Heatmap($hmStore, $hmHa))->payload(['metric' => 'charge_solar', 'span' => 'month', 'date' => '2026-09'], $hmNow);
$hmCount = (new Heatmap($hmStore, $hmHa))->payload(['metric' => 'charge_count', 'span' => 'all'], $hmNow);
check($hmShare['summary'][0]['text'] === '60' . NNBSP . '%' && $hmCount['summary'][0]['text'] === '1' && $hmCount['rows'][0]['label'] === '2026', 'Sonnenanteil aus den Summen; Gesamt beginnt beim ersten Jahr mit Daten');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $file = tempnam(sys_get_temp_dir(), 'ems-selftest');
    $sessionStore = new ConfigStore($file);
    $insert = $sessionStore->pdo()->prepare('INSERT INTO sessions (started_at, ended_at, vehicle, source) VALUES (?, ?, ?, ?)');
    $insert->execute(['2026-10-01T10:00:00+02:00', '2026-10-01T12:00:00+02:00', 'Laden', 'recorder']);
    $insert->execute(['2026-10-02T10:00:00+02:00', '2026-10-02T12:00:00+02:00', 'Golf', 'import']);
    $insert->execute(['2026-10-03T10:00:00+02:00', null, 'Laden', 'recorder']);
    $sessions = new Sessions($sessionStore);
    $sessions->repairVehicleNames('Auto');
    $vehicles = $sessionStore->pdo()->query('SELECT vehicle FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check($vehicles === ['Auto', 'Golf', 'Auto'], 'Statustexte als Fahrzeug werden einmalig zum Namen, Importe bleiben');
    $sessions->renameVehicle('Auto', 'ID.3');
    $vehicles = $sessionStore->pdo()->query('SELECT vehicle FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check($vehicles === ['ID.3', 'Golf', 'ID.3'], 'ein neuer Fahrzeugname gilt für die Vorgänge des Recorders');
    check($sessions->delete(1) && !$sessions->delete(3) && count($sessions->all()) === 2, 'Löschen nur für abgeschlossene Vorgänge');
    // Nachträglich zuordnen: zwei Zyklen innerhalb eines Ansteckens bekommen denselben Zeitpunkt.
    $plugStart = strtotime('2026-10-08 14:00:00 Europe/Berlin');
    $sessionStore->pdo()->exec('DELETE FROM sessions');
    $insert->execute([date('c', $plugStart + 120), date('c', $plugStart + 2400), 'ID.3', 'recorder']);
    $insert->execute([date('c', $plugStart + 4200), date('c', $plugStart + 6000), 'ID.3', 'recorder']);
    $insert->execute([date('c', $plugStart + 30000), date('c', $plugStart + 32000), 'ID.3', 'recorder']);
    $ha = new class ($plugStart) implements HaSource {
        public function __construct(private int $plug) {}
        public function setNumber(string $entityId, float $value): void {}
        public function service(string $domain, string $service, array $data): void {}
        public function notifyServices(): array { return []; }
        public function notify(string $service, array $payload): void {}
        public function configured(): bool { return true; }
        public function ping(): array { return ['ok' => true]; }
        public function states(): array { return []; }
        public function state(string $entityId): ?array { return null; }
        public function index(): array { return []; }
        public function history(string $entityId, int $start, ?int $end = null): array { return []; }
        public function stateHistory(string $entityId, int $start, ?int $end = null): array
        {
            return [['t' => $this->plug - 600, 's' => 'idle'], ['t' => $this->plug, 's' => 'wait_car'], ['t' => $this->plug + 7000, 's' => 'idle'], ['t' => $this->plug + 29000, 's' => 'wait_car']];
        }
        public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array { return []; }
        public function search(string $query, int $limit = 20): array { return []; }
    };
    $assigned = $sessions->backfillPlugs($ha, 'sensor.wallbox_car', $plugStart + 40000);
    $plugs = $sessionStore->pdo()->query('SELECT plug_at FROM sessions ORDER BY started_at')->fetchAll(PDO::FETCH_COLUMN);
    check($assigned === 3 && $plugs[0] === $plugs[1] && $plugs[1] !== $plugs[2] && count(Sessions::groups($sessions->all())) === 2, 'Nachträglich zugeordnet: zwei Zyklen ein Ladevorgang, das nächste Anstecken ein neuer');
    check($sessions->deleteGroup((int) $sessionStore->pdo()->query('SELECT MAX(id) FROM sessions WHERE plug_at = ' . $sessionStore->pdo()->quote((string) $plugs[0]))->fetchColumn()) && count($sessions->all()) === 1, 'Löschen nimmt den ganzen Ladevorgang');
    // Recorder: Pausen beim Laden bleiben ein Ladevorgang, erst Abstecken und neues Anstecken beginnen einen neuen.
    $sessionStore->pdo()->exec('DELETE FROM sessions');
    $sessionStore->put('plug_state', ['since' => null]);
    $sessionStore->put('session_runtime', ['idle_since' => null]);
    $t = strtotime('2026-10-08 14:00:00 Europe/Berlin');
    $step = static function (string $car, float $kw, int $at) use ($sessions): void {
        $sessions->tick(['wallbox_kw' => $kw, 'wallbox_car_raw' => $car, 'grid_import_kw' => 0.0, 'vehicle_name' => 'ID.3', 'loadpoint_name' => 'Garage'], ['off_delay_s' => 60], $at);
    };
    $step('wait_car', 0.0, $t);
    foreach ([[10, 'charging', 4.0], [20, 'charging', 4.0], [30, 'wait_car', 0.0], [100, 'wait_car', 0.0], [600, 'charging', 3.0], [610, 'charging', 3.0], [620, 'complete', 0.0], [700, 'complete', 0.0], [800, 'idle', 0.0], [5000, 'wait_car', 0.0], [5010, 'charging', 2.0], [5020, 'charging', 2.0]] as [$offset, $car, $kw]) {
        $step($car, $kw, $t + $offset);
    }
    $groupsAfter = Sessions::groups($sessions->all());
    check(count($sessions->all()) === 3 && count($groupsAfter) === 2 && count($groupsAfter[1]['cycles']) === 2 && $groupsAfter[0]['ended_at'] === null, 'Recorder: zwei Zyklen bis zum Abstecken, danach ein neuer Ladevorgang');
    foreach (['', '-wal', '-shm'] as $suffix) {
        @unlink($file . $suffix);
    }
}

// Börsenpreise: Energy-Charts mit Viertelstunden und Stunden, aWATTar in Millisekunden.
$ec = MarketPrices::parseEnergyCharts(json_encode(['unix_seconds' => [1000 * 900, 1001 * 900, 1002 * 900], 'price' => [80.5, null, -12.0], 'unit' => 'EUR / MWh']));
check(count($ec) === 2 && $ec[0] == ['start' => 900000, 'end' => 900900, 'eur_mwh' => 80.5] && $ec[1]['eur_mwh'] === -12.0 && $ec[1]['end'] - $ec[1]['start'] === 900, 'Energy-Charts: Viertelstunden, Lücken ohne Preis entfallen, negative Preise bleiben');
$ecHours = MarketPrices::parseEnergyCharts(json_encode(['unix_seconds' => [3600, 7200], 'price' => [50, 60]]));
check($ecHours[0]['end'] === 7200 && $ecHours[1]['end'] === 10800, 'Energy-Charts: vor Oktober 2025 Stunden');
$aw = MarketPrices::parseAwattar(json_encode(['data' => [['start_timestamp' => 1791813600000, 'end_timestamp' => 1791817200000, 'marketprice' => 180.68, 'unit' => 'Eur/MWh']]]));
check($aw === [['start' => 1791813600, 'end' => 1791817200, 'eur_mwh' => 180.68]], 'aWATTar: Millisekunden zu Sekunden');
$priceStore = new ConfigStore(':memory:');
$market = new MarketPrices($priceStore);
$q0 = (int) strtotime('2026-10-12 02:00:00 Europe/Berlin');
$market->save([['start' => $q0, 'end' => $q0 + 3600, 'eur_mwh' => 100.0]], 'awattar');
$market->save([['start' => $q0, 'end' => $q0 + 900, 'eur_mwh' => 90.0], ['start' => $q0 + 900, 'end' => $q0 + 1800, 'eur_mwh' => -20.0]], 'energy-charts');
$market->save([['start' => $q0, 'end' => $q0 + 3600, 'eur_mwh' => 300.0], ['start' => $q0 + 3600, 'end' => $q0 + 7200, 'eur_mwh' => 50.0]], 'awattar');
$quarters = (new MarketPrices($priceStore))->quarters($q0, $q0 + 7200);
check($quarters === [$q0 => 9.0, $q0 + 900 => -2.0, $q0 + 3600 => 5.0, $q0 + 4500 => 5.0, $q0 + 5400 => 5.0, $q0 + 6300 => 5.0], 'Genauere Viertelstunden ersetzen die Stunde des Ersatzes; der Ersatz füllt nur Stunden ohne sie');

// Tarif: Endpreis aus Börse und Aufschlägen, Modul 3 über Mitternacht, Verlauf nach Datum.
$dyn = Tariff::normalize(['mode' => 'dynamic', 'dyn_grid_ct' => 10, 'dyn_levies_ct' => 6, 'dyn_supplier_ct' => 2, 'dyn_vat_pct' => 19, 'dyn_windows' => [['from' => '22:00', 'to' => '06:00', 'ct' => 2.5], ['from' => '17:00', 'to' => '17:00', 'ct' => 1]]]);
$night = (int) strtotime('2026-10-12 03:00:00 Europe/Berlin');
$noon = (int) strtotime('2026-10-12 12:00:00 Europe/Berlin');
$parts = Tariff::parts(8.0, $dyn, $noon);
check(abs($parts['total'] - (8 + 10 + 6 + 2) * 1.19) < 1e-9 && abs($parts['vat'] - 26 * 0.19) < 1e-9 && count($dyn['dyn_windows']) === 1, 'Endpreis = (Börse + Netz + Steuern und Umlagen + Anbieter) × (1 + MwSt); ein leeres Fenster entfällt');
check(Tariff::gridCt($dyn, $night) === 2.5 && Tariff::gridCt($dyn, $noon) === 10.0 && abs(Tariff::dynamicCt(-5.0, $dyn, $night) - (-5 + 2.5 + 6 + 2) * 1.19) < 1e-9, 'Modul 3: das Fenster gilt auch über Mitternacht, auch bei negativer Börse');
check(Tariff::clock('24:00') === '00:00' && Tariff::clock('7:5') === null && Tariff::clock('07:05') === '07:05' && Tariff::normalize([])['mode'] === 'static' && Tariff::normalize([])['base_eur'] === null, 'Uhrzeiten und Standardwerte des Tarifs');
$hist = Tariff::withEntry([], Tariff::ORIGIN, ['import_ct' => 30]);
$hist = Tariff::withEntry($hist, '2026-07-01', ['import_ct' => 34.7]);
$hist = Tariff::withEntry($hist, '2026-07-01', ['import_ct' => 35.0]);
check(count($hist) === 2 && Tariff::at($hist, (int) strtotime('2026-06-30 23:00:00 Europe/Berlin'))['import_ct'] === 30.0 && Tariff::at($hist, (int) strtotime('2026-07-01 00:30:00 Europe/Berlin'))['import_ct'] === 35.0, 'Verlauf: gleiches Datum ersetzt, jede Zeit hat ihren Preis');
check(count(Tariff::withoutEntry($hist, '2026-07-01')) === 1 && count(Tariff::withoutEntry(Tariff::withoutEntry($hist, '2026-07-01'), Tariff::ORIGIN)) === 1, 'Löschen lässt immer einen Satz übrig');
$tariffStore = new ConfigStore(':memory:');
$tariff = new Tariff($tariffStore);
$tariff->saveHistory([['from' => Tariff::ORIGIN, 'tariffs' => ['mode' => 'static', 'import_ct' => 30, 'base_eur' => 10, 'dyn_base_eur' => 15] + $dyn]], $noon);
(new MarketPrices($tariffStore))->save([['start' => $noon, 'end' => $noon + 900, 'eur_mwh' => 80.0], ['start' => $noon + 900, 'end' => $noon + 1800, 'eur_mwh' => 120.0]], 'energy-charts');
$tariff = new Tariff($tariffStore);
check($tariff->importCt($noon) === 30.0 && abs($tariff->dynamicAt($noon) - 26 * 1.19) < 1e-9 && (float) $tariffStore->get('tariffs')['import_ct'] === 30.0, 'Aktiv statisch: Bezug zum Arbeitspreis, dynamisch rechnet trotzdem mit');
$cmp = $tariff->compare($noon, $noon + 1800, [$noon => [1.0, 900], $noon + 900 => [2.0, 900]]);
check(abs($cmp['static']['energy'] - 0.9) < 1e-9 && abs($cmp['dynamic']['spot'] - (1 * 8 + 2 * 12) / 100) < 1e-9 && abs($cmp['dynamic']['total'] - $cmp['dynamic']['base'] - (1 * 26 + 2 * 30) * 1.19 / 100) < 1e-9 && $cmp['quarter_share'] === 1.0, 'Vergleich: Viertelstunden einzeln, Bestandteile summieren sich zum Endpreis');
check(abs($cmp['static']['base'] - 10 / 31) < 1e-9 && abs($cmp['dynamic']['base'] - 15 / 31) < 1e-9, 'Grundpreis anteilig für den einen Tag im Oktober');
$shift = $tariff->shift([$noon + 900 => 1.0], 11.0);
check($shift['days'] === 1 && abs($shift['actual'] - 30 * 1.19 / 100) < 1e-9 && abs($shift['best'] - 26 * 1.19 / 100) < 1e-9 && $shift['saving'] > 0, 'Verschieben: dieselbe Menge in der günstigsten Viertelstunde des Tages');
$row = ['started_at' => date('c', $noon), 'duration_s' => 1800, 'energy_kwh' => 5, 'solar_kwh' => 0, 'grid_kwh' => 5];
$tariff->saveHistory([['from' => Tariff::ORIGIN, 'tariffs' => ['mode' => 'dynamic'] + $dyn]], $noon);
check(abs((new Tariff($tariffStore))->forRow($row)['import_ct'] - (26 + 30) / 2 * 1.19) < 1e-9, 'Ladevorgang im dynamischen Tarif: Mittel der Viertelstunden der Ladezeit');

// Preis-Entität des Anbieters: Listen in Euro oder Cent, Zeit als Text oder Zahl.
$entity = Tariff::parseEntity(['state' => '0.2841', 'attributes' => ['unit_of_measurement' => 'EUR/kWh', 'today' => [['startsAt' => '2026-10-12T00:00:00+02:00', 'total' => 0.25], ['startsAt' => '2026-10-12T00:15:00+02:00', 'total' => 0.26]], 'other' => 'x']], $noon);
check($entity['now'] === 28.41 && count($entity['rows']) === 2 && $entity['rows'][0]['ct'] === 25.0 && $entity['rows'][0]['end'] - $entity['rows'][0]['start'] === 900, 'Preis-Entität in Euro mit Viertelstunden');
$octo = Tariff::parseEntity(['state' => '31.2', 'attributes' => ['unit_of_measurement' => 'ct/kWh', 'rates' => [['start' => '2026-10-12T00:00:00Z', 'end' => '2026-10-12T01:00:00Z', 'value_inc_vat' => 30.5]]]], $noon);
check($octo['now'] === 31.2 && $octo['rows'][0]['ct'] === 30.5 && $octo['rows'][0]['end'] - $octo['rows'][0]['start'] === 3600 && Tariff::parseEntity(null, $noon)['rows'] === [], 'Preis-Entität in Cent mit Beginn und Ende');

// Viertelstunden wie ein Smart Meter: Leistung mal Zeit, geteilt an der Grenze; Lücken bleiben leer.
check(GridMeter::split(890, 920) === [[0, 10], [900, 20]], 'Viertelstunden: geteilt an der Grenze');
$meterStore = new ConfigStore(':memory:');
$meter = new GridMeter($meterStore);
$meter->record(['grid_import_kw' => 3.6, 'grid_export_kw' => 0.0], 1790);
$meter->record(['grid_import_kw' => 0.0, 'grid_export_kw' => 1.8], 1810);
$meter->record(['grid_import_kw' => 9.9, 'grid_export_kw' => 0.0], 2400);
$meterRows = $meter->quarters(0, 3600);
check(abs($meterRows[900]['import'] - 0.01) < 1e-9 && abs($meterRows[1800]['import'] - 0.01) < 1e-9 && count($meterRows) === 2, 'Netzbezug 3,6 kW über 20 s verteilt; nach einer Lücke von 10 min wird nichts geschätzt');
$merged = GridMeter::merge([0 => 1.0, 3600 => 2.0], [3600 => ['import' => 0.4, 'export' => 0, 'seconds' => 900], 4500 => ['import' => 0.4, 'export' => 0, 'seconds' => 900], 5400 => ['import' => 0.4, 'export' => 0, 'seconds' => 900], 6300 => ['import' => 0.4, 'export' => 0, 'seconds' => 900]]);
check($merged === [0 => [1.0, 3600], 3600 => [0.4, 900], 4500 => [0.4, 900], 5400 => [0.4, 900], 6300 => [0.4, 900]], 'Gemessene Stunden als Viertelstunden, die übrigen aus Home Assistant');
check(PriceView::cheapest([0 => 30.0, 900 => 10.0, 1800 => 12.0, 2700 => 40.0, 7200 => 1.0], 2) == ['start' => 900, 'end' => 2700, 'ct' => 11.0] && PriceView::cheapest([0 => 30.0, 900 => 10.0], 2, true)['ct'] === 20.0, 'Günstigste zusammenhängende Zeit, Lücken unterbrechen');

// Laden nach Preis: Plan aus den günstigsten Viertelstunden, Sonne zuerst, Warten auf morgen, knapp, Preisgrenze.
$p0 = (int) strtotime('2026-10-12 18:00:00 Europe/Berlin');
$deadline = $p0 + 52 * 900;
$night = [];
for ($i = 0; $i < 52; $i++) {
    $night[$p0 + $i * 900] = $i >= 24 && $i < 32 ? 15.0 : 30.0;
}
$cheapPlan = ChargePlan::plan(11.0, 11.0, $p0, $deadline, $night);
check($cheapPlan['status'] === 'ok' && $cheapPlan['slots'] === [$p0 + 28 * 900, $p0 + 29 * 900, $p0 + 30 * 900, $p0 + 31 * 900] && $cheapPlan['avg_ct'] === 15.0, 'Plan: 11 kWh bei 11 kW sind vier Viertelstunden, die günstigsten, bei gleichem Preis die späteren');
check(ChargePlan::plan(2.75, 11.0, $p0, $deadline, array_fill_keys(array_keys($night), 30.0))['slots'] === [$p0 + 51 * 900], 'Statischer Tarif: die späteste Viertelstunde, damit die Sonne vorher helfen kann');
$sunny = ChargePlan::plan(11.0, 11.0, $p0, $deadline, $night, [$p0 => 11.0, $p0 + 900 => 11.0]);
check(abs($sunny['sun_kwh'] - 5.5) < 1e-9 && count($sunny['slots']) === 2 && abs($sunny['grid_kwh'] - 5.5) < 1e-9, 'Erwartete Sonne zieht vom Netz ab');
$unknownNight = $night;
foreach (array_keys($unknownNight) as $i => $q) {
    if ($i >= 24) {
        $unknownNight[$q] = null;
    }
}
check(ChargePlan::plan(11.0, 11.0, $p0, $deadline, $unknownNight)['status'] === 'waiting' && ChargePlan::plan(11.0, 11.0, $p0, $deadline, $unknownNight)['slots'] === [], 'Reicht die Zeit ohne Preis, wartet der Plan auf die Preise für morgen');
$partial = ChargePlan::plan(88.0, 11.0, $p0, $deadline, $unknownNight);
check($partial['status'] === 'partial' && count($partial['slots']) === 4, 'Reicht sie nicht, kommt jetzt nur der Rest aus den bekannten Preisen');
$tight = ChargePlan::plan(200.0, 11.0, $p0, $p0 + 3600, $night);
check($tight['status'] === 'tight' && count($tight['slots']) === 4 && ChargePlan::plan(0.0, 11.0, $p0, $p0 + 3600, $night)['status'] === 'done', 'Zu wenig Zeit: jede Viertelstunde bis zur Uhrzeit; nichts mehr zu laden: fertig');
$slotPlan = ['slots' => [$p0 + 24 * 900]];
check(ChargePlan::effective('smart', $slotPlan, null, null, false, true, $p0 + 24 * 900 + 100) === ['mode' => 'netz', 'reason' => 'plan']
    && ChargePlan::effective('smart', $slotPlan, null, null, false, true, $p0) === ['mode' => 'smart', 'reason' => null]
    && ChargePlan::effective('aus', $slotPlan, null, null, false, true, $p0 + 24 * 900)['reason'] === null
    && ChargePlan::effective('smart', $slotPlan, null, null, false, false, $p0 + 24 * 900)['reason'] === null, 'Netzladen nur in einer geplanten Viertelstunde, nur in Solar und Min+Solar und mit Auto');
check(ChargePlan::effective('smart_dauerhaft', null, 18.0, 20.0, true, true, $p0) === ['mode' => 'netz', 'reason' => 'limit']
    && ChargePlan::effective('smart', null, 21.0, 20.0, true, true, $p0)['reason'] === null
    && ChargePlan::effective('smart', null, 18.0, 20.0, false, true, $p0)['reason'] === null, 'Preisgrenze nur mit dynamischem Tarif und nur darunter');
check(ChargePlan::windows([900, 0, 2700]) === [[0, 1800], [2700, 3600]] && ChargePlan::limit(['price_limit_ct' => '19.5']) === 19.5 && ChargePlan::limit([]) === null, 'Zeitfenster aus Viertelstunden, Preisgrenze aus den Ladeparametern');
$ready = Target::build(['type' => 'soc', 'soc' => '80', 'ready_by' => '07:00', 'then' => 'smart'], $p0, 40.0);
check(($ready['target']['ready_by'] ?? null) === (int) strtotime('2026-10-13 07:00:00 Europe/Berlin') && Target::build(['type' => 'soc', 'soc' => '80', 'ready_by' => '7 Uhr'], $p0, 40.0)['error'] !== null
    && !isset(Target::build(['type' => 'time', 'until' => '20:00', 'ready_by' => '07:00'], $p0, null)['target']['ready_by']), 'Fertig bis: die nächste 7:00, Unsinn wird abgelehnt, ein Ziel nach Uhrzeit braucht es nicht');
check(abs(Target::neededKwh(['type' => 'soc', 'value' => 80], ['car_soc' => 40, 'capacity_kwh' => 75]) - 30 / 0.92) < 1e-9 && Target::neededKwh(['type' => 'energy', 'value' => 20], ['session_kwh' => 5]) === 15.0 && Target::neededKwh(['type' => 'soc', 'value' => 80], []) === null, 'Was bis zum Ziel noch fehlt, mit Ladeverlust');
check(str_contains(ChargePlan::text($cheapPlan + ['deadline' => $deadline]), 'aus dem Netz') && str_contains(ChargePlan::text(['status' => 'waiting', 'slots' => [], 'deadline' => $deadline, 'sun_kwh' => 0, 'avg_ct' => null]), '13 Uhr') && ChargePlan::text(null) === '', 'Satz zum Plan für die Karte');

echo "alle prüfungen bestanden\n";
