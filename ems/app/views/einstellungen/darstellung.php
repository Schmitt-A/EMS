<?php
declare(strict_types=1);
/**
 * Einstellungen → Darstellung: Farbschema. Mit JS wechselt es sofort und wird per /api/darstellung gespeichert.
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
<section class="card stack" aria-labelledby="ha-title">
  <h3 class="card-title" id="ha-title">In Home Assistant</h3>
  <p class="body-sm">Seitenleiste und automatische Updates schaltest du auf der Home-Assistant-Seite dieser App, unter Einstellungen → Apps → EMS.</p>
</section>
