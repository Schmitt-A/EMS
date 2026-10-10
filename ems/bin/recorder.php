<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';

$snapshot = new Snapshot(store(), ha());
$sessions = new Sessions(store());
$reserve = new Reserve(store(), ha());
$controller = new Controller(store(), ha());
$notify = new Notify(store(), ha());
$sessions->repairVehicleNames((string) (cfg()['vehicle']['name'] ?? ''));
$series = new Series(store(), ha());

while (true) {
    $started = time();
    try {
        (new WeatherFeed(store()))->refresh();
        $snap = $snapshot->build();
        if ($snap['connected']) {
            $sessions->tick(
                array_merge($snap['values'], [
                    'car_label' => $snap['car_label'],
                    'vehicle_name' => (string) ($snap['cfg']['vehicle']['name'] ?? ''),
                    'loadpoint_name' => (string) ($snap['cfg']['chargepoint']['name'] ?? 'Wallbox'),
                ]),
                $snap['cfg']['charge'],
                time()
            );
            // Regelung der Wallbox wie evcc: Timer alle 10 s, Schritt alle 30 s, schreibt nur bei aktivem EMS.
            $controller->tick($snap, time());
            // Backup-Puffer: beim Netzladen anheben, danach zurücksetzen (auch nach einem Neustart mittendrin).
            $reserve->sync($snap['values'], $snap['cfg'], time(), $sessions->open() !== null);
            // Mitteilungen an die Home-Assistant-App; ein Fehler dort hält den Rest der Runde nicht auf.
            try {
                $notify->tick($snap, time());
            } catch (Throwable $e) {
                file_put_contents(data_dir() . '/recorder.log', date('c') . ' Mitteilungen: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
            // Ältere Zyklen ohne Ansteckzeit aus dem Statusverlauf zuordnen, höchstens einmal pro Stunde.
            $sessions->backfillPlugs(ha(), (string) ($snap['cfg']['mapping']['wallbox_car'] ?? ''), time());
            if ((int) date('G') >= 1) {
                $series->calibrate($snap['cfg']['plant'], false);
            }
        }
    } catch (Throwable $e) {
        file_put_contents(data_dir() . '/recorder.log', date('c') . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
    $wait = 10 - (time() - $started);
    if ($wait > 0) {
        sleep($wait);
    }
}
