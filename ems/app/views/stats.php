<?php
declare(strict_types=1);
page_head('Ladestatistik', 'Sonnenanteil, Netzanteil und Kosten der Ladevorgänge. Neue Vorgänge legt der Recorder selbst an, solange die App läuft.');
$import = (float) $tariffs['import_ct'];
$chartQuery = http_build_query(['chart' => 'sessions', 'span' => $span, 'month' => $month, 'year' => $year]);
$filters = [
    'date' => ['Datum', 'calendar'],
    'energy' => ['Geladen', 'zap'],
    'solar' => ['Sonne', 'sun'],
    'cost' => ['Kosten', 'scale'],
    'duration' => ['Dauer', 'timer'],
];
$active = preg_replace('/_(asc|desc)$/', '', $sort) ?: 'date';
$dir = str_ends_with($sort, '_asc') ? 'asc' : 'desc';
?>
<?php period_nav('/statistik', $year, $month, $span, ['sort' => $sort], ['month', 'year']); ?>
<?php if (!$rows): ?>
  <div class="card mt-4 p-6 text-sm">
    <p>In diesem Zeitraum ist noch kein Ladevorgang gespeichert. Neue Vorgänge schreibt der Recorder, sobald die Wallbox lädt.</p>
    <form class="mt-4" method="post" action="<?= e(url('/statistik')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <input type="hidden" name="span" value="<?= e($span) ?>">
      <input type="hidden" name="year" value="<?= (int) $year ?>">
      <input type="hidden" name="month" value="<?= e($month) ?>">
      <input type="hidden" name="sort" value="<?= e($sort) ?>">
      <button class="btn-primary min-h-11" type="submit">Bisherige Vorgänge aus Home Assistant übernehmen</button>
    </form>
    <p class="mt-2 text-xs text-muted-foreground">Einmalig aus sensor.ems_ladelog_historie. Danach rechnet diese App selbst.</p>
  </div>
<?php else: ?>
  <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <?php stat_card('Geladen', kwh($totals['energy'], 1), 'x'); ?>
    <?php stat_card('Sonne', pct($totals['solar_pct'], 0), 'x'); ?>
    <?php stat_card('Kosten', euro($totals['cost']), 'x'); ?>
    <?php stat_card('Ø Preis', ct($totals['ct']), 'x'); ?>
    <?php stat_card('Dauer', duration_label($totals['duration']), 'x'); ?>
  </div>
  <section class="card mt-4 p-4">
    <h2 class="mb-1 text-sm font-medium"><?= $span === 'year' ? 'Tage im Jahr' : 'Tage im Monat' ?></h2>
    <p class="mb-3 text-xs text-muted-foreground">Sonne orange, Netz blau, übereinander. Ziehen oder wischen verschiebt die Tage.</p>
    <?php chart_box('/api/series?' . $chartQuery, 'h-80', 'pan'); ?>
  </section>
  <div class="mt-4 flex flex-wrap gap-2">
    <?php foreach ($filters as $key => [$label, $glyph]):
        $on = $key === $active;
        $next = $key . '_' . ($on && $dir === 'desc' ? 'asc' : 'desc');
    ?>
      <a class="chip min-h-11" href="<?= e(url('/statistik?' . http_build_query(['span' => $span, 'year' => $year, 'month' => $month, 'sort' => $next]))) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= icon($glyph, 'h-3.5 w-3.5') ?><span><?= e($label) ?></span><?php if ($on): ?><?= icon($dir === 'asc' ? 'arrow-up' : 'arrow-down', 'h-3.5 w-3.5') ?><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card mt-3 overflow-hidden">
    <table class="w-full text-left text-sm">
      <thead class="text-xs text-muted-foreground"><tr>
        <th class="px-4 py-3 font-medium">Zeitraum</th>
        <th class="py-3 font-medium">Geladen</th>
        <th class="py-3 pr-4 font-medium">Sonne</th>
        <th class="hidden py-3 font-medium md:table-cell">Kosten</th>
        <th class="hidden py-3 pr-4 font-medium md:table-cell">Dauer</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $row):
            $cost = $row['costed'];
            $energy = (float) $row['energy_kwh'];
            $seconds = (int) $row['duration_s'];
            $avg = $seconds > 0 ? $energy / ($seconds / 3600) : null;
            $meterStart = $row['meter_start'] ?? null;
            $meterEnd = $row['meter_end'] ?? null;
        ?>
          <tr class="cursor-pointer border-t border-border" data-session tabindex="0" aria-expanded="false">
            <td class="px-4 py-3 align-top">
              <span class="block tabular-nums"><?= e(long_when((string) $row['started_at'])) ?></span>
              <?php if (!empty($row['ended_at'])): ?><span class="block tabular-nums text-muted-foreground"><?= e(long_when((string) $row['ended_at'])) ?></span><?php endif; ?>
            </td>
            <td class="py-3 align-top tabular-nums">
              <span class="block"><?= e(kwh($energy, 1)) ?></span>
              <span class="block text-xs text-muted-foreground"><?= e(duration_clock($seconds)) ?><?= $avg !== null ? ' (~' . e(num($avg, 1)) . ' kW)' : '' ?></span>
            </td>
            <td class="py-3 pr-4 align-top tabular-nums text-export"><?= e(pct($cost['solar_pct'], 1)) ?><span class="block text-xs"><?= e(kwh((float) $row['solar_kwh'], 1)) ?></span></td>
            <td class="hidden py-3 align-top tabular-nums md:table-cell"><?= e(euro($cost['cost'])) ?><span class="block text-xs text-muted-foreground"><?= e(ct($cost['ct'])) ?></span></td>
            <td class="hidden py-3 pr-4 align-top tabular-nums md:table-cell"><?= e(duration_label($seconds)) ?></td>
          </tr>
          <tr class="border-t border-border bg-muted/40" data-session-more hidden>
            <td class="px-4 py-4" colspan="5">
              <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-muted-foreground">Ladepunkt</dt><dd><?= e((string) ($row['loadpoint'] ?: 'Wallbox')) ?></dd></div>
                <div><dt class="text-xs text-muted-foreground">Fahrzeug</dt><dd><?= e((string) ($row['vehicle'] ?: '—')) ?></dd></div>
                <div><dt class="text-xs text-muted-foreground">Kosten</dt><dd class="tabular-nums"><?= e(euro($cost['cost'])) ?> · <?= e(ct($cost['ct'])) ?></dd></div>
                <div><dt class="text-xs text-muted-foreground">Dauer</dt><dd class="tabular-nums"><?= e(duration_clock($seconds)) ?></dd></div>
                <div><dt class="text-xs text-muted-foreground">Zählerstand</dt><dd class="tabular-nums"><?php if ($meterStart !== null && $meterEnd !== null): ?><?= e(num((float) $meterStart, 1)) ?>–<?= e(num((float) $meterEnd, 1)) ?> kWh<?php else: ?>—<?php endif; ?></dd></div>
                <div>
                  <dt class="text-xs text-muted-foreground">Kilometerstand</dt>
                  <dd>
                    <form class="mt-1 flex gap-2" method="post" action="<?= e(url('/statistik')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="odometer">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <input type="hidden" name="span" value="<?= e($span) ?>">
                      <input type="hidden" name="year" value="<?= (int) $year ?>">
                      <input type="hidden" name="month" value="<?= e($month) ?>">
                      <input type="hidden" name="sort" value="<?= e($sort) ?>">
                      <input class="field" name="odometer" inputmode="decimal" placeholder="Wert eintragen" value="<?= $row['odometer'] !== null && $row['odometer'] !== '' ? e((string) $row['odometer']) : '' ?>" aria-label="Kilometerstand">
                      <button class="btn-ghost shrink-0" type="submit">Speichern</button>
                    </form>
                  </dd>
                </div>
              </dl>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mt-4 text-sm text-muted-foreground">Gegen reinen Netzbezug zu <?= e(num($import, 1)) ?> ct/kWh wären es <?= e(euro($totals['reference'])) ?>. Mit Sonne und Einspeisevergütung sind es <?= e(euro($totals['cost'])) ?>, Ersparnis <?= e(euro($totals['saved'])) ?>. Der Sonnenanteil ist mit der entgangenen Vergütung bewertet, nicht mit null. Auf dem Handy zeigt die Zeile Zeitraum, Geladen und Sonne. Ein Tipp öffnet Ladepunkt, Fahrzeug, Kosten, Dauer, Zählerstand und den Kilometerstand.</p>
<?php endif; ?>
