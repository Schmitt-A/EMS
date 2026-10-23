<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';

$snapshot = new Snapshot(store(), ha());
$sessions = new Sessions(store());
$reserve = new Reserve(store(), ha());
$batteryGrid = new BatteryPlan(store(), ha());
$controller = new Controller(store(), ha());
$notify = new Notify(store(), ha());
$sessions->repairVehicleNames((string) (cfg()['vehicle']['name'] ?? ''));
$series = new Series(store(), ha());
$market = new MarketPrices(store());
$meter = new GridMeter(store());
$tariff = new Tariff(store());
$providerAt = 0;

while (true) {
    $started = time();
    try {
        (new WeatherFeed(store()))->refresh();
        // Börsenpreise: heute und ab 13 Uhr morgen, sonst nach und nach zwei Jahre Vergangenheit.
        try {
            $market->refresh(time());
        } catch (Throwable $e) {
            file_put_contents(data_dir() . '/recorder.log', date('c') . ' Börsenpreise: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
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
            // Netzbezug und Einspeisung je Viertelstunde, wie ein Smart Meter abrechnet.
            $meter->record($snap['values'], time());
            // Ladeplan für ein Ziel mit „fertig bis“ in Ladefenstern: alle 15 min, nach neuen Preisen, Fenstern oder Zielen.
            ChargePlan::refresh(store(), $snap, time());
            // Regelung der Wallbox wie evcc: Timer alle 10 s, Schritt alle 30 s, schreibt nur bei aktivem EMS.
            $controller->tick($snap, time());
            // Backup-Puffer: beim Netzladen anheben, danach zurücksetzen (auch nach einem Neustart mittendrin).
            $reserve->sync($snap['values'], $snap['cfg'], time(), $sessions->open() !== null);
            // Ladepläne des Speichers: die günstigsten Viertelstunden vor ihrer Uhrzeit, alle 15 min neu.
            BatteryPlans::refresh(store(), $snap, time());
            // Speicher im Energie-Boost oder nach Ladeplan aus dem Netz laden; danach zurück auf den Betriebsmodus.
            $batteryGrid->sync($snap, time());
            // Mitteilungen an die Home-Assistant-App; ein Fehler dort hält den Rest der Runde nicht auf.
            try {
                $notify->tick($snap, time());
            } catch (Throwable $e) {
                file_put_contents(data_dir() . '/recorder.log', date('c') . ' Mitteilungen: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
            // Preise der Anbieter-Entität (Tibber, Octopus …) aufzeichnen und den Arbeitspreis aus seiner Entität
            // übernehmen, alle 5 min.
            if (time() - $providerAt >= 300) {
                $providerAt = time();
                try {
                    $tariff->recordProvider(ha(), (string) ($snap['cfg']['mapping']['price_import'] ?? ''), time());
                    $tariff->syncStaticPrice(ha(), (string) ($snap['cfg']['mapping']['price_static'] ?? ''), time());
                } catch (Throwable $e) {
                    file_put_contents(data_dir() . '/recorder.log', date('c') . ' Preis-Entität: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
            }
            // Ertrag der Anlage von PVGIS für „Lohnt sich dynamisch?“, nur wenn er fehlt, veraltet ist oder sich die
            // Anlage geändert hat; nach einem Fehler frühestens am nächsten Tag wieder.
            try {
                (new YearCheck(store(), ha()))->pv($snap['cfg']['plant'], time(), true);
            } catch (Throwable $e) {
                file_put_contents(data_dir() . '/recorder.log', date('c') . ' PVGIS: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
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
