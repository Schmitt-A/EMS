<?php
declare(strict_types=1);
/**
 * Prognose → Strompreis: Preis jetzt im aktiven Tarif, Börse, heute und morgen, das Diagramm mit dynamischem und
 * statischem Preis, Börse und Einspeisung (14 Tage zurück bis zum letzten bekannten Preis), die günstigsten Zeiten und
 * der Vergleich statisch ↔ dynamisch, aufgeschlüsselt. Daten aus PriceView.
 * @var array $price
 * @var array $overview
 */
$now = $price['now'];
$cmp = $price['compare'];
$tz = new DateTimeZone('Europe/Berlin');
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

echo ui_page_head('Prognose', ui_head_chip('zap', e($active === null ? '—' : ct((float) $active)), 'Strompreis jetzt ' . ($active === null ? 'unbekannt' : ct((float) $active)) . '. Energieübersicht öffnen'));
echo ui_seg_links('Prognose', ['/prognose' => 'Sonne', '/prognose/strompreis' => 'Strompreis'], '/prognose/strompreis');
if (!empty($price['meta']['error']) && $price['horizon'] < time()) {
    echo ui_notice('circle-alert', e('Börsenpreise: ' . $price['meta']['error']), 'error');
}
?>
<section class="card stack price-card" aria-labelledby="price-title">
  <h2 class="card-title" id="price-title">Strompreis</h2>
  <div class="price-now">
    <?= ui_metric($dynamicActive ? 'Jetzt, dynamisch' : 'Jetzt, statisch', ui_num($active === null ? null : (float) $active, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($dynamicActive ? 'Börse plus deine Aufschläge' : 'dynamisch wären es ' . ct($now['dynamic_ct'] === null ? null : (float) $now['dynamic_ct'])) . '</span>']) ?>
    <?= ui_metric('Börse jetzt', ui_num($now['spot_ct'] === null ? null : (float) $now['spot_ct'], 'ct/kWh'), ['after' => '<span class="caption muted">netto, ohne Aufschläge</span>']) ?>
    <?= ui_metric('Heute dynamisch', ui_num($price['today']['avg'] ?? null, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($price['today'] ? 'Ø, ' . ct((float) $price['today']['min']) . ' bis ' . ct((float) $price['today']['max']) : 'noch keine Preise') . '</span>']) ?>
    <?= ui_metric('Morgen dynamisch', ui_num($price['tomorrow']['avg'] ?? null, 'ct/kWh'), ['after' => '<span class="caption muted">' . e($tomorrowKnown ? 'Ø, ' . ct((float) $price['tomorrow']['min']) . ' bis ' . ct((float) $price['tomorrow']['max']) : 'Preise ab etwa 13 Uhr') . '</span>']) ?>
  </div>
  <?= ui_chart('time', '/api/series?chart=price', 'Strompreis je Viertelstunde: dynamisch, statisch, Börse und Einspeisung', [
      'window' => '2',
      'class' => 'chart-xl',
      'tools' => '<div class="chart-tools">' . ui_segment('price-window', 'Zeitraum', ['today' => 'Heute', '2' => '2 Tage', '7' => '7 Tage'], '2', ['compact' => true, 'id' => 'prw', 'attrs' => ['data-chart-window' => true]])
          . '<div class="chart-toggles">'
          . '<button type="button" class="toggle-chip c-price" aria-pressed="true" data-chart-series="dynamic"><span class="swatch swatch-dot"></span>Dynamisch</button>'
          . '<button type="button" class="toggle-chip c-v1" aria-pressed="true" data-chart-series="static"><span class="swatch swatch-dot"></span>Statisch</button>'
          . '<button type="button" class="toggle-chip c-muted" aria-pressed="true" data-chart-series="spot"><span class="swatch swatch-dot"></span>Börse</button>'
          . '<button type="button" class="toggle-chip c-grid-out" aria-pressed="true" data-chart-series="export"><span class="swatch swatch-dot"></span>Einspeisung</button>'
          . ($price['limit'] !== null ? '<button type="button" class="toggle-chip c-ink" aria-pressed="true" data-chart-series="limit"><span class="swatch swatch-dot"></span>Preisgrenze</button>' : '')
          . '</div></div>',
      'style' => [
          'dynamic' => ['type' => 'step', 'width' => $dynamicActive ? 'bold' : null, 'dash' => !$dynamicActive, 'unit' => 'ct/kWh', 'decimals' => 1],
          'static' => ['type' => 'step', 'width' => $dynamicActive ? null : 'bold', 'dash' => $dynamicActive, 'unit' => 'ct/kWh', 'decimals' => 1],
          'spot' => ['type' => 'step', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1],
          'export' => ['type' => 'step', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1],
          'limit' => ['type' => 'step', 'width' => 'thin', 'dash' => true, 'unit' => 'ct/kWh', 'decimals' => 1],
      ],
      'empty' => 'Noch keine Börsenpreise. Der Recorder holt sie gleich.',
  ]) ?>
  <p class="caption muted">Dick der Tarif, nach dem du bezahlst, gestrichelt der andere. Je Tag stehen der teuerste und der günstigste dynamische Preis. Ändern unter <?= ui_inline('Einstellungen → Tarif', ['href' => url('/einstellungen/tarif')]) ?>.</p>
  <p class="caption muted"><?= e(MarketPrices::SOURCE) ?>.</p>
</section>

<section class="card stack" aria-labelledby="cheap-title">
  <h2 class="card-title" id="cheap-title">Günstige Zeiten</h2>
  <ul class="price-cheap body" role="list">
    <li><span>Günstigste Stunde</span><strong class="num"><?= e($range($price['cheap']['hour'])) ?><?= $price['cheap']['hour'] ? ' · ' . e(ct((float) $price['cheap']['hour']['ct'])) : '' ?></strong></li>
    <li><span>Günstigste drei Stunden</span><strong class="num"><?= e($range($price['cheap']['three'])) ?><?= $price['cheap']['three'] ? ' · ' . e(ct((float) $price['cheap']['three']['ct'])) : '' ?></strong></li>
    <li><span>Teuerste Stunde</span><strong class="num"><?= e($range($price['cheap']['dear'])) ?><?= $price['cheap']['dear'] ? ' · ' . e(ct((float) $price['cheap']['dear']['ct'])) : '' ?></strong></li>
  </ul>
  <p class="caption muted">Dynamischer Preis von jetzt bis <?= e($price['horizon'] > 0 ? $until($price['horizon']) : '—') ?>.<?= $tomorrowKnown ? '' : ' Die Preise für morgen gibt es ab etwa 13 Uhr.' ?></p>
  <?= ui_divider('Laden nach Preis') ?>
  <ul class="plain-list stack-tight body-sm" role="list">
    <li><?= $price['limit'] !== null
        ? e('Preisgrenze ' . ct((float) $price['limit']) . ': Darunter lädt das Auto in Solar und Min+Solar voll aus dem Netz' . ($dynamicActive ? '.' : ', sobald der dynamische Tarif aktiv ist.'))
        : e('Keine Preisgrenze gesetzt.') ?> <?= ui_inline('Einstellungen → Ladepunkt', ['href' => url('/einstellungen/ladepunkt')]) ?></li>
    <li><?= $price['plan'] !== null ? e(ChargePlan::text($price['plan'])) . ' Im Diagramm hinterlegt.' : e('Kein Ladeplan. Ein Ladeziel mit „fertig bis“ in der Ladepunkt-Karte lädt in den günstigsten Viertelstunden davor.') ?></li>
  </ul>
</section>

<section class="card stack" aria-labelledby="compare-title">
  <h2 class="card-title" id="compare-title">Statisch oder dynamisch?</h2>
  <?= ui_seg_links('Zeitraum des Vergleichs', $spanLinks, '/prognose/strompreis?vergleich=' . $price['span']) ?>
<?php if ($cmp['kwh'] < 0.01): ?>
  <p class="body muted">Für diesen Zeitraum gibt es noch keinen Netzbezug<?= $cmp['note'] ? ': ' . e($cmp['note']) : '' ?>.</p>
<?php else: ?>
<?php $diff = (float) $cmp['difference']; ?>
  <p class="compare-verdict"><?= e($diff >= 0
      ? 'Dynamisch hätte ' . euro($diff) . ' weniger gekostet.'
      : 'Statisch war ' . euro(-$diff) . ' günstiger.') ?></p>
  <p class="body-sm muted">Netzbezug <?= e(kwh((float) $cmp['kwh'], 0)) ?>, jede Zeit mit dem Tarif, der damals galt. Wie du tatsächlich bezahlt hast, steht unter „<?= e($dynamicActive ? 'Dynamisch' : 'Statisch') ?>“.</p>
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
<?php
view('partials/overview', ['overview' => $overview]);
