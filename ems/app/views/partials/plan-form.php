<?php
declare(strict_types=1);
/**
 * Ladeplan fürs Netzladen (GridPlans): Wochentage, Uhrzeit, Ziel nach Ladestand, Reichweite oder Energie und optional
 * die Bedingungen Hausspeicher, Sonne und Auto, je mit dem Wert von jetzt. Die Felder der Zielart, die Grenze einer
 * eingeschalteten Bedingung und die Einheit der Bedingung Auto zeigt CSS über :has(), ohne Skript. Gespeichert wird per
 * Formular (section grid_plan).
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
$carLimit = !empty($vehicle['limit_from_car']) && isset($vehicle['limit']) ? (float) $vehicle['limit'] : null;
$carType = (string) ($plan['car_below_type'] ?? 'soc');
$carNow = implode(' · ', array_filter([
    $vehicle['soc'] === null ? '' : pct((float) $vehicle['soc']),
    $vehicle['range_km'] === null ? '' : with_unit((float) $vehicle['range_km'], 0, 'km'),
]));
$now = time();
$carKw = GridPlans::carKw($snap['cfg'], store());
$learned = GridPlans::learned(store());
$power = ChargePlan::powerKw($snap['cfg']['charge'], $carKw);
$fixed = is_numeric($snap['cfg']['vehicle']['charge_kw'] ?? null);
$id = static fn (string $name): string => 'f-' . $px . '-' . $name;
// Für components/plan.js: je Wochentag die nächste Mitternacht (heute eingeschlossen), die Sonnenprognose je Stunde
// für gut eine Woche, Preis, Leistung und Werte des Autos; daraus rechnet das Sheet Start, Kosten und Sonne je Tag.
$tz = new DateTimeZone('Europe/Berlin');
$midnight = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
$dayStarts = [];
for ($i = 0; $i < 7; $i++) {
    $d = $midnight->modify('+' . $i . ' day');
    $dayStarts[(int) $d->format('N')] = $d->getTimestamp();
}
$sunHours = [];
foreach (Forecast::fromRadiation((new WeatherFeed(store()))->hours($midnight->getTimestamp(), $midnight->getTimestamp() + 15 * 86400), (array) $snap['cfg']['plant']) as $row) {
    $sunHours[] = [(int) $row['t'], round((float) $row['kw'], 2)];
}
$tariff = new Tariff(store());
$dynamic = ($tariff->tariffAt($now)['mode'] ?? 'static') === 'dynamic';
$prices = [];
for ($q = intdiv($now, 900) * 900; $q < $now + 86400; $q += 900) {
    $ct = $tariff->importCt($q);
    if ($ct !== null) {
        $prices[] = $ct;
    }
}
$priceCt = $prices === [] ? null : ($dynamic ? array_sum($prices) / count($prices) : $prices[0]);
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack target-form plan-form"<?= ui_attrs([
    'data-plan-form' => true,
    'data-now' => $now,
    'data-days' => ui_json($dayStarts),
    'data-sun' => ui_json($sunHours),
    'data-power' => round($power, 2),
    'data-price' => $priceCt === null ? null : round($priceCt, 2),
    'data-dynamic' => $dynamic ? '1' : null,
    'data-soc' => $vehicle['soc'] ?? null,
    'data-limit' => $carLimit,
    'data-capacity' => $vehicle['capacity_kwh'] ?? null,
    'data-range' => $vehicle['range_km'] ?? null,
    'data-consumption' => $vehicle['consumption_kwh'] ?? null,
]) ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="grid_plan"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="id" value="<?= e((string) ($plan['id'] ?? '')) ?>">
  <p class="body-sm muted">Steckt das Auto, lädt es an diesen Tagen so aus dem Netz, dass es zur Uhrzeit das Ziel erreicht: mit dynamischem Tarif in den günstigsten Viertelstunden davor, mit statischem in den letzten, damit die Sonne vorher noch helfen kann.</p>
  <div class="form-rows"><?= ui_form_row('Plan aktiv', ui_toggle('on', (bool) ($plan['on'] ?? true), 'Plan aktiv', ['id' => $id('on')]), ['for' => $id('on')]) ?></div>
  <fieldset class="plan-days"><legend class="form-label">Wochentage</legend>
<?php foreach (GridPlans::dayNames() as $n => $name): ?>
    <label class="plan-day"><input type="checkbox" name="days[]" value="<?= (int) $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?> aria-label="<?= e(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][$n - 1]) ?>"><span aria-hidden="true"><?= icon('check', 'icon-16') ?><?= e($name) ?></span></label>
<?php endforeach; ?>
  </fieldset>
  <div class="form-rows"><?= ui_form_row('Fertig um', ui_input('time', $time, ['id' => $id('time'), 'type' => 'time', 'class' => 'field-num']), ['for' => $id('time')]) ?></div>
  <p class="caption plan-calc" data-plan-start aria-live="polite"></p>
  <?= ui_segment('type', 'Ziel nach', [
      'soc' => ['label' => 'Ladestand', 'short' => '%'],
      'range' => ['label' => 'Reichweite', 'short' => 'km'],
      'energy' => ['label' => 'Energie', 'short' => 'kWh'],
  ], $type, ['id' => $px . '-type']) ?>
  <div class="target-panel stack" data-panel="soc">
    <?= str_replace(['id="f-soc"', 'for="f-soc"'], ['id="' . $id('soc') . '"', 'for="' . $id('soc') . '"'], ui_range('soc', 'Bis Ladestand', $soc, 20, 100, 5, '%', $vehicle['soc'] === null ? 'Braucht den Ladestand des Autos, etwa über Tesla BLE.' : 'Jetzt ' . pct((float) $vehicle['soc']) . '.')) ?>
<?php if ($carLimit !== null): ?>
    <p class="caption muted">Das Ladelimit im Auto steht auf <?= e(pct($carLimit)) ?>. Darüber lädt das Auto nicht, und EMS ändert das Limit nicht; ein höheres Ziel endet bei <?= e(pct($carLimit)) ?>.</p>
<?php endif; ?>
  </div>
  <div class="target-panel stack" data-panel="range">
    <div class="form-rows"><?= ui_form_row('Reichweite', ui_input('km', ui_field_num($km, 0), ['id' => $id('km'), 'class' => 'field-num', 'inputmode' => 'numeric', 'autocomplete' => 'off']), ['for' => $id('km'), 'hint' => 'Kilometer, die das Auto dann anzeigt' . ($vehicle['range_km'] === null ? '; braucht die Reichweite des Autos.' : ', jetzt ' . with_unit((float) $vehicle['range_km'], 0, 'km') . '.')]) ?></div>
  </div>
  <div class="target-panel stack" data-panel="energy">
    <div class="form-rows"><?= ui_form_row('Energie', ui_input('kwh', ui_field_num($kwhValue, 1), ['id' => $id('kwh'), 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => $id('kwh'), 'hint' => 'kWh seit dem Anstecken.']) ?></div>
  </div>
  <p class="body-sm plan-calc plan-cost" data-plan-cost aria-live="polite"></p>
  <?= ui_divider('Nur wenn') ?>
  <p class="caption muted">Geprüft, sobald das Auto steckt und der Plan in den nächsten 24 Stunden dran ist. Gelten dann alle eingeschalteten Bedingungen, lädt der Plan bis zum Ziel, auch wenn das Auto dabei über seine Grenze kommt.</p>
  <div class="plan-conds">
    <div class="plan-cond">
      <div class="form-rows"><?= ui_form_row('Hausspeicher unter', ui_toggle('soc_below_on', ($plan['soc_below'] ?? null) !== null, 'Bedingung Hausspeicher', ['id' => $id('soc-on')]), ['for' => $id('soc-on'), 'hint' => 'Jetzt ' . ($batterySoc === null ? 'unbekannt' : pct($batterySoc)) . '. Ist er voller, lädt der Plan nicht.']) ?></div>
      <div class="form-rows plan-cond-value"><?= ui_form_row('Grenze in %', ui_input('soc_below', ui_field_num((float) ($plan['soc_below'] ?? 50), 0), ['id' => $id('soc-below'), 'class' => 'field-num', 'inputmode' => 'numeric', 'autocomplete' => 'off']), ['for' => $id('soc-below')]) ?></div>
    </div>
    <div class="plan-cond">
      <div class="form-rows"><?= ui_form_row('Sonne unter', ui_toggle('pv_below_on', ($plan['pv_below'] ?? null) !== null, 'Bedingung Sonne', ['id' => $id('pv-on')]), ['for' => $id('pv-on'), 'hint' => 'Prognose des Tages bis zur Uhrzeit. Kommt mehr, lädt der Plan an diesem Tag nicht aus dem Netz.']) ?></div>
      <div class="form-rows plan-cond-value"><?= ui_form_row('Grenze in kWh', ui_input('pv_below', ui_field_num((float) ($plan['pv_below'] ?? 10), 0), ['id' => $id('pv-below'), 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => $id('pv-below')]) ?></div>
      <ul class="plan-sun caption" data-plan-sun role="list" aria-label="Sonnenprognose je gewähltem Tag"></ul>
    </div>
    <div class="plan-cond">
      <div class="form-rows"><?= ui_form_row('Auto unter', ui_toggle('car_below_on', ($plan['car_below'] ?? null) !== null, 'Bedingung Auto', ['id' => $id('car-on')]), ['for' => $id('car-on'), 'hint' => ($carNow === '' ? 'Ladestand oder Reichweite des Autos, braucht dessen Entität' : 'Ladestand oder Reichweite, jetzt ' . $carNow) . '. Hat es mehr, lädt der Plan nicht.']) ?></div>
      <div class="plan-cond-value stack-tight">
        <?= ui_segment('car_below_type', 'Auto unter', ['soc' => 'Ladestand', 'range' => 'Reichweite'], $carType, ['id' => $px . '-car-type', 'compact' => true]) ?>
        <div class="form-rows"><?= ui_form_row('Grenze', '<span class="ha-link">' . ui_input('car_below', ui_field_num((float) ($plan['car_below'] ?? ($carType === 'range' ? 150 : 40)), 0), ['id' => $id('car-below'), 'class' => 'field-num', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'data-plan-car' => true]) . '<span class="plan-unit" data-unit="soc">%</span><span class="plan-unit" data-unit="range">km</span></span>', ['for' => $id('car-below')]) ?></div>
      </div>
    </div>
  </div>
  <p class="caption muted">EMS rechnet mit <?= e(kw($power)) ?> Ladeleistung<?= $fixed ? ' (fest unter Fahrzeug)' : ($learned !== null ? ' (gelernt beim Netzladen)' : ' (Höchststrom × Phasen)') ?>. Ändern unter <?= ui_inline('Fahrzeug', ['data-open-dialog' => 'cp-vehicle']) ?>.</p>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit" name="do" value="save"><?= icon('calendar', 'icon-16') ?>Plan speichern</button>
<?php if ($plan !== null): ?>
    <button class="btn btn-secondary" type="submit" name="do" value="delete">Löschen</button>
<?php endif; ?>
  </div>
</form>
