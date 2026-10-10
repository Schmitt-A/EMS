<?php
declare(strict_types=1);
/**
 * Einstellungen → Tarif & CO₂: Bezugspreis, Einspeisevergütung und CO₂-Faktor des Netzstroms.
 * @var array $cfg
 * @var string $back
 */
$t = $cfg['tariffs'];
?>
<section class="card stack" aria-labelledby="tariff-title">
  <h3 class="card-title" id="tariff-title">Preise und Strommix</h3>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="tariffs"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows">
      <?= ui_number_row('import_ct', 'Netzbezug', (float) $t['import_ct'], 2, 'ct/kWh') ?>
      <?= ui_number_row('export_ct', 'Einspeisevergütung', (float) $t['export_ct'], 2, 'ct/kWh') ?>
      <?= ui_number_row('co2_g_kwh', 'CO₂-Faktor', (float) ($t['co2_g_kwh'] ?? 380), 0, 'g/kWh, der deutsche Strommix liegt bei etwa 380.', ['inputmode' => 'numeric']) ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Tarif speichern</button></div>
  </form>
  <p class="body-sm muted">Netzstrom im Auto kostet den Bezugspreis, Sonnenstrom die entgangene Einspeisevergütung. Mit dem CO₂-Faktor wird Netzstrom zu verursachtem und Sonnenstrom zu gespartem CO₂.</p>
</section>
