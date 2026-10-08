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
    $rows = Forecast::archiveRows($memory, $plant, ['2026-10-08' => 4.2]);
    check(count($rows) === 1 && abs((float) $rows[0]['actual'] - 4.2) < 0.01 && $rows[0]['mean'] > 0, 'Archiv verbindet Ertrag und Prognose');
    $outlook = Forecast::storageOutlook([
        ['t' => strtotime('2026-10-08 12:00:00 Europe/Berlin'), 'kw' => 3],
        ['t' => strtotime('2026-10-08 13:00:00 Europe/Berlin'), 'kw' => 3],
        ['t' => strtotime('2026-10-08 14:00:00 Europe/Berlin'), 'kw' => 3],
    ], strtotime('2026-10-08 12:00:00 Europe/Berlin'), 50, 5, 0, 80);
    check($outlook['reachable'] && $outlook['full_at'] !== null && $outlook['surplus_kwh'] > 0, 'Speicherfüllung aus dem Sonnenüberschuss');
}
$batteryIcon = icon('battery');
check(str_contains($batteryIcon, 'width="16"') && str_contains($batteryIcon, 'width="9"'), 'Batterie-Icon behält die Flächen');

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

echo "alle prüfungen bestanden\n";
