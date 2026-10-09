<?php
declare(strict_types=1);
/**
 * Mehr → System: Verbindung zu Home Assistant, Assistent, Konfiguration als JSON.
 * @var array $ping
 * @var string $back
 */
$connection = connection();
?>
<section class="card stack" aria-labelledby="conn-title">
  <h3 class="card-title" id="conn-title">Verbindung</h3>
<?php if ($ping['ok']): ?>
  <p class="body"><?= ui_pill('Verbunden', 'plug', true) ?> Home Assistant <?= e((string) $ping['version']) ?><?= !empty($ping['location']) ? ', ' . e((string) $ping['location']) : '' ?></p>
<?php else: ?>
  <?= ui_notice('wifi-off', e((string) ($ping['error'] ?? 'Keine Verbindung.')), 'error') ?>
<?php endif; ?>
<?php if ($connection['source'] === 'supervisor'): ?>
  <p class="body-sm muted">Die App spricht über den Supervisor mit Home Assistant und braucht keinen eigenen Token.</p>
<?php else: ?>
  <p class="body-sm muted">Adresse: <?= e($connection['url'] !== '' ? $connection['url'] : 'nicht gesetzt') ?></p>
  <div class="form-actions"><a class="btn btn-secondary" href="<?= e(url('/einrichten/verbindung')) ?>">Verbindung ändern</a></div>
<?php endif; ?>
</section>
<section class="card stack" aria-labelledby="wizard-title">
  <h3 class="card-title" id="wizard-title">Einrichtung</h3>
  <p class="body-sm muted">Der Assistent führt Schritt für Schritt durch Verbindung, Messstellen und Wetter. Was schon zugeordnet ist, bleibt stehen.</p>
  <div class="form-actions"><a class="btn btn-secondary" href="<?= e(url('/einrichten')) ?>">Assistent öffnen</a></div>
</section>
<section class="card stack" aria-labelledby="json-title">
  <h3 class="card-title" id="json-title">Konfiguration als JSON</h3>
  <p class="body-sm muted">Die Datei enthält Zuordnung, Tarif mit CO₂-Faktor, Anlage, Speichergrenzen, Ladeparameter, Ladepunkt, Fahrzeug, DWD-Adresse und Darstellung. Verbindung und Ladevorgänge bleiben draußen.</p>
  <?= ui_config_exchange('/mehr', $back) ?>
</section>
<?php if (demo_mode()): ?>
<section class="card stack" aria-labelledby="demo-title">
  <h3 class="card-title" id="demo-title">Demo-Modus</h3>
  <p class="body-sm muted">Alle Werte kommen aus Beispieldaten. Die Komponentenseite zeigt jeden Baustein der Oberfläche in allen Zuständen.</p>
  <div class="form-actions"><a class="btn btn-secondary" href="<?= e(url('/komponenten')) ?>">Komponenten ansehen</a></div>
</section>
<?php endif; ?>
