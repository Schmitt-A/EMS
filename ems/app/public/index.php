<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

ingest_json_body();

$path = request_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/api/health') {
    json_out(['ok' => true]);
}

if ($path === '/api/live') {
    $snapshot = new Snapshot(store(), ha());
    $snap = $snapshot->build();
    if (demo_mode() && $snap['connected']) {
        // Im Demo-Modus läuft kein Recorder; der laufende Ladevorgang wächst mit jeder Abfrage.
        (new Sessions(store()))->tick(array_merge($snap['values'], [
            'vehicle_name' => (string) ($snap['cfg']['vehicle']['name'] ?? ''),
            'loadpoint_name' => (string) ($snap['cfg']['chargepoint']['name'] ?? 'Wallbox'),
        ]), $snap['cfg']['charge'], time());
    }
    json_out($snapshot->livePayload($snap));
}

// JSON-Endpunkte der Oberfläche. Das CSRF-Token kommt im Body, ingest_json_body() legt es in $_POST.
$writes = ['/api/modus', '/api/limit', '/api/darstellung', '/api/grenzen'];
if (in_array($path, $writes, true)) {
    if ($method !== 'POST') {
        json_out(['error' => 'Nur POST.'], 405);
    }
    csrf_check();
    $fresh = static function (): array {
        $snapshot = new Snapshot(store(), ha());
        return $snapshot->livePayload($snapshot->build());
    };
    if ($path === '/api/modus') {
        Actions::saveChargeMode();
        json_out($fresh());
    }
    if ($path === '/api/limit') {
        Actions::saveLimit();
        json_out($fresh());
    }
    if ($path === '/api/darstellung') {
        Actions::saveTheme();
        json_out(['ok' => true]);
    }
    $zones = Actions::saveZones();
    json_out(['zones' => $zones, 'live' => $fresh()]);
}

if ($path === '/api/config.json') {
    header('Content-Disposition: attachment; filename="ems-konfiguration.json"');
    json_out(Actions::portable());
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
            json_out($series->battery($config['mapping']));
        }
        if ($chart === 'power') {
            json_out($series->power($config['mapping'], $config['plant']));
        }
        if ($chart === 'daily' || $chart === 'compare') {
            json_out($series->days($config['plant'])[$chart]);
        }
        if ($chart === 'outlook') {
            json_out($series->outlook($config['plant']));
        }
        if ($chart === 'weather') {
            json_out($series->weather($config['mapping']));
        }
        if ($chart === 'sessions') {
            json_out(session_chart((string) ($_GET['span'] ?? 'month'), (string) ($_GET['month'] ?? date('Y-m')), (int) ($_GET['year'] ?? date('Y'))));
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
    if (($_POST['section'] ?? '') === 'import') {
        Actions::importConfig();
    }
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
    $back = stats_query();
    if (($_POST['action'] ?? '') === 'odometer') {
        $raw = str_replace(',', '.', trim((string) ($_POST['odometer'] ?? '')));
        $km = $raw === '' || !is_numeric($raw) ? null : (float) $raw;
        (new Sessions(store()))->setOdometer((int) ($_POST['id'] ?? 0), $km);
        flash($km === null ? 'Kilometerstand bleibt leer.' : 'Kilometerstand gespeichert.');
        redirect('/statistik' . $back);
    }
    $result = (new Sessions(store()))->import(ha());
    flash($result['message']);
    redirect('/statistik' . $back);
}

if ($path === '/batterie' && $method === 'POST') {
    $_POST['section'] = 'battery';
    $_POST['back'] = '/batterie';
    Actions::saveSettings();
}

$snapshot = new Snapshot(store(), ha());
$snap = $snapshot->build();
$live = $snapshot->livePayload($snap);

if ($path === '/komponenten' && demo_mode()) {
    page('komponenten', compact('snap', 'live') + ['title' => 'Komponenten', 'layout' => 'shell']);
}
if ($path === '/laden') {
    redirect('/');
}
if ($path === '/') {
    $overview = energy_overview();
    page('laden', compact('snap', 'live', 'overview') + ['title' => 'Laden', 'layout' => 'shell']);
}
if ($path === '/batterie') {
    redirect('/speicher');
}
if ($path === '/speicher') {
    $overview = energy_overview();
    page('speicher', compact('snap', 'live', 'overview') + ['title' => 'Speicher', 'layout' => 'shell']);
}
if ($path === '/laden') {
    $session = $snap['session'];
    page('charge', compact('snap', 'live', 'session') + ['title' => 'Laden']);
}
if ($path === '/statistik') {
    $tz = new DateTimeZone('Europe/Berlin');
    $span = (($_GET['span'] ?? 'month') === 'year') ? 'year' : 'month';
    $year = (int) ($_GET['year'] ?? date('Y'));
    if ($year < 2020 || $year > 2100) {
        $year = (int) date('Y');
    }
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || (int) substr($month, 0, 4) !== $year) {
        $month = sprintf('%04d-%s', $year, date('m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = sprintf('%04d-01', $year);
        }
    }
    $sorts = [
        'date_desc' => 'Datum absteigend',
        'date_asc' => 'Datum aufsteigend',
        'energy_desc' => 'Meist geladen',
        'energy_asc' => 'Wenigsten geladen',
        'solar_desc' => 'Meiste Sonne',
        'solar_asc' => 'Wenigste Sonne',
        'cost_desc' => 'Höchste Kosten',
        'cost_asc' => 'Niedrigste Kosten',
        'duration_desc' => 'Längste Dauer',
        'duration_asc' => 'Kürzeste Dauer',
    ];
    $sort = (string) ($_GET['sort'] ?? 'date_desc');
    if (!isset($sorts[$sort])) {
        $sort = 'date_desc';
    }
    $tariffs = cfg()['tariffs'];
    $sessions = new Sessions(store());
    if ($span === 'year') {
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $tz);
        $rows = $sessions->between($start, $start->modify('+1 year'));
    } else {
        $rows = $sessions->month($month);
    }
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
    usort($rows, static function (array $a, array $b) use ($sort): int {
        $cmp = match ($sort) {
            'date_asc', 'date_desc' => strcmp((string) $a['started_at'], (string) $b['started_at']),
            'energy_asc', 'energy_desc' => ((float) $a['energy_kwh'] <=> (float) $b['energy_kwh']),
            'solar_asc', 'solar_desc' => ((float) $a['costed']['solar_pct'] <=> (float) $b['costed']['solar_pct']),
            'cost_asc', 'cost_desc' => ((float) $a['costed']['cost'] <=> (float) $b['costed']['cost']),
            default => ((int) $a['duration_s'] <=> (int) $b['duration_s']),
        };
        return str_ends_with($sort, '_asc') ? $cmp : -$cmp;
    });
    $totals['solar_pct'] = $totals['energy'] > 0 ? $totals['solar'] / $totals['energy'] * 100 : 0;
    $totals['ct'] = $totals['energy'] > 0 ? $totals['cost'] / $totals['energy'] * 100 : 0;
    page('stats', compact('live', 'month', 'year', 'span', 'sort', 'sorts', 'rows', 'totals', 'tariffs') + ['title' => 'Statistik']);
}
if ($path === '/prognose') {
    $yield = safe_yield();
    $yesterday = yesterday_yield();
    $pack = (new Series(store(), ha()))->days(cfg()['plant']);
    $scores = $pack['goodness'] ?? [];
    $todayKey = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $horizon = (new DateTimeImmutable($todayKey, new DateTimeZone('Europe/Berlin')))->modify('+5 days')->format('Y-m-d');
    $actual = [];
    foreach (store()->pdo()->query('SELECT day, actual_kwh FROM daily') ?: [] as $row) {
        if ($row['actual_kwh'] === null) {
            continue;
        }
        $actual[(string) $row['day']] = (float) $row['actual_kwh'];
    }
    if ($yield !== null) {
        $actual[$todayKey] = $yield;
    }
    $upcoming = [];
    $past = [];
    foreach (Forecast::archiveRows(store()->pdo(), cfg()['plant'], $actual) as $row) {
        $day = (string) $row['day'];
        if ($day > $horizon) {
            continue;
        }
        if ($day >= $todayKey) {
            $upcoming[] = $row;
            continue;
        }
        if ($row['mean'] === null && (int) $row['n'] === 0) {
            continue;
        }
        $past[] = $row;
    }
    usort($upcoming, static fn (array $a, array $b): int => strcmp((string) $a['day'], (string) $b['day']));
    usort($past, static fn (array $a, array $b): int => strcmp((string) $b['day'], (string) $a['day']));
    $modelRows = $pack['board'] ?? [];
    $lesson = $pack['lesson'] ?? null;
    $overview = energy_overview();
    $captions = Forecast::captions(store()->pdo(), cfg()['plant']);
    $weather = (new WeatherFeed(store()))->meta();
    page('prognose', compact('snap', 'live', 'yield', 'yesterday', 'scores', 'upcoming', 'past', 'modelRows', 'todayKey', 'lesson', 'overview', 'captions', 'weather') + ['title' => 'Prognose', 'layout' => 'shell']);
}
if ($path === '/einstellungen') {
    $ping = ha()->ping();
    page('settings', ['cfg' => cfg(), 'ping' => $ping, 'live' => $live, 'suggest' => known_suggestions(), 'title' => 'Einstellungen']);
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
    $suggest = known_suggestions();
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

function known_suggestions(): array
{
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
        return [];
    }
    return $suggest;
}

/** Summen der Ladevorgänge für die Energieübersicht und die Kopf-Chips. */
function energy_overview(): array
{
    $tariffs = cfg()['tariffs'];
    $sessions = new Sessions(store());
    $all = $sessions->all();
    $tz = new DateTimeZone('Europe/Berlin');
    $since30 = (new DateTimeImmutable('today', $tz))->modify('-30 days')->format('c');
    $yearStart = (new DateTimeImmutable('first day of january this year', $tz))->setTime(0, 0)->format('c');
    $within = static fn (string $from): array => array_values(array_filter($all, static fn (array $row): bool => strcmp((string) $row['started_at'], $from) >= 0));
    return [
        'tariffs' => $tariffs,
        'periods' => [
            '30' => Sessions::summary($within($since30), $tariffs),
            'year' => Sessions::summary($within($yearStart), $tariffs),
            'all' => Sessions::summary($all, $tariffs),
        ],
    ];
}

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
    $tz = new DateTimeZone('Europe/Berlin');
    $end = (new DateTimeImmutable('today', $tz))->getTimestamp();
    try {
        return (new Series(store(), ha()))->yieldBetween($end - 86400, $end, cfg()['mapping']);
    } catch (Throwable) {
        return null;
    }
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

function stats_query(): string
{
    $span = (($_POST['span'] ?? $_GET['span'] ?? 'month') === 'year') ? 'year' : 'month';
    $year = (int) ($_POST['year'] ?? $_GET['year'] ?? date('Y'));
    $month = (string) ($_POST['month'] ?? $_GET['month'] ?? date('Y-m'));
    $sort = (string) ($_POST['sort'] ?? $_GET['sort'] ?? 'date_desc');
    return '?' . http_build_query(['span' => $span, 'year' => $year, 'month' => $month, 'sort' => $sort]);
}

function session_chart(string $span, string $month, int $year): array
{
    $tz = new DateTimeZone('Europe/Berlin');
    $span = $span === 'year' ? 'year' : 'month';
    if ($year < 2020 || $year > 2100) {
        $year = (int) date('Y');
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $sessions = new Sessions(store());
    if ($span === 'year') {
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $tz);
        $end = $start->modify('+1 year');
        $rows = $sessions->between($start, $end);
        $count = (int) $start->diff($end)->days;
        $xTitle = 'Tag im Jahr';
    } else {
        $start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
        $rows = $sessions->month($month);
        $count = (int) $start->format('t');
        $xTitle = 'Tag';
    }
    $days = [];
    foreach ($rows as $row) {
        try {
            $when = (new DateTimeImmutable((string) $row['started_at']))->setTimezone($tz);
        } catch (Throwable) {
            continue;
        }
        $day = $span === 'year' ? ((int) $when->format('z')) + 1 : (int) $when->format('j');
        $days[$day]['solar'] = ($days[$day]['solar'] ?? 0) + (float) $row['solar_kwh'];
        $days[$day]['grid'] = ($days[$day]['grid'] ?? 0) + (float) $row['grid_kwh'];
    }
    $labels = $solar = $grid = [];
    for ($day = 1; $day <= $count; $day++) {
        $labels[] = (string) $day;
        $solar[] = round($days[$day]['solar'] ?? 0, 2);
        $grid[] = round($days[$day]['grid'] ?? 0, 2);
    }
    $last = max(0, $count - 1);
    $window = $span === 'year' ? 10 : 7;
    $today = new DateTimeImmutable('now', $tz);
    $focus = 0;
    if ($span === 'year' && (int) $today->format('Y') === (int) $start->format('Y')) {
        $focus = (int) $today->format('z');
    } elseif ($span !== 'year' && $today->format('Y-m') === $start->format('Y-m')) {
        $focus = (int) $today->format('j') - 1;
    }
    $focus = max(0, min($last, $focus));
    $hi = min($last, $focus + intdiv($window, 2));
    $lo = max(0, $hi - $window + 1);
    $hi = min($last, $lo + $window - 1);
    return [
        'axis' => 'category',
        'stacked' => true,
        'pan' => 'index',
        'labels' => $labels,
        'days' => max(1, $count),
        'view' => [$lo, $hi],
        'bounds' => [0, $last],
        'xTitle' => $xTitle,
        'yTitle' => 'Energie (kWh)',
        'series' => [
            ['key' => 'solar', 'label' => 'Sonne', 'color' => 'sun', 'type' => 'bar', 'stack' => 'energy', 'data' => $solar],
            ['key' => 'grid', 'label' => 'Netz', 'color' => 'net', 'type' => 'bar', 'stack' => 'energy', 'data' => $grid],
        ],
    ];
}
