<?php
declare(strict_types=1);
/**
 * Einstellungen → Speicher: Grenzen fürs Auto (wie auf der Seite Speicher), der Backup-Puffer mit Standardwert (beim
 * Netzladen angehoben), Laden aus dem Netz in Ladefenstern (BatteryPlan) und die Entladeleistung für die Aufteilung.
 * Jede Karte speichert nur ihre Felder.
 * @var array $cfg
 * @var array $snap
 * @var array $suggest
 * @var string $back
 */
$b = $cfg['battery_strategy'];
$z = zone_thresholds((float) $b['priority_soc'], (float) $b['car_buffer_soc'], (float) $b['car_auto_soc']);
$settings = Reserve::settings($cfg);
$reserve = $snap['reserve'];
$open = static fn (string $section): string => '<form method="post" action="' . e(url('/einstellungen')) . '" class="stack">' . csrf_field()
    . '<input type="hidden" name="section" value="' . e($section) . '"><input type="hidden" name="back" value="' . e($back) . '">';
$numberField = static fn (string $name, ?float $value, int $decimals, array $attrs = []): string => ui_input($name, $value === null ? '' : ui_field_num($value, $decimals), $attrs + ['class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '–']);
?>
<section class="card stack" aria-labelledby="zones-title">
  <h3 class="card-title" id="zones-title">Grenzen fürs Auto</h3>
  <p class="body-sm muted">Dieselben Grenzen wie auf der Seite <?= ui_inline('Speicher', ['href' => url('/speicher')]) ?>. Sie rasten auf 5 % und bleiben geordnet. Eine Grenze auf 100 % schaltet Stützung oder Start ohne Sonne ab.</p>
  <?= $open('battery') ?>
  <?= ui_range('priority_soc', 'Haus bis', (float) $z['priority_soc'], 0, 100, 5, '%', 'Bis hierhin geht Sonnenüberschuss zuerst in den Speicher.') ?>
  <?= ui_range('car_buffer_soc', 'Stützung ab', (float) $z['car_buffer_soc'], 0, 100, 5, '%', 'Ab hier darf der Speicher eine laufende Ladung halten, auch wenn die Sonne nachlässt.') ?>
  <?= ui_range('car_auto_soc', 'Start ohne Sonne ab', (float) $z['car_auto_soc'], 0, 100, 5, '%', 'Ab hier startet die Ladung auch ohne Sonne und endet bei der Stützung.') ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Grenzen speichern</button></div>
  </form>
</section>
<section class="card stack" aria-labelledby="reserve-title">
  <h3 class="card-title" id="reserve-title">Backup-Puffer</h3>
  <p class="body-sm muted">Unter dem Backup-Puffer gibt der Speicher nichts ab, die Energie bleibt für einen Stromausfall. Den Standardwert setzt die App beim Speichern. Mit Netzladen (jetzt oder im Ladefenster) hebt sie den Puffer auf den Ladestand, solange das Auto lädt, und setzt ihn danach zurück; in der Zeit versorgt der Speicher auch das Haus nicht. Im Modus Schnell gibt der Speicher bis zum Standardwert mit ab.</p>
<?php if ($reserve['error']): ?>
  <?= ui_notice('triangle-alert', e((string) $reserve['error']), 'warn') ?>
<?php endif; ?>
  <?= $open('reserve') ?>
  <?= ui_entity_field('battery_reserve', 'Entität des Puffers', (string) ($cfg['mapping']['battery_reserve'] ?? ''), 'Eine number-Entität in Prozent. Bei der sonnenBatterie heißt sie number.sonnenbatterie_…_battery_reserve und erscheint erst, wenn im Speicher der Schreibzugriff der JSON-API an ist; eine eigene Template-Number mit rest_command, etwa number.sonnenbatterie_backup_puffer, geht genauso.', $suggest['battery_reserve'] ?? '') ?>
  <div class="form-rows">
    <?= ui_form_row('Standardwert', $numberField('backup_soc', $settings['default'] === null ? null : (float) $settings['default'], 0, ['inputmode' => 'numeric', 'data-warn-below' => Reserve::SAFE_SOC, 'data-warn-note' => 'backup-warn']), ['for' => 'f-backup_soc', 'hint' => 'Prozent. Darauf setzt die App den Puffer nach dem Netzladen zurück.']) ?>
  </div>
  <div id="backup-warn"<?= $settings['default'] !== null && $settings['default'] < Reserve::SAFE_SOC ? '' : ' hidden' ?>>
    <?= ui_notice('triangle-alert', 'Unter ' . e(pct(Reserve::SAFE_SOC)) . ' bleibt bei einem Stromausfall kaum Reserve im Speicher. Zur Sicherheit mindestens ' . e(pct(Reserve::SAFE_SOC)) . ' einstellen.', 'warn') ?>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Puffer speichern</button></div>
  </form>
  <dl class="kv">
    <div><dt>Stand</dt><dd><?= e((string) $reserve['text']) ?></dd></div>
<?php if ($reserve['mapped']): ?>
    <div><dt>Home Assistant meldet</dt><dd><?= e($reserve['reported'] === null ? 'keinen Wert' : pct((float) $reserve['reported'])) ?><span class="sub">Bei der sonnenBatterie zeigt die Entität erst nach dem ersten Setzen den echten Wert.</span></dd></div>
<?php endif; ?>
  </dl>
<?php if ($reserve['log']): ?>
  <?= ui_divider('Zuletzt') ?>
  <ul class="plain-list stack-tight body-sm" role="list">
<?php foreach ($reserve['log'] as $row): ?>
    <li><span class="num muted"><?= e($row['time']) ?></span> <?= e($row['text']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php $grid = BatteryPlan::settings($cfg); ?>
<section class="card stack" aria-labelledby="grid-charge-title">
  <h3 class="card-title" id="grid-charge-title">Laden aus dem Netz</h3>
  <p class="body-sm muted">Mit einem Energie-Boost lädt EMS den Speicher einmalig in einem Ladefenster aus dem Netz, bis zu einem Ladestand. Den Boost und die Empfehlung dazu findest du unter <?= ui_inline('Speicher → Ausblick', ['href' => url('/speicher')]) ?>; geschaltet wird nur bei aktivem EMS. Dafür stellt EMS den Betriebsmodus auf manuell und „Laden erzwingen“ auf die Ladeleistung, danach zurück auf den Betriebsmodus von vorher. Wirkungsgrad und Verschleiß gehen in die Empfehlung ein.</p>
  <?= $open('battery_grid') ?>
  <?= ui_entity_field('battery_operating_mode', 'Betriebsmodus', (string) ($cfg['mapping']['battery_operating_mode'] ?? ''), 'select mit manual und automatic, bei der sonnenBatterie select.sonnenbatterie_…_select_operating_mode. Sie erscheint erst, wenn die Integration schreiben darf (Token der JSON-API).', $suggest['battery_operating_mode'] ?? '') ?>
  <?= ui_entity_field('battery_force_charge', 'Laden erzwingen', (string) ($cfg['mapping']['battery_force_charge'] ?? ''), 'number in Watt, bei der sonnenBatterie number.sonnenbatterie_…_number_charge.', $suggest['battery_force_charge'] ?? '') ?>
  <div class="form-rows">
    <?= ui_form_row('Ladeleistung', $numberField('grid_kw', $grid['power_set'] ? $grid['power_kw'] : null, 2), ['for' => 'f-grid_kw', 'hint' => 'kW. Leer heißt die Entladeleistung, sonst 2,5 kW.']) ?>
    <?= ui_form_row('Wirkungsgrad', $numberField('grid_efficiency_pct', $grid['efficiency'] * 100, 0, ['inputmode' => 'numeric']), ['for' => 'f-grid_efficiency_pct', 'hint' => 'Prozent, Laden und Entladen zusammen. Standard 90 %.']) ?>
    <?= ui_form_row('Verschleiß', $numberField('grid_wear_ct', $grid['wear_ct'], 1), ['for' => 'f-grid_wear_ct', 'hint' => 'ct je kWh, die durch den Speicher geht. Standard 2 ct.']) ?>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>
<section class="card stack" aria-labelledby="discharge-title">
  <h3 class="card-title" id="discharge-title">Entladeleistung</h3>
  <p class="body-sm muted">Mit der höchsten Entladeleistung teilt die Regelung genau auf, was beim Laden der Speicher und was das Netz liefert. Leer heißt unbekannt; dann steht bei Schnell „erst Speicher, dann Netz“.</p>
  <?= $open('battery') ?>
  <div class="form-rows">
    <?= ui_form_row('Höchstens', $numberField('discharge_kw', $settings['discharge_kw'], 2), ['for' => 'f-discharge_kw', 'hint' => 'kW, laut Datenblatt des Speichers.']) ?>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Leistung speichern</button></div>
  </form>
</section>
