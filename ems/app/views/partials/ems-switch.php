<?php
declare(strict_types=1);
/**
 * Hauptschalter oben in den Einstellungen: Regelt EMS die Wallbox (frc, amp, psm) und den Backup-Puffer, oder
 * zeigt es nur an? Einschalten geht erst, wenn die Entitäten der go-e passen; dazu Stand, Warnungen und die
 * letzten Schaltvorgänge.
 * @var array $cfg
 * @var array $snap
 * @var array $control problems, state
 * @var string $back
 */
$active = Controller::active($cfg);
$state = $control['state'];
$problems = $control['problems'];
$paused = $active ? $state['paused'] : null;
$stale = !empty($snap['ems']['stale']);
?>
<section class="card stack ems-switch" aria-labelledby="ems-title"<?= $active ? ' data-active' : '' ?>>
  <div class="ems-switch-head">
    <h2 class="card-title" id="ems-title"><?= icon('power', 'icon-20') ?>EMS regelt die Wallbox</h2>
    <span class="pill<?= $active && !$paused ? '' : ' pill-neutral' ?>"><?= e($active ? ($paused ? 'pausiert' : 'aktiv') : 'aus') ?></span>
  </div>
<?php if ($active): ?>
  <p class="body-sm">EMS stellt Ladestrom, Phasen und Start/Stopp der go-e nach dem Modus ein und hebt beim Netzladen den Backup-Puffer. evcc darf die Wallbox in der Zeit nicht steuern.</p>
<?php else: ?>
  <p class="body-sm muted">Aus: EMS zeigt nur an, was es einstellen würde. evcc oder die go-e regeln weiter über Home Assistant. Zum Testen evcc vom Ladepunkt lösen und hier einschalten.</p>
<?php endif; ?>
<?php if ($paused): ?>
  <?= ui_notice('triangle-alert', e((string) $paused), 'warn') ?>
<?php elseif ($stale): ?>
  <?= ui_notice('triangle-alert', 'Der Recorder läuft nicht, die Regelung steht. Die Wallbox bleibt, wie sie ist.', 'warn') ?>
<?php endif; ?>
<?php if (!$active && $problems): ?>
  <ul class="plain-list stack-tight body-sm" role="list">
<?php foreach ($problems as $problem): ?>
    <li class="ems-problem"><?= icon('circle-alert', 'icon-16') ?><span><?= e($problem) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="form-actions">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="control"><input type="hidden" name="back" value="<?= e($back) ?>">
<?php if ($active): ?>
    <input type="hidden" name="active" value="0">
    <button class="btn btn-secondary" type="submit"><?= icon('power', 'icon-16') ?>EMS ausschalten</button>
<?php else: ?>
    <input type="hidden" name="active" value="1">
    <button class="btn btn-primary" type="submit"<?= $problems ? ' disabled' : '' ?>><?= icon('power', 'icon-16') ?>EMS einschalten</button>
<?php endif; ?>
  </form>
<?php if ($state['log']): ?>
  <details class="ems-log">
    <summary><?= icon('chevron-down', 'icon-16') ?><span>Letzte Schaltvorgänge</span></summary>
    <ul class="plain-list stack-tight body-sm" role="list">
<?php foreach (array_slice((array) $state['log'], 0, 12) as $row): ?>
      <li><span class="num muted"><?= e(date('d.m. H:i', (int) $row['t'])) ?></span> <?= e((string) $row['text']) ?></li>
<?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>
</section>
