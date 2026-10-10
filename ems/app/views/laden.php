<?php
declare(strict_types=1);
/**
 * Laden (Home, 8): Energiefluss-Balken, Ladepunkt-Karte und darunter die Regelung. Ebene 2: Ladepunkt-Einstellungen, Fahrzeug, Energieübersicht.
 * @var array $snap
 * @var array $live
 * @var array $overview
 */
$v = $snap['values'];
$b = $snap['balance'];
$c = $snap['cfg']['charge'];
$cp = $snap['chargepoint'] + ['vehicle' => $snap['vehicle']];
$vehicle = $snap['vehicle'];
$tariffs = $snap['cfg']['tariffs'];
$f = $snap['forecast'];
$strategy = zone_thresholds(
    (float) ($snap['cfg']['battery_strategy']['priority_soc'] ?? 80),
    (float) ($snap['cfg']['battery_strategy']['car_buffer_soc'] ?? 100),
    (float) ($snap['cfg']['battery_strategy']['car_auto_soc'] ?? 100),
);
$month = $overview['periods']['30'];
$share = $month['solar_pct'] !== null ? (float) $month['solar_pct'] : null;
$price = $month['ct'] !== null ? (float) $month['ct'] : null;
$co2 = (float) $month['co2_saved_kg'];

echo ui_page_head('Laden', ui_head_chips(
    ui_head_chip('sun', e(num($share, 0)) . NNBSP . '%', 'Solaranteil der letzten 30 Tage: ' . pct($share) . '. Energieübersicht öffnen'),
    ui_head_chip('coins', e(num($price, 1)) . NNBSP . 'ct', 'Ø Preis der letzten 30 Tage: ' . ($price === null ? 'unbekannt' : ct($price)) . '. Energieübersicht öffnen'),
    ui_head_chip('leaf', e(num($co2, 0)) . NNBSP . 'kg', 'CO₂ gespart in den letzten 30 Tagen: ' . num($co2, 0) . ' kg. Energieübersicht öffnen'),
));

$missing = Actions::missing($snap['cfg']['mapping']);
if (!$snap['connected']) {
    echo ui_notice('wifi-off', e((string) ($snap['error'] ?? 'Keine Verbindung.')) . ' ' . ui_inline('Verbindung prüfen', ['href' => url('/mehr/system')]), 'error');
} elseif ($missing) {
    echo ui_notice('circle-alert', 'Noch nicht zugeordnet: ' . e(implode(', ', $missing)) . '. ' . ui_inline('Assistent öffnen', ['href' => url('/einrichten')]), 'warn');
}

$socLink = ui_inline('<span data-live="battery.soc_text">' . e(pct($v['battery_soc'] !== null ? (float) $v['battery_soc'] : null)) . '</span><span class="sr-only">, zum Speicher</span>', ['href' => url('/speicher')]);
$rows = [
    'in_kw' => $b['in_kw'],
    'out_kw' => $b['out_kw'],
    'in' => [
        ['key' => 'pv', 'icon' => 'sun', 'tone' => 'solar', 'label' => 'PV', 'kw' => $v['pv_kw']],
        ['key' => 'forecast', 'icon' => 'sun-medium', 'tone' => 'muted', 'label' => 'Prognose heute', 'muted' => true,
            'value' => '<span data-live="flow_rows.forecast_value">' . e(Snapshot::forecastValue($f)) . '</span>',
            'context' => ui_inline('<span data-live="flow_rows.forecast_text">' . e(Snapshot::forecastRest($f)) . '</span><span class="sr-only">, zur Prognose</span>', ['href' => url('/prognose')])],
        ['key' => 'battery', 'icon' => 'battery', 'tone' => 'battery', 'label' => 'Speicher', 'kw' => $v['battery_discharge_kw'], 'context' => $socLink],
        ['key' => 'grid', 'icon' => 'utility-pole', 'tone' => 'grid-in', 'label' => 'Netz', 'kw' => $v['grid_import_kw'], 'context' => e(ct((float) $tariffs['import_ct']))],
    ],
    'out' => [
        ['key' => 'house', 'icon' => 'house', 'tone' => 'muted', 'label' => 'Haus', 'kw' => $b['house_base_kw']],
        ['key' => 'wallbox', 'icon' => 'car', 'tone' => 'muted', 'label' => $cp['name'], 'kw' => $v['wallbox_kw'], 'context' => e($vehicle['name'])],
        ['key' => 'battery', 'icon' => 'battery', 'tone' => 'battery', 'label' => 'Speicher', 'kw' => $v['battery_charge_kw'], 'context' => $socLink],
        ['key' => 'grid', 'icon' => 'utility-pole', 'tone' => 'grid-out', 'label' => 'Einspeisung', 'kw' => $v['grid_export_kw'], 'context' => e(ct((float) $tariffs['export_ct']))],
    ],
];
?>
<div class="home-grid">
  <section class="card" aria-labelledby="flow-title">
    <h2 class="sr-only" id="flow-title">Energiefluss</h2>
    <?= ui_flow(Energy::flowBar($v, $b), $rows) ?>
  </section>
  <?= ui_chargepoint($cp, ['id' => 'cp', 'live' => true]) ?>
  <?= ui_control($snap['control'], $c, $strategy, ['live' => true]) ?>
</div>
<?php if ($snap['warnings']): ?>
<section class="section" aria-labelledby="hints-title">
  <h2 class="section-title" id="hints-title">Hinweise</h2>
  <div class="notice notice-warn"><?= icon('triangle-alert', 'icon-20') ?><ul class="notice-list body-sm" role="list">
<?php foreach (array_slice($snap['warnings'], 0, 4) as $warning): ?>
    <li><?= e($warning) ?></li>
<?php endforeach; ?>
  </ul></div>
</section>
<?php endif; ?>
<?php
// Ebene 2: Ladepunkt-Einstellungen
ob_start();
?>
<p class="body muted">Wie die Regelung mit diesen Werten gerade entscheiden würde, zeigt die Karte Regelung unter dem Ladepunkt. Die App schreibt nichts an die Wallbox.</p>
<?php view('partials/charge-form', ['charge' => $c, 'back' => '/']); ?>
<?= ui_divider('Ladepunkt') ?>
<form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="chargepoint"><input type="hidden" name="back" value="/">
  <div class="form-rows"><?= ui_form_row('Name', ui_input('chargepoint_name', (string) $cp['name'], ['id' => 'f-cp-name', 'maxlength' => '40', 'autocomplete' => 'off']), ['for' => 'f-cp-name']) ?></div>
  <div class="form-actions"><button class="btn btn-secondary" type="submit">Name speichern</button></div>
</form>
<?php
echo ui_dialog('cp-settings', 'Einstellungen: ' . $cp['name'], ob_get_clean());

// Ebene 2: Fahrzeug
ob_start();
view('partials/vehicle-form', ['name' => (string) $vehicle['name'], 'limit' => (float) $vehicle['limit'], 'mapping' => $snap['cfg']['mapping'], 'back' => '/']);
echo ui_dialog('cp-vehicle', 'Fahrzeug: ' . $vehicle['name'], ob_get_clean());
view('partials/overview', ['overview' => $overview]);
