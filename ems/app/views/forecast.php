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
    <p class="text-xs font-medium text-muted-foreground">Güte <?php tip('Güte ist die Summe der Prognosen geteilt durch die Summe der gemessenen Erträge. Es zählen nur abgeschlossene Tage, an denen Ist und Prognose vollständig vorliegen. Der laufende Tag kommt erst dazu, wenn er vorbei ist. Liegt die Güte über 1, lag die Prognose höher. Der Eichfaktor ist ein anderer Wert: er steht in der Formel und wird mit dem Rohmodell multipliziert. 3 Tage, 7 Tage, Monat und Quartal wählen dasselbe Fenster für diese Kachel und für das Diagramm. Standard sind die letzten drei abgeschlossenen Tage. Die Rechnung mit den Zahlen von heute klappt weiter unten auf.', true); ?></p>
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
  <p class="mb-3 text-xs text-muted-foreground">Start ist heute und die zwei folgenden Tage. Jeder Punkt ist die Energie dieser Stunde. Die Skala bleibt fest in Schritten von 0,5 kWh. Wischen verschiebt die Tage. Über der Kurve, in der Mitte des Tages, steht die Prognose mit Abweichung, dieselben Zahlen wie in der Tabelle.</p>
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
  <h2 class="mb-1 flex items-center gap-1 text-sm font-medium">Tage: Ist, Prognose und Güte <?php tip('Ist ist der Energiezähler in kWh. Prognose ist der gespeicherte Tageswert, Rohmodell mal Eichfaktor. Die Güte eines Tages ist Prognose ÷ Ist. Die Kachel summiert dieselben abgeschlossenen Tage, die hier gewählt sind. Der laufende Tag fehlt, bis er vorbei ist.', true); ?></h2>
  <p class="mb-3 text-xs text-muted-foreground">Standard sind die letzten drei abgeschlossenen Tage, der neueste links. 7 Tage, Monat und Quartal erweitern das Fenster. Ziehen bleibt in diesem Fenster.</p>
  <div class="mb-3 flex flex-wrap gap-2" data-gute-tools>
    <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
    <button type="button" class="chip" data-window="7">7 Tage</button>
    <button type="button" class="chip" data-window="month">Monat</button>
    <button type="button" class="chip" data-window="quarter">Quartal</button>
    <button type="button" class="chip" data-window="reset">Zurücksetzen</button>
    <button type="button" class="chip" data-series="actual" aria-pressed="true">Ist</button>
    <button type="button" class="chip" data-series="model" aria-pressed="true">Prognose</button>
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
      <h2 class="mr-auto text-sm font-medium">Prognosedaten <?php tip('Oben steht der aktuelle Tag, darunter die fünf kommenden. Prognose und Abweichung sind Mittelwert und Streuung der gespeicherten DWD-Läufe. Dieselbe Zahl steht über der Kurve im Energiediagramm und in den Kacheln für heute und morgen. Vergangene Tage liegen im Dialog.', true); ?></h2>
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
          <?php forecast_archive_rows($upcoming, 'Für heute und die kommenden Tage liegt noch kein gespeicherter Modelllauf.', $todayKey); ?>
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
<?php
$lessonTip = 'Die Spalte Prognose ist Rohmodell × Eichfaktor. Die Abweichung ist die Streuung der gespeicherten DWD-Läufe, mal demselben Faktor. Der Eichfaktor steht in der Formel. Die Güte teilt danach die Summe der Prognosen durch die Summe der Erträge und ist ein anderer Wert.';
if (is_array($lesson) && ($lesson['raw'] ?? null) !== null && ($lesson['prognosis'] ?? null) !== null) {
    $lessonFactor = (float) $lesson['factor'];
    $lessonScale = (int) $lesson['regress_days'] >= 5 ? abs((float) $lesson['regress_b']) : $lessonFactor;
    $lessonProduct = (int) $lesson['regress_days'] >= 5
        ? (float) $lesson['regress_a'] + (float) $lesson['regress_b'] * (float) $lesson['raw']
        : (float) $lesson['raw'] * $lessonFactor;
    $lessonTip = (int) $lesson['regress_days'] >= 5
        ? 'Prognose = ' . num((float) $lesson['regress_a'], 2) . ' + ' . num((float) $lesson['regress_b'], 2) . ' × Rohmodell. Heute: ' . num((float) $lesson['regress_a'], 2) . ' + ' . num((float) $lesson['regress_b'], 2) . ' × ' . num((float) $lesson['raw'], 2) . ' = ' . num($lessonProduct, 2) . ' kWh, angezeigt ' . kwh((float) $lesson['prognosis'], 1) . '. '
        : 'Prognose = Rohmodell × Eichfaktor. Heute: ' . num((float) $lesson['raw'], 2) . ' kWh × ' . num($lessonFactor, 2) . ' = ' . num($lessonProduct, 2) . ' kWh, angezeigt ' . kwh((float) $lesson['prognosis'], 1) . '. ';
    if (($lesson['sd_raw'] ?? null) !== null) {
        $lessonSpread = (float) $lesson['sd_raw'] * $lessonScale;
        $lessonTip .= 'Abweichung = Streuung der ' . (int) $lesson['runs'] . ' DWD-Läufe × ' . num($lessonScale, 2) . ': ' . num((float) $lesson['sd_raw'], 2) . ' × ' . num($lessonScale, 2) . ' = ' . num($lessonSpread, 2) . ' kWh, angezeigt ± ' . num($lessonSpread, 1) . ' kWh. ';
    } else {
        $lessonTip .= 'Für heute liegt noch kein zweiter DWD-Lauf vor, deshalb bleibt die Abweichung leer. ';
    }
    $lessonTip .= 'Der Eichfaktor ' . num($lessonFactor, 2) . ' steht in dieser Formel. ';
    if (($lesson['gute'] ?? null) !== null) {
        $lessonTip .= 'Die Güte ' . num((float) $lesson['gute'], 2) . ' ist Summe Prognose ÷ Summe Ist über ' . count($lesson['pairs']) . ' abgeschlossene Tage. Sie prüft die fertige Prognose und ist ein anderer Wert als der Eichfaktor. ';
    }
    $lessonTip .= 'Die farbige Zeile ist der laufende Tag, seine Güte bleibt leer, bis der Tag vorbei ist. Die ausgeschriebene Rechnung steht darüber zum Aufklappen.';
}
?>
<?php if (is_array($lesson)): ?>
<details class="card mt-4 p-5 text-sm">
  <summary class="cursor-pointer font-medium">Rechnung für <?= e(day_label((string) $lesson['day'])) ?></summary>
  <div class="mt-3 space-y-3 text-muted-foreground">
    <?php if (($lesson['raw'] ?? null) !== null && ($lesson['prognosis'] ?? null) !== null): ?>
      <?php
        $walkFactor = (float) $lesson['factor'];
        $walkRegression = (int) $lesson['regress_days'] >= 5;
        $walkScale = $walkRegression ? abs((float) $lesson['regress_b']) : $walkFactor;
        $walkProduct = $walkRegression
            ? (float) $lesson['regress_a'] + (float) $lesson['regress_b'] * (float) $lesson['raw']
            : (float) $lesson['raw'] * $walkFactor;
      ?>
      <p><span class="font-medium text-foreground">Prognose.</span> Der DWD liefert Strahlung. Das Rohmodell summiert daraus die Energie mit Eichfaktor 1 und bildet den Mittelwert der gespeicherten Läufe. Heute: <?php if ($walkRegression): ?><?= e(num((float) $lesson['regress_a'], 2)) ?> + <?= e(num((float) $lesson['regress_b'], 2)) ?> × <?= e(num((float) $lesson['raw'], 2)) ?> = <?= e(num($walkProduct, 2)) ?> kWh.<?php else: ?><?= e(num((float) $lesson['raw'], 2)) ?> kWh × <?= e(num($walkFactor, 2)) ?> = <?= e(num($walkProduct, 2)) ?> kWh.<?php endif; ?> Tabelle, Kachel und Kurve runden auf eine Nachkommastelle: <?= e(kwh((float) $lesson['prognosis'], 1)) ?>.</p>
      <?php if (($lesson['sd_raw'] ?? null) !== null): ?>
        <p><span class="font-medium text-foreground">Abweichung.</span> <?= (int) $lesson['runs'] ?> Läufe liegen für diesen Tag vor. Ihre Streuung um das Rohmodell ist <?= e(num((float) $lesson['sd_raw'], 2)) ?> kWh. Dieselbe Streuung mal <?= $walkRegression ? 'dem Regressionsfaktor' : 'dem Eichfaktor' ?> bleibt auf der Skala der Prognose: <?= e(num((float) $lesson['sd_raw'], 2)) ?> × <?= e(num($walkScale, 2)) ?> = <?= e(num((float) $lesson['sd_raw'] * $walkScale, 2)) ?> kWh, angezeigt ± <?= e(num((float) $lesson['sd_raw'] * $walkScale, 1)) ?> kWh. Über der Kurve steht damit <?= e(Forecast::captionText((float) $lesson['prognosis'], (float) $lesson['sd_raw'] * $walkScale)) ?>.</p>
      <?php else: ?>
        <p><span class="font-medium text-foreground">Abweichung.</span> Für heute liegt <?= (int) $lesson['runs'] === 1 ? 'ein vollständiger Lauf' : 'noch kein zweiter Lauf' ?> vor. Die Streuung entsteht ab dem zweiten Lauf, bis dahin bleibt die Abweichung leer.</p>
      <?php endif; ?>
    <?php elseif (($lesson['prognosis'] ?? null) !== null): ?>
      <p><span class="font-medium text-foreground">Prognose.</span> Für <?= e(day_label((string) $lesson['day'])) ?> steht der festgehaltene Tageswert <?= e(kwh((float) $lesson['prognosis'], 1)) ?>. Ein Rohmodell aus der aktuellen DWD-Datei liegt für diesen Tag nicht vor.</p>
    <?php endif; ?>
    <p><span class="font-medium text-foreground">Eichfaktor <?= e(num((float) $lesson['factor'], 2)) ?>.</span> Er steht in der Formel P = min(Limit, Strahlung × (kWp × 1,04 × 0,90 × 0,975 / 1000) × Eichfaktor) und verkleinert das Rohmodell, bevor die Prognose feststeht. <?php if (!empty($lesson['factor_locked'])): ?>Der Wert ist in den Einstellungen festgehalten.<?php elseif ((int) $lesson['regress_days'] >= 5): ?>Er ist der Mittelwert aus Ist ÷ Rohmodell über <?= (int) $lesson['regress_days'] ?> abgeschlossene Tage.<?php else: ?>Bisher <?= (int) $lesson['regress_days'] === 1 ? 'liegt ein abgeschlossener Tag mit Ist und Rohmodell vor' : 'liegen ' . (int) $lesson['regress_days'] . ' abgeschlossene Tage mit Ist und Rohmodell vor' ?>. Ab fünf solchen Tagen wird der Eichfaktor zum Mittel aus Ist ÷ Rohmodell.<?php endif; ?> Die Spalte Faktor schreibt die Multiplikation noch einmal aus. Solange weniger als fünf Tage vorliegen, stimmen Prognose und Faktor überein.</p>
    <div>
      <p><span class="font-medium text-foreground">Güte<?php if (($lesson['gute'] ?? null) !== null): ?> <?= e(num((float) $lesson['gute'], 2)) ?><?php endif; ?>.</span> Die Kachel rechnet Summe der Prognosen ÷ Summe der Erträge. Der laufende Tag<?php if (($lesson['actual'] ?? null) !== null): ?>, bisher <?= e(kwh((float) $lesson['actual'], 1)) ?> gemessen,<?php endif; ?> kommt erst dazu, wenn er vorbei ist.</p>
      <?php if ($lesson['pairs']): ?>
        <div class="touch-x mt-2">
          <table class="w-full text-left text-sm">
            <thead class="text-xs"><tr>
              <th class="py-2 pr-4 font-medium">Tag</th>
              <th class="py-2 pr-4 font-medium">Ist</th>
              <th class="py-2 font-medium">Prognose</th>
            </tr></thead>
            <tbody>
              <?php foreach ($lesson['pairs'] as $pair): ?>
                <tr class="border-t border-border">
                  <td class="py-2 pr-4"><?= e(day_label((string) $pair['day'])) ?></td>
                  <td class="py-2 pr-4 tabular-nums"><?= e(kwh((float) $pair['actual'], 1)) ?></td>
                  <td class="py-2 tabular-nums"><?= e(kwh((float) $pair['model'], 1)) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr class="border-t border-border font-medium text-foreground">
                <td class="py-2 pr-4">Summe</td>
                <td class="py-2 pr-4 tabular-nums"><?= e(kwh((float) $lesson['sum_actual'], 1)) ?></td>
                <td class="py-2 tabular-nums"><?= e(kwh((float) $lesson['sum_model'], 1)) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        <?php if (($lesson['gute'] ?? null) !== null): ?>
          <p class="mt-2">Güte = <?= e(num((float) $lesson['sum_model'], 2)) ?> ÷ <?= e(num((float) $lesson['sum_actual'], 2)) ?> = <?= e(num((float) $lesson['gute'], 2)) ?>. Eine Güte über 1 heißt, die Prognose lag über diese Tage höher als der Zähler. Der Eichfaktor <?= e(num((float) $lesson['factor'], 2)) ?> hat diese Prognose vorher erzeugt. Die Güte prüft das Ergebnis.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="mt-2">Für die Güte liegt noch kein abgeschlossener Tag mit Ist und Prognose vor.</p>
      <?php endif; ?>
    </div>
  </div>
</details>
<?php endif; ?>
<section class="card mt-4 overflow-hidden" data-colset="model">
  <div class="flex flex-wrap items-center gap-2 px-4 pt-4">
    <h2 class="mr-auto text-sm font-medium">Modelle <?php tip($lessonTip, true); ?></h2>
    <p class="mt-1 w-full text-xs text-muted-foreground">Fünf kommende Tage, der farbige laufende Tag, darunter fünf vergangene Tage. Die Prognose trägt die Abweichung der DWD-Läufe.</p>
    <?php foreach (['actual' => ['Ist', 'zap'], 'model' => ['Prognose', 'chart-column'], 'gute' => ['Güte', 'scale'], 'raw' => ['Rohmodell', 'sun'], 'fitted' => ['Faktor', 'sliders-horizontal'], 'regress' => ['Regression', 'chart-line']] as $col => [$label, $glyph]): ?>
      <button type="button" class="chip min-h-11" data-col-toggle="<?= e($col) ?>" aria-pressed="true"><?= icon($glyph, 'h-3.5 w-3.5') ?><span><?= e($label) ?></span></button>
    <?php endforeach; ?>
  </div>
  <div class="touch-x mt-3">
    <table class="w-full text-left text-sm">
      <thead class="text-xs text-muted-foreground"><tr>
        <th class="px-4 py-3 font-medium">Tag</th>
        <th class="py-3 font-medium" data-col="actual">Ist</th>
        <th class="py-3 font-medium" data-col="model">Prognose</th>
        <th class="py-3 font-medium" data-col="gute">Güte</th>
        <th class="py-3 font-medium" data-col="raw">Rohmodell</th>
        <th class="py-3 font-medium" data-col="fitted">Faktor</th>
        <th class="py-3 pr-4 font-medium" data-col="regress">Regression</th>
      </tr></thead>
      <tbody>
        <?php if (!$modelRows): ?>
          <tr><td class="px-4 py-4 text-muted-foreground" colspan="7">Für die kommenden und vergangenen Tage liegt noch keine Prognose.</td></tr>
        <?php endif; ?>
        <?php foreach ($modelRows as $row): ?>
          <tr class="border-t border-border<?= !empty($row['today']) ? ' row-today' : '' ?>">
            <td class="whitespace-nowrap px-4 py-3"><?= e(day_label((string) $row['day'])) ?></td>
            <td class="py-3 tabular-nums" data-col="actual"><?= e(kwh($row['actual'], 1)) ?></td>
            <td class="py-3 tabular-nums" data-col="model"><?php $prognosisText = Forecast::captionText($row['model'] !== null ? (float) $row['model'] : null, isset($row['sd']) && $row['sd'] !== null ? (float) $row['sd'] : null); ?><?= e($prognosisText ?? '—') ?></td>
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
    <p>Die Übersicht benutzt <?= e(Forecast::methodLabel($plant)) ?>. <?php if ((int) $plant['regress_days'] >= 5): ?>Die Regression ist Ist = <?= e(num((float) $plant['regress_a'], 2)) ?> + <?= e(num((float) $plant['regress_b'], 2)) ?> × Rohmodell und ist aktiv (<?= (int) $plant['regress_days'] ?> Tage).<?php else: ?>Die Regression startet ab fünf abgeschlossenen Tagen<?php if ((int) $plant['regress_days']): ?> (<?= (int) $plant['regress_days'] ?> bisher)<?php endif; ?>. Bis dahin gilt der Eichfaktor.<?php endif; ?></p>
    <p>Der Ertrag kommt vom Energiezähler. Die Zahl in der Mitte eines Tages, über der Kurve, ist der Mittelwert der gespeicherten DWD-Läufe mal Eichfaktor, mit der Streuung dieser Läufe. Dieselbe Zahl steht in der Tabelle und in den Kacheln. Die Güte benutzt nur Tage, die schon vorbei sind und bei denen Ist und Prognose vollständig sind. Die Rechnung für den laufenden Tag klappt darüber auf.</p>
    <p>Strahlung, Bewölkung, Sonnenschein und Temperatur kommen aus der DWD-Datei. Jeder Modelllauf bleibt gespeichert, sobald der Tag ab Mitternacht in der Datei steht.</p>
    <p>Im EMS-Dashboard wird die Tagessumme um 00:01 Uhr mit der festen Konstante 0,00893 festgeschrieben. Die Kurve hier folgt Generatorleistung, Verlustkette und Eichfaktor. Bei 10,03 kWp und Faktor 0,93 sind das 0,00851 kW je W/m².</p>
    <a class="inline-block font-medium text-primary" href="<?= e(url('/einstellungen')) ?>">Anlagenwerte ändern</a>
  </div>
</details>
