<?php
declare(strict_types=1);
/**
 * Laden (Home, 8): Energiefluss-Balken und Ladepunkt-Karte. Ebene 2: Ladepunkt-Einstellungen, Fahrzeug, Energieübersicht.
 * @var array $snap
 * @var array $live
 * @var array $overview
 */
$v = $snap['values'];
$b = $snap['balance'];
$s = $snap['setpoint'];
$c = $snap['cfg']['charge'];
$cp = $snap['chargepoint'] + ['vehicle' => $snap['vehicle']];
$vehicle = $snap['vehicle'];
$tariffs = $snap['cfg']['tariffs'];
$f = $snap['forecast'];
$co2 = (float) $overview['periods']['all']['co2_saved_kg'];

echo ui_page_head('Laden', ui_head_chip('leaf', e(num($co2, 0)) . NNBSP . 'kg', 'CO₂ gespart: ' . num($co2, 0) . ' kg. Energieübersicht öffnen'));

$missing = Actions::missing($snap['cfg']['mapping']);
if (!$snap['connected']) {
    echo ui_notice('wifi-off', e((string) ($snap['error'] ?? 'Keine Verbindung.')) . ' ' . ui_inline('Verbindung prüfen', ['href' => url('/mehr/system')]), 'error');
} elseif ($missing) {
    echo ui_notice('circle-alert', 'Noch nicht zugeordnet: ' . e(implode(', ', $missing)) . '. ' . ui_inline('Assistent öffnen', ['href' => url('/einrichten')]), 'warn');
}

$rows = [
    'in_kw' => $b['in_kw'],
    'out_kw' => $b['out_kw'],
    'in' => [
        ['key' => 'pv', 'icon' => 'sun', 'tone' => 'solar', 'label' => 'PV', 'kw' => $v['pv_kw'], 'context' => $f ? ui_inline(e(kwh($f['today_kwh'] ?? null)) . ' heute<span class="sr-only">, zur Prognose</span>', ['href' => url('/prognose')]) : null],
        ['key' => 'battery', 'icon' => 'battery', 'tone' => 'battery', 'label' => 'Speicher', 'kw' => $v['battery_discharge_kw'], 'context' => ui_inline('<span data-live="battery.soc_text">' . e(pct($v['battery_soc'] !== null ? (float) $v['battery_soc'] : null)) . '</span><span class="sr-only">, zum Speicher</span>', ['href' => url('/speicher')])],
        ['key' => 'grid', 'icon' => 'utility-pole', 'tone' => 'grid-in', 'label' => 'Netz', 'kw' => $v['grid_import_kw'], 'context' => e(ct((float) $tariffs['import_ct']))],
    ],
    'out' => [
        ['key' => 'house', 'icon' => 'house', 'tone' => 'muted', 'label' => 'Haus', 'kw' => $b['house_base_kw']],
        ['key' => 'wallbox', 'icon' => 'car', 'tone' => 'muted', 'label' => $cp['name'], 'kw' => $v['wallbox_kw'], 'context' => e($vehicle['name'])],
        ['key' => 'battery', 'icon' => 'battery', 'tone' => 'battery', 'label' => 'Speicher', 'kw' => $v['battery_charge_kw']],
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
<p class="body muted">Der Vorschlag zeigt, was eine Regelung jetzt einstellen würde. Die App schreibt nichts an die Wallbox.</p>
<?php view('partials/charge-form', ['charge' => $c, 'back' => '/']); ?>
<?= ui_divider('Regelung im Detail') ?>
<dl class="kv">
  <div><dt>PV</dt><dd data-live="pv"><?= e(kw($v['pv_kw'])) ?></dd></div>
  <div><dt>Haus ohne Wallbox</dt><dd data-live="house"><?= e(kw($b['house_base_kw'])) ?></dd></div>
  <div><dt>Speicher-Vorrang</dt><dd><span data-live="storage"><?= e(kw($b['storage_priority_kw'])) ?></span><span class="sub">Ladeleistung des Speichers, solange er unter der Hausgrenze liegt.</span></dd></div>
  <div><dt>Überschuss</dt><dd data-live="surplus"><?= e(kw($b['surplus_kw'])) ?></dd></div>
  <div><dt>Soll am Zähler</dt><dd><span data-live="psoll"><?= e(kw($s['p_soll_kw'], 2)) ?></span><span class="sub">Wallbox minus Netz minus Reserve, Bezug zählt positiv.</span></dd></div>
  <div><dt>Abweichung</dt><dd data-live="delta"><?= e(kw($s['delta_kw'], 2)) ?></dd></div>
</dl>
<?= ui_divider('Die Modi') ?>
<dl class="kv">
<?php foreach (ui_mode_options() as $key => $option): ?>
  <div><dt><?= e($option['label']) ?></dt><dd><?= e(Energy::modeText($key)) ?></dd></div>
<?php endforeach; ?>
</dl>
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
