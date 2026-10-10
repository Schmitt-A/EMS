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
check($fast['amps'] === 16 && $fast['phases'] === 3, 'Schnellladen 16 A dreiphasig');

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
check($minimum['amps'] === 6 && abs($minimum['flows']['grid_kw'] - 1.38) < 0.001 && $minimum['flows']['battery_kw'] < 0.001, 'Min+Solar: die Mindestleistung kommt unter der Stützung aus dem Netz');
$full = Energy::suggest(['pv_kw' => 5.0, 'house_base_kw' => 0.5, 'battery_charge_kw' => 0.0, 'battery_discharge_kw' => 0.0, 'wallbox_kw' => 0.0, 'grid_import_kw' => 0.0, 'grid_export_kw' => 4.5, 'battery_soc' => 60, 'surplus_kw' => 4.5], ['mode' => 'schnell'] + $solarCfg, 1, $zones);
check($full['amps'] === 16 && $full['phases'] === 3 && abs($full['flows']['sun_kw'] - 4.5) < 0.001 && abs($full['flows']['mixed_kw'] - 6.54) < 0.001, 'Schnell: Sonne zuerst, der Rest aus Speicher und Netz');

$fit = Forecast::regression([10, 20, 30], [12, 22, 32]);
check(abs($fit['a'] - 2) < 0.01 && abs($fit['b'] - 1) < 0.01, 'Regression');
$west = Forecast::geometry(13, 270);
check(abs($west - 1) < 0.02, 'Westdach nahe Faktor 1');
$plantNoon = ['kwp' => 10.03, 'factor' => 1, 'tilt' => 13, 'azimuth' => 270, 'inverter_kw' => 10];
$noon = Forecast::powerKw(1000, 13, $plantNoon);
$morning = Forecast::powerKw(1000, 8, $plantNoon);
check($noon > 4 && $noon <= 10, '1000 W/m² bleibt unter dem Limit, ist ' . $noon);
check(abs($morning - $noon) < 0.001, 'die Stunde verändert die Leistung nicht');

$latched = Energy::latch(['amps' => 10, 'phases' => 1], ['amps' => 0, 'phases' => 1], 1000, $charge);
check($latched['latched_amps'] === 0 && $latched['wait_s'] === 60, 'Einschalten wartet 60 s');
$latched = Energy::latch(['amps' => 10, 'phases' => 1], ['amps' => 0, 'phases' => 1, 'pending_amps' => 10, 'pending_phases' => 1, 'pending_since' => 900], 1000, $charge);
check($latched['latched_amps'] === 10 && $latched['wait_s'] === 0, 'Nach der Wartezeit wird verriegelt');

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

echo "alle prüfungen bestanden\n";
