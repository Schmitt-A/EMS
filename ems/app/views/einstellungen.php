<?php
declare(strict_types=1);
/**
 * Einstellungen: oben der Hauptschalter „EMS regelt die Wallbox“, darunter acht Bereiche. Mobil erst die Liste,
 * dann der Bereich mit Zurück; ab 1024 px die Liste links und der Bereich rechts, auf /einstellungen der Ladepunkt.
 * @var ?string $area
 * @var array $cfg
 * @var array $ping
 * @var array $suggest
 * @var array $snap
 * @var array $control Hauptschalter: problems (Vorbedingungen), state (Controller)
 */
$m = $cfg['mapping'];
$t = $cfg['tariffs'];
$p = $cfg['plant'];
$theme = (string) ($cfg['ui']['theme'] ?? 'system');
$missing = Actions::missing($m);
$b = $cfg['battery_strategy'];
$reserve = Reserve::settings($cfg);
$areas = [
    'ladepunkt' => ['Ladepunkt', 'plug', $cfg['chargepoint']['name'] . ' · ' . Energy::modeLabel((string) $cfg['charge']['mode'])],
    'fahrzeug' => ['Fahrzeug', 'car', $cfg['vehicle']['name'] . ' · Limit ' . pct((float) $cfg['vehicle']['limit_soc'])],
    'speicher' => ['Speicher', 'battery', 'Haus bis ' . pct((float) $b['priority_soc']) . ' · ' . ($reserve['default'] !== null ? 'Puffer ' . pct((float) $reserve['default']) : 'Puffer offen')],
    'energie' => ['Energie', 'zap', $missing ? 'Fehlt: ' . implode(', ', $missing) : 'PV, Speicher, Netz und Haus zugeordnet'],
    'prognose' => ['Prognose', 'sun', num((float) $p['kwp'], 2) . NNBSP . 'kWp · Faktor ' . num((float) $p['factor'], 2)],
    'tarif' => ['Tarif & CO₂', 'coins', ct((float) $t['import_ct']) . ' · ' . num((float) ($t['co2_g_kwh'] ?? 380), 0) . NNBSP . 'g/kWh'],
    'darstellung' => ['Darstellung', 'monitor', ['system' => 'System', 'light' => 'Hell', 'dark' => 'Dunkel'][$theme] ?? 'System'],
    'system' => ['System', 'server', $ping['ok'] ? 'Verbunden mit Home Assistant ' . $ping['version'] : 'Keine Verbindung'],
];
$shown = $area ?? 'ladepunkt';

echo ui_page_head('Einstellungen');
?>
<div class="mehr"<?= $area !== null ? ' data-area="' . e($area) . '"' : '' ?>>
  <div class="mehr-menu">
<?php view('partials/ems-switch', ['cfg' => $cfg, 'snap' => $snap, 'control' => $control, 'back' => $area === null ? '/einstellungen' : '/einstellungen/' . $area]); ?>
  <nav aria-label="Bereiche">
    <ul class="detail-list" role="list">
<?php foreach ($areas as $key => [$title, $glyph, $text]): ?>
      <li><a class="detail-item" href="<?= e(url('/einstellungen/' . $key)) ?>"<?= $key === $area ? ' aria-current="page"' : ($area === null && $key === $shown ? ' data-default' : '') ?>><?= icon($glyph, 'icon-20') ?><span><span class="detail-item-title"><?= e($title) ?></span><span class="detail-item-text"><?= e($text) ?></span></span><?= icon('chevron-right', 'icon-16') ?></a></li>
<?php endforeach; ?>
    </ul>
  </nav>
  </div>
  <section class="mehr-detail" aria-labelledby="area-title">
    <a class="text-action mehr-back" href="<?= e(url('/einstellungen')) ?>"><?= icon('chevron-left', 'icon-16') ?>Alle Bereiche</a>
    <h2 class="card-title" id="area-title"><?= e($areas[$shown][0]) ?></h2>
<?php view('einstellungen/' . $shown, ['cfg' => $cfg, 'snap' => $snap, 'ping' => $ping, 'suggest' => $suggest, 'missing' => $missing, 'back' => '/einstellungen/' . $shown]); ?>
  </section>
</div>
