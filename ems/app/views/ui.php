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

function entity_field(string $name, string $label, string $value, string $hint, string $suggest = ''): void
{
    echo '<label class="block"><span class="mb-1 block text-sm font-medium">' . e($label) . '</span>';
    echo '<input class="field" name="' . e($name) . '" value="' . e($value) . '" data-entity-search placeholder="sensor…" autocomplete="off">';
    echo '<span class="mt-1 block text-xs text-muted-foreground">' . e($hint) . '</span>';
    if ($suggest !== '' && $suggest !== $value) {
        echo '<button type="button" class="mt-1 text-xs font-medium text-primary" data-fill="' . e($name) . '" data-value="' . e($suggest) . '">Vorschlag übernehmen: ' . e($suggest) . '</button>';
    }
    echo '</label>';
}

function chart_box(string $endpoint, string $class = 'h-72'): void
{
    echo '<div class="' . e($class) . '" data-chart data-url="' . e(url($endpoint)) . '"><canvas></canvas></div>';
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
