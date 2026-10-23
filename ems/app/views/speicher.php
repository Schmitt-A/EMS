<?php
declare(strict_types=1);
/**
 * Speicher (8): oben die Heimspeicher-Säule mit drei Zonen und daneben, gleich hoch, der Speicherverlauf; darunter in
 * voller Breite „So wird geladen“ und der Ausblick: wann Speicher-Vorrang, batteriegestütztes Laden und voll erreicht
 * sind, Überschuss und Hausverbrauch, die nächsten Tage (Speicher am Anfang, Sonne, Haus, Bilanz, Netz und Speicher am
 * Ende, BatteryPlan::days()) und die Ladepläne des Speichers (BatteryPlans) mit dem, was der nächste gerade plant, und
 * dem Protokoll; je Plan ein Sheet.
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
$now = time();
$outlook = $live['outlook'];
// Laden aus dem Netz (BatteryPlan): Stand, Protokoll und was das Schalten gerade verhindert.
$gridState = (new BatteryPlan(store(), ha()))->state();
try {
    $gridBlocker = BatteryPlan::blocker($snap['cfg'], ha()->index());
} catch (Throwable) {
    $gridBlocker = 'Home Assistant ist nicht erreichbar.';
}
// Ladepläne des Speichers: Liste und was der nächste fällige gerade plant.
$batteryPlans = BatteryPlans::all(store());
$planState = BatteryPlans::state(store());
$planRows = BatteryPlans::rows($batteryPlans, $planState, BatteryPlans::windows(store(), new Tariff(store()), $now), $now, (int) store()->get(BatteryPlans::DONE, 0));
$planText = BatteryPlans::text($planState, $now);
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
  <section class="card storage-history" aria-labelledby="history-title">
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
  <section class="card storage-wide" aria-labelledby="zones-title">
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
  <section class="card stack storage-wide" aria-labelledby="outlook-title">
    <h2 class="card-title" id="outlook-title">Ausblick</h2>
    <dl class="kv outlook-list">
      <div><dt>Speicher-Vorrang <?= e(pct((float) $priority)) ?></dt><dd data-live="outlook.priority"><?= e((string) $outlook['priority']) ?></dd></div>
      <div><dt>Batteriegestützt ab <?= e(pct((float) $buffer)) ?></dt><dd data-live="outlook.buffer"><?= e((string) $outlook['buffer']) ?></dd></div>
      <div><dt>Voll, 100<?= NNBSP ?>%</dt><dd data-live="outlook.full"><?= e((string) $outlook['full']) ?></dd></div>
      <div><dt>Überschuss bis Mitternacht</dt><dd data-live="outlook.surplus"><?= e((string) $outlook['surplus']) ?></dd></div>
      <div><dt>Hausverbrauch</dt><dd><span data-live="outlook.house"><?= e((string) $outlook['house']) ?></span><span class="sub">Üblicher Tag ohne Wallbox: je Stunde der Median der letzten 30 Tage, damit einzelne Tage mit Laden nicht zählen.</span></dd></div>
    </dl>
    <?= ui_divider('Die nächsten Tage') ?>
<?php
$days = $snap['days_outlook'];
$withBattery = array_filter($days, static fn (array $d): bool => $d['start_kwh'] !== null) !== [];
$signed = static fn (?float $v): string => $v === null ? '—' : ($v > 0.04 ? '+' : ($v < -0.04 ? '−' : '')) . num(abs($v), 1);
$cells = static function (string $key, ?string $socKey = null, bool $sign = false) use ($days, $signed): string {
    $html = '';
    foreach ($days as $d) {
        $value = $d[$key] === null ? null : (float) $d[$key];
        $html .= '<td>' . e($sign ? $signed($value) : num($value, 1)) . ($socKey !== null && $d[$socKey] !== null ? '<span class="sub">' . e(pct((float) $d[$socKey])) . '</span>' : '') . '</td>';
    }
    return $html;
};
$floorSoc = (float) ($snap['floor_soc'] ?? 0);
?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Speicher, Sonne und Haus in den nächsten Tagen">
      <table class="compare-table outlook-days">
        <thead><tr><th scope="col">kWh</th><?php foreach ($days as $d): ?><th scope="col"><?= e($d['head']) ?><?= $d['sub'] !== '' ? '<span class="sub">' . e($d['sub']) . '</span>' : '' ?></th><?php endforeach; ?></tr></thead>
        <tbody>
<?php if ($withBattery): ?>
          <tr><th scope="row">Im Speicher<span class="sub">nutzbar, am Anfang</span></th><?= $cells('start_kwh', 'start_soc') ?></tr>
<?php endif; ?>
          <tr><th scope="row">+ Sonne</th><?= $cells('sun_kwh') ?></tr>
          <tr><th scope="row">− Haus</th><?= $cells('house_kwh') ?></tr>
          <tr class="row-sum"><th scope="row">= Bilanz</th><?= $cells('balance_kwh', null, true) ?></tr>
<?php if ($withBattery): ?>
          <tr><th scope="row">Ins Netz<span class="sub">Speicher voll</span></th><?= $cells('export_kwh') ?></tr>
          <tr><th scope="row">Aus dem Netz<span class="sub">Speicher leer</span></th><?= $cells('import_kwh') ?></tr>
          <tr><th scope="row">Im Speicher<span class="sub">am Ende des Tages</span></th><?= $cells('end_kwh', 'end_soc') ?></tr>
<?php endif; ?>
        </tbody>
      </table>
    </div>
<?php if ($withBattery): ?>
    <p class="caption muted"><?= e('Im Speicher steht, was über dem Backup-Puffer' . ($floorSoc > 0 ? ' von ' . pct($floorSoc) : '') . ' nutzbar ist, heute das von jetzt, darunter der Ladestand. Bilanz: Speicher plus Sonne minus Haus. Viertelstunde für Viertelstunde lädt die Sonne den Speicher; was nicht mehr hineinpasst, geht ins Netz. Abends und nachts deckt der Speicher das Haus bis zum Puffer, den Rest das Netz. Was am Ende übrig ist, hat der nächste Tag am Anfang. Sonne laut Prognose, Haus aus dem üblichen Tagesverlauf, ohne Verluste beim Laden und Entladen.') ?></p>
<?php else: ?>
    <p class="caption muted">Sonne laut Prognose, Haus aus dem üblichen Tagesverlauf; heute nur noch der Rest des Tages. Was fehlt, deckt der Speicher, solange er reicht, danach das Netz. Mit Ladestand und Größe des Speichers stünde hier auch, was er dazu beiträgt.</p>
<?php endif; ?>
    <?= ui_divider('Ladepläne') ?>
    <div class="cp-plans stack-tight" id="battery-plans">
      <p class="body-sm muted">Den Speicher aus dem Netz laden, wenn Strom günstig ist: im Zeitfenster deines Tarifs oder, dynamisch, in den Ladefenstern, an gewählten Tagen oder einmalig, auf einen Ladestand oder nach Bedarf.</p>
<?php if ($planText !== ''): $charging = in_array($planState['status'] ?? '', ['ok', 'tight'], true); ?>
      <div class="boost-advice<?= $charging ? ' boost-advice-charge' : '' ?>"><p class="body-sm boost-advice-head"><?= icon($charging ? 'battery-charging' : 'info', 'icon-20') ?><span><?= e($planText) ?></span></p></div>
<?php endif; ?>
<?php if ($planRows): ?>
      <ul class="cp-plan-list" role="list">
<?php foreach ($planRows as $row): ?>
        <li><button type="button" class="cp-plan" data-open-dialog="bp-plan-<?= e($row['id']) ?>" aria-haspopup="dialog"<?= $row['on'] ? '' : ' data-off' ?>><?= icon('calendar', 'icon-16') ?><span class="cp-plan-text"><span class="cp-plan-label"><?= e($row['label']) ?><?= $row['active'] ? '<span class="pill">plant gerade</span>' : '' ?></span><span class="caption muted cp-plan-detail"><?= e($row['detail']) ?></span></span><?= icon('chevron-right', 'icon-16') ?></button></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
      <button type="button" class="text-action" data-open-dialog="bp-plan-new" aria-haspopup="dialog"><?= icon('plus', 'icon-16') ?><span>Ladeplan hinzufügen</span></button>
<?php if ($batteryPlans && $gridBlocker !== ''): ?>
      <?= ui_notice('info', e('Noch lädt EMS nicht: ' . $gridBlocker)) ?>
<?php endif; ?>
<?php if (!empty($gridState['error'])): ?>
      <?= ui_notice('triangle-alert', e((string) $gridState['error']), 'warn') ?>
<?php endif; ?>
    </div>
<?php if (!empty($gridState['log'])): ?>
    <ul class="plain-list stack-tight body-sm" role="list" aria-label="Zuletzt aus dem Netz geladen">
<?php foreach (array_slice((array) $gridState['log'], 0, 4) as $row): ?>
      <li><span class="num muted"><?= e(date('d.m. H:i', (int) $row['t'])) ?></span> <?= e((string) $row['text']) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
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

// Ebene 2: Ladepläne des Speichers, je Plan ein Sheet und eines für einen neuen
foreach (array_merge($batteryPlans, [null]) as $batteryPlan) {
    $planId = $batteryPlan === null ? 'new' : (string) $batteryPlan['id'];
    ob_start();
    view('partials/battery-plan-form', ['plan' => $batteryPlan, 'snap' => $snap, 'back' => '/speicher', 'px' => 'bp-' . $planId]);
    echo ui_dialog('bp-plan-' . $planId, $batteryPlan === null ? 'Neuer Ladeplan des Speichers' : 'Ladeplan des Speichers', ob_get_clean());
}
view('partials/overview', ['overview' => $overview]);
