<?php
declare(strict_types=1);
/**
 * Ladevorgänge (8): Zeitraum und Kennzahl, gestapeltes Diagramm, Solaranteil als Ring, Liste oder Tabelle.
 * Ebene 2: Detail je Vorgang mit Kilometerstand und Löschen.
 * @var array $live
 * @var array $params
 * @var array $rows
 * @var array $summary
 * @var array $years
 * @var ?array $detail
 * @var array $tariffs
 * @var bool $hasAny
 * @var array $overview
 */
$tz = new DateTimeZone('Europe/Berlin');
$span = $params['span'];
// Uhrzeit von–bis eines Ladevorgangs oder Zyklus
$clock = static fn (string $iso): string => (new DateTimeImmutable($iso))->setTimezone($tz)->format('H:i');
$range = static fn (array $row): string => $clock((string) $row['started_at']) . '–' . (empty($row['ended_at']) ? 'jetzt' : $clock((string) $row['ended_at']));
$here = static fn (array $patch = []): string => url('/ladevorgaenge' . sessions_query($params, $patch));
$ct = $summary['ct'];

echo ui_page_head('Ladevorgänge', ui_head_chip('coins', $ct === null ? '—' : e(num($ct, 1)) . NNBSP . 'ct', 'Ø Preis ' . ($ct === null ? 'unbekannt' : ct($ct)) . '. Energieübersicht öffnen'));

// Zeitraum, Navigation ‹ 2026 › und Kennzahl
$prevNext = null;
if ($span === 'year') {
    $label = (string) $params['year'];
    $prevNext = [$here(['year' => $params['year'] - 1, 'month' => sprintf('%04d-%s', $params['year'] - 1, substr($params['month'], 5))]), $here(['year' => $params['year'] + 1, 'month' => sprintf('%04d-%s', $params['year'] + 1, substr($params['month'], 5))])];
} elseif ($span === 'month') {
    $current = new DateTimeImmutable($params['month'] . '-01', $tz);
    $label = month_label($params['month']);
    $prev = $current->modify('-1 month');
    $next = $current->modify('+1 month');
    $prevNext = [$here(['month' => $prev->format('Y-m'), 'year' => (int) $prev->format('Y')]), $here(['month' => $next->format('Y-m'), 'year' => (int) $next->format('Y')])];
} else {
    $label = 'Alle Jahre';
}
?>
<form class="filters" method="get" action="<?= e(url('/ladevorgaenge')) ?>" data-autosubmit>
  <input type="hidden" name="year" value="<?= (int) $params['year'] ?>">
  <input type="hidden" name="month" value="<?= e($params['month']) ?>">
  <input type="hidden" name="sort" value="<?= e($params['sort']) ?>">
  <?= ui_segment('span', 'Zeitraum', ['month' => 'Monat', 'year' => 'Jahr', 'all' => 'Gesamt'], $span, ['id' => 'f-span']) ?>
  <div class="period-nav">
<?php if ($prevNext): ?>
    <a class="icon-btn" href="<?= e($prevNext[0]) ?>" aria-label="Zeitraum davor"><?= icon('chevron-left', 'icon-20') ?></a>
<?php endif; ?>
    <p class="card-title period-label" aria-live="polite"><?= e($label) ?></p>
<?php if ($prevNext): ?>
    <a class="icon-btn" href="<?= e($prevNext[1]) ?>" aria-label="Zeitraum danach"><?= icon('chevron-right', 'icon-20') ?></a>
<?php endif; ?>
  </div>
  <?= ui_segment('metric', 'Kennzahl', ['energy' => 'Energie', 'cost' => 'Kosten', 'co2' => 'CO₂'], $params['metric'], ['id' => 'f-metric', 'compact' => true]) ?>
  <noscript><button class="btn btn-secondary" type="submit">Anzeigen</button></noscript>
</form>
<?php if (!$rows): ?>
  <section class="card">
    <?php
      $import = '<form method="post" action="' . e(url('/ladevorgaenge')) . '">' . csrf_field()
          . '<input type="hidden" name="action" value="import"><input type="hidden" name="span" value="' . e($span) . '"><input type="hidden" name="year" value="' . (int) $params['year'] . '"><input type="hidden" name="month" value="' . e($params['month']) . '">'
          . '<button class="btn btn-secondary" type="submit">' . icon('history', 'icon-16') . 'Aus Home Assistant übernehmen</button></form>';
      echo ui_empty('chart-column', $hasAny
          ? 'In diesem Zeitraum gibt es keinen Ladevorgang. Neue Vorgänge schreibt der Recorder, sobald die Wallbox lädt.'
          : 'Noch kein Ladevorgang gespeichert. Bisherige Vorgänge lassen sich einmalig aus sensor.ems_ladelog_historie übernehmen.', $hasAny ? '' : $import);
    ?>
  </section>
<?php else: ?>
<div class="split split-sessions">
  <section class="card stack" aria-labelledby="chart-title">
    <h2 class="card-title" id="chart-title"><?= e(['energy' => 'Energie', 'cost' => 'Kosten', 'co2' => 'CO₂'][$params['metric']]) ?> nach <?= e(['month' => 'Tagen', 'year' => 'Monaten', 'all' => 'Jahren'][$span]) ?></h2>
    <?= ui_chart('bars', '/api/series' . sessions_query($params, ['chart' => 'sessions']), 'Ladevorgänge ' . $label, [
        'style' => $params['metric'] === 'cost' ? ['_unit' => '€', '_decimals' => 2] : ($params['metric'] === 'co2' ? ['_unit' => 'kg', '_decimals' => 1] : ['_unit' => 'kWh', '_decimals' => 1]),
    ]) ?>
  </section>
  <section class="card stack" aria-labelledby="share-title">
    <h2 class="card-title" id="share-title">Solaranteil</h2>
    <?= ui_ring([
        ['label' => 'Sonne', 'value' => (float) $summary['solar'], 'class' => 'c-solar', 'text' => kwh((float) $summary['solar'], 0)],
        ['label' => 'Netz', 'value' => (float) $summary['grid'], 'class' => 'c-grid-in', 'text' => kwh((float) $summary['grid'], 0)],
    ], pct($summary['solar_pct'], 0), 'Sonne', 'Solaranteil ' . pct($summary['solar_pct'], 1) . ': ' . kwh((float) $summary['solar'], 0) . ' Sonne, ' . kwh((float) $summary['grid'], 0) . ' Netz') ?>
    <dl class="kv">
      <div><dt>Geladen</dt><dd><?= e(kwh((float) $summary['energy'], 1)) ?><span class="sub"><?= (int) $summary['count'] ?> Vorgänge, <?= e(duration_label((int) $summary['duration'])) ?></span></dd></div>
      <div><dt>Kosten</dt><dd><?= e(euro((float) $summary['cost'])) ?><span class="sub">Ø <?= e(ct($summary['ct'])) ?>, gespart <?= e(euro((float) $summary['saved'])) ?></span></dd></div>
      <div><dt>CO₂ gespart</dt><dd><?= e(num((float) $summary['co2_saved_kg'], 0)) ?>&#8239;kg<span class="sub">mit <?= e(num((float) ($tariffs['co2_g_kwh'] ?? 380), 0)) ?>&#8239;g/kWh</span></dd></div>
    </dl>
  </section>
</div>
<?php
$sortLink = static function (string $key, string $label, bool $numeric = false) use ($params, $here): string {
    $active = str_starts_with($params['sort'], $key . '_');
    $dir = str_ends_with($params['sort'], '_asc') ? 'asc' : 'desc';
    $next = $key . '_' . ($active && $dir === 'desc' ? 'asc' : 'desc');
    $aria = $active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    return '<th scope="col"' . ($numeric ? ' class="num-col"' : '') . ' aria-sort="' . $aria . '"><a class="sort-btn" href="' . e($here(['sort' => $next])) . '">' . e($label)
        . ($active ? icon($dir === 'asc' ? 'arrow-up' : 'arrow-down', 'icon-16') : '') . '</a></th>';
};
?>
<?php if ($span === 'all'): ?>
<section class="section" aria-labelledby="years-title">
  <h2 class="section-title" id="years-title">Jahre</h2>
  <ul class="list-cards" role="list">
<?php foreach ($years as $item): $sum = $item['summary']; ?>
    <li><a class="list-card" href="<?= e($here(['span' => 'year', 'year' => $item['year'], 'month' => $item['year'] . '-01'])) ?>">
      <span class="list-card-title"><?= (int) $item['year'] ?></span>
      <span class="list-card-side metric-sm"><?= e(euro((float) $sum['cost'])) ?></span>
      <span class="list-card-meta"><span><?= e(kwh((float) $sum['energy'], 0)) ?></span><?= ui_pill(pct($sum['solar_pct']) . ' Solar') ?><span><?= (int) $sum['count'] ?> Vorgänge</span><span><?= e(num((float) $sum['co2_saved_kg'], 0)) ?>&#8239;kg CO₂ gespart</span></span>
    </a></li>
<?php endforeach; ?>
  </ul>
</section>
<?php else: ?>
<section class="section" aria-labelledby="list-title">
  <h2 class="section-title" id="list-title">Vorgänge</h2>
  <form class="only-narrow sort-form" method="get" action="<?= e(url('/ladevorgaenge')) ?>" data-autosubmit>
    <?php foreach (['span', 'year', 'month', 'metric'] as $keep): ?><input type="hidden" name="<?= $keep ?>" value="<?= e((string) $params[$keep]) ?>"><?php endforeach; ?>
    <?= ui_form_row('Sortierung', ui_select('sort', [
        'date_desc' => 'Neueste zuerst', 'date_asc' => 'Älteste zuerst', 'energy_desc' => 'Meist geladen', 'solar_desc' => 'Meiste Sonne',
        'cost_desc' => 'Höchste Kosten', 'cost_asc' => 'Niedrigste Kosten', 'duration_desc' => 'Längste Dauer',
    ], $params['sort'], ['id' => 'f-sort']), ['for' => 'f-sort']) ?>
  </form>
  <ul class="list-cards only-narrow" role="list">
<?php foreach ($rows as $row): $n = count($row['cycles']); ?>
    <li class="list-group"><a class="list-card" href="<?= e($here(['vorgang' => (int) $row['id']])) ?>">
      <span class="list-card-title"><?= e(($row['vehicle'] ?: 'Fahrzeug') . ' · ' . day_label(substr((string) $row['started_at'], 0, 10))) ?></span>
      <span class="list-card-side metric-sm"><?= e(euro((float) $row['costed']['cost'])) ?></span>
      <span class="list-card-meta"><span><?= e(kwh((float) $row['energy_kwh'])) ?></span><?= ui_pill(pct((float) $row['costed']['solar_pct']) . ' Solar', 'sun', (float) $row['costed']['solar_pct'] < 50) ?><?php if (empty($row['ended_at'])): ?><span>läuft</span><?php endif; ?></span>
    </a>
<?php if ($n > 1): ?>
      <button type="button" class="cycles-toggle" data-expand aria-expanded="false" aria-controls="m-cycles-<?= (int) $row['id'] ?>"><?= icon('chevron-down', 'icon-16') ?><span><?= $n ?> Ladezyklen, <?= e($range($row)) ?></span></button>
      <ul class="cycle-list" id="m-cycles-<?= (int) $row['id'] ?>" role="list" hidden>
<?php foreach ($row['cycles'] as $cycle): ?>
        <li><span><?= e($range($cycle)) ?></span><span><?= e(kwh((float) $cycle['energy_kwh'])) ?></span><span><?= e(pct((float) $cycle['costed']['solar_pct'])) ?> Sonne</span></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
  <div class="card card-flush only-wide">
    <div class="table-wrap" tabindex="0" role="region" aria-labelledby="list-title">
      <table class="table">
        <thead><tr>
          <?= $sortLink('date', 'Datum') ?>
          <th scope="col">Fahrzeug</th>
          <?= $sortLink('energy', 'Geladen', true) ?>
          <?= $sortLink('duration', 'Dauer', true) ?>
          <?= $sortLink('solar', 'Sonne', true) ?>
          <?= $sortLink('cost', 'Kosten', true) ?>
          <th scope="col" class="num-col">CO₂ gespart</th>
        </tr></thead>
<?php foreach ($rows as $row): $n = count($row['cycles']); $cid = 'cycles-' . (int) $row['id']; ?>
        <tbody class="group">
          <tr>
            <th scope="row"><span class="group-cell"><?php if ($n > 1): ?><button type="button" class="icon-btn expand-btn" data-expand aria-expanded="false" aria-controls="<?= $cid ?>" aria-label="<?= $n ?> Ladezyklen zeigen"><?= icon('chevron-right', 'icon-16') ?></button><?php else: ?><span class="expand-space"></span><?php endif; ?>
              <a class="inline-action align-start" href="<?= e($here(['vorgang' => (int) $row['id']])) ?>"><?= e(long_when((string) $row['started_at'])) ?><?= $n > 1 ? '–' . e(empty($row['ended_at']) ? 'jetzt' : $clock((string) $row['ended_at'])) : '' ?></a></span></th>
            <td><?= e((string) ($row['vehicle'] ?: '—')) ?><?php if ($n > 1): ?><span class="sub"><?= $n ?> Ladezyklen</span><?php endif; ?></td>
            <td class="num-col"><?= e(kwh((float) $row['energy_kwh'])) ?></td>
            <td class="num-col"><?= empty($row['ended_at']) ? 'läuft' : e(duration_clock((int) $row['duration_s'])) ?></td>
            <td class="num-col"><?= e(pct((float) $row['costed']['solar_pct'])) ?></td>
            <td class="num-col"><?= e(euro((float) $row['costed']['cost'])) ?></td>
            <td class="num-col"><?= e(num((float) $row['co2']['saved_kg'], 1)) ?>&#8239;kg</td>
          </tr>
        </tbody>
<?php if ($n > 1): ?>
        <tbody class="cycles" id="<?= $cid ?>" hidden>
<?php foreach ($row['cycles'] as $i => $cycle): ?>
          <tr>
            <th scope="row"><span class="cycle-time"><?= e($range($cycle)) ?></span></th>
            <td>Zyklus <?= $i + 1 ?></td>
            <td class="num-col"><?= e(kwh((float) $cycle['energy_kwh'])) ?></td>
            <td class="num-col"><?= empty($cycle['ended_at']) ? 'läuft' : e(duration_clock((int) $cycle['duration_s'])) ?></td>
            <td class="num-col"><?= e(pct((float) $cycle['costed']['solar_pct'])) ?></td>
            <td class="num-col"><?= e(euro((float) $cycle['costed']['cost'])) ?></td>
            <td class="num-col"><?= e(num((float) $cycle['co2']['saved_kg'], 1)) ?>&#8239;kg</td>
          </tr>
<?php endforeach; ?>
        </tbody>
<?php endif; ?>
<?php endforeach; ?>
        <tfoot><tr>
          <td>Summe</td><td><?= (int) $summary['count'] ?> Vorgänge</td>
          <td class="num-col"><?= e(kwh((float) $summary['energy'])) ?></td>
          <td class="num-col"><?= e(duration_clock((int) $summary['duration'])) ?></td>
          <td class="num-col"><?= e(pct($summary['solar_pct'])) ?></td>
          <td class="num-col"><?= e(euro((float) $summary['cost'])) ?></td>
          <td class="num-col"><?= e(num((float) $summary['co2_saved_kg'], 1)) ?>&#8239;kg</td>
        </tr></tfoot>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
<?php
// Ebene 2: Detail eines Vorgangs
if ($detail) {
    $cost = Sessions::cost($detail, $tariffs);
    $co2 = Sessions::co2($detail, (float) ($tariffs['co2_g_kwh'] ?? 380));
    $seconds = (int) $detail['duration_s'];
    $avg = $seconds > 0 ? (float) $detail['energy_kwh'] / ($seconds / 3600) : null;
    $running = empty($detail['ended_at']);
    $hidden = '';
    foreach (['span', 'year', 'month', 'metric', 'sort'] as $keep) {
        $hidden .= '<input type="hidden" name="' . $keep . '" value="' . e((string) $params[$keep]) . '">';
    }
    ob_start();
    ?>
<dl class="kv">
  <div><dt>Ladepunkt</dt><dd><?= e((string) ($detail['loadpoint'] ?: 'Wallbox')) ?></dd></div>
  <div><dt>Fahrzeug</dt><dd><?= e((string) ($detail['vehicle'] ?: '—')) ?></dd></div>
  <div><dt>Zeitraum</dt><dd><?= e(long_when((string) $detail['started_at'])) ?><span class="sub"><?= $running ? 'läuft noch' : 'bis ' . e(long_when((string) $detail['ended_at'])) ?></span></dd></div>
  <div><dt>Geladen</dt><dd><?= e(kwh((float) $detail['energy_kwh'])) ?><span class="sub"><?= e(duration_clock($seconds)) ?><?= $avg !== null ? ', Ø ' . e(kw($avg)) : '' ?></span></dd></div>
  <div><dt>Solar</dt><dd><?= ui_pill(pct((float) $cost['solar_pct']) . ' Solar') ?><span class="sub"><?= e(kwh((float) $detail['solar_kwh'])) ?> Sonne, <?= e(kwh((float) $detail['grid_kwh'])) ?> Netz</span></dd></div>
  <div><dt>Kosten</dt><dd><?= e(euro((float) $cost['cost'])) ?><span class="sub">Ø <?= e(ct((float) $cost['ct'])) ?>, gespart <?= e(euro((float) $cost['saved'])) ?></span></dd></div>
  <div><dt>CO₂</dt><dd><?= e(num((float) $co2['saved_kg'], 1)) ?>&#8239;kg gespart<span class="sub"><?= e(num((float) $co2['caused_kg'], 1)) ?>&#8239;kg aus dem Netz</span></dd></div>
  <div><dt>Zählerstand</dt><dd><?php if ($detail['meter_start'] !== null && $detail['meter_end'] !== null): ?><?= e(num((float) $detail['meter_start'], 1)) ?>–<?= e(kwh((float) $detail['meter_end'])) ?><?php else: ?>—<?php endif; ?></dd></div>
</dl>
<?php if (count($detail['cycles']) > 1): ?>
<div class="stack-tight">
  <h3 class="label"><?= count($detail['cycles']) ?> Ladezyklen</h3>
  <ol class="cycle-list" role="list">
<?php foreach ($detail['cycles'] as $cycle): ?>
    <li><span><?= e($range($cycle)) ?></span><span><?= e(kwh((float) $cycle['energy_kwh'])) ?></span><span><?= e(pct(Sessions::cost($cycle, $tariffs)['solar_pct'])) ?> Sonne</span></li>
<?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/ladevorgaenge')) ?>" class="stack-tight">
  <?= csrf_field() ?><?= $hidden ?><input type="hidden" name="action" value="odometer"><input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
  <div class="form-rows"><?= ui_form_row('Kilometerstand', ui_input('odometer', $detail['odometer'] !== null && $detail['odometer'] !== '' ? (string) $detail['odometer'] : '', ['id' => 'f-odometer', 'class' => 'field-num', 'inputmode' => 'decimal', 'placeholder' => 'km']), ['for' => 'f-odometer']) ?></div>
  <div class="form-actions"><button class="btn btn-secondary" type="submit">Kilometerstand speichern</button></div>
</form>
    <?php
    $foot = $running
        ? '<p class="caption muted">Ein laufender Vorgang lässt sich nicht löschen.</p><button type="button" class="btn btn-primary" data-close-dialog>Fertig</button>'
        : '<button type="button" class="text-action text-danger" data-open-dialog="session-delete">' . icon('trash-2', 'icon-16') . 'Löschen</button><button type="button" class="btn btn-primary" data-close-dialog>Fertig</button>';
    echo ui_dialog('session-detail', 'Ladevorgang', ob_get_clean(), ['autoopen' => true, 'return' => $here(), 'foot' => $foot]);
    if (!$running) {
        $cyclesText = count($detail['cycles']) > 1 ? ' in ' . count($detail['cycles']) . ' Ladezyklen' : '';
        echo ui_dialog('session-delete', 'Ladevorgang löschen?', '<p class="body">' . e(long_when((string) $detail['started_at'])) . ', ' . e(kwh((float) $detail['energy_kwh'])) . e($cyclesText) . '. Der Vorgang verschwindet aus Liste, Diagramm und Summen. Das lässt sich nicht rückgängig machen.</p>'
            . '<form method="post" action="' . e(url('/ladevorgaenge')) . '" class="form-actions">' . csrf_field() . $hidden
            . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $detail['id'] . '">'
            . '<button type="button" class="btn btn-secondary" data-close-dialog>Abbrechen</button><button class="btn btn-danger" type="submit">' . icon('trash-2', 'icon-16') . 'Löschen</button></form>');
    }
}
view('partials/overview', ['overview' => $overview]);
