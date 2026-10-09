<?php
declare(strict_types=1);
/**
 * Ladeparameter als Formular: im Ladepunkt-Sheet auf „Laden“ und unter Mehr → Ladepunkt.
 * @var array $charge
 * @var string $back
 */
?>
<form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="charge"><input type="hidden" name="back" value="<?= e($back) ?>">
  <?= ui_range('solar_share', 'Mindest-Sonnenanteil', (float) $charge['solar_share'], 0, 100, 1, '%', 'Bei 100 % bleibt die Ladeleistung auf dem Überschuss. Bei 50 % darf sie bis zum Doppelten gehen, der Rest käme aus dem Netz.') ?>
  <?= ui_range('reserve_w', 'Regelreserve', (float) $charge['reserve_w'], 0, 2000, 10, 'W', 'Abstand zur Nulllinie am Zähler, damit eine Wolke nicht gleich Netzbezug auslöst.') ?>
  <?= ui_range('min_a', 'Mindeststrom', (float) $charge['min_a'], 6, 16, 1, 'A', '1-phasig sind 6 A rund 1,4 kW, 3-phasig 4,1 kW.') ?>
  <?= ui_range('max_a', 'Höchststrom', (float) $charge['max_a'], 6, 16, 1, 'A') ?>
  <?= ui_range('switch_s', 'Schütz-Schutzzeit', (float) $charge['switch_s'], 60, 600, 10, 's') ?>
  <?= ui_range('on_delay_s', 'Einschaltverzögerung', (float) $charge['on_delay_s'], 60, 600, 10, 's') ?>
  <?= ui_range('off_delay_s', 'Ausschaltverzögerung', (float) $charge['off_delay_s'], 60, 600, 10, 's') ?>
  <div class="form-rows"><?= ui_form_row('Phasen', ui_select('phase_mode', ['auto' => 'Automatisch', '1p' => '1-phasig', '3p' => '3-phasig'], (string) $charge['phase_mode']), ['for' => 'f-phase_mode']) ?></div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Ladeparameter speichern</button></div>
</form>
