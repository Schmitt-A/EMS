<?php
declare(strict_types=1);
page_head('Ladestatistik', 'Sonnenanteil, Netzanteil und Kosten der Ladevorgänge in diesem Monat. Neue Vorgänge legt der Recorder selbst an, solange die App läuft: ab 0,2 kW oder beim Status Laden, und er schließt sie nach der Ausschaltverzögerung.');
$import = (float) $tariffs['import_ct'];
?>
<form class="mb-4" method="get" action="<?= e(url('/statistik')) ?>">
  <label class="text-sm">Monat
    <select class="field mt-1 max-w-xs" name="month" onchange="this.form.submit()">
      <?php foreach ($months as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $value === $month ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>
<?php if (!$rows): ?>
  <div class="card p-6 text-sm">
    <p>In diesem Monat ist noch kein Ladevorgang gespeichert. Neue Vorgänge schreibt der Recorder, sobald die Wallbox lädt.</p>
    <form class="mt-4" method="post" action="<?= e(url('/statistik')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <button class="btn-primary" type="submit">Bisherige Vorgänge aus Home Assistant übernehmen</button>
    </form>
    <p class="mt-2 text-xs text-muted-foreground">Einmalig aus sensor.ems_ladelog_historie. Danach rechnet diese App selbst.</p>
  </div>
<?php else: ?>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <?php stat_card('Geladen', kwh($totals['energy'], 1), 'x'); ?>
    <?php stat_card('Sonne', pct($totals['solar_pct'], 0), 'x'); ?>
    <?php stat_card('Kosten', euro($totals['cost']), 'x'); ?>
    <?php stat_card('Ø Preis', ct($totals['ct']), 'x'); ?>
    <?php stat_card('Dauer', duration_label($totals['duration']), 'x'); ?>
  </div>
  <section class="card mt-4 p-4"><?php chart_box('/api/series?chart=sessions&month=' . rawurlencode($month)); ?></section>
  <div class="card mt-4 overflow-x-auto">
    <table class="w-full min-w-[720px] text-left text-sm">
      <thead class="text-xs text-muted-foreground"><tr>
        <th class="px-4 py-3 font-medium">Anfang</th><th class="py-3 font-medium">Fahrzeug</th><th class="py-3 font-medium">Geladen</th><th class="py-3 font-medium">Sonne</th><th class="py-3 font-medium">Kosten</th><th class="py-3 font-medium">Ø Preis</th><th class="px-4 py-3 font-medium">Dauer</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $row): $cost = $row['costed']; ?>
          <tr class="border-t border-border">
            <td class="px-4 py-3 tabular-nums"><?= e((new DateTimeImmutable($row['started_at']))->format('d.m. H:i')) ?></td>
            <td class="py-3"><?= e($row['vehicle'] ?: '—') ?></td>
            <td class="py-3 tabular-nums"><?= e(kwh((float) $row['energy_kwh'], 2)) ?></td>
            <td class="py-3 tabular-nums text-export"><?= e(pct($cost['solar_pct'], 0)) ?></td>
            <td class="py-3 tabular-nums"><?= e(euro($cost['cost'])) ?></td>
            <td class="py-3 tabular-nums"><?= e(ct($cost['ct'])) ?></td>
            <td class="px-4 py-3 tabular-nums"><?= e(duration_label((int) $row['duration_s'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mt-4 text-sm text-muted-foreground">Gegen reinen Netzbezug zu <?= e(num($import, 1)) ?> ct/kWh wären es <?= e(euro($totals['reference'])) ?>. Mit Sonne und Einspeisevergütung sind es <?= e(euro($totals['cost'])) ?>, Ersparnis <?= e(euro($totals['saved'])) ?>. Der Sonnenanteil ist mit der entgangenen Vergütung bewertet, nicht mit null.</p>
<?php endif; ?>
