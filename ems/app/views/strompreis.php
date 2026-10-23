<?php
declare(strict_types=1);
/**
 * Prognose → Strompreis, nur für den aktiven Tarif. Dynamisch: Preis jetzt, Börse, heute und morgen, das Diagramm mit
 * dynamischem Preis, Börse und Einspeisung (14 Tage zurück bis zum letzten bekannten Preis) und den aktiven
 * Ladefenstern, darunter die Ladefenster selbst (je Tag das günstigste Fenster, Preisbereich, eigene Fenster).
 * Statisch: Arbeitspreis, mit Zeitfenstern die Preise von Auto und Speicher jetzt, das Diagramm mit diesen Preisen und
 * die Zeitfenster als Liste. Für beide „Welcher Tarif lohnt sich?“: ein Jahr Tag für Tag (YearCheck) in drei Varianten
 * mit Heatmap nach Kalenderwochen.
 * @var array $price PriceView::build()
 * @var array $year YearCheck::build(): Wochen, Jahr, Sonne, Vorschläge und Annahmen
 * @var string $metric Kennzahl der Heatmap aus ?kennzahl, damit „Neu rechnen“ sie behält
 * @var array $overview
 */
$now = $price['now'];
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
<?php if ($dynamicActive): ?>
    <?= ui_metric('Jetzt', ui_num($active === null ? null : (float) $active, 'ct/kWh'), ['after' => '<span class="caption muted">dynamisch, Börse plus deine Aufschläge</span>']) ?>
    <?= ui_metric('Börse jetzt', ui_num($now['spot_ct'] === null ? null : (float) $now['spot_ct'], 'ct/kWh'), ['after' => '<span class="caption muted">netto, ohne Aufschläge</span>']) ?>
    <?= ui_metric('Heute', ui_num($price['today']['avg'] ?? null, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($price['today'] ? 'Ø, ' . ct((float) $price['today']['min']) . ' bis ' . ct((float) $price['today']['max']) : 'noch keine Preise') . '</span>']) ?>
    <?= ui_metric('Morgen', ui_num($price['tomorrow']['avg'] ?? null, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($tomorrowKnown ? 'Ø, ' . ct((float) $price['tomorrow']['min']) . ' bis ' . ct((float) $price['tomorrow']['max']) : 'Preise ab etwa 13 Uhr') . '</span>']) ?>
<?php else: ?>
    <?= ui_metric('Arbeitspreis', ui_num($active === null ? null : (float) $active, 'ct/kWh'), ['after' => '<span class="caption muted">statisch, für das Haus immer</span>']) ?>
<?php foreach ($price['static_uses'] as $use => $info): ?>
    <?= ui_metric(Tariff::USES[$use] . ' jetzt', ui_num((float) $info['ct'], 'ct/kWh'), ['after' => '<span class="caption muted">' . e($info['text']) . '</span>']) ?>
<?php endforeach; ?>
<?php endif; ?>
  </div>
  <?= ui_chart('time', '/api/series?chart=price', 'Strompreis je Viertelstunde: dynamisch, statisch, Börse und Einspeisung, die aktiven Ladefenster hinterlegt', [
      'window' => '2',
      'class' => 'chart-xl',
      'id' => 'price-chart',
      'tools' => '<div class="chart-tools">' . ui_segment('price-window', 'Zeitraum', ['today' => 'Heute', '2' => '2 Tage', '7' => '7 Tage'], '2', ['compact' => true, 'id' => 'prw', 'attrs' => ['data-chart-window' => true]])
          . '<div class="chart-toggles">'
          . ($dynamicActive
              ? '<button type="button" class="toggle-chip c-price" aria-pressed="true" data-chart-series="dynamic"><span class="swatch swatch-dot"></span>Dynamisch</button>'
                  . '<button type="button" class="toggle-chip c-muted" aria-pressed="true" data-chart-series="spot"><span class="swatch swatch-dot"></span>Börse</button>'
              : '<button type="button" class="toggle-chip c-price" aria-pressed="true" data-chart-series="static"><span class="swatch swatch-dot"></span>Arbeitspreis</button>'
                  . (isset($price['static_uses']['car']) ? '<button type="button" class="toggle-chip c-v1" aria-pressed="true" data-chart-series="car"><span class="swatch swatch-dot"></span>Auto</button>' : '')
                  . (isset($price['static_uses']['battery']) ? '<button type="button" class="toggle-chip c-battery" aria-pressed="true" data-chart-series="battery"><span class="swatch swatch-dot"></span>Speicher</button>' : ''))
          . '<button type="button" class="toggle-chip c-grid-out" aria-pressed="true" data-chart-series="export"><span class="swatch swatch-dot"></span>Einspeisung</button>'
          . ($price['windows_enabled'] ? '<button type="button" class="toggle-chip c-accent" aria-pressed="true" data-chart-bands><span class="swatch"></span>Ladefenster</button>' : '')
          . '</div></div>',
      'style' => [
          'dynamic' => ['type' => 'step', 'width' => 'bold', 'unit' => 'ct/kWh', 'decimals' => 1],
          'static' => ['type' => 'step', 'width' => 'bold', 'unit' => 'ct/kWh', 'decimals' => 1],
          'car' => ['type' => 'step', 'unit' => 'ct/kWh', 'decimals' => 1],
          'battery' => ['type' => 'step', 'dash' => true, 'unit' => 'ct/kWh', 'decimals' => 1],
          'spot' => ['type' => 'step', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1],
          'export' => ['type' => 'step', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1],
      ],
      'empty' => 'Noch keine Börsenpreise. Der Recorder holt sie gleich.',
  ]) ?>
<?php if ($dynamicActive): ?>
  <p class="caption muted">Dein dynamischer Preis, darunter Börse und Einspeisung.<?= $price['windows_enabled'] ? ' Hinterlegt sind die aktiven Ladefenster, kräftiger die Viertelstunden, die ein Ladeplan dazunimmt, und der Preisbereich als waagerechtes Band.' : '' ?> Je Tag stehen der teuerste und der günstigste Preis. Ändern unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</p>
  <p class="caption muted"><?= e(MarketPrices::SOURCE) ?>.</p>
<?php else: ?>
  <p class="caption muted">Dein statischer Arbeitspreis<?= $price['static_uses'] ? ', dazu was Auto und Speicher in ihren Zeitfenstern zahlen' : '' ?>, und die Einspeisung. Ändern unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</p>
<?php endif; ?>
</section>

<?php
// Ladefenster: nur mit dynamischem Tarif; mit statischem kostet jede Viertelstunde gleich viel.
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
<?php if (!$dynamicActive): ?>
<section class="card stack" aria-labelledby="windows-title">
  <h2 class="card-title" id="windows-title">Zeitfenster</h2>
<?php if ($price['static_windows'] === []): ?>
  <p class="body-sm muted">Dein statischer Tarif hat keine Zeitfenster mit anderem Preis; jede Viertelstunde kostet gleich viel. Hast du etwa zeitvariables Netzentgelt nach §14a oder einen Autostrom-Tarif, trägst du die Fenster unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?> ein.</p>
<?php else: ?>
  <p class="body-sm muted">In diesen Zeiten zahlen Auto und Speicher einen anderen Preis; das Haus zahlt immer den Arbeitspreis von <?= e(ct((float) $active)) ?>. Ladepläne für Auto und Speicher laden bevorzugt hier.</p>
  <ul class="plain-list stack-tight tw-overview" role="list">
<?php foreach ($price['static_windows'] as $row): ?>
    <li><span class="body-sm"><strong><?= e($row['label']) ?></strong><?= $row['running'] ? ' <span class="pill">läuft</span>' : '' ?></span><span class="caption muted"><?= e($row['text']) ?></span></li>
<?php endforeach; ?>
  </ul>
  <p class="caption muted">Ändern unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>. Ladepläne: <?= ui_inline('Auto', ['href' => url('/') . '#cp']) ?>, <?= ui_inline('Speicher', ['href' => url('/speicher') . '#outlook-title']) ?>.</p>
<?php endif; ?>
</section>
<?php else: ?>
<section class="card stack" aria-labelledby="windows-title">
  <h2 class="card-title" id="windows-title">Ladefenster</h2>
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
  <p class="caption muted">Ab jetzt am günstigsten: <?= e($range($price['cheap']['hour'])) ?><?= $price['cheap']['hour'] ? ' · ' . e(ct((float) $price['cheap']['hour']['ct'])) : '' ?>, am teuersten: <?= e($range($price['cheap']['dear'])) ?><?= $price['cheap']['dear'] ? ' · ' . e(ct((float) $price['cheap']['dear']['ct'])) : '' ?>, je eine Stunde, dynamisch. Preise bis <?= e($price['horizon'] > 0 ? $until($price['horizon']) : '—') ?>.<?= $tomorrowKnown ? '' : ' Die Preise für morgen gibt es ab etwa 13 Uhr.' ?></p>
</section>
<?php endif; ?>

<?php
// Heatmap nach Kalenderwochen, je Quartal eine Gruppe: je Kennzahl Farbstärke (0…1 über alle Wochen), kurze Zahl und
// Text für Screenreader; dazu Legende mit kleinstem und größtem Wert. Preise brutto, wie unter Einstellungen → Tarif.
$metrics = [
    'day' => ['label' => 'Ø Tag', 'color' => 'price', 'unit' => 'ct/kWh', 'decimals' => 1, 'caption' => 'Ø dynamischer Preis über den ganzen Tag in ct/kWh, brutto: Börse plus Netzentgelt, Umlagen, Anbieteraufschlag und MwSt, wie unter Einstellungen → Tarif.'],
    'window' => ['label' => 'Fenster', 'color' => 'price', 'unit' => 'ct/kWh', 'decimals' => 1, 'caption' => 'Ø dynamischer Preis im günstigsten Fenster des Tages (' . $year['length_h'] . ' h) in ct/kWh, brutto wie Ø Tag.'],
    'save' => ['label' => 'Spart', 'color' => 'success', 'unit' => 'ct/kWh', 'decimals' => 1, 'caption' => 'Ersparnis je kWh, die im Fenster lädt statt zu deinem statischen Arbeitspreis von ' . ct($year['static_ct']) . ': statisch minus Fenster, in ct/kWh.'],
    'sun' => ['label' => 'Sonne', 'color' => 'solar', 'unit' => 'kWh', 'decimals' => 0, 'caption' => 'Ertrag deiner Anlage je Woche in kWh.'],
    'cover' => ['label' => 'Deckung', 'color' => 'grid-out', 'unit' => '%', 'decimals' => 0, 'caption' => 'Anteil von Haus und Auto je Woche in %, den Sonne und Speicher decken; Stunde für Stunde mit deinen Angaben oben.'],
];
$metric = isset($metrics[$metric]) ? $metric : 'save';
$weeks = $year['weeks'];
$scale = [];
foreach ($metrics as $key => $m) {
    $values = array_values(array_filter(array_column($weeks, $key), static fn (mixed $v): bool => $v !== null));
    $scale[$key] = $values ? [min($values), max($values)] : [0.0, 0.0];
    $metrics[$key]['lo'] = $values ? with_unit((float) min($values), $m['decimals'], $m['unit']) : '—';
    $metrics[$key]['hi'] = $values ? with_unit((float) max($values), $m['decimals'], $m['unit']) : '—';
}
$cellAttrs = static function (int $week, array $row) use ($metrics, $scale, $metric): array {
    $attrs = ['data-week' => $week, 'data-empty' => $row[$metric] === null];
    foreach ($metrics as $key => $m) {
        $value = $row[$key];
        [$lo, $hi] = $scale[$key];
        $attrs['data-v-' . $key] = $value === null ? '' : (string) round($hi - $lo > 1e-9 ? ($value - $lo) / ($hi - $lo) : 0.5, 3);
        $attrs['data-n-' . $key] = $value === null ? '' : num((float) $value, 0);
        $attrs['data-t-' . $key] = 'KW ' . $week . ': ' . ($value === null ? 'ohne Preis' : with_unit((float) $value, $m['decimals'], $m['unit']));
    }
    return $attrs;
};
$quarters = [['Q1', 'Jan–Mär', 1, 13], ['Q2', 'Apr–Jun', 14, 26], ['Q3', 'Jul–Sep', 27, 39], ['Q4', 'Okt–Dez', 40, 53]];
$y = $year['year'];
$eur = static fn (float $v): string => with_unit($v, 0, '€');
$pvNote = match ($year['pv']['source']) {
    'pvgis' => ($year['pv']['weather_year'] ? 'PVGIS, Wetter von ' . $year['pv']['weather_year'] : 'PVGIS, übliches Jahr') . ' bei ' . YearCheck::place($year['location']) . ($year['pv']['weather_year'] ? ', Stunde für Stunde' : ''),
    'demo' => 'Beispielwerte',
    'pending' => 'Schätzung, PVGIS wird gerade abgefragt',
    default => 'Schätzung aus 950 kWh je kWp' . ($year['pv']['error'] ? ', ' . $year['pv']['error'] : ''),
};
$carSun = $year['inputs']['car_mode'] !== 'fenster';
?>
<section class="card stack" aria-labelledby="decide-title">
  <h2 class="card-title" id="decide-title">Welcher Tarif lohnt sich?</h2>
  <p class="body-sm muted">Ein Jahr Tag für Tag, statisch, <?= $y['has_windows'] ? 'statisch mit deinen Zeitfenstern' : 'mit Zeitfenstern (sobald du welche einträgst)' ?> und dynamisch: Börsenpreise der letzten 12 Monate mit deinen Preisen von heute aus <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>, die Sonne deiner Anlage und Haus und Auto aus den Angaben hier, gleichmäßig übers Jahr verteilt.</p>
  <form method="get" action="<?= e(url('/prognose/strompreis')) ?>#decide-title" class="stack" id="decide-form">
    <div class="form-rows">
      <?= ui_number_row('haus', 'Haus', (float) $year['inputs']['house_kwh'], 0, 'kWh im Jahr. Vorschlag ' . kwh($year['suggest']['house_kwh'], 0) . ': ' . $year['suggest']['notes']['house'], ['inputmode' => 'numeric']) ?>
      <?= ui_number_row('km', 'Auto', (float) $year['inputs']['car_km'], 0, 'km im Jahr. Vorschlag ' . num($year['suggest']['car_km'], 0) . ' km: ' . $year['suggest']['notes']['km'], ['inputmode' => 'numeric']) ?>
      <?= ui_number_row('verbrauch', 'Verbrauch Auto', (float) $year['inputs']['car_kwh100'], 1, 'kWh je 100 km. Vorschlag ' . num($year['suggest']['car_kwh100'], 1) . ': ' . $year['suggest']['notes']['use']) ?>
      <?= ui_form_row('Auto lädt', ui_select('auto', YearCheck::CAR_MODES, $year['inputs']['car_mode'], ['id' => 'f-auto']), ['for' => 'f-auto', 'hint' => 'Sonne zuerst: Überschuss am Tag, der Rest aus dem Netz. Nur im Fenster: tagsüber unterwegs.']) ?>
    </div>
    <div class="form-actions"><button class="btn btn-secondary" type="submit">Neu rechnen</button></div>
  </form>
<?php if ($y['priced_days'] < 30): ?>
  <p class="body muted">Für das Jahr fehlen noch Börsenpreise; EMS lädt sie nach und nach bis zwei Jahre zurück.</p>
<?php else: ?>
<?php
    // Die Varianten nach Summe: welche am günstigsten ist und um wie viel vor der nächsten.
    $variants = ['Statisch' => (float) $y['static_eur'], 'Dynamisch' => (float) $y['dynamic_eur']] + ($y['has_windows'] ? ['Statisch mit Zeitfenstern' => (float) $y['windows_eur']] : []);
    asort($variants);
    $names = array_keys($variants);
    $avg = static fn (?float $v): string => $v === null ? '' : '<span class="sub">' . e('Ø ' . with_unit($v, 1, 'ct')) . '</span>';
?>
  <p class="compare-verdict"><?= e($names[0] . ' wäre im Jahr am günstigsten, etwa ' . $eur($variants[$names[1]] - $variants[$names[0]]) . ' weniger als ' . lcfirst($names[1]) . ', Grundpreise eingerechnet.') ?></p>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Kosten im Jahr je Tarif">
  <table class="compare-table">
    <thead><tr><th scope="col">€ im Jahr</th><th scope="col">Statisch</th><?= $y['has_windows'] ? '<th scope="col">Mit Zeit&shy;fenstern</th>' : '' ?><th scope="col">Dynamisch</th></tr></thead>
    <tbody>
      <tr><th scope="row">Arbeitspreis<span class="sub"><?= e(kwh((float) $y['grid_kwh'], 0) . ' aus dem Netz') ?></span></th><td><?= e(num((float) $y['static_energy_eur'], 0)) ?><?= $avg($y['static_avg_ct']) ?></td><?= $y['has_windows'] ? '<td>' . e(num((float) $y['windows_energy_eur'], 0)) . $avg($y['windows_avg_ct']) . '</td>' : '' ?><td><?= e(num((float) $y['dynamic_energy_eur'], 0)) ?><?= $avg($y['dynamic_avg_ct']) ?></td></tr>
      <tr><th scope="row">Grundpreis<span class="sub">zwölf Monate</span></th><td><?= e(num((float) $y['static_base_eur'], 0)) ?><span class="sub"><?= e('12 × ' . euro((float) $year['base_eur'])) ?></span></td><?= $y['has_windows'] ? '<td>' . e(num((float) $y['windows_base_eur'], 0)) . '<span class="sub">wie statisch</span></td>' : '' ?><td><?= e(num((float) $y['dynamic_base_eur'], 0)) ?><span class="sub"><?= e('12 × ' . euro((float) $year['dyn_base_eur'])) ?></span></td></tr>
    </tbody>
    <tfoot><tr><th scope="row">Summe</th><td><?= e(num((float) $y['static_eur'], 0)) ?></td><?= $y['has_windows'] ? '<td>' . e(num((float) $y['windows_eur'], 0)) . '</td>' : '' ?><td><?= e(num((float) $y['dynamic_eur'], 0)) ?></td></tr></tfoot>
  </table>
  </div>
<?php if ($y['has_windows']): ?>
  <p class="caption muted"><?= e('Mit Zeitfenstern lädt das Auto etwa ' . kwh((float) $y['windows_car_kwh'], 0) . ' im Jahr in seinen Fenstern' . ((float) $y['windows_shift_kwh'] >= 1 ? ', der Speicher lädt etwa ' . kwh((float) $y['windows_shift_kwh'], 0) . ' fürs Haus in seinen vor' : '') . '; das Haus zahlt sonst den Arbeitspreis.') ?></p>
<?php endif; ?>
<?php endif; ?>
  <?= ui_segment('kennzahl', 'Kennzahl', array_map(static fn (array $m): string => $m['label'], $metrics), $metric, ['compact' => true, 'id' => 'wkm', 'form' => 'decide-form', 'attrs' => ['data-week-metric' => true]]) ?>
  <figure class="hm wk" data-weekmap<?= ui_attrs(['data-color' => $metrics[$metric]['color'], 'data-metrics' => ui_json(array_map(static fn (array $m): array => ['color' => $m['color'], 'caption' => $m['caption'], 'lo' => $m['lo'], 'hi' => $m['hi']], $metrics))]) ?>>
    <div class="wk-quarters">
<?php foreach ($quarters as [$q, $months, $first, $last]): ?>
<?php $inQuarter = array_filter($weeks, static fn (int $w): bool => $w >= $first && $w <= $last, ARRAY_FILTER_USE_KEY); ?>
<?php if (!$inQuarter) { continue; } ?>
      <div class="wk-q">
        <h3 class="wk-q-title" id="wk-<?= e(strtolower($q)) ?>"><?= e($q) ?> <span><?= e($months) ?></span></h3>
        <ol class="wk-grid" role="list" aria-labelledby="wk-<?= e(strtolower($q)) ?>">
<?php foreach ($inQuarter as $week => $row): ?>
          <li class="hm-cell wk-cell"<?= ui_attrs($cellAttrs((int) $week, $row)) ?>><span class="hm-d" aria-hidden="true"><?= (int) $week ?></span><span class="hm-n" aria-hidden="true" data-wk-n><?= e($row[$metric] === null ? '' : num((float) $row[$metric], 0)) ?></span><span class="sr-only" data-wk-t><?= e('KW ' . $week . ': ' . ($row[$metric] === null ? 'ohne Preis' : with_unit((float) $row[$metric], $metrics[$metric]['decimals'], $metrics[$metric]['unit']))) ?></span></li>
<?php endforeach; ?>
        </ol>
      </div>
<?php endforeach; ?>
    </div>
    <figcaption class="wk-caption">
      <span class="hm-legend caption"><span data-wk-lo><?= e($metrics[$metric]['lo']) ?></span><span class="hm-ramp" aria-hidden="true"></span><span data-wk-hi><?= e($metrics[$metric]['hi']) ?></span><span class="hm-nodata"><span class="hm-swatch" aria-hidden="true"></span>ohne Preis</span></span>
      <span class="caption muted" data-wk-caption><?= e($metrics[$metric]['caption']) ?></span>
    </figcaption>
  </figure>
  <dl class="kv">
    <div><dt>Sonne im Jahr</dt><dd><?= e(kwh((float) $y['pv_kwh'], 0)) ?><span class="sub"><?= e($pvNote) ?></span></dd></div>
    <div><dt>Haus und Auto</dt><dd><?= e(kwh((float) $y['demand_kwh'], 0)) ?><span class="sub"><?= e('Haus ' . kwh((float) $y['house_kwh'], 0) . ', Auto ' . kwh((float) $y['car_kwh'], 0) . ' an der Wallbox') ?></span></dd></div>
    <div><dt>Sonne und Speicher decken</dt><dd><?= e(pct($y['cover_pct'] === null ? null : (float) $y['cover_pct'])) ?><span class="sub"><?= e('Mit ' . kwh($year['battery_kwh'], 1) . ' Speicher' . ($year['floor_soc'] > 0 ? ', davon ' . kwh((float) $year['battery_usable_kwh'], 1) . ' nutzbar über dem Backup-Puffer von ' . pct((float) $year['floor_soc']) : '') . '; er hilft nur von einem Tag auf den nächsten, Überschuss im Sommer geht ins Netz (' . kwh((float) $y['export_kwh'], 0) . ').') ?></span></dd></div>
    <div><dt>Aus dem Netz</dt><dd><?= e(kwh((float) $y['grid_kwh'], 0)) ?><span class="sub"><?= e('Haus ' . kwh((float) $y['grid_house_kwh'], 0) . ', Auto ' . kwh((float) $y['grid_car_kwh'], 0) . ($carSun ? '; das Auto lädt ' . kwh((float) $y['car_sun_kwh'], 0) . ' aus der Sonne' : '')) ?></span></dd></div>
  </dl>
  <ul class="plain-list stack-tight body-sm muted" role="list">
    <li><?= e($carSun
        ? 'Das Auto nimmt zuerst Sonnenüberschuss, den Rest aus dem Netz: dynamisch im günstigsten Fenster des Tages, statisch zum Arbeitspreis.'
        : 'Das Auto lädt nur aus dem Netz, dynamisch im günstigsten Fenster des Tages, statisch zum Arbeitspreis; die Sonne bekommen Haus, Speicher und Netz.') ?></li>
    <li><?= e('Das Haus zahlt dynamisch den Preis der Stunde. Reicht die Sonne nicht, um den Speicher zu füllen (meist im Winter), lädt er dynamisch im Fenster vor, wenn das nach Verlust und Verschleiß günstiger ist'
        . ((float) $y['shift_kwh'] >= 1 ? ': an etwa ' . $y['shift_days'] . ' Tagen ' . kwh((float) $y['shift_kwh'], 0) . ', das spart ' . euro((float) $y['shift_eur']) . '.' : '; mit deinen Preisen lohnt es sich nicht.')) ?></li>
<?php if ((float) $y['car_rest_kwh'] >= 1): ?>
    <li><?= e(kwh((float) $y['car_rest_kwh'], 0) . ' fürs Auto passen nicht ins Fenster (' . $year['length_h'] . ' h mit ' . kw((float) $year['car_kw']) . ') und zählen zum Ø Preis des Tages.') ?></li>
<?php endif; ?>
<?php if ($year['base_missing']): ?>
    <li>Ein Grundpreis fehlt noch und zählt mit 0 €; eintragen unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</li>
<?php endif; ?>
<?php if ($y['priced_days'] >= 30 && $y['priced_days'] < 360): ?>
    <li><?= e('Börsenpreise gibt es erst für ' . $y['priced_days'] . ' der letzten 365 Tage; die übrigen Tage rechnen mit ihrem Ø Tagesverlauf.') ?></li>
<?php endif; ?>
    <li>Die Einspeisevergütung ist in allen Tarifen gleich und zählt nicht mit. Für die Sonne schickt EMS Standort, kWp, Neigung und Ausrichtung an PVGIS; Standort, Verluste und Wetterjahr stehen unter <?= ui_inline('Einstellungen → Prognose', ['href' => url('/einstellungen/prognose') . '#pvgis-title']) ?>. <?= e(YearCheck::SOURCE) ?>. Börsenpreise: <?= e(MarketPrices::SOURCE) ?>.</li>
  </ul>
</section>
</div>
<?php
view('partials/overview', ['overview' => $overview]);
