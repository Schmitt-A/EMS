<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';

$snapshot = new Snapshot(store(), ha());
$sessions = new Sessions(store());
$series = new Series(store(), ha());

while (true) {
    $started = time();
    try {
        (new WeatherFeed(store()))->refresh();
        $snap = $snapshot->build();
        if ($snap['connected']) {
            $sessions->tick(
                array_merge($snap['values'], ['car_label' => $snap['car_label']]),
                $snap['cfg']['charge'],
                time()
            );
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
