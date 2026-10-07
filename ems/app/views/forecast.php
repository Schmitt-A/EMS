<?php
declare(strict_types=1);
$plant = $snap['cfg']['plant'];
$v = $snap['values'];
$f = $snap['forecast'];
page_head('Prognose', 'Gemessene PV-Leistung aus Home Assistant gegen die stündliche DWD-Strahlung.');
$weather = (new WeatherFeed(store()))->meta();
?>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  <?php stat_card('PV live', kw($v['pv_kw']), 'pv'); ?>
  <?php stat_card('Ertrag heute', kwh($yield), 'x'); ?>
  <?php stat_card('Ertrag gestern', kwh($yesterday), 'x'); ?>
  <div class="card p-4">
    <p class="text-xs font-medium text-muted-foreground">Prognose heute</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums" data-live="today_forecast"><?= e(kwh($f['today_kwh'] ?? null)) ?></p>
    <p class="mt-1 text-xs text-muted-foreground">gesamt · noch <span class="tabular-nums" data-live="remaining"><?= e(kwh($f['remaining_kwh'] ?? null)) ?></span></p>
  </div>
  <?php stat_card('Prognose morgen', kwh($f['tomorrow_kwh'] ?? null), 'x'); ?>
  <?php stat_card('Güte gestern', $goodness === null ? '—' : num($goodness, 2), 'x'); ?>
</div>
<?php if (!empty($weather['error'])): ?>
  <p class="mt-4 rounded-lg border border-border bg-muted px-3 py-2 text-sm"><?= e((string) $weather['error']) ?></p>
<?php elseif (!empty($weather['fetched_at'])): ?>
  <p class="mt-4 text-xs text-muted-foreground">DWD <?= e((string) ($weather['name'] ?? $weather['station'] ?? '')) ?>, Stand <?= e(date('d.m.Y H:i', (int) $weather['fetched_at'])) ?><?= !empty($weather['issue']) ? ', Modelllauf ' . e(date('d.m. H:i', (int) $weather['issue'])) : '' ?>.</p>
<?php endif; ?>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Kommende Tage</h2>
  <p class="mb-2 text-xs text-muted-foreground">Der markierte Streifen ist der heutige Tag. Seitlich scrollen zeigt die weiteren Prognosetage.</p>
  <?php chart_box('/api/series?chart=outlook', 'h-72', true); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Leistung</h2>
  <p class="mb-2 text-xs text-muted-foreground">Alle vorhandenen Stunden der PV-Entität und die Prognose bis zum Ende der DWD-Datei.</p>
  <?php chart_box('/api/series?chart=power', 'h-80', true); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-2 text-sm font-medium">Tage: Ist, Modell und Güte</h2>
  <?php chart_box('/api/series?chart=daily', 'h-72', true); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-2 text-sm font-medium">Faktor gegen Regression</h2>
  <?php chart_box('/api/series?chart=compare', 'h-72', true); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Wetter vom DWD</h2>
  <p class="mb-2 text-xs text-muted-foreground">Globalstrahlung, Sonnenschein, Bewölkung und Temperatur aus der MOSMIX-Datei.</p>
  <?php chart_box('/api/series?chart=weather', 'h-72', true); ?>
</section>
<details class="card mt-4 p-5 text-sm" open>
  <summary class="cursor-pointer font-medium">Wie gerechnet wird</summary>
  <div class="mt-3 space-y-2 text-muted-foreground">
    <p>Dach <?= e(num((float) $plant['tilt'], 0)) ?>°, Ausrichtung <?= e(num((float) $plant['azimuth'], 0)) ?>°, <?= e(num((float) $plant['kwp'], 2)) ?> kWp, Wechselrichter-Limit <?= e(num((float) $plant['inverter_kw'], 1)) ?> kW.</p>
    <p>P = min(Limit, Strahlung × Kettenfaktor × Dachfaktor × Tagesform × Eichfaktor <?= e(num((float) $plant['factor'], 2)) ?>).</p>
    <p>Die Übersicht benutzt <?= e(Forecast::methodLabel($plant)) ?>. Die Regression ist Ist = <?= e(num((float) $plant['regress_a'], 2)) ?> + <?= e(num((float) $plant['regress_b'], 2)) ?> × Modell und greift ab fünf Tagen<?= (int) $plant['regress_days'] ? ' (' . (int) $plant['regress_days'] . ' bisher)' : '' ?>.</p>
    <p>Neigung und Himmelsrichtung verschieben nur die Form des Tages. Die Höhe fängt die Eichung auf. Das ist kein vollständiges Strahlungsmodell.</p>
    <a class="inline-block font-medium text-primary" href="<?= e(url('/einstellungen')) ?>">Anlagenwerte ändern</a>
  </div>
</details>
