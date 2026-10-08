<?php
declare(strict_types=1);
$c = $cfg['charge'];
$p = $cfg['plant'];
$t = $cfg['tariffs'];
$b = $cfg['battery_strategy'];
$m = $cfg['mapping'];
$suggest = $suggest ?? [];
page_head('Einstellungen', 'Tarife, Dach und Strategie liegen hier. Die Messwerte kommen aus Home Assistant.');
?>
<div class="grid gap-4">
  <section class="card p-5 text-sm">
    <h2 class="font-medium">Verbindung</h2>
    <p class="mt-2 text-muted-foreground"><?= $ping['ok'] ? 'Erreichbar, Home Assistant ' . e($ping['version']) . ($ping['location'] ? ', ' . e($ping['location']) : '') : e($ping['error'] ?? 'Nicht verbunden') ?>.</p>
    <a class="mt-2 inline-block font-medium text-primary" href="<?= e(url('/einrichten/verbindung')) ?>">Verbindung prüfen</a>
  </section>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="mapping"><input type="hidden" name="back" value="/einstellungen">
    <h2 class="font-medium">Zuordnung</h2>
    <p class="text-sm text-muted-foreground">Tippe einen Namen oder eine Entität. Die Liste sucht in Home Assistant und übernimmt die gewählte Kennung.</p>
    <div class="space-y-4">
      <fieldset class="rounded-lg border border-border p-4">
        <legend class="px-1 text-sm font-medium">Photovoltaik</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php entity_field('pv_power', 'PV-Leistung', (string) $m['pv_power'], 'Watt oder Kilowatt.'); ?>
          <?php entity_field('pv_energy', 'PV-Energiezähler, optional', (string) $m['pv_energy'], 'total_increasing in kWh. Sonst wird die Leistung aufintegriert.'); ?>
        </div>
      </fieldset>
      <fieldset class="rounded-lg border border-border p-4">
        <legend class="px-1 text-sm font-medium">Speicher</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php entity_field('battery_soc', 'Ladestand', (string) $m['battery_soc'], 'Prozent.'); ?>
          <?php entity_field('battery_capacity', 'Restkapazität, optional', (string) $m['battery_capacity'], 'Wh oder kWh, der aktuell nutzbare Inhalt.'); ?>
          <?php entity_field('battery_total', 'Gesamtkapazität', (string) ($m['battery_total'] ?? ''), 'Installierte nutzbare Kapazität, Wh oder kWh.', $suggest['battery_total'] ?? ''); ?>
          <?php entity_field('battery_charge', 'Speicher laden', (string) $m['battery_charge'], 'Leistung beim Laden.'); ?>
          <?php entity_field('battery_discharge', 'Speicher entladen', (string) $m['battery_discharge'], 'Leistung beim Entladen.'); ?>
          <?php entity_field('battery_signed', 'Speicher mit Vorzeichen', (string) $m['battery_signed'], 'Nur bei einem gemeinsamen Sensor.'); ?>
          <label class="block text-sm">Speicherleistung
            <select class="field mt-1" name="battery_mode">
              <option value="split" <?= $m['battery_mode'] === 'split' ? 'selected' : '' ?>>Laden und Entladen getrennt</option>
              <option value="signed" <?= $m['battery_mode'] === 'signed' ? 'selected' : '' ?>>Ein Sensor mit Vorzeichen</option>
            </select>
          </label>
          <label class="block text-sm">Positives Vorzeichen beim Speicher
            <select class="field mt-1" name="battery_sign">
              <option value="positive_charge" <?= $m['battery_sign'] === 'positive_charge' ? 'selected' : '' ?>>Laden</option>
              <option value="positive_discharge" <?= $m['battery_sign'] === 'positive_discharge' ? 'selected' : '' ?>>Entladen</option>
            </select>
          </label>
        </div>
      </fieldset>
      <fieldset class="rounded-lg border border-border p-4">
        <legend class="px-1 text-sm font-medium">Netz</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php entity_field('grid_import', 'Netzbezug', (string) $m['grid_import'], 'Leistung aus dem Netz.'); ?>
          <?php entity_field('grid_export', 'Einspeisung', (string) $m['grid_export'], 'Leistung ins Netz.'); ?>
          <?php entity_field('grid_signed', 'Netz mit Vorzeichen', (string) $m['grid_signed'], 'Nur bei einem gemeinsamen Sensor.'); ?>
          <label class="block text-sm">Netzleistung
            <select class="field mt-1" name="grid_mode">
              <option value="split" <?= $m['grid_mode'] === 'split' ? 'selected' : '' ?>>Bezug und Einspeisung getrennt</option>
              <option value="signed" <?= $m['grid_mode'] === 'signed' ? 'selected' : '' ?>>Ein Sensor mit Vorzeichen</option>
            </select>
          </label>
          <label class="block text-sm">Positives Vorzeichen beim Netz
            <select class="field mt-1" name="grid_sign">
              <option value="positive_import" <?= $m['grid_sign'] === 'positive_import' ? 'selected' : '' ?>>Bezug</option>
              <option value="positive_export" <?= $m['grid_sign'] === 'positive_export' ? 'selected' : '' ?>>Einspeisung</option>
            </select>
          </label>
        </div>
      </fieldset>
      <fieldset class="rounded-lg border border-border p-4">
        <legend class="px-1 text-sm font-medium">Haus</legend>
        <div class="mt-3 grid gap-4">
          <?php entity_field('house_power', 'Hausverbrauch', (string) $m['house_power'], 'Leistung des Haushalts.'); ?>
          <input type="hidden" name="house_includes_wallbox" value="0">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="house_includes_wallbox" value="1" <?= !empty($m['house_includes_wallbox']) ? 'checked' : '' ?>> Der Hausverbrauch enthält die Wallbox</label>
        </div>
      </fieldset>
      <fieldset class="rounded-lg border border-border p-4">
        <legend class="px-1 text-sm font-medium">Wallbox</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php entity_field('wallbox_power', 'Wallbox-Leistung', (string) $m['wallbox_power'], 'Watt oder Kilowatt.'); ?>
          <?php entity_field('wallbox_car', 'Fahrzeugstatus', (string) $m['wallbox_car'], 'Zum Beispiel charging.'); ?>
          <?php entity_field('wallbox_amps', 'Gemeldeter Strom', (string) $m['wallbox_amps'], 'Ampere, nur Anzeige.'); ?>
          <?php entity_field('wallbox_amps_max', 'Maximalstrom', (string) $m['wallbox_amps_max'], 'Optional.'); ?>
          <?php entity_field('wallbox_phases', 'Gemeldete Phasen', (string) $m['wallbox_phases'], '1-phasig oder 3-phasig.'); ?>
          <?php entity_field('wallbox_force', 'Zwangszustand', (string) $m['wallbox_force'], 'Optional, nur Anzeige.'); ?>
        </div>
      </fieldset>
    </div>
    <button class="btn-primary" type="submit">Zuordnung speichern</button>
  </form>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="tariffs">
    <h2 class="font-medium">Stromtarife</h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Netzbezug (ct/kWh)<input class="field mt-1" name="import_ct" value="<?= e((string) $t['import_ct']) ?>"></label>
      <label class="text-sm">Einspeisevergütung (ct/kWh)<input class="field mt-1" name="export_ct" value="<?= e((string) $t['export_ct']) ?>"></label>
    </div>
    <button class="btn-primary" type="submit">Tarife speichern</button>
  </form>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="plant">
    <h2 class="font-medium">Anlage</h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <?php foreach ([
        'kwp' => 'Generator (kWp)', 'inverter_kw' => 'Wechselrichter-Limit (kW)', 'tilt' => 'Neigung (°)',
        'azimuth' => 'Ausrichtung (°)', 'n_days' => 'Kalibrierung (Tage)', 'factor' => 'Eichfaktor',
        'regress_a' => 'Regression a', 'regress_b' => 'Regression b',
      ] as $name => $label): ?>
        <label class="text-sm"><?= e($label) ?><input class="field mt-1" name="<?= e($name) ?>" value="<?= e((string) $p[$name]) ?>"></label>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-muted-foreground">Die Kalibrierung schaut so viele abgeschlossene Tage zurück. Die Güte auf der Prognoseseite wählt ihr Fenster selbst: 3 Tage, 7 Tage, Monat oder Quartal. Ein geänderter Eichfaktor oder eine geänderte Regression bleibt stehen, bis du sie wieder freigibst.</p>
    <div class="flex flex-wrap gap-2">
      <button class="btn-primary" type="submit">Anlage speichern</button>
      <button class="btn-ghost" name="unlock_factor" value="1" type="submit">Eichfaktor freigeben</button>
      <button class="btn-ghost" name="unlock_regress" value="1" type="submit">Regression neu schätzen</button>
    </div>
  </form>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="battery">
    <h2 class="font-medium">Speicher</h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Vorrang (%)<input class="field mt-1" name="priority_soc" value="<?= e((string) $b['priority_soc']) ?>"></label>
      <label class="text-sm">Mindestreserve (%)<input class="field mt-1" name="reserve_soc" value="<?= e((string) $b['reserve_soc']) ?>"></label>
    </div>
    <p class="text-xs text-muted-foreground">Der Vorrang ist die Hausgrenze auf der Batterieseite: bis dahin füllt Sonnenstrom zuerst den Hausspeicher. Die Mindestreserve markiert, was fürs Haus bleiben soll. Die Zonen dort rasten in Schritten von 5 %.</p>
    <button class="btn-primary" type="submit">Speicher speichern</button>
  </form>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="car"><input type="hidden" name="back" value="/einstellungen">
    <h2 class="font-medium">Auto</h2>
    <p class="text-sm text-muted-foreground">Bis zur Hausgrenze geht Sonnenüberschuss im Modus Smart zuerst in den Hausspeicher. Darüber hat das Auto den Überschuss. Ab der Stützung darf der Speicher mitladen, ab dem automatischen Start auch ohne Sonne. Die Hausgrenze steckt in der Ladevorschau. Stützung und Start bestimmen Anzeige und Zeiten. Ladestand und Kapazität des Autos füllen die Anzeige, sobald die Entitäten da sind.</p>
    <div class="grid gap-4 sm:grid-cols-2">
      <?php entity_field('car_soc', 'Ladestand des Autos', (string) ($m['car_soc'] ?? ''), 'Prozent, sobald das Fahrzeug ihn meldet.'); ?>
      <?php entity_field('car_capacity', 'Kapazität des Autos', (string) ($m['car_capacity'] ?? ''), 'Wh oder kWh.'); ?>
      <label class="text-sm">Stützung ab (%)<input class="field mt-1" name="car_buffer_soc" value="<?= e((string) ($b['car_buffer_soc'] ?? 100)) ?>"></label>
      <label class="text-sm">Automatisch ab (%)<input class="field mt-1" name="car_auto_soc" value="<?= e((string) ($b['car_auto_soc'] ?? 100)) ?>"></label>
    </div>
    <button class="btn-primary" type="submit">Auto speichern</button>
  </form>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="weather"><input type="hidden" name="back" value="/einstellungen">
    <h2 class="font-medium">DWD-Prognose</h2>
    <p class="text-sm text-muted-foreground">Die Strahlungsprognose kommt direkt vom Deutschen Wetterdienst, stündlich für etwa zehn Tage. Die App holt die Datei ungefähr alle 30 Minuten neu.</p>
    <label class="block text-sm">KMZ-Adresse
      <input class="field mt-1 font-mono text-xs" name="weather_url" value="<?= e((string) ($cfg['weather']['url'] ?? '')) ?>">
    </label>
    <div class="space-y-2 text-sm text-muted-foreground">
      <p>So findest du den Link:</p>
      <ol class="list-decimal space-y-1 pl-5">
        <li>Öffne <a class="font-medium text-primary" href="https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/">die Stationsliste MOSMIX_L</a>.</li>
        <li>Die Kennung steht im <a class="font-medium text-primary" href="https://www.dwd.de/DE/leistungen/met_verfahren_mosmix/mosmix_stationskatalog.cfg?view=nasPublication&amp;nn=16102">Stationskatalog</a>. Soonwald West ist F9519.</li>
        <li>Im Ordner der Station liegt <span class="font-mono text-xs">kml/MOSMIX_L_LATEST_F9519.kmz</span>.</li>
        <li>Die Adresse endet auf diese Datei und beginnt mit <span class="font-mono text-xs">https://opendata.dwd.de/</span>.</li>
      </ol>
    </div>
    <button class="btn-primary" type="submit">Wetteradresse speichern</button>
  </form>
  <form method="post" class="card space-y-4 p-5">
    <?= csrf_field() ?><input type="hidden" name="section" value="theme">
    <h2 class="font-medium">Darstellung</h2>
    <div class="flex flex-wrap gap-2">
      <?php foreach (['system' => 'System', 'light' => 'Hell', 'dark' => 'Dunkel'] as $value => $label): ?>
        <label class="chip cursor-pointer"><input type="radio" name="theme" value="<?= e($value) ?>" <?= ($cfg['ui']['theme'] ?? 'system') === $value ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn-primary" type="submit">Darstellung speichern</button>
  </form>
  <p class="text-xs text-muted-foreground">Seitenleiste und automatische Updates schaltest du auf der Home-Assistant-Seite dieser App, unter Einstellungen → Apps → EMS.</p>
  <section class="card space-y-3 p-5 text-sm">
    <h2 class="font-medium">Konfiguration als JSON</h2>
    <p class="text-muted-foreground">Die Datei enthält die ganze Konfiguration: Zuordnung, Stromtarife, Anlage, Speicher, Ladeparameter, DWD-Adresse und Darstellung. Verbindung und Ladevorgänge bleiben draußen.</p>
    <?php config_exchange('/einstellungen', '/einstellungen'); ?>
  </section>
</div>
