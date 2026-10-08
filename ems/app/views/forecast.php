<?php
declare(strict_types=1);
$plant = $snap['cfg']['plant'];
$v = $snap['values'];
$f = $snap['forecast'];
page_head('Prognose', 'Gemessene PV-Energie und die DWD-Prognose. Die Güte zählt nur abgeschlossene Tage.');
$score = $scores['3'] ?? ['text' => '—', 'days' => 0];
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
      <p class="mt-1 text-xs text-muted-foreground">Für heute liegt kein festgehaltener Tageswert vor. Die Datei enthält die Stunden ab Mitternacht nicht mehr.</p>
    <?php endif; ?>
  </div>
  <?php stat_card('Prognose morgen', kwh($f['tomorrow_kwh'] ?? null), 'x'); ?>
  <div class="card p-4" data-gute-tile>
    <p class="text-xs font-medium text-muted-foreground">Güte <?php tip('Güte ist die Summe der Modellwerte geteilt durch die Summe der gemessenen Erträge. Es zählen nur abgeschlossene Tage, an denen Ist und Modell vollständig vorliegen. Der laufende Tag kommt erst dazu, wenn er vorbei ist. Liegt die Güte über 1, lag das Modell höher. 3 Tage, 7 Tage, Monat und Quartal wählen dasselbe Fenster für diese Kachel und für das Diagramm. Standard sind die letzten drei abgeschlossenen Tage.', true); ?></p>
    <p class="mt-1 text-2xl font-semibold tabular-nums" data-gute-ratio><?= e((string) ($score['text'] ?? '—')) ?></p>
    <p class="mt-1 text-xs text-muted-foreground"><span data-gute-days><?= (int) ($score['days'] ?? 0) ?></span> abgeschlossene Tage</p>
  </div>
</div>
<?php if (!empty($weather['error'])): ?>
  <p class="mt-4 rounded-lg border border-border bg-muted px-3 py-2 text-sm"><?= e((string) $weather['error']) ?></p>
<?php elseif (!empty($weather['fetched_at'])): ?>
  <p class="mt-4 text-xs text-muted-foreground">DWD <?= e((string) ($weather['name'] ?? $weather['station'] ?? '')) ?>, Stand <?= e(date('d.m.Y H:i', (int) $weather['fetched_at'])) ?><?= !empty($weather['issue']) ? ', Modelllauf ' . e(date('d.m. H:i', (int) $weather['issue'])) : '' ?>.</p>
<?php endif; ?>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Energie</h2>
  <p class="mb-3 text-xs text-muted-foreground">Start ist heute und die zwei folgenden Tage. Jeder Punkt ist die Energie dieser Stunde. Die Skala bleibt am höchsten Wert, damit die Tage beim Verschieben vergleichbar bleiben. Über dem Tag steht die Prognose mit Abweichung, dieselben Zahlen wie in der Tabelle.</p>
  <div class="mb-3 flex flex-wrap gap-2" data-power-tools>
    <button type="button" class="chip" data-window="today">Heute</button>
    <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
    <button type="button" class="chip" data-window="7">7 Tage</button>
    <button type="button" class="chip" data-window="all">Alles</button>
    <button type="button" class="chip" data-window="reset">Zurücksetzen</button>
    <button type="button" class="chip" data-series="actual" aria-pressed="true">Gemessen</button>
    <button type="button" class="chip" data-series="forecast" aria-pressed="true">Prognose</button>
  </div>
  <?php chart_box('/api/series?chart=power', 'h-80', 'pan'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 flex items-center gap-1 text-sm font-medium">Tage: Ist, Modell und Güte <?php tip('Ist ist der Energiezähler in kWh. Modell ist die gespeicherte Prognose des Tages. Die Güte eines Tages ist Modell ÷ Ist. Die Kachel summiert dieselben abgeschlossenen Tage, die hier gewählt sind. Der laufende Tag fehlt, bis er vorbei ist.', true); ?></h2>
  <p class="mb-3 text-xs text-muted-foreground">Standard sind die letzten drei abgeschlossenen Tage, der neueste links. 7 Tage, Monat und Quartal erweitern das Fenster. Ziehen bleibt in diesem Fenster.</p>
  <div class="mb-3 flex flex-wrap gap-2" data-gute-tools>
    <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
    <button type="button" class="chip" data-window="7">7 Tage</button>
    <button type="button" class="chip" data-window="month">Monat</button>
    <button type="button" class="chip" data-window="quarter">Quartal</button>
    <button type="button" class="chip" data-window="reset">Zurücksetzen</button>
    <button type="button" class="chip" data-series="actual" aria-pressed="true">Ist</button>
    <button type="button" class="chip" data-series="model" aria-pressed="true">Modell</button>
    <button type="button" class="chip" data-series="k" aria-pressed="true">Güte</button>
  </div>
  <?php chart_box('/api/series?chart=daily', 'h-72', 'pan'); ?>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Faktor gegen Regression</h2>
  <p class="mb-3 text-xs text-muted-foreground">Rohmodell ohne Eichfaktor, daneben der starre Faktor und die Regression. Dieselben drei Tage, der aktuelle Tag links. Die Regression greift in der Prognose erst ab fünf Tagen.</p>
  <div class="mb-3 flex flex-wrap gap-2" data-day-tools>
    <button type="button" class="chip" data-window="today">Heute</button>
    <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
    <button type="button" class="chip" data-window="7">7 Tage</button>
    <button type="button" class="chip" data-window="all">Alles</button>
    <button type="button" class="chip" data-window="reset">Zurücksetzen</button>
    <button type="button" class="chip" data-series="actual" aria-pressed="true">Ist</button>
    <button type="button" class="chip" data-series="model" aria-pressed="true">Rohmodell</button>
    <button type="button" class="chip" data-series="fitted" aria-pressed="true">Faktor</button>
    <button type="button" class="chip" data-series="regress" aria-pressed="true">Regression</button>
  </div>
  <?php chart_box('/api/series?chart=compare', 'h-72', 'pan'); ?>
</section>
<section class="mt-4 space-y-3" data-colset="forecast">
  <div class="flex flex-wrap gap-2">
    <?php foreach (['actual' => ['Ertrag', 'zap'], 'mean' => ['Prognose', 'chart-line'], 'sd' => ['Abweichung', 'activity'], 'radiation' => ['Strahlung', 'sun-medium'], 'sun' => ['Sonne', 'sun'], 'cloud' => ['Wolken', 'cloud-sun'], 'temp' => ['Temperatur', 'gauge'], 'runs' => ['Läufe', 'circle-check']] as $col => [$label, $glyph]): ?>
      <button type="button" class="chip min-h-11" data-col-toggle="<?= e($col) ?>" aria-pressed="true"><?= icon($glyph, 'h-3.5 w-3.5') ?><span><?= e($label) ?></span></button>
    <?php endforeach; ?>
  </div>
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center gap-2 px-4 pt-4">
      <h2 class="mr-auto text-sm font-medium">Prognosedaten <?php tip('Oben steht der aktuelle Tag, darunter die kommenden. Prognose und Abweichung sind Mittelwert und Streuung der gespeicherten DWD-Läufe. Dieselbe Zahl steht über dem Energiediagramm. Vergangene Tage liegen im Dialog.', true); ?></h2>
      <?php if ($past): ?>
        <button type="button" class="chip min-h-11" data-open-dialog="forecast-past">Vergangene Tage</button>
      <?php endif; ?>
    </div>
    <div class="touch-x mt-3">
      <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted-foreground"><tr>
          <th class="px-4 py-3 font-medium">Tag</th>
          <th class="py-3 font-medium" data-col="actual">Ertrag</th>
          <th class="py-3 font-medium" data-col="mean">Prognose</th>
          <th class="py-3 font-medium" data-col="sd">Abweichung</th>
          <th class="py-3 font-medium" data-col="radiation">Strahlung</th>
          <th class="py-3 font-medium" data-col="sun">Sonne</th>
          <th class="py-3 font-medium" data-col="cloud">Wolken</th>
          <th class="py-3 font-medium" data-col="temp">Temperatur</th>
          <th class="py-3 pr-4 font-medium" data-col="runs">Läufe</th>
        </tr></thead>
        <tbody>
          <?php forecast_archive_rows($upcoming, 'Für heute und die kommenden Tage liegt noch kein gespeicherter Modelllauf.'); ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($past): ?>
    <dialog id="forecast-past" class="sheet">
      <div class="flex items-center gap-2 px-4 pt-4">
        <h2 class="mr-auto text-sm font-medium">Vergangene Tage</h2>
        <button type="button" class="btn-ghost min-h-11" data-close-dialog>Schließen</button>
      </div>
      <div class="touch-x mt-3">
        <table class="w-full text-left text-sm">
          <thead class="text-xs text-muted-foreground"><tr>
            <th class="px-4 py-3 font-medium">Tag</th>
            <th class="py-3 font-medium" data-col="actual">Ertrag</th>
            <th class="py-3 font-medium" data-col="mean">Prognose</th>
            <th class="py-3 font-medium" data-col="sd">Abweichung</th>
            <th class="py-3 font-medium" data-col="radiation">Strahlung</th>
            <th class="py-3 font-medium" data-col="sun">Sonne</th>
            <th class="py-3 font-medium" data-col="cloud">Wolken</th>
            <th class="py-3 font-medium" data-col="temp">Temperatur</th>
            <th class="py-3 pr-4 font-medium" data-col="runs">Läufe</th>
          </tr></thead>
          <tbody>
            <?php forecast_archive_rows($past, 'Noch keine abgeschlossenen Tage.'); ?>
          </tbody>
        </table>
      </div>
    </dialog>
  <?php endif; ?>
</section>
<section class="card mt-4 overflow-hidden" data-colset="model">
  <div class="flex flex-wrap items-center gap-2 px-4 pt-4">
    <h2 class="mr-auto text-sm font-medium">Modelle <?php tip('Ist ist der Energiezähler. Modell ist die Prognose aus der gespeicherten Strahlung mit Eichfaktor, ab fünf Tagen mit der Regression. Güte ist Modell ÷ Ist. Rohmodell ist dieselbe Summe ohne Eichfaktor. Faktor ist Rohmodell × Eichfaktor. Regression ist a + b × Rohmodell.', true); ?></h2>
    <?php foreach (['actual' => ['Ist', 'zap'], 'model' => ['Modell', 'chart-column'], 'gute' => ['Güte', 'scale'], 'raw' => ['Rohmodell', 'sun'], 'fitted' => ['Faktor', 'sliders-horizontal'], 'regress' => ['Regression', 'chart-line']] as $col => [$label, $glyph]): ?>
      <button type="button" class="chip min-h-11" data-col-toggle="<?= e($col) ?>" aria-pressed="true"><?= icon($glyph, 'h-3.5 w-3.5') ?><span><?= e($label) ?></span></button>
    <?php endforeach; ?>
  </div>
  <div class="touch-x mt-3">
    <table class="w-full text-left text-sm">
      <thead class="text-xs text-muted-foreground"><tr>
        <th class="px-4 py-3 font-medium">Tag</th>
        <th class="py-3 font-medium" data-col="actual">Ist</th>
        <th class="py-3 font-medium" data-col="model">Modell</th>
        <th class="py-3 font-medium" data-col="gute">Güte</th>
        <th class="py-3 font-medium" data-col="raw">Rohmodell</th>
        <th class="py-3 font-medium" data-col="fitted">Faktor</th>
        <th class="py-3 pr-4 font-medium" data-col="regress">Regression</th>
      </tr></thead>
      <tbody>
        <?php if (!$modelRows): ?>
          <tr><td class="px-4 py-4 text-muted-foreground" colspan="7">In diesem Zeitraum liegen noch keine Modelltage.</td></tr>
        <?php endif; ?>
        <?php foreach ($modelRows as $row): ?>
          <tr class="border-t border-border">
            <td class="whitespace-nowrap px-4 py-3"><?= e(day_label((string) $row['day'])) ?></td>
            <td class="py-3 tabular-nums" data-col="actual"><?= e(kwh($row['actual'], 1)) ?></td>
            <td class="py-3 tabular-nums" data-col="model"><?= e(kwh($row['model'], 1)) ?></td>
            <td class="py-3 tabular-nums" data-col="gute"><?= $row['gute'] === null ? '—' : e(num((float) $row['gute'], 2)) ?></td>
            <td class="py-3 tabular-nums" data-col="raw"><?= e(kwh($row['raw'], 1)) ?></td>
            <td class="py-3 tabular-nums" data-col="fitted"><?= e(kwh($row['fitted'], 1)) ?></td>
            <td class="py-3 pr-4 tabular-nums" data-col="regress"><?= e(kwh($row['regress'], 1)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<section class="card mt-4 p-4">
  <h2 class="mb-1 text-sm font-medium">Wetter vom DWD</h2>
  <p class="mb-3 text-xs text-muted-foreground">Strahlung, Sonnenschein, Bewölkung und Temperatur. Start ist heute und die zwei folgenden Tage. Ziehen oder wischen verschiebt die Zeit.</p>
  <div class="mb-3 flex flex-wrap gap-2" data-power-tools>
    <button type="button" class="chip" data-window="today">Heute</button>
    <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
    <button type="button" class="chip" data-window="7">7 Tage</button>
    <button type="button" class="chip" data-window="all">Alles</button>
    <button type="button" class="chip" data-window="reset">Zurücksetzen</button>
    <button type="button" class="chip" data-series="radiation" aria-pressed="true">Strahlung</button>
    <button type="button" class="chip" data-series="sun" aria-pressed="true">Sonne</button>
    <button type="button" class="chip" data-series="cloud" aria-pressed="true">Wolken</button>
    <button type="button" class="chip" data-series="temp" aria-pressed="true">Temperatur</button>
  </div>
  <?php chart_box('/api/series?chart=weather', 'h-72', 'pan'); ?>
</section>
<details class="card mt-4 p-5 text-sm" open>
  <summary class="cursor-pointer font-medium">Wie gerechnet wird</summary>
  <div class="mt-3 space-y-2 text-muted-foreground">
    <p>Dach <?= e(num((float) $plant['tilt'], 0)) ?>°, Ausrichtung <?= e(num((float) $plant['azimuth'], 0)) ?>°, <?= e(num((float) $plant['kwp'], 2)) ?> kWp, Wechselrichter-Limit <?= e(num((float) $plant['inverter_kw'], 1)) ?> kW, Eichfaktor <?= e(num((float) $plant['factor'], 2)) ?>.</p>
    <p>P = min(Limit, Strahlung × (kWp × 1,04 × 0,90 × 0,975 / 1000) × Eichfaktor). Jede Stunde der DWD-Datei zählt einmal. Die Zahl über dem Tag ist der Mittelwert der gespeicherten Läufe.</p>
    <p>Die Übersicht benutzt <?= e(Forecast::methodLabel($plant)) ?>. Die Regression ist Ist = <?= e(num((float) $plant['regress_a'], 2)) ?> + <?= e(num((float) $plant['regress_b'], 2)) ?> × Rohmodell und greift ab fünf Tagen<?= (int) $plant['regress_days'] ? ' (' . (int) $plant['regress_days'] . ' bisher)' : '' ?>.</p>
    <p>Der Ertrag kommt vom Energiezähler. Die Zahl über einem Tag im Energiediagramm ist der Mittelwert der gespeicherten DWD-Läufe mit seiner Abweichung, dieselbe Zahl wie in der Tabelle. Die Güte benutzt nur Tage, die schon vorbei sind und bei denen Ist und Modell vollständig sind.</p>
    <p>Strahlung, Bewölkung, Sonnenschein und Temperatur kommen aus der DWD-Datei. Jeder Modelllauf bleibt gespeichert, sobald der Tag ab Mitternacht in der Datei steht.</p>
    <p>Im EMS-Dashboard wird die Tagessumme um 00:01 Uhr mit der festen Konstante 0,00893 festgeschrieben. Die Kurve hier folgt Generatorleistung, Verlustkette und Eichfaktor. Bei 10,03 kWp und Faktor 0,93 sind das 0,00851 kW je W/m².</p>
    <a class="inline-block font-medium text-primary" href="<?= e(url('/einstellungen')) ?>">Anlagenwerte ändern</a>
  </div>
</details>
