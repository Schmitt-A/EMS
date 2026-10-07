<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$path = request_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/api/health') {
    json_out(['ok' => true]);
}

if ($path === '/api/live') {
    $snapshot = new Snapshot(store(), ha());
    $snap = $snapshot->build();
    json_out($snapshot->livePayload($snap));
}

if ($path === '/api/entities') {
    try {
        json_out(['results' => ha()->search((string) ($_GET['q'] ?? ''), 15)]);
    } catch (Throwable $e) {
        json_out(['results' => [], 'error' => $e->getMessage()]);
    }
}

if ($path === '/api/series') {
    $chart = (string) ($_GET['chart'] ?? '');
    $config = cfg();
    $series = new Series(store(), ha());
    try {
        if ($chart === 'battery') {
            json_out($series->battery(max(1, (int) ($_GET['range'] ?? 24)), $config['mapping'], $config['battery_strategy']));
        }
        if ($chart === 'power') {
            json_out($series->power($config['mapping'], $config['plant']));
        }
        if ($chart === 'daily' || $chart === 'compare') {
            $days = $series->days($config['plant']);
            json_out(['series' => $days[$chart]]);
        }
        if ($chart === 'weather') {
            json_out($series->weather($config['mapping']));
        }
        if ($chart === 'sessions') {
            json_out(session_chart((string) ($_GET['month'] ?? date('Y-m'))));
        }
    } catch (Throwable $e) {
        json_out(['series' => [], 'error' => $e->getMessage()], 500);
    }
    json_out(['series' => []]);
}

$wizard = (bool) store()->get('wizard_done', false);
$inWizard = str_starts_with($path, '/einrichten');
if (!$wizard && !$inWizard) {
    redirect('/einrichten/verbindung');
}

if ($inWizard && $method === 'POST') {
    $step = trim(substr($path, strlen('/einrichten')), '/');
    $step = $step === '' ? 'verbindung' : $step;
    if (!isset(Actions::STEPS[$step])) {
        $step = 'verbindung';
    }
    Actions::saveWizard($step);
}

if ($path === '/einstellungen' && $method === 'POST') {
    Actions::saveSettings();
}

if ($path === '/statistik' && $method === 'POST') {
    csrf_check();
    $result = (new Sessions(store()))->import(ha());
    flash($result['message']);
    redirect('/statistik');
}

if ($path === '/batterie' && $method === 'POST') {
    $_POST['section'] = 'battery';
    $_POST['back'] = '/batterie';
    Actions::saveSettings();
}

$snapshot = new Snapshot(store(), ha());
$snap = $snapshot->build();
$live = $snapshot->livePayload($snap);

if ($path === '/' ) {
    $yield = safe_yield();
    page('overview', compact('snap', 'live', 'yield') + ['title' => 'Übersicht']);
}
if ($path === '/batterie') {
    page('battery', compact('snap', 'live') + ['title' => 'Batterie']);
}
if ($path === '/laden') {
    $session = $snap['session'];
    page('charge', compact('snap', 'live', 'session') + ['title' => 'Laden']);
}
if ($path === '/statistik') {
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $tariffs = cfg()['tariffs'];
    $rows = (new Sessions(store()))->month($month);
    $totals = ['energy' => 0.0, 'solar' => 0.0, 'grid' => 0.0, 'cost' => 0.0, 'reference' => 0.0, 'saved' => 0.0, 'duration' => 0, 'solar_pct' => 0.0, 'ct' => 0.0];
    foreach ($rows as &$row) {
        $row['costed'] = Sessions::cost($row, $tariffs);
        $totals['energy'] += (float) $row['energy_kwh'];
        $totals['solar'] += (float) $row['solar_kwh'];
        $totals['grid'] += (float) $row['grid_kwh'];
        $totals['cost'] += $row['costed']['cost'];
        $totals['reference'] += $row['costed']['reference'];
        $totals['saved'] += $row['costed']['saved'];
        $totals['duration'] += (int) $row['duration_s'];
    }
    unset($row);
    $totals['solar_pct'] = $totals['energy'] > 0 ? $totals['solar'] / $totals['energy'] * 100 : 0;
    $totals['ct'] = $totals['energy'] > 0 ? $totals['cost'] / $totals['energy'] * 100 : 0;
    $months = [];
    $cursor = new DateTimeImmutable('first day of this month');
    for ($i = 0; $i < 12; $i++) {
        $months[$cursor->format('Y-m')] = $cursor->format('m.Y');
        $cursor = $cursor->modify('-1 month');
    }
    page('stats', compact('live', 'month', 'months', 'rows', 'totals', 'tariffs') + ['title' => 'Statistik']);
}
if ($path === '/prognose') {
    $yield = safe_yield();
    $yesterday = yesterday_yield();
    $goodness = null;
    $day = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $stmt = store()->pdo()->prepare('SELECT actual_kwh, model_kwh FROM daily WHERE day = ?');
    $stmt->execute([$day]);
    $row = $stmt->fetch();
    if ($row && (float) $row['actual_kwh'] > 0 && $row['model_kwh'] !== null) {
        $goodness = (float) $row['model_kwh'] / (float) $row['actual_kwh'];
    }
    page('forecast', compact('snap', 'live', 'yield', 'yesterday', 'goodness') + ['title' => 'Prognose']);
}
if ($path === '/einstellungen') {
    $ping = ha()->ping();
    page('settings', ['cfg' => cfg(), 'ping' => $ping, 'live' => $live, 'title' => 'Einstellungen']);
}
if ($inWizard) {
    $step = trim(substr($path, strlen('/einrichten')), '/');
    if ($step === '' || $step === 'einrichten') {
        $step = 'verbindung';
    }
    if (!isset(Actions::STEPS[$step])) {
        redirect('/einrichten/verbindung');
    }
    $ping = ha()->ping();
    $connection = connection();
    $connection['token'] = $connection['token'] !== '' ? 'set' : '';
    $suggest = [];
    try {
        if (ha()->configured()) {
            $index = ha()->index();
            foreach (Actions::SUGGEST as $key => $id) {
                if (isset($index[$id])) {
                    $suggest[$key] = $id;
                }
            }
        }
    } catch (Throwable) {
        $suggest = [];
    }
    $missing = Actions::missing(cfg()['mapping']);
    $review = review_lines(cfg()['mapping']);
    page('wizard', [
        'step' => $step,
        'cfg' => cfg(),
        'ping' => $ping,
        'connection' => $connection,
        'suggest' => $suggest,
        'missing' => $missing,
        'review' => $review,
        'showNav' => $wizard,
        'live' => $wizard ? $live : null,
        'title' => 'Einrichten',
    ], $wizard);
}

http_response_code(404);
echo 'Nicht gefunden';

function page(string $view, array $data, bool $nav = true): never
{
    $data['showNav'] = $data['showNav'] ?? $nav;
    render($view, $data);
}

function safe_yield(): ?float
{
    try {
        return (new Series(store(), ha()))->yieldToday(cfg()['mapping']);
    } catch (Throwable) {
        return null;
    }
}

function yesterday_yield(): ?float
{
    $day = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $stmt = store()->pdo()->prepare('SELECT actual_kwh FROM daily WHERE day = ?');
    $stmt->execute([$day]);
    $row = $stmt->fetch();
    return $row && $row['actual_kwh'] !== null ? (float) $row['actual_kwh'] : null;
}

function review_lines(array $mapping): array
{
    $labels = [
        'pv_power' => 'PV-Leistung',
        'battery_soc' => 'Ladestand',
        'battery_charge' => 'Batterie laden',
        'battery_discharge' => 'Batterie entladen',
        'grid_import' => 'Netzbezug',
        'grid_export' => 'Einspeisung',
        'house_power' => 'Haus',
        'wallbox_power' => 'Wallbox',
        'weather_radiation' => 'Strahlung',
    ];
    $index = [];
    try {
        $index = ha()->configured() ? ha()->index() : [];
    } catch (Throwable) {
        $index = [];
    }
    $lines = [];
    foreach ($labels as $key => $label) {
        $id = (string) ($mapping[$key] ?? '');
        if ($id === '') {
            $lines[] = ['label' => $label, 'text' => 'nicht gesetzt', 'ok' => false];
            continue;
        }
        $row = $index[$id] ?? null;
        if (!$row) {
            $lines[] = ['label' => $label, 'text' => 'nicht gefunden', 'ok' => false];
            continue;
        }
        $state = (string) $row['state'];
        $unit = (string) ($row['attributes']['unit_of_measurement'] ?? '');
        $ok = !in_array($state, ['unavailable', 'unknown', ''], true);
        $lines[] = ['label' => $label, 'text' => trim($state . ' ' . $unit), 'ok' => $ok];
    }
    return $lines;
}

function session_chart(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $rows = (new Sessions(store()))->month($month);
    $days = [];
    foreach ($rows as $row) {
        $day = (int) (new DateTimeImmutable($row['started_at']))->format('j');
        $days[$day]['solar'] = ($days[$day]['solar'] ?? 0) + (float) $row['solar_kwh'];
        $days[$day]['grid'] = ($days[$day]['grid'] ?? 0) + (float) $row['grid_kwh'];
    }
    $count = (int) (new DateTimeImmutable($month . '-01'))->format('t');
    $solar = $grid = [];
    for ($day = 1; $day <= $count; $day++) {
        $solar[] = ['x' => $day, 'y' => round($days[$day]['solar'] ?? 0, 2)];
        $grid[] = ['x' => $day, 'y' => round($days[$day]['grid'] ?? 0, 2)];
    }
    return [
        'axis' => 'day',
        'series' => [
            ['key' => 'solar', 'label' => 'Sonne', 'color' => 'export', 'type' => 'bar', 'data' => $solar],
            ['key' => 'grid', 'label' => 'Netz', 'color' => 'import', 'type' => 'bar', 'data' => $grid],
        ],
    ];
}
