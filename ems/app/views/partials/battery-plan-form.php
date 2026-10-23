<?php
declare(strict_types=1);
/**
 * Ladeplan des Speichers (BatteryPlans): Wochentage (für einen neuen Plan keine vorgewählt), Uhrzeit, Ladestand bis
 * dahin und optional die Bedingungen Speicher unter einem Ladestand und Sonne unter einer Menge, je mit dem Wert von
 * jetzt. components/plan.js rechnet beim Tippen (data-kind battery) Start, Kosten und Sonne je Tag. Gespeichert wird per
 * Formular (section battery_plan).
 * @var ?array $plan gespeicherter Plan, null für einen neuen
 * @var array $snap
 * @var string $back
 * @var string $px Präfix der IDs, je Plan eigen
 */
$plan ??= null;
$days = $plan['days'] ?? [];
$time = (string) ($plan['time'] ?? '06:00');
$target = (float) ($plan['target_soc'] ?? 80);
$values = $snap['values'];
$soc = is_numeric($values['battery_soc'] ?? null) ? (float) $values['battery_soc'] : null;
$capacity = BatteryPlans::capacity($values);
$settings = BatteryPlan::settings($snap['cfg']);
$now = time();
$sheet = GridPlans::sheetData(store(), (array) $snap['cfg']['plant'], 'battery', $now);
$id = static fn (string $name): string => 'f-' . $px . '-' . $name;
$range = static fn (string $name, string $label, float $value, float $min, string $hint = ''): string => str_replace(
    ['id="f-' . $name . '"', 'for="f-' . $name . '"'],
    ['id="' . $id($name) . '"', 'for="' . $id($name) . '"'],
    ui_range($name, $label, $value, $min, 100, 5, '%', $hint)
);
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack plan-form"<?= ui_attrs([
    'data-plan-form' => true,
    'data-kind' => 'battery',
    'data-now' => $now,
    'data-days' => ui_json($sheet['days']),
    'data-sun' => ui_json($sheet['sun']),
    'data-power' => round($settings['power_kw'], 2),
    'data-eta' => round($settings['efficiency'], 3),
    'data-price' => $sheet['price'],
    'data-dynamic' => $sheet['dynamic'] ? '1' : null,
    'data-window' => $sheet['window'] ? '1' : null,
    'data-soc' => $soc,
    'data-capacity' => $capacity === null ? null : round($capacity, 2),
]) ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="battery_plan"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="id" value="<?= e((string) ($plan['id'] ?? '')) ?>">
  <p class="body-sm muted">An diesen Tagen lädt der Speicher so aus dem Netz, dass er zur Uhrzeit den Ladestand hat: in den günstigsten Viertelstunden davor, im statischen Tarif in seinen Zeitfenstern, sonst in den letzten. Was die Sonne bis dahin erwartbar schafft, kommt nicht aus dem Netz. Geladen wird nur, wenn EMS regelt.</p>
  <div class="form-rows"><?= ui_form_row('Plan aktiv', ui_toggle('on', (bool) ($plan['on'] ?? true), 'Plan aktiv', ['id' => $id('on')]), ['for' => $id('on')]) ?></div>
  <fieldset class="plan-days"><legend class="form-label">Wochentage</legend>
<?php foreach (GridPlans::dayNames() as $n => $name): ?>
    <label class="plan-day"><input type="checkbox" name="days[]" value="<?= (int) $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?> aria-label="<?= e(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][$n - 1]) ?>"><span aria-hidden="true"><?= icon('check', 'icon-16') ?><?= e($name) ?></span></label>
<?php endforeach; ?>
  </fieldset>
  <div class="form-rows"><?= ui_form_row('Fertig um', ui_input('time', $time, ['id' => $id('time'), 'type' => 'time', 'class' => 'field-num']), ['for' => $id('time')]) ?></div>
  <p class="caption plan-calc" data-plan-start aria-live="polite"></p>
  <?= $range('target_soc', 'Ladestand bis', $target, 10, $soc === null ? 'Braucht den Ladestand des Speichers.' : 'Jetzt ' . pct($soc) . ($capacity === null ? '.' : ' von ' . kwh($capacity, 1) . '.')) ?>
  <p class="body-sm plan-calc plan-cost" data-plan-cost aria-live="polite"></p>
  <?= ui_divider('Nur wenn') ?>
  <p class="caption muted">Geprüft, sobald der Plan in den nächsten 24 Stunden dran ist. Gelten dann alle eingeschalteten Bedingungen, lädt er bis zum Ziel.</p>
  <div class="plan-conds">
    <div class="plan-cond">
      <div class="form-rows"><?= ui_form_row('Speicher unter', ui_toggle('soc_below_on', ($plan['soc_below'] ?? null) !== null, 'Bedingung Speicher', ['id' => $id('soc-on')]), ['for' => $id('soc-on'), 'hint' => 'Jetzt ' . ($soc === null ? 'unbekannt' : pct($soc)) . '. Ist er voller, lädt der Plan nicht.']) ?></div>
      <div class="plan-cond-value stack-tight"><?= $range('soc_below', 'Ladestand unter', (float) ($plan['soc_below'] ?? 30), 5) ?></div>
    </div>
    <div class="plan-cond">
      <div class="form-rows"><?= ui_form_row('Sonne unter', ui_toggle('pv_below_on', ($plan['pv_below'] ?? null) !== null, 'Bedingung Sonne', ['id' => $id('pv-on')]), ['for' => $id('pv-on'), 'hint' => 'Prognose bis zur Uhrzeit. Kommt mehr, lädt der Plan an diesem Tag nicht aus dem Netz.']) ?></div>
      <div class="form-rows plan-cond-value"><?= ui_form_row('Grenze in kWh', ui_input('pv_below', ui_field_num((float) ($plan['pv_below'] ?? 10), 0), ['id' => $id('pv-below'), 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => $id('pv-below')]) ?></div>
      <ul class="plan-sun caption" data-plan-sun role="list" aria-label="Sonnenprognose je gewähltem Tag"></ul>
    </div>
  </div>
  <p class="caption muted">EMS rechnet mit <?= e(kw($settings['power_kw'])) ?> Ladeleistung aus dem Netz und <?= e(pct($settings['efficiency'] * 100)) ?> Wirkungsgrad. Ändern unter <?= ui_inline('Einstellungen → Speicher', ['href' => url('/einstellungen/speicher')]) ?>.</p>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit" name="do" value="save"><?= icon('calendar', 'icon-16') ?>Plan speichern</button>
<?php if ($plan !== null): ?>
    <button class="btn btn-secondary" type="submit" name="do" value="delete">Löschen</button>
<?php endif; ?>
  </div>
</form>
