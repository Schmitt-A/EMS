<?php
declare(strict_types=1);
$keys = array_keys(Actions::STEPS);
$index = array_search($step, $keys, true);
$mapping = $cfg['mapping'];
page_head('Einrichten', Actions::STEPS[$step] . ' · Schritt ' . (($index === false ? 0 : $index) + 1) . ' von ' . count($keys));
?>
<ol class="mb-4 flex flex-wrap gap-2">
  <?php foreach (Actions::STEPS as $key => $label): ?>
    <li><a class="chip <?= $key === $step ? 'border-primary text-foreground' : '' ?>" href="<?= e(url('/einrichten/' . $key)) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ol>
<form method="post" class="card space-y-4 p-5" action="<?= e(url('/einrichten/' . $step)) ?>">
  <?= csrf_field() ?>
  <?php if ($step === 'verbindung'): ?>
    <?php if (($connection['source'] ?? '') === 'supervisor'): ?>
      <p class="text-sm">Diese Installation spricht über den Supervisor mit Home Assistant. Ein eigener Token ist nicht nötig.</p>
    <?php else: ?>
      <label class="block text-sm">Adresse<input class="field mt-1" name="url" value="<?= e($connection['url']) ?>" placeholder="http://192.168.180.27:8123"></label>
      <label class="block text-sm">Token<input class="field mt-1" name="token" type="password" value="" placeholder="<?= $connection['token'] !== '' ? 'gespeichert, leer lassen zum Behalten' : 'Long-Lived Access Token' ?>"></label>
    <?php endif; ?>
    <p class="text-sm <?= !empty($ping['ok']) ? 'text-export' : 'text-import' ?>"><?= !empty($ping['ok']) ? 'Erreichbar. Home Assistant ' . e((string) $ping['version']) : e((string) ($ping['error'] ?? '')) ?></p>
  <?php elseif ($step === 'photovoltaik'): ?>
    <?php entity_field('pv_power', 'PV-Leistung', (string) $mapping['pv_power'], 'Watt oder Kilowatt, die Einheit wird umgerechnet.', $suggest['pv_power'] ?? ''); ?>
    <?php entity_field('pv_energy', 'Energiezähler, optional', (string) $mapping['pv_energy'], 'total_increasing in kWh. Sonst wird die Leistung aufintegriert.', $suggest['pv_energy'] ?? ''); ?>
  <?php elseif ($step === 'speicher'): ?>
    <?php entity_field('battery_soc', 'Ladestand', (string) $mapping['battery_soc'], 'Prozent.', $suggest['battery_soc'] ?? ''); ?>
    <label class="block text-sm">Leistungsart
      <select class="field mt-1" name="battery_mode">
        <option value="split" <?= $mapping['battery_mode'] === 'split' ? 'selected' : '' ?>>Laden und Entladen getrennt</option>
        <option value="signed" <?= $mapping['battery_mode'] === 'signed' ? 'selected' : '' ?>>Ein Sensor mit Vorzeichen</option>
      </select>
    </label>
    <?php entity_field('battery_charge', 'Laden', (string) $mapping['battery_charge'], 'Leistung beim Laden.', $suggest['battery_charge'] ?? ''); ?>
    <?php entity_field('battery_discharge', 'Entladen', (string) $mapping['battery_discharge'], 'Leistung beim Entladen.', $suggest['battery_discharge'] ?? ''); ?>
    <?php entity_field('battery_signed', 'Vorzeichen-Sensor', (string) $mapping['battery_signed'], 'Nur nötig, wenn oben ein Sensor gewählt ist.', ''); ?>
    <label class="block text-sm">Positives Vorzeichen bedeutet
      <select class="field mt-1" name="battery_sign">
        <option value="positive_charge" <?= $mapping['battery_sign'] === 'positive_charge' ? 'selected' : '' ?>>Laden</option>
        <option value="positive_discharge" <?= $mapping['battery_sign'] === 'positive_discharge' ? 'selected' : '' ?>>Entladen</option>
      </select>
    </label>
    <?php entity_field('battery_capacity', 'Restkapazität, optional', (string) $mapping['battery_capacity'], 'Wh oder kWh.', $suggest['battery_capacity'] ?? ''); ?>
  <?php elseif ($step === 'netz'): ?>
    <label class="block text-sm">Zählerart
      <select class="field mt-1" name="grid_mode">
        <option value="split" <?= $mapping['grid_mode'] === 'split' ? 'selected' : '' ?>>Bezug und Einspeisung getrennt</option>
        <option value="signed" <?= $mapping['grid_mode'] === 'signed' ? 'selected' : '' ?>>Ein Sensor mit Vorzeichen</option>
      </select>
    </label>
    <?php entity_field('grid_import', 'Netzbezug', (string) $mapping['grid_import'], 'Leistung aus dem Netz.', $suggest['grid_import'] ?? ''); ?>
    <?php entity_field('grid_export', 'Einspeisung', (string) $mapping['grid_export'], 'Leistung ins Netz.', $suggest['grid_export'] ?? ''); ?>
    <?php entity_field('grid_signed', 'Vorzeichen-Sensor', (string) $mapping['grid_signed'], 'Nur bei einem gemeinsamen Sensor.', ''); ?>
    <label class="block text-sm">Positives Vorzeichen bedeutet
      <select class="field mt-1" name="grid_sign">
        <option value="positive_import" <?= $mapping['grid_sign'] === 'positive_import' ? 'selected' : '' ?>>Bezug</option>
        <option value="positive_export" <?= $mapping['grid_sign'] === 'positive_export' ? 'selected' : '' ?>>Einspeisung</option>
      </select>
    </label>
  <?php elseif ($step === 'haus'): ?>
    <?php entity_field('house_power', 'Hausverbrauch', (string) $mapping['house_power'], 'Leistung des Haushalts.', $suggest['house_power'] ?? ''); ?>
    <input type="hidden" name="house_includes_wallbox" value="0">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="house_includes_wallbox" value="1" <?= !empty($mapping['house_includes_wallbox']) ? 'checked' : '' ?>> Dieser Wert enthält die Wallbox</label>
  <?php elseif ($step === 'wallbox'): ?>
    <?php entity_field('wallbox_power', 'Ladeleistung', (string) $mapping['wallbox_power'], 'Watt oder Kilowatt.', $suggest['wallbox_power'] ?? ''); ?>
    <?php entity_field('wallbox_car', 'Fahrzeugstatus', (string) $mapping['wallbox_car'], 'Zum Beispiel charging oder idle.', $suggest['wallbox_car'] ?? ''); ?>
    <p class="text-xs text-muted-foreground">Die folgenden Felder werden nur gelesen. Bei einer go-e sind sie meist vorhanden.</p>
    <?php entity_field('wallbox_amps', 'Gemeldeter Strom', (string) $mapping['wallbox_amps'], 'Ampere, nur Anzeige.', $suggest['wallbox_amps'] ?? ''); ?>
    <?php entity_field('wallbox_amps_max', 'Maximalstrom der Wallbox', (string) $mapping['wallbox_amps_max'], 'Optional.', $suggest['wallbox_amps_max'] ?? ''); ?>
    <?php entity_field('wallbox_phases', 'Gemeldete Phasen', (string) $mapping['wallbox_phases'], '1-phasig, 3-phasig oder automatisch.', $suggest['wallbox_phases'] ?? ''); ?>
    <?php entity_field('wallbox_force', 'Zwangszustand', (string) $mapping['wallbox_force'], 'Optional, nur Anzeige.', $suggest['wallbox_force'] ?? ''); ?>
  <?php elseif ($step === 'wetter'): ?>
    <p class="text-sm text-muted-foreground">Strahlung, Bewölkung, Sonnenschein und Temperatur kommen nur aus der MOSMIX-Datei des Deutschen Wetterdienstes. Die App holt sie ungefähr alle 30 Minuten.</p>
    <label class="block text-sm">KMZ-Adresse
      <input class="field mt-1 font-mono text-xs" name="weather_url" value="<?= e((string) ($cfg['weather']['url'] ?? WeatherFeed::DEFAULT_URL)) ?>">
    </label>
    <p class="text-xs text-muted-foreground">Die Adresse endet auf <span class="font-mono">MOSMIX_L_LATEST_….kmz</span> und beginnt mit https://opendata.dwd.de/. Soonwald West ist F9519. Den Ordner findest du in der Stationsliste MOSMIX_L.</p>
  <?php else: ?>
    <?php if ($missing): ?>
      <p class="text-sm text-import">Noch nötig: <?= e(implode(', ', $missing)) ?>.</p>
    <?php else: ?>
      <p class="text-sm text-export">Die nötigen Messstellen sind gesetzt.</p>
    <?php endif; ?>
    <ul class="space-y-2 text-sm">
      <?php foreach ($review as $line): ?>
        <li class="flex justify-between gap-3 border-b border-border py-2"><span><?= e($line['label']) ?></span><span class="text-right tabular-nums <?= $line['ok'] ? '' : 'text-import' ?>"><?= e($line['text']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <div class="flex justify-between gap-3 pt-2">
    <?php if ($index > 0): ?><a class="btn-ghost" href="<?= e(url('/einrichten/' . $keys[$index - 1])) ?>">Zurück</a><?php else: ?><span></span><?php endif; ?>
    <button class="btn-primary" type="submit"><?= $step === 'pruefen' ? 'Fertig' : 'Weiter' ?></button>
  </div>
</form>
<?php if ($step === 'verbindung' || $step === 'pruefen'): ?>
<section class="card mt-4 space-y-3 p-5 text-sm">
  <h2 class="font-medium">Vorhandene Konfiguration</h2>
  <p class="text-muted-foreground">Eine früher gespeicherte JSON-Datei setzt Zuordnung, Tarife, Dach und die DWD-Adresse auf einmal.</p>
  <?php config_exchange('/einrichten/' . $step, '/einrichten/' . $step); ?>
</section>
<?php endif; ?>
