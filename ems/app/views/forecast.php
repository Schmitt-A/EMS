<?php
declare(strict_types=1);
$plant = $snap['cfg']['plant'];
$v = $snap['values'];
$f = $snap['forecast'];
page_head('Prognose', 'Gemessene Leistung gegen die DWD-Strahlung, geeicht an den letzten Tagen.');
?>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  <?php stat_card('PV live', kw($v['pv_kw']), 'pv'); ?>
  <?php stat_card('Ertrag heute', kwh($yield), 'x'); ?>
  <?php stat_card('Ertrag gestern', kwh($yesterday), 'x'); ?>
  <?php stat_card('Prognose heute', kwh($f['today_kwh'] ?? null), 'today_forecast'); ?>
  <?php stat_card('Prognose morgen', kwh($f['tomorrow_kwh'] ?? null), 'x'); ?>
  <?php stat_card('Güte gestern', $goodness === null ? '—' : num($goodness, 2), 'x'); ?>
</div>
<section class="card mt-4 p-4">
  <h2 class="mb-2 text-sm font-medium">Leistung, 24 Stunden zurück und bis morgen Abend</h2>
  <?php chart_box('/api/series?chart=power', 'h-80'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-2 text-sm font-medium">Tage: Ist, Modell und Güte</h2>
  <?php chart_box('/api/series?chart=daily', 'h-72'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-2 text-sm font-medium">Faktor gegen Regression</h2>
  <?php chart_box('/api/series?chart=compare', 'h-72'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-2 text-sm font-medium">Wetter</h2>
  <?php chart_box('/api/series?chart=weather', 'h-64'); ?>
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
