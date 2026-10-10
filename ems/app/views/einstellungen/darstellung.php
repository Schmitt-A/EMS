<?php
declare(strict_types=1);
/**
 * Einstellungen → Darstellung: Farbschema (mit JS sofort, gespeichert per /api/darstellung) und ob Laden den
 * Energiefluss als Balken oder als Energie-Flow zeigt.
 * @var array $cfg
 * @var string $back
 */
$theme = (string) ($cfg['ui']['theme'] ?? 'system');
?>
<section class="card stack" aria-labelledby="theme-title">
  <h3 class="card-title" id="theme-title">Farbschema</h3>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="theme"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?= ui_segment('theme', 'Farbschema', [
        'system' => ['label' => 'System', 'icon' => 'monitor'],
        'light' => ['label' => 'Hell', 'icon' => 'sun'],
        'dark' => ['label' => 'Dunkel', 'icon' => 'moon'],
    ], $theme, ['id' => 'f-theme', 'attrs' => ['data-theme-switch' => true]]) ?>
    <noscript><div class="form-actions"><button class="btn btn-primary" type="submit">Darstellung speichern</button></div></noscript>
  </form>
  <p class="body-sm muted">System folgt der Einstellung von Telefon oder Rechner. Die Wahl gilt für alle, die EMS öffnen.</p>
</section>
<section class="card stack" aria-labelledby="flowview-title">
  <h3 class="card-title" id="flowview-title">Energiefluss auf Laden</h3>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack" data-autosubmit>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="flow_view"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?= ui_segment('flow_view', 'Energiefluss auf Laden', [
        'bar' => ['label' => 'Balken', 'icon' => 'chart-column'],
        'graph' => ['label' => 'Energie-Flow', 'icon' => 'activity'],
    ], (string) ($cfg['ui']['flow_view'] ?? 'bar'), ['id' => 'f-flow-view']) ?>
    <noscript><div class="form-actions"><button class="btn btn-primary" type="submit">Darstellung speichern</button></div></noscript>
  </form>
  <p class="body-sm muted">Balken: Quellen oben, Verbraucher unten, jede Seite über die ganze Breite. Energie-Flow: Kreise für Sonne, Netz, Verbrauch, Speicher und Auto, auf den Linien laufen Punkte dorthin, wohin die Energie fließt.</p>
</section>
<section class="card stack" aria-labelledby="ha-title">
  <h3 class="card-title" id="ha-title">In Home Assistant</h3>
  <p class="body-sm">Seitenleiste und automatische Updates schaltest du auf der Home-Assistant-Seite dieser App, unter Einstellungen → Apps → EMS.</p>
</section>
