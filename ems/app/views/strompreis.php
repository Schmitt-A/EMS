<?php
declare(strict_types=1);
/**
 * Prognose → Strompreis: Preis jetzt im aktiven Tarif, Börse, heute und morgen, das Diagramm mit dynamischem und
 * statischem Preis, Börse und Einspeisung (14 Tage zurück bis zum letzten bekannten Preis) und den aktiven
 * Ladefenstern. Darunter die Ladefenster selbst (je Tag das günstigste Fenster, an- und abschaltbar, eigene Fenster),
 * die Entscheidungshilfe je Monat mit eigenen Annahmen und der Vergleich mit dem gemessenen Netzbezug. Daten aus
 * PriceView.
 * @var array $price
 * @var array $year YearCheck::build(): Wochen, Jahr, Sonne, Vorschläge und Annahmen
 * @var array $overview
 */
$now = $price['now'];
$cmp = $price['compare'];
$tz = new DateTimeZone('Europe/Berlin');
$ts = time();
$when = static function (int $ts) use ($tz): string {
    $local = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $day = $local->format('Y-m-d');
    $prefix = $day === $today ? 'heute' : ($day === (new DateTimeImmutable('tomorrow', $tz))->format('Y-m-d') ? 'morgen' : short_day_label($local));
    return $prefix . ' ' . $local->format('H:i');
};
$range = static fn (?array $w): string => $w === null ? '—' : $when($w['start']) . '–' . (new DateTimeImmutable('@' . $w['end']))->setTimezone($tz)->format('H:i');
// Das Ende der Preise ist Mitternacht; „bis morgen 24:00“ liest sich besser als „bis übermorgen 00:00“.
$until = static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i') === '00:00' ? str_replace(' 23:59', ' 24:00', $when($ts - 60)) : $when($ts);
$dynamicActive = $now['mode'] === 'dynamic';
$active = $now['import_ct'];
$tomorrowKnown = $price['tomorrow'] !== null;
$money = static fn (float $v): string => euro($v);
$spanLinks = [];
foreach (PriceView::SPANS as $key => $label) {
    $spanLinks['/prognose/strompreis?vergleich=' . $key] = $label;
}
$wc = $price['windows_config'];
$post = static fn (string $section): string => '<input type="hidden" name="section" value="' . e($section) . '"><input type="hidden" name="back" value="/prognose/strompreis">' . csrf_field();

echo ui_page_head('Prognose', ui_head_chip('zap', e($active === null ? '—' : ct((float) $active)), 'Strompreis jetzt ' . ($active === null ? 'unbekannt' : ct((float) $active)) . '. Energieübersicht öffnen'));
echo ui_seg_links('Prognose', ['/prognose' => 'Sonne', '/prognose/strompreis' => 'Strompreis'], '/prognose/strompreis');
if (!empty($price['meta']['error']) && $price['horizon'] < time()) {
    echo ui_notice('circle-alert', e('Börsenpreise: ' . $price['meta']['error']), 'error');
}
?>
<div class="stack">
<section class="card stack price-card" aria-labelledby="price-title">
  <h2 class="card-title" id="price-title">Strompreis</h2>
  <div class="price-now">
    <?= ui_metric($dynamicActive ? 'Jetzt, dynamisch' : 'Jetzt, statisch', ui_num($active === null ? null : (float) $active, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($dynamicActive ? 'Börse plus deine Aufschläge' : 'dynamisch wären es ' . ct($now['dynamic_ct'] === null ? null : (float) $now['dynamic_ct'])) . '</span>']) ?>
    <?= ui_metric('Börse jetzt', ui_num($now['spot_ct'] === null ? null : (float) $now['spot_ct'], 'ct/kWh'), ['after' => '<span class="caption muted">netto, ohne Aufschläge</span>']) ?>
    <?= ui_metric('Heute dynamisch', ui_num($price['today']['avg'] ?? null, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($price['today'] ? 'Ø, ' . ct((float) $price['today']['min']) . ' bis ' . ct((float) $price['today']['max']) : 'noch keine Preise') . '</span>']) ?>
    <?= ui_metric('Morgen dynamisch', ui_num($price['tomorrow']['avg'] ?? null, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($tomorrowKnown ? 'Ø, ' . ct((float) $price['tomorrow']['min']) . ' bis ' . ct((float) $price['tomorrow']['max']) : 'Preise ab etwa 13 Uhr') . '</span>']) ?>
  </div>
  <?= ui_chart('time', '/api/series?chart=price', 'Strompreis je Viertelstunde: dynamisch, statisch, Börse und Einspeisung, die aktiven Ladefenster hinterlegt', [
      'window' => '2',
      'class' => 'chart-xl',
      'tools' => '<div class="chart-tools">' . ui_segment('price-window', 'Zeitraum', ['today' => 'Heute', '2' => '2 Tage', '7' => '7 Tage'], '2', ['compact' => true, 'id' => 'prw', 'attrs' => ['data-chart-window' => true]])
          . '<div class="chart-toggles">'
          . '<button type="button" class="toggle-chip c-price" aria-pressed="true" data-chart-series="dynamic"><span class="swatch swatch-dot"></span>Dynamisch</button>'
          . '<button type="button" class="toggle-chip c-v1" aria-pressed="true" data-chart-series="static"><span class="swatch swatch-dot"></span>Statisch</button>'
          . '<button type="button" class="toggle-chip c-muted" aria-pressed="true" data-chart-series="spot"><span class="swatch swatch-dot"></span>Börse</button>'
          . '<button type="button" class="toggle-chip c-grid-out" aria-pressed="true" data-chart-series="export"><span class="swatch swatch-dot"></span>Einspeisung</button>'
          . '</div></div>',
      'style' => [
          'dynamic' => ['type' => 'step', 'width' => $dynamicActive ? 'bold' : null, 'dash' => !$dynamicActive, 'unit' => 'ct/kWh', 'decimals' => 1],
          'static' => ['type' => 'step', 'width' => $dynamicActive ? null : 'bold', 'dash' => $dynamicActive, 'unit' => 'ct/kWh', 'decimals' => 1],
          'spot' => ['type' => 'step', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1],
          'export' => ['type' => 'step', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1],
      ],
      'empty' => 'Noch keine Börsenpreise. Der Recorder holt sie gleich.',
  ]) ?>
  <p class="caption muted">Dick der Tarif, nach dem du bezahlst, gestrichelt der andere. Mit dynamischem Tarif sind die aktiven Ladefenster hinterlegt, kräftiger die Viertelstunden, die ein Ladeplan dazunimmt, und der Preisbereich als waagerechtes Band. Je Tag stehen der teuerste und der günstigste dynamische Preis. Ändern unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</p>
  <p class="caption muted"><?= e(MarketPrices::SOURCE) ?>.</p>
</section>

<?php
// Ladefenster: mit dynamischem Tarif direkt, mit statischem als aufklappbare Vorschau (gleiche Bedienung).
ob_start();
?>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="form-rows" data-autosubmit>
    <?= $post('windows') ?>
    <?= ui_form_row('Günstigstes Fenster je Tag', ui_select('length_h', array_combine(array_map('strval', PriceWindows::LENGTHS), array_map(static fn (int $h): string => $h . ($h === 1 ? ' Stunde' : ' Stunden'), PriceWindows::LENGTHS)), (string) $wc['length_h']), ['for' => 'f-length_h']) ?>
    <noscript><div class="form-actions"><button class="btn btn-secondary" type="submit">Länge speichern</button></div></noscript>
  </form>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack window-range">
    <?= $post('window_range') ?>
    <fieldset class="window-range-set">
      <legend class="form-label">Preisbereich<span class="form-hint">Jede Zeit, in der der Preis darin liegt, wird ein Ladefenster, auch für alle kommenden Tage. „Von“ leer heißt ohne Untergrenze.</span></legend>
      <div class="window-range-fields">
        <label for="f-range_from"><span class="caption muted">von, ct/kWh</span><?= ui_input('range_from', $wc['range_from'] === null ? '' : ui_field_num((float) $wc['range_from'], 1), ['id' => 'f-range_from', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '–']) ?></label>
        <label for="f-range_to"><span class="caption muted">bis, ct/kWh</span><?= ui_input('range_to', $wc['range_to'] === null ? '' : ui_field_num((float) $wc['range_to'], 1), ['id' => 'f-range_to', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => 'aus']) ?></label>
      </div>
    </fieldset>
    <div class="form-actions">
      <button class="btn btn-secondary" type="submit">Preisbereich speichern</button>
<?php if ($wc['range_to'] !== null): ?>
      <button class="btn btn-secondary" type="submit" name="clear" value="1">Preisbereich aus</button>
<?php endif; ?>
    </div>
  </form>
<?php if (!$price['windows']): ?>
  <p class="body muted">Noch keine Preise für heute, die Fenster erscheinen mit ihnen.</p>
<?php else: ?>
  <ul class="window-list" role="list">
<?php foreach ($price['windows'] as $w): ?>
<?php
    $status = PriceWindows::status($w, $ts);
    $label = ucfirst(PriceWindows::label($w, $ts));
    $kind = match ($w['kind']) {
        'custom' => 'eigenes',
        'range' => 'Preisbereich',
        default => 'günstigstes je Tag',
    };
    $state = match ($status) {
        'past' => 'vorbei',
        'running' => 'läuft',
        default => '',
    };
    $meta = implode(' · ', array_filter([$w['ct'] === null ? 'Preis noch offen' : 'Ø ' . ct((float) $w['ct']), $kind, $state]));
?>
    <li class="window-item" data-status="<?= e($status) ?>" data-kind="<?= e($w['kind']) ?>"<?= $w['on'] ? '' : ' data-off' ?>>
      <div class="window-main"><span class="window-when num"><?= e($label) ?></span><span class="caption muted"><?= e($meta) ?></span></div>
<?php if ($w['kind'] === 'custom'): ?>
      <form method="post" action="<?= e(url('/einstellungen')) ?>">
        <?= $post('window_delete') ?><input type="hidden" name="start" value="<?= (int) $w['start'] ?>"><input type="hidden" name="end" value="<?= (int) $w['end'] ?>">
        <?= '<button type="submit" class="icon-btn" aria-label="' . e('Ladefenster ' . $label . ' löschen') . '">' . icon('x', 'icon-20') . '</button>' ?>
      </form>
<?php else: ?>
      <form method="post" action="<?= e(url('/einstellungen')) ?>" data-autosubmit>
        <?= $post('window_toggle') ?>
<?php if ($w['kind'] === 'range'): ?>
        <input type="hidden" name="start" value="<?= (int) $w['start'] ?>">
<?php else: ?>
        <input type="hidden" name="day" value="<?= e($w['day']) ?>">
<?php endif; ?>
        <?= ui_toggle('on', (bool) $w['on'], 'Ladefenster ' . $label, ['id' => 'f-win-' . $w['id']]) ?>
        <noscript><button class="btn btn-secondary" type="submit">Speichern</button></noscript>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <details class="window-add">
    <summary><?= icon('plus', 'icon-16') ?><span>Eigenes Fenster anlegen</span></summary>
    <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
      <?= $post('window_add') ?>
      <div class="form-rows">
<?php
$days = [];
for ($i = 0; $i < 3; $i++) {
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->setTime(12, 0)->modify('+' . $i . ' day');
    $days[$d->format('Y-m-d')] = $i === 0 ? 'Heute' : ($i === 1 ? 'Morgen' : short_day_label($d));
}
?>
        <?= ui_form_row('Tag', ui_select('day', $days, (string) array_key_first($days), ['id' => 'f-window-day']), ['for' => 'f-window-day']) ?>
        <?= ui_form_row('Von', ui_input('from', '01:00', ['id' => 'f-window-from', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-window-from']) ?>
        <?= ui_form_row('Bis', ui_input('to', '04:00', ['id' => 'f-window-to', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-window-to', 'hint' => 'Ein Ende vor dem Beginn meint den nächsten Tag.']) ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Fenster anlegen</button></div>
    </form>
  </details>
<?php
$windowsUi = ob_get_clean();
$chosen = $price['users']['car_chosen'];
$boostWindow = $price['users']['battery'];
?>
<section class="card stack" aria-labelledby="windows-title">
  <h2 class="card-title" id="windows-title">Ladefenster</h2>
<?php if (!$price['windows_enabled']): ?>
  <p class="body-sm muted">Mit deinem statischen Tarif kostet jede Viertelstunde gleich viel, Ladefenster gibt es deshalb erst mit dynamischem Tarif. Die Vorschau zeigt, wie sie mit deinen Aufschlägen aussähen; was du dort einstellst, gilt, sobald du unter Einstellungen → Tarif auf dynamisch wechselst.</p>
  <details class="window-preview">
    <summary><?= icon('chevron-down', 'icon-16') ?><span>Vorschau</span></summary>
    <div class="stack window-preview-body"><?= $windowsUi ?></div>
  </details>
<?php else: ?>
  <p class="body-sm muted">Je Tag sucht EMS das günstigste Fenster am Stück, sobald die Preise da sind; gegen 13 Uhr kommen die für morgen dazu, mit einer Preis-Entität von Tibber oder Octopus auch deren Preise. Dazu kommen die Zeiten in deinem Preisbereich und eigene Fenster. Aus den aktiven Fenstern wählst du in der Ladepunkt-Karte die fürs Auto und unter Speicher → Ausblick das für den Energie-Boost.</p>
  <?= $windowsUi ?>
  <ul class="plain-list stack-tight body-sm" role="list">
    <li>Auto: <?= e($price['users']['car'] !== 'windows'
        ? 'Netzladen steht auf „' . Energy::gridLabel((string) $price['users']['car']) . '“.'
        : ($chosen ? 'lädt in ' . implode(', ', array_map(static fn (array $w): string => PriceWindows::label($w, $ts), $chosen)) . '.' : 'In Ladefenstern, aber noch kein Fenster gewählt.')) ?> <?= ui_inline('Laden', ['href' => url('/') . '#cp']) ?></li>
    <li>Speicher: <?= e($boostWindow !== null ? 'Energie-Boost ' . PriceWindows::label($boostWindow, $ts) . ' bis ' . pct((float) $boostWindow['target_soc']) . '.' : 'kein Energie-Boost geplant.') ?> <?= ui_inline('Speicher → Ausblick', ['href' => url('/speicher') . '#outlook-title']) ?></li>
<?php if ($price['plan'] !== null): ?>
    <li><?= e(ChargePlan::text($price['plan'])) ?></li>
<?php endif; ?>
  </ul>
<?php endif; ?>
  <p class="caption muted">Ab jetzt am günstigsten: <?= e($range($price['cheap']['hour'])) ?><?= $price['cheap']['hour'] ? ' · ' . e(ct((float) $price['cheap']['hour']['ct'])) : '' ?>, am teuersten: <?= e($range($price['cheap']['dear'])) ?><?= $price['cheap']['dear'] ? ' · ' . e(ct((float) $price['cheap']['dear']['ct'])) : '' ?>, je eine Stunde, dynamisch. Preise bis <?= e($price['horizon'] > 0 ? $until($price['horizon']) : '—') ?>.<?= $tomorrowKnown ? '' : ' Die Preise für morgen gibt es ab etwa 13 Uhr.' ?></p>
</section>

<?php
// Heatmap nach Kalenderwochen: je Kennzahl Farbstärke (0…1 über alle Wochen), kurze Zahl und Text für Screenreader.
$metrics = [
    'day' => ['label' => 'Ø Tag', 'color' => 'price', 'unit' => 'ct/kWh', 'decimals' => 1, 'caption' => 'Ø dynamischer Preis über den ganzen Tag, mit deinen Aufschlägen von heute.'],
    'window' => ['label' => 'Fenster', 'color' => 'price', 'unit' => 'ct/kWh', 'decimals' => 1, 'caption' => 'Ø dynamischer Preis im günstigsten Fenster des Tages (' . $year['length_h'] . ' h).'],
    'save' => ['label' => 'Spart', 'color' => 'success', 'unit' => 'ct/kWh', 'decimals' => 1, 'caption' => 'Ersparnis je kWh im Ladefenster gegenüber deinem statischen Preis von ' . ct($year['static_ct']) . '.'],
    'sun' => ['label' => 'Sonne', 'color' => 'solar', 'unit' => 'kWh', 'decimals' => 0, 'caption' => 'Ertrag deiner Anlage je Woche in einem üblichen Jahr.'],
    'cover' => ['label' => 'Deckung', 'color' => 'grid-out', 'unit' => '%', 'decimals' => 0, 'caption' => 'Anteil von Haus und Auto, den Sonne und Speicher in der Woche decken.'],
];
$weeks = $year['weeks'];
$range = [];
foreach (array_keys($metrics) as $key) {
    $values = array_values(array_filter(array_column($weeks, $key), static fn (mixed $v): bool => $v !== null));
    $range[$key] = $values ? [min($values), max($values)] : [0.0, 0.0];
}
$cellAttrs = static function (int $week, array $row) use ($metrics, $range): array {
    $attrs = ['data-week' => $week];
    foreach ($metrics as $key => $m) {
        $value = $row[$key];
        [$lo, $hi] = $range[$key];
        $attrs['data-v-' . $key] = $value === null ? '' : (string) round($hi - $lo > 1e-9 ? ($value - $lo) / ($hi - $lo) : 0.5, 3);
        $attrs['data-n-' . $key] = $value === null ? '' : num((float) $value, 0);
        $attrs['data-t-' . $key] = 'KW ' . $week . ': ' . ($value === null ? 'ohne Preis' : with_unit((float) $value, $m['decimals'], $m['unit']));
    }
    return $attrs;
};
$y = $year['year'];
$pvNote = match ($year['pv']['source']) {
    'pvgis' => $year['pv']['weather_year'] ? 'PVGIS, Wetter von ' . $year['pv']['weather_year'] . ' an deinem Standort, Stunde für Stunde' : 'PVGIS, übliches Jahr an deinem Standort',
    'demo' => 'Beispielwerte',
    'pending' => 'Schätzung, PVGIS wird gerade abgefragt',
    default => 'Schätzung aus 950 kWh je kWp' . ($year['pv']['error'] ? ', ' . $year['pv']['error'] : ''),
};
?>
<section class="card stack" aria-labelledby="decide-title">
  <h2 class="card-title" id="decide-title">Lohnt sich dynamisch?</h2>
  <p class="body-sm muted">Ein Jahr Woche für Woche: Börsenpreise der letzten 12 Monate mit deinen Preisen von heute aus <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?> und der Ertrag deiner Anlage in einem üblichen Jahr.</p>
  <?= ui_segment('wk-metric', 'Kennzahl', array_map(static fn (array $m): string => $m['label'], $metrics), 'save', ['compact' => true, 'id' => 'wkm', 'attrs' => ['data-week-metric' => true]]) ?>
  <figure class="hm wk" data-weekmap data-color="success"<?= ui_attrs(['data-metrics' => ui_json(array_map(static fn (array $m): array => ['color' => $m['color'], 'caption' => $m['caption']], $metrics))]) ?>>
    <ol class="wk-grid" role="list" aria-label="Kalenderwochen">
<?php foreach ($weeks as $week => $row): ?>
      <li class="hm-cell wk-cell"<?= ui_attrs($cellAttrs((int) $week, $row)) ?>><span class="hm-d" aria-hidden="true"><?= (int) $week ?></span><span class="hm-n" aria-hidden="true" data-wk-n><?= e($row['save'] === null ? '' : num((float) $row['save'], 0)) ?></span><span class="sr-only" data-wk-t><?= e('KW ' . $week . ': ' . ($row['save'] === null ? 'ohne Preis' : with_unit((float) $row['save'], 1, 'ct/kWh'))) ?></span></li>
<?php endforeach; ?>
    </ol>
    <figcaption class="caption muted" data-wk-caption><?= e($metrics['save']['caption']) ?></figcaption>
  </figure>
  <form method="get" action="<?= e(url('/prognose/strompreis')) ?>#decide-title" class="stack">
    <input type="hidden" name="vergleich" value="<?= e((string) $price['span']) ?>">
    <div class="form-rows">
      <?= ui_number_row('haus', 'Haus', (float) $year['inputs']['house_kwh'], 0, 'kWh im Jahr. Vorschlag ' . kwh($year['suggest']['house_kwh'], 0) . ': ' . $year['suggest']['notes']['house'], ['inputmode' => 'numeric']) ?>
      <?= ui_number_row('km', 'Auto', (float) $year['inputs']['car_km'], 0, 'km im Jahr. Vorschlag ' . num($year['suggest']['car_km'], 0) . ' km: ' . $year['suggest']['notes']['km'], ['inputmode' => 'numeric']) ?>
      <?= ui_number_row('verbrauch', 'Verbrauch Auto', (float) $year['inputs']['car_kwh100'], 1, 'kWh je 100 km. Vorschlag ' . num($year['suggest']['car_kwh100'], 1) . ': ' . $year['suggest']['notes']['use']) ?>
    </div>
    <div class="form-actions"><button class="btn btn-secondary" type="submit">Neu rechnen</button></div>
  </form>
<?php if ($y['priced_days'] < 30): ?>
  <p class="body muted">Für das Jahr fehlen noch Börsenpreise; EMS lädt sie nach und nach bis zwei Jahre zurück.</p>
<?php else: ?>
  <p class="compare-verdict"><?= e($y['difference'] >= 0 ? 'Dynamisch wäre im Jahr etwa ' . euro($y['difference']) . ' günstiger.' : 'Statisch wäre im Jahr etwa ' . euro(-$y['difference']) . ' günstiger.') ?></p>
  <dl class="kv">
    <div><dt>Sonne im Jahr</dt><dd><?= e(kwh((float) $y['pv_kwh'], 0)) ?><span class="sub"><?= e($pvNote) ?></span></dd></div>
    <div><dt>Haus und Auto</dt><dd><?= e(kwh((float) $y['demand_kwh'], 0)) ?><span class="sub"><?= e('Haus ' . kwh((float) $y['house_kwh'], 0) . ', Auto ' . kwh((float) $y['car_kwh'], 0) . ' an der Wallbox') ?></span></dd></div>
    <div><dt>Sonne und Speicher decken</dt><dd><?= e(pct($y['cover_pct'] === null ? null : (float) $y['cover_pct'])) ?><span class="sub"><?= e('Mit ' . kwh($year['battery_kwh'], 1) . ' Speicher, der nur von einem Tag auf den nächsten hilft; Überschuss im Sommer geht ins Netz (' . kwh((float) $y['export_kwh'], 0) . ').') ?></span></dd></div>
    <div><dt>Aus dem Netz</dt><dd><?= e(kwh((float) $y['grid_kwh'], 0)) ?><span class="sub"><?= e('Haus ' . kwh((float) $y['grid_house_kwh'], 0) . ', Auto ' . kwh((float) $y['grid_car_kwh'], 0)) ?></span></dd></div>
    <div><dt>Statisch</dt><dd><?= e(euro((float) $y['static_eur'])) ?><span class="sub"><?= e(ct($year['static_ct']) . ' je kWh, mit Grundpreis') ?></span></dd></div>
    <div><dt>Dynamisch</dt><dd><?= e(euro((float) $y['dynamic_eur'])) ?><span class="sub"><?= e('Haus zum Preis der Stunde, Auto im Ladefenster' . ((float) $y['shift_kwh'] >= 1 ? ', der Speicher lädt ' . kwh((float) $y['shift_kwh'], 0) . ' im Fenster vor und spart ' . euro((float) $y['shift_eur']) : '') . ', mit Grundpreis') ?></span></dd></div>
  </dl>
  <ul class="plain-list stack-tight body-sm muted" role="list">
    <li>Haus und Auto verbrauchen gleichmäßig über das Jahr, das Haus nach seinem üblichen Tagesverlauf. Das Auto nimmt Sonnenüberschuss, den Rest aus dem Netz.</li>
<?php if ($year['base_missing']): ?>
    <li>Ein Grundpreis fehlt noch und zählt mit 0 €; eintragen unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</li>
<?php endif; ?>
<?php if ($y['priced_days'] < 360): ?>
    <li><?= e('Börsenpreise gibt es erst für ' . $y['priced_days'] . ' der letzten 365 Tage; die Kosten sind von diesen Tagen aufs Jahr hochgerechnet.') ?></li>
<?php endif; ?>
    <li>Für die Sonne schickt EMS Standort, kWp, Neigung und Ausrichtung an PVGIS. <?= e(YearCheck::SOURCE) ?>. Börsenpreise: <?= e(MarketPrices::SOURCE) ?>.</li>
  </ul>
<?php endif; ?>
</section>

<section class="card stack" aria-labelledby="compare-title">
  <h2 class="card-title" id="compare-title">Gemessen: statisch oder dynamisch?</h2>
  <?= ui_seg_links('Zeitraum des Vergleichs', $spanLinks, '/prognose/strompreis?vergleich=' . $price['span']) ?>
<?php if ($cmp['kwh'] < 0.01): ?>
  <p class="body muted">Für diesen Zeitraum gibt es noch keinen Netzbezug<?= $cmp['note'] ? ': ' . e($cmp['note']) : '' ?>.</p>
<?php else: ?>
<?php $diff = (float) $cmp['difference']; ?>
  <p class="compare-verdict"><?= e($diff >= 0
      ? 'Dynamisch hätte ' . euro($diff) . ' weniger gekostet.'
      : 'Statisch war ' . euro(-$diff) . ' günstiger.') ?></p>
  <p class="body-sm muted">Netzbezug <?= e(kwh((float) $cmp['kwh'], 0)) ?>, jede Zeit mit dem Tarif, der damals galt. Gezählt wird nur, was Home Assistant oder EMS gemessen hat; wie du tatsächlich bezahlt hast, steht unter „<?= e($dynamicActive ? 'Dynamisch' : 'Statisch') ?>“.</p>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Vergleich statisch und dynamisch">
  <table class="compare-table">
    <thead><tr><th scope="col">Posten</th><th scope="col">Statisch</th><th scope="col">Dynamisch</th></tr></thead>
    <tbody>
      <tr><th scope="row">Börsenpreis<span class="sub">netto</span></th><td>—</td><td><?= e($money((float) $cmp['dynamic']['spot'])) ?></td></tr>
      <tr><th scope="row">Netzentgelt<span class="sub">netto</span></th><td>—</td><td><?= e($money((float) $cmp['dynamic']['grid'])) ?></td></tr>
      <tr><th scope="row">Steuern und Umlagen<span class="sub">netto</span></th><td>—</td><td><?= e($money((float) $cmp['dynamic']['levies'])) ?></td></tr>
      <tr><th scope="row">Anbieteraufschlag<span class="sub">netto</span></th><td>—</td><td><?= e($money((float) $cmp['dynamic']['supplier'])) ?></td></tr>
      <tr><th scope="row">MwSt</th><td>—</td><td><?= e($money((float) $cmp['dynamic']['vat'])) ?></td></tr>
      <tr><th scope="row">Arbeitspreis<span class="sub">brutto</span></th><td><?= e($money((float) $cmp['static']['energy'])) ?></td><td>—</td></tr>
      <tr><th scope="row">Grundpreis<span class="sub">anteilig</span></th><td><?= e($money((float) $cmp['static']['base'])) ?></td><td><?= e($money((float) $cmp['dynamic']['base'])) ?></td></tr>
      <tr><th scope="row">Ø je kWh<span class="sub">ohne Grundpreis</span></th><td><?= e(ct($cmp['static']['avg_ct'] === null ? null : (float) $cmp['static']['avg_ct'])) ?></td><td><?= e(ct($cmp['dynamic']['avg_ct'] === null ? null : (float) $cmp['dynamic']['avg_ct'])) ?></td></tr>
    </tbody>
    <tfoot><tr><th scope="row">Summe</th><td><?= e($money((float) $cmp['static']['total'])) ?></td><td><?= e($money((float) $cmp['dynamic']['total'])) ?></td></tr></tfoot>
  </table>
  </div>
<?php if ($cmp['shift']['kwh'] > 0.5): ?>
  <p class="body">Hätte das Auto seinen Netzstrom (<?= e(kwh((float) $cmp['shift']['kwh'], 0)) ?>) am selben Tag in den günstigsten Viertelstunden geladen, wären dynamisch weitere <strong><?= e($money((float) $cmp['shift']['saving'])) ?></strong> drin gewesen.</p>
<?php endif; ?>
  <ul class="plain-list stack-tight body-sm muted" role="list">
<?php if ($cmp['base_missing']['static'] || $cmp['base_missing']['dynamic']): ?>
    <li>Ein Grundpreis fehlt noch und zählt mit 0 €; eintragen unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</li>
<?php endif; ?>
<?php if ($cmp['missing_kwh'] > 0.5): ?>
    <li>Für <?= e(kwh((float) $cmp['missing_kwh'], 0)) ?> fehlt noch der Börsenpreis; EMS lädt ältere Preise nach und nach, bis zwei Jahre zurück.</li>
<?php endif; ?>
    <li><?= e($cmp['quarter_share'] > 0.01
        ? pct($cmp['quarter_share'] * 100) . ' des Netzbezugs hat EMS selbst je Viertelstunde gemessen, den Rest je Stunde aus Home Assistant.'
        : 'Den Netzbezug liefert Home Assistant je Stunde; ab jetzt misst EMS ihn selbst je Viertelstunde, wie ein Smart Meter abrechnet.') ?></li>
    <li>Die Aufschläge sind deine Werte unter Einstellungen → Tarif; die Einspeisung bleibt in beiden Tarifen gleich.</li>
  </ul>
<?php endif; ?>
</section>
</div>
<?php
view('partials/overview', ['overview' => $overview]);
