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
$showHouse = $priority > 0 && $priority < 100;
$showBuffer = $buffer > $priority;
$showAuto = $auto > $buffer;
$carSoc = isset($v['car_soc']) ? max(0, min(100, (float) $v['car_soc'])) : null;
$carKnown = $carSoc !== null;
$carFill = $carKnown ? (int) round($carSoc) : 0;
$carFlow = !empty($snap['car_charging']) ? 'laden' : 'ruhe';
$mode = (string) ($snap['cfg']['charge']['mode'] ?? 'smart');

$face = static function (bool $edit) use ($flow, $socFill, $showHouse, $showBuffer, $showAuto, $priority, $buffer, $auto): void {
    $host = $edit ? 'div' : 'span';
    echo '<' . $host . ' class="zone-frame' . ($edit ? ' zone-frame-edit' : '') . '"' . ($edit ? ' data-battery data-flow="' . e($flow) . '" style="--soc: ' . e(num($socFill, 0)) . '%"' : '') . '>';
    echo '<span class="zone-nub"></span><span class="zone-scale">';
    echo '<span class="zone-body' . ($edit ? ' zone-body-edit' : '') . '">';
    echo '<span class="zone-band zone-boost"><span class="zone-glyph">' . icon('zap', 'h-4 w-4') . '</span></span>';
    echo '<span class="zone-band zone-vehicle"></span>';
    echo '<span class="zone-band zone-house"><span class="zone-glyph">' . icon('house', 'h-4 w-4') . '</span></span>';
    echo '<span class="zone-fill" data-soc-fill></span>';
    echo '<span class="zone-auto"></span>';
    echo '<span class="zone-level"><span class="zone-level-line"></span><span class="zone-level-car">' . icon('car', 'h-3.5 w-3.5') . '</span></span>';
    echo '</span>';
    echo '<span class="zone-tag" data-zone-tag="priority" style="bottom: var(--house)"' . ($showHouse ? '' : ' hidden') . '>' . icon('house', 'h-3 w-3') . ' <span data-zone-read="priority">' . $priority . '</span></span>';
    echo '<span class="zone-tag" data-zone-tag="buffer" style="bottom: var(--support)"' . ($showBuffer ? '' : ' hidden') . '>' . icon('car', 'h-3 w-3') . ' <span data-zone-read="buffer">' . $buffer . '</span></span>';
    echo '<span class="zone-tag" data-zone-tag="auto" style="bottom: var(--auto)"' . ($showAuto ? '' : ' hidden') . '>' . icon('zap', 'h-3 w-3') . ' <span data-zone-read="auto">' . $auto . '</span></span>';
    echo '</span></' . $host . '>';
};

$rows = [[
    'stand' => 'unter ' . $priority . ' %',
    'sun' => 'Speicher zuerst. Das Auto bekommt nur, was die Batterie nicht mehr aufnimmt.',
    'dark' => 'Keine Ladung.',
]];
if ($buffer > $priority) {
    $rows[] = [
        'stand' => $priority . '–' . $buffer . ' %',
        'sun' => 'Auto aus dem Überschuss. Der Speicher bleibt fürs Haus.',
        'dark' => 'Die Vorschau setzt aus, sobald der Überschuss unter der Mindestleistung liegt.',
    ];
}
if ($auto > $buffer) {
    $rows[] = [
        'stand' => $buffer . '–' . $auto . ' %',
        'sun' => 'Auto lädt. Der Speicher darf bis ' . $buffer . ' % mit entladen.',
        'dark' => 'Keine neue Ladung. Eine laufende Ladung endet bei ' . $buffer . ' %.',
    ];
}
$rows[] = [
    'stand' => 'ab ' . $auto . ' %',
    'sun' => 'Auto lädt. Der Speicher darf bis ' . $buffer . ' % mit.',
    'dark' => ($auto >= 100 && $buffer >= 100)
        ? 'Ein Start ohne Sonne liegt erst bei vollem Speicher.'
        : 'Die Ladung startet auch ohne Sonne und endet bei ' . $buffer . ' %.',
];

page_head('Batterie');
?>
<div class="batt-page min-w-0" data-zone-root style="--house: <?= $priority ?>%; --support: <?= $buffer ?>%; --auto: <?= $auto ?>%">
  <div class="batt-hero">
    <section class="card batt-stage">
      <button type="button" class="zone-open" data-battery data-flow="<?= e($flow) ?>" data-open-dialog="battery-zones" style="--soc: <?= e(num($socFill, 0)) ?>%" aria-haspopup="dialog" aria-controls="battery-zones" aria-label="Grenzen der Hausbatterie anpassen">
        <?php $face(false); ?>
      </button>
      <div class="batt-copy min-w-0">
        <p class="text-xs font-medium text-muted-foreground">Ladestand</p>
        <p class="mt-1 text-4xl font-semibold tabular-nums text-battery sm:text-5xl" data-live="soc"><?= e(pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null)) ?></p>
        <p class="mt-2 text-sm" data-live="activity"><?= e($snap['activity']) ?></p>
        <p class="mt-1 text-sm tabular-nums" data-live="capacity_line"><?= e($live['capacity_line'] ?? '') ?></p>
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
        <p class="mt-2 text-sm text-muted-foreground">Wallbox <span data-live="car"><?= e($snap['car_label'] ?? '') ?></span></p>
      </div>
    </section>
  </div>

  <section class="card mt-4 min-w-0 p-4 sm:p-5">
    <div class="mb-3 flex flex-wrap gap-2" data-battery-tools>
      <button type="button" class="chip" data-window="24" aria-pressed="false">24 h</button>
      <button type="button" class="chip" data-window="3" aria-pressed="true">3 Tage</button>
      <button type="button" class="chip" data-window="7" aria-pressed="false">7 Tage</button>
      <button type="button" class="chip" data-window="30" aria-pressed="false">1 Monat</button>
      <button type="button" class="chip" data-unit="soc" aria-pressed="true">%</button>
      <button type="button" class="chip" data-unit="cap" aria-pressed="false">Kapazität</button>
    </div>
    <?php chart_box('/api/series?chart=battery', 'h-64 sm:h-80', 'pan'); ?>
  </section>

  <form id="battery-zones-form" method="post" action="<?= e(url('/einstellungen')) ?>" class="card mt-4 p-4 sm:p-5">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="battery">
    <input type="hidden" name="back" value="/batterie">
    <div class="zone-policies">
      <article class="zone-policy zone-policy-house">
        <h2 class="text-sm font-medium">1. Batterie</h2>
        <p class="mt-2 text-sm text-muted-foreground">Unter <span data-zone-read="priority"><?= $priority ?></span> % geht der Sonnenüberschuss im Modus Smart zuerst in den Hausspeicher. Die Ladevorschau zieht die laufende Ladeleistung ab. Was die Batterie nicht aufnimmt, darf das Auto schon vorher laden. Ohne angestecktes Auto bleibt der Strom im Speicher.</p>
        <label class="mt-3 block text-sm">Bis <span class="tabular-nums" data-range-out><?= $priority ?> %</span>
          <input class="mt-1 w-full" type="range" name="priority_soc" data-zone="priority" min="0" max="100" step="5" value="<?= $priority ?>">
        </label>
        <p class="mt-2 text-sm" data-live="battery_full"><?= e($live['battery_full'] ?? '') ?></p>
        <p class="mt-1 text-sm" data-live="battery_priority" data-live-hide="battery_priority" <?= empty($live['battery_priority']) ? 'hidden' : '' ?>><?= e($live['battery_priority'] ?? '') ?></p>
        <p class="mt-1 text-sm" data-live="battery_surplus"><?= e($live['battery_surplus'] ?? '') ?></p>
        <p class="mt-1 text-xs text-muted-foreground">Haus, 30 Tage <span class="tabular-nums text-foreground" data-live="house_mean"><?= e($live['house_mean'] ?? '—') ?></span> ohne Wallbox.</p>
      </article>
      <article class="zone-policy zone-policy-car">
        <h2 class="text-sm font-medium">2. Auto</h2>
        <p class="mt-2 text-sm text-muted-foreground">Von <span data-zone-read="priority"><?= $priority ?></span> % bis <span data-zone-read="buffer"><?= $buffer ?></span> % hat das Auto den Überschuss. Die Vorschau folgt dieser Leistung. Unter der Mindestleistung setzt sie nach der Ausschaltverzögerung aus. Der Speicher bleibt in diesem Bereich fürs Haus.</p>
        <label class="mt-3 block text-sm">Ab <span class="tabular-nums" data-range-out><?= $priority ?> %</span>
          <input class="mt-1 w-full" type="range" data-zone-mirror="priority" min="0" max="100" step="5" value="<?= $priority ?>" aria-label="Fahrzeug ab diesem Ladestand">
        </label>
        <p class="mt-2 text-sm" data-live="car_outlook"><?= e($live['car_outlook'] ?? '') ?></p>
      </article>
      <article class="zone-policy zone-policy-boost">
        <h2 class="text-sm font-medium">3. Batteriegestützt</h2>
        <p class="mt-2 text-sm text-muted-foreground">Ab <span data-zone-read="buffer"><?= $buffer ?></span> % darf gespeicherte Energie das Auto stützen, bis der Stand wieder auf dieser Grenze liegt. Ab <span data-zone-read="auto"><?= $auto ?></span> % startet die Ladung auch ohne Überschuss. Der Weg über den Speicher kostet Wandlung, direkter Sonnenstrom bleibt der kürzere Weg.</p>
        <label class="mt-3 block text-sm">Stützung ab <span class="tabular-nums" data-range-out><?= $buffer ?> %</span>
          <input class="mt-1 w-full" type="range" name="car_buffer_soc" data-zone="buffer" min="0" max="100" step="5" value="<?= $buffer ?>">
        </label>
        <label class="mt-2 block text-sm">Start ab <span class="tabular-nums" data-range-out><?= $auto ?> %</span>
          <input class="mt-1 w-full" type="range" name="car_auto_soc" data-zone="auto" min="0" max="100" step="5" value="<?= $auto ?>">
        </label>
        <p class="mt-2 text-sm" data-live="battery_buffer"><?= e($live['battery_buffer'] ?? '') ?></p>
        <p class="mt-1 text-xs text-muted-foreground">Die Hausgrenze steckt im Überschuss der Ladevorschau. Stützung und Start bestimmen die Anzeige und die Zeiten.</p>
      </article>
    </div>
    <div class="touch-x mt-4">
      <table class="zone-table">
        <thead><tr><th>Speicher</th><th>Sonne reicht</th><th>Sonne reicht nicht</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr><td class="whitespace-nowrap font-medium"><?= e($row['stand']) ?></td><td><?= e($row['sun']) ?></td><td><?= e($row['dark']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <ul class="mode-key">
      <li <?= $mode === 'aus' ? 'aria-current="true"' : '' ?>><span class="font-medium">Aus.</span> Die Wallbox gibt keinen Ladestrom. Der Überschuss geht in den Speicher, danach ins Netz.</li>
      <li <?= $mode === 'smart' ? 'aria-current="true"' : '' ?>><span class="font-medium">Smart.</span> Die drei Zonen gelten. Unter der Hausgrenze zuerst der Speicher, darüber das Auto, ab der Stützung darf der Speicher mit.</li>
      <li <?= $mode === 'smart_dauerhaft' ? 'aria-current="true"' : '' ?>><span class="font-medium">Smart mit Dauerhaft.</span> Die Mindestleistung bleibt an. Die Hausgrenze senkt höchstens bis auf diesen Strom. Stützung und Start ändern daran wenig, weil schon geladen wird.</li>
      <li <?= $mode === 'schnell' ? 'aria-current="true"' : '' ?>><span class="font-medium">Schnell.</span> Volle Leistung. Die drei Grenzen bleiben außen vor, der Hausspeicher deckt den Bezug.</li>
    </ul>
    <button class="btn-primary mt-4" type="submit">Grenzen speichern</button>
  </form>

  <dialog id="battery-zones" class="sheet">
    <div class="sheet-head flex items-center gap-2 px-4 pb-3 pt-4">
      <h2 class="mr-auto text-sm font-medium">Grenzen</h2>
      <button type="button" class="btn-ghost min-h-11" data-close-dialog>Schließen</button>
    </div>
    <div class="space-y-4 px-4 py-4">
      <?php $face(true); ?>
      <label class="block text-sm">1. Batterie bis <span class="tabular-nums" data-range-out><?= $priority ?> %</span>
        <input class="mt-1 w-full" type="range" data-zone-mirror="priority" min="0" max="100" step="5" value="<?= $priority ?>">
      </label>
      <label class="block text-sm">2. Auto ab <span class="tabular-nums" data-range-out><?= $priority ?> %</span>
        <input class="mt-1 w-full" type="range" data-zone-mirror="priority" min="0" max="100" step="5" value="<?= $priority ?>">
      </label>
      <label class="block text-sm">3. Stützung ab <span class="tabular-nums" data-range-out><?= $buffer ?> %</span>
        <input class="mt-1 w-full" type="range" data-zone-mirror="buffer" min="0" max="100" step="5" value="<?= $buffer ?>">
      </label>
      <label class="block text-sm">Start ab <span class="tabular-nums" data-range-out><?= $auto ?> %</span>
        <input class="mt-1 w-full" type="range" data-zone-mirror="auto" min="0" max="100" step="5" value="<?= $auto ?>">
      </label>
      <button class="btn-primary" type="submit" form="battery-zones-form">Grenzen speichern</button>
    </div>
  </dialog>
</div>
