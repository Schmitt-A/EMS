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
check(Forecast::displayedModel('pin', 28.0, $scaled) === 28.0, 'festgehaltener Modelltag bleibt 28 kWh');
check(Forecast::displayedModel('drop', 20.0, $plant) === null, 'verworfener Modelltag bleibt leer');
check(abs((float) Forecast::displayedModel(null, 10.0, $scaled) - 5) < 0.01, 'normaler Modelltag nimmt den Eichfaktor');
$three = Forecast::windowDays(['2026-10-05', '2026-10-06', '2026-10-07', '2026-09-30'], '2026-10-08', '3');
sort($three);
check($three === ['2026-10-05', '2026-10-06', '2026-10-07'], 'Gütefenster drei abgeschlossene Tage');
check(Forecast::windowDays(['2026-10-05', '2026-09-30'], '2026-10-08', 'month') === ['2026-10-05'], 'Gütefenster Monat');
check(Forecast::windowDays(['2026-10-05', '2026-09-30'], '2026-10-08', 'quarter') === ['2026-10-05'], 'Gütefenster Quartal');
check(Forecast::captionText(10.47, 0.04) === '10,5 ± 0,0 kWh', 'Beschriftung rundet wie die Tabelle');
check(Forecast::captionText(10.7, null) === '10,7 kWh', 'ohne Streuung nur der Prognosewert');
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
check($boardToday['today'] === true && $boardToday['gute'] === null && $boardToday['regress'] === null && abs((float) $boardToday['model'] - 5) < 0.01 && abs((float) $boardToday['raw'] - 10) < 0.01 && abs((float) $boardToday['fitted'] - 5) < 0.01, 'heutiger Tag hat Rohmodell und Faktor, die Güte und die Regression bleiben leer');
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
