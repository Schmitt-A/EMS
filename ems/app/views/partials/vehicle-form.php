<?php
declare(strict_types=1);
/**
 * Fahrzeug als Formular: im Fahrzeug-Sheet auf „Laden“ und unter Mehr → Fahrzeug.
 * @var string $name
 * @var float $limit
 * @var array $mapping
 * @var string $back
 */
?>
<form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="vehicle"><input type="hidden" name="back" value="<?= e($back) ?>">
  <div class="form-rows"><?= ui_form_row('Name', ui_input('vehicle_name', $name, ['id' => 'f-vehicle-name', 'maxlength' => '40', 'autocomplete' => 'off']), ['for' => 'f-vehicle-name']) ?></div>
  <?= ui_range('limit_soc', 'Ladelimit', $limit, 20, 100, 5, '%', 'Ziel für Restzeit und Ladebalken. Die Wallbox wird nicht gestellt.') ?>
  <?= ui_entity_field('car_soc', 'Ladestand des Autos', (string) ($mapping['car_soc'] ?? ''), 'Prozent, sobald das Fahrzeug ihn meldet.') ?>
  <?= ui_entity_field('car_capacity', 'Kapazität des Autos', (string) ($mapping['car_capacity'] ?? ''), 'Wh oder kWh.') ?>
  <?= ui_entity_field('car_range', 'Reichweite, optional', (string) ($mapping['car_range'] ?? ''), 'Kilometer. Die Reichweite beim Limit wird daraus geschätzt.') ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Fahrzeug speichern</button></div>
</form>
