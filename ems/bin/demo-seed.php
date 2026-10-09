<?php
declare(strict_types=1);

// Spielt app/demo/beispieldaten.json in eine frische Datenbank unter $EMS_DATA ein.
// Aufruf: EMS_DEMO=1 EMS_DATA=/pfad/zum/demo-ordner php bin/demo-seed.php
if (getenv('EMS_DEMO') !== '1') {
    fwrite(STDERR, "Nur mit EMS_DEMO=1.\n");
    exit(1);
}
$dir = rtrim((string) (getenv('EMS_DATA') ?: ''), '/');
if ($dir === '' || $dir === '/data') {
    fwrite(STDERR, "EMS_DATA muss auf ein eigenes Demo-Verzeichnis zeigen, nicht auf /data.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/src/bootstrap.php';

foreach (['ems.sqlite', 'ems.sqlite-wal', 'ems.sqlite-shm', 'states-cache.json'] as $name) {
    @unlink(data_dir() . '/' . $name);
}

$model = DemoModel::load();
$data = $model->data();
$store = store();
$pdo = $store->pdo();

$store->put('wizard_done', true);
$store->put('model_repair', '2026-10');
$store->put('mapping', array_merge($store->defaults()['mapping'], DemoHaClient::mapping()));
$plant = array_merge($store->defaults()['plant'], $model->plant(), ['n_days' => 7]);
$store->put('plant', $plant);
$store->merge('tariffs', [
    'import_ct' => (float) $data['tarif']['bezug_ct'],
    'export_ct' => (float) $data['tarif']['einspeisung_ct'],
    'co2_g_kwh' => (float) $data['tarif']['co2_g_kwh'],
]);
$zones = $data['speicher_grenzen'];
$store->merge('battery_strategy', zone_thresholds((float) $zones['priority_soc'], (float) $zones['car_buffer_soc'], (float) $zones['car_auto_soc']));
$store->merge('chargepoint', ['name' => (string) $data['ladepunkt']['name']]);
$store->merge('vehicle', ['name' => (string) $data['fahrzeug']['name'], 'limit_soc' => (float) $data['fahrzeug']['limit']]);

// Ältere DWD-Läufe, damit Mittelwert und Streuung der Läufe etwas zu zeigen haben.
$now = time();
for ($k = 8; $k >= 1; $k--) {
    $issue = intdiv($now - $k * 6 * 3600, 10800) * 10800;
    Forecast::rememberIssue($pdo, $model->weatherFile($issue)['hours'], $plant, $issue);
}
(new WeatherFeed($store))->refresh(true);

// Ist und Rohmodell der letzten vier Monate, damit Güte und Regression ein Fenster haben.
$tz = new DateTimeZone('Europe/Berlin');
$today = new DateTimeImmutable('today', $tz);
$daily = $pdo->prepare('INSERT INTO daily (day, actual_kwh, model_kwh) VALUES (?, ?, ?) ON CONFLICT(day) DO UPDATE SET actual_kwh = excluded.actual_kwh, model_kwh = excluded.model_kwh');
for ($offset = 120; $offset >= 1; $offset--) {
    $day = $today->modify('-' . $offset . ' days')->format('Y-m-d');
    $daily->execute([$day, round($model->dayActualKwh($day), 3), round($model->dayModelKwh($day), 3)]);
}

// Ladevorgänge über drei Jahre.
$insert = $pdo->prepare(
    'INSERT OR IGNORE INTO sessions (started_at, ended_at, vehicle, loadpoint, energy_kwh, solar_kwh, grid_kwh, duration_s, source, odometer, meter_start, meter_end)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "demo", ?, ?, ?)'
);
$vehicle = (string) $data['fahrzeug']['name'];
$loadpoint = (string) $data['ladepunkt']['name'];
$count = 0;
foreach ($data['ladevorgaenge'] as $row) {
    [$hour, $minute] = array_map('intval', explode(':', (string) $row['start']));
    $start = $today->modify('-' . (int) $row['tage_zurueck'] . ' days')->setTime($hour, $minute);
    $end = $start->modify('+' . (int) $row['dauer_min'] . ' minutes');
    $insert->execute([
        $start->format('c'),
        $end->format('c'),
        $vehicle,
        $loadpoint,
        (float) $row['kwh'],
        (float) $row['sonne_kwh'],
        (float) $row['netz_kwh'],
        (int) $row['dauer_min'] * 60,
        $row['km'],
        $row['zaehler_start'],
        $row['zaehler_ende'],
    ]);
    $count += $insert->rowCount();
}

// Lädt das Auto gerade, steht der laufende Vorgang schon offen in der Liste.
$live = $model->at($model->now());
if ($live['status'] === 'charging') {
    $from = $today->setTime(10, 0)->getTimestamp();
    $energy = 0.0;
    foreach ($model->series('wall', $from, $model->now()) as $kw) {
        $energy += $kw * 300 / 3600;
    }
    $pdo->prepare('INSERT INTO sessions (started_at, vehicle, loadpoint, energy_kwh, solar_kwh, grid_kwh, duration_s, source) VALUES (?, ?, ?, ?, ?, 0, ?, "demo")')
        ->execute([date('c', $from), $vehicle, $loadpoint, round($energy, 3), round($energy, 3), $model->now() - $from]);
    $store->put('session_runtime', ['idle_since' => null, 'last_sample' => $model->now()]);
}

echo "Demo-Daten in {$dir}: {$count} Ladevorgänge, 120 Tage Ist/Modell, Wetter und DWD-Läufe.\n";
