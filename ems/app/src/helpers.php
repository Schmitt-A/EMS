<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ingress_base(): string
{
    $path = $_SERVER['HTTP_X_INGRESS_PATH'] ?? '';
    return rtrim((string) $path, '/');
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = ingress_base();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim((string) $path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

function url(string $path = '/'): string
{
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/' && !str_starts_with($path, '?')) {
        $path = '/' . $path;
    }
    return ingress_base() . $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
    }
}

function ingest_json_body(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $_POST) {
        return;
    }
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($type, 'application/json')) {
        return;
    }
    $raw = file_get_contents('php://input');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return;
    }
    foreach ($data as $key => $value) {
        if (!is_string($key) || str_contains($key, "\0")) {
            continue;
        }
        if (is_bool($value)) {
            $_POST[$key] = $value ? '1' : '0';
        } elseif (is_scalar($value)) {
            $_POST[$key] = (string) $value;
        }
    }
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $current = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($current) ? $current : null;
}

/** Schmales, nicht umbrechendes Leerzeichen zwischen Zahl und Einheit. */
const NNBSP = "\u{202F}";

function num(?float $value, int $decimals = 2): string
{
    if ($value === null || is_nan($value)) {
        return '—';
    }
    return number_format($value, $decimals, ',', '.');
}

function with_unit(?float $value, int $decimals, string $unit): string
{
    return $value === null || is_nan($value) ? '—' : num($value, $decimals) . NNBSP . $unit;
}

function kw(?float $value, int $decimals = 1): string
{
    return with_unit($value, $decimals, 'kW');
}

function kwh(?float $value, int $decimals = 1): string
{
    return with_unit($value, $decimals, 'kWh');
}

function pct(?float $value, int $decimals = 0): string
{
    return with_unit($value, $decimals, '%');
}

function amps(?float $value): string
{
    return with_unit($value, 0, 'A');
}

function ct(?float $value): string
{
    return with_unit($value, 1, 'ct/kWh');
}

function euro(?float $value): string
{
    return with_unit($value, 2, '€');
}

/** Zahl und Einheit als zwei Spans, die Einheit eine Stufe kleiner und leichter. */
function metric(?float $value, string $unit, int $decimals = 1): string
{
    if ($value === null || is_nan($value)) {
        return '<span class="num">—</span>';
    }
    return '<span class="num">' . e(num($value, $decimals)) . '</span>' . NNBSP . '<span class="unit">' . e($unit) . '</span>';
}

function demo_mode(): bool
{
    return getenv('EMS_DEMO') === '1';
}

/** Gebaute Datei unter /assets/build mit Inhalts-Hash als Version. */
function asset(string $file): string
{
    static $manifest = null;
    if ($manifest === null) {
        $json = @file_get_contents(EMS_APP . '/public/assets/build/manifest.json');
        $manifest = is_string($json) ? (json_decode($json, true) ?: []) : [];
    }
    $version = $manifest[$file] ?? '';
    return url('/assets/build/' . $file) . ($version !== '' ? '?v=' . $version : '');
}

function duration_label(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return $hours . ' h ' . $rest . ' min';
}

/** Icon aus dem Sprite. Die Größe kommt über die Klasse, die Farbe über currentColor. */
function icon(string $name, string $class = 'icon-16'): string
{
    $id = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?? '';
    return '<svg class="icon ' . e($class) . '" aria-hidden="true" focusable="false"><use href="' . e(asset('icons.svg')) . '#' . $id . '"></use></svg>';
}

function month_label(string $month): string
{
    $names = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];
    $stamp = new DateTimeImmutable($month . '-01', new DateTimeZone('Europe/Berlin'));
    return ($names[(int) $stamp->format('n')] ?? $month) . ' ' . $stamp->format('Y');
}

function when_label(?int $ts): string
{
    if ($ts === null) {
        return '—';
    }
    $tz = new DateTimeZone('Europe/Berlin');
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $today = new DateTimeImmutable('today', $tz);
    $day = $dt->format('Y-m-d');
    $time = $dt->format('H:i');
    if ($day === $today->format('Y-m-d')) {
        return 'heute ' . $time;
    }
    if ($day === $today->modify('+1 day')->format('Y-m-d')) {
        return 'morgen ' . $time;
    }
    return day_label($day) . ', ' . $time;
}

function day_label(string $day): string
{
    $dt = new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('Europe/Berlin'));
    $names = ['Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa', 'Sun' => 'So'];
    return ($names[$dt->format('D')] ?? $dt->format('D')) . ' ' . $dt->format('d.m.');
}

function long_when(string $iso): string
{
    try {
        $dt = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Berlin'));
    } catch (Throwable) {
        return $iso;
    }
    $days = ['Mon' => 'Mo.', 'Tue' => 'Di.', 'Wed' => 'Mi.', 'Thu' => 'Do.', 'Fri' => 'Fr.', 'Sat' => 'Sa.', 'Sun' => 'So.'];
    $months = [1 => 'Jan.', 2 => 'Feb.', 3 => 'März', 4 => 'Apr.', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'Aug.', 9 => 'Sep.', 10 => 'Okt.', 11 => 'Nov.', 12 => 'Dez.'];
    $day = $days[$dt->format('D')] ?? $dt->format('D');
    $month = $months[(int) $dt->format('n')] ?? $dt->format('m.');
    return $day . ', ' . $dt->format('j') . '. ' . $month . ' ' . $dt->format('Y') . ', ' . $dt->format('H:i');
}

function duration_clock(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $hours . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ' h';
}

function is_entity_id(string $id): bool
{
    return (bool) preg_match('/^[a-z_]+\\.[a-z0-9_]+$/', $id);
}

function clamp_float(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function snap_percent(float $value): float
{
    $step = round(clamp_float($value, 0, 100) / 5) * 5;

    return max(0.0, min(100.0, $step));
}

/**
 * Hausgrenze, Stützung und automatischer Start, auf 5 % gerastert und aufsteigend.
 *
 * @return array{priority_soc: float, car_buffer_soc: float, car_auto_soc: float}
 */
function zone_thresholds(float $priority, float $buffer, float $auto): array
{
    $priority = snap_percent($priority);
    $buffer = snap_percent($buffer);
    $auto = snap_percent($auto);
    if ($buffer < $priority) {
        $buffer = $priority;
    }
    if ($auto < $buffer) {
        $auto = $buffer;
    }

    return [
        'priority_soc' => $priority,
        'car_buffer_soc' => $buffer,
        'car_auto_soc' => $auto,
    ];
}

function post_float(string $key, float $min, float $max, float $fallback): float
{
    $raw = str_replace(',', '.', trim((string) ($_POST[$key] ?? '')));
    if ($raw === '' || !is_numeric($raw)) {
        return $fallback;
    }
    return clamp_float((float) $raw, $min, $max);
}

function post_int(string $key, int $min, int $max, int $fallback): int
{
    return (int) round(post_float($key, $min, $max, $fallback));
}

function post_entity(string $key): string
{
    $id = strtolower(trim((string) ($_POST[$key] ?? '')));
    return is_entity_id($id) ? $id : '';
}

function data_dir(): string
{
    $dir = getenv('EMS_DATA') ?: '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Datenverzeichnis nicht beschreibbar: ' . $dir);
    }
    return $dir;
}

function connection(): array
{
    $supervisor = getenv('SUPERVISOR_TOKEN') ?: '';
    if ($supervisor !== '') {
        return ['url' => 'http://supervisor/core', 'token' => $supervisor, 'source' => 'supervisor'];
    }
    $url = rtrim((string) (getenv('HA_URL') ?: ''), '/');
    $token = (string) (getenv('HA_TOKEN') ?: '');
    $file = data_dir() . '/connection.json';
    if (is_file($file)) {
        $json = json_decode((string) file_get_contents($file), true);
        if (is_array($json)) {
            if ($url === '' && !empty($json['url'])) {
                $url = rtrim((string) $json['url'], '/');
            }
            if ($token === '' && !empty($json['token'])) {
                $token = (string) $json['token'];
            }
        }
    }
    return ['url' => $url, 'token' => $token, 'source' => ($url !== '' && $token !== '') ? 'local' : 'missing'];
}

function save_connection(string $url, ?string $token): void
{
    $current = connection();
    $next = [
        'url' => rtrim($url, '/'),
        'token' => ($token === null || $token === '') ? ($current['source'] === 'supervisor' ? '' : $current['token']) : $token,
    ];
    $file = data_dir() . '/connection.json';
    file_put_contents($file, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($file, 0600);
}

/**
 * Freigabe der Seitenübergänge. Steht inline im Kopf (per CSP-Hash erlaubt), weil Chrome sie beim
 * Einfügen von <body> liest; app.css lädt dann womöglich noch, und der Übergang bräche ab.
 */
const VIEW_TRANSITION_CSS = '@view-transition{navigation:auto}@media (prefers-reduced-motion:reduce){@view-transition{navigation:none}}';

function render(string $view, array $data = []): never
{
    $data['flash'] = flash();
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'sha256-" . base64_encode(hash('sha256', VIEW_TRANSITION_CSS, true)) . "'");
    header('X-Content-Type-Options: nosniff');
    extract($data, EXTR_SKIP);
    ob_start();
    require EMS_APP . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require EMS_APP . '/views/shell.php';
    exit;
}

function view(string $partial, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require EMS_APP . '/views/' . $partial . '.php';
}
