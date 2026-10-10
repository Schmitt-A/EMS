<?php
declare(strict_types=1);
/**
 * Ladeparameter als Formular: im Ladepunkt-Sheet auf „Laden“ und unter Einstellungen → Ladepunkt. Die Zeiten
 * folgen evcc; „Zeiten wie evcc“ setzt Ein 60 s, Aus 180 s und die Schutzzeit 60 s.
 * @var array $charge
 * @var string $back
 */
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="charge"><input type="hidden" name="back" value="<?= e($back) ?>">
  <?= ui_range('solar_share', 'Mindest-Sonnenanteil', (float) $charge['solar_share'], 0, 100, 1, '%', 'Bei 100 % bleibt die Ladeleistung auf dem Überschuss. Bei 50 % darf sie bis zum Doppelten gehen, der Rest käme aus dem Netz.') ?>
  <?= ui_range('reserve_w', 'Regelreserve', (float) $charge['reserve_w'], 0, 2000, 10, 'W', 'Abstand zur Nulllinie am Zähler, damit eine Wolke nicht gleich Netzbezug auslöst.') ?>
  <?= ui_range('min_a', 'Mindeststrom', (float) $charge['min_a'], 6, 16, 1, 'A', '1-phasig sind 6 A rund 1,4 kW, 3-phasig 4,1 kW.') ?>
  <?= ui_range('max_a', 'Höchststrom', (float) $charge['max_a'], 6, 16, 1, 'A') ?>
  <?= ui_range('on_delay_s', 'Einschaltverzögerung', (float) $charge['on_delay_s'], 60, 600, 10, 's', 'So lange muss genug Sonne da sein, bevor die Ladung startet, und so lange wartet der Wechsel auf drei Phasen. evcc: 1 min.') ?>
  <?= ui_range('off_delay_s', 'Ausschaltverzögerung', (float) $charge['off_delay_s'], 60, 600, 10, 's', 'So lange lädt das Auto mit dem Mindeststrom weiter, bevor es stoppt oder auf eine Phase wechselt. evcc: 3 min.') ?>
  <?= ui_range('switch_s', 'Schütz-Schutzzeit', (float) $charge['switch_s'], 60, 600, 10, 's', 'Mindestabstand zwischen zwei Schaltvorgängen der Wallbox. evcc: 60 s.') ?>
  <div class="form-rows">
    <?= ui_form_row('Phasen', ui_select('phase_mode', ['auto' => 'Automatisch', '1p' => '1-phasig', '3p' => '3-phasig'], (string) $charge['phase_mode']), ['for' => 'f-phase_mode']) ?>
    <?= ui_form_row('Nach dem Ladeziel', ui_select('then_mode', ['smart' => 'Nur Solar', 'smart_dauerhaft' => 'Min+Solar', 'aus' => 'Aus'], (string) ($charge['then_mode'] ?? 'smart')), ['for' => 'f-then_mode', 'hint' => 'Vorschlag im Ladeziel-Sheet.']) ?>
    <?= ui_form_row('Nach dem Abstecken', ui_select('after_unplug', ['' => 'Modus behalten', 'smart' => 'Nur Solar', 'smart_dauerhaft' => 'Min+Solar', 'aus' => 'Aus', 'schnell' => 'Netzladen'], (string) ($charge['after_unplug'] ?? '')), ['for' => 'f-after_unplug']) ?>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Ladeparameter speichern</button>
    <button class="btn btn-secondary" type="submit" name="evcc_defaults" value="1">Zeiten wie evcc</button>
  </div>
</form>
