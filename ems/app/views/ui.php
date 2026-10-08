<?php
declare(strict_types=1);

function page_head(string $title, string $text = ''): void
{
    echo '<header class="mb-6"><h1 class="text-2xl font-semibold tracking-tight">' . e($title) . '</h1>';
    if ($text !== '') {
        echo '<p class="mt-1 max-w-2xl text-sm text-muted-foreground">' . e($text) . '</p>';
    }
    echo '</header>';
}

function stat_card(string $label, string $value, string $live, string $tone = 'foreground'): void
{
    echo '<div class="card p-4"><p class="text-xs font-medium text-muted-foreground">' . e($label) . '</p>';
    echo '<p class="mt-1 text-2xl font-semibold tabular-nums" data-live="' . e($live) . '">' . $value . '</p></div>';
}

function tip(string $text, bool $wide = false): void
{
    echo '<span class="tip"><button type="button" class="tip-btn" aria-label="Erläuterung">' . icon('info', 'h-3.5 w-3.5') . '</button>';
    echo '<span class="tip-body' . ($wide ? ' tip-wide' : '') . '" role="tooltip">' . e($text) . '</span></span>';
}

function formula(string $mathml): void
{
    echo '<div class="formula-scroll"><math xmlns="http://www.w3.org/1998/Math/MathML">' . $mathml . '</math></div>';
}

function mnum(?float $value, int $decimals = 2): string
{
    return '<mn>' . e(num($value, $decimals)) . '</mn>';
}

require __DIR__ . '/forecast_method.php';

function entity_field(string $name, string $label, string $value, string $hint, string $suggest = ''): void
{
    echo '<label class="entity block"><span class="mb-1 block text-sm font-medium">' . e($label) . '</span>';
    echo '<input class="field" name="' . e($name) . '" value="' . e($value) . '" data-entity-search placeholder="Name oder Entität suchen" autocomplete="off" role="combobox" aria-autocomplete="list">';
    echo '<span class="mt-1 block text-xs text-muted-foreground">' . e($hint) . '</span>';
    if ($suggest !== '' && $suggest !== $value) {
        echo '<button type="button" class="mt-1 text-xs font-medium text-primary" data-fill="' . e($name) . '" data-value="' . e($suggest) . '">Vorschlag übernehmen: ' . e($suggest) . '</button>';
    }
    echo '</label>';
}

function period_nav(string $path, int $year, string $month, string $span, array $keep, array $modes): void
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = sprintf('%04d-01', $year);
    }
    $current = new DateTimeImmutable($month . '-01', new DateTimeZone('Europe/Berlin'));
    $prevM = $current->modify('-1 month');
    $nextM = $current->modify('+1 month');
    $query = static function (array $patch) use ($path, $keep): string {
        return url($path . '?' . http_build_query($keep + $patch));
    };
    if ($span === 'year') {
        $label = (string) $year;
        $prev = $query(['span' => 'year', 'year' => $year - 1, 'month' => sprintf('%04d-%s', $year - 1, $current->format('m'))]);
        $next = $query(['span' => 'year', 'year' => $year + 1, 'month' => sprintf('%04d-%s', $year + 1, $current->format('m'))]);
    } elseif ($span === 'all') {
        $label = 'Alle Tage';
        $prev = '';
        $next = '';
    } else {
        $label = month_label($current->format('Y-m'));
        $prev = $query(['span' => 'month', 'year' => (int) $prevM->format('Y'), 'month' => $prevM->format('Y-m')]);
        $next = $query(['span' => 'month', 'year' => (int) $nextM->format('Y'), 'month' => $nextM->format('Y-m')]);
    }
    $names = ['month' => 'Monat', 'year' => 'Jahr', 'all' => 'Alle'];
    echo '<section class="card flex items-center gap-2 p-3">';
    if ($prev !== '') {
        echo '<a class="btn-ghost min-h-11 min-w-11 shrink-0 px-0" href="' . e($prev) . '" aria-label="Zurück">‹</a>';
    }
    echo '<p class="min-w-0 flex-1 truncate text-center text-base font-semibold">' . e($label) . '</p>';
    if ($next !== '') {
        echo '<a class="btn-ghost min-h-11 min-w-11 shrink-0 px-0" href="' . e($next) . '" aria-label="Weiter">›</a>';
    }
    foreach ($modes as $mode) {
        $on = $mode === $span;
        echo '<a class="' . ($on ? 'btn-primary' : 'btn-ghost') . ' min-h-11 shrink-0" href="' . e($query(['span' => $mode, 'year' => $year, 'month' => $month])) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . e($names[$mode] ?? $mode) . '</a>';
    }
    echo '</section>';
}

function forecast_archive_rows(array $rows, string $empty, string $today = ''): void
{
    if (!$rows) {
        echo '<tr><td class="px-4 py-4 text-muted-foreground" colspan="9">' . e($empty) . '</td></tr>';
        return;
    }
    foreach ($rows as $row) {
        $mark = $today !== '' && (string) $row['day'] === $today;
        echo '<tr class="border-t border-border' . ($mark ? ' row-today' : '') . '">';
        echo '<td class="whitespace-nowrap px-4 py-3">' . e(day_label((string) $row['day'])) . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="actual">' . e(kwh($row['actual'], 1)) . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="mean">' . e(kwh($row['mean'], 1)) . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="sd">' . ($row['sd'] === null ? '—' : '± ' . e(num((float) $row['sd'], 1)) . ' kWh') . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="radiation">' . ($row['radiation'] === null ? '—' : e(num((float) $row['radiation'] / 1000, 2)) . ' kWh/m²') . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="sun">' . ($row['sunshine_s'] === null ? '—' : e(num((float) $row['sunshine_s'] / 3600, 1)) . ' h') . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="cloud">' . ($row['cloud'] === null ? '—' : e(num((float) $row['cloud'], 0)) . ' %') . '</td>';
        echo '<td class="py-3 tabular-nums" data-col="temp">' . ($row['temp_c'] === null ? '—' : e(num((float) $row['temp_c'], 1)) . ' °C') . '</td>';
        echo '<td class="py-3 pr-4 tabular-nums" data-col="runs">' . (int) $row['n'] . '</td>';
        echo '</tr>';
    }
}

function chart_box(string $endpoint, string $class = 'h-72', string $mode = ''): void
{
    if ($mode === 'scroll') {
        echo '<div class="touch-x min-w-0 max-w-full">';
    }
    $attr = $mode === 'scroll' ? ' data-scroll="1"' : ($mode === 'pan' ? ' data-pan="1"' : '');
    echo '<div class="' . e($class) . '" data-chart' . $attr . ' data-url="' . e(url($endpoint)) . '"><canvas></canvas></div>';
    if ($mode === 'scroll') {
        echo '</div>';
    }
}

function config_exchange(string $action, string $back): void
{
    echo '<div class="space-y-3">';
    echo '<a class="btn-ghost" href="' . e(url('/api/config.json')) . '">JSON herunterladen</a>';
    echo '<form method="post" enctype="multipart/form-data" action="' . e(url($action)) . '" class="space-y-3">';
    echo csrf_field();
    echo '<input type="hidden" name="section" value="import"><input type="hidden" name="back" value="' . e($back) . '">';
    echo '<label class="block text-sm">JSON einfügen<textarea class="field mt-1 min-h-[7rem] font-mono text-xs" name="config_json" placeholder="{ &quot;version&quot;: 1, &quot;mapping&quot;: { } }"></textarea></label>';
    echo '<label class="block text-sm">oder Datei<input class="field mt-1" type="file" name="config_file" accept="application/json,.json"></label>';
    echo '<button class="btn-primary" type="submit">JSON importieren</button>';
    echo '</form></div>';
}

function mode_options(): array
{
    return [
        'aus' => 'Aus',
        'smart' => 'Smart',
        'smart_dauerhaft' => 'Smart (+ Dauerhaft)',
        'schnell' => 'Schnell',
    ];
}
