<?php
declare(strict_types=1);
/**
 * Mehr → Energie: Zuordnung von PV, Speicher, Netz und Haus. Jede Karte speichert nur ihre Felder.
 * @var array $cfg
 * @var array $suggest
 * @var list<string> $missing
 * @var string $back
 */
$m = $cfg['mapping'];
$entity = static fn (string $key, string $label, string $hint): string => ui_entity_field($key, $label, (string) ($m[$key] ?? ''), $hint, $suggest[$key] ?? '');
$open = static fn (): string => '<form method="post" action="' . e(url('/mehr')) . '" class="stack">' . csrf_field()
    . '<input type="hidden" name="section" value="mapping"><input type="hidden" name="back" value="' . e($back) . '">';
$close = '<div class="form-actions"><button class="btn btn-primary" type="submit">Zuordnung speichern</button></div></form>';

if ($missing) {
    echo ui_notice('circle-alert', 'Noch nicht zugeordnet: ' . e(implode(', ', $missing)) . '.', 'warn');
}
?>
<p class="body-sm muted">Tippe einen Namen oder eine Entität. Die Liste sucht in Home Assistant und übernimmt die gewählte Kennung.</p>
<section class="card stack" aria-labelledby="map-pv-title">
  <h3 class="card-title" id="map-pv-title">Photovoltaik</h3>
  <?= $open() ?>
  <?= $entity('pv_power', 'PV-Leistung', 'Watt oder Kilowatt, die Einheit wird umgerechnet.') ?>
  <?= $entity('pv_energy', 'Energiezähler, optional', 'total_increasing in kWh. Sonst wird die Leistung aufintegriert.') ?>
  <?= $close ?>
</section>
<section class="card stack" aria-labelledby="map-battery-title">
  <h3 class="card-title" id="map-battery-title">Speicher</h3>
  <?= $open() ?>
  <?= $entity('battery_soc', 'Ladestand', 'Prozent.') ?>
  <?= $entity('battery_total', 'Gesamtkapazität', 'Installierte nutzbare Kapazität, Wh oder kWh.') ?>
  <?= $entity('battery_capacity', 'Restkapazität, optional', 'Wh oder kWh, der aktuell nutzbare Inhalt.') ?>
  <div class="form-rows"><?= ui_form_row('Leistung', ui_select('battery_mode', ['split' => 'Laden und Entladen getrennt', 'signed' => 'Ein Sensor mit Vorzeichen'], (string) $m['battery_mode']), ['for' => 'f-battery_mode', 'stack' => true]) ?></div>
  <?= $entity('battery_charge', 'Speicher laden', 'Leistung beim Laden.') ?>
  <?= $entity('battery_discharge', 'Speicher entladen', 'Leistung beim Entladen.') ?>
  <?= $entity('battery_signed', 'Speicher mit Vorzeichen', 'Nur bei einem gemeinsamen Sensor.') ?>
  <div class="form-rows"><?= ui_form_row('Positives Vorzeichen bedeutet', ui_select('battery_sign', ['positive_charge' => 'Laden', 'positive_discharge' => 'Entladen'], (string) $m['battery_sign']), ['for' => 'f-battery_sign', 'stack' => true]) ?></div>
  <p class="body-sm muted">Backup-Puffer, Grenzen und Entladeleistung stehen unter <?= ui_inline('Mehr → Speicher', ['href' => url('/mehr/speicher')]) ?>.</p>
  <?= $close ?>
</section>
<section class="card stack" aria-labelledby="map-grid-title">
  <h3 class="card-title" id="map-grid-title">Netz</h3>
  <?= $open() ?>
  <div class="form-rows"><?= ui_form_row('Zähler', ui_select('grid_mode', ['split' => 'Bezug und Einspeisung getrennt', 'signed' => 'Ein Sensor mit Vorzeichen'], (string) $m['grid_mode']), ['for' => 'f-grid_mode', 'stack' => true]) ?></div>
  <?= $entity('grid_import', 'Netzbezug', 'Leistung aus dem Netz.') ?>
  <?= $entity('grid_export', 'Einspeisung', 'Leistung ins Netz.') ?>
  <?= $entity('grid_signed', 'Netz mit Vorzeichen', 'Nur bei einem gemeinsamen Sensor.') ?>
  <div class="form-rows"><?= ui_form_row('Positives Vorzeichen bedeutet', ui_select('grid_sign', ['positive_import' => 'Bezug', 'positive_export' => 'Einspeisung'], (string) $m['grid_sign']), ['for' => 'f-grid_sign', 'stack' => true]) ?></div>
  <?= $close ?>
</section>
<section class="card stack" aria-labelledby="map-house-title">
  <h3 class="card-title" id="map-house-title">Haus</h3>
  <?= $open() ?>
  <?= $entity('house_power', 'Hausverbrauch', 'Leistung des Haushalts.') ?>
  <div class="form-rows"><?= ui_form_row('Enthält die Wallbox', ui_toggle('house_includes_wallbox', !empty($m['house_includes_wallbox']), 'Der Hausverbrauch enthält die Wallbox'), ['hint' => 'Dann zieht die App die Wallbox vom Hausverbrauch ab.']) ?></div>
  <?= $close ?>
</section>
