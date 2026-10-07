<?php
declare(strict_types=1);
/** @var string $content */
/** @var string|null $flash */
$theme = cfg()['ui']['theme'] ?? 'system';
$nav = [
    '/' => ['Übersicht', 'house'],
    '/batterie' => ['Batterie', 'battery'],
    '/laden' => ['Laden', 'car'],
    '/statistik' => ['Statistik', 'chart-column'],
    '/prognose' => ['Prognose', 'chart-line'],
    '/einstellungen' => ['Einstellungen', 'settings'],
];
$current = request_path();
$showNav = !empty($showNav);
?>
<!doctype html>
<html lang="de" data-theme="<?= e($theme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'EMS') ?></title>
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
  <script>
    (function () {
      var stored = localStorage.getItem('ems-theme');
      var theme = stored || document.documentElement.dataset.theme || 'system';
      var dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.classList.toggle('dark', dark);
    })();
  </script>
</head>
<body class="min-h-screen overflow-x-hidden bg-background text-foreground">
  <div class="md:flex md:min-h-screen">
    <?php if ($showNav): ?>
    <aside class="hidden md:flex md:w-60 md:flex-col md:border-r md:border-border md:bg-card">
      <div class="flex items-center gap-2 px-4 py-5">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"><?= icon('zap', 'h-4 w-4') ?></span>
        <div>
          <p class="text-sm font-semibold leading-none">EMS</p>
          <p class="mt-1 text-xs text-muted-foreground">Energie</p>
        </div>
      </div>
      <nav class="flex flex-1 flex-col gap-1 px-3">
        <?php foreach ($nav as $href => [$label, $glyph]): ?>
          <a class="nav-link" href="<?= e(url($href)) ?>" <?= $current === $href ? 'aria-current="page"' : '' ?>><?= icon($glyph, $href === '/batterie' ? 'h-4 w-4 text-battery' : 'h-4 w-4') ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      </nav>
    </aside>
    <?php endif; ?>
    <div class="min-w-0 flex-1">
      <?php if ($showNav && isset($live)): ?>
      <header class="sticky top-0 z-10 border-b border-border bg-background/90 backdrop-blur">
        <div class="flex flex-wrap items-center gap-2 px-4 py-3 md:px-8">
          <span class="text-xs text-muted-foreground">Stand <span data-live="fetched_at"><?= e($live['fetched_at'] ?? '') ?></span></span>
          <span class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <span class="chip"><span class="h-1.5 w-1.5 rounded-full <?= !empty($live['connected']) ? 'bg-export' : 'bg-import' ?>" data-live-dot></span><span data-live="connection"><?= !empty($live['connected']) ? 'verbunden' : 'getrennt' ?></span></span>
            <span class="chip text-pv"><?= icon('sun', 'h-3.5 w-3.5') ?><span data-live="pv"><?= e($live['pv'] ?? '—') ?></span></span>
            <span class="chip text-battery"><?= icon('battery', 'h-3.5 w-3.5 text-battery') ?><span data-live="soc"><?= e($live['soc'] ?? '—') ?></span></span>
            <span class="chip hidden lg:inline-flex text-house"><?= icon('house', 'h-3.5 w-3.5') ?><span data-live="house"><?= e($live['house'] ?? '—') ?></span></span>
            <span class="chip hidden xl:inline-flex"><span data-live="grid"><?= e($live['grid'] ?? '—') ?></span></span>
            <span class="chip text-wallbox"><?= icon('car', 'h-3.5 w-3.5') ?><span data-live="wallbox"><?= e($live['wallbox'] ?? '—') ?></span></span>
          </span>
        </div>
      </header>
      <?php endif; ?>
      <main class="mx-auto w-full max-w-6xl px-4 py-6 pb-24 md:px-8 md:pb-10">
        <?php if (!empty($flash)): ?>
          <p class="mb-4 rounded-lg border border-border bg-muted px-3 py-2 text-sm"><?= e($flash) ?></p>
        <?php endif; ?>
        <?= $content ?>
      </main>
    </div>
  </div>
  <?php if ($showNav): ?>
  <nav class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-6 border-t border-border bg-card md:hidden">
    <?php
      $short = ['/' => 'Start', '/batterie' => 'Speicher', '/laden' => 'Laden', '/statistik' => 'Statistik', '/prognose' => 'Prognose', '/einstellungen' => 'System'];
      foreach ($nav as $href => [$label, $glyph]): ?>
      <a class="flex min-w-0 flex-col items-center gap-1 px-0.5 py-2 text-[10px] leading-tight text-muted-foreground <?= $current === $href ? 'text-foreground' : '' ?>" href="<?= e(url($href)) ?>"><?= icon($glyph, $href === '/batterie' ? 'h-5 w-5 text-battery' : 'h-4 w-4') ?><span class="w-full truncate text-center"><?= e($short[$href] ?? $label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <script>window.EMS_BASE = <?= json_encode(ingress_base(), JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= e(url('/assets/js/chart.js')) ?>"></script>
  <script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
