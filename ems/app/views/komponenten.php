<?php
declare(strict_types=1);
/**
 * Komponentenübersicht (Lieferung 2 der Vorgabe): alle Bausteine aus Abschnitt 7 in allen Zuständen,
 * hell und dunkel nebeneinander. Nur im Demo-Modus erreichbar.
 * @var array $snap
 * @var array $live
 * @var array $overview
 */
$v = $snap['values'];
$flow = Energy::flowBar($v, $snap['balance']);
$rows = [
    'in_kw' => $snap['balance']['in_kw'],
    'out_kw' => $snap['balance']['out_kw'],
    'in' => [
        ['key' => 'pv', 'icon' => 'sun', 'tone' => 'solar', 'label' => 'PV', 'kw' => $v['pv_kw']],
        ['key' => 'forecast', 'icon' => 'sun-medium', 'tone' => 'muted', 'label' => 'Prognose heute', 'muted' => true, 'value' => e(kwh(21.4)), 'context' => ui_inline(e('Rest 8,2' . NNBSP . 'kWh'), ['href' => url('/prognose')])],
        ['key' => 'battery', 'icon' => 'battery', 'tone' => 'battery', 'label' => 'Speicher', 'kw' => $v['battery_discharge_kw'], 'context' => ui_inline(e(pct($v['battery_soc'])), ['href' => url('/speicher')])],
        ['key' => 'grid', 'icon' => 'utility-pole', 'tone' => 'grid-in', 'label' => 'Netz', 'kw' => $v['grid_import_kw'], 'context' => e(ct(34.7))],
    ],
    'out' => [
        ['key' => 'house', 'icon' => 'house', 'tone' => 'muted', 'label' => 'Haus', 'kw' => $snap['balance']['house_base_kw']],
        ['key' => 'wallbox', 'icon' => 'car', 'tone' => 'muted', 'label' => 'Garage', 'kw' => $v['wallbox_kw']],
        ['key' => 'battery', 'icon' => 'battery', 'tone' => 'battery', 'label' => 'Speicher', 'kw' => $v['battery_charge_kw']],
        ['key' => 'grid', 'icon' => 'utility-pole', 'tone' => 'grid-out', 'label' => 'Einspeisung', 'kw' => $v['grid_export_kw'], 'context' => e(ct(8.1))],
    ],
];
$staticFlow = Energy::flowBar(
    ['pv_kw' => 7.8, 'battery_discharge_kw' => 0.0, 'grid_import_kw' => 0.0, 'wallbox_kw' => 4.1, 'battery_charge_kw' => 1.6, 'grid_export_kw' => 1.3],
    ['house_base_kw' => 0.8]
);
$vehicle = ['name' => 'ID.3', 'status' => 'Lädt …', 'soc' => 57.0, 'range_km' => 296.0, 'limit' => 80.0, 'range_at_limit' => 416.0];
$cards = [
    'Lädt mit Sonne' => ['name' => 'Garage', 'mode' => 'smart', 'charging' => true, 'solar_only' => true, 'power_kw' => 7.36, 'phases' => 3, 'session_kwh' => 12.4, 'remaining_s' => 4300, 'vehicle' => $vehicle],
    'Verbunden, wartet' => ['name' => 'Garage', 'mode' => 'smart_dauerhaft', 'charging' => false, 'solar_only' => false, 'power_kw' => 0.0, 'phases' => 0, 'session_kwh' => 0.0, 'remaining_s' => null, 'vehicle' => ['status' => 'Verbunden'] + $vehicle],
    'Fahrzeugdaten fehlen (gesperrt)' => ['name' => 'Garage', 'mode' => 'aus', 'charging' => false, 'solar_only' => false, 'power_kw' => null, 'phases' => 0, 'session_kwh' => null, 'remaining_s' => null, 'vehicle' => ['name' => 'Auto', 'status' => 'Nicht verbunden', 'soc' => null, 'range_km' => null, 'limit' => null, 'range_at_limit' => null]],
];
$battery = [
    'soc' => $v['battery_soc'],
    'stored_text' => num((float) ($v['battery_capacity_kwh'] ?? 0), 1) . ' von ' . kwh((float) ($v['battery_total_kwh'] ?? 13.4)),
    'priority' => 50, 'buffer' => 80, 'auto' => 90, 'flow' => 'ruhe',
];

$pane = static function (string $theme, callable $content): string {
    ob_start();
    $content($theme === 'light' ? 'h' : 'd');
    return '<div class="gallery-pane" data-theme="' . $theme . '"><p class="label">' . ($theme === 'light' ? 'Hell' : 'Dunkel') . '</p>' . ob_get_clean() . '</div>';
};
$section = static function (string $title, string $text, callable $content) use ($pane): void {
    echo '<section class="gallery-section"><header><h2 class="card-title">' . e($title) . '</h2><p class="body muted">' . e($text) . '</p></header>';
    echo '<div class="gallery-pair">' . $pane('light', $content) . $pane('dark', $content) . '</div></section>';
};
$state = static fn (string $name, string $html): string => '<div class="gallery-state"><span class="caption">' . e($name) . '</span>' . $html . '</div>';

echo ui_page_head('Komponenten', ui_head_chip('leaf', e(num(953, 0)) . NNBSP . 'kg', 'CO₂ gespart: 953 kg, Energieübersicht öffnen'));
?>
<p class="body muted">Alle Bausteine aus Abschnitt 7 in den Zuständen Standard, Hover, Fokus, Aktiv, Deaktiviert und Laden. Hover und Fokus sind hier erzwungen, damit man sie ohne Maus sieht.</p>
<div class="gallery">
<?php
$section('Buttons und Icon-Buttons', 'Kapseln für Aktionen, Primär in Tinte, Sekundär auf Sand. Icon-Buttons 44 × 44 px mit aria-label.', static function (string $s) use ($state): void {
    echo '<div class="gallery-states">';
    echo $state('Standard', '<button type="button" class="btn btn-primary">Speichern</button>');
    echo $state('Hover', '<button type="button" class="btn btn-primary is-hover">Speichern</button>');
    echo $state('Fokus', '<button type="button" class="btn btn-primary is-focus">Speichern</button>');
    echo $state('Aktiv', '<button type="button" class="btn btn-primary is-active">Speichern</button>');
    echo $state('Deaktiviert', '<button type="button" class="btn btn-primary" disabled>Speichern</button>');
    echo '</div><div class="gallery-states">';
    echo $state('Sekundär', '<button type="button" class="btn btn-secondary">Abbrechen</button>');
    echo $state('Sekundär Hover', '<button type="button" class="btn btn-secondary is-hover">Abbrechen</button>');
    echo $state('Amber', '<button type="button" class="btn btn-accent">' . icon('sun', 'icon-16') . 'Sonne nutzen</button>');
    echo $state('Laden', '<button type="button" class="btn btn-primary" disabled aria-busy="true">Wird gespeichert …</button>');
    echo '</div><div class="gallery-states">';
    echo $state('Icon-Button', ui_icon_button('sliders-horizontal', 'Einstellungen'));
    echo $state('Hover', ui_icon_button('sliders-horizontal', 'Einstellungen', ['class' => 'icon-btn is-hover']));
    echo $state('Fokus', ui_icon_button('x', 'Schließen', ['class' => 'icon-btn is-focus']));
    echo $state('Textaktion', '<button type="button" class="text-action">' . icon('plus', 'icon-16') . 'Wiederkehrenden Plan hinzufügen</button>');
    echo $state('Löschen', '<button type="button" class="text-action text-danger">' . icon('trash-2', 'icon-16') . 'Löschen</button>');
    echo '</div>';
});

$section('Segment-Umschalter', 'Modi und Zeiträume als Kapsel-Segmente. Pfeiltasten wechseln, die aktive Kapsel gleitet. Bei wenig Platz wird „Min+Solar“ zu „Min+“ mit Sonne.', static function (string $s) use ($state): void {
    echo $state('Modi', ui_segment('mode-' . $s, 'Lademodus', ui_mode_options(), 'smart', ['id' => 'g-mode-' . $s]));
    echo '<div class="gallery-narrow">' . $state('Schmal (Kurzlabel)', ui_segment('mode-n-' . $s, 'Lademodus', ui_mode_options(), 'smart_dauerhaft', ['id' => 'g-moden-' . $s])) . '</div>';
    echo $state('Kompakt (Filter)', ui_segment('span-' . $s, 'Zeitraum', ['month' => 'Monat', 'year' => 'Jahr', 'all' => 'Gesamt'], 'year', ['compact' => true, 'id' => 'g-span-' . $s]));
    echo $state('Deaktiviert', ui_segment('mode-x-' . $s, 'Lademodus', ui_mode_options(), 'aus', ['id' => 'g-modex-' . $s, 'attrs' => ['disabled' => true]]));
});

$section('Kennzahlen und unterstrichene Werte', 'Zahlen groß und fett, Labels klein in Versalien. Unterstrichen heißt bedienbar.', static function (string $s) use ($state): void {
    echo '<div class="card metrics-3">';
    echo ui_metric('Leistung', metric(11.0, 'kW'));
    echo ui_metric('Geladen', metric(12.4, 'kWh'));
    echo ui_metric('Restzeit', '<span class="num">1:12' . NNBSP . 'h</span>');
    echo '</div><div class="gallery-states">';
    echo $state('Wert', ui_inline(metric(80, '%', 0), ['data-open-dialog' => 'g-dialog-' . $s]));
    echo $state('Hover', ui_inline(metric(80, '%', 0), [], 'is-hover'));
    echo $state('Fokus', ui_inline('Fr 07:00', [], 'is-focus'));
    echo $state('Kopf-Chip', ui_head_chip('coins', e(num(500.7, 2)) . NNBSP . '€', 'Ersparnis 500,70 €'));
    echo '</div>';
    echo ui_metric_block('sun', 'solar', 'Solaranteil', metric(57.8, '%'), ['3.134 kWh Solar · 2.288 kWh Netz']);
    echo ui_metric_block('coins', 'grid-in', 'Energiepreis', metric(17.6, 'ct/kWh'), ['Ø aller Ladevorgänge']);
    echo ui_metric_block('leaf', 'grid-out', 'CO₂', metric(953, 'kg', 0), ['gespart mit Sonnenstrom']);
});

$section('Badges, Pills, Tooltips, Legende', 'Peak-Badge in Datenfarbe, Status-Pill auf weichem Amber, Tooltip mit Zeigerpfeil.', static function (string $s) use ($state): void {
    echo '<div class="gallery-states">';
    echo $state('Peak-Badge', ui_badge('113,6' . NNBSP . 'kWh'));
    echo $state('Status-Pill', ui_pill('100' . NNBSP . '% Solar'));
    echo $state('Neutral', ui_pill('Verbunden', 'plug', true));
    echo $state('Tooltip offen', str_replace('<span class="tip">', '<span class="tip open">', ui_tip('Der Speicher lädt zuerst, bis die Hausgrenze erreicht ist.')));
    echo '</div>';
    echo ui_divider('Aktiver Plan');
    echo '<div class="legend caption"><span class="legend-item"><span class="swatch swatch-solar"></span>Eigenverbrauch</span><span class="legend-item"><span class="swatch swatch-grid-in"></span>Netzbezug</span><span class="legend-item"><span class="swatch swatch-grid-out"></span>Einspeisung</span><span class="legend-item"><span class="swatch swatch-battery"></span>Speicher</span></div>';
});

$section('Energiefluss-Balken', 'Signatur-Komponente: Quellen oben, Verbraucher unten, Werte ab 56 px Segmentbreite. Oben die Demo-Anlage live, darunter Mittag mit Einspeisung.', static function (string $s) use ($flow, $rows, $staticFlow): void {
    echo '<div class="card">' . ui_flow($flow, $rows, 'g-flow-live-' . $s) . '</div>';
    echo '<div class="card">' . str_replace('<figure class="flow"', '<figure class="flow" data-static', ui_flow($staticFlow, ['in' => [], 'out' => [], 'in_kw' => 7.8, 'out_kw' => 7.8], 'g-flow-noon-' . $s)) . '</div>';
    echo '<div class="card"><p class="caption muted">Laden</p><div class="skeleton sk-bar"></div></div>';
});

$section('Ladepunkt-Karte mit Ladebalken', 'Modus, Leistung mit Phasen, Fahrzeug, Ladebalken mit ziehbarem Limit. Zustände: lädt, wartet, Daten fehlen.', static function (string $s) use ($cards, $state): void {
    $i = 0;
    foreach ($cards as $name => $card) {
        $id = 'g-cp-' . $s . (++$i);
        echo $state($name, '<div class="gallery-card">' . ui_chargepoint($card, ['id' => $id, 'editable' => $i < 3]) . '</div>');
        if ($i < 3) {
            // Rahmen der Sheets; die echten Formulare stehen auf der Seite Laden.
            echo ui_dialog($id . '-settings', 'Einstellungen: ' . $card['name'], '<p class="body muted">Hier stehen in der App die Ladeparameter, die Regelung im Detail und die Modi.</p>');
            echo ui_dialog($id . '-vehicle', 'Fahrzeug: ' . $card['vehicle']['name'], '<p class="body muted">Hier stehen in der App Name, Ladelimit und die Entitäten des Fahrzeugs.</p>');
        }
    }
});

$section('Heimspeicher-Säule', 'Drei Zonen von unten: Haus, Auto, batteriegestützt. Schwellen links unterstrichen, Füllstand als Linie.', static function (string $s) use ($battery): void {
    echo '<div class="card">' . str_replace('data-battery-col', 'data-battery-col data-static', ui_battery_column($battery, false)) . '</div>';
});

$section('Formularzeilen', 'Label links, Steuerelement rechts. Select und Eingabe 44 px, Toggle mit Häkchen.', static function (string $s): void {
    echo '<div class="card form-rows">';
    echo ui_form_row('Tag', ui_select('day-' . $s, ['fr' => 'Freitag', 'sa' => 'Samstag'], 'fr', ['id' => 'g-day-' . $s]), ['for' => 'g-day-' . $s]);
    echo ui_form_row('Ladeziel', ui_input('target-' . $s, '80', ['id' => 'g-target-' . $s, 'class' => 'field-num', 'inputmode' => 'numeric']), ['for' => 'g-target-' . $s, 'hint' => 'Prozent']);
    echo ui_form_row('Aktiv', ui_toggle('on-' . $s, true, 'Plan aktiv', ['id' => 'g-on-' . $s]));
    echo ui_form_row('Aus', ui_toggle('off-' . $s, false, 'Plan aus', ['id' => 'g-off-' . $s]));
    echo ui_form_row('Deaktiviert', ui_input('dis-' . $s, 'nicht änderbar', ['id' => 'g-dis-' . $s, 'disabled' => true]), ['for' => 'g-dis-' . $s]);
    echo '</div><div class="card stack">';
    echo ui_range('reserve-' . $s, 'Regelreserve', 200, 0, 2000, 10, 'W', 'Abstand zur Nulllinie am Zähler.');
    echo ui_entity_field('pv-' . $s, 'PV-Leistung', 'sensor.demo_pv_power', 'Watt oder Kilowatt.', 'sensor.total_dc_power');
    echo '<button type="button" class="text-action">' . icon('plus', 'icon-16') . 'Wiederkehrenden Plan hinzufügen</button>';
    echo '</div>';
});

$section('Sheets und Dialoge', 'Mobil Bottom Sheet mit Griff, ab 640 px zentriert. Esc schließt, der Fokus kehrt zurück.', static function (string $s): void {
    echo '<button type="button" class="btn btn-secondary" data-open-dialog="g-dialog-' . $s . '">Dialog öffnen</button>';
    $body = '<dl class="kv">'
        . '<div><dt>Ladepunkt</dt><dd>Garage</dd></div>'
        . '<div><dt>Geladen</dt><dd>' . e(kwh(7.5)) . '<span class="sub">3:03' . NNBSP . 'h, Ø ' . e(kw(2.4)) . '</span></dd></div>'
        . '<div><dt>Solar</dt><dd>' . ui_pill('100' . NNBSP . '% Solar') . '</dd></div>'
        . '</dl>' . ui_segment('dir-' . $s, 'Richtung', ['dep' => 'Abfahrt', 'arr' => 'Ankunft'], 'dep', ['id' => 'g-dir-' . $s]);
    echo ui_dialog('g-dialog-' . $s, 'Ladevorgang', $body, ['foot' => '<button type="button" class="text-action text-danger">' . icon('trash-2', 'icon-16') . 'Löschen</button><button type="button" class="btn btn-primary" data-close-dialog>Fertig</button>']);
});

$section('Tabelle und Listenkarten', 'Ab Tablet eine Tabelle mit Sticky-Kopf und Summenzeile, auf dem Handy Listenkarten.', static function (string $s): void {
    $data = [['Fr 10.10.', 22.4, 71, 4.12], ['Mi 08.10.', 9.8, 100, 0.8], ['Mo 06.10.', 31.2, 34, 9.85]];
    echo '<div class="card card-flush only-wide"><div class="table-wrap" tabindex="0" role="region" aria-label="Ladevorgänge"><table class="table"><thead><tr><th scope="col"><button type="button" class="sort-btn">Datum' . icon('arrow-down', 'icon-16') . '</button></th><th scope="col" class="num-col">Geladen<span class="th-unit">kWh</span></th><th scope="col" class="num-col">Sonne<span class="th-unit">%</span></th><th scope="col" class="num-col">Kosten<span class="th-unit">€</span></th></tr></thead><tbody>';
    foreach ($data as [$day, $energy, $solar, $cost]) {
        echo '<tr><td>' . e($day) . '</td><td class="num-col">' . e(num($energy, 1)) . '</td><td class="num-col">' . e(num($solar, 0)) . '</td><td class="num-col">' . e(num($cost, 2)) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td>Summe</td><td class="num-col">63,4</td><td class="num-col">57</td><td class="num-col">14,77</td></tr></tfoot></table></div></div>';
    echo '<ul class="list-cards only-narrow" role="list">';
    foreach ($data as [$day, $energy, $solar, $cost]) {
        echo '<li><a class="list-card" href="#"><span class="list-card-title">ID.3 · ' . e($day) . '</span><span class="list-card-side metric-sm">' . e(euro($cost)) . '</span><span class="list-card-meta"><span>' . e(kwh($energy)) . '</span>' . ui_pill(pct($solar) . ' Solar') . '</span></a></li>';
    }
    echo '</ul>';
});

$section('Zustände', 'Laden als Skelett, keine Daten mit einer Aktion, Hinweise, Verbindung verloren.', static function (string $s): void {
    echo '<div class="card stack"><div class="skeleton sk-line"></div><div class="skeleton sk-block"></div></div>';
    echo '<div class="card">' . ui_empty('chart-column', 'In diesem Zeitraum gibt es noch keinen Ladevorgang.', '<button type="button" class="btn btn-secondary">Aus Home Assistant übernehmen</button>') . '</div>';
    echo ui_notice('triangle-alert', 'sensor.demo_car_soc hat keinen Messwert.', 'warn');
    echo ui_notice('circle-alert', 'Der DWD antwortet mit Status 503.', 'error');
    echo '<div class="banner" role="status">' . icon('wifi-off', 'icon-20') . '<span>Keine Verbindung zu Home Assistant. Letzter Wert 14:32.</span></div>';
});

$section('Diagramme', 'Eigene SVG-Diagramme: Solarprognose als Fläche, Ladevorgänge als gestapelte Säulen, Anteile als Ring.', static function (string $s): void {
    $windows = ui_segment('w-' . $s, 'Zeitraum', ['today' => 'Heute', '3' => '3 Tage', '7' => '7 Tage'], '3', ['compact' => true, 'id' => 'g-w-' . $s, 'attrs' => ['data-chart-window' => true]]);
    echo '<div class="card">' . ui_chart('time', '/api/series?chart=power', 'Solarprognose für drei Tage', [
        'window' => '3', 'tools' => '<div class="chart-tools">' . $windows . '</div>',
        'style' => ['forecast' => ['color' => 'solar', 'type' => 'area', 'peak' => true, 'label' => 'Prognose'], 'actual' => ['color' => 'ink', 'width' => 'thin', 'label' => 'Gemessen']],
    ]) . '</div>';
    echo '<div class="card">' . ui_chart('bars', '/api/series?chart=sessions&span=year&year=' . date('Y'), 'Ladeenergie nach Tagen', [
        'style' => ['solar' => ['color' => 'solar'], 'grid' => ['color' => 'grid-in']],
    ]) . '</div>';
    echo '<div class="card">' . ui_ring([
        ['label' => 'Solar', 'value' => 3134, 'class' => 'c-solar', 'text' => kwh(3134, 0)],
        ['label' => 'Netz', 'value' => 2288, 'class' => 'c-grid-in', 'text' => kwh(2288, 0)],
    ], pct(57.8, 1), 'Solaranteil', 'Solaranteil 57,8 %: 3.134 kWh Solar, 2.288 kWh Netz') . '</div>';
});
?>
</div>
<?php view('partials/overview', ['overview' => $overview]);
