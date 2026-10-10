<?php
declare(strict_types=1);
/**
 * Einstellungen → Fahrzeug: Name, Ladelimit, Akku und die Entitäten des Autos, auch von Tesla BLE.
 * @var array $cfg
 * @var array $snap
 * @var array $suggest
 * @var string $back
 */
?>
<section class="card stack" aria-labelledby="car-title">
  <h3 class="card-title" id="car-title">Name, Limit und Entitäten</h3>
<?php view('partials/vehicle-form', [
    'name' => (string) $cfg['vehicle']['name'],
    'limit' => (float) $cfg['vehicle']['limit_soc'],
    'carLimit' => !empty($snap['vehicle']['limit_from_car']) ? (float) $snap['vehicle']['limit'] : null,
    'capacity' => is_numeric($cfg['vehicle']['capacity_kwh'] ?? null) ? (float) $cfg['vehicle']['capacity_kwh'] : null,
    'mapping' => $cfg['mapping'],
    'suggest' => $suggest,
    'back' => $back,
]); ?>
</section>
<p class="body-sm muted">Ab wann der Hausspeicher das Auto stützt, legst du unter <?= ui_inline('Einstellungen → Speicher', ['href' => url('/einstellungen/speicher')]) ?> oder auf der Seite <?= ui_inline('Speicher', ['href' => url('/speicher')]) ?> fest.</p>
