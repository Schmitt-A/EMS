<?php
declare(strict_types=1);
/**
 * Ladeziel-Sheet: nach Energiemenge, Uhrzeit oder Ladestand; der Regler des Ladestands nennt die Reichweite dazu, eine
 * eigene Zielart Reichweite gibt es nicht mehr (ein gespeichertes Ziel danach zeigt das Sheet als Ladestand). Für Sonne und Speicher ($scope sun) mit dem
 * Folgemodus, fürs Netzladen ($scope grid) ohne ihn, dafür bei Energie, Ladestand und Reichweite optional „fertig
 * bis“: In Ladefenstern prüft ChargePlan, ob die gewählten Fenster reichen, und plant sonst die günstigsten
 * Viertelstunden dazu. Die Felder der gewählten Art zeigt CSS über :has() am Segment, ohne Skript. Unter jedem Feld
 * rechnet components/target.js beim Tippen die Kilometer und die Dauer aus den data-Werten des Formulars. Gespeichert
 * wird per Formular (section target, scope). Beide Sheets stehen auf derselben Seite, darum tragen die IDs den Bereich.
 * @var ?array $target gespeichertes Ziel
 * @var array $snap
 * @var string $back
 * @var string $scope sun oder grid
 */
$scope = in_array($scope ?? 'sun', ['grid', 'now'], true) ? $scope : 'sun';
$px = ['grid' => 'g', 'now' => 'n', 'sun' => 't'][$scope];
$then = (string) ($target['then'] ?? ($snap['cfg']['charge']['then_mode'] ?? 'smart'));
$vehicle = $snap['vehicle'];
$carSoc = $vehicle['soc'] ?? null;
// Ohne Ziel: mit Ladestand des Autos nach Ladestand, sonst nach Energie.
$type = (string) ($target['type'] ?? ($carSoc !== null ? 'soc' : 'energy'));
$range = $vehicle['range_km'] ?? null;
$kwhNow = (float) ($snap['chargepoint']['session_kwh'] ?? 0);
$power = (float) ($snap['values']['wallbox_kw'] ?? 0);
$plan = (float) ($snap['suggestion']['offered_kw'] ?? 0);
$value = $target === null ? null : (float) $target['value'];
// Ohne Ziel: 20 kWh mehr als bisher, in einer Stunde (auf die Viertelstunde), das Limit des Autos, 100 km mehr.
$energy = $type === 'energy' && $value !== null ? $value : ceil($kwhNow + 20);
$until = $type === 'time' && $value !== null ? date('H:i', (int) $value) : date('H:i', (int) (ceil((time() + 3600) / 900) * 900));
$soc = $type === 'soc' && $value !== null ? $value : (float) ($vehicle['limit'] ?? 80);
if ($type === 'range') {
    // Altes Ziel nach Reichweite: als Ladestand, über km je Prozent von jetzt.
    $perPct = $range !== null && $carSoc !== null && (float) $carSoc >= 1 ? (float) $range / (float) $carSoc : null;
    $soc = $perPct !== null && $value !== null ? max(20, min(100, round($value / $perPct / 5) * 5)) : $soc;
    $type = 'soc';
}
$ready = isset($target['ready_by']) ? date('H:i', (int) $target['ready_by']) : '';
$hint = static fn (string $panel): string => '<p class="caption target-km" data-target-km="' . $panel . '" aria-live="polite"></p>';
?>
<form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack target-form"<?= ui_attrs([
    'data-target-form' => true,
    'data-consumption' => $vehicle['consumption_kwh'] ?? null,
    'data-kwh-now' => round($kwhNow, 3),
    'data-rate' => $power > 0.1 ? round($power, 3) : ($plan > 0.1 ? round($plan, 3) : null),
    'data-soc' => $carSoc,
    'data-capacity' => $vehicle['capacity_kwh'] ?? null,
    'data-range' => $range,
]) ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="target"><input type="hidden" name="scope" value="<?= e($scope) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
<?php if ($scope === 'now'): ?>
  <p class="body-sm muted">Ab „Starten“ lädt das Auto voll aus dem Netz, bis dieses Ziel erreicht ist, der Hausspeicher gibt dabei nichts ab. Danach gelten wieder die Ladefenster, wenn sie eingeschaltet sind, und Sonne und Speicher nach dem Modus. Beim Abstecken verfällt das Ziel.</p>
<?php elseif ($scope === 'grid'): ?>
  <p class="body-sm muted">Bis hierhin lädt das Auto in den gewählten Ladefenstern aus dem Netz. Ist das Ziel erreicht, lädt es bis zum Abstecken in keinem Fenster mehr, Sonne und Speicher laden nach dem Modus weiter. Beim Abstecken verfällt das Ziel.</p>
<?php else: ?>
  <p class="body-sm muted">Ist das Ziel erreicht, wechselt die App in den Modus darunter; „Laden aus“ stoppt die Wallbox. Beim Abstecken verfällt das Ziel.</p>
<?php endif; ?>
<?php if (!Controller::active($snap['cfg'])): ?>
  <?= ui_notice('info', 'EMS regelt die Wallbox noch nicht. Das Ziel zeigt Fortschritt und Restzeit, stoppen kann EMS erst, wenn es unter ' . ui_inline('Einstellungen', ['href' => url('/einstellungen')]) . ' eingeschaltet ist.') ?>
<?php endif; ?>
  <?= ui_segment('type', 'Ziel nach', [
      'soc' => ['label' => 'Ladestand', 'short' => '%'],
      'energy' => ['label' => 'Energie', 'short' => 'kWh'],
      'time' => ['label' => 'Uhrzeit', 'short' => 'Zeit'],
  ], $type, ['id' => $px . 'g-type']) ?>
  <div class="target-panel stack" data-panel="energy">
    <div class="form-rows"><?= ui_form_row('Energie', ui_input('kwh', ui_field_num($energy, 1), ['id' => 'f-' . $px . '-kwh', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => 'f-' . $px . '-kwh', 'hint' => 'kWh insgesamt seit dem Anstecken, bisher ' . kwh($kwhNow) . '.']) ?></div>
    <?= $hint('energy') ?>
  </div>
  <div class="target-panel stack" data-panel="time">
    <div class="form-rows"><?= ui_form_row('Bis', ui_input('until', $until, ['id' => 'f-' . $px . '-until', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-' . $px . '-until', 'hint' => 'Lädt ab jetzt bis zu dieser Uhrzeit, eine vergangene Zeit meint morgen.']) ?></div>
    <?= $hint('time') ?>
    <div class="row wrap target-quick" role="group" aria-label="Schnellwahl">
      <button class="btn btn-secondary" type="submit" name="hours" value="1">+1 h</button>
      <button class="btn btn-secondary" type="submit" name="hours" value="2">+2 h</button>
      <button class="btn btn-secondary" type="submit" name="hours" value="4">+4 h</button>
    </div>
  </div>
  <div class="target-panel stack" data-panel="soc">
<?php if ($carSoc === null): ?>
    <p class="body-sm">Für ein Ziel nach Ladestand braucht die App den Ladestand des Autos, etwa über Tesla BLE unter <?= ui_inline('Einstellungen → Fahrzeug', ['href' => url('/einstellungen/fahrzeug')]) ?>. Bis dahin geht ein Ziel nach Energie.</p>
<?php else: ?>
    <?= str_replace(['id="f-soc"', 'for="f-soc"'], ['id="f-' . $px . '-soc"', 'for="f-' . $px . '-soc"'], ui_range('soc', 'Bis Ladestand', $soc, 20, 100, 5, '%', 'Jetzt ' . pct((float) $carSoc) . '.')) ?>
    <?= $hint('soc') ?>
<?php endif; ?>
  </div>
<?php if ($scope === 'grid'): ?>
  <div class="form-rows target-ready"><?= ui_form_row('Fertig bis', ui_input('ready_by', $ready, ['id' => 'f-' . $px . '-ready_by', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-' . $px . '-ready_by', 'hint' => 'Optional. EMS prüft, ob die gewählten Fenster bis dahin reichen, mit der erwarteten Sonne, und nimmt sonst die günstigsten Viertelstunden davor dazu.']) ?></div>
<?php elseif ($scope === 'sun'): ?>
  <div class="form-rows"><?= ui_form_row('Danach', ui_select('then', ['smart' => 'Intelligent', 'smart_dauerhaft' => 'Min+Sonne', 'aus' => 'Laden aus (Wallbox stoppt)'], $then, ['id' => 'f-' . $px . '-then']), ['for' => 'f-' . $px . '-then']) ?></div>
<?php endif; ?>
  <div class="form-actions">
<?php if ($scope === 'now'): ?>
    <button class="btn btn-primary" type="submit"><?= icon('plug-zap', 'icon-16') ?><?= $target === null ? 'Starten' : 'Ziel ändern' ?></button>
<?php if ($target !== null): ?>
    <button class="btn btn-secondary" type="submit" name="clear" value="1">Stoppen</button>
<?php endif; ?>
<?php else: ?>
    <button class="btn btn-primary" type="submit"><?= icon('target', 'icon-16') ?>Ziel setzen</button>
<?php if ($target !== null): ?>
    <button class="btn btn-secondary" type="submit" name="clear" value="1">Ziel löschen</button>
<?php endif; ?>
<?php endif; ?>
  </div>
</form>
