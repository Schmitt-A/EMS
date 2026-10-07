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

function tip(string $text): void
{
    echo '<span class="tip"><button type="button" class="tip-btn" aria-label="Erläuterung">' . icon('info', 'h-3.5 w-3.5') . '</button>';
    echo '<span class="tip-body" role="tooltip">' . e($text) . '</span></span>';
}

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

function chart_box(string $endpoint, string $class = 'h-72', string $mode = ''): void
{
    if ($mode === 'scroll') {
        echo '<div class="overflow-x-auto">';
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
