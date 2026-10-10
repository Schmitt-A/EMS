<?php
declare(strict_types=1);
/**
 * Prognose (8): Solarprognose über drei Tage mit Tageswerten, Ist und Prognose mit Güte.
 * Ebene 2: Rechnung, Modelle, Prognosedaten, Faktor gegen Regression, Wetter, Wie gerechnet wird.
 * @var array $snap
 * @var array $live
 * @var array $overview
 * @var ?float $yield
 * @var ?float $yesterday
 * @var array $scores
 * @var array $upcoming
 * @var array $past
 * @var array $modelRows
 * @var string $todayKey
 * @var ?array $lesson
 * @var array $captions
 * @var array $weather
 */
$plant = $snap['cfg']['plant'];
$f = $snap['forecast'];
$score = $scores['3'] ?? ['text' => '—', 'days' => 0];
$tz = new DateTimeZone('Europe/Berlin');
$day = static fn (int $offset): string => (new DateTimeImmutable($todayKey, $tz))->modify('+' . $offset . ' days')->format('Y-m-d');
$after = $captions[$day(2)]['kwh'] ?? null;

$todayText = $f ? num($f['today_kwh'] ?? null, 1) . NNBSP . 'kWh' : '—';
echo ui_page_head('Prognose', ui_head_chip('sun', e($todayText), 'Prognose heute ' . $todayText . '. Energieübersicht öffnen'));

if (!empty($weather['error'])) {
    echo ui_notice('circle-alert', e('Wetterdatei: ' . $weather['error']), 'error');
}
$stamp = '';
if (!empty($weather['fetched_at'])) {
    $stamp = 'DWD ' . ($weather['name'] ?? $weather['station'] ?? '') . ', Stand ' . date('d.m. H:i', (int) $weather['fetched_at'])
        . (!empty($weather['issue']) ? ', Modelllauf ' . date('d.m. H:i', (int) $weather['issue']) : '') . '.';
}
?>
<div class="split split-wide">
  <section class="card stack" aria-labelledby="solar-title">
    <h2 class="card-title" id="solar-title">Solarprognose</h2>
    <?= ui_chart('time', '/api/series?chart=power', 'Solarprognose für heute und die zwei folgenden Tage', [
        'window' => '3',
        'class' => 'chart-tall',
        'tools' => '<div class="chart-tools">' . ui_segment('power-window', 'Zeitraum', ['today' => 'Heute', '3' => '3 Tage', '7' => '7 Tage'], '3', ['compact' => true, 'id' => 'pw', 'attrs' => ['data-chart-window' => true]])
            . '<div class="chart-toggles">'
            . '<button type="button" class="toggle-chip c-solar" aria-pressed="true" data-chart-series="forecast"><span class="swatch swatch-dot"></span>Prognose</button>'
            . '<button type="button" class="toggle-chip c-ink" aria-pressed="true" data-chart-series="actual"><span class="swatch swatch-dot"></span>Gemessen</button>'
            . '</div></div>',
        'style' => [
            'forecast' => ['color' => 'solar', 'type' => 'area', 'peak' => true, 'label' => 'Prognose', 'unit' => 'kW', 'decimals' => 2],
            'actual' => ['color' => 'ink', 'width' => 'bold', 'label' => 'Gemessen', 'unit' => 'kW', 'decimals' => 2],
        ],
        'empty' => 'Noch keine Prognose. Die DWD-Datei wird beim nächsten Abruf geladen.',
    ]) ?>
    <div class="day-values">
      <?= ui_metric('Heute, Rest', ui_live_num('remaining_kwh', $f['remaining_kwh'] ?? null, 'kWh'), ['after' => '<span class="caption muted">von ' . e(kwh($f['today_kwh'] ?? null)) . '</span>' . ui_spread($f['today_sd'] ?? null)]) ?>
      <?= ui_metric('Morgen', ui_num($f['tomorrow_kwh'] ?? null, 'kWh'), ['after' => ui_spread($f['tomorrow_sd'] ?? null)]) ?>
      <?= ui_metric('Übermorgen', ui_num($after !== null ? (float) $after : null, 'kWh'), ['after' => ui_spread($captions[$day(2)]['sd'] ?? null)]) ?>
    </div>
    <p class="caption muted">Gemessen heute <?= e(kwh($yield)) ?>, gestern <?= e(kwh($yesterday)) ?>. <?= e($stamp) ?></p>
  </section>
  <section class="card stack" aria-labelledby="quality-title">
    <h2 class="card-title" id="quality-title">Ist und Prognose</h2>
    <?= ui_chart('bars', '/api/series?chart=daily', 'Ist und Prognose der abgeschlossenen Tage mit Güte', [
        'window' => '3',
        'tools' => '<div class="chart-tools">' . ui_segment('daily-window', 'Fenster', ['3' => '3 Tage', '7' => '7 Tage', 'month' => 'Monat', 'quarter' => 'Quartal'], '3', ['compact' => true, 'id' => 'dw', 'attrs' => ['data-chart-window' => true]]) . '</div>',
        'style' => [
            'actual' => ['color' => 'solar', 'unit' => 'kWh', 'decimals' => 1],
            'model' => ['color' => 'grid-out', 'unit' => 'kWh', 'decimals' => 1],
            'k' => ['color' => 'ink', 'unit' => '', 'decimals' => 2, 'legendSum' => false],
            'ideal' => ['color' => 'muted'],
        ],
        'empty' => 'Die Güte braucht mindestens einen abgeschlossenen Tag mit Ertrag und Prognose.',
    ]) ?>
    <p class="body">Güte <?= ui_inline('<strong data-goodness-ratio>' . e((string) ($score['text'] ?? '—')) . '</strong>', ['data-open-dialog' => 'forecast-method', 'aria-haspopup' => 'dialog']) ?> über <span data-goodness-days><?= (int) ($score['days'] ?? 0) ?></span> abgeschlossene Tage. Über 1 lag die Prognose höher als der Zähler.</p>
  </section>
</div>
<section class="section" aria-labelledby="details-title">
  <h2 class="section-title" id="details-title">Details</h2>
  <ul class="detail-list" role="list">
<?php foreach ([
    ['forecast-method', 'scale', 'Rechnung', 'Vom DWD-Lauf über Eichfaktor und Abweichung bis zur Güte, mit den Zahlen von heute.', is_array($lesson)],
    ['forecast-models', 'chart-column', 'Modelle', 'Fünf kommende und fünf vergangene Tage mit Rohmodell, Faktor und Regression.', true],
    ['forecast-data', 'list', 'Prognosedaten', 'Prognose, Abweichung, Strahlung, Sonne, Wolken und Temperatur je Tag.', true],
    ['forecast-compare', 'chart-line', 'Faktor gegen Regression', 'Rohmodell, starrer Faktor und Regression neben dem Ist.', true],
    ['forecast-weather', 'cloud-sun', 'Wetter vom DWD', 'Strahlung, Sonnenschein, Bewölkung und Temperatur.', true],
    ['forecast-howto', 'info', 'Wie gerechnet wird', 'Dach, Ausrichtung, Verlustkette und Eichfaktor dieser Anlage.', true],
] as [$id, $glyph, $title, $text, $show]): ?>
<?php if ($show): ?>
    <li><button type="button" class="detail-item" data-open-dialog="<?= e($id) ?>" aria-haspopup="dialog"><?= icon($glyph, 'icon-20') ?><span><span class="detail-item-title"><?= e($title) ?></span><span class="detail-item-text"><?= e($text) ?></span></span><?= icon('chevron-right', 'icon-16') ?></button></li>
<?php endif; ?>
<?php endforeach; ?>
  </ul>
</section>
<?php
if (is_array($lesson)) {
    forecast_method_dialog($lesson, $plant, $modelRows, (float) (store()->defaults()['plant']['factor'] ?? 0.93), $weather);
}

// Modelle
ob_start();
?>
<p class="body muted">Prognose ist der Mittelwert der DWD-Läufe mal Eichfaktor, mit der Abweichung dieser Läufe. Der laufende Tag ist hervorgehoben.</p>
<div class="table-wrap" tabindex="0" role="region" aria-label="Modelle">
  <table class="table table-compact">
    <thead><tr><th scope="col">Tag</th><th scope="col" class="num-col">Ist</th><th scope="col" class="num-col">Prognose</th><th scope="col" class="num-col">Güte</th><th scope="col" class="num-col">Rohmodell</th><th scope="col" class="num-col">Faktor</th><th scope="col" class="num-col">Regression</th></tr></thead>
    <tbody>
<?php if (!$modelRows): ?>
      <tr><td colspan="7">Für die kommenden und vergangenen Tage liegt noch keine Prognose.</td></tr>
<?php endif; ?>
<?php foreach ($modelRows as $row): ?>
      <tr<?= !empty($row['today']) ? ' data-current' : '' ?>>
        <th scope="row" class="nowrap"><?= e(day_label((string) $row['day'])) ?></th>
        <td class="num-col"><?= e(kwh($row['actual'] !== null ? (float) $row['actual'] : null)) ?></td>
        <td class="num-col nowrap"><?= e(Forecast::captionText($row['model'] !== null ? (float) $row['model'] : null, isset($row['sd']) && $row['sd'] !== null ? (float) $row['sd'] : null) ?? '—') ?></td>
        <td class="num-col"><?= $row['gute'] === null ? '—' : e(num((float) $row['gute'], 2)) ?></td>
        <td class="num-col"><?= e(kwh($row['raw'] !== null ? (float) $row['raw'] : null)) ?></td>
        <td class="num-col"><?= e(kwh($row['fitted'] !== null ? (float) $row['fitted'] : null)) ?></td>
        <td class="num-col"><?= e(kwh(($row['regress'] ?? null) !== null ? (float) $row['regress'] : null)) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
echo ui_dialog('forecast-models', 'Modelle', ob_get_clean(), ['wide' => true]);

// Prognosedaten
$archive = static function (array $rows, string $empty, string $today, string $label): string {
    $html = '<div class="table-wrap" tabindex="0" role="region" aria-label="' . e($label) . '"><table class="table table-compact"><thead><tr><th scope="col">Tag</th><th scope="col" class="num-col">Ertrag</th><th scope="col" class="num-col">Prognose</th><th scope="col" class="num-col">Abweichung</th><th scope="col" class="num-col">Strahlung</th><th scope="col" class="num-col">Sonne</th><th scope="col" class="num-col">Wolken</th><th scope="col" class="num-col">Temperatur</th><th scope="col" class="num-col">Läufe</th></tr></thead><tbody>';
    if (!$rows) {
        return $html . '<tr><td colspan="9">' . e($empty) . '</td></tr></tbody></table></div>';
    }
    foreach ($rows as $row) {
        $html .= '<tr' . ($today !== '' && (string) $row['day'] === $today ? ' data-current' : '') . '>'
            . '<th scope="row" class="nowrap">' . e(day_label((string) $row['day'])) . '</th>'
            . '<td class="num-col">' . e(kwh($row['actual'] !== null ? (float) $row['actual'] : null)) . '</td>'
            . '<td class="num-col">' . e(kwh($row['mean'] !== null ? (float) $row['mean'] : null)) . '</td>'
            . '<td class="num-col">' . ($row['sd'] === null ? '—' : '± ' . e(kwh((float) $row['sd']))) . '</td>'
            . '<td class="num-col">' . ($row['radiation'] === null ? '—' : e(num((float) $row['radiation'] / 1000, 2)) . NNBSP . 'kWh/m²') . '</td>'
            . '<td class="num-col">' . ($row['sunshine_s'] === null ? '—' : e(num((float) $row['sunshine_s'] / 3600, 1)) . NNBSP . 'h') . '</td>'
            . '<td class="num-col">' . ($row['cloud'] === null ? '—' : e(pct((float) $row['cloud']))) . '</td>'
            . '<td class="num-col">' . ($row['temp_c'] === null ? '—' : e(num((float) $row['temp_c'], 1)) . NNBSP . '°C') . '</td>'
            . '<td class="num-col">' . (int) $row['n'] . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
};
$body = '<p class="body muted">Oben der laufende Tag und fünf kommende Tage, darunter die abgeschlossenen Tage. Dieselben Werte stehen über der Kurve.</p>'
    . $archive($upcoming, 'Für heute und die kommenden Tage liegt noch kein gespeicherter Modelllauf.', $todayKey, 'Kommende Tage')
    . ui_divider('Vergangene Tage')
    . $archive($past, 'Noch keine abgeschlossenen Tage.', '', 'Vergangene Tage');
echo ui_dialog('forecast-data', 'Prognosedaten', $body, ['wide' => true]);

// Faktor gegen Regression
echo ui_dialog('forecast-compare', 'Faktor gegen Regression', '<p class="body muted">Rohmodell ohne Eichfaktor, daneben der starre Faktor und die Regression. Die Regression greift erst ab fünf Tagen.</p>'
    . ui_chart('bars', '/api/series?chart=compare', 'Ist, Rohmodell, Faktor und Regression je Tag', [
        'style' => [
            'actual' => ['color' => 'solar', 'unit' => 'kWh', 'decimals' => 1],
            'model' => ['color' => 'muted', 'dash' => false, 'unit' => 'kWh', 'decimals' => 1],
            'fitted' => ['color' => 'v1', 'unit' => 'kWh', 'decimals' => 1],
            'regress' => ['color' => 'grid-out', 'unit' => 'kWh', 'decimals' => 1],
        ],
        'empty' => 'Noch keine Tage zum Vergleichen.',
    ]), ['wide' => true]);

// Wetter
echo ui_dialog('forecast-weather', 'Wetter vom DWD', '<p class="body muted">Strahlung, Sonnenschein, Bewölkung und Temperatur aus der MOSMIX-Datei, heute und die folgenden Tage.</p>'
    . ui_chart('time', '/api/series?chart=weather', 'Wetter aus der DWD-Prognose', [
        'window' => '3',
        'tools' => '<div class="chart-tools">' . ui_segment('weather-window', 'Zeitraum', ['today' => 'Heute', '3' => '3 Tage', '7' => '7 Tage'], '3', ['compact' => true, 'id' => 'ww', 'attrs' => ['data-chart-window' => true]]) . '</div>',
        'style' => [
            'radiation' => ['color' => 'solar', 'unit' => 'W/m²', 'decimals' => 0],
            'temp' => ['color' => 'price', 'width' => 'thin', 'unit' => '°C', 'decimals' => 1],
            'sun' => ['color' => 'grid-out', 'width' => 'thin', 'unit' => 'min', 'decimals' => 0],
            'cloud' => ['color' => 'v1', 'width' => 'thin', 'dash' => true, 'unit' => '%', 'decimals' => 0],
        ],
        'empty' => 'Noch keine Wetterdaten.',
    ]), ['wide' => true]);

// Wie gerechnet wird
ob_start();
?>
<dl class="kv">
  <div><dt>Anlage</dt><dd><?= e(num((float) $plant['kwp'], 2)) ?>&#8239;kWp, Wechselrichter-Limit <?= e(kw((float) $plant['inverter_kw'])) ?></dd></div>
  <div><dt>Dach</dt><dd>Neigung <?= e(num((float) $plant['tilt'], 0)) ?>°, Ausrichtung <?= e(num((float) $plant['azimuth'], 0)) ?>°</dd></div>
  <div><dt>Eichfaktor</dt><dd><?= e(num((float) $plant['factor'], 2)) ?><?= !empty($plant['factor_locked']) ? '<span class="sub">festgehalten</span>' : '' ?></dd></div>
  <div><dt>Verfahren</dt><dd><?= e(Forecast::methodLabel($plant)) ?><span class="sub"><?php if ((int) $plant['regress_days'] >= 5): ?>Ist = <?= e(num((float) $plant['regress_a'], 2)) ?> + <?= e(num((float) $plant['regress_b'], 2)) ?> × Rohmodell, aktiv seit <?= (int) $plant['regress_days'] ?> Tagen.<?php else: ?>Die Regression startet ab fünf abgeschlossenen Tagen<?= (int) $plant['regress_days'] ? ' (' . (int) $plant['regress_days'] . ' bisher)' : '' ?>.<?php endif; ?></span></dd></div>
</dl>
<?php formula('<mrow><mi>P</mi><mo>=</mo><mo>min</mo><mo>(</mo><mtext>Limit</mtext><mo>,</mo><mi>G</mi><mo>×</mo><mo>(</mo>' . mnum((float) $plant['kwp'], 2) . '<mo>×</mo><mn>1,04</mn><mo>×</mo><mn>0,90</mn><mo>×</mo><mn>0,975</mn><mo>/</mo><mn>1000</mn><mo>)</mo><mo>×</mo><mi>f</mi><mo>)</mo></mrow>'); ?>
<p class="body">Jede Stunde der DWD-Datei zählt einmal. Die Zahl über einem Tag ist der Mittelwert der gespeicherten Läufe mal Eichfaktor, mit der Streuung dieser Läufe. Der Ertrag kommt vom Energiezähler. Die Güte benutzt nur abgeschlossene Tage, an denen Ist und Prognose vollständig sind.</p>
<p class="body">Strahlung, Bewölkung, Sonnenschein und Temperatur kommen aus der DWD-Datei. Jeder Modelllauf bleibt gespeichert, sobald der Tag ab Mitternacht in der Datei steht.</p>
<p><?= ui_inline('Anlagenwerte ändern', ['href' => url('/einstellungen/prognose')], 'align-start') ?></p>
<?php
echo ui_dialog('forecast-howto', 'Wie gerechnet wird', ob_get_clean());
view('partials/overview', ['overview' => $overview]);
