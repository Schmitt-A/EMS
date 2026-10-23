<?php
declare(strict_types=1);
/**
 * Einstellungen → Tarif & CO₂: welcher Tarif aktiv ist und nur dessen Felder (CSS über :has() am Segment): der statische
 * (Arbeitspreis von Hand oder aus einer Entität, Grundpreis, Zeitfenster mit anderem Preis fürs Auto und/oder den
 * Speicher) oder der dynamische (Börse plus Netzentgelt, Steuern und Umlagen, Anbieter, MwSt, Grundpreis, optional §14a
 * Modul 3); dazu Einspeisung und CO₂, alles gültig ab einem Datum; darunter der Verlauf. Die Felder des anderen Tarifs
 * werden mitgeschickt und behalten ihre Werte, damit „Welcher Tarif lohnt sich?“ beide rechnen kann. Siehe Tariff.
 * @var array $cfg
 * @var string $back
 * @var array $suggest
 */
$tariff = new Tariff(store());
$now = time();
$history = $tariff->history();
$t = $tariff->tariffAt($now);
$price = $tariff->now($now);
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$field = static fn (string $name, ?float $value, int $decimals, array $attrs = []): string => ui_input($name, $value === null ? '' : ui_field_num($value, $decimals), $attrs + ['class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '–']);
$row = static fn (string $name, string $label, ?float $value, int $decimals, string $hint, array $attrs = []): string => ui_form_row($label, $field($name, $value, $decimals, $attrs), ['for' => 'f-' . $name, 'hint' => $hint]);
$windows = $t['dyn_windows'];
$static = $t['windows'];
$staticEntity = (string) ($cfg['mapping']['price_static'] ?? '');
// Zeitfenster des statischen Tarifs: die gespeicherten und ein leeres zum Anlegen.
$slots = array_merge($static, count($static) < Tariff::WINDOWS_MAX ? [null] : []);
$dayNames = GridPlans::dayNames();
$longDays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
$surcharge = Tariff::surchargeCt($t, $now);
$dateText = static fn (string $day): string => $day === Tariff::ORIGIN ? 'Beginn' : date('d.m.Y', (int) strtotime($day . ' 12:00:00'));
$summary = static function (array $s): string {
    $static = 'statisch ' . ct((float) $s['import_ct']) . ($s['base_eur'] !== null ? ' + ' . euro((float) $s['base_eur']) . '/Monat' : '')
        . ($s['windows'] ? ', ' . count($s['windows']) . ' Zeitfenster' : '');
    $dynamic = 'dynamisch Börse + ' . ct(Tariff::surchargeCt($s, time())) . ($s['dyn_base_eur'] !== null ? ' + ' . euro((float) $s['dyn_base_eur']) . '/Monat' : '');
    return ($s['mode'] === 'dynamic' ? $dynamic . ' (aktiv), ' . $static : $static . ' (aktiv), ' . $dynamic) . ' · Einspeisung ' . ct((float) $s['export_ct']);
};
?>
<section class="card stack" aria-labelledby="tariff-title">
  <h3 class="card-title" id="tariff-title">Tarif</h3>
  <p class="body-sm muted">Aktiv ist der Tarif, nach dem du bezahlst; hier stehen nur seine Preise. Ändert sich ein Preis, trägst du ihn mit dem Datum ein, ab dem er gilt. Frühere Zeiten rechnen weiter mit dem alten Preis.</p>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack tariff-form" data-tariff-form>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="tariffs"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?= ui_segment('mode', 'Aktiver Tarif', ['static' => 'Statisch', 'dynamic' => 'Dynamisch'], (string) $t['mode'], ['id' => 'tariff-mode']) ?>

    <div class="stack" data-tariff-panel="static">
      <div class="form-rows">
        <?= $row('import_ct', 'Arbeitspreis', (float) $t['import_ct'], 2, $staticEntity === '' ? 'ct/kWh brutto, fest.' : 'ct/kWh brutto, kommt aus der Entität darunter; der Recorder übernimmt eine Änderung ab dem Tag, an dem sie kommt.', $staticEntity === '' ? [] : ['readonly' => true]) ?>
        <?= $row('base_eur', 'Grundpreis', $t['base_eur'], 2, '€ im Monat brutto.') ?>
      </div>
      <?= ui_entity_field('price_static', 'Arbeitspreis aus Entität, optional', $staticEntity, 'Ein Sensor mit dem Arbeitspreis in ct/kWh oder €/kWh, etwa aus der Integration deines Anbieters. Leer trägst du ihn oben von Hand ein.', $suggest['price_static'] ?? '') ?>
      <div class="stack-tight">
        <div class="form-rows"><?= ui_form_row('Zeitfenster mit anderem Preis', ui_toggle('swin_on', (bool) $static, 'Zeitfenster mit anderem Preis nutzen', ['id' => 'f-swin-on']), ['for' => 'f-swin-on', 'hint' => 'Etwa zeitvariables Netzentgelt nach §14a oder ein Autostrom-Tarif: in diesen Zeiten kostet das Laden von Auto oder Speicher weniger.']) ?></div>
        <div class="stack tw-list" data-tw-list>
          <p class="body-sm muted">Je Fenster: Zeit, Wochentage, wofür es gilt und der Preis, als Abzug vom Arbeitspreis (etwa 12 ct weniger) oder als Festpreis. Gilt für Auto und Speicher etwas anderes, legst du zwei Fenster mit derselben Zeit an. Ein Fenster darf über Mitternacht gehen und zählt dann zum Tag, an dem es beginnt.</p>
<?php foreach ($slots as $i => $w): $fresh = $w === null; $px = 'f-swin-' . $i; ?>
<?php if ($fresh): ?>
          <details class="tw-add"<?= $static === [] ? ' open' : '' ?>>
            <summary><?= icon('plus', 'icon-16') ?><span><?= $static === [] ? 'Zeitfenster anlegen' : 'Weiteres Zeitfenster' ?></span></summary>
<?php endif; ?>
          <fieldset class="tw-card" data-tw>
            <legend><?= e($fresh ? 'Neues Zeitfenster' : 'Zeitfenster ' . ($i + 1)) ?></legend>
            <div class="tw-times">
              <label for="<?= e($px) ?>-from">Von<input class="field field-num" type="time" id="<?= e($px) ?>-from" name="swin[<?= $i ?>][from]" value="<?= e((string) ($w['from'] ?? '')) ?>"></label>
              <label for="<?= e($px) ?>-to">Bis<input class="field field-num" type="time" id="<?= e($px) ?>-to" name="swin[<?= $i ?>][to]" value="<?= e((string) ($w['to'] ?? '')) ?>"></label>
            </div>
            <fieldset class="plan-days"><legend class="form-label">Wochentage</legend>
<?php foreach ($dayNames as $n => $name): ?>
              <label class="plan-day"><input type="checkbox" name="swin[<?= $i ?>][days][]" value="<?= (int) $n ?>"<?= $fresh || $w['days'] === [] || in_array($n, $w['days'], true) ? ' checked' : '' ?> aria-label="<?= e($longDays[$n - 1]) ?>"><span aria-hidden="true"><?= icon('check', 'icon-16') ?><?= e($name) ?></span></label>
<?php endforeach; ?>
            </fieldset>
            <fieldset class="plan-days"><legend class="form-label">Gilt für</legend>
<?php foreach (Tariff::USES as $use => $label): ?>
              <label class="plan-day"><input type="checkbox" name="swin[<?= $i ?>][for][]" value="<?= e($use) ?>"<?= !$fresh && in_array($use, $w['for'], true) ? ' checked' : '' ?> data-tw-for><span><?= icon('check', 'icon-16') ?><?= e($label) ?></span></label>
<?php endforeach; ?>
            </fieldset>
            <div class="tw-price">
              <label for="<?= e($px) ?>-kind">Preis<span class="select"><select id="<?= e($px) ?>-kind" name="swin[<?= $i ?>][kind]" data-tw-kind>
                <option value="minus"<?= ($w['kind'] ?? 'minus') === 'minus' ? ' selected' : '' ?>>Abzug</option>
                <option value="price"<?= ($w['kind'] ?? '') === 'price' ? ' selected' : '' ?>>Festpreis</option>
              </select><?= icon('chevron-down', 'icon-16') ?></span></label>
              <label for="<?= e($px) ?>-ct">ct/kWh<input class="field field-num" id="<?= e($px) ?>-ct" name="swin[<?= $i ?>][ct]" inputmode="decimal" autocomplete="off" placeholder="–" value="<?= e($fresh ? '' : ui_field_num((float) $w['ct'], 2)) ?>" data-tw-ct></label>
            </div>
            <p class="caption tw-result" data-tw-result aria-live="polite"><?= e($fresh ? '' : 'Im Fenster ' . ct(Tariff::windowCt($t, $w)) . ' statt ' . ct((float) $t['import_ct']) . '.') ?></p>
<?php if (!$fresh): ?>
            <label class="tw-delete"><input type="checkbox" name="swin[<?= $i ?>][delete]" value="1"> Beim Speichern entfernen</label>
<?php endif; ?>
          </fieldset>
<?php if ($fresh): ?>
          </details>
<?php endif; ?>
<?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="stack" data-tariff-panel="dynamic">
      <p class="body-sm muted">Endpreis = (Börsenpreis + Netzentgelt + Steuern und Umlagen + Anbieteraufschlag) × (1 + MwSt). Vorbelegt sind typische Werte; die echten stehen im Preisblatt deines Netzbetreibers und Anbieters.</p>
      <div class="form-rows">
        <?= $row('dyn_grid_ct', 'Netzentgelt', (float) $t['dyn_grid_ct'], 2, 'ct/kWh netto, Arbeitspreis deines Netzbetreibers.') ?>
        <?= $row('dyn_levies_ct', 'Steuern und Umlagen', (float) $t['dyn_levies_ct'], 2, 'ct/kWh netto: Stromsteuer 2,05, Konzessionsabgabe 1,32–2,39, KWKG-, Offshore- und §19-Umlage zusammen gut 2,9 (2026).') ?>
        <?= $row('dyn_supplier_ct', 'Anbieteraufschlag', (float) $t['dyn_supplier_ct'], 2, 'ct/kWh netto.') ?>
        <?= $row('dyn_vat_pct', 'MwSt', (float) $t['dyn_vat_pct'], 0, '%.', ['inputmode' => 'numeric']) ?>
        <?= $row('dyn_base_eur', 'Grundpreis', $t['dyn_base_eur'], 2, '€ im Monat brutto, mit Netz-Grundpreis und Messstelle (Smart Meter).') ?>
      </div>
      <dl class="kv">
        <div><dt>Aufschlag auf die Börse</dt><dd><?= e(ct($surcharge)) ?><span class="sub">brutto je kWh, MwSt auf den Börsenpreis kommt dazu</span></dd></div>
<?php if ($price['spot_ct'] !== null): ?>
        <div><dt>Endpreis jetzt</dt><dd><?= e(ct((float) $price['dynamic_ct'])) ?><span class="sub">bei <?= e(ct((float) $price['spot_ct'])) ?> Börsenpreis netto</span></dd></div>
<?php endif; ?>
      </dl>
      <details class="stack-tight tariff-windows"<?= $windows ? ' open' : '' ?>>
        <summary><?= icon('chevron-down', 'icon-16') ?><span>Zeitvariables Netzentgelt (§14a Modul 3)</span></summary>
        <p class="body-sm muted">Nur, wenn du beim Netzbetreiber Modul 3 hast: In diesen Zeiten gilt ein anderes Netzentgelt (netto), sonst das oben. Ein Fenster darf über Mitternacht gehen.</p>
        <div class="form-rows">
          <?= ui_form_row('Modul 3', ui_toggle('modul3', (bool) $windows, 'Zeitvariables Netzentgelt nutzen')) ?>
        </div>
<?php for ($i = 0; $i < 3; $i++): $w = $windows[$i] ?? null; ?>
        <fieldset class="tariff-window">
          <legend>Fenster <?= $i + 1 ?></legend>
          <label for="f-win-from-<?= $i ?>">Von</label>
          <input class="field" type="time" id="f-win-from-<?= $i ?>" name="win_from[]" value="<?= e((string) ($w['from'] ?? '')) ?>">
          <label for="f-win-to-<?= $i ?>">Bis</label>
          <input class="field" type="time" id="f-win-to-<?= $i ?>" name="win_to[]" value="<?= e((string) ($w['to'] ?? '')) ?>">
          <label for="f-win-ct-<?= $i ?>">ct/kWh</label>
          <input class="field field-num" id="f-win-ct-<?= $i ?>" name="win_ct[]" inputmode="decimal" autocomplete="off" placeholder="–" value="<?= e($w === null ? '' : ui_field_num((float) $w['ct'], 2)) ?>">
        </fieldset>
<?php endfor; ?>
      </details>
      <?= ui_entity_field('price_import', 'Preis-Entität des Anbieters, optional', (string) ($cfg['mapping']['price_import'] ?? ''), 'Mit Tibber oder Octopus: der Preis-Sensor der Integration, mit den Preisen von heute und morgen in seinen Attributen. Dann gilt im dynamischen Tarif dieser Preis; ohne ihn rechnet EMS Börse plus Aufschläge.', $suggest['price_import'] ?? '') ?>
      <p class="body-sm muted">Ein dynamischer Tarif braucht ein Smart Meter (intelligentes Messsystem) und rechnet je Viertelstunde ab.</p>
    </div>

    <?= ui_divider('Einspeisung und Strommix') ?>
    <div class="form-rows">
      <?= $row('export_ct', 'Einspeisevergütung', (float) $t['export_ct'], 2, 'ct/kWh nach EEG, fest.') ?>
      <?= $row('co2_g_kwh', 'CO₂-Faktor', (float) $t['co2_g_kwh'], 0, 'g/kWh, der deutsche Strommix liegt bei etwa 380.', ['inputmode' => 'numeric']) ?>
      <?= ui_form_row('Gilt ab', ui_input('valid_from', $today, ['type' => 'date', 'max' => '2100-12-31']), ['for' => 'f-valid_from', 'hint' => 'Für einen Preis, der schon länger gilt, ein frühes Datum wählen.']) ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Tarif speichern</button></div>
  </form>
  <p class="body-sm muted">Netzstrom im Auto kostet den Bezugspreis, im statischen Tarif in seinen Zeitfenstern deren Preis; Sonnenstrom kostet die entgangene Einspeisevergütung.</p>
</section>
<section class="card stack" aria-labelledby="tariff-history-title">
  <h3 class="card-title" id="tariff-history-title">Preise nach Datum</h3>
  <ul class="plain-list stack-tight body-sm tariff-history" role="list">
<?php foreach (array_reverse($history) as $entry): ?>
    <li>
      <span><strong>ab <?= e($dateText($entry['from'])) ?></strong> <?= e($summary($entry['tariffs'])) ?></span>
<?php if (count($history) > 1): ?>
      <form method="post" action="<?= e(url('/einstellungen')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="tariff_delete"><input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="from" value="<?= e($entry['from']) ?>">
        <button class="btn btn-secondary" type="submit" aria-label="<?= e('Preis ab ' . $dateText($entry['from']) . ' löschen') ?>"><?= icon('trash-2', 'icon-16') ?>Löschen</button>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
  <p class="caption muted"><?= e(MarketPrices::SOURCE) ?>.</p>
</section>
