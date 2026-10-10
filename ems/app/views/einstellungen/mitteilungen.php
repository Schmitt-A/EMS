<?php
declare(strict_types=1);
/**
 * Einstellungen → Mitteilungen: die Geräte mit der Home-Assistant-App und die Ruhezeit, die Meldungen zum
 * Ankreuzen (der Stift öffnet ein Sheet mit Titel, Text, Platzhaltern, Vorschau, Grenzen und Probe), die
 * Test-Mitteilung und die zuletzt gesendeten. Platzhalter und Vorschau zeigen die Werte von jetzt;
 * components/notify.js setzt sie beim Tippen ein, schickt Tests per /api/mitteilung und speichert die Häkchen.
 * @var array $cfg
 * @var array $snap
 * @var string $back
 */
$notify = new Notify(store(), ha());
$settings = Notify::settings($cfg);
$services = $notify->services($settings['targets']);
$log = $notify->log(15);
$now = time();
$action = url('/einstellungen');
$open = static fn (string $section, array $attrs = []): string => '<form method="post" action="' . e($action) . '"' . ui_attrs($attrs) . '>' . csrf_field()
    . '<input type="hidden" name="section" value="' . e($section) . '"><input type="hidden" name="back" value="' . e($back) . '">';
$check = static fn (string $name, string $value, bool $checked, string $id): string => '<span class="check"><input type="checkbox"'
    . ui_attrs(['name' => $name, 'value' => $value, 'id' => $id, 'checked' => $checked]) . '><span class="check-box" aria-hidden="true">' . icon('check', 'icon-16') . '</span></span>';
$time = static fn (string $name, string $value, string $id): string => '<input class="field field-num" type="time"' . ui_attrs(['name' => $name, 'id' => $id, 'value' => $value, 'step' => 60]) . '>';
$pill = static fn (array $row): string => '<span class="pill' . ($row['status'] === 'error' ? ' pill-error' : ($row['status'] === 'sent' ? '' : ' pill-neutral')) . '">' . e($row['status_text']) . '</span>';

// Titel und Text mit den Platzhaltern als Knöpfe (Wert von jetzt) und der Vorschau.
$composer = static function (string $event, string $id, string $title, string $message, array $context): string {
    [$own, $rest] = Notify::varsFor($event);
    $chip = static fn (string $key): string => '<button type="button" class="nt-var" data-insert="{' . e($key) . '}" title="' . e(Notify::varLabel($event, $key)) . '">'
        . '<code>{' . e($key) . '}</code><span class="nt-var-value">' . e((string) ($context[$key] ?? '—')) . '</span>'
        . '<span class="sr-only">einfügen: ' . e(Notify::varLabel($event, $key)) . '</span></button>';
    $unknown = Notify::unknown($title . ' ' . $message);
    return '<div class="form-rows">'
        . ui_form_row('Titel', '<input class="field" type="text"' . ui_attrs(['name' => 'title', 'id' => $id . '-title', 'value' => $title, 'maxlength' => Notify::TITLE_MAX, 'autocomplete' => 'off', 'spellcheck' => 'true', 'data-nt-field' => 'title']) . '>', ['for' => $id . '-title', 'stack' => true])
        . ui_form_row('Text', '<textarea class="field nt-text" rows="3"' . ui_attrs(['name' => 'message', 'id' => $id . '-message', 'maxlength' => Notify::MESSAGE_MAX, 'data-nt-field' => 'message']) . '>' . e($message) . '</textarea>', ['for' => $id . '-message', 'stack' => true])
        . '</div>'
        . '<div class="nt-vars"><p class="label" id="' . e($id) . '-vars">Platzhalter, ein Tippen fügt ein</p>'
        . '<div class="nt-var-list" role="group" aria-labelledby="' . e($id) . '-vars">' . implode('', array_map($chip, $own)) . '</div>'
        . '<details class="nt-more"><summary>' . icon('chevron-down', 'icon-16') . '<span>Alle Platzhalter</span></summary><div class="nt-var-list">' . implode('', array_map($chip, $rest)) . '</div></details></div>'
        . '<div class="nt-preview-wrap"><p class="label">Vorschau mit den Werten von jetzt</p>'
        . '<div class="nt-preview"><span class="nt-preview-app">' . icon('zap', 'icon-16') . 'EMS · jetzt</span>'
        . '<span class="nt-preview-title" data-preview="title">' . e(Notify::render($title, $context)) . '</span>'
        . '<span class="nt-preview-text" data-preview="message">' . e(Notify::render($message, $context)) . '</span></div>'
        . '<p class="caption nt-unknown" data-unknown' . ($unknown ? '' : ' hidden') . '>' . e($unknown ? 'Unbekannt: ' . implode(', ', array_map(static fn (string $key): string => '{' . $key . '}', $unknown)) : '') . '</p></div>';
};

$testContext = $notify->contextFor('test', [], $snap, $now);
?>
<section class="card stack" aria-labelledby="nt-devices-title">
  <h3 class="card-title" id="nt-devices-title">Geräte</h3>
  <p class="body-sm muted">Mitteilungen kommen über die Home-Assistant-App. Jedes Handy, auf dem die App mit diesem Home Assistant angemeldet ist, steht hier. Sie gehen auch raus, wenn EMS die Wallbox nicht regelt.</p>
<?php if ($services['error'] !== null): ?>
  <?= ui_notice('triangle-alert', 'Home Assistant nennt gerade keine Geräte: ' . e($services['error']), 'warn') ?>
<?php endif; ?>
  <?= $open('notify_targets', ['class' => 'stack', 'id' => 'nt-targets', 'data-nt-targets' => true]) ?>
<?php if ($services['list'] === []): ?>
  <?= ui_empty('smartphone', 'Keine Home-Assistant-App gefunden. Melde dich in der App auf dem Handy bei diesem Home Assistant an, dann erscheint es hier.') ?>
<?php else: ?>
  <fieldset class="nt-group">
    <legend class="label">An diese Geräte</legend>
    <ul class="plain-list nt-list" role="list">
<?php foreach ($services['list'] as $i => $device): ?>
      <li class="nt-item"><label class="nt-check" for="nt-to-<?= $i ?>"><?= $check('targets[]', $device['service'], in_array($device['service'], $settings['targets'], true), 'nt-to-' . $i) ?><?= icon($device['app'] ? 'smartphone' : 'bell', 'icon-20') ?><span class="nt-check-body"><span class="nt-check-title"><?= e($device['name']) ?></span><span class="nt-check-text"><?= e($device['missing'] ? 'Home Assistant kennt dieses Gerät nicht mehr.' : 'notify.' . $device['service']) ?></span></span></label></li>
<?php endforeach; ?>
    </ul>
  </fieldset>
<?php endif; ?>
  <div class="form-rows">
    <?= ui_form_row('Tippen öffnet EMS', ui_toggle('open_app', $settings['open_app'], 'Tippen öffnet EMS', ['id' => 'f-nt-open']), ['hint' => 'Ein Tippen auf die Mitteilung öffnet EMS in der App, wenn EMS als App in Home Assistant läuft.']) ?>
    <?= ui_form_row('Ruhezeit', ui_toggle('quiet', $settings['quiet'], 'Ruhezeit', ['id' => 'f-nt-quiet']), ['hint' => 'In der Ruhezeit kommen Mitteilungen ohne Ton, wichtige wie immer.']) ?>
    <?= ui_form_row('Ruhezeit ab', $time('quiet_from', $settings['quiet_from'], 'f-nt-quiet-from'), ['for' => 'f-nt-quiet-from']) ?>
    <?= ui_form_row('Ruhezeit bis', $time('quiet_to', $settings['quiet_to'], 'f-nt-quiet-to'), ['for' => 'f-nt-quiet-to']) ?>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Geräte speichern</button></div>
  </form>
</section>

<section class="card stack" aria-labelledby="nt-events-title">
  <h3 class="card-title" id="nt-events-title">Was gemeldet wird</h3>
  <p class="body-sm muted">Ankreuzen, was aufs Handy soll. Der Stift öffnet Titel, Text und Grenzen einer Meldung, mit Vorschau und Probe. Wichtige Meldungen kommen auch in der Ruhezeit mit Ton.</p>
  <?= $open('notify_events', ['class' => 'stack', 'data-nt-events' => true]) ?>
<?php foreach (Notify::GROUPS as $group => $groupLabel): ?>
  <fieldset class="nt-group">
    <legend class="label"><?= e($groupLabel) ?></legend>
    <ul class="plain-list nt-list" role="list">
<?php foreach (Notify::EVENTS as $key => $def): ?>
<?php if ($def['group'] !== $group) continue; ?>
<?php $conf = $settings['events'][$key]; $id = 'nt-' . str_replace('_', '-', $key); ?>
      <li class="nt-item">
        <label class="nt-check" for="<?= e($id) ?>-on"><?= $check('events[]', $key, $conf['on'], $id . '-on') ?><span class="nt-check-body"><span class="nt-check-title"><?= e($def['label']) ?><?= $conf['important'] ? ' <span class="pill">wichtig</span>' : '' ?><?= $conf['custom'] ? ' <span class="pill pill-neutral">eigener Text</span>' : '' ?></span><span class="nt-check-text"><?= e($def['hint']) ?></span></span></label>
        <?= ui_icon_button('pencil', $def['label'] . ' anpassen', ['data-open-dialog' => $id, 'aria-haspopup' => 'dialog']) ?>
      </li>
<?php endforeach; ?>
    </ul>
  </fieldset>
<?php endforeach; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit" data-nt-save>Auswahl speichern</button><p class="caption nt-status" role="status" data-nt-status></p></div>
  </form>
</section>

<section class="card stack" aria-labelledby="nt-test-head">
  <h3 class="card-title" id="nt-test-head">Test-Mitteilung</h3>
  <p class="body-sm muted">Geht sofort an die angekreuzten Geräte, auch in der Ruhezeit. Titel und Text dürfen Platzhalter enthalten und bleiben gespeichert.</p>
  <?= $open('notify_test', ['class' => 'stack', 'id' => 'f-nt-test-form', 'data-nt-form' => true, 'data-event' => 'test', 'data-vars' => ui_json($testContext)]) ?>
  <?= $composer('test', 'f-nt-test', $settings['test']['title'], $settings['test']['message'], $testContext) ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit" data-nt-test><?= icon('send', 'icon-16') ?>Test senden</button><p class="caption nt-status" role="status" data-nt-status></p></div>
  </form>
</section>

<section class="card stack" aria-labelledby="nt-log-head">
  <h3 class="card-title" id="nt-log-head">Zuletzt gesendet</h3>
  <p class="body-sm muted" data-nt-empty<?= $log ? ' hidden' : '' ?>>Noch keine Mitteilung gesendet.</p>
  <ul class="plain-list nt-log" role="list" data-nt-log>
<?php foreach ($log as $row): ?>
    <li class="nt-log-row">
      <div class="nt-log-head"><span class="nt-log-event"><?= e($row['event']) ?></span><?= $pill($row) ?></div>
      <p class="nt-log-title"><?= e($row['title']) ?></p>
      <p class="nt-log-text"><?= e($row['message']) ?></p>
      <p class="nt-log-meta"><?= e($row['time'] . ($row['to'] !== '' ? ' · an ' . $row['to'] : '')) ?></p>
<?php if ($row['error'] !== null): ?>
      <p class="nt-log-error"><?= e($row['error']) ?></p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php
// Je Meldung ein Sheet: Senden, Wichtig, Grenzen, Titel und Text mit Platzhaltern und Vorschau.
foreach (Notify::EVENTS as $key => $def) {
    $conf = $settings['events'][$key];
    $id = 'nt-' . str_replace('_', '-', $key);
    $form = 'f-' . $id;
    $context = $notify->contextFor($key, Notify::sampleData($key, $now), $snap, $now);
    $params = '';
    foreach ($def['params'] as $param) {
        $p = Notify::PARAMS[$param];
        $field = $form . '-' . str_replace('_', '-', $param);
        $control = $param === 'report_time'
            ? $time('report_time', $settings['report_time'], $field)
            : ui_input($param, ui_field_num((float) $settings[$param], (int) $p['decimals']), ['id' => $field, 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off']);
        $params .= ui_form_row($p['label'], $control, ['for' => $field, 'hint' => $p['hint']]);
    }
    $body = $open('notify_event', ['class' => 'stack', 'id' => $form . '-form', 'data-nt-form' => true, 'data-event' => $key, 'data-vars' => ui_json($context)])
        . '<input type="hidden" name="event" value="' . e($key) . '">'
        . '<p class="body-sm muted">' . e($def['hint']) . '</p>'
        . '<div class="form-rows">'
        . ui_form_row('Senden', ui_toggle('on', $conf['on'], 'Senden', ['id' => $form . '-on']))
        . ui_form_row('Wichtig', ui_toggle('important', $conf['important'], 'Wichtig', ['id' => $form . '-important']), ['hint' => 'Auch in der Ruhezeit mit Ton; auf dem iPhone zeitkritisch, unter Android mit hoher Priorität.'])
        . $params
        . '</div>'
        . $composer($key, $form, $conf['title'], $conf['message'], $context)
        . '</form>';
    $foot = '<div class="nt-foot">'
        . '<button class="btn btn-primary" type="submit" form="' . e($form) . '-form" name="do" value="save">Speichern</button>'
        . '<button class="btn btn-secondary" type="submit" form="' . e($form) . '-form" name="do" value="test" data-nt-test>' . icon('send', 'icon-16') . 'Probe senden</button>'
        . ($conf['custom'] ? '<button class="text-action" type="submit" form="' . e($form) . '-form" name="do" value="reset">' . icon('rotate-ccw', 'icon-16') . 'Standardtext</button>' : '')
        . '<p class="caption nt-status" role="status" data-nt-status></p>'
        . '</div>';
    echo ui_dialog($id, $def['label'], $body, ['foot' => $foot]);
}
