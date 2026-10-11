<?php
declare(strict_types=1);
/**
 * Statistik: eine Kennzahl als Heatmap über einen Tag (24 Stunden), einen Monat (Kalender, umschaltbar auf Tage ×
 * Stunden), ein Quartal oder Jahr (Monatsblöcke), alle Jahre (Jahre × Monate) oder einen eigenen Zeitraum. Die
 * Filter sind ein GET-Formular, das beim Wechsel abschickt; geblättert wird über Links. Die Zellen zeichnet
 * components/heatmap.js aus /api/series?chart=heatmap.
 * @var array $filters aus Heatmap::params()
 * @var array $range aus Heatmap::range()
 */
$p = $filters;
$def = Heatmap::METRICS[$p['metric']];
$step = ['day' => ['vorheriger Tag', 'nächster Tag'], 'month' => ['vorheriger Monat', 'nächster Monat'], 'quarter' => ['vorheriges Quartal', 'nächstes Quartal'], 'year' => ['vorheriges Jahr', 'nächstes Jahr'], 'custom' => ['Zeitraum davor', 'Zeitraum danach']][$p['span']] ?? null;
$link = static function (mixed $anchor) use ($p): string {
    $patch = is_array($anchor) ? $anchor : ['date' => (string) $anchor];
    return url('/statistik') . Heatmap::query($p, $patch);
};
$src = '/api/series' . Heatmap::query($p, ['chart' => 'heatmap']);
$hours = Heatmap::hoursAllowed($p);

echo ui_page_head('Statistik');
?>
<div class="stack">
<form method="get" action="<?= e(url('/statistik')) ?>" class="card stack hm-filters" data-autosubmit aria-label="Filter">
  <div class="form-rows">
    <div class="form-row">
      <label for="f-metric">Kennzahl</label>
      <div class="control"><span class="select"><select name="metric" id="f-metric">
<?php foreach (Heatmap::GROUPS as $group => $groupLabel): ?>
        <optgroup label="<?= e($groupLabel) ?>">
<?php foreach (Heatmap::METRICS as $key => $metric): ?>
<?php if ($metric['group'] === $group): ?>
          <option value="<?= e($key) ?>"<?= $key === $p['metric'] ? ' selected' : '' ?>><?= e($metric['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
        </optgroup>
<?php endforeach; ?>
      </select><?= icon('chevron-down', 'icon-16') ?></span></div>
    </div>
  </div>
  <?= ui_segment('span', 'Zeitraum', [
      'day' => 'Tag',
      'month' => ['label' => 'Monat', 'short' => 'Mon.'],
      'quarter' => ['label' => 'Quartal', 'short' => 'Qu.'],
      'year' => 'Jahr',
      'all' => ['label' => 'Gesamt', 'short' => 'Alle'],
      'custom' => ['label' => 'Eigener', 'short' => 'Frei'],
  ], $p['span'], ['id' => 'f-span']) ?>
<?php if (in_array($p['span'], ['month', 'custom'], true)): ?>
  <?= ui_segment('raster', 'Raster', [
      'days' => ['label' => 'Tage', 'icon' => 'calendar-days'],
      'hours' => ['label' => 'Tage × Stunden', 'icon' => 'grid-3x3', 'disabled' => !$hours],
  ], $p['raster'], ['id' => 'f-raster']) ?>
<?php endif; ?>
<?php if ($p['span'] === 'custom'): ?>
  <div class="hm-custom">
    <div class="form-rows">
      <?= ui_form_row('Von', ui_input('from', $p['from'], ['id' => 'f-from', 'type' => 'date', 'class' => 'field-num']), ['for' => 'f-from']) ?>
      <?= ui_form_row('Bis', ui_input('to', $p['to'], ['id' => 'f-to', 'type' => 'date', 'class' => 'field-num']), ['for' => 'f-to', 'hint' => 'Höchstens zwei Jahre; Tage × Stunden bis ' . Heatmap::HOUR_DAYS . ' Tage.']) ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Anzeigen</button></div>
  </div>
<?php else: ?>
  <input type="hidden" name="date" value="<?= e($p['date']) ?>">
<?php endif; ?>
  <noscript><div class="form-actions"><button class="btn btn-primary" type="submit">Anzeigen</button></div></noscript>
</form>

<section class="card stack hm-card" aria-labelledby="hm-title">
  <div class="period-nav hm-nav">
<?php if ($range['prev'] !== null): ?>
    <a class="icon-btn" href="<?= e($link($range['prev'])) ?>" aria-label="<?= e(ucfirst($step[0])) ?>"><?= icon('chevron-left', 'icon-20') ?></a>
<?php else: ?>
    <span></span>
<?php endif; ?>
    <h2 class="period-label card-title" id="hm-title"><span class="hm-metric"><?= e($def['label']) ?></span><span class="hm-when"><?= e($range['title']) ?></span></h2>
<?php if ($range['next'] !== null): ?>
    <a class="icon-btn" href="<?= e($link($range['next'])) ?>" aria-label="<?= e(ucfirst($step[1])) ?>"><?= icon('chevron-right', 'icon-20') ?></a>
<?php else: ?>
    <span></span>
<?php endif; ?>
  </div>
<?php if (!$range['current'] && $range['today'] !== ''): ?>
  <a class="text-action hm-today" href="<?= e($link($range['today'])) ?>"><?= icon('calendar', 'icon-16') ?>Zurück zu heute</a>
<?php endif; ?>
  <figure class="hm" data-heatmap data-src="<?= e(url($src)) ?>" data-color="<?= e($def['color']) ?>" data-layout="<?= e($range['layout']) ?>" aria-labelledby="hm-title" aria-describedby="hm-detail">
    <div class="hm-frame" data-hm-frame data-loading aria-hidden="true"></div>
    <figcaption class="hm-legend caption" data-hm-legend hidden>
      <span data-hm-lo></span><span class="hm-ramp" aria-hidden="true"></span><span data-hm-hi></span>
      <span class="hm-nodata"><span class="hm-swatch" aria-hidden="true"></span>keine Daten</span>
    </figcaption>
  </figure>
  <p class="body-sm hm-detail" id="hm-detail" data-hm-detail aria-live="polite">Ein Feld antippen oder darauf zeigen, dann steht hier sein Wert.</p>
  <div class="hm-notes" data-hm-notes></div>
  <dl class="hm-summary" data-hm-summary></dl>
  <details class="hm-table">
    <summary><?= icon('chevron-down', 'icon-16') ?><span>Alle Werte als Tabelle</span></summary>
    <div class="hm-table-body" data-hm-table></div>
  </details>
</section>
</div>
