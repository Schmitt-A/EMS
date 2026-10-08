<?php
declare(strict_types=1);
$v = $snap['values'];
$strategy = $snap['cfg']['battery_strategy'];
$flow = match ($snap['activity'] ?? '') {
    'Laden' => 'laden',
    'Entladen' => 'entladen',
    default => 'ruhe',
};
$socFill = isset($v['battery_soc']) ? max(0, min(100, (float) $v['battery_soc'])) : 0;
$buffer = (float) ($strategy['car_buffer_soc'] ?? 100);
page_head('Batterie', 'Ladestand, Kapazität und was der Speicher bis Mitternacht noch schafft.');
?>
<div class="grid gap-4 lg:grid-cols-[minmax(17rem,22rem)_1fr]">
  <section class="card flex items-center gap-4 p-5" data-battery data-flow="<?= e($flow) ?>" style="--soc: <?= e(num($socFill, 0)) ?>%">
    <div class="battery" aria-hidden="true">
      <div class="battery-nub"></div>
      <div class="battery-body">
        <div class="battery-fill" data-soc-fill></div>
      </div>
    </div>
    <div class="min-w-0">
      <p class="text-xs font-medium text-muted-foreground">Ladestand</p>
      <p class="mt-1 text-5xl font-semibold tabular-nums text-battery" data-live="soc"><?= e(pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null)) ?></p>
      <p class="mt-3 text-sm" data-live="activity"><?= e($snap['activity']) ?></p>
      <p class="mt-1 text-sm tabular-nums" data-live="capacity_line"><?= e($live['capacity_line'] ?? '') ?></p>
    </div>
  </section>
  <section class="card p-4 sm:p-5">
    <div class="mb-3 flex flex-wrap gap-2" data-battery-tools>
      <button type="button" class="chip" data-window="24" aria-pressed="true">24 h</button>
      <button type="button" class="chip" data-window="3" aria-pressed="false">3 Tage</button>
      <button type="button" class="chip" data-window="7" aria-pressed="false">7 Tage</button>
      <button type="button" class="chip" data-window="30" aria-pressed="false">1 Monat</button>
      <button type="button" class="chip" data-unit="soc" aria-pressed="true">%</button>
      <button type="button" class="chip" data-unit="cap" aria-pressed="false">Kapazität</button>
    </div>
    <?php chart_box('/api/series?chart=battery', 'h-80', 'pan'); ?>
    <p class="mt-2 text-xs text-muted-foreground">Ein Wert auf der Achse. Am Tag stehen Minimum und Maximum. Ziehen verschiebt den Ausschnitt.</p>
  </section>
</div>
<section class="card mt-4 p-4 sm:p-5">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h2 class="font-medium">Was der Speicher noch schafft</h2>
      <p class="mt-1 max-w-2xl text-sm text-muted-foreground">Die Zeiten rechnen mit dem mittleren Hausverbrauch der letzten 30 Tage. Die Wallbox lädt in dieser Rechnung kein Auto. Der Sonnenstrom füllt zuerst den Hausspeicher, bis zum Speicher-Vorrang. Darüber bleibt der Überschuss fürs Auto. Ab dem Puffer darf auch gespeicherte Energie das Auto stützen.</p>
    </div>
  </div>
  <div class="plan-board mt-4">
    <article class="plan-cell">
      <p class="plan-kicker">Voll <span class="tabular-nums" data-live="plan_full_kwh"><?= e($live['plan_full_kwh'] ?? '') ?></span></p>
      <p class="mt-2 text-sm" data-live="battery_full"><?= e($live['battery_full'] ?? '') ?></p>
    </article>
    <article class="plan-cell">
      <p class="plan-kicker">Vorrang <span class="tabular-nums" data-live="plan_priority_kwh"><?= e($live['plan_priority_kwh'] ?? '') ?></span></p>
      <p class="mt-2 text-sm" data-live="battery_priority" data-live-hide="battery_priority" <?= empty($live['battery_priority']) ? 'hidden' : '' ?>><?= e($live['battery_priority'] ?? '') ?></p>
    </article>
    <article class="plan-cell plan-cell-car">
      <p class="plan-kicker">Auto <span class="tabular-nums" data-live="plan_buffer_kwh"><?= e($live['plan_buffer_kwh'] ?? '') ?></span></p>
      <p class="mt-2 text-sm" data-live="battery_buffer"><?= e($live['battery_buffer'] ?? '') ?></p>
    </article>
    <article class="plan-cell plan-cell-house">
      <p class="plan-kicker">Haus, 30 Tage</p>
      <p class="plan-figure" data-live="house_mean"><?= e($live['house_mean'] ?? '—') ?></p>
      <p class="mt-1 text-xs text-muted-foreground">Mittel ohne Wallbox. Damit werden 100 % und der Speicher-Vorrang gerechnet.</p>
      <p class="mt-2 text-sm" data-live="battery_surplus"><?= e($live['battery_surplus'] ?? '') ?></p>
    </article>
  </div>
  <div class="plan-now mt-4">
    <p class="plan-kicker">Jetzt <span class="tabular-nums" data-live="plan_now"><?= e($live['plan_now'] ?? '') ?></span></p>
    <p class="mt-1 text-sm" data-live="car_outlook"><?= e($live['car_outlook'] ?? '') ?></p>
    <p class="mt-1 text-sm" data-live="car_state"><?= e($live['car_state'] ?? '') ?></p>
  </div>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="mt-5 grid gap-5 lg:grid-cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="battery">
    <input type="hidden" name="back" value="/batterie">
    <label class="plan-slider">
      <span class="flex justify-between gap-3 text-sm"><span class="inline-flex items-center gap-1">Speicher-Vorrang <?php tip('Bis zu diesem Ladestand geht Sonnenstrom zuerst in den Hausspeicher. Die laufende Ladeleistung heißt auf der Ladeseite Speicher-Vorrang und wird vom Überschuss fürs Auto abgezogen.'); ?></span><span class="tabular-nums" data-range-out><?= e(num((float) $strategy['priority_soc'], 0)) ?> %</span></span>
      <input class="mt-2 w-full" type="range" name="priority_soc" min="0" max="100" step="1" value="<?= e((string) $strategy['priority_soc']) ?>">
      <span class="mt-1 block text-xs text-muted-foreground">Sonne füllt den Hausspeicher zuerst, danach bleibt der Überschuss für die Wallbox.</span>
    </label>
    <label class="plan-slider plan-slider-car">
      <span class="flex justify-between gap-3 text-sm"><span class="inline-flex items-center gap-1">Auto aus dem Speicher <?php tip('Oberhalb dieses Ladestands darf gespeicherte Energie das Auto stützen, wie der Puffer bei evcc. Bei 100 % bleibt der Speicher fürs Haus. Die Sollleistung der Wallbox bleibt bei diesem Regler unverändert.'); ?></span><span class="tabular-nums" data-range-out><?= e(num($buffer, 0)) ?> %</span></span>
      <input class="mt-2 w-full" type="range" name="car_buffer_soc" min="0" max="100" step="1" value="<?= e((string) $buffer) ?>">
      <span class="mt-1 block text-xs text-muted-foreground"><?= $buffer >= 100 ? 'Der Hausspeicher bleibt fürs Haus.' : 'Ab ' . e(num($buffer, 0)) . ' % kann gespeicherte Energie das Auto stützen.' ?></span>
    </label>
    <label class="plan-slider plan-slider-house">
      <span class="flex justify-between gap-3 text-sm"><span class="inline-flex items-center gap-1">Mindestreserve Haus <?php tip('So viel Ladestand bleibt fürs Haus vorgesehen. Bei 100 % ist der Speicher fürs Auto tabu. In die Sollleistung der Wallbox geht die Regelreserve in Watt ein.'); ?></span><span class="tabular-nums" data-range-out><?= e(num((float) $strategy['reserve_soc'], 0)) ?> %</span></span>
      <input class="mt-2 w-full" type="range" name="reserve_soc" min="0" max="100" step="1" value="<?= e((string) $strategy['reserve_soc']) ?>">
      <span class="mt-1 block text-xs text-muted-foreground"><?= (float) $strategy['reserve_soc'] >= 100 ? 'Der Speicher bleibt fürs Haus markiert.' : 'Die Hausreserve liegt bei ' . e(num((float) $strategy['reserve_soc'], 0)) . ' %.' ?></span>
    </label>
    <div class="lg:col-span-3">
      <button class="btn-primary" type="submit">Schwellen speichern</button>
    </div>
  </form>
</section>
