<?php
declare(strict_types=1);
page_head('Übersicht', 'Wo die Leistung jetzt herkommt und wohin sie fließt.');
$v = $snap['values'];
$b = $snap['balance'];
$s = $snap['setpoint'];
$f = $snap['forecast'];
$missing = Actions::missing($snap['cfg']['mapping']);
if (!$snap['connected']) {
    echo '<div class="card mb-4 p-4 text-sm">' . e($snap['error'] ?? 'Keine Verbindung.') . ' <a class="font-medium text-primary" href="' . e(url('/einrichten/verbindung')) . '">Verbindung einrichten</a></div>';
} elseif ($missing) {
    echo '<div class="card mb-4 p-4 text-sm">Noch nicht zugeordnet: ' . e(implode(', ', $missing)) . '. <a class="font-medium text-primary" href="' . e(url('/einrichten')) . '">Assistent öffnen</a></div>';
}
view('partials/flow', ['snap' => $snap]);
?>
<div class="mt-4 grid gap-4 lg:grid-cols-2">
  <section class="card p-5">
    <div class="flex items-baseline justify-between"><h2 class="font-medium">Eingang</h2><p class="text-xl font-semibold tabular-nums" data-live="in"><?= e(kw($b['in_kw'])) ?></p></div>
    <dl class="mt-4 space-y-3 text-sm">
      <div class="flex justify-between gap-3"><dt class="text-pv">PV</dt><dd class="tabular-nums" data-live="pv"><?= e(kw($v['pv_kw'])) ?></dd></div>
      <div class="flex justify-between gap-3 text-muted-foreground"><dt>Rest heute</dt><dd class="tabular-nums" data-live="remaining"><?= e(kwh($f['remaining_kwh'] ?? null)) ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-battery">Batterie entladen</dt><dd class="tabular-nums" data-live="discharge"><?= e(kw($v['battery_discharge_kw'])) ?></dd></div>
      <div class="flex justify-between gap-3 text-muted-foreground"><dt>Ladestand</dt><dd class="tabular-nums" data-live="soc"><?= e(pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null)) ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-import">Netzbezug</dt><dd class="tabular-nums" data-live="import"><?= e(kw($v['grid_import_kw'])) ?></dd></div>
      <div class="text-xs text-muted-foreground"><?= e(num((float) $snap['cfg']['tariffs']['import_ct'], 1)) ?> ct/kWh</div>
    </dl>
  </section>
  <section class="card p-5">
    <div class="flex items-baseline justify-between"><h2 class="font-medium">Ausgang</h2><p class="text-xl font-semibold tabular-nums" data-live="out"><?= e(kw($b['out_kw'])) ?></p></div>
    <dl class="mt-4 space-y-3 text-sm">
      <div class="flex justify-between gap-3"><dt class="text-house">Haushalt</dt><dd class="tabular-nums" data-live="house"><?= e(kw($b['house_base_kw'])) ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-wallbox">Wallbox</dt><dd class="tabular-nums" data-live="wallbox"><?= e(kw($v['wallbox_kw'])) ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-battery">Batterie laden</dt><dd class="tabular-nums" data-live="charge"><?= e(kw($v['battery_charge_kw'])) ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-export">Einspeisung</dt><dd class="tabular-nums" data-live="export"><?= e(kw($v['grid_export_kw'])) ?></dd></div>
      <div class="text-xs text-muted-foreground"><?= e(num((float) $snap['cfg']['tariffs']['export_ct'], 1)) ?> ct/kWh</div>
    </dl>
  </section>
</div>
<a class="card mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 p-4" href="<?= e(url('/laden')) ?>">
  <span class="text-sm font-medium"><?= e(Energy::modeLabel($s['mode'])) ?></span>
  <span class="text-sm tabular-nums" data-live="wallbox"><?= e(kw($v['wallbox_kw'])) ?></span>
  <span class="text-sm text-muted-foreground" data-live="car"><?= e($snap['car_label']) ?></span>
  <span class="text-sm text-muted-foreground" data-live="proposal"><?= e($snap['setpoint']['latched_amps'] === 0 ? 'Vorschlag: aus' : 'Vorschlag: ' . $snap['setpoint']['latched_amps'] . ' A') ?></span>
  <span class="ml-auto text-sm font-medium text-primary">Lademanagement</span>
</a>
<section class="card mt-4 p-5">
  <div class="flex items-baseline justify-between gap-3">
    <h2 class="text-sm font-medium">Heute</h2>
    <p class="text-sm tabular-nums text-muted-foreground"><?= e(kwh($yield)) ?> gemessen<?php if ($f): ?> · <?= e(kwh($f['today_kwh'])) ?> Prognose (<?= e($f['method']) ?>)<?php endif; ?></p>
  </div>
  <?php $ratio = ($f && $yield !== null && $f['today_kwh'] > 0) ? min(100, $yield / $f['today_kwh'] * 100) : 0; ?>
  <div class="mt-3 h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-pv" style="width: <?= e((string) round($ratio, 1)) ?>%"></div></div>
</section>
<?php if ($snap['warnings']): ?>
  <ul class="mt-4 space-y-1 text-xs text-muted-foreground">
    <?php foreach (array_slice($snap['warnings'], 0, 4) as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>
