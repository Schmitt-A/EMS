<?php
declare(strict_types=1);
/**
 * Speicher (8): Heimspeicher-Säule mit drei Zonen, Erklärliste, Ausblick und Speicherverlauf.
 * Ebene 2: Grenzen mit Reglern und die Zonen-Tabelle.
 * @var array $snap
 * @var array $live
 * @var array $overview
 */
$v = $snap['values'];
$z = zone_thresholds(
    (float) ($snap['cfg']['battery_strategy']['priority_soc'] ?? 80),
    (float) ($snap['cfg']['battery_strategy']['car_buffer_soc'] ?? 100),
    (float) ($snap['cfg']['battery_strategy']['car_auto_soc'] ?? 100),
);
$priority = (int) $z['priority_soc'];
$buffer = (int) $z['car_buffer_soc'];
$auto = (int) $z['car_auto_soc'];
$saved = (float) $overview['periods']['all']['saved'];
$mode = (string) ($snap['cfg']['charge']['mode'] ?? 'smart');
$read = static fn (string $key, int $value): string => '<span data-zone-read="' . e($key) . '">' . $value . '</span>' . NNBSP . '%';
$limit = static fn (string $key, int $value): string => ui_inline('<span class="sr-only">Grenze ändern, </span>' . $read($key, $value), ['data-open-dialog' => 'battery-limits']);

echo ui_page_head('Speicher', ui_head_chip('coins', e(num($saved, 2)) . NNBSP . '€', 'Ersparnis ' . euro($saved) . '. Energieübersicht öffnen'));

if (!$snap['connected']) {
    echo ui_notice('wifi-off', e((string) ($snap['error'] ?? 'Keine Verbindung.')), 'error');
}
?>
<div class="storage-grid">
  <section class="card storage-figure" aria-labelledby="col-title">
    <h2 class="sr-only" id="col-title">Hausspeicher</h2>
    <?= ui_battery_column([
        'soc' => $v['battery_soc'] !== null ? (float) $v['battery_soc'] : null,
        'stored_text' => Snapshot::storedText($v['battery_capacity_kwh'], $v['battery_total_kwh']),
        'priority' => $priority,
        'buffer' => $buffer,
        'auto' => $auto,
        'flow' => $live['battery']['flow'] ?? 'ruhe',
    ]) ?>
    <p class="body-sm muted storage-activity" data-live="battery.activity_text"><?= e((string) ($live['battery']['activity_text'] ?? '')) ?></p>
    <button type="button" class="btn btn-secondary" data-open-dialog="battery-limits"><?= icon('sliders-horizontal', 'icon-16') ?>Grenzen ändern</button>
  </section>
  <div class="stack">
    <section class="card" aria-labelledby="zones-title">
      <h2 class="card-title" id="zones-title">So wird geladen</h2>
      <ul class="zone-list" role="list">
        <li class="zone-item"><span class="zone-icon zone-icon-house"><?= icon('house', 'icon-20') ?></span>
          <p class="body">Bis <?= $limit('priority', $priority) ?> geht Sonnenüberschuss zuerst in den Hausspeicher. Das Auto bekommt nur, was der Speicher gerade nicht aufnimmt.</p></li>
        <li class="zone-item"><span class="zone-icon zone-icon-car"><?= icon('car', 'icon-20') ?></span>
          <p class="body">Von <?= $read('priority', $priority) ?> bis <?= $limit('buffer', $buffer) ?> hat das Auto den Überschuss. Der Speicher bleibt in diesem Bereich fürs Haus.</p></li>
        <li class="zone-item"><span class="zone-icon zone-icon-boost"><?= icon('zap', 'icon-20') ?></span>
          <p class="body"><span data-zone-when="buffer-on"<?= $buffer >= 100 ? ' hidden' : '' ?>>Ab <?= $read('buffer', $buffer) ?> darf gespeicherte Energie das Auto stützen.</span><span data-zone-when="buffer-off"<?= $buffer >= 100 ? '' : ' hidden' ?>>Gespeicherte Energie stützt das Auto nicht, die Grenze steht auf 100<?= NNBSP ?>%.</span>
            <span data-zone-when="auto-on"<?= $auto >= 100 ? ' hidden' : '' ?>>Ab <?= $limit('auto', $auto) ?> startet die Ladung auch ohne Sonne.</span><span data-zone-when="auto-off"<?= $auto >= 100 ? '' : ' hidden' ?>>Ohne Sonne startet keine Ladung.</span></p></li>
      </ul>
    </section>
    <section class="card stack" aria-labelledby="outlook-title">
      <h2 class="card-title" id="outlook-title">Ausblick</h2>
      <ul class="plain-list stack-tight body" role="list">
        <li data-live="battery_full"><?= e((string) $live['battery_full']) ?></li>
        <li data-live="battery_priority" data-live-hide="battery_priority"<?= $live['battery_priority'] === '' ? ' hidden' : '' ?>><?= e((string) $live['battery_priority']) ?></li>
        <li data-live="battery_buffer"><?= e((string) $live['battery_buffer']) ?></li>
        <li class="muted" data-live="battery_surplus"><?= e((string) $live['battery_surplus']) ?></li>
      </ul>
      <p class="caption muted">Hausverbrauch der letzten 30 Tage ohne Wallbox: <span class="num" data-live="house_mean"><?= e((string) $live['house_mean']) ?></span></p>
    </section>
    <section class="card" aria-labelledby="history-title">
      <h2 class="card-title" id="history-title">Speicherverlauf</h2>
      <?= ui_chart('time', '/api/series?chart=battery', 'Ladestand des Hausspeichers', [
          'window' => '3',
          'anchor' => 'now',
          'class' => 'chart-tall',
          // Wie die Solarprognose: Fläche in Amber, Tageswerte als Kapseln.
          'style' => ['soc' => ['color' => 'solar', 'type' => 'area', 'curve' => 'linear', 'unit' => '%', 'decimals' => 0], 'cap' => ['color' => 'solar', 'type' => 'area', 'curve' => 'linear', 'unit' => 'kWh', 'decimals' => 1]],
          'tools' => '<div class="chart-tools">'
              . ui_segment('battery-window', 'Zeitraum', ['3' => '3 Tage', '7' => '7 Tage'], '3', ['compact' => true, 'fit' => true, 'id' => 'bw', 'attrs' => ['data-chart-window' => true]])
              . ui_segment('battery-unit', 'Einheit', ['soc' => '%', 'cap' => 'kWh'], 'soc', ['compact' => true, 'fit' => true, 'id' => 'bu', 'attrs' => ['data-chart-unit' => true]])
              . '</div>',
          'empty' => 'Noch kein Verlauf. Der Ladestand braucht die Statistik von Home Assistant.',
      ]) ?>
    </section>
  </div>
</div>
<?php
// Ebene 2: Grenzen
$rows = [[
    'stand' => 'unter ' . $priority . NNBSP . '%',
    'sun' => 'Speicher zuerst. Das Auto bekommt nur, was die Batterie nicht mehr aufnimmt.',
    'dark' => 'Keine Ladung.',
]];
if ($buffer > $priority) {
    $rows[] = ['stand' => $priority . '–' . $buffer . NNBSP . '%', 'sun' => 'Auto aus dem Überschuss. Der Speicher bleibt fürs Haus.', 'dark' => 'Der Vorschlag setzt aus, sobald der Überschuss unter der Mindestleistung liegt.'];
}
if ($auto > $buffer) {
    $rows[] = ['stand' => $buffer . '–' . $auto . NNBSP . '%', 'sun' => 'Auto lädt. Der Speicher darf bis ' . $buffer . NNBSP . '% mit entladen.', 'dark' => 'Keine neue Ladung. Eine laufende Ladung endet bei ' . $buffer . NNBSP . '%.'];
}
// Eine Grenze auf 100 % schaltet Stützung und Start ohne Sonne ab (wie Energy::zone()).
$rows[] = [
    'stand' => 'ab ' . $auto . NNBSP . '%',
    'sun' => $buffer >= 100 ? 'Auto aus dem Überschuss. Der Speicher stützt nicht.' : 'Auto lädt. Der Speicher darf bis ' . $buffer . NNBSP . '% mit.',
    'dark' => $auto >= 100 ? 'Ohne Sonne startet keine Ladung.' : 'Die Ladung startet auch ohne Sonne und endet bei ' . $buffer . NNBSP . '%.',
];
ob_start();
?>
<p class="body muted">Die Grenzen rasten auf 5 % und bleiben geordnet: Haus bis Auto bis Start. Sie wirken im Modus Solar und Min+Solar auf den Vorschlag und die Zeiten.</p>
<div class="stack">
  <div class="range"><div class="range-head"><label for="z-priority">Haus bis</label><output class="range-out" for="z-priority"><?= e(pct($priority)) ?></output></div>
    <input type="range" id="z-priority" min="0" max="100" step="5" value="<?= $priority ?>" data-zone-input="priority"></div>
  <div class="range"><div class="range-head"><label for="z-buffer">Auto bis, darüber batteriegestützt</label><output class="range-out" for="z-buffer"><?= e(pct($buffer)) ?></output></div>
    <input type="range" id="z-buffer" min="0" max="100" step="5" value="<?= $buffer ?>" data-zone-input="buffer"></div>
  <div class="range"><div class="range-head"><label for="z-auto">Start ohne Sonne ab</label><output class="range-out" for="z-auto"><?= e(pct($auto)) ?></output></div>
    <input type="range" id="z-auto" min="0" max="100" step="5" value="<?= $auto ?>" data-zone-input="auto"></div>
</div>
<?= ui_divider('Zonen im Detail') ?>
<div class="table-wrap" tabindex="0" role="region" aria-label="Zonen im Detail">
  <table class="table">
    <thead><tr><th scope="col">Speicher</th><th scope="col">Sonne reicht</th><th scope="col">Sonne reicht nicht</th></tr></thead>
    <tbody>
<?php foreach ($rows as $row): ?>
      <tr><th scope="row" class="num"><?= e($row['stand']) ?></th><td><?= e($row['sun']) ?></td><td><?= e($row['dark']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= ui_divider('Modi und Grenzen') ?>
<dl class="kv">
  <div><dt>Aus</dt><dd>Kein Ladestrom. Der Überschuss geht in den Speicher, danach ins Netz.</dd></div>
  <div><dt>Solar</dt><dd>Die drei Zonen gelten. Unter der Hausgrenze zuerst der Speicher, darüber das Auto, ab der Stützung darf der Speicher mit.</dd></div>
  <div><dt>Min+Solar</dt><dd>Die Mindestleistung bleibt an. Die Hausgrenze senkt höchstens bis auf diesen Strom.</dd></div>
  <div><dt>Schnell</dt><dd>Volle Leistung. Die Grenzen bleiben außen vor, der Hausspeicher deckt den Bezug.</dd></div>
</dl>
<p class="caption muted">Aktuell: <?= e(Energy::modeLabel($mode)) ?>. Geändert wird sofort, ohne Speichern-Knopf.</p>
<?php
echo ui_dialog('battery-limits', 'Grenzen des Speichers', ob_get_clean());
view('partials/overview', ['overview' => $overview]);
