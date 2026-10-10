<?php
declare(strict_types=1);

/*
 * Bausteine der Oberfläche nach Abschnitt 7 der Vorgabe. Jede Funktion gibt HTML zurück, alle Texte
 * laufen durch e(). Proportionen (Breiten, Füllstände) stehen als data-Attribute und werden per CSSOM
 * gesetzt, nie als style-Attribut, damit die CSP default-src 'self' hält.
 */

/** MathML-Formel in einer eigenen, per Tastatur scrollbaren Region. */
function formula(string $mathml): void
{
    echo '<div class="formula-scroll" tabindex="0" role="region" aria-label="Formel"><math xmlns="http://www.w3.org/1998/Math/MathML">' . $mathml . '</math></div>';
}

function mnum(?float $value, int $decimals = 2): string
{
    return '<mn>' . e(num($value, $decimals)) . '</mn>';
}

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

/** Mehrere Kopf-Chips; auf dem Handy in einer eigenen Zeile unter dem Titel. */
function ui_head_chips(string ...$chips): string
{
    return '<div class="head-chips">' . implode('', $chips) . '</div>';
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

/** Die vier Lademodi, intern unverändert (schnell ist Netzladen). Schmal stehen Kurzformen, der volle Name im aria-label. */
function ui_mode_options(): array
{
    return [
        'aus' => ['label' => 'Aus'],
        'smart' => ['label' => 'Nur Solar', 'short' => 'Nur', 'shortIcon' => 'sun'],
        'smart_dauerhaft' => ['label' => 'Min+Solar', 'short' => 'Min+', 'shortIcon' => 'sun'],
        'schnell' => ['label' => 'Netzladen', 'short' => 'Netz'],
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

/** Zahl für ein Eingabefeld: Komma, ohne Tausenderpunkt (post_float liest den Punkt als Komma), ohne Nullen am Ende. */
function ui_field_num(float $value, int $decimals = 2): string
{
    $text = number_format($value, $decimals, ',', '');
    return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
}

/** Formularzeile mit Zahlenfeld, die Einheit steht im Hinweis. */
function ui_number_row(string $name, string $label, float $value, int $decimals, string $hint, array $attrs = []): string
{
    return ui_form_row($label, ui_input($name, ui_field_num($value, $decimals), $attrs + ['class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => 'f-' . $name, 'hint' => $hint]);
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

/** Konfiguration als JSON: herunterladen, einfügen oder als Datei hochladen. */
function ui_config_exchange(string $action, string $back): string
{
    return '<div class="stack">'
        . '<p><a class="btn btn-secondary" href="' . e(url('/api/config.json')) . '" download>' . icon('arrow-down', 'icon-16') . 'JSON herunterladen</a></p>'
        . '<form method="post" enctype="multipart/form-data" action="' . e(url($action)) . '" class="stack">' . csrf_field()
        . '<input type="hidden" name="section" value="import"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<div class="form-rows">'
        . ui_form_row('JSON einfügen', '<textarea class="field" id="f-config-json" name="config_json" rows="5" spellcheck="false" placeholder="{ &quot;version&quot;: 1, &quot;mapping&quot;: { } }"></textarea>', ['for' => 'f-config-json', 'stack' => true])
        . ui_form_row('oder Datei', '<input class="field field-file" type="file" id="f-config-file" name="config_file" accept="application/json,.json">', ['for' => 'f-config-file', 'stack' => true])
        . '</div><div class="form-actions"><button class="btn btn-primary" type="submit">JSON importieren</button></div></form></div>';
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
        'data-anchor' => $opts['anchor'] ?? null,
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
 * 7.1 Energiefluss als Balken: Klammern oben für die Quellen, unten für die Verbraucher, jede Seite über die ganze
 * Breite. Steht auf Laden ohne Karte vor dem Hintergrund; die Tabelle Rein und Raus liefert ui_flow_table().
 */
function ui_flow_bar(array $flow, string $id = 'flow', bool $static = false): string
{
    $labels = ['grid_in' => 'Netzbezug', 'battery' => 'Speicher', 'solar' => 'Eigenverbrauch', 'grid_out' => 'Einspeisung'];
    $segmentKw = [];
    foreach ($flow['segments'] ?? [] as $segment) {
        $segmentKw[$segment['key']] = (float) $segment['kw'];
    }
    $bar = '';
    $legend = '';
    foreach ($labels as $key => $label) {
        $kw = $segmentKw[$key] ?? 0.0;
        $class = str_replace('_', '-', $key);
        $bar .= '<span class="flow-seg flow-seg-' . $class . '" data-seg="' . $key . '" data-kw="' . e((string) $kw) . '"' . ($kw <= 0 ? ' data-zero' : '')
            . '><span class="flow-val">' . e(kw($kw)) . '</span></span>';
        $legend .= '<span class="legend-item" data-legend="' . $key . '"' . ($kw <= 0 ? ' hidden' : '') . '><span class="swatch swatch-' . $class . '"></span>' . e($label) . '</span>';
    }
    $brackets = static function (array $keys, string $where, array $items) use ($flow): string {
        $html = '<div class="flow-brackets flow-brackets-' . $where . '" aria-hidden="true">';
        foreach ($keys as $key => [$glyph, $tone]) {
            $item = null;
            foreach ($items as $entry) {
                if ($entry['key'] === $key) {
                    $item = $entry;
                }
            }
            $html .= '<span class="bracket" data-bracket="' . e($key) . '"' . ($item ? '' : ' data-gone') . '><span class="bracket-label">'
                . icon($glyph, 'icon-20' . ($tone !== '' ? ' tone-' . $tone : ''))
                . '<span class="bracket-kw">' . e(kw((float) ($item['kw'] ?? 0))) . '</span>'
                . ($key === 'battery' ? '<span class="bracket-soc">' . e(pct($flow['soc'] ?? null)) . '</span>' : '')
                . '</span></span>';
        }
        return $html . '</div>';
    };
    $top = ['grid' => ['utility-pole', 'grid-in'], 'battery' => ['battery', 'battery'], 'pv' => ['sun', 'solar']];
    $bottom = ['house' => ['house', ''], 'wallbox' => ['car', ''], 'battery' => ['battery', 'battery'], 'grid' => ['utility-pole', 'grid-out']];
    return '<figure class="flow" id="' . e($id) . '" data-flow="' . e(ui_json($flow)) . '"' . ($static ? ' data-static' : '') . '>'
        . '<div class="flow-track">' . $brackets($top, 'top', $flow['sources'] ?? [])
        . '<div class="flow-bar" role="img" aria-label="Energiefluss" data-flow-bar>' . $bar . '</div>'
        . $brackets($bottom, 'bottom', $flow['sinks'] ?? []) . '</div>'
        . '<div class="flow-sides" aria-hidden="true"><span>Rein</span><span>Raus</span></div>'
        . '<figcaption class="flow-legend caption">' . $legend . '</figcaption>'
        . '</figure>';
}

/**
 * Tabelle Rein und Raus in einer Karte: mobil per Knopf aufklappbar, ab 640 px immer offen. Beide Seiten haben
 * dieselben Zeilenhöhen, Speicher neben Speicher und Netz neben Einspeisung. Eine Zeile mit 'value' (fertiges HTML)
 * statt 'kw' zeigt keine Leistung, 'muted' setzt sie dezenter ab.
 */
function ui_flow_table(array $rows, string $id = 'flow-table', bool $static = false): string
{
    $column = static function (string $title, string $side, ?float $sum, array $items): string {
        $html = '<div class="flow-side" data-flow-side="' . $side . '"><div class="flow-col-head"><h3 class="flow-col-title">' . e($title) . '</h3><span class="flow-col-sum" data-flow-sum>' . e(kw($sum)) . '</span></div><ul class="plain-list flow-list" role="list">';
        foreach ($items as $item) {
            $idle = !isset($item['value']) && ($item['kw'] ?? 0) < 0.01;
            $html .= '<li class="flow-row' . (!empty($item['muted']) ? ' flow-row-muted' : '') . '"' . ($idle ? ' data-idle' : '') . ' data-flow-row="' . e($item['key']) . '">' . icon($item['icon'], 'icon-20 tone-' . $item['tone'])
                . '<span class="flow-row-main"><span class="flow-row-name">' . e($item['label']) . '</span>' . (isset($item['context']) ? '<span class="flow-row-context">' . $item['context'] . '</span>' : '') . '</span>'
                . '<span class="flow-row-kw metric-sm">' . ($item['value'] ?? e(kw($item['kw']))) . '</span></li>';
        }
        return $html . '</ul></div>';
    };
    // Zugeklappt (mobil) stehen die Summen im Knopf.
    $sum = static fn (string $key): string => $static ? ui_num($rows[$key] ?? null, 'kW') : ui_live_num('flow_rows.' . $key, $rows[$key] ?? null, 'kW');
    return '<div class="flow-table" id="' . e($id) . '" data-flow-rows' . ($static ? ' data-static' : '') . '>'
        . '<button type="button" class="flow-toggle" aria-expanded="false" aria-controls="' . e($id) . '-details" data-flow-toggle><span class="flow-toggle-text">Rein ' . $sum('in_kw') . ' · Raus ' . $sum('out_kw') . '</span>' . icon('chevron-down', 'icon-16') . '<span class="sr-only">, im Detail</span></button>'
        . '<div class="flow-columns" id="' . e($id) . '-details" data-collapsed>' . $column('Rein', 'in', $rows['in_kw'] ?? null, $rows['in']) . $column('Raus', 'out', $rows['out_kw'] ?? null, $rows['out']) . '</div>'
        . '</div>';
}

/**
 * Energie-Flow Rein → Raus: links die Quellen Sonne, Speicher und Netz, rechts die Ziele Haus, Auto, Speicher und
 * Einspeisung, wie die Tabelle Rein und Raus. Die Linien zeichnet components/energy-flow.js zwischen den Kreisen,
 * je Quelle zu jedem Ziel, das sie gerade versorgt, breiter bei mehr Leistung; Punkte laufen in der Farbe der Quelle
 * von links nach rechts. Der Ring der Sonne zeigt, wie viel der Tagesprognose schon erzeugt ist, die Ringe von Haus
 * und Auto die Mischung aus Sonne, Speicher und Netz. Unter jedem Namen steht eine Zeile mit Kennzahlen und Icons:
 * Sonne gemessen und Prognose, Speicher Ladestand und bis voll, Netz und Einspeisung der Preis, Haus das Mittel der
 * letzten 30 Tage, Auto Ladestand und Reichweite. Daten aus Snapshot::flowGraph().
 * opts: id, static, car (Name des Autos), links (bool: Kreise führen zu Prognose, Speicher, Ladepunkt)
 */
function ui_energy_flow(array $graph, array $opts = []): string
{
    $id = (string) ($opts['id'] ?? 'energy-flow');
    $links = $opts['links'] ?? true;
    $n = $graph['nodes'];
    $mix = $graph['mix'];
    $car = (string) ($opts['car'] ?? 'Auto');
    $soc = static fn (?float $value): string => $value === null ? '' : pct($value);
    $arc = static fn (string $part, float $len, float $from, string $color): string => '<circle class="ef-arc c-' . $color . '" cx="50" cy="50" r="46" pathLength="100" stroke-dasharray="' . round($len, 2) . ' ' . round(100 - $len, 2) . '" stroke-dashoffset="' . round(-$from, 2) . '" data-arc="' . $part . '"></circle>';
    // Ring: Fortschritt der Prognose (Sonne), Mischung (Haus, Auto) oder ganz in der Farbe des Kreises.
    $ring = static function (string $kind) use ($n, $mix, $arc): string {
        $html = '<svg class="ef-ring" viewBox="0 0 100 100" aria-hidden="true"><circle class="ef-ring-base" cx="50" cy="50" r="46" pathLength="100"></circle>';
        if ($kind === 'progress') {
            $html .= $arc('progress', max(0.0, (float) ($n['sun']['progress'] ?? 1)) * 100, 0, 'solar');
        } elseif ($kind === 'mix') {
            $from = 0.0;
            foreach (['sun' => 'solar', 'battery' => 'battery', 'grid' => 'grid-in'] as $part => $color) {
                $len = max(0.0, (float) ($mix[$part] ?? 0)) * 100;
                $html .= $arc($part, $len, $from, $color);
                $from += $len;
            }
        }
        return $html . '</svg>';
    };
    $node = static function (string $key, string $label, string $facts, string $glyph, string $aria, ?string $href, string $kind) use ($n, $ring, $links): string {
        $tag = $links && $href !== null ? 'a' : 'div';
        $attrs = $tag === 'a' ? ' href="' . e($href) . '"' : ' role="img"';
        return '<div class="ef-node ef-' . str_replace('_', '-', $key) . '" data-ef-node="' . $key . '"' . ((float) $n[$key]['kw'] < 0.01 ? ' data-idle' : '') . '>'
            . '<' . $tag . ' class="ef-circle"' . $attrs . ' aria-label="' . e($aria) . '" data-ef-aria="' . $key . '">' . $ring($kind)
            . '<span class="ef-inner">' . icon($glyph, 'icon-20') . '<span class="ef-value" data-ef="' . $key . '">' . e(kw((float) $n[$key]['kw'])) . '</span></span></' . $tag . '>'
            . '<span class="ef-label"><span class="ef-name">' . e($label) . '</span><span class="ef-facts">' . $facts . '</span></span>'
            . '</div>';
    };
    // Zweite Zeile: Kennzahl mit Icon, ohne Wert ausgeblendet; energy-flow.js schreibt die Werte live.
    $fact = static fn (string $key, string $glyph, string $label, ?string $text): string => '<span class="ef-fact" data-ef-fact="' . $key . '" title="' . e($label) . '"' . ($text === null ? ' hidden' : '') . '>'
        . icon($glyph, 'icon-16') . '<span class="sr-only">' . e($label) . ': </span><span data-ef="' . $key . '">' . e((string) $text) . '</span></span>';
    $value = static fn (mixed $number, callable $format): ?string => is_numeric($number) ? $format((float) $number) : null;
    $battery = static fn (string $key): string => $fact($key . '_soc', 'battery-medium', 'Ladestand', $value($n[$key]['soc'] ?? null, $soc))
        . $fact($key . '_full', 'battery-plus', 'Bis voll', $value($n[$key]['to_full_kwh'] ?? null, static fn (float $x): string => kwh($x)));
    $sun = $n['sun'];
    $sunFacts = $fact('sun_done', 'gauge', 'Heute gemessen', $value($sun['done_kwh'] ?? null, static fn (float $x): string => kwh($x)))
        . $fact('sun_forecast', 'cloud-sun', 'Prognose heute', $value($sun['forecast_kwh'] ?? null, static fn (float $x): string => kwh($x)));
    $sunAria = 'Sonne ' . kw((float) $sun['kw']) . (is_numeric($sun['done_kwh'] ?? null) ? ', heute gemessen ' . kwh((float) $sun['done_kwh']) : '') . (is_numeric($sun['forecast_kwh'] ?? null) ? ', Prognose ' . kwh((float) $sun['forecast_kwh']) : '');
    $in = $node('sun', 'Sonne', $sunFacts, 'sun', $sunAria, url('/prognose'), 'progress')
        . $node('battery_out', 'Speicher', $battery('battery_out'), 'battery', 'Speicher entlädt ' . kw((float) $n['battery_out']['kw']), url('/speicher'), 'full')
        . $node('grid_in', 'Netz', $fact('grid_in_price', 'coins', 'Preis Netzbezug', $value($n['grid_in']['price_ct'] ?? null, 'ct')), 'utility-pole', 'Netzbezug ' . kw((float) $n['grid_in']['kw']), null, 'full');
    $out = $node('home', 'Haus', $fact('home_mean', 'history', 'Mittel der letzten 30 Tage', $value($n['home']['mean_kw'] ?? null, static fn (float $x): string => 'Ø ' . kw($x))), 'house', 'Haus ' . kw((float) $n['home']['kw']), null, 'mix')
        . $node('car', $car, $fact('car_soc', 'battery-medium', 'Ladestand', $value($n['car']['soc'] ?? null, $soc)) . $fact('car_range', 'route', 'Reichweite', $value($n['car']['range_km'] ?? null, static fn (float $x): string => with_unit($x, 0, 'km'))), 'car', $car . ' lädt mit ' . kw((float) $n['car']['kw']), '#cp', 'mix')
        . $node('battery_in', 'Speicher', $battery('battery_in'), 'battery', 'Speicher lädt ' . kw((float) $n['battery_in']['kw']), url('/speicher'), 'full')
        . $node('grid_out', 'Einspeisung', $fact('grid_out_price', 'hand-coins', 'Vergütung Einspeisung', $value($n['grid_out']['price_ct'] ?? null, 'ct')), 'utility-pole', 'Einspeisung ' . kw((float) $n['grid_out']['kw']), null, 'full');
    return '<figure class="ef" id="' . e($id) . '" data-energy-flow="' . e(ui_json($graph)) . '"' . (!empty($opts['static']) ? ' data-static' : '') . ' aria-labelledby="' . e($id) . '-sum">'
        . '<figcaption class="sr-only" id="' . e($id) . '-sum" data-ef-summary>Energie-Flow</figcaption>'
        . '<svg class="ef-links" aria-hidden="true"></svg>'
        . '<div class="ef-col ef-col-in"><p class="ef-head">Rein</p><div class="ef-stack">' . $in . '</div></div>'
        . '<div class="ef-col ef-col-out"><p class="ef-head">Raus</p><div class="ef-stack">' . $out . '</div></div>'
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
        . '<p class="bc-caption body">Speicherstand <strong data-live="battery.soc_text">' . e(pct($soc)) . '</strong> · <span data-live="battery.stored_text">' . e($b['stored_text']) . '</span></p>'
        . '<div class="bc-figure"><div class="bc-scale">' . $mark('priority', (float) $b['priority']) . $mark('buffer', (float) $b['buffer']) . '</div>'
        . '<div class="bc-body" role="img" aria-label="' . e('Hausspeicher ' . pct($soc) . '. Haus bis ' . pct((float) $b['priority']) . ', Auto bis ' . pct((float) $b['buffer']) . ', darüber batteriegestützt.') . '" data-bc-body>'
        . '<span class="bc-nub"></span><span class="bc-clip">'
        . '<span class="bc-zone bc-zone-house" data-zone="house">' . icon('house', 'icon-24') . '</span>'
        . '<span class="bc-zone bc-zone-car" data-zone="car">' . icon('car', 'icon-24') . '</span>'
        . '<span class="bc-zone bc-zone-boost" data-zone="boost">' . icon('zap', 'icon-24') . '</span>'
        . '<span class="bc-empty"></span><span class="bc-auto"' . ((float) $b['auto'] >= 99.5 ? ' data-off' : '') . '></span></span>'
        . '<span class="bc-level"></span></div>'
        . '<div class="bc-side"><span class="bc-soc" aria-hidden="true" data-live="battery.soc_text">' . e(pct($soc)) . '</span></div></div>'
        . '</div>';
}

/** Zahl mit Einheit als ein Element. */
function ui_num(?float $value, string $unit, int $decimals = 1): string
{
    return '<span class="num-unit">' . metric($value, $unit, $decimals) . '</span>';
}

/** Modell-Unsicherheit unter einem Prognosewert: „± 1,5 kWh“, leer ohne Streuung. */
function ui_spread(?float $sd): string
{
    return $sd === null ? '' : '<span class="caption muted">± ' . e(num($sd, 1)) . NNBSP . 'kWh</span>';
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
    // Meldet das Auto sein Ladelimit (Tesla BLE), lässt es sich hier nicht ziehen.
    $fromCar = !empty($v['limit_from_car']);
    $socText = $live ? ui_live_num('vehicle.soc', $v['soc'], '%', 0) : ui_num($v['soc'], '%', 0);
    $range = '<span class="sub">' . ($live ? ui_live_num('vehicle.range_km', $v['range_km'], 'km', 0) : ui_num($v['range_km'], 'km', 0)) . '</span>';
    $rangeLimit = $v['range_at_limit'] !== null ? '<span class="sub">' . e(num((float) $v['range_at_limit'], 0)) . NNBSP . 'km</span>' : '';
    $limitHtml = match (true) {
        $limit === null => '<span class="num">—</span>',
        $fromCar => ($live ? ui_live_num('vehicle.limit', (float) $limit, '%', 0) : ui_num((float) $limit, '%', 0)) . '<span class="sub">vom Auto</span>',
        $editable => ui_inline('<span data-limit-text>' . e(pct((float) $limit)) . '</span>', ['data-open-dialog' => $id . '-vehicle', 'aria-label' => 'Limit ' . pct((float) $limit) . ' ändern'], 'align-end'),
        default => ui_num((float) $limit, '%', 0),
    };
    $odometer = !empty($v['has_odometer'])
        ? ui_metric('Km-Stand', $live ? ui_live_num('vehicle.odometer_km', $v['odometer_km'], 'km', 0) : ui_num($v['odometer_km'], 'km', 0))
        : '';
    $name = $editable
        ? ui_inline(e((string) $v['name']), ['data-open-dialog' => $id . '-vehicle', 'aria-label' => 'Fahrzeug ' . $v['name'] . ': Einstellungen'], 'align-start')
        : '<span class="metric-sm">' . e((string) $v['name']) . '</span>';
    // Ladeziel: Zeile mit Fortschritt oder „Ladeziel setzen“; live.js schreibt die Texte, chargepoint.js Breite und Sichtbarkeit.
    $target = $cp['target'] ?? null;
    $t = $target ?? ['label' => '', 'text' => '', 'then_text' => '', 'progress' => 0];
    $targetHtml = !$editable ? '' : '<div class="cp-target" data-target data-progress="' . e((string) (float) $t['progress']) . '"' . ($target === null ? ' hidden' : '') . '>'
        . '<span class="cp-target-icon" aria-hidden="true">' . icon('target', 'icon-16') . '</span>'
        . '<div class="cp-target-body"><p class="cp-target-head"><span class="cp-target-label"' . ui_attrs(['data-live' => $p('target.label')]) . '>' . e((string) $t['label']) . '</span>'
        . '<span class="caption muted"' . ui_attrs(['data-live' => $p('target.then_text')]) . '>' . e((string) $t['then_text']) . '</span></p>'
        . '<span class="cp-target-bar" aria-hidden="true"><span class="cp-target-fill"></span></span>'
        . '<p class="caption cp-target-text"' . ui_attrs(['data-live' => $p('target.text')]) . '>' . e((string) $t['text']) . '</p></div>'
        . ui_inline('Ändern', ['data-open-dialog' => $id . '-target', 'aria-label' => 'Ladeziel ändern'])
        . '</div>'
        . '<button type="button" class="text-action cp-target-set" data-open-dialog="' . e($id) . '-target" data-target-none' . ($target !== null ? ' hidden' : '') . '>' . icon('target', 'icon-16') . '<span>Ladeziel setzen</span></button>';
    // Übersicht des laufenden Ladevorgangs.
    $session = $cp['session_view'] ?? ['open' => false];
    $item = static fn (string $label, string $key) => '<div><dt>' . e($label) . '</dt><dd' . ui_attrs(['data-live' => $p('session_view.' . $key)]) . '>' . e((string) ($session[$key] ?? '—')) . '</dd></div>';
    $sessionHtml = '<div class="cp-session" data-session' . (empty($session['open']) ? ' hidden' : '') . '>'
        . '<p class="label">Dieser Ladevorgang <span' . ui_attrs(['data-live' => $p('session_view.since')]) . '>' . e((string) ($session['since'] ?? '')) . '</span></p>'
        . '<dl class="cp-session-list">' . $item('Dauer', 'duration') . $item('Strecke', 'km') . $item('Ø Leistung', 'avg') . $item('Sonne', 'solar') . $item('Kosten', 'cost') . '</dl></div>';
    $track = '<div class="chargebar-track" data-chargebar-track><span class="chargebar-clip"><span class="chargebar-fill"></span><span class="chargebar-target"></span></span>'
        . ($limit !== null && $editable && !$fromCar
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
        . $targetHtml
        . $sessionHtml
        . '<hr class="rule">'
        . '<div class="cp-vehicle">' . icon('car', 'icon-24') . $name . '<span class="cp-status body-sm"' . ui_attrs(['data-live' => $p('vehicle.status')]) . '>' . e((string) $v['status']) . '</span></div>'
        . '<div class="chargebar"' . ui_attrs([
            'data-chargebar' => true,
            'data-soc' => $v['soc'] === null ? null : round((float) $v['soc'], 1),
            'data-limit' => $limit,
            'data-charging' => $cp['charging'] ? 'true' : 'false',
            'data-unknown' => $v['soc'] === null,
        ]) . '><span class="chargebar-chip" aria-hidden="true">' . icon('battery-charging', 'icon-16') . '</span>' . $track . '</div>'
        . '<div class="' . ($odometer !== '' ? 'metrics-3' : 'metrics-2') . ' cp-values">'
        . ui_metric('Ladung', '<span class="row wrap">' . $socText . $range . '</span>')
        . $odometer
        . ui_metric('Limit', '<span class="row wrap">' . $limitHtml . $rangeLimit . '</span>')
        . '</div>'
        . '</article>';
}

/**
 * Regelung unter der Ladepunkt-Karte: mit welcher Stufe die Wallbox jetzt laden würde, woher die Leistung
 * käme und wohin der Überschuss ginge und die Phasen-Leiter. Aufgeklappt: was die drei Modi täten und der
 * Rechenweg. Daten aus Snapshot::control(); Breiten und Positionen setzt components/control.js (CSSOM und
 * SVG-Attribute).
 * opts: id, live (Texte über data-live aktualisieren)
 */
function ui_control(array $c, array $charge, array $strategy, array $opts = []): string
{
    $id = (string) ($opts['id'] ?? 'ctl');
    $live = !empty($opts['live']);
    $l = static fn (string $path): ?string => $live ? 'control.' . $path : null;
    $text = static fn (string $path, string $value, string $tag = 'span', string $class = ''): string => '<' . $tag . ($class !== '' ? ' class="' . e($class) . '"' : '') . ui_attrs(['data-live' => $l($path)]) . '>' . e($value) . '</' . $tag . '>';
    $bar = static function (string $name, array $parts, array $flows, string $label): string {
        $html = '<div class="ctl-bar" data-ctl-bar="' . e($name) . '" role="img" aria-label="' . e($label) . '">';
        foreach ($parts as $part) {
            $html .= '<span class="ctl-seg ctl-seg-' . e($part) . '" data-part="' . e($part) . '"' . ((float) ($flows[$part . '_kw'] ?? 0) < 0.01 ? ' data-zero' : '') . '></span>';
        }
        return $html . '<span class="ctl-seg ctl-seg-rest" data-part="rest"></span></div>';
    };
    $f = $c['flows'];
    $carParts = ['sun', 'battery', 'grid', 'mixed'];
    // Je Block eine Legende; control.js zeigt nur Farben, die in diesem Block vorkommen.
    $legend = static function (string $name, array $keys, array $flowsList): string {
        $labels = ['sun' => 'Sonne', 'battery' => 'Speicher', 'grid' => 'Netz', 'export' => 'Einspeisung', 'mixed' => 'Speicher, dann Netz'];
        $parts = ['sun' => ['sun'], 'battery' => ['charge', 'battery'], 'grid' => ['grid'], 'export' => ['export'], 'mixed' => ['mixed']];
        $html = '<ul class="ctl-legend caption" role="list" data-legend="' . e($name) . '">';
        foreach ($keys as $key) {
            $seen = false;
            foreach ($flowsList as $flows) {
                foreach ($parts[$key] as $part) {
                    $seen = $seen || (float) ($flows[$part . '_kw'] ?? 0) >= 0.01;
                }
            }
            $html .= '<li class="ctl-key-' . e($key) . '"' . ($seen ? '' : ' hidden') . '><span class="swatch"></span>' . e($labels[$key]) . '</li>';
        }
        return $html . '</ul>';
    };

    // 1. Jetzt: Stufe und Leistung beim Einschalten
    $html = '<section class="card ctl" id="' . e($id) . '" aria-labelledby="' . e($id) . '-title" data-control="' . e(ui_json(['scale_kw' => $c['scale_kw'], 'flows' => $f, 'modes' => array_map(static fn (array $m): array => ['flows' => $m['flows'], 'active' => $m['active']], $c['modes']), 'ladder' => $c['ladder']])) . '"' . ($live ? ' data-control-live' : '') . '>'
        . '<div class="ctl-grid"><header class="ctl-head"><h2 class="card-title" id="' . e($id) . '-title">Regelung</h2><span class="ctl-pills">'
        . '<span class="pill' . (!empty($c['ems_active']) ? '' : ' pill-neutral') . '" data-ems-pill' . ui_attrs(['data-live' => $l('ems_label')]) . '>' . e((string) ($c['ems_label'] ?? 'nur Anzeige')) . '</span>'
        . $text('mode_label', (string) $c['mode_label'], 'span', 'pill pill-neutral') . '</span></header>'
        . '<div class="ctl-now">'
        . $text('headline', (string) $c['headline'], 'p', 'label')
        . '<p class="ctl-now-value">' . $text('kw_text', (string) $c['kw_text'], 'span', 'metric-xl')
        . '<span class="ctl-level metric-sm"' . ui_attrs(['data-live' => $l('level_text'), 'data-live-hide' => $l('level_text')]) . ($c['level_text'] === '' ? ' hidden' : '') . '>' . e((string) $c['level_text']) . '</span></p>'
        . $text('reason', (string) $c['reason'], 'p', 'body-sm muted')
        . '<p class="body-sm ctl-pending"' . ui_attrs(['data-live' => $l('pending'), 'data-live-hide' => $l('pending')]) . ($c['pending'] === '' ? ' hidden' : '') . '>' . e((string) $c['pending']) . '</p>'
        . '</div>';

    // 2. Aufteilung: der Überschuss über dem Haus und die Quellen des Autos auf derselben Skala
    $html .= '<div class="ctl-block ctl-block-split"><h3 class="ctl-title">Aufteilung</h3><div class="ctl-split">'
        . '<span class="ctl-split-name">Überschuss</span>'
        . $bar('spare', ['sun', 'charge', 'export'], $f, 'Überschuss über dem Haus: ans Auto, in den Speicher, ins Netz')
        . $text('details.spare', kw((float) $f['spare_kw']), 'span', 'ctl-split-kw')
        . '<span class="ctl-split-name">Auto</span>'
        . $bar('car', $carParts, $f, 'Leistung des Autos: aus der Sonne, aus dem Speicher, aus dem Netz')
        . $text('car_text', kw((float) $f['car_kw']), 'span', 'ctl-split-kw')
        . '</div>'
        . $text('split_text', (string) $c['split_text'], 'p', 'body-sm')
        . $legend('split', ['sun', 'battery', 'grid', 'export', 'mixed'], [$f])
        . '</div>';

    // 3. Phasen und Stufe: je Phase eine Zeile auf derselben kW-Achse, Striche je Ampere, Punkt für die Stufe,
    // gestrichelt die Sonne fürs Auto. Die Namen stehen links, damit die Linie keine Schrift kreuzt.
    $minA = min(16, max(6, (int) ($charge['min_a'] ?? 6)));
    $maxA = min(16, max($minA, (int) ($charge['max_a'] ?? 16)));
    $phaseMode = (string) ($charge['phase_mode'] ?? 'auto');
    $max = max(0.1, (float) $c['ladder']['max_kw']);
    $at = static fn (float $kw): string => round(min(100, max(0, $kw / $max * 100)), 2) . '%';
    $sun = (float) $c['ladder']['sun_kw'];
    $level = (float) $c['ladder']['level_kw'];
    $levelPhases = (int) $c['ladder']['level_phases'];
    $ladder = '<div class="ctl-ladder" data-ladder role="img" aria-label="' . e('Stufen von ' . $minA . ' bis ' . $maxA . NNBSP . 'A, jetzt ' . $c['level_text'] . '.') . '">';
    foreach ($phaseMode === '1p' ? [1] : ($phaseMode === '3p' ? [3] : [1, 3]) as $phases) {
        $from = Energy::KW_PER_AMP * $minA * $phases;
        $to = Energy::KW_PER_AMP * $maxA * $phases;
        $ladder .= '<span class="ladder-name"><span>' . e($phases . '-phasig') . '</span><span class="caption muted">' . e(num($from, 1) . '–' . kw($to)) . '</span></span>'
            . '<svg class="ladder-row" aria-hidden="true" data-lane="' . $phases . '">'
            . '<rect class="ladder-lane" x="' . $at($from) . '" y="12" width="' . round(($to - $from) / $max * 100, 2) . '%" height="8" rx="4"></rect>';
        for ($a = $minA; $a <= $maxA; $a++) {
            $x = $at(Energy::KW_PER_AMP * $a * $phases);
            $ladder .= '<line class="ladder-tick" x1="' . $x . '" x2="' . $x . '" y1="9" y2="23"></line>';
        }
        $ladder .= '<line class="ladder-sun" x1="' . $at($sun) . '" x2="' . $at($sun) . '" y1="0" y2="32" data-ladder-sun></line>'
            . '<circle class="ladder-level" r="7" cx="' . $at($level) . '" cy="16"' . ($levelPhases === $phases ? '' : ' visibility="hidden"') . ' data-ladder-level></circle></svg>';
    }
    $ladder .= '<span></span><svg class="ladder-row ladder-foot" aria-hidden="true"><text class="ladder-sun-label" x="' . $at($sun) . '" y="13" text-anchor="' . ($sun / $max > 0.5 ? 'end' : 'start') . '" data-ladder-sun-label>' . e('Sonne fürs Auto ' . kw($sun)) . '</text></svg></div>';
    $auto = $phaseMode === 'auto'
        ? 'Automatisch: unter ' . kw(Energy::PHASE_DOWN_KW) . ' einphasig, ab ' . kw(Energy::PHASE_UP_KW) . ' dreiphasig, dazwischen bleibt die Phase. Ein Wechsel wartet die Schütz-Schutzzeit von ' . (int) ($charge['switch_s'] ?? 60) . NNBSP . 's ab.'
        : 'Fest ' . ($phaseMode === '1p' ? 'einphasig' : 'dreiphasig') . ', einstellbar in den Ladeparametern.';
    $html .= '<div class="ctl-block ctl-block-ladder"><h3 class="ctl-title">Phasen und Stufe</h3>' . $ladder . '<p class="body-sm muted">' . e($auto) . '</p></div>';

    // 4. Aufgeklappt: was die drei Modi jetzt täten, der Rechenweg und die Modi im Wortlaut
    $html .= '<details class="ctl-details"><summary>' . icon('chevron-down', 'icon-16') . '<span>Lademodi und Rechenweg</span></summary><div class="ctl-details-body">'
        . '<div class="ctl-block ctl-block-modes"><h3 class="ctl-title">Die drei Modi jetzt</h3><ul class="ctl-modes" role="list">';
    foreach ($c['modes'] as $key => $m) {
        $html .= '<li class="ctl-mode" data-ctl-mode="' . e($key) . '"' . ($m['active'] ? ' data-active' : '') . '>'
            . '<div class="ctl-mode-head"><span class="ctl-mode-name">' . e((string) $m['label']) . '</span><span class="pill ctl-active-pill">aktiv</span>'
            . $text('modes.' . $key . '.kw_text', (string) $m['kw_text'], 'span', 'ctl-mode-kw metric-sm') . '</div>'
            . $bar('mode-' . $key, $carParts, $m['flows'], 'Leistung im Modus ' . $m['label'] . ': aus der Sonne, aus dem Speicher, aus dem Netz')
            . '<p class="caption muted ctl-mode-text">' . $text('modes.' . $key . '.level_text', (string) $m['level_text'], 'span', 'ctl-mode-level') . $text('modes.' . $key . '.text', (string) $m['text']) . '</p>'
            . '</li>';
    }
    $html .= '</ul>' . $legend('modes', $carParts, array_map(static fn (array $m): array => array_intersect_key($m['flows'], array_flip(['sun_kw', 'battery_kw', 'grid_kw', 'mixed_kw'])), $c['modes'])) . '</div>';

    $details = [
        ['PV', 'pv', ''],
        ['Haus ohne Wallbox', 'house', ''],
        ['Überschuss über dem Haus', 'spare', 'PV minus Haus, bevor Speicher und Auto etwas nehmen.'],
        ['Speicher', 'battery', ''],
        ['Sonne fürs Auto', 'solar', 'Der kleinere Wert aus Überschuss (unter der Hausgrenze ohne die Ladeleistung des Speichers) und Zähler.'],
        ['Am Zähler verfügbar', 'meter', 'Ladeleistung plus Einspeisung minus Bezug und Regelreserve. Ab der Hausgrenze zählt Speicherladen mit, bis zur Stützung Entladen dagegen.'],
        ['Ziel und Stufe', 'target', ''],
        ['Abweichung zur Wallbox', 'delta', ''],
        ['Was fehlt, deckt', 'cover', 'Wie im Eigenverbrauch: erst der Speicher bis zu seinem Backup-Puffer, dann das Netz. Beim Netzladen mit „Speicher schonen“ nur das Netz.'],
    ];
    $html .= '<div class="ctl-block"><h3 class="ctl-title">Rechenweg</h3><dl class="kv">';
    foreach ($details as [$label, $key, $sub]) {
        $html .= '<div><dt>' . e($label) . '</dt><dd>' . $text('details.' . $key, (string) ($c['details'][$key] ?? '—')) . ($sub !== '' ? '<span class="sub">' . e($sub) . '</span>' : '') . '</dd></div>';
    }
    $html .= '<div><dt>Grenzen</dt><dd>' . e('Haus bis ' . pct((float) $strategy['priority_soc']) . ', Stützung ab ' . pct((float) $strategy['car_buffer_soc']) . ', Start ab ' . pct((float) $strategy['car_auto_soc'])) . '<span class="sub">' . ui_inline('Unter Einstellungen → Speicher ändern', ['href' => url('/einstellungen/speicher')]) . '</span></dd></div>'
        . '<div><dt>Regelreserve, Sonnenanteil</dt><dd>' . e(num((float) ($charge['reserve_w'] ?? 0), 0) . NNBSP . 'W, mindestens ' . pct((float) ($charge['solar_share'] ?? 100))) . '</dd></div>'
        . '</dl></div>';

    if (!empty($c['log'])) {
        // Die letzten Schaltvorgänge der Regelung, beim Laden der Seite.
        $html .= '<div class="ctl-block"><h3 class="ctl-title">Zuletzt geschaltet</h3><ul class="plain-list stack-tight body-sm" role="list">';
        foreach ($c['log'] as $row) {
            $html .= '<li><span class="num muted">' . e((string) $row['time']) . '</span> ' . e((string) $row['text']) . '</li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '<div class="ctl-block"><h3 class="ctl-title">So arbeiten die Modi</h3><dl class="kv">';
    foreach (ui_mode_options() as $key => $option) {
        $html .= '<div><dt>' . e($option['label']) . '</dt><dd>' . e(Energy::modeText($key)) . '</dd></div>';
    }
    $note = !empty($c['ems_active'])
        ? 'EMS regelt: Es stellt Ladestrom, Phasen und Start/Stopp der Wallbox ein und hebt beim Netzladen den Backup-Puffer.'
        : 'EMS zeigt nur an, was es einstellen würde. Regeln lässt es sich unter Einstellungen mit dem Hauptschalter.';
    return $html . '</dl><p class="caption muted">' . e($note) . '</p></div></div></details></div></section>';
}
