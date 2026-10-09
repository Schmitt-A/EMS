<?php
declare(strict_types=1);
/** @var string $content */
/** @var string|null $flash */
$theme = (string) (cfg()['ui']['theme'] ?? 'system');
$theme = in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
$current = request_path();
$showNav = $showNav ?? true;
$nav = [
    '/' => ['Laden', 'plug', null],
    '/speicher' => ['Speicher', 'battery', null],
    '/prognose' => ['Prognose', 'sun', null],
    '/ladevorgaenge' => ['Ladevorgänge', 'chart-column', 'Vorgänge'],
    '/mehr' => ['Mehr', 'ellipsis', null],
];
$isActive = static fn (string $href): bool => $href === '/' ? $current === '/' : ($current === $href || str_starts_with($current, $href . '/'));
?>
<!doctype html>
<html lang="de" data-theme="<?= e($theme) ?>" data-base="<?= e(ingress_base()) ?>" data-icons="<?= e(asset('icons.svg')) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <style><?= VIEW_TRANSITION_CSS ?></style>
<?php if ($theme === 'system'): ?>
  <meta name="theme-color" content="#FAF8F5" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#12100E" media="(prefers-color-scheme: dark)">
<?php else: ?>
  <meta name="theme-color" content="<?= $theme === 'dark' ? '#12100E' : '#FAF8F5' ?>">
<?php endif; ?>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? 'EMS') . ' · EMS') ?></title>
  <link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
  <link rel="preload" href="<?= e(asset('InterVariable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
  <script type="module" src="<?= e(asset('app.js')) ?>"></script>
</head>
<body>
<a class="skip-link" href="#inhalt">Zum Inhalt</a>
<div class="app<?= $showNav ? '' : ' app-bare' ?>">
<?php if ($showNav): ?>
  <nav class="nav" aria-label="Hauptnavigation">
    <div class="nav-brand"><?= icon('zap', 'icon-24') ?><span>EMS</span></div>
    <ul class="nav-list" role="list">
<?php foreach ($nav as $href => [$label, $glyph, $short]): ?>
      <li><a class="nav-link" href="<?= e(url($href)) ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>><?= icon($glyph, 'icon-24') ?><?php if ($short): ?><span class="sr-only"><?= e($label) ?></span><span class="tab-label tab-label-long" aria-hidden="true"><?= e($label) ?></span><span class="tab-label tab-label-short" aria-hidden="true"><?= e($short) ?></span><?php else: ?><span class="tab-label"><?= e($label) ?></span><?php endif; ?></a></li>
<?php endforeach; ?>
    </ul>
  </nav>
<?php endif; ?>
  <div class="app-main">
    <div class="banner" data-banner hidden role="status"><?= icon('wifi-off', 'icon-20') ?><span data-banner-text>Keine Verbindung zu Home Assistant.</span></div>
    <main class="page" id="inhalt" tabindex="-1">
<?php if (!empty($flash)): ?>
      <p class="flash" role="status"><?= icon('circle-check', 'icon-20') ?><span><?= e($flash) ?></span></p>
<?php endif; ?>
<?= $content ?>
    </main>
  </div>
</div>
<p class="sr-only" aria-live="polite" data-live-say></p>
</body>
</html>
