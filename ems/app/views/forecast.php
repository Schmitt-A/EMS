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
    <?php if (($f['today_kwh'] ?? null) === null): ?>
      <p class="mt-1 text-xs text-muted-foreground">Die Morgenstunden sind nicht mehr in der DWD-Datei. Der Tageswert bleibt stehen, sobald ein Tag ab Mitternacht erfasst wurde.</p>
    <?php endif; ?>
  </div>
  <?php stat_card('Prognose morgen', kwh($f['tomorrow_kwh'] ?? null), 'x'); ?>
  <div class="card p-4">
    <p class="text-xs font-medium text-muted-foreground">Güte gestern</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums"><?= e($goodness === null ? '—' : num($goodness, 2)) ?></p>
    <p class="mt-1 text-xs text-muted-foreground">Modell / Ist. Über 1 lag das Modell höher.</p>
  </div>
</div>
<?php if (!empty($weather['error'])): ?>
  <p class="mt-4 rounded-lg border border-border bg-muted px-3 py-2 text-sm"><?= e((string) $weather['error']) ?></p>
<?php elseif (!empty($weather['fetched_at'])): ?>
  <p class="mt-4 text-xs text-muted-foreground">DWD <?= e((string) ($weather['name'] ?? $weather['station'] ?? '')) ?>, Stand <?= e(date('d.m.Y H:i', (int) $weather['fetched_at'])) ?><?= !empty($weather['issue']) ? ', Modelllauf ' . e(date('d.m. H:i', (int) $weather['issue'])) : '' ?>.</p>
<?php endif; ?>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Leistung</h2>
  <p class="mb-3 text-xs text-muted-foreground">Start ist heute und die zwei folgenden Tage. Ziehen oder wischen verschiebt die Zeit. Über dem Mittagspeak steht die erwartete Tagesenergie.</p>
  <div class="mb-3 flex flex-wrap gap-2" data-power-tools>
    <button type="button" class="chip" data-window="today">Heute</button>
    <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
    <button type="button" class="chip" data-window="7">7 Tage</button>
    <button type="button" class="chip" data-window="all">Alles</button>
    <button type="button" class="chip" data-window="reset">Zurücksetzen</button>
    <button type="button" class="chip" data-series="actual" aria-pressed="true">Gemessen</button>
    <button type="button" class="chip" data-series="forecast" aria-pressed="true">Prognose</button>
    <button type="button" class="chip" data-series="radiation" aria-pressed="true">Strahlung</button>
  </div>
  <?php chart_box('/api/series?chart=power', 'h-80', 'pan'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Tage: Ist, Modell und Güte</h2>
  <p class="mb-2 text-xs text-muted-foreground">Ist ist der Energiezähler. Modell ist die Prognose mit Eichfaktor. Güte teilt Modell durch Ist.</p>
  <?php chart_box('/api/series?chart=daily', 'h-72'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Faktor gegen Regression</h2>
  <p class="mb-2 text-xs text-muted-foreground">Rohmodell ohne Eichfaktor, daneben der starre Faktor und die Regression. Die Regression greift in der Prognose erst ab fünf Tagen.</p>
  <?php chart_box('/api/series?chart=compare', 'h-72'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Wetter vom DWD</h2>
  <p class="mb-2 text-xs text-muted-foreground">Globalstrahlung, Sonnenschein, Bewölkung und Temperatur aus der MOSMIX-Datei.</p>
  <?php chart_box('/api/series?chart=weather', 'h-72', 'scroll'); ?>
</section>
<details class="card mt-4 p-5 text-sm" open>
  <summary class="cursor-pointer font-medium">Wie gerechnet wird</summary>
  <div class="mt-3 space-y-2 text-muted-foreground">
    <p>Dach <?= e(num((float) $plant['tilt'], 0)) ?>°, Ausrichtung <?= e(num((float) $plant['azimuth'], 0)) ?>°, <?= e(num((float) $plant['kwp'], 2)) ?> kWp, Wechselrichter-Limit <?= e(num((float) $plant['inverter_kw'], 1)) ?> kW, Eichfaktor <?= e(num((float) $plant['factor'], 2)) ?>.</p>
    <p>P = min(Limit, Strahlung × (kWp × 1,04 × 0,90 × 0,975 / 1000) × Eichfaktor). Jede Stunde der DWD-Datei zählt einmal. Die Zahl am Mittagspeak ist die Summe dieser Stunden.</p>
    <p>Die Übersicht benutzt <?= e(Forecast::methodLabel($plant)) ?>. Die Regression ist Ist = <?= e(num((float) $plant['regress_a'], 2)) ?> + <?= e(num((float) $plant['regress_b'], 2)) ?> × Rohmodell und greift ab fünf Tagen<?= (int) $plant['regress_days'] ? ' (' . (int) $plant['regress_days'] . ' bisher)' : '' ?>.</p>
    <p>Der Ertrag kommt vom Energiezähler. Fehlt der Zähler, wird die Leistung aufintegriert. Eine volle Tagesprognose wird gespeichert, solange die DWD-Datei den Tag ab Mitternacht enthält. Danach bleibt dieser Wert stehen.</p>
    <p>Im EMS-Dashboard wird die Tagessumme um 00:01 Uhr mit der festen Konstante 0,00893 festgeschrieben. Die Kurve hier folgt Generatorleistung, Verlustkette und Eichfaktor. Bei 10,03 kWp und Faktor 0,93 sind das 0,00851 kW je W/m².</p>
    <a class="inline-block font-medium text-primary" href="<?= e(url('/einstellungen')) ?>">Anlagenwerte ändern</a>
  </div>
</details>
