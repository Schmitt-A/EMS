<?php
declare(strict_types=1);
$v = $snap['values'];
$b = $snap['balance'];
$s = $snap['setpoint'];
$c = $snap['cfg']['charge'];
$tariffs = $snap['cfg']['tariffs'];
page_head('Lademanagement', 'Der Vorschlag wird nur angezeigt. Die Wallbox wird von hier nicht gestellt.');
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="card p-4">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="charge">
  <input type="hidden" name="back" value="/laden">
  <?php foreach (['solar_share','reserve_w','min_a','max_a','switch_s','on_delay_s','off_delay_s','phase_mode'] as $keep): ?>
    <input type="hidden" name="<?= e($keep) ?>" value="<?= e((string) $c[$keep]) ?>">
  <?php endforeach; ?>
  <div class="flex flex-wrap gap-2">
    <?php foreach (mode_options() as $value => $label): ?>
      <button class="<?= $c['mode'] === $value ? 'btn-primary' : 'btn-ghost' ?>" name="mode" value="<?= e($value) ?>" type="submit"><?= e($label) ?></button>
    <?php endforeach; ?>
  </div>
  <p class="mt-3 text-sm text-muted-foreground"><?= e(Energy::modeText($c['mode'])) ?></p>
</form>
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
  <?php stat_card('Wallbox', kw($v['wallbox_kw']), 'wallbox'); ?>
  <?php stat_card('Sollleistung', kw($s['latched_kw']), 'target'); ?>
  <?php stat_card('Geladen', kwh(isset($session['energy_kwh']) ? (float) $session['energy_kwh'] : null), 'session_energy'); ?>
  <?php stat_card('Dauer', isset($session['duration_s']) ? duration_label((int) $session['duration_s']) : '—', 'session_duration'); ?>
</div>
<p class="mt-3 text-sm text-muted-foreground">Wallbox meldet <span data-live="car"><?= e($snap['car_label']) ?></span> · <span data-live="amps"><?= e(amps(isset($v['wallbox_amps']) ? (float) $v['wallbox_amps'] : null)) ?></span> · <span data-live="phase"><?= e($snap['phase_label']) ?></span></p>
<p class="mt-1 text-sm font-medium" data-live="proposal"><?= e((int) $s['latched_amps'] === 0 ? 'Vorschlag: aus' : 'Vorschlag: ' . (int) $s['latched_amps'] . ' A, ' . (int) $s['latched_phases'] . '-phasig') ?><?= (int) $s['wait_s'] > 0 ? ' in ' . (int) $s['wait_s'] . ' s' : '' ?></p>
<?php if ($s['reason']): ?><p class="mt-1 text-xs text-muted-foreground"><?= e($s['reason']) ?></p><?php endif; ?>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
  <section class="card p-5 text-sm">
    <h2 class="font-medium">Vom Dach</h2>
    <p class="mt-1 text-xs text-muted-foreground">Überschuss = PV − Haus − Speicher-Vorrang</p>
    <dl class="mt-3 space-y-2">
      <div class="flex justify-between"><dt>PV</dt><dd class="tabular-nums" data-live="pv"><?= e(kw($v['pv_kw'])) ?></dd></div>
      <div class="flex justify-between"><dt>Haus ohne Wallbox</dt><dd class="tabular-nums" data-live="house"><?= e(kw($b['house_base_kw'])) ?></dd></div>
      <div class="flex justify-between"><dt class="inline-flex items-center gap-1">Speicher-Vorrang <?php tip('Das ist die aktuelle Ladeleistung, solange der Ladestand unter der Vorrang-Schwelle liegt. Sonst null. Die Schwelle stellst du bei der Batterie ein, Startwert 80 %. Sie nimmt dem Auto den Sonnenstrom, den der Speicher gerade schluckt.'); ?></dt><dd class="tabular-nums" data-live="storage"><?= e(kw($b['storage_priority_kw'])) ?></dd></div>
      <div class="flex justify-between font-medium"><dt>Überschuss</dt><dd class="tabular-nums" data-live="surplus"><?= e(kw($b['surplus_kw'])) ?></dd></div>
    </dl>
  </section>
  <section class="card p-5 text-sm">
    <h2 class="font-medium">Über den Zähler</h2>
    <p class="mt-1 text-xs text-muted-foreground">Soll = Wallbox − Netz − Reserve. Bezug zählt positiv.</p>
    <dl class="mt-3 space-y-2">
      <div class="flex justify-between"><dt>Wallbox</dt><dd class="tabular-nums" data-live="wallbox"><?= e(kw($v['wallbox_kw'])) ?></dd></div>
      <div class="flex justify-between"><dt>Netz</dt><dd class="tabular-nums"><?= e(kw($s['grid_signed_kw'])) ?></dd></div>
      <div class="flex justify-between"><dt class="inline-flex items-center gap-1">Reserve <?php tip('Puffer in Watt am Hauszähler, nicht der Ladestand des Speichers. Die Sollleistung ist Wallbox minus Netz minus diese Reserve. Der Puffer hält das Haus bei einer Wolke vom Netzbezug fern. Startwert 200 W, einstellbar am Regler Regelreserve.'); ?></dt><dd class="tabular-nums"><?= e(num(((float) $c['reserve_w']) / 1000, 2)) ?> kW</dd></div>
      <div class="flex justify-between font-medium"><dt>Soll</dt><dd class="tabular-nums" data-live="psoll"><?= e(kw($s['p_soll_kw'])) ?></dd></div>
      <div class="flex justify-between text-muted-foreground"><dt>Abweichung</dt><dd class="tabular-nums" data-live="delta"><?= e(kw($s['delta_kw'])) ?></dd></div>
    </dl>
  </section>
</div>

<form method="post" action="<?= e(url('/einstellungen')) ?>" class="card mt-4 grid gap-5 p-5 sm:grid-cols-2">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="charge">
  <input type="hidden" name="back" value="/laden">
  <input type="hidden" name="mode" value="<?= e($c['mode']) ?>">
  <?php
    $ranges = [
        'solar_share' => ['Mindest-Sonnenanteil', '%', 0, 100, 1, ''],
        'reserve_w' => ['Regelreserve', 'W', 0, 2000, 10, 'Watt, die am Zähler als Abstand zur Nulllinie bleiben. Das ist der Puffer der Sollleistung und etwas anderes als der Speicher-Vorrang.'],
        'min_a' => ['Minimaler Strom', 'A', 6, 16, 1, ''],
        'max_a' => ['Maximaler Strom', 'A', 6, 32, 1, ''],
        'switch_s' => ['Schütz-Schutzzeit', 's', 60, 600, 10, ''],
        'on_delay_s' => ['Einschaltverzögerung', 's', 60, 600, 10, ''],
        'off_delay_s' => ['Ausschaltverzögerung', 's', 60, 600, 10, ''],
    ];
    foreach ($ranges as $name => [$label, $unit, $min, $max, $step, $hint]): ?>
    <label class="block">
      <span class="flex justify-between text-sm"><span class="inline-flex items-center gap-1"><?= e($label) ?><?php if ($hint !== ''): ?> <?php tip($hint); ?><?php endif; ?></span><span class="tabular-nums" data-range-out><?= e((string) $c[$name]) ?> <?= e($unit) ?></span></span>
      <input class="mt-2 w-full accent-primary" type="range" name="<?= e($name) ?>" min="<?= $min ?>" max="<?= $max ?>" step="<?= $step ?>" value="<?= e((string) $c[$name]) ?>">
    </label>
  <?php endforeach; ?>
  <label class="block sm:col-span-2">
    <span class="mb-1 block text-sm font-medium">Phasenwunsch</span>
    <select class="field" name="phase_mode">
      <?php foreach (['auto' => 'Automatisch', '1p' => '1-phasig', '3p' => '3-phasig'] as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $c['phase_mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="sm:col-span-2"><button class="btn-primary" type="submit">Parameter speichern</button></div>
</form>
<details class="card mt-4 p-5 text-sm">
  <summary class="cursor-pointer font-medium">Phasen und 1-Ampere-Schritte</summary>
  <p class="mt-3 text-muted-foreground">P = 230 V × Ampere × Phasen. Zwischen 3,68 und 4,14 kW bleibt die laufende Phase, weil 1-phasig dort endet und 3-phasig dort erst beginnt.</p>
  <table class="mt-3 w-full text-left">
    <thead class="text-xs text-muted-foreground"><tr><th class="py-1 font-medium">Phase</th><th>6 A</th><th>je 1 A</th><th>16 A</th></tr></thead>
    <tbody class="tabular-nums">
      <tr><td class="py-1">1-phasig</td><td>1,38 kW</td><td>+0,23 kW</td><td>3,68 kW</td></tr>
      <tr><td class="py-1">3-phasig</td><td>4,14 kW</td><td>+0,69 kW</td><td>11,04 kW</td></tr>
    </tbody>
  </table>
</details>
<p class="mt-4 text-xs text-muted-foreground">Schreiben an Ampere, Phase und Modus kommt später, und nur mit dem Schalter „Regelung aktiv“.</p>
