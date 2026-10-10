<?php
declare(strict_types=1);
/**
 * Ladeziel-Sheet: nach Energiemenge, Uhrzeit, Ladestand oder Reichweite, dazu der Folgemodus. Die Felder der
 * gewählten Art zeigt CSS über :has() am Segment, ohne Skript. Unter jedem Feld rechnet components/target.js beim
 * Tippen die Kilometer und die Dauer aus den data-Werten des Formulars. Gespeichert wird per Formular (section target).
 * @var ?array $target gespeichertes Ziel
 * @var array $snap
 * @var string $back
 */
$type = (string) ($target['type'] ?? 'energy');
$then = (string) ($target['then'] ?? ($snap['cfg']['charge']['then_mode'] ?? 'smart'));
$vehicle = $snap['vehicle'];
$carSoc = $vehicle['soc'] ?? null;
$range = $vehicle['range_km'] ?? null;
$kwhNow = (float) ($snap['chargepoint']['session_kwh'] ?? 0);
$power = (float) ($snap['values']['wallbox_kw'] ?? 0);
$plan = (float) ($snap['suggestion']['offered_kw'] ?? 0);
$value = $target === null ? null : (float) $target['value'];
// Ohne Ziel: 20 kWh mehr als bisher, in einer Stunde (auf die Viertelstunde), das Limit des Autos, 100 km mehr.
$energy = $type === 'energy' && $value !== null ? $value : ceil($kwhNow + 20);
$until = $type === 'time' && $value !== null ? date('H:i', (int) $value) : date('H:i', (int) (ceil((time() + 3600) / 900) * 900));
$soc = $type === 'soc' && $value !== null ? $value : (float) ($vehicle['limit'] ?? 80);
$km = $type === 'range' && $value !== null ? $value : ($range === null ? 300.0 : ceil(((float) $range + 100) / 10) * 10);
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
  <input type="hidden" name="section" value="target"><input type="hidden" name="back" value="<?= e($back) ?>">
  <p class="body-sm muted">Ist das Ziel erreicht, wechselt die App in den Modus darunter; Aus stoppt die Wallbox. Beim Abstecken verfällt das Ziel.</p>
<?php if (!Controller::active($snap['cfg'])): ?>
  <?= ui_notice('info', 'EMS regelt die Wallbox noch nicht. Das Ziel zeigt Fortschritt und Restzeit, stoppen kann EMS erst, wenn es unter ' . ui_inline('Einstellungen', ['href' => url('/einstellungen')]) . ' eingeschaltet ist.') ?>
<?php endif; ?>
  <?= ui_segment('type', 'Ziel nach', [
      'energy' => ['label' => 'Energie', 'short' => 'kWh'],
      'time' => ['label' => 'Uhrzeit', 'short' => 'Zeit'],
      'soc' => ['label' => 'Ladestand', 'short' => '%'],
      'range' => ['label' => 'Reichweite', 'short' => 'km'],
  ], $type, ['id' => 'tg-type']) ?>
  <div class="target-panel stack" data-panel="energy">
    <div class="form-rows"><?= ui_form_row('Energie', ui_input('kwh', ui_field_num($energy, 1), ['id' => 'f-kwh', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']), ['for' => 'f-kwh', 'hint' => 'kWh insgesamt seit dem Anstecken, bisher ' . kwh($kwhNow) . '.']) ?></div>
    <?= $hint('energy') ?>
  </div>
  <div class="target-panel stack" data-panel="time">
    <div class="form-rows"><?= ui_form_row('Bis', ui_input('until', $until, ['id' => 'f-until', 'type' => 'time', 'class' => 'field-num']), ['for' => 'f-until', 'hint' => 'Lädt ab jetzt bis zu dieser Uhrzeit, eine vergangene Zeit meint morgen.']) ?></div>
    <?= $hint('time') ?>
    <div class="row wrap target-quick" role="group" aria-label="Schnellwahl">
      <button class="btn btn-secondary" type="submit" name="hours" value="1">+1 h</button>
      <button class="btn btn-secondary" type="submit" name="hours" value="2">+2 h</button>
      <button class="btn btn-secondary" type="submit" name="hours" value="4">+4 h</button>
    </div>
  </div>
  <div class="target-panel stack" data-panel="soc">
<?php if ($carSoc === null): ?>
    <p class="body-sm">Für ein Ziel nach Ladestand braucht die App den Ladestand des Autos, etwa über Tesla BLE unter <?= ui_inline('Einstellungen → Fahrzeug', ['href' => url('/einstellungen/fahrzeug')]) ?>.</p>
<?php else: ?>
    <?= ui_range('soc', 'Bis Ladestand', $soc, 20, 100, 5, '%', 'Jetzt ' . pct((float) $carSoc) . '.') ?>
    <?= $hint('soc') ?>
<?php endif; ?>
  </div>
  <div class="target-panel stack" data-panel="range">
<?php if ($range === null): ?>
    <p class="body-sm">Für ein Ziel nach Reichweite braucht die App die Reichweite des Autos, etwa über Tesla BLE, oder Ladestand, Akku und Verbrauch unter <?= ui_inline('Einstellungen → Fahrzeug', ['href' => url('/einstellungen/fahrzeug')]) ?>.</p>
<?php else: ?>
    <div class="form-rows"><?= ui_form_row('Reichweite', ui_input('km', ui_field_num($km, 0), ['id' => 'f-km', 'class' => 'field-num', 'inputmode' => 'numeric', 'autocomplete' => 'off']), ['for' => 'f-km', 'hint' => 'Kilometer, die das Auto danach anzeigt, jetzt ' . with_unit((float) $range, 0, 'km') . '.']) ?></div>
    <?= $hint('range') ?>
<?php endif; ?>
  </div>
  <div class="form-rows"><?= ui_form_row('Danach', ui_select('then', ['smart' => 'Nur Solar', 'smart_dauerhaft' => 'Min+Solar', 'aus' => 'Aus (Wallbox stoppt)'], $then), ['for' => 'f-then']) ?></div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= icon('target', 'icon-16') ?>Ziel setzen</button>
<?php if ($target !== null): ?>
    <button class="btn btn-secondary" type="submit" name="clear" value="1">Ziel löschen</button>
<?php endif; ?>
  </div>
</form>
