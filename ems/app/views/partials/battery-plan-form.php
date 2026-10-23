<?php
declare(strict_types=1);
/**
 * Ladeplan des Speichers (BatteryPlans): Wann (im günstigen Fenster oder bis zu einer Uhrzeit), an Wochentagen (für
 * einen neuen Plan keine vorgewählt) oder einmalig, wie viel (auf einen Ladestand oder nach Bedarf mit Obergrenze),
 * im Fenster spät oder sofort ab Beginn, optional nur unter einem Ladestand. Was nicht zur Wahl passt (Uhrzeit,
 * Zeitpunkt, Wochentage bei „einmalig“), blendet CSS über :has() aus. components/battery-plan.js rechnet beim Tippen das
 * nächste Mal, Start und Kosten. Gespeichert wird per Formular (section battery_plan).
 * @var ?array $plan gespeicherter Plan, null für einen neuen
 * @var array $snap
 * @var string $back
 * @var string $px Präfix der IDs, je Plan eigen
 */
$plan ??= null;
$values = $snap['values'];
$soc = is_numeric($values['battery_soc'] ?? null) ? (float) $values['battery_soc'] : null;
$capacity = BatteryPlans::capacity($values);
$settings = BatteryPlan::settings($snap['cfg']);
$now = time();
$tariff = new Tariff(store());
$sheet = GridPlans::sheetData(store(), (array) $snap['cfg']['plant'], 'battery', $now);
$tz = new DateTimeZone('Europe/Berlin');
// Die günstigen Fenster mit dem Wochentag ihres Beginns, für das nächste Mal im Sheet.
$windows = array_map(static fn (array $w): array => [$w['start'], $w['end'], (int) (new DateTimeImmutable('@' . $w['start']))->setTimezone($tz)->format('N')], BatteryPlans::windows(store(), $tariff, $now));
$id = static fn (string $name): string => 'f-' . $px . '-' . $name;
$range = static fn (string $name, string $label, float $value, float $min, string $hint = ''): string => str_replace(
    ['id="f-' . $name . '"', 'for="f-' . $name . '"'],
    ['id="' . $id($name) . '"', 'for="' . $id($name) . '"'],
    ui_range($name, $label, $value, $min, 100, 5, '%', $hint)
);
$dynamic = $sheet['dynamic'];
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack plan-form bp-form"<?= ui_attrs([
    'data-battery-plan' => true,
    'data-now' => $now,
    'data-days' => ui_json($sheet['days']),
    'data-windows' => ui_json($windows),
    'data-no-window' => $dynamic ? 'Gerade ist kein passendes Ladefenster bekannt; die Fenster für morgen kommen gegen 13 Uhr.' : 'An den gewählten Tagen gibt es kein Zeitfenster für den Speicher; eintragen unter Einstellungen → Tarif.',
    'data-power' => round($settings['power_kw'], 2),
    'data-eta' => round($settings['efficiency'], 3),
    'data-price' => $sheet['price'],
    'data-dynamic' => $dynamic ? '1' : null,
    'data-window' => $sheet['window'] ? '1' : null,
    'data-soc' => $soc,
    'data-capacity' => $capacity === null ? null : round($capacity, 2),
]) ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="battery_plan"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="id" value="<?= e((string) ($plan['id'] ?? '')) ?>">
  <div class="form-rows"><?= ui_form_row('Plan aktiv', ui_toggle('on', (bool) ($plan['on'] ?? true), 'Plan aktiv', ['id' => $id('on')]), ['for' => $id('on')]) ?></div>
  <?= ui_segment('when', 'Wann', ['window' => 'Im günstigen Fenster', 'time' => 'Bis Uhrzeit'], (string) ($plan['when'] ?? 'window'), ['id' => $px . '-when']) ?>
  <p class="caption muted" data-bp="window"><?= e(BatteryPlans::sourceText($tariff, $now)) ?></p>
  <div class="form-rows" data-bp="time"><?= ui_form_row('Fertig um', ui_input('time', (string) ($plan['time'] ?? '06:00'), ['id' => $id('time'), 'type' => 'time', 'class' => 'field-num']), ['for' => $id('time')]) ?></div>
  <div class="form-rows"><?= ui_form_row('Einmalig', ui_toggle('once', (bool) ($plan['once'] ?? false), 'Einmalig', ['id' => $id('once')]), ['for' => $id('once'), 'hint' => 'Lädt nur beim nächsten Mal, danach verschwindet der Plan.']) ?></div>
  <fieldset class="plan-days" data-bp="days"><legend class="form-label">Wochentage</legend>
<?php foreach (GridPlans::dayNames() as $n => $name): ?>
    <label class="plan-day"><input type="checkbox" name="days[]" value="<?= (int) $n ?>"<?= in_array($n, (array) ($plan['days'] ?? []), true) ? ' checked' : '' ?> aria-label="<?= e(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][$n - 1]) ?>"><span aria-hidden="true"><?= icon('check', 'icon-16') ?><?= e($name) ?></span></label>
<?php endforeach; ?>
  </fieldset>
  <?= ui_segment('amount', 'Wie viel', ['soc' => 'Auf Ladestand', 'need' => 'Nach Bedarf'], (string) ($plan['amount'] ?? 'soc'), ['id' => $px . '-amount']) ?>
  <?= $range('target_soc', 'Ladestand bis', (float) ($plan['target_soc'] ?? 100), 10, $soc === null ? 'Braucht den Ladestand des Speichers.' : 'Jetzt ' . pct($soc) . ($capacity === null ? '.' : ' von ' . kwh($capacity, 1) . '.')) ?>
  <p class="caption muted" data-bp="need">Nach Bedarf lädt EMS so viel, wie das Haus bis einen Tag nach dem Fenster mit Sonne und Speicher braucht, und nur, wenn das nach Verlust und Verschleiß günstiger ist als später aus dem Netz; der Regler ist die Obergrenze. Im Winter heißt das oft fast voll, im Sommer oft gar nicht.</p>
  <div class="stack-tight" data-bp="window">
    <?= ui_segment('start', 'Zeitpunkt im Fenster', ['late' => 'So spät wie möglich', 'early' => 'Sofort ab Beginn'], (string) ($plan['start'] ?? 'late'), ['id' => $px . '-start']) ?>
    <p class="caption muted">So spät wie möglich: fertig zum Ende des Fensters, der Speicher steht kürzer voll<?= $dynamic ? ', und es nimmt die günstigsten Viertelstunden' : '' ?>. Sofort ab Beginn: lädt gleich, wenn das Fenster anfängt.</p>
  </div>
  <p class="caption plan-calc" data-plan-start aria-live="polite"></p>
  <p class="body-sm plan-calc plan-cost" data-plan-cost aria-live="polite"></p>
  <?= ui_divider('Nur wenn') ?>
  <div class="plan-conds">
    <div class="plan-cond">
      <div class="form-rows"><?= ui_form_row('Speicher unter', ui_toggle('soc_below_on', ($plan['soc_below'] ?? null) !== null, 'Bedingung Speicher', ['id' => $id('soc-on')]), ['for' => $id('soc-on'), 'hint' => 'Jetzt ' . ($soc === null ? 'unbekannt' : pct($soc)) . '. Ist er voller, lädt der Plan diesmal nicht. Geprüft, sobald der Plan in den nächsten 24 Stunden dran ist.']) ?></div>
      <div class="plan-cond-value stack-tight"><?= $range('soc_below', 'Ladestand unter', (float) ($plan['soc_below'] ?? 30), 5) ?></div>
    </div>
  </div>
  <p class="caption muted">Geladen wird nur, wenn EMS regelt, mit <?= e(kw($settings['power_kw'])) ?> aus dem Netz und <?= e(pct($settings['efficiency'] * 100)) ?> Wirkungsgrad. Ändern unter <?= ui_inline('Einstellungen → Speicher', ['href' => url('/einstellungen/speicher')]) ?>.</p>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit" name="do" value="save"><?= icon('calendar', 'icon-16') ?>Plan speichern</button>
<?php if ($plan !== null): ?>
    <button class="btn btn-secondary" type="submit" name="do" value="delete">Löschen</button>
<?php endif; ?>
  </div>
</form>
