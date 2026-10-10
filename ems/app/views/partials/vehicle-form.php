<?php
declare(strict_types=1);
/**
 * Fahrzeug als Formular: im Fahrzeug-Sheet auf „Laden“ und unter Mehr → Fahrzeug.
 * @var string $name
 * @var float $limit Ladelimit der App
 * @var ?float $carLimit Ladelimit, das das Auto meldet (Tesla BLE); gilt dann statt des Reglers
 * @var ?float $capacity Kapazität von Hand, null wenn nicht gesetzt
 * @var array $mapping
 * @var array $suggest
 * @var string $back
 */
$carLimit ??= null;
$capacity ??= null;
$suggest ??= [];
$entity = static fn (string $key, string $label, string $hint): string => ui_entity_field($key, $label, (string) ($mapping[$key] ?? ''), $hint, $suggest[$key] ?? '');
?>
<form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="vehicle"><input type="hidden" name="back" value="<?= e($back) ?>">
  <div class="form-rows"><?= ui_form_row('Name', ui_input('vehicle_name', $name, ['id' => 'f-vehicle-name', 'maxlength' => '40', 'autocomplete' => 'off']), ['for' => 'f-vehicle-name']) ?></div>
<?php if ($carLimit !== null): ?>
  <p class="body-sm">Das Auto meldet sein Ladelimit: <strong class="num"><?= e(pct($carLimit)) ?></strong>. Es gilt für Restzeit und Ladebalken statt des Reglers.</p>
<?php else: ?>
  <?= ui_range('limit_soc', 'Ladelimit', $limit, 20, 100, 5, '%', 'Ziel für Restzeit und Ladebalken. Die Wallbox wird nicht gestellt.') ?>
<?php endif; ?>
  <div class="form-rows"><?= ui_form_row('Akku', ui_input('capacity_kwh', $capacity === null ? '' : ui_field_num($capacity, 1), ['id' => 'f-capacity_kwh', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '–']), ['for' => 'f-capacity_kwh', 'hint' => 'kWh, für die Restzeit, wenn keine Entität die Kapazität meldet.']) ?></div>
  <?= ui_divider('Entitäten') ?>
  <?= $entity('car_soc', 'Ladestand des Autos', 'Prozent, sobald das Fahrzeug ihn meldet.') ?>
  <?= $entity('car_range', 'Reichweite, optional', 'Kilometer oder Meilen. Die Reichweite beim Limit wird daraus geschätzt.') ?>
  <?= $entity('car_odometer', 'Kilometerstand, optional', 'Kilometer oder Meilen. Jeder neue Ladevorgang bekommt den Stand beim Anstecken.') ?>
  <?= $entity('car_limit', 'Ladelimit des Autos, optional', 'Prozent. Ist es zugeordnet, gilt es statt des Reglers.') ?>
  <?= $entity('car_capacity', 'Kapazität des Autos, optional', 'Wh oder kWh. Sonst gilt das Feld Akku.') ?>
<?php $id = static fn (string $name): string => '<span class="entity-id">' . e($name) . '</span>'; ?>
  <p class="caption muted">Tesla über Bluetooth: Mit ESPHome Tesla BLE heißen die Sensoren etwa <?= $id('sensor.tesla_ble_charge_level') ?>, <?= $id('sensor.tesla_ble_range') ?>, <?= $id('sensor.tesla_ble_odometer') ?> und <?= $id('sensor.tesla_ble_charge_limit') ?>. Mit dem deutschen Sprachpaket enden sie auf <?= $id('_ladezustand') ?>, <?= $id('_reichweite') ?>, <?= $id('_kilometerstand') ?> und <?= $id('_ladelimit') ?>. Gefundene Sensoren stehen als Vorschlag unter dem Feld. Meilen rechnet die App in Kilometer um.</p>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Fahrzeug speichern</button></div>
</form>
