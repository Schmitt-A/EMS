<?php
declare(strict_types=1);
$c = $cfg['charge'];
$p = $cfg['plant'];
$t = $cfg['tariffs'];
$b = $cfg['battery_strategy'];
$m = $cfg['mapping'];
page_head('Einstellungen', 'Tarife, Dach und Strategie liegen hier. Die Messwerte kommen aus Home Assistant.');
?>
<div class="grid gap-4">
  <section class="card p-5 text-sm">
    <h2 class="font-medium">Verbindung</h2>
    <p class="mt-2 text-muted-foreground"><?= $ping['ok'] ? 'Erreichbar, Home Assistant ' . e($ping['version']) . ($ping['location'] ? ', ' . e($ping['location']) : '') : e($ping['error'] ?? 'Nicht verbunden') ?>.</p>
    <a class="mt-2 inline-block font-medium text-primary" href="<?= e(url('/einrichten/verbindung')) ?>">Verbindung prüfen</a>
  </section>
  <section class="card p-5 text-sm">
    <h2 class="font-medium">Zuordnung</h2>
    <ul class="mt-3 space-y-2">
      <?php foreach (['pv_power' => 'Photovoltaik', 'battery_soc' => 'Speicher', 'grid_import' => 'Netzbezug', 'house_power' => 'Haus', 'wallbox_power' => 'Wallbox', 'weather_radiation' => 'Strahlung'] as $key => $label): ?>
        <li class="flex justify-between gap-3"><span><?= e($label) ?></span><span class="truncate text-muted-foreground"><?= e($m[$key] ?: 'nicht gesetzt') ?></span></li>
      <?php endforeach; ?>
    </ul>
    <a class="mt-3 inline-block font-medium text-primary" href="<?= e(url('/einrichten/photovoltaik')) ?>">Zuordnung ändern</a>
  </section>
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
        'azimuth' => 'Ausrichtung (°)', 'n_days' => 'Mittelung (Tage)', 'factor' => 'Eichfaktor',
        'regress_a' => 'Regression a', 'regress_b' => 'Regression b',
      ] as $name => $label): ?>
        <label class="text-sm"><?= e($label) ?><input class="field mt-1" name="<?= e($name) ?>" value="<?= e((string) $p[$name]) ?>"></label>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-muted-foreground">Ein geänderter Eichfaktor oder eine geänderte Regression bleibt stehen, bis du sie wieder freigibst. Dann schätzt die nächste Eichung neu.</p>
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
    <button class="btn-primary" type="submit">Speicher speichern</button>
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
</div>
