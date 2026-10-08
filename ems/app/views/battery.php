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
$zones = zone_thresholds(
    (float) ($strategy['priority_soc'] ?? 80),
    (float) ($strategy['car_buffer_soc'] ?? 100),
    (float) ($strategy['car_auto_soc'] ?? 100),
);
$priority = (int) $zones['priority_soc'];
$buffer = (int) $zones['car_buffer_soc'];
$auto = (int) $zones['car_auto_soc'];
$carSoc = isset($v['car_soc']) ? max(0, min(100, (float) $v['car_soc'])) : null;
$carKnown = $carSoc !== null;
$carFill = $carKnown ? (int) round($carSoc) : 0;
$carFlow = !empty($snap['car_charging']) ? 'laden' : 'ruhe';

$zoneBands = static function (): void {
    echo '<span class="zone-band zone-boost"><span class="zone-glyph">' . icon('zap', 'h-4 w-4') . '</span></span>';
    echo '<span class="zone-band zone-vehicle"></span>';
    echo '<span class="zone-band zone-house"><span class="zone-glyph">' . icon('house', 'h-4 w-4') . '</span></span>';
    echo '<span class="zone-sheen" data-soc-fill></span>';
    echo '<span class="zone-auto" title="Automatischer Start"></span>';
    echo '<span class="zone-level"><span class="zone-level-line"></span><span class="zone-level-car">' . icon('car', 'h-3.5 w-3.5') . '</span></span>';
};
page_head('Batterie', 'Zum Beispiel sichert der Hausspeicher bis 50 % die Grundversorgung, von 50 % bis 80 % hat das Fahrzeug Vorrang, und über 80 % kann der Speicher mitladen. Ab 90 % startet das automatisch. Die Grenzen stellst du selbst ein.');
?>
<div class="batt-page min-w-0" data-zone-root style="--house: <?= $priority ?>%; --support: <?= $buffer ?>%; --auto: <?= $auto ?>%">
  <div class="batt-hero">
    <section class="card batt-stage">
      <button type="button" class="zone-open" data-battery data-flow="<?= e($flow) ?>" data-open-dialog="battery-zones" style="--soc: <?= e(num($socFill, 0)) ?>%" aria-haspopup="dialog" aria-controls="battery-zones" aria-label="Zonen der Hausbatterie anpassen">
        <span class="zone-battery">
          <span class="zone-nub"></span>
          <span class="zone-body">
            <?php $zoneBands(); ?>
          </span>
        </span>
      </button>
      <div class="batt-copy min-w-0">
        <p class="text-xs font-medium text-muted-foreground">Ladestand</p>
        <p class="mt-1 text-4xl font-semibold tabular-nums text-battery sm:text-5xl" data-live="soc"><?= e(pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null)) ?></p>
        <p class="mt-2 text-sm" data-live="activity"><?= e($snap['activity']) ?></p>
        <p class="mt-1 text-sm tabular-nums" data-live="capacity_line"><?= e($live['capacity_line'] ?? '') ?></p>
        <ul class="zone-legend">
          <li><i class="swatch swatch-boost"></i> Stützung ab <span data-zone-read="buffer"><?= $buffer ?></span> %</li>
          <li><i class="swatch swatch-vehicle"></i> Fahrzeug ab <span data-zone-read="priority"><?= $priority ?></span> %</li>
          <li><i class="swatch swatch-house"></i> Haus bis <span data-zone-read="priority"><?= $priority ?></span> %</li>
        </ul>
        <p class="mt-3 text-sm" data-live="battery_full"><?= e($live['battery_full'] ?? '') ?></p>
        <p class="mt-1 text-sm" data-live="battery_priority" data-live-hide="battery_priority" <?= empty($live['battery_priority']) ? 'hidden' : '' ?>><?= e($live['battery_priority'] ?? '') ?></p>
        <p class="mt-1 text-sm" data-live="battery_surplus"><?= e($live['battery_surplus'] ?? '') ?></p>
        <p class="mt-1 text-xs text-muted-foreground">Haus, 30 Tage <span class="tabular-nums text-foreground" data-live="house_mean"><?= e($live['house_mean'] ?? '—') ?></span> ohne Wallbox. <span data-live="car_outlook"><?= e($live['car_outlook'] ?? '') ?></span></p>
        <button type="button" class="btn-ghost mt-3 min-h-11" data-open-dialog="battery-zones">Zonen ziehen</button>
      </div>
    </section>
    <section class="card car-stage" data-car-battery data-car-known="<?= $carKnown ? '1' : '0' ?>" data-flow="<?= e($carFlow) ?>" style="--soc: <?= $carFill ?>%">
      <div class="car-pack" aria-hidden="true">
        <div class="car-pack-body"><div class="car-pack-fill"></div></div>
        <div class="car-pack-nub"></div>
      </div>
      <div class="min-w-0">
        <p class="text-xs font-medium text-muted-foreground">Elektroauto</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums" data-live="car_soc_label"><?= e($live['car_soc_label'] ?? '—') ?></p>
        <p class="mt-1 text-sm tabular-nums" data-live="car_capacity_line"><?= e($live['car_capacity_line'] ?? '') ?></p>
        <p class="mt-2 text-sm" data-live="car_state"><?= e($live['car_state'] ?? '') ?></p>
        <p class="mt-1 text-sm text-muted-foreground">Wallbox <span data-live="car"><?= e($snap['car_label'] ?? '') ?></span></p>
      </div>
    </section>
  </div>

  <section class="card mt-4 min-w-0 p-4 sm:p-5">
    <div class="mb-3 flex flex-wrap gap-2" data-battery-tools>
      <button type="button" class="chip" data-window="24" aria-pressed="true">24 h</button>
      <button type="button" class="chip" data-window="3" aria-pressed="false">3 Tage</button>
      <button type="button" class="chip" data-window="7" aria-pressed="false">7 Tage</button>
      <button type="button" class="chip" data-window="30" aria-pressed="false">1 Monat</button>
      <button type="button" class="chip" data-unit="soc" aria-pressed="true">%</button>
      <button type="button" class="chip" data-unit="cap" aria-pressed="false">Kapazität</button>
    </div>
    <?php chart_box('/api/series?chart=battery', 'h-64 sm:h-80', 'pan'); ?>
    <p class="mt-2 text-xs text-muted-foreground">Ein Wert auf der Achse. Am Tag stehen Minimum und Maximum. Ziehen verschiebt den Ausschnitt, senkrecht läuft die Seite.</p>
  </section>

  <form id="battery-zones-form" method="post" action="<?= e(url('/einstellungen')) ?>" class="card mt-4 p-4 sm:p-5">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="battery">
    <input type="hidden" name="back" value="/batterie">
    <div class="zone-policies">
      <article class="zone-policy zone-policy-boost">
        <h2 class="text-sm font-medium">Batteriegestütztes Fahrzeugladen</h2>
        <p class="mt-2 text-sm text-muted-foreground">Das Fahrzeug wird unter Verwendung der Hausbatterie geladen, sobald diese über <span data-zone-read="buffer"><?= $buffer ?></span> % geladen ist. Automatischer Start ab einem Ladestand von über <span data-zone-read="auto"><?= $auto ?></span> %.</p>
        <p class="mt-1 text-xs text-muted-foreground">Die Sollleistung der Wallbox bleibt unverändert. Die Marken gelten für die Anzeige und die Zeiten.</p>
        <label class="mt-3 block text-sm">Stützung ab <span class="tabular-nums" data-range-out><?= $buffer ?> %</span>
          <input class="mt-1 w-full" type="range" name="car_buffer_soc" data-zone="buffer" min="0" max="100" step="5" value="<?= $buffer ?>">
        </label>
        <label class="mt-2 block text-sm">Automatisch ab <span class="tabular-nums" data-range-out><?= $auto ?> %</span>
          <input class="mt-1 w-full" type="range" name="car_auto_soc" data-zone="auto" min="0" max="100" step="5" value="<?= $auto ?>">
        </label>
        <p class="mt-2 text-sm" data-live="battery_buffer"><?= e($live['battery_buffer'] ?? '') ?></p>
      </article>
      <article class="zone-policy zone-policy-car">
        <h2 class="text-sm font-medium">Fahrzeugladen priorisieren</h2>
        <p class="mt-2 text-sm text-muted-foreground">Das Laden des Fahrzeugs hat Vorrang, sobald die Hausbatterie über <span data-zone-read="priority"><?= $priority ?></span> % geladen ist.</p>
        <label class="mt-3 block text-sm">Fahrzeug ab <span class="tabular-nums" data-range-out><?= $priority ?> %</span>
          <input class="mt-1 w-full" type="range" name="priority_soc" data-zone="priority" min="0" max="100" step="5" value="<?= $priority ?>">
        </label>
        <p class="mt-2 text-xs text-muted-foreground">Haus und Fahrzeug teilen sich diese Grenze.</p>
      </article>
      <article class="zone-policy zone-policy-house">
        <h2 class="text-sm font-medium">Hausbatterieladen priorisieren</h2>
        <p class="mt-2 text-sm text-muted-foreground">Das Laden der Hausbatterie hat Vorrang, bis sie einen Ladestand von <span data-zone-read="priority"><?= $priority ?></span> % erreicht hat. Darunter fließt der Strom zuerst in den Hausspeicher und sichert die Grundversorgung.</p>
        <label class="mt-3 block text-sm">Haus bis <span class="tabular-nums" data-range-out><?= $priority ?> %</span>
          <input class="mt-1 w-full" type="range" data-zone-mirror="priority" min="0" max="100" step="5" value="<?= $priority ?>" aria-label="Hausbatterie priorisieren bis">
        </label>
      </article>
    </div>
    <button class="btn-primary mt-4" type="submit">Zonen speichern</button>
  </form>

  <dialog id="battery-zones" class="sheet">
    <div class="sheet-head flex items-center gap-2 px-4 pb-3 pt-4">
      <h2 class="mr-auto text-sm font-medium">Zonen ziehen</h2>
      <button type="button" class="btn-ghost min-h-11" data-close-dialog>Schließen</button>
    </div>
    <div class="space-y-4 px-4 py-4">
      <p class="text-sm text-muted-foreground">Ziehe die Marken in Schritten von 5 %. Haus und Fahrzeug teilen sich die untere Grenze. Darüber stützt der Speicher das Fahrzeug, ab der oberen Marke ist der automatische Start gesetzt.</p>
      <div class="zone-slot" data-zone-editor>
        <div class="zone-body zone-body-edit" data-zone-body data-battery data-flow="<?= e($flow) ?>" style="--soc: <?= e(num($socFill, 0)) ?>%">
          <?php $zoneBands(); ?>
        </div>
        <button type="button" class="zone-handle" data-handle="priority" style="bottom: var(--house)" aria-label="Hausgrenze" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $priority ?>">Haus <span data-handle-value="priority"><?= $priority ?></span> %</button>
        <button type="button" class="zone-handle zone-handle-buffer" data-handle="buffer" style="bottom: var(--support)" aria-label="Batteriegestütztes Laden" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $buffer ?>">Stützt <span data-handle-value="buffer"><?= $buffer ?></span> %</button>
        <button type="button" class="zone-handle zone-handle-auto" data-handle="auto" style="bottom: var(--auto)" aria-label="Automatischer Start" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $auto ?>">Start <span data-handle-value="auto"><?= $auto ?></span> %</button>
      </div>
      <button class="btn-primary" type="submit" form="battery-zones-form">Zonen speichern</button>
    </div>
  </dialog>
</div>
