<?php
declare(strict_types=1);

/*
 * Bausteine der Oberfläche nach Abschnitt 7 der Vorgabe. Jede Funktion gibt HTML zurück, alle Texte
 * laufen durch e(). Proportionen (Breiten, Füllstände) stehen als data-Attribute und werden per CSSOM
 * gesetzt, nie als style-Attribut, damit die CSP default-src 'self' hält.
 */

function ui_attrs(array $attrs): string
{
    $out = '';
    foreach ($attrs as $key => $value) {
        if ($value === null || $value === false) {
            continue;
        }
        $out .= $value === true ? ' ' . $key : ' ' . $key . '="' . e((string) $value) . '"';
    }
    return $out;
}

function ui_json(mixed $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ui_page_head(string $title, string $chip = ''): string
{
    return '<header class="page-head"><h1 class="page-title">' . e($title) . '</h1>' . $chip . '</header>';
}

/** Kennzahl-Chip rechts im Kopf, öffnet die Energieübersicht. */
function ui_head_chip(string $icon, string $valueHtml, string $label, string $dialog = 'energy-overview'): string
{
    return '<button type="button" class="head-chip" data-open-dialog="' . e($dialog) . '" aria-haspopup="dialog" aria-label="' . e($label) . '">'
        . icon($icon, 'icon-16') . '<span class="num">' . $valueHtml . '</span></button>';
}

function ui_icon_button(string $icon, string $label, array $attrs = []): string
{
    $class = trim('icon-btn ' . ($attrs['class'] ?? ''));
    unset($attrs['class']);
    return '<button type="button" class="' . e($class) . '"' . ui_attrs(['aria-label' => $label] + $attrs) . '>' . icon($icon, 'icon-20') . '</button>';
}

/** 7.8 Unterstrichener Wert: ein Button für Dialoge, ein Link für andere Seiten. */
function ui_inline(string $html, array $attrs = [], string $class = ''): string
{
    $classes = trim('inline-action ' . $class);
    if (isset($attrs['href'])) {
        return '<a class="' . e($classes) . '"' . ui_attrs($attrs) . '>' . $html . '</a>';
    }
    return '<button type="button" class="' . e($classes) . '"' . ui_attrs($attrs) . '>' . $html . '</button>';
}

/** Kennzahl mit Label in Versalien und großem Wert. */
function ui_metric(string $label, string $valueHtml, array $opts = []): string
{
    $size = $opts['size'] ?? 'lg';
    return '<div class="metric' . (isset($opts['class']) ? ' ' . e($opts['class']) : '') . '">'
        . '<span class="label">' . e($label) . '</span>'
        . '<span class="value metric-' . e($size) . '"' . ui_attrs($opts['attrs'] ?? []) . '>' . $valueHtml . '</span>'
        . ($opts['after'] ?? '')
        . '</div>';
}

/** 7.9 Kennzahl-Block: Icon in Datenfarbe, Label, großer Wert, zwei Sekundärzeilen. */
function ui_metric_block(string $icon, string $tone, string $label, string $valueHtml, array $lines = []): string
{
    $html = '<div class="metric-block">' . icon($icon, 'icon-40 tone-' . $tone) . '<span class="block-label tone-' . e($tone === 'grid-out' ? 'muted' : $tone) . '">' . e($label) . '</span>'
        . '<span class="metric-xl">' . $valueHtml . '</span>';
    if ($lines) {
        $html .= '<span class="block-lines body-sm">' . implode('<br>', array_map('e', $lines)) . '</span>';
    }
    return $html . '</div>';
}

/**
 * 7.2 Segment-Umschalter.
 * @param array<string, string|array{label: string, short?: string, shortIcon?: string, icon?: string, disabled?: bool}> $options
 */
function ui_segment(string $name, string $legend, array $options, ?string $current, array $opts = []): string
{
    $classes = 'seg' . (!empty($opts['compact']) ? ' seg-compact' : '') . (!empty($opts['fit']) ? ' seg-fit' : '');
    $base = (string) ($opts['id'] ?? ('seg-' . $name));
    $html = '<fieldset class="' . $classes . '"' . ui_attrs($opts['attrs'] ?? []) . '><legend>' . e($legend) . '</legend><span class="seg-thumb" aria-hidden="true"></span>';
    foreach ($options as $value => $option) {
        $label = is_array($option) ? $option['label'] : $option;
        $id = $base . '-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $value);
        $short = is_array($option) && isset($option['short']);
        $html .= '<input' . ui_attrs([
            'type' => 'radio',
            'id' => $id,
            'name' => $name,
            'value' => (string) $value,
            'checked' => $current === (string) $value,
            'disabled' => is_array($option) && !empty($option['disabled']),
            'form' => $opts['form'] ?? null,
            'aria-label' => $short ? $label : null,
        ]) . '><label for="' . e($id) . '">';
        if ($short) {
            $html .= '<span class="seg-long">' . e($label) . '</span><span class="seg-short" aria-hidden="true">' . e($option['short'])
                . (isset($option['shortIcon']) ? icon($option['shortIcon'], 'icon-16') : '') . '</span>';
        } else {
            $html .= (is_array($option) && isset($option['icon']) ? icon($option['icon'], 'icon-16') : '') . '<span class="seg-label">' . e($label) . '</span>';
        }
        $html .= '</label>';
    }
    return $html . '</fieldset>';
}

/** Die vier Lademodi, intern unverändert, angezeigt nach der Vorgabe. */
function ui_mode_options(): array
{
    return [
        'aus' => ['label' => 'Aus'],
        'smart' => ['label' => 'Solar'],
        'smart_dauerhaft' => ['label' => 'Min+Solar', 'short' => 'Min+', 'shortIcon' => 'sun'],
        'schnell' => ['label' => 'Schnell'],
    ];
}

function ui_badge(string $text): string
{
    return '<span class="badge">' . e($text) . '</span>';
}

function ui_pill(string $text, ?string $icon = 'sun', bool $neutral = false): string
{
    return '<span class="pill' . ($neutral ? ' pill-neutral' : '') . '">' . ($icon ? icon($icon, 'icon-16') : '') . e($text) . '</span>';
}

/** Tooltip an einem Info-Knopf. */
function ui_tip(string $text, string $label = 'Erläuterung'): string
{
    static $n = 0;
    $id = 'tip-' . (++$n);
    return '<span class="tip"><button type="button" class="icon-btn" data-tip aria-label="' . e($label) . '" aria-describedby="' . $id . '" aria-expanded="false">'
        . icon('info', 'icon-16') . '</button><span class="tip-body" role="tooltip" id="' . $id . '">' . e($text) . '</span></span>';
}

/** 7.6 Dialog: mobil Bottom Sheet, ab 640 px zentriert. Der Inhalt ist fertiges HTML. */
function ui_dialog(string $id, string $title, string $body, array $opts = []): string
{
    $titleId = $id . '-title';
    return '<dialog id="' . e($id) . '" class="dialog' . (!empty($opts['wide']) ? ' dialog-wide' : '') . '" aria-labelledby="' . e($titleId) . '"'
        . ui_attrs(['data-autoopen' => !empty($opts['autoopen']), 'data-return' => $opts['return'] ?? null]) . '>'
        . '<div class="dialog-grip" data-grip aria-hidden="true"></div>'
        . '<div class="dialog-head" data-grip><h2 class="card-title" id="' . e($titleId) . '">' . e($title) . '</h2>' . ($opts['head'] ?? '')
        . '<button type="button" class="icon-btn" data-close-dialog aria-label="Schließen">' . icon('x', 'icon-20') . '</button></div>'
        . '<div class="dialog-body">' . $body . '</div>'
        . (isset($opts['foot']) ? '<div class="dialog-foot">' . $opts['foot'] . '</div>' : '')
        . '</dialog>';
}

/** 7.7 Formularzeile: Label links, Steuerelement rechts (oder gestapelt). */
function ui_form_row(string $label, string $control, array $opts = []): string
{
    $hint = isset($opts['hint']) && $opts['hint'] !== '' ? '<span class="form-hint">' . e($opts['hint']) . '</span>' : '';
    $labelHtml = isset($opts['for'])
        ? '<label for="' . e($opts['for']) . '">' . e($label) . $hint . '</label>'
        : '<span class="form-label">' . e($label) . $hint . '</span>';
    return '<div class="form-row' . (!empty($opts['stack']) ? ' form-row-stack' : '') . '">' . $labelHtml . '<div class="control">' . $control . '</div></div>';
}

function ui_select(string $name, array $options, string $current, array $attrs = []): string
{
    $html = '<span class="select"><select' . ui_attrs(['name' => $name, 'id' => $attrs['id'] ?? ('f-' . $name)] + $attrs) . '>';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>' . icon('chevron-down', 'icon-16') . '</span>';
}

function ui_input(string $name, string $value, array $attrs = []): string
{
    $class = 'field' . (isset($attrs['class']) ? ' ' . $attrs['class'] : '');
    unset($attrs['class']);
    return '<input class="' . e($class) . '"' . ui_attrs(['name' => $name, 'id' => $attrs['id'] ?? ('f-' . $name), 'value' => $value] + $attrs) . '>';
}

/** Toggle 52 × 32 mit Häkchen. Das versteckte Feld schickt 0, wenn er aus ist. */
function ui_toggle(string $name, bool $checked, string $label, array $attrs = []): string
{
    return '<input type="hidden" name="' . e($name) . '" value="0"><span class="toggle-wrap"><span class="toggle"><input type="checkbox" role="switch"'
        . ui_attrs(['name' => $name, 'id' => $attrs['id'] ?? ('f-' . $name), 'value' => '1', 'checked' => $checked, 'aria-label' => $label] + $attrs)
        . '><span class="toggle-track"></span><span class="toggle-knob">' . icon('check', 'icon-16') . '</span></span></span>';
}

/** Regler mit Wertausgabe. */
function ui_range(string $name, string $label, float $value, float $min, float $max, float $step, string $unit, string $hint = ''): string
{
    $id = 'f-' . $name;
    $decimals = $step < 1 ? 1 : 0;
    return '<div class="range"><div class="range-head"><label for="' . e($id) . '">' . e($label) . '</label>'
        . '<span class="row">' . ($hint !== '' ? ui_tip($hint, $label . ' erklärt') : '')
        . '<output class="range-out" for="' . e($id) . '" data-unit="' . e($unit) . '" data-decimals="' . $decimals . '">' . e(num($value, $decimals)) . NNBSP . e($unit) . '</output></span></div>'
        . '<input type="range"' . ui_attrs(['id' => $id, 'name' => $name, 'min' => $min, 'max' => $max, 'step' => $step, 'value' => $value]) . '></div>';
}

/** Entität mit Suche in Home Assistant. */
function ui_entity_field(string $name, string $label, string $value, string $hint, string $suggest = ''): string
{
    $id = 'f-' . $name;
    $html = '<div class="entity stack-tight" data-entity><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
        . '<input class="field"' . ui_attrs([
            'id' => $id,
            'name' => $name,
            'value' => $value,
            'data-entity-search' => true,
            'autocomplete' => 'off',
            'spellcheck' => 'false',
            'role' => 'combobox',
            'aria-autocomplete' => 'list',
            'aria-expanded' => 'false',
            'aria-controls' => $id . '-list',
            'placeholder' => 'Name oder Entität suchen',
        ]) . '>'
        . '<ul class="entity-results" id="' . e($id) . '-list" role="listbox" hidden></ul>'
        . '<span class="form-hint">' . e($hint) . '</span>';
    if ($suggest !== '' && $suggest !== $value) {
        $html .= '<button type="button" class="text-action" data-fill="' . e($id) . '" data-value="' . e($suggest) . '">' . icon('plus', 'icon-16') . '<span>Vorschlag: ' . e($suggest) . '</span></button>';
    }
    return $html . '</div>';
}

function ui_divider(string $text): string
{
    return '<p class="divider-label label">' . e($text) . '</p>';
}

/** 7.12 Keine Daten: Icon, ein Satz, eine Aktion. */
function ui_empty(string $icon, string $text, string $action = ''): string
{
    return '<div class="empty">' . icon($icon, 'icon-40') . '<p>' . e($text) . '</p>' . $action . '</div>';
}

function ui_notice(string $icon, string $html, string $tone = ''): string
{
    return '<div class="notice' . ($tone !== '' ? ' notice-' . e($tone) : '') . '">' . icon($icon, 'icon-20') . '<div class="body-sm">' . $html . '</div></div>';
}

/**
 * Diagramm-Halter. JS holt die Daten von $src und zeichnet SVG. $label ist die Zusammenfassung für Screenreader.
 * opts: class, tools (HTML), style (Serien-Overrides), window, empty (Text ohne Daten)
 */
function ui_chart(string $type, string $src, string $label, array $opts = []): string
{
    return '<figure class="chart' . (isset($opts['class']) ? ' ' . e($opts['class']) : '') . '"' . ui_attrs([
        'data-chart' => $type,
        'data-src' => url($src),
        'data-label' => $label,
        'data-style' => isset($opts['style']) ? ui_json($opts['style']) : null,
        'data-window' => $opts['window'] ?? null,
        'data-empty' => $opts['empty'] ?? null,
        'id' => $opts['id'] ?? null,
    ]) . '>' . ($opts['tools'] ?? '') . '<div class="chart-frame" data-loading></div><figcaption class="sr-only">' . e($label) . '</figcaption></figure>';
}

/**
 * 9.6 Ring aus SVG-Kreisbögen, 24 px stark, 3° Lücke, runde Enden. Serverseitig gerechnet.
 * @param list<array{label: string, value: float, class: string, text: string}> $parts
 */
function ui_ring(array $parts, string $total, string $sub, string $label): string
{
    $sum = array_sum(array_map(static fn (array $p): float => max(0.0, $p['value']), $parts));
    $r = 64.0;
    $c = 2 * M_PI * $r;
    $svg = '<svg viewBox="0 0 160 160" role="img" aria-label="' . e($label) . '"><circle class="ring-track" cx="80" cy="80" r="' . $r . '"></circle>';
    if ($sum > 0) {
        $offset = 0.0;
        $gap = $c * 3 / 360 + 24;
        $visible = array_values(array_filter($parts, static fn (array $p): bool => $p['value'] > 0));
        foreach ($visible as $part) {
            $length = $part['value'] / $sum * $c;
            $dash = count($visible) > 1 ? max(0.1, $length - $gap) : $c;
            $svg .= '<circle class="ring-seg ' . e($part['class']) . '" cx="80" cy="80" r="' . $r . '" transform="rotate(-90 80 80)"'
                . ' stroke-dasharray="' . round($dash, 2) . ' ' . round($c, 2) . '" stroke-dashoffset="' . round(-$offset - (count($visible) > 1 ? $gap / 2 : 0), 2) . '"'
                . (count($visible) > 1 ? '' : ' stroke-linecap="butt"') . '></circle>';
            $offset += $length;
        }
    }
    $svg .= '<text class="ring-total" x="80" y="80" text-anchor="middle">' . e($total) . '</text><text class="ring-sub" x="80" y="100" text-anchor="middle">' . e($sub) . '</text></svg>';
    $legend = '<ul class="chart-legend chart-legend-sums">';
    foreach ($parts as $part) {
        $share = $sum > 0 ? $part['value'] / $sum * 100 : 0;
        $legend .= '<li class="' . e($part['class']) . '"><span class="swatch swatch-dot"></span><span>' . e($part['label']) . '</span><span class="sum">' . e($part['text']) . ' · ' . e(pct($share, 0)) . '</span></li>';
    }
    return '<div class="ring">' . $svg . $legend . '</ul></div>';
}

/**
 * 7.1 Energiefluss-Balken mit Klammern, Seitenmarken, Legende und Detail-Liste.
 * @param array $flow Ergebnis von Energy::flowBar()
 * @param array{in: list<array>, out: list<array>, in_kw: ?float, out_kw: ?float} $rows
 */
function ui_flow(array $flow, array $rows, string $id = 'flow'): string
{
    $labels = ['grid_in' => 'Netzbezug', 'battery' => 'Speicher', 'rest' => 'Nicht erfasst', 'solar' => 'Eigenverbrauch', 'grid_out' => 'Einspeisung'];
    $classes = ['grid_in' => 'flow-seg-grid-in', 'battery' => 'flow-seg-battery', 'rest' => 'flow-seg-rest', 'solar' => 'flow-seg-solar', 'grid_out' => 'flow-seg-grid-out'];
    $segments = $flow['segments'] ?: array_map(static fn (string $key): array => ['key' => $key, 'kw' => 0.0], array_keys($labels));
    $bar = '';
    foreach ($segments as $segment) {
        $bar .= '<span class="flow-seg ' . $classes[$segment['key']] . '" data-seg="' . e($segment['key']) . '" data-kw="' . e((string) $segment['kw']) . '"'
            . ($segment['kw'] <= 0 ? ' data-zero' : '') . '><span class="flow-val">' . e(kw((float) $segment['kw'])) . '</span></span>';
    }
    $top = ['grid' => 'utility-pole', 'battery' => 'battery', 'pv' => 'sun'];
    $bottom = ['house' => 'house', 'wallbox' => 'car', 'battery' => 'battery', 'grid' => 'utility-pole'];
    $brackets = static function (array $keys, string $where): string {
        $html = '<div class="flow-brackets flow-brackets-' . $where . '" aria-hidden="true">';
        foreach ($keys as $key => $glyph) {
            $html .= '<span class="bracket" data-bracket="' . e($key) . '" data-gone>' . icon($glyph, 'icon-16') . '</span>';
        }
        return $html . '</div>';
    };
    $column = static function (string $title, string $side, ?float $sum, array $items): string {
        $html = '<div data-flow-side="' . $side . '"><div class="flow-col-head"><h3 class="label">' . e($title) . '</h3><span class="metric-sm" data-flow-sum>' . e(kw($sum)) . '</span></div><ul class="plain-list" role="list">';
        foreach ($items as $item) {
            $idle = ($item['kw'] ?? 0) < 0.01;
            $html .= '<li class="flow-row"' . ($idle ? ' data-idle' : '') . ' data-flow-row="' . e($item['key']) . '">' . icon($item['icon'], 'icon-20 tone-' . $item['tone'])
                . '<span class="flow-row-main"><span class="flow-row-name">' . e($item['label']) . '</span>' . (isset($item['context']) ? '<span class="flow-row-context">' . $item['context'] . '</span>' : '') . '</span>'
                . '<span class="flow-row-kw metric-sm">' . e(kw($item['kw'])) . '</span></li>';
        }
        return $html . '</ul></div>';
    };
    return '<figure class="flow" id="' . e($id) . '" data-flow="' . e(ui_json($flow)) . '">'
        . '<div class="flow-track">' . $brackets($top, 'top')
        . '<div class="flow-bar" role="img" aria-label="Energiefluss" data-flow-bar>' . $bar . '</div>'
        . $brackets($bottom, 'bottom') . '</div>'
        . '<div class="flow-sides" aria-hidden="true"><span>Rein</span><span>Raus</span></div>'
        . '<figcaption class="flow-legend caption"><span class="legend-item"><span class="swatch swatch-solar"></span>Eigenverbrauch</span><span class="legend-item">Einspeisung<span class="swatch swatch-grid-out"></span></span></figcaption>'
        . '<button type="button" class="flow-toggle" aria-expanded="false" aria-controls="' . e($id) . '-details" data-flow-toggle>Rein und Raus im Detail' . icon('chevron-down', 'icon-16') . '</button>'
        . '<div class="flow-columns" id="' . e($id) . '-details" data-collapsed>' . $column('Rein', 'in', $rows['in_kw'] ?? null, $rows['in']) . $column('Raus', 'out', $rows['out_kw'] ?? null, $rows['out']) . '</div>'
        . '</figure>';
}

/** 7.5 Heimspeicher-Säule mit drei Zonen. */
function ui_battery_column(array $b, bool $editable = true): string
{
    $soc = $b['soc'];
    $mark = static function (string $key, float $value) use ($editable): string {
        if (!$editable) {
            return '<span class="bc-mark" data-mark="' . e($key) . '">' . e(pct($value)) . '</span>';
        }
        return '<button type="button" class="inline-action bc-mark" data-mark="' . e($key) . '" data-open-dialog="battery-limits" role="slider"'
            . ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $value . '" aria-valuetext="' . e(pct($value)) . '"'
            . ' aria-label="' . e($key === 'priority' ? 'Grenze Haus' : 'Grenze Auto') . '">' . e(pct($value)) . '</button>';
    };
    return '<div class="battery-col" data-battery-col' . ui_attrs([
        'data-soc' => $soc === null ? null : round((float) $soc, 1),
        'data-s1' => $b['priority'],
        'data-s2' => $b['buffer'],
        'data-auto' => $b['auto'],
        'data-activity' => $b['flow'] ?? 'ruhe',
    ]) . '>'
        . '<p class="bc-caption body">Speicherstand <strong data-live="soc_text">' . e(pct($soc)) . '</strong> · <span data-live="stored_text">' . e($b['stored_text']) . '</span></p>'
        . '<div class="bc-figure"><div class="bc-scale">' . $mark('priority', (float) $b['priority']) . $mark('buffer', (float) $b['buffer']) . '</div>'
        . '<div class="bc-body" role="img" aria-label="' . e('Hausspeicher ' . pct($soc) . '. Haus bis ' . pct((float) $b['priority']) . ', Auto bis ' . pct((float) $b['buffer']) . ', darüber batteriegestützt.') . '" data-bc-body>'
        . '<span class="bc-nub"></span><span class="bc-clip">'
        . '<span class="bc-zone bc-zone-house" data-zone="house">' . icon('house', 'icon-24') . '</span>'
        . '<span class="bc-zone bc-zone-car" data-zone="car">' . icon('car', 'icon-24') . '</span>'
        . '<span class="bc-zone bc-zone-boost" data-zone="boost">' . icon('zap', 'icon-24') . '</span>'
        . '<span class="bc-empty"></span><span class="bc-auto"' . ((float) $b['auto'] >= 99.5 ? ' data-off' : '') . '></span></span>'
        . '<span class="bc-level"></span></div>'
        . '<div class="bc-side"><span class="bc-soc" aria-hidden="true" data-live="soc_text">' . e(pct($soc)) . '</span></div></div>'
        . '</div>';
}

/** Zahl mit Einheit als ein Element. */
function ui_num(?float $value, string $unit, int $decimals = 1): string
{
    return '<span class="num-unit">' . metric($value, $unit, $decimals) . '</span>';
}

/** Live-Zahl mit Einheit: der Server rendert den Startwert, live.js schreibt neue Werte gleich formatiert. */
function ui_live_num(string $path, ?float $value, string $unit, int $decimals = 1): string
{
    return '<span class="num-unit" data-live-num="' . e($path) . '" data-unit="' . e($unit) . '" data-decimals="' . $decimals . '">' . metric($value, $unit, $decimals) . '</span>';
}

/**
 * 7.3 Ladepunkt-Karte mit Modus, Kennzahlen, Fahrzeug und 7.4 Ladebalken.
 * $cp kommt aus Snapshot::chargepointView(), im Demo- und Komponentenmodus auch aus Beispielwerten.
 */
function ui_chargepoint(array $cp, array $opts = []): string
{
    $v = $cp['vehicle'];
    $id = (string) ($opts['id'] ?? 'cp');
    $live = !empty($opts['live']);
    $editable = $opts['editable'] ?? true;
    $p = static fn (string $path): ?string => $live ? $path : null;
    $mode = ui_segment($id === 'cp' ? 'mode' : $id . '-mode', 'Lademodus', ui_mode_options(), (string) $cp['mode'], [
        'id' => $id . '-mode',
        'attrs' => ['data-mode-switch' => $live ? '1' : null, 'disabled' => !$editable],
    ]);
    $power = '<span class="cp-power">' . icon('zap', 'icon-16') . ($live ? ui_live_num('chargepoint.power_kw', $cp['power_kw'], 'kW') : ui_num($cp['power_kw'], 'kW')) . '</span>';
    $phases = '<span class="phases" data-active="' . (int) $cp['phases'] . '"' . ui_attrs(['data-live-phases' => $p('chargepoint.phases')]) . ' role="img" aria-label="' . e(((int) $cp['phases']) > 0 ? $cp['phases'] . '-phasig' : 'keine Phase aktiv') . '"><span></span><span></span><span></span></span>';
    $remaining = $cp['remaining_s'] !== null ? duration_clock((int) $cp['remaining_s']) : '—';
    $limit = $v['limit'];
    $socText = $live ? ui_live_num('vehicle.soc', $v['soc'], '%', 0) : ui_num($v['soc'], '%', 0);
    $range = '<span class="sub">' . ($live ? ui_live_num('vehicle.range_km', $v['range_km'], 'km', 0) : ui_num($v['range_km'], 'km', 0)) . '</span>';
    $rangeLimit = $v['range_at_limit'] !== null ? '<span class="sub">' . e(num((float) $v['range_at_limit'], 0)) . NNBSP . 'km</span>' : '';
    $limitHtml = $limit !== null
        ? ($editable ? ui_inline('<span data-limit-text>' . e(pct((float) $limit)) . '</span>', ['data-open-dialog' => $id . '-vehicle', 'aria-label' => 'Limit ' . pct((float) $limit) . ' ändern'], 'align-end') : ui_num((float) $limit, '%', 0))
        : '<span class="num">—</span>';
    $name = $editable
        ? ui_inline(e((string) $v['name']), ['data-open-dialog' => $id . '-vehicle', 'aria-label' => 'Fahrzeug ' . $v['name'] . ': Einstellungen'], 'align-start')
        : '<span class="metric-sm">' . e((string) $v['name']) . '</span>';
    $track = '<div class="chargebar-track" data-chargebar-track><span class="chargebar-clip"><span class="chargebar-fill"></span><span class="chargebar-target"></span></span>'
        . ($limit !== null && $editable
            ? '<span class="limit-mark" role="slider" tabindex="0" aria-label="Ladelimit" aria-valuemin="20" aria-valuemax="100" aria-valuenow="' . (int) $limit . '" aria-valuetext="' . e(pct((float) $limit)) . '" data-limit-mark></span>'
            : '')
        . '</div>';
    return '<article class="cp card" id="' . e($id) . '"' . ui_attrs([
        'data-chargepoint' => $live ? '1' : null,
        'data-charging' => $cp['charging'] ? 'true' : 'false',
        'data-solar-only' => !empty($cp['solar_only']) ? 'true' : 'false',
    ]) . '>'
        . '<header class="cp-head"><h2 class="card-title">' . e((string) $cp['name']) . '</h2><div class="cp-mode">' . $mode . '</div>'
        . ($editable ? ui_icon_button('sliders-horizontal', 'Einstellungen für ' . $cp['name'], ['data-open-dialog' => $id . '-settings', 'aria-haspopup' => 'dialog']) : '')
        . '</header>'
        . '<div class="metrics-3">'
        . ui_metric('Leistung', $power, ['after' => $phases])
        . ui_metric('Geladen', $live ? ui_live_num('chargepoint.session_kwh', $cp['session_kwh'], 'kWh') : ui_num($cp['session_kwh'], 'kWh'))
        . ui_metric('Restzeit', '<span class="num"' . ui_attrs(['data-live' => $p('chargepoint.remaining_text')]) . '>' . e($remaining) . '</span>')
        . '</div>'
        . '<p class="cp-suggest body-sm"' . ui_attrs(['data-live' => $p('chargepoint.suggestion')]) . '>' . e((string) $cp['suggestion']) . '</p>'
        . '<hr class="rule">'
        . '<div class="cp-vehicle">' . icon('car', 'icon-24') . $name . '<span class="cp-status body-sm"' . ui_attrs(['data-live' => $p('vehicle.status')]) . '>' . e((string) $v['status']) . '</span></div>'
        . '<div class="chargebar"' . ui_attrs([
            'data-chargebar' => true,
            'data-soc' => $v['soc'] === null ? null : round((float) $v['soc'], 1),
            'data-limit' => $limit,
            'data-charging' => $cp['charging'] ? 'true' : 'false',
            'data-unknown' => $v['soc'] === null,
        ]) . '><span class="chargebar-chip" aria-hidden="true">' . icon('battery-charging', 'icon-16') . '</span>' . $track . '</div>'
        . '<div class="metrics-2 cp-values">'
        . ui_metric('Ladung', '<span class="row wrap">' . $socText . $range . '</span>')
        . ui_metric('Limit', '<span class="row wrap">' . $limitHtml . $rangeLimit . '</span>')
        . '</div>'
        . '</article>';
}
