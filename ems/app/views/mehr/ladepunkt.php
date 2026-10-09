<?php
declare(strict_types=1);
/**
 * Mehr → Ladepunkt: Name, Ladeparameter, die Modi und die Entitäten der Wallbox.
 * @var array $cfg
 * @var array $suggest
 * @var string $back
 */
$m = $cfg['mapping'];
?>
<section class="card stack" aria-labelledby="cp-name-title">
  <h3 class="card-title" id="cp-name-title">Name</h3>
  <form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="chargepoint"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows"><?= ui_form_row('Name', ui_input('chargepoint_name', (string) $cfg['chargepoint']['name'], ['id' => 'f-cp-name', 'maxlength' => '40', 'autocomplete' => 'off']), ['for' => 'f-cp-name', 'hint' => 'Steht auf der Ladepunkt-Karte und bei jedem neuen Ladevorgang.']) ?></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Name speichern</button></div>
  </form>
</section>
<section class="card stack" aria-labelledby="cp-params-title">
  <h3 class="card-title" id="cp-params-title">Ladeparameter</h3>
  <p class="body-sm muted">Der Vorschlag zeigt, was eine Regelung jetzt einstellen würde. Die App schreibt nichts an die Wallbox.</p>
<?php view('partials/charge-form', ['charge' => $cfg['charge'], 'back' => $back]); ?>
</section>
<section class="card stack" aria-labelledby="cp-modes-title">
  <h3 class="card-title" id="cp-modes-title">Die Modi</h3>
  <dl class="kv">
<?php foreach (ui_mode_options() as $key => $option): ?>
    <div><dt><?= e($option['label']) ?></dt><dd><?= e(Energy::modeText($key)) ?></dd></div>
<?php endforeach; ?>
  </dl>
  <p class="body-sm muted">Den Modus wählst du auf der Seite <?= ui_inline('Laden', ['href' => url('/')]) ?>.</p>
</section>
<section class="card stack" aria-labelledby="cp-entities-title">
  <h3 class="card-title" id="cp-entities-title">Entitäten der Wallbox</h3>
  <p class="body-sm muted">Tippe einen Namen oder eine Entität. Die Liste sucht in Home Assistant und übernimmt die gewählte Kennung.</p>
  <form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="mapping"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?= ui_entity_field('wallbox_power', 'Ladeleistung', (string) $m['wallbox_power'], 'Watt oder Kilowatt.', $suggest['wallbox_power'] ?? '') ?>
    <?= ui_entity_field('wallbox_car', 'Fahrzeugstatus', (string) $m['wallbox_car'], 'Zum Beispiel charging oder idle.', $suggest['wallbox_car'] ?? '') ?>
    <?= ui_entity_field('wallbox_amps', 'Gemeldeter Strom', (string) $m['wallbox_amps'], 'Ampere, nur Anzeige.', $suggest['wallbox_amps'] ?? '') ?>
    <?= ui_entity_field('wallbox_amps_max', 'Maximalstrom', (string) $m['wallbox_amps_max'], 'Optional.', $suggest['wallbox_amps_max'] ?? '') ?>
    <?= ui_entity_field('wallbox_phases', 'Gemeldete Phasen', (string) $m['wallbox_phases'], '1-phasig, 3-phasig oder automatisch.', $suggest['wallbox_phases'] ?? '') ?>
    <?= ui_entity_field('wallbox_force', 'Zwangszustand', (string) $m['wallbox_force'], 'Optional, nur Anzeige.', $suggest['wallbox_force'] ?? '') ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Entitäten speichern</button></div>
  </form>
</section>
