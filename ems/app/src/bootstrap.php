<?php
declare(strict_types=1);

const EMS_APP = __DIR__ . '/..';

date_default_timezone_set('Europe/Berlin');

if (getenv('EMS_DEBUG') === '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

require EMS_APP . '/src/helpers.php';
require EMS_APP . '/src/ConfigStore.php';
require EMS_APP . '/src/HaClient.php';
require EMS_APP . '/src/Energy.php';
require EMS_APP . '/src/Forecast.php';
require EMS_APP . '/src/WeatherFeed.php';
require EMS_APP . '/src/Sessions.php';
require EMS_APP . '/src/Snapshot.php';
require EMS_APP . '/src/Series.php';
require EMS_APP . '/src/Actions.php';
require EMS_APP . '/views/ui.php';

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function store(): ConfigStore
{
    static $store = null;
    if (!$store) {
        $store = new ConfigStore(data_dir() . '/ems.sqlite');
    }
    return $store;
}

function ha(): HaClient
{
    static $client = null;
    if (!$client) {
        $connection = connection();
        $client = new HaClient($connection['url'], $connection['token']);
    }
    return $client;
}

function cfg(): array
{
    return store()->all();
}
