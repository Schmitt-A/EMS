<?php
declare(strict_types=1);
/**
 * Mehr → Fahrzeug: Name, Ladelimit und die Entitäten des Autos.
 * @var array $cfg
 * @var string $back
 */
?>
<section class="card stack" aria-labelledby="car-title">
  <h3 class="card-title" id="car-title">Name, Limit und Entitäten</h3>
<?php view('partials/vehicle-form', ['name' => (string) $cfg['vehicle']['name'], 'limit' => (float) $cfg['vehicle']['limit_soc'], 'mapping' => $cfg['mapping'], 'back' => $back]); ?>
</section>
<p class="body-sm muted">Ab wann der Hausspeicher das Auto stützt, legst du auf der Seite <?= ui_inline('Speicher', ['href' => url('/speicher')]) ?> fest.</p>
