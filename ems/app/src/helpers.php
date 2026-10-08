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

function num(?float $value, int $decimals = 2): string
{
    if ($value === null || is_nan($value)) {
        return '—';
    }
    return number_format($value, $decimals, ',', '.');
}

function kw(?float $value): string
{
    return $value === null ? '—' : num($value, 2) . ' kW';
}

function kwh(?float $value, int $decimals = 1): string
{
    return $value === null ? '—' : num($value, $decimals) . ' kWh';
}

function pct(?float $value, int $decimals = 0): string
{
    return $value === null ? '—' : num($value, $decimals) . ' %';
}

function amps(?float $value): string
{
    return $value === null ? '—' : num($value, 0) . ' A';
}

function ct(?float $value): string
{
    return $value === null ? '—' : num($value, 1) . ' ct/kWh';
}

function euro(?float $value): string
{
    return $value === null ? '—' : num($value, 2) . ' €';
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

function icon(string $name, string $class = 'h-4 w-4'): string
{
    $file = EMS_APP . '/assets/icons/' . basename($name) . '.svg';
    if (!is_file($file)) {
        return '';
    }
    $svg = (string) file_get_contents($file);
    $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;
    $svg = preg_replace_callback('/<svg\b([^>]*)>/', static function (array $match) use ($class): string {
        $attrs = preg_replace('/\s(?:width|height|class)="[^"]*"/', '', $match[1]) ?? $match[1];
        return '<svg' . $attrs . ' class="' . e($class) . ' overflow-visible" overflow="visible" aria-hidden="true">';
    }, $svg, 1) ?? $svg;
    return $svg;
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

function render(string $view, array $data = []): never
{
    $data['flash'] = flash();
    extract($data, EXTR_SKIP);
    ob_start();
    require EMS_APP . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require EMS_APP . '/views/layout.php';
    exit;
}

function view(string $partial, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require EMS_APP . '/views/' . $partial . '.php';
}
