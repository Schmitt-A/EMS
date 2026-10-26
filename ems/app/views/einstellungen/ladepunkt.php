<?php
declare(strict_types=1);
/**
 * Einstellungen → Ladepunkt: Name, Ladeparameter, die Entitäten der Wallbox und ob ein Stromanbieter (etwa Octopus
 * Intelligent) in einem Zeitraum das Laden plant (ProviderControl). Die Modi erklärt die Karte Regelung auf „Laden“.
 * @var array $cfg
 * @var array $suggest
 * @var string $back
 */
$m = $cfg['mapping'];
$provider = ProviderControl::settings($cfg);
$providerTariff = new Tariff(store());
$providerView = ProviderControl::view($cfg, $providerTariff, time());
$providerState = (new ProviderControl(store(), ha()))->state();
$carWindows = array_values(array_filter((array) $providerTariff->tariffAt(time())['windows'], static fn (array $w): bool => in_array('car', (array) $w['for'], true)));
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
  <p class="body-sm muted">Tippe einen Namen oder eine Entität. Die Liste sucht in Home Assistant und übernimmt die gewählte Kennung. Zum Regeln der go-e eignet sich die Integration „go-eCharger API v2“, sie schreibt über die lokale HTTP-API; über MQTT nehmen manche go-e keine Befehle an.</p>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="mapping"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?= ui_entity_field('wallbox_power', 'Ladeleistung', (string) $m['wallbox_power'], 'Watt oder Kilowatt.', $suggest['wallbox_power'] ?? '') ?>
    <?= ui_entity_field('wallbox_car', 'Fahrzeugstatus', (string) $m['wallbox_car'], 'Zum Beispiel charging, idle oder „Warte auf Fahrzeug“.', $suggest['wallbox_car'] ?? '') ?>
    <?= ui_entity_field('wallbox_amps', 'Ladestrom (amp)', (string) $m['wallbox_amps'], 'number in Ampere. Regelt EMS, stellt es hier den Strom ein.', $suggest['wallbox_amps'] ?? '') ?>
    <?= ui_entity_field('wallbox_amps_max', 'Maximalstrom', (string) $m['wallbox_amps_max'], 'Optional, nur Anzeige.', $suggest['wallbox_amps_max'] ?? '') ?>
    <?= ui_entity_field('wallbox_phases', 'Phasenumschaltung (psm)', (string) $m['wallbox_phases'], 'select der go-e: 1 einphasig, 2 dreiphasig, 0 automatisch.', $suggest['wallbox_phases'] ?? '') ?>
    <?= ui_entity_field('wallbox_force', 'Zwangszustand (frc)', (string) $m['wallbox_force'], 'select der go-e: 1 sperrt, 2 gibt frei, 0 neutral. Ohne ihn kann EMS nicht regeln.', $suggest['wallbox_force'] ?? '') ?>
    <?= ui_entity_field('wallbox_energy', 'Lademenge seit Anstecken, optional', (string) ($m['wallbox_energy'] ?? ''), 'Wh oder kWh der Wallbox (go-e „wh“). Dann zählen Ladeziel und Übersicht mit ihr.', $suggest['wallbox_energy'] ?? '') ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Entitäten speichern</button></div>
  </form>
</section>
<section class="card stack" aria-labelledby="cp-provider-title">
  <h3 class="card-title" id="cp-provider-title">Stromanbieter plant das Laden</h3>
  <p class="body-sm muted">Für Tarife, bei denen der Anbieter das Auto selbst steuert, etwa Octopus Intelligent: nachts lädt das Auto günstig, wann genau, entscheidet der Anbieter über die Schnittstelle des Autos. In diesem Zeitraum gibt EMS die Wallbox mit vollem Strom frei und schaltet „Boost Charge“ aus; der Speicher gibt dabei nichts ans Auto ab. Außerhalb schaltet EMS „Boost Charge“ ein, damit der Anbieter das Auto nicht sperrt und EMS mit Sonne regeln kann. Geschaltet wird nur bei aktivem EMS.</p>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack provider-form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="provider"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows"><?= ui_form_row('Anbieter plant', ui_toggle('provider_on', $provider['on'], 'Anbieter plant das Laden', ['id' => 'f-provider-on']), ['for' => 'f-provider-on']) ?></div>
    <?= ui_segment('provider_window', 'Zeitraum', ['tariff' => 'Zeitfenster des Tarifs', 'time' => 'Eigene Zeit'], $provider['window'], ['id' => 'provider-window']) ?>
    <p class="caption muted" data-provider="tariff"><?= e($carWindows === [] ? 'Im Tarif ist noch kein Zeitfenster fürs Auto eingetragen (Einstellungen → Tarif, statisch).' : 'Zeitfenster fürs Auto: ' . implode(', ', array_map(static fn (array $w): string => Tariff::windowLabel($w), $carWindows)) . '.') ?></p>
    <div class="form-rows" data-provider="time">
      <?= ui_form_row('Von', ui_input('provider_from', $provider['from'], ['id' => 'f-provider-from', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-provider-from']) ?>
      <?= ui_form_row('Bis', ui_input('provider_to', $provider['to'], ['id' => 'f-provider-to', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-provider-to', 'hint' => 'Ein Ende vor dem Beginn meint den nächsten Tag.']) ?>
    </div>
    <?= ui_entity_field('provider_boost', 'Boost Charge, optional', (string) ($m['provider_boost'] ?? ''), 'switch des Anbieters fürs Sofortladen, bei Octopus etwa switch.octopus_…_boost_charge. Ohne ihn gibt EMS im Zeitraum nur die Wallbox frei.', $suggest['provider_boost'] ?? '') ?>
    <?= ui_entity_field('provider_control', 'Smart Control, optional', (string) ($m['provider_control'] ?? ''), 'switch, ohne den es Boost Charge nicht gibt, bei Octopus switch.octopus_…_smart_control. Ist er aus, schaltet EMS ihn ein.', $suggest['provider_control'] ?? '') ?>
<?php if ($providerView['text'] !== ''): ?>
    <p class="body-sm"><?= e($providerView['text']) ?></p>
<?php endif; ?>
<?php if (!empty($providerState['error'])): ?>
    <?= ui_notice('triangle-alert', e((string) $providerState['error']), 'warn') ?>
<?php endif; ?>
<?php if (!empty($providerState['log'])): ?>
    <ul class="plain-list stack-tight body-sm" role="list" aria-label="Zuletzt geschaltet">
<?php foreach (array_slice((array) $providerState['log'], 0, 4) as $row): ?>
      <li><span class="num muted"><?= e(date('d.m. H:i', (int) $row['t'])) ?></span> <?= e((string) $row['text']) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>
