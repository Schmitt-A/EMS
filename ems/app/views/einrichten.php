<?php
declare(strict_types=1);
/**
 * Einrichten: Assistent in acht Schritten. Vor der ersten Einrichtung ohne Navigation.
 * @var string $step
 * @var array $cfg
 * @var array $ping
 * @var array $connection
 * @var array $suggest
 * @var list<string> $missing
 * @var list<array{label: string, text: string, ok: bool}> $review
 */
$keys = array_keys(Actions::STEPS);
$index = (int) array_search($step, $keys, true);
$m = $cfg['mapping'];
$entity = static fn (string $key, string $label, string $hint, ?string $value = null): string => ui_entity_field($key, $label, $value ?? (string) ($m[$key] ?? ''), $hint, $suggest[$key] ?? '');

echo ui_page_head('Einrichten');
?>
<nav aria-label="Schritte">
  <ol class="steps" role="list">
<?php foreach ($keys as $n => $key): ?>
    <li><a class="step-link" href="<?= e(url('/einrichten/' . $key)) ?>"<?= $key === $step ? ' aria-current="step"' : '' ?>><span class="step-num"><?= $n + 1 ?></span><?= e(Actions::STEPS[$key]) ?></a></li>
<?php endforeach; ?>
  </ol>
</nav>
<section class="card stack" aria-labelledby="step-title">
  <div class="stack-tight">
    <p class="label">Schritt <?= $index + 1 ?> von <?= count($keys) ?></p>
    <h2 class="card-title" id="step-title"><?= e(Actions::STEPS[$step]) ?></h2>
  </div>
  <form method="post" action="<?= e(url('/einrichten/' . $step)) ?>" class="stack">
    <?= csrf_field() ?>
<?php if ($step === 'verbindung'): ?>
<?php if (($connection['source'] ?? '') === 'supervisor'): ?>
    <p class="body">Diese Installation spricht über den Supervisor mit Home Assistant. Ein eigener Token ist nicht nötig.</p>
<?php else: ?>
    <div class="form-rows">
      <?= ui_form_row('Adresse', ui_input('url', (string) $connection['url'], ['id' => 'f-url', 'type' => 'url', 'placeholder' => 'http://homeassistant.local:8123', 'spellcheck' => 'false', 'autocomplete' => 'off']), ['for' => 'f-url', 'stack' => true]) ?>
      <?= ui_form_row('Token', ui_input('token', '', ['id' => 'f-token', 'type' => 'password', 'autocomplete' => 'off', 'placeholder' => $connection['token'] !== '' ? 'Gespeichert, leer lassen zum Behalten' : 'Long-Lived Access Token']), ['for' => 'f-token', 'stack' => true, 'hint' => 'Den Token legst du in Home Assistant unter Profil → Sicherheit an.']) ?>
    </div>
<?php endif; ?>
<?php if (!empty($ping['ok'])): ?>
    <?= ui_notice('circle-check', 'Erreichbar. Home Assistant ' . e((string) $ping['version']) . '.') ?>
<?php elseif (!empty($ping['error'])): ?>
    <?= ui_notice('wifi-off', e((string) $ping['error']), 'error') ?>
<?php endif; ?>
<?php elseif ($step === 'photovoltaik'): ?>
    <?= $entity('pv_power', 'PV-Leistung', 'Watt oder Kilowatt, die Einheit wird umgerechnet.') ?>
    <?= $entity('pv_energy', 'Energiezähler, optional', 'total_increasing in kWh. Sonst wird die Leistung aufintegriert.') ?>
<?php elseif ($step === 'speicher'): ?>
    <?= $entity('battery_soc', 'Ladestand', 'Prozent.') ?>
    <div class="form-rows"><?= ui_form_row('Leistung', ui_select('battery_mode', ['split' => 'Laden und Entladen getrennt', 'signed' => 'Ein Sensor mit Vorzeichen'], (string) $m['battery_mode']), ['for' => 'f-battery_mode', 'stack' => true]) ?></div>
    <?= $entity('battery_charge', 'Laden', 'Leistung beim Laden.') ?>
    <?= $entity('battery_discharge', 'Entladen', 'Leistung beim Entladen.') ?>
    <?= $entity('battery_signed', 'Vorzeichen-Sensor', 'Nur nötig, wenn oben ein Sensor gewählt ist.') ?>
    <div class="form-rows"><?= ui_form_row('Positives Vorzeichen bedeutet', ui_select('battery_sign', ['positive_charge' => 'Laden', 'positive_discharge' => 'Entladen'], (string) $m['battery_sign']), ['for' => 'f-battery_sign', 'stack' => true]) ?></div>
    <?= $entity('battery_capacity', 'Restkapazität, optional', 'Wh oder kWh, der aktuell nutzbare Inhalt.') ?>
    <?= $entity('battery_total', 'Gesamtkapazität', 'Installierte nutzbare Kapazität, Wh oder kWh.', (string) (($m['battery_total'] ?? '') !== '' ? $m['battery_total'] : ($suggest['battery_total'] ?? ''))) ?>
<?php elseif ($step === 'netz'): ?>
    <div class="form-rows"><?= ui_form_row('Zähler', ui_select('grid_mode', ['split' => 'Bezug und Einspeisung getrennt', 'signed' => 'Ein Sensor mit Vorzeichen'], (string) $m['grid_mode']), ['for' => 'f-grid_mode', 'stack' => true]) ?></div>
    <?= $entity('grid_import', 'Netzbezug', 'Leistung aus dem Netz.') ?>
    <?= $entity('grid_export', 'Einspeisung', 'Leistung ins Netz.') ?>
    <?= $entity('grid_signed', 'Vorzeichen-Sensor', 'Nur bei einem gemeinsamen Sensor.') ?>
    <div class="form-rows"><?= ui_form_row('Positives Vorzeichen bedeutet', ui_select('grid_sign', ['positive_import' => 'Bezug', 'positive_export' => 'Einspeisung'], (string) $m['grid_sign']), ['for' => 'f-grid_sign', 'stack' => true]) ?></div>
<?php elseif ($step === 'haus'): ?>
    <?= $entity('house_power', 'Hausverbrauch', 'Leistung des Haushalts.') ?>
    <div class="form-rows"><?= ui_form_row('Enthält die Wallbox', ui_toggle('house_includes_wallbox', !empty($m['house_includes_wallbox']), 'Dieser Wert enthält die Wallbox'), ['hint' => 'Dann zieht die App die Wallbox vom Hausverbrauch ab.']) ?></div>
<?php elseif ($step === 'wallbox'): ?>
    <?= $entity('wallbox_power', 'Ladeleistung', 'Watt oder Kilowatt.') ?>
    <?= $entity('wallbox_car', 'Fahrzeugstatus', 'Zum Beispiel charging oder idle.') ?>
    <p class="body-sm muted">Die folgenden Felder werden nur gelesen. Bei einer go-e sind sie meist vorhanden.</p>
    <?= $entity('wallbox_amps', 'Gemeldeter Strom', 'Ampere, nur Anzeige.') ?>
    <?= $entity('wallbox_amps_max', 'Maximalstrom der Wallbox', 'Optional.') ?>
    <?= $entity('wallbox_phases', 'Gemeldete Phasen', '1-phasig, 3-phasig oder automatisch.') ?>
    <?= $entity('wallbox_force', 'Zwangszustand', 'Optional, nur Anzeige.') ?>
    <p class="body-sm muted">Ladestand und Kapazität des Autos kommen später dazu. Solange die Felder leer sind, rechnet die Speicherseite ohne Fahrzeugenergie.</p>
    <?= $entity('car_soc', 'Ladestand des Autos', 'Prozent, sobald das Fahrzeug ihn meldet.') ?>
    <?= $entity('car_capacity', 'Kapazität des Autos', 'Wh oder kWh.') ?>
<?php elseif ($step === 'wetter'): ?>
    <p class="body-sm muted">Strahlung, Bewölkung, Sonnenschein und Temperatur kommen nur aus der MOSMIX-Datei des Deutschen Wetterdienstes. Die App holt sie ungefähr alle 30 Minuten.</p>
    <div class="form-rows"><?= ui_form_row('KMZ-Adresse', ui_input('weather_url', (string) ($cfg['weather']['url'] ?? WeatherFeed::DEFAULT_URL), ['id' => 'f-weather-url', 'type' => 'url', 'spellcheck' => 'false', 'autocomplete' => 'off']), ['for' => 'f-weather-url', 'stack' => true, 'hint' => 'Endet auf MOSMIX_L_LATEST_….kmz und beginnt mit https://opendata.dwd.de/. Soonwald West ist F9519.']) ?></div>
<?php else: ?>
<?php if ($missing): ?>
    <?= ui_notice('circle-alert', 'Noch nötig: ' . e(implode(', ', $missing)) . '.', 'warn') ?>
<?php else: ?>
    <?= ui_notice('circle-check', 'Die nötigen Messstellen sind gesetzt.') ?>
<?php endif; ?>
    <dl class="kv">
<?php foreach ($review as $line): ?>
      <div><dt><?= e($line['label']) ?></dt><dd<?= $line['ok'] ? '' : ' class="text-danger"' ?>><?= e($line['text']) ?></dd></div>
<?php endforeach; ?>
    </dl>
<?php endif; ?>
    <div class="form-actions wizard-actions">
<?php if ($index > 0): ?>
      <a class="btn btn-secondary" href="<?= e(url('/einrichten/' . $keys[$index - 1])) ?>">Zurück</a>
<?php endif; ?>
      <button class="btn btn-primary" type="submit"><?= $step === 'pruefen' ? 'Fertig' : 'Weiter' ?></button>
    </div>
  </form>
</section>
<?php if ($step === 'verbindung' || $step === 'pruefen'): ?>
<section class="card stack section" aria-labelledby="import-title">
  <h2 class="card-title" id="import-title">Vorhandene Konfiguration</h2>
  <p class="body-sm muted">Eine früher gespeicherte JSON-Datei setzt Zuordnung, Tarif, Anlage und die DWD-Adresse auf einmal.</p>
  <?= ui_config_exchange('/einrichten/' . $step, '/einrichten/' . $step) ?>
</section>
<?php endif; ?>
