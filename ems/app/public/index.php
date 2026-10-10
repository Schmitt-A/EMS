<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

ingest_json_body();

$path = request_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/favicon.ico') {
    // Browser fragen das klassische Favicon zusätzlich an; die Seite verlinkt ein SVG.
    http_response_code(204);
    exit;
}

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
            $params = sessions_params($_GET);
            json_out(Sessions::chart(sessions_chart_rows($params), $params['span'], $params['metric'], cfg()['tariffs'], $params['month'], $params['year']));
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

if (($path === '/einstellungen' || $path === '/mehr' || str_starts_with($path, '/mehr/')) && $method === 'POST') {
    Actions::saveSettings();
}

if (($path === '/ladevorgaenge' || $path === '/statistik') && $method === 'POST') {
    csrf_check();
    $params = sessions_params($_POST);
    $sessions = new Sessions(store());
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'odometer') {
        $raw = str_replace(',', '.', trim((string) ($_POST['odometer'] ?? '')));
        $km = $raw === '' || !is_numeric($raw) ? null : (float) $raw;
        $sessions->setOdometer($id, $km);
        flash($km === null ? 'Kilometerstand bleibt leer.' : 'Kilometerstand gespeichert.');
        redirect('/ladevorgaenge' . sessions_query($params, ['vorgang' => $id]));
    }
    if ($action === 'delete') {
        flash($sessions->deleteGroup($id) ? 'Ladevorgang gelöscht.' : 'Ein laufender Ladevorgang lässt sich nicht löschen.');
        redirect('/ladevorgaenge' . sessions_query($params));
    }
    $result = $sessions->import(ha());
    flash($result['message']);
    redirect('/ladevorgaenge' . sessions_query($params));
}

$snapshot = new Snapshot(store(), ha());
$snap = $snapshot->build();
$live = $snapshot->livePayload($snap);

if ($path === '/komponenten' && demo_mode()) {
    $overview = energy_overview();
    page('komponenten', compact('snap', 'live', 'overview') + ['title' => 'Komponenten']);
}
if ($path === '/laden') {
    redirect('/');
}
if ($path === '/') {
    $overview = energy_overview();
    page('laden', compact('snap', 'live', 'overview') + ['title' => 'Laden']);
}
if ($path === '/batterie') {
    redirect('/speicher');
}
if ($path === '/speicher') {
    $overview = energy_overview();
    page('speicher', compact('snap', 'live', 'overview') + ['title' => 'Speicher']);
}
if ($path === '/statistik') {
    redirect('/ladevorgaenge' . sessions_query(sessions_params($_GET)));
}
if ($path === '/ladevorgaenge') {
    $params = sessions_params($_GET);
    $tariffs = cfg()['tariffs'];
    $sessions = new Sessions(store());
    $sessions->repairVehicleNames((string) (cfg()['vehicle']['name'] ?? ''));
    $sessions->backfillPlugs(ha(), (string) (cfg()['mapping']['wallbox_car'] ?? ''), time());
    $cycles = sessions_rows($params);
    // Ein Ladevorgang reicht vom Anstecken bis zum Abstecken; seine Zyklen klappen in der Liste auf.
    $rows = Sessions::groups($cycles);
    $priced = static function (array $row) use ($tariffs): array {
        $row['costed'] = Sessions::cost($row, $tariffs);
        $row['co2'] = Sessions::co2($row, (float) ($tariffs['co2_g_kwh'] ?? 380));
        return $row;
    };
    foreach ($rows as &$row) {
        $row = $priced($row);
        $row['cycles'] = array_map($priced, $row['cycles']);
    }
    unset($row);
    $sort = $params['sort'];
    usort($rows, static function (array $a, array $b) use ($sort): int {
        $cmp = match (preg_replace('/_(asc|desc)$/', '', $sort)) {
            'energy' => ((float) $a['energy_kwh'] <=> (float) $b['energy_kwh']),
            'solar' => ((float) $a['costed']['solar_pct'] <=> (float) $b['costed']['solar_pct']),
            'cost' => ((float) $a['costed']['cost'] <=> (float) $b['costed']['cost']),
            'duration' => ((int) $a['duration_s'] <=> (int) $b['duration_s']),
            default => strcmp((string) $a['started_at'], (string) $b['started_at']),
        };
        return str_ends_with($sort, '_asc') ? $cmp : -$cmp;
    });
    $summary = Sessions::summary($cycles, $tariffs);
    $years = [];
    if ($params['span'] === 'all') {
        $byYear = [];
        foreach ($cycles as $row) {
            $byYear[substr((string) $row['started_at'], 0, 4)][] = $row;
        }
        krsort($byYear);
        foreach ($byYear as $y => $list) {
            $years[] = ['year' => (int) $y, 'summary' => Sessions::summary($list, $tariffs)];
        }
    }
    $detail = isset($_GET['vorgang']) ? $sessions->group((int) $_GET['vorgang']) : null;
    $hasAny = $params['span'] === 'all' ? (bool) $rows : (bool) $sessions->latest();
    $overview = energy_overview();
    page('ladevorgaenge', compact('live', 'params', 'rows', 'summary', 'years', 'detail', 'tariffs', 'hasAny', 'overview') + ['title' => 'Ladevorgänge']);
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
    page('prognose', compact('snap', 'live', 'yield', 'yesterday', 'scores', 'upcoming', 'past', 'modelRows', 'todayKey', 'lesson', 'overview', 'captions', 'weather') + ['title' => 'Prognose']);
}
if ($path === '/einstellungen') {
    redirect('/mehr');
}
if ($path === '/mehr' || str_starts_with($path, '/mehr/')) {
    $area = trim(substr($path, strlen('/mehr')), '/');
    if ($area !== '' && (!preg_match('/^[a-z]+$/', $area) || !is_file(EMS_APP . '/views/mehr/' . $area . '.php'))) {
        redirect('/mehr');
    }
    page('mehr', ['area' => $area === '' ? null : $area, 'cfg' => cfg(), 'ping' => ha()->ping(), 'live' => $live, 'suggest' => known_suggestions(), 'title' => 'Mehr']);
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
    page('einrichten', [
        'step' => $step,
        'cfg' => cfg(),
        'ping' => $ping,
        'connection' => $connection,
        'suggest' => $suggest,
        'missing' => $missing,
        'review' => $review,
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

/** Zeitraum, Jahr, Monat, Kennzahl und Sortierung der Ladevorgänge, geprüft. */
function sessions_params(array $source): array
{
    $span = (string) ($source['span'] ?? 'month');
    if (!in_array($span, ['month', 'year', 'all'], true)) {
        $span = 'month';
    }
    $year = (int) ($source['year'] ?? date('Y'));
    if ($year < 2020 || $year > 2100) {
        $year = (int) date('Y');
    }
    $month = (string) ($source['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || (int) substr($month, 0, 4) !== $year) {
        $month = sprintf('%04d-%s', $year, date('m'));
    }
    $metric = (string) ($source['metric'] ?? 'energy');
    if (!in_array($metric, ['energy', 'cost', 'co2'], true)) {
        $metric = 'energy';
    }
    $sort = (string) ($source['sort'] ?? 'date_desc');
    if (!preg_match('/^(date|energy|solar|cost|duration)_(asc|desc)$/', $sort)) {
        $sort = 'date_desc';
    }
    return ['span' => $span, 'year' => $year, 'month' => $month, 'metric' => $metric, 'sort' => $sort];
}

function sessions_query(array $params, array $patch = []): string
{
    return '?' . http_build_query(array_filter(array_merge($params, $patch), static fn (mixed $v): bool => $v !== null && $v !== ''));
}

/** Vorgänge fürs Diagramm: im laufenden Monat oder Jahr auch die Tage und Monate davor, die links vom Zeitraum stehen. */
function sessions_chart_rows(array $params): array
{
    $sessions = new Sessions(store());
    if ($params['span'] === 'all') {
        return $sessions->all();
    }
    $axis = Sessions::chartAxis($params['span'], $params['month'], $params['year']);
    return $sessions->between($axis['from'], $axis['to']);
}

function sessions_rows(array $params): array
{
    $sessions = new Sessions(store());
    if ($params['span'] === 'all') {
        return $sessions->all();
    }
    if ($params['span'] === 'year') {
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $params['year']), new DateTimeZone('Europe/Berlin'));
        return $sessions->between($start, $start->modify('+1 year'));
    }
    return $sessions->month($params['month']);
}
