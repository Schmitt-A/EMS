<?php
declare(strict_types=1);
/**
 * Prognose → Strompreis: Preis jetzt im aktiven Tarif, Börse, heute und morgen, das Diagramm mit dynamischem und
 * statischem Preis, Börse und Einspeisung (14 Tage zurück bis zum letzten bekannten Preis) und den aktiven
 * Ladefenstern. Darunter die Ladefenster selbst (je Tag das günstigste Fenster, an- und abschaltbar, eigene Fenster),
 * die Entscheidungshilfe je Monat mit eigenen Annahmen und der Vergleich mit dem gemessenen Netzbezug. Daten aus
 * PriceView.
 * @var array $price
 * @var array $inputs fenster und rest in kWh je Jahr
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
$months = $price['months'];
$decision = PriceView::decision($months, (float) $inputs['fenster'], (float) $inputs['rest']);
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
  <p class="caption muted">Dick der Tarif, nach dem du bezahlst, gestrichelt der andere. Hinterlegt sind die aktiven Ladefenster, kräftiger die Viertelstunden, die ein Ladeplan dazunimmt. Je Tag stehen der teuerste und der günstigste dynamische Preis. Ändern unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</p>
  <p class="caption muted"><?= e(MarketPrices::SOURCE) ?>.</p>
</section>

<section class="card stack" aria-labelledby="windows-title">
  <h2 class="card-title" id="windows-title">Ladefenster</h2>
  <p class="body-sm muted">Je Tag sucht EMS das günstigste Fenster am Stück, sobald die Preise da sind. In aktiven Fenstern lädt das Auto mit Netzladen „In Ladefenstern“ voll aus dem Netz, der Speicher, wenn er es unter Speicher → Ausblick darf und die Sonne nicht reicht.</p>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="form-rows" data-autosubmit>
    <?= $post('windows') ?>
    <?= ui_form_row('Länge je Tag', ui_select('length_h', array_combine(array_map('strval', PriceWindows::LENGTHS), array_map(static fn (int $h): string => $h . ($h === 1 ? ' Stunde' : ' Stunden'), PriceWindows::LENGTHS)), (string) $wc['length_h']), ['for' => 'f-length_h']) ?>
    <noscript><div class="form-actions"><button class="btn btn-secondary" type="submit">Länge speichern</button></div></noscript>
  </form>
<?php if (!$price['windows']): ?>
  <p class="body muted">Noch keine Preise für heute, die Fenster erscheinen mit ihnen.</p>
<?php else: ?>
  <ul class="window-list" role="list">
<?php foreach ($price['windows'] as $w): ?>
<?php
    $status = PriceWindows::status($w, $ts);
    $label = ucfirst(PriceWindows::label($w, $ts));
    $state = match ($status) {
        'past' => 'vorbei',
        'running' => 'läuft',
        default => '',
    };
    $meta = implode(' · ', array_filter([$w['ct'] === null ? 'Preis noch offen' : 'Ø ' . ct((float) $w['ct']), $w['kind'] === 'custom' ? 'eigenes' : 'Vorschlag', $state]));
?>
    <li class="window-item" data-status="<?= e($status) ?>"<?= $w['on'] ? '' : ' data-off' ?>>
      <div class="window-main"><span class="window-when num"><?= e($label) ?></span><span class="caption muted"><?= e($meta) ?></span></div>
<?php if ($w['kind'] === 'auto'): ?>
      <form method="post" action="<?= e(url('/einstellungen')) ?>" data-autosubmit>
        <?= $post('window_toggle') ?><input type="hidden" name="day" value="<?= e($w['day']) ?>">
        <?= ui_toggle('on', (bool) $w['on'], 'Ladefenster ' . $label, ['id' => 'f-win-' . $w['day']]) ?>
        <noscript><button class="btn btn-secondary" type="submit">Speichern</button></noscript>
      </form>
<?php else: ?>
      <form method="post" action="<?= e(url('/einstellungen')) ?>">
        <?= $post('window_delete') ?><input type="hidden" name="start" value="<?= (int) $w['start'] ?>"><input type="hidden" name="end" value="<?= (int) $w['end'] ?>">
        <?= '<button type="submit" class="icon-btn" aria-label="' . e('Ladefenster ' . $label . ' löschen') . '">' . icon('x', 'icon-20') . '</button>' ?>
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
  <ul class="plain-list stack-tight body-sm" role="list">
    <li>Auto: <?= e($price['users']['car'] === 'windows' ? 'lädt in den Fenstern (Netzladen „In Ladefenstern“).' : 'nutzt die Fenster nicht, Netzladen steht auf „' . Energy::gridLabel((string) $price['users']['car']) . '“.') ?> <?= ui_inline('Laden', ['href' => url('/') . '#cp']) ?></li>
    <li>Speicher: <?= e($price['users']['battery'] ? 'lädt in den Fenstern, wenn die Sonne nicht reicht.' : 'nutzt die Fenster nicht.') ?> <?= ui_inline('Speicher → Ausblick', ['href' => url('/speicher') . '#outlook-title']) ?></li>
<?php if ($price['plan'] !== null): ?>
    <li><?= e(ChargePlan::text($price['plan'])) ?></li>
<?php endif; ?>
  </ul>
  <p class="caption muted">Ab jetzt am günstigsten: <?= e($range($price['cheap']['hour'])) ?><?= $price['cheap']['hour'] ? ' · ' . e(ct((float) $price['cheap']['hour']['ct'])) : '' ?>, am teuersten: <?= e($range($price['cheap']['dear'])) ?><?= $price['cheap']['dear'] ? ' · ' . e(ct((float) $price['cheap']['dear']['ct'])) : '' ?>, je eine Stunde. Preise bis <?= e($price['horizon'] > 0 ? $until($price['horizon']) : '—') ?>.<?= $tomorrowKnown ? '' : ' Die Preise für morgen gibt es ab etwa 13 Uhr.' ?></p>
</section>

<section class="card stack" aria-labelledby="decide-title"<?= ui_attrs($decision === null ? [] : [
    'data-decide' => true,
    'data-flat' => $decision['flat_ct'],
    'data-window' => $decision['window_ct'],
    'data-static' => $decision['static_ct'],
    'data-base' => round($decision['base_eur'], 2),
    'data-dyn-base' => round($decision['dyn_base_eur'], 2),
]) ?>>
  <h2 class="card-title" id="decide-title">Lohnt sich dynamisch?</h2>
<?php if ($decision === null): ?>
  <p class="body muted">Dafür braucht EMS mindestens einen ganzen Monat Börsenpreise; es lädt sie nach und nach bis zwei Jahre zurück.</p>
<?php else: ?>
  <p class="body-sm muted">Mit deinen Preisen von heute und den echten Börsenpreisen <?= $decision['months'] === 1 ? 'des letzten Monats' : 'der letzten ' . e((string) $decision['months']) . ' Monate' ?>: Eine kWh im Ladefenster (je Tag die günstigsten <?= (int) $months['length_h'] ?> h) kostet dynamisch Ø <?= e(ct($decision['window_ct'])) ?>, über den ganzen Tag Ø <?= e(ct($decision['flat_ct'])) ?>, statisch <?= e(ct($decision['static_ct'])) ?>. Trag ein, wie viel du ins Ladefenster legen kannst, etwa das Auto und den Speicher, und was sonst aus dem Netz kommt.</p>
  <form method="get" action="<?= e(url('/prognose/strompreis')) ?>#decide-title" class="stack" data-decide-form>
    <input type="hidden" name="vergleich" value="<?= e((string) $price['span']) ?>">
    <div class="form-rows">
      <?= ui_number_row('fenster', 'Im Ladefenster', (float) $inputs['fenster'], 0, 'kWh im Jahr, Auto und Speicher', ['inputmode' => 'numeric']) ?>
      <?= ui_number_row('rest', 'Übriger Netzbezug', (float) $inputs['rest'], 0, 'kWh im Jahr, zu jeder Tageszeit', ['inputmode' => 'numeric']) ?>
    </div>
    <div class="form-actions"><button class="btn btn-secondary" type="submit" data-decide-submit>Rechnen</button></div>
  </form>
  <p class="compare-verdict" data-decide-verdict aria-live="polite"><?= e($decision['difference'] >= 0
      ? 'Dynamisch wäre im Jahr etwa ' . euro($decision['difference']) . ' günstiger.'
      : 'Statisch wäre im Jahr etwa ' . euro(-$decision['difference']) . ' günstiger.') ?></p>
  <p class="body-sm" data-decide-detail><?= e('Dynamisch ' . euro($decision['dynamic_eur']) . ', statisch ' . euro($decision['static_eur']) . ' im Jahr, mit Grundpreisen. '
      . match (true) {
          $decision['break_even_kwh'] === null => 'Das Ladefenster liegt nicht unter deinem statischen Preis, Verschieben bringt dynamisch nichts.',
          $decision['break_even_kwh'] <= 0.0 => 'Dynamisch ist hier schon ohne Verschieben günstiger.',
          default => 'Dynamisch lohnt sich ab etwa ' . kwh($decision['break_even_kwh'], 0) . ' im Ladefenster.',
      }) ?></p>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Dynamischer Preis je Monat">
  <table class="compare-table">
    <thead><tr><th scope="col">Monat</th><th scope="col">Ø Tag<span class="sub">ct/kWh</span></th><th scope="col">Ø Fenster<span class="sub">ct/kWh</span></th><th scope="col">Spart<span class="sub">ct/kWh</span></th></tr></thead>
    <tbody>
<?php foreach (array_reverse($months['rows']) as $row): ?>
      <tr><th scope="row"><?= e((string) $row['label']) ?><?= (int) $row['negative'] > 0 ? '<span class="sub">' . e((int) $row['negative'] . ' Viertelstunden unter 0') . '</span>' : '' ?></th>
        <td><?= e(num((float) $row['flat_ct'], 1)) ?></td><td><?= e(num((float) $row['window_ct'], 1)) ?></td><td><?= e(num($decision['static_ct'] - (float) $row['window_ct'], 1)) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <ul class="plain-list stack-tight body-sm muted" role="list">
    <li>„Spart“: statischer Preis minus Ladefenster, je kWh. Der übrige Netzbezug ist über den Tag gleich verteilt; abends ist Strom meist teurer, nachts und mittags günstiger.</li>
<?php if ($decision['base_missing']): ?>
    <li>Ein Grundpreis fehlt noch und zählt mit 0 €; eintragen unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</li>
<?php endif; ?>
    <li>Dein gemessener Netzbezug steht im Vergleich darunter.</li>
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
