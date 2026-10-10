<?php
declare(strict_types=1);
/**
 * Einstellungen → Ladepunkt: Name, Ladeparameter und die Entitäten der Wallbox. Die Modi erklärt die Karte Regelung auf „Laden“.
 * @var array $cfg
 * @var array $suggest
 * @var string $back
 */
$m = $cfg['mapping'];
?>
<section class="card stack" aria-labelledby="cp-name-title">
  <h3 class="card-title" id="cp-name-title">Name</h3>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="chargepoint"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows"><?= ui_form_row('Name', ui_input('chargepoint_name', (string) $cfg['chargepoint']['name'], ['id' => 'f-cp-name', 'maxlength' => '40', 'autocomplete' => 'off']), ['for' => 'f-cp-name', 'hint' => 'Steht auf der Ladepunkt-Karte und bei jedem neuen Ladevorgang.']) ?></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Name speichern</button></div>
  </form>
</section>
<section class="card stack" aria-labelledby="cp-params-title">
  <h3 class="card-title" id="cp-params-title">Ladeparameter</h3>
  <p class="body-sm muted">Wie die Regelung mit diesen Werten gerade entscheidet, zeigt die Karte Regelung auf der Seite <?= ui_inline('Laden', ['href' => url('/')]) ?>. An die Wallbox schreibt EMS nur, wenn es oben mit dem Hauptschalter eingeschaltet ist.</p>
<?php view('partials/charge-form', ['charge' => $cfg['charge'], 'back' => $back]); ?>
</section>
<section class="card stack" aria-labelledby="cp-entities-title">
  <h3 class="card-title" id="cp-entities-title">Entitäten der Wallbox</h3>
  <p class="body-sm muted">Tippe einen Namen oder eine Entität. Die Liste sucht in Home Assistant und übernimmt die gewählte Kennung.</p>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="mapping"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?= ui_entity_field('wallbox_power', 'Ladeleistung', (string) $m['wallbox_power'], 'Watt oder Kilowatt.', $suggest['wallbox_power'] ?? '') ?>
    <?= ui_entity_field('wallbox_car', 'Fahrzeugstatus', (string) $m['wallbox_car'], 'Zum Beispiel charging oder idle.', $suggest['wallbox_car'] ?? '') ?>
    <?= ui_entity_field('wallbox_amps', 'Ladestrom (amp)', (string) $m['wallbox_amps'], 'number in Ampere. Regelt EMS, stellt es hier den Strom ein.', $suggest['wallbox_amps'] ?? '') ?>
    <?= ui_entity_field('wallbox_amps_max', 'Maximalstrom', (string) $m['wallbox_amps_max'], 'Optional, nur Anzeige.', $suggest['wallbox_amps_max'] ?? '') ?>
    <?= ui_entity_field('wallbox_phases', 'Phasenumschaltung (psm)', (string) $m['wallbox_phases'], 'select der go-e: 1 einphasig, 2 dreiphasig, 0 automatisch.', $suggest['wallbox_phases'] ?? '') ?>
    <?= ui_entity_field('wallbox_force', 'Zwangszustand (frc)', (string) $m['wallbox_force'], 'select der go-e: 1 sperrt, 2 gibt frei, 0 neutral. Ohne ihn kann EMS nicht regeln.', $suggest['wallbox_force'] ?? '') ?>
    <?= ui_entity_field('wallbox_energy', 'Lademenge seit Anstecken, optional', (string) ($m['wallbox_energy'] ?? ''), 'Wh oder kWh der Wallbox (go-e „wh“). Dann zählen Ladeziel und Übersicht mit ihr.', $suggest['wallbox_energy'] ?? '') ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Entitäten speichern</button></div>
  </form>
</section>
