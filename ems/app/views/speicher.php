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
// Aus dem Netz laden (BatteryPlan): Einstellungen, Stand, was das Schalten gerade verhindert, und die Sätze dazu.
$gridSettings = BatteryPlan::settings($snap['cfg']);
$gridState = (new BatteryPlan(store(), ha()))->state();
try {
    $gridBlocker = BatteryPlan::blocker($snap['cfg'], ha()->index());
} catch (Throwable) {
    $gridBlocker = 'Home Assistant ist nicht erreichbar.';
}
$gridText = BatteryPlan::text($snap['battery_plan'] ?? null, $gridSettings, $gridState, $gridBlocker, time());
$read =static fn (string $key, int $value): string => '<span data-zone-read="' . e($key) . '">' . $value . '</span>' . NNBSP . '%';
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
          <p class="body">Von <?= $read('priority', $priority) ?> bis <?= $limit('buffer', $buffer) ?> hat das Auto den Überschuss. Im Modus Intelligent bleibt der Speicher in diesem Bereich fürs Haus.</p></li>
        <li class="zone-item"><span class="zone-icon zone-icon-boost"><?= icon('zap', 'icon-20') ?></span>
          <p class="body"><span data-zone-when="buffer-on"<?= $buffer >= 100 ? ' hidden' : '' ?>>Ab <?= $read('buffer', $buffer) ?> darf gespeicherte Energie das Auto stützen.</span><span data-zone-when="buffer-off"<?= $buffer >= 100 ? '' : ' hidden' ?>>Gespeicherte Energie stützt das Auto nicht, die Grenze steht auf 100<?= NNBSP ?>%.</span>
            <span data-zone-when="auto-on"<?= $auto >= 100 ? ' hidden' : '' ?>>Ab <?= $limit('auto', $auto) ?> startet die Ladung auch ohne Sonne.</span><span data-zone-when="auto-off"<?= $auto >= 100 ? '' : ' hidden' ?>>Ohne Sonne startet keine Ladung.</span></p></li>
        <li class="zone-item"><span class="zone-icon zone-icon-reserve"><?= icon('shield', 'icon-20') ?></span>
          <p class="body"><span data-live="reserve.text"><?= e((string) $snap['reserve']['text']) ?></span> <?= ui_inline('Puffer einstellen', ['href' => url('/einstellungen/speicher')]) ?></p></li>
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
      <?= ui_divider('Die nächsten Tage') ?>
      <div class="table-wrap" tabindex="0" role="region" aria-label="Sonne und Haus in den nächsten Tagen">
        <table class="compare-table">
          <thead><tr><th scope="col">Tag</th><th scope="col">Sonne<span class="sub">kWh</span></th><th scope="col">Haus<span class="sub">kWh</span></th><th scope="col">Bilanz<span class="sub">kWh</span></th></tr></thead>
          <tbody>
<?php foreach ($snap['days_outlook'] as $day): ?>
<?php $balance = $day['balance_kwh']; ?>
            <tr><th scope="row"><?= e((string) $day['label']) ?></th><td><?= e(num($day['sun_kwh'], 1)) ?></td><td><?= e(num($day['house_kwh'], 1)) ?></td>
              <td><?= e($balance === null ? '—' : ($balance >= 0 ? '+' : '−') . num(abs((float) $balance), 1)) ?></td></tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="caption muted">Sonne laut Prognose, Haus mit dem Mittel der letzten 30 Tage ohne Wallbox. Was fehlt, deckt der Speicher, solange er reicht, danach das Netz.</p>
      <?= ui_divider('Aus dem Netz laden') ?>
      <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack" data-autosubmit>
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="battery_grid"><input type="hidden" name="back" value="/speicher">
        <div class="form-rows"><?= ui_form_row('In Ladefenstern laden, wenn die Sonne nicht reicht', ui_toggle('grid_charge', $gridSettings['on'], 'Speicher in Ladefenstern aus dem Netz laden'), ['for' => 'f-grid_charge']) ?></div>
        <noscript><div class="form-actions"><button class="btn btn-secondary" type="submit">Speichern</button></div></noscript>
      </form>
      <p class="body"><?= e($gridText['head']) ?></p>
<?php if ($gridText['lines']): ?>
      <ul class="plain-list stack-tight body-sm muted" role="list">
<?php foreach ($gridText['lines'] as $line): ?>
        <li><?= e($line) ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
<?php if ($gridSettings['on'] && $gridBlocker !== '' && $gridBlocker !== 'aus'): ?>
      <?= ui_notice('info', e('Noch schaltet EMS nicht: ' . $gridBlocker)) ?>
<?php endif; ?>
<?php if (!empty($gridState['error'])): ?>
      <?= ui_notice('triangle-alert', e((string) $gridState['error']), 'warn') ?>
<?php endif; ?>
      <p class="caption muted">EMS lädt nur in aktiven <?= ui_inline('Ladefenstern', ['href' => url('/prognose/strompreis') . '#windows-title']) ?> und nur so viel, wie bis einen Tag danach fehlt, wenn der Preisunterschied Verluste und Verschleiß übersteigt. Ins Netz geht davon nichts zurück. Leistung, Grenzen und Entitäten unter <?= ui_inline('Einstellungen → Speicher', ['href' => url('/einstellungen/speicher')]) ?>.</p>
<?php if (!empty($gridState['log'])): ?>
      <ul class="plain-list stack-tight body-sm" role="list" aria-label="Zuletzt aus dem Netz geladen">
<?php foreach (array_slice((array) $gridState['log'], 0, 4) as $row): ?>
        <li><span class="num muted"><?= e(date('d.m. H:i', (int) $row['t'])) ?></span> <?= e((string) $row['text']) ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
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
<p class="body muted">Die Grenzen rasten auf 5 % und bleiben geordnet: Haus bis Auto bis Start. Sie wirken in den Modi Intelligent und Min+Sonne auf den Vorschlag und die Zeiten.</p>
<div class="stack">
  <div class="range"><div class="range-head"><label for="z-priority">Haus bis</label><output class="range-out" for="z-priority"><?= e(pct($priority)) ?></output></div>
    <input type="range" id="z-priority" min="0" max="100" step="5" value="<?= $priority ?>" data-zone-input="priority"></div>
  <div class="range"><div class="range-head"><label for="z-buffer">Auto bis, darüber batteriegestützt</label><output class="range-out" for="z-buffer"><?= e(pct($buffer)) ?></output></div>
    <input type="range" id="z-buffer" min="0" max="100" step="5" value="<?= $buffer ?>" data-zone-input="buffer"></div>
  <div class="range"><div class="range-head"><label for="z-auto">Start ohne Sonne ab</label><output class="range-out" for="z-auto"><?= e(pct($auto)) ?></output></div>
    <input type="range" id="z-auto" min="0" max="100" step="5" value="<?= $auto ?>" data-zone-input="auto"></div>
</div>
<?= ui_divider('Zonen im Detail') ?>
<p class="body-sm muted">So entscheidet Intelligent. Min+Sonne hält zusätzlich den Mindeststrom; was dafür fehlt, deckt der Speicher bis zum Backup-Puffer, dann das Netz.</p>
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
  <div><dt>Laden aus</dt><dd>Kein Ladestrom. Der Überschuss geht in den Speicher, danach ins Netz.</dd></div>
  <div><dt>Intelligent</dt><dd>Die drei Zonen gelten. Unter der Hausgrenze zuerst der Speicher, darüber das Auto, ab der Stützung darf der Speicher mit.</dd></div>
  <div><dt>Min+Sonne</dt><dd>Die Mindestleistung bleibt an. Was die Sonne nicht schafft, deckt der Speicher bis zum Backup-Puffer, dann das Netz.</dd></div>
  <div><dt>Schnell</dt><dd>Volle Leistung, die Grenzen bleiben außen vor. Was die Sonne nicht schafft, deckt der Speicher bis zum Backup-Puffer, dann das Netz.</dd></div>
  <div><dt>Netzladen</dt><dd>„Jetzt“ oder „In Ladefenstern“, eingestellt in der Ladepunkt-Karte. Volle Leistung, die Grenzen bleiben außen vor. Was die Sonne nicht schafft, kommt aus dem Netz: Solange das Auto lädt, hebt die App den Backup-Puffer auf den Ladestand, danach gilt wieder der Standardwert.</dd></div>
</dl>
<p class="caption muted">Aktuell: <?= e($mode === 'aus' ? 'Laden aus' : Energy::modeLabel($mode)) ?>. Geändert wird sofort, ohne Speichern-Knopf. Backup-Puffer und Entladeleistung stehen unter <?= ui_inline('Einstellungen → Speicher', ['href' => url('/einstellungen/speicher')]) ?>.</p>
<?php
echo ui_dialog('battery-limits', 'Grenzen des Speichers', ob_get_clean());
view('partials/overview', ['overview' => $overview]);
