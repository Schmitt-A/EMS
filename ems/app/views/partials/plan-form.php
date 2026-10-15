<?php
declare(strict_types=1);
/**
 * Ladeplan fürs Netzladen (GridPlans): Wochentage, Uhrzeit, Ziel nach Ladestand, Reichweite oder Energie und optional
 * die Bedingungen Hausspeicher und Sonne, je mit dem Wert von jetzt. Die Felder der Zielart zeigt CSS über :has() wie
 * im Ladeziel-Sheet. Gespeichert wird per Formular (section grid_plan).
 * @var ?array $plan gespeicherter Plan, null für einen neuen
 * @var array $snap
 * @var string $back
 * @var string $px Präfix der IDs, je Plan eigen
 */
$plan ??= null;
$days = $plan['days'] ?? [1, 2, 3, 4, 5];
$time = (string) ($plan['time'] ?? '07:00');
$type = (string) ($plan['type'] ?? 'soc');
$value = isset($plan['value']) ? (float) $plan['value'] : null;
$vehicle = $snap['vehicle'];
$soc = $type === 'soc' && $value !== null ? $value : (float) ($vehicle['limit'] ?? 80);
$km = $type === 'range' && $value !== null ? $value : 300.0;
$kwhValue = $type === 'energy' && $value !== null ? $value : 20.0;
$batterySoc = isset($snap['values']['battery_soc']) ? (float) $snap['values']['battery_soc'] : null;
$now = time();
$next = GridPlans::next(['days' => $days, 'time' => $time], $now);
$sun = $next === null ? null : ChargePlan::sunKwh(store(), $snap['cfg'], $snap, $now, $next);
$carKw = GridPlans::carKw($snap['cfg'], store());
$learned = GridPlans::learned(store());
$power = ChargePlan::powerKw($snap['cfg']['charge'], $carKw);
$fixed = is_numeric($snap['cfg']['vehicle']['charge_kw'] ?? null);
$id = static fn (string $name): string => 'f-' . $px . '-' . $name;
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack target-form plan-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="grid_plan"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="id" value="<?= e((string) ($plan['id'] ?? '')) ?>">
  <p class="body-sm muted">Steckt das Auto, lädt es an diesen Tagen so aus dem Netz, dass es zur Uhrzeit das Ziel erreicht: mit dynamischem Tarif in den günstigsten Viertelstunden davor, mit statischem in den letzten, damit die Sonne vorher noch helfen kann.</p>
  <div class="form-rows"><?= ui_form_row('Plan aktiv', ui_toggle('on', (bool) ($plan['on'] ?? true), 'Plan aktiv', ['id' => $id('on')]), ['for' => $id('on')]) ?></div>
  <fieldset class="plan-days"><legend class="form-label">Wochentage</legend>
<?php foreach (GridPlans::dayNames() as $n => $name): ?>
    <label class="plan-day"><input type="checkbox" name="days[]" value="<?= (int) $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?> aria-label="<?= e(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][$n - 1]) ?>"><span aria-hidden="true"><?= e($name) ?></span></label>
<?php endforeach; ?>
  </fieldset>
  <div class="form-rows"><?= ui_form_row('Fertig um', ui_input('time', $time, ['id' => $id('time'), 'type' => 'time', 'class' => 'field-num']), ['for' => $id('time')]) ?></div>
  <?= ui_segment('type', 'Ziel nach', [
      'soc' => ['label' => 'Ladestand', 'short' => '%'],
      'range' => ['label' => 'Reichweite', 'short' => 'km'],
      'energy' => ['label' => 'Energie', 'short' => 'kWh'],
  ], $type, ['id' => $px . '-type']) ?>
  <div class="target-panel stack" data-panel="soc">
    <?= str_replace(['id="f-soc"', 'for="f-soc"'], ['id="' . $id('soc') . '"', 'for="' . $id('soc') . '"'], ui_range('soc', 'Bis Ladestand', $soc, 20, 100, 5, '%', $vehicle['soc'] === null ? 'Braucht den Ladestand des Autos, etwa über Tesla BLE.' : 'Jetzt ' . pct((float) $vehicle['soc']) . '.')) ?>
  </div>
  <div class="target-panel stack" data-panel="range">
    <div class="form-rows"><?= ui_form_row('Reichweite', ui_input('km', ui_field_num($km, 0), ['id' => $id('km'), 'class' => 'field-num', 'inputmode' => 'numeric', 'autocomplete' => 'off']), ['for' => $id('km'), 'hint' => 'Kilometer, die das Auto dann anzeigt' . ($vehicle['range_km'] === null ? '; braucht die Reichweite des Autos.' : ', jetzt ' . with_unit((float) $vehicle['range_km'], 0, 'km') . '.')]) ?></div>
  </div>
  <div class="target-panel stack" data-panel="energy">
    <div class="form-rows"><?= ui_form_row('Energie', ui_input('kwh', ui_field_num($kwhValue, 1), ['id' => $id('kwh'), 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => $id('kwh'), 'hint' => 'kWh seit dem Anstecken.']) ?></div>
  </div>
  <?= ui_divider('Nur wenn') ?>
  <div class="form-rows">
    <?= ui_form_row('Speicher unter', '<span class="ha-link">' . ui_toggle('soc_below_on', ($plan['soc_below'] ?? null) !== null, 'Bedingung Speicher', ['id' => $id('soc-on')]) . ui_input('soc_below', ui_field_num((float) ($plan['soc_below'] ?? 50), 0), ['id' => $id('soc-below'), 'class' => 'field-num', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'aria-label' => 'Speicher unter Prozent']) . '</span>', ['hint' => 'Prozent des Hausspeichers, jetzt ' . ($batterySoc === null ? 'unbekannt' : pct($batterySoc)) . '. Ist er voller, lädt der Plan nicht.']) ?>
    <?= ui_form_row('Sonne unter', '<span class="ha-link">' . ui_toggle('pv_below_on', ($plan['pv_below'] ?? null) !== null, 'Bedingung Sonne', ['id' => $id('pv-on')]) . ui_input('pv_below', ui_field_num((float) ($plan['pv_below'] ?? 10), 0), ['id' => $id('pv-below'), 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'aria-label' => 'Sonne unter kWh']) . '</span>', ['hint' => 'kWh Prognose bis zur Uhrzeit' . ($sun === null || $next === null ? '' : ', bis ' . day_label(date('Y-m-d', $next)) . ' ' . date('H:i', $next) . ' erwartet ≈ ' . kwh($sun, 1)) . '. Kommt mehr, lädt der Plan nicht aus dem Netz.']) ?>
  </div>
  <p class="caption muted">EMS rechnet mit <?= e(kw($power)) ?> Ladeleistung<?= $fixed ? ' (fest unter Fahrzeug)' : ($learned !== null ? ' (gelernt beim Netzladen)' : ' (Höchststrom × Phasen)') ?>. Ändern unter <?= ui_inline('Fahrzeug', ['data-open-dialog' => 'cp-vehicle']) ?>.</p>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit" name="do" value="save"><?= icon('calendar', 'icon-16') ?>Plan speichern</button>
<?php if ($plan !== null): ?>
    <button class="btn btn-secondary" type="submit" name="do" value="delete">Löschen</button>
<?php endif; ?>
  </div>
</form>
