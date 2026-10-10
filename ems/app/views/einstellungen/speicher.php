<?php
declare(strict_types=1);
/**
 * Einstellungen → Speicher: Grenzen fürs Auto (wie auf der Seite Speicher), der Backup-Puffer mit Standardwert und
 * Schonen beim Netzladen, und die Entladeleistung für die Aufteilung. Jede Karte speichert nur ihre Felder.
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
  <p class="body-sm muted">Unter dem Backup-Puffer gibt der Speicher nichts ab, die Energie bleibt für einen Stromausfall. Den Standardwert setzt die App beim Speichern. Ist „Speicher schonen“ an, hebt sie den Puffer beim Netzladen auf den Ladestand, solange das Auto lädt, und setzt ihn danach zurück. In der Zeit versorgt der Speicher auch das Haus nicht.</p>
<?php if ($reserve['error']): ?>
  <?= ui_notice('triangle-alert', e((string) $reserve['error']), 'warn') ?>
<?php endif; ?>
  <?= $open('reserve') ?>
  <?= ui_entity_field('battery_reserve', 'Entität des Puffers', (string) ($cfg['mapping']['battery_reserve'] ?? ''), 'Eine number-Entität in Prozent. Bei der sonnenBatterie heißt sie number.sonnenbatterie_…_battery_reserve und erscheint erst, wenn im Speicher der Schreibzugriff der JSON-API an ist.', $suggest['battery_reserve'] ?? '') ?>
  <div class="form-rows">
    <?= ui_form_row('Standardwert', $numberField('backup_soc', $settings['default'] === null ? null : (float) $settings['default'], 0, ['inputmode' => 'numeric']), ['for' => 'f-backup_soc', 'hint' => 'Prozent. Darauf setzt die App den Puffer nach dem Netzladen zurück.']) ?>
    <?= ui_form_row('Speicher schonen', ui_toggle('grid_protect', $settings['protect'], 'Beim Netzladen den Backup-Puffer anheben'), ['hint' => 'Beim Netzladen kommt dann alles, was die Sonne nicht schafft, aus dem Netz.']) ?>
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
<section class="card stack" aria-labelledby="discharge-title">
  <h3 class="card-title" id="discharge-title">Entladeleistung</h3>
  <p class="body-sm muted">Mit der höchsten Entladeleistung teilt die Regelung genau auf, was beim Laden der Speicher und was das Netz liefert. Leer heißt unbekannt; dann steht beim Netzladen „erst Speicher, dann Netz“.</p>
  <?= $open('battery') ?>
  <div class="form-rows">
    <?= ui_form_row('Höchstens', $numberField('discharge_kw', $settings['discharge_kw'], 2), ['for' => 'f-discharge_kw', 'hint' => 'kW, laut Datenblatt des Speichers.']) ?>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Leistung speichern</button></div>
  </form>
</section>
