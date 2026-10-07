<?php
declare(strict_types=1);
/** @var array $snap */
$v = $snap['values'] ?? [];
$b = $snap['balance'] ?? [];
$gridKnown = ($v['grid_import_kw'] ?? null) !== null || ($v['grid_export_kw'] ?? null) !== null;
$gridValue = $gridKnown ? kw(max((float) ($v['grid_import_kw'] ?? 0), (float) ($v['grid_export_kw'] ?? 0))) : '—';
$nodes = [
    'pv' => ['PV', kw($v['pv_kw'] ?? null), 'pv', 380, 36, 'pv'],
    'battery' => ['Speicher', pct(isset($v['battery_soc']) ? (float) $v['battery_soc'] : null, 0), 'battery', 78, 188, 'soc'],
    'house' => ['Haus', kw($b['house_base_kw'] ?? null), 'house', 682, 188, 'house'],
    'grid' => ['Netz', $gridValue, 'import', 210, 352, 'grid_kw'],
    'wallbox' => ['Wallbox', kw($v['wallbox_kw'] ?? null), 'wallbox', 550, 352, 'wallbox'],
];
?>
<div class="card overflow-hidden p-3 sm:p-5">
  <div class="mb-2 flex items-baseline justify-between gap-3">
    <h2 class="text-sm font-medium">Energiefluss</h2>
    <p class="text-xs text-muted-foreground">Differenz <span data-live="diff"><?= e(($b['diff_kw'] ?? null) === null ? '—' : num((float) $b['diff_kw'], 2) . ' kW') ?></span></p>
  </div>
  <svg viewBox="0 0 760 420" class="h-auto w-full" role="img" aria-label="Energiefluss">
    <g fill="none" stroke="var(--c-border)" stroke-width="1.4" stroke-linecap="round">
      <path data-flow="pv" d="M380 78 V168" />
      <path data-flow="battery" d="M150 200 H330" />
      <path data-flow="house" d="M430 200 H610" />
      <path data-flow="grid" d="M380 232 C380 300 250 300 250 330" />
      <path data-flow="wallbox" d="M400 230 C430 300 520 300 520 330" />
    </g>
    <circle cx="380" cy="200" r="7" fill="hsl(var(--primary))" />
    <?php foreach ($nodes as $key => [$label, $value, $tone, $x, $y, $liveKey]): ?>
      <a href="<?= e(url($key === 'pv' || $key === 'house' || $key === 'grid' ? '/' : ($key === 'battery' ? '/batterie' : '/laden'))) ?>">
        <rect x="<?= $x - 62 ?>" y="<?= $y ?>" width="124" height="58" rx="12" fill="var(--c-card)" stroke="var(--c-border)" />
        <text x="<?= $x ?>" y="<?= $y + 22 ?>" text-anchor="middle" font-size="12" fill="var(--c-muted)"><?= e($label) ?></text>
        <text x="<?= $x ?>" y="<?= $y + 42 ?>" text-anchor="middle" font-size="14" font-weight="600" fill="var(--c-fg)" data-live-svg="<?= e($liveKey) ?>"><?= e($value) ?></text>
      </a>
    <?php endforeach; ?>
  </svg>
</div>
