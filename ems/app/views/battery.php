<?php
declare(strict_types=1);
$v = $snap['values'];
$strategy = $snap['cfg']['battery_strategy'];
page_head('Batterie', 'Ladestand, nutzbare Energie und die Schwellen für das Auto.');
?>
<div class="grid gap-4 lg:grid-cols-[220px_1fr]">
  <section class="card flex flex-col items-center justify-center p-6 text-center">
    <p class="text-xs font-medium text-muted-foreground">Ladestand</p>
    <p class="mt-1 text-5xl font-semibold tabular-nums text-battery" data-live="soc"><?= e(pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null)) ?></p>
    <p class="mt-3 text-sm" data-live="activity"><?= e($snap['activity']) ?></p>
    <p class="mt-1 text-sm tabular-nums text-muted-foreground" data-live="capacity"><?= e(kwh($v['battery_capacity_kwh'])) ?> nutzbar</p>
  </section>
  <section class="card p-4 sm:p-5">
    <div class="mb-3 flex flex-wrap gap-2" data-ranges data-chart-base="<?= e(url('/api/series?chart=battery&range=')) ?>">
      <?php foreach ([12 => '12 h', 24 => '24 h', 72 => '3 Tage', 120 => '5 Tage'] as $hours => $label): ?>
        <button type="button" class="btn-ghost !px-2.5 !py-1" data-range="<?= $hours ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <?php chart_box('/api/series?chart=battery&range=24', 'h-80'); ?>
  </section>
</div>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="card mt-4 space-y-6 p-5">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="battery">
  <input type="hidden" name="back" value="/batterie">
  <h2 class="font-medium">Nutzung</h2>
  <label class="block">
    <span class="flex justify-between text-sm"><span class="inline-flex items-center gap-1">Speicher-Vorrang <?php tip('Bis zu diesem Ladestand geht Sonnenstrom zuerst in den Speicher. Die laufende Ladeleistung heißt auf der Ladeseite Speicher-Vorrang und wird vom Überschuss fürs Auto abgezogen. Darüber bleibt der Überschuss für die Wallbox.'); ?></span><span class="tabular-nums" data-range-out><?= e(num((float) $strategy['priority_soc'], 0)) ?> %</span></span>
    <input class="mt-2 w-full accent-primary" type="range" name="priority_soc" min="0" max="100" step="1" value="<?= e((string) $strategy['priority_soc']) ?>">
    <span class="mt-1 block text-xs text-muted-foreground">Bis zu diesem Ladestand geht PV zuerst in den Speicher, danach an das Auto.</span>
  </label>
  <label class="block">
    <span class="flex justify-between text-sm"><span class="inline-flex items-center gap-1">Mindestreserve Haus <?php tip('Markierung im Verlauf: so viel Ladestand soll fürs Haus bleiben. Bei 100 % ist der Speicher fürs Auto tabu. In die Sollleistung der Wallbox geht dieser Regler nicht ein, dort zählt die Regelreserve in Watt.'); ?></span><span class="tabular-nums" data-range-out><?= e(num((float) $strategy['reserve_soc'], 0)) ?> %</span></span>
    <input class="mt-2 w-full accent-primary" type="range" name="reserve_soc" min="0" max="100" step="1" value="<?= e((string) $strategy['reserve_soc']) ?>">
    <span class="mt-1 block text-xs text-muted-foreground"><?= (float) $strategy['reserve_soc'] >= 100 ? 'Der Speicher bleibt fürs Haus markiert und wird im Vorschlag nicht fürs Auto eingeplant.' : 'Die Linie markiert ' . e(num((float) $strategy['reserve_soc'], 0)) . ' % als Hausreserve.' ?></span>
  </label>
  <button class="btn-primary" type="submit">Schwellen speichern</button>
</form>
