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
$noon = Forecast::powerKw(1000, 13, ['kwp' => 10.03, 'factor' => 1, 'tilt' => 13, 'azimuth' => 270, 'inverter_kw' => 10]);
check($noon > 4 && $noon <= 10, '1000 W/m² bleibt unter dem Limit, ist ' . $noon);

$latched = Energy::latch(['amps' => 10, 'phases' => 1], ['amps' => 0, 'phases' => 1], 1000, $charge);
check($latched['latched_amps'] === 0 && $latched['wait_s'] === 60, 'Einschalten wartet 60 s');
$latched = Energy::latch(['amps' => 10, 'phases' => 1], ['amps' => 0, 'phases' => 1, 'pending_amps' => 10, 'pending_phases' => 1, 'pending_since' => 900], 1000, $charge);
check($latched['latched_amps'] === 10 && $latched['wait_s'] === 0, 'Nach der Wartezeit wird verriegelt');

echo "alle prüfungen bestanden\n";
