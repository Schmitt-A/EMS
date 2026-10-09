<?php
declare(strict_types=1);

function paired_days_sentence(int $n): string
{
    if ($n === 0) {
        return 'Bisher liegt kein abgeschlossener Tag mit Ist und Rohmodell vor.';
    }
    if ($n === 1) {
        return 'Bisher liegt ein abgeschlossener Tag mit Ist und Rohmodell vor.';
    }
    return 'Bisher liegen ' . $n . ' abgeschlossene Tage mit Ist und Rohmodell vor.';
}

function multiply_decimals(float $left, float $right, float $product): int
{
    foreach ([2, 3, 4] as $decimals) {
        $check = round(round($left, $decimals) * round($right, $decimals), 2);
        if (abs($check - round($product, 2)) < 0.001) {
            return $decimals;
        }
    }
    return 4;
}

/**
 * Schrittweise Rechnung vom DWD-Lauf bis zur Prognose, mit den Zahlen des laufenden Tages.
 *
 * @param array<string, mixed> $lesson
 * @param array<string, mixed> $plant
 * @param array<int, array<string, mixed>> $modelRows
 * @param array<string, mixed> $weather
 */
function forecast_method_dialog(array $lesson, array $plant, array $modelRows, float $defaultFactor = 0.93, array $weather = []): void
{
    $day = (string) ($lesson['day'] ?? '');
    $raw = isset($lesson['raw']) && $lesson['raw'] !== null ? (float) $lesson['raw'] : null;
    $sdRaw = isset($lesson['sd_raw']) && $lesson['sd_raw'] !== null ? (float) $lesson['sd_raw'] : null;
    $runs = (int) ($lesson['runs'] ?? 0);
    $factor = (float) ($lesson['factor'] ?? $defaultFactor);
    $locked = !empty($lesson['factor_locked']);
    $prognosis = isset($lesson['prognosis']) && $lesson['prognosis'] !== null ? (float) $lesson['prognosis'] : null;
    $actual = isset($lesson['actual']) && $lesson['actual'] !== null ? (float) $lesson['actual'] : null;
    $pairs = is_array($lesson['pairs'] ?? null) ? $lesson['pairs'] : [];
    $sumActual = (float) ($lesson['sum_actual'] ?? 0);
    $sumModel = (float) ($lesson['sum_model'] ?? 0);
    $gute = isset($lesson['gute']) && $lesson['gute'] !== null ? (float) $lesson['gute'] : null;
    $regressDays = (int) ($lesson['regress_days'] ?? count($lesson['samples'] ?? []));
    $regressA = (float) ($lesson['regress_a'] ?? 0);
    $regressB = (float) ($lesson['regress_b'] ?? 1);
    $regression = $regressDays >= 5;
    $issues = is_array($lesson['issues'] ?? null) ? $lesson['issues'] : [];
    $samples = is_array($lesson['samples'] ?? null) ? $lesson['samples'] : [];
    $sampleMean = isset($lesson['sample_mean']) && $lesson['sample_mean'] !== null ? (float) $lesson['sample_mean'] : null;
    if ($sampleMean === null && $samples) {
        $ratioSum = 0.0;
        foreach ($samples as $sample) {
            $ratioSum += (float) ($sample['ratio'] ?? 0);
        }
        $sampleMean = $ratioSum / count($samples);
    }
    $scale = $regression ? abs($regressB) : $factor;
    $kwp = (float) ($plant['kwp'] ?? 0);
    $inverter = (float) ($plant['inverter_kw'] ?? 0);
    $chain1 = Forecast::chain($kwp, 1.0);
    $chainF = Forecast::chain($kwp, $factor);
    $hourRaw = min($inverter > 0 ? $inverter : 1e9, 1000 * $chain1);
    $hourNow = min($inverter > 0 ? $inverter : 1e9, 1000 * $chainF);
    $product = null;
    if ($raw !== null) {
        $product = $regression ? max(0, $regressA + $regressB * $raw) : max(0, $raw * $factor);
    }
    $spread = $sdRaw !== null ? $sdRaw * $scale : null;
    $factorDecimals = abs($factor - round($factor, 2)) < 0.0001 ? 2 : 3;
    $rawDecimals = ($raw !== null && $product !== null && !$regression) ? multiply_decimals($raw, $factor, $product) : 2;
    $sdDecimals = ($sdRaw !== null && $spread !== null) ? multiply_decimals($sdRaw, $scale, $spread) : 2;
    $place = trim((string) ($weather['name'] ?? ''));
    $station = trim((string) ($weather['station'] ?? ''));
    $where = $place !== '' ? $place . ($station !== '' ? ', ' . $station : '') : ($station !== '' ? $station : '');
    $tz = new DateTimeZone('Europe/Berlin');
    $ahead = [];
    $held = [];
    foreach ($modelRows as $row) {
        if (!is_array($row) || !isset($row['day'])) {
            continue;
        }
        if ((string) $row['day'] > $day) {
            $ahead[] = $row;
            continue;
        }
        if (empty($row['today']) && ($row['raw'] ?? null) === null && ($row['model'] ?? null) !== null) {
            $held[] = $row;
        }
    }
    usort($ahead, static fn (array $a, array $b): int => strcmp((string) $a['day'], (string) $b['day']));
    $sampleCount = count($samples);
    $wouldBe = $sampleMean !== null ? round($sampleMean, 3) : null;
    ?>
<dialog id="forecast-method" class="dialog dialog-wide" aria-labelledby="forecast-method-title">
  <div class="dialog-grip" data-grip aria-hidden="true"></div>
  <div class="dialog-head" data-grip>
    <h2 class="card-title" id="forecast-method-title">Rechnung für <?= e($day !== '' ? day_label($day) : 'den laufenden Tag') ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="Schließen"><?= icon('x', 'icon-20') ?></button>
  </div>
  <div class="dialog-body method">
  <div class="method-note body">
    <p><strong>Eichfaktor <?= e(num($factor, $factorDecimals)) ?>.</strong>
      <?php if ($locked): ?>
        Der Wert ist in den Einstellungen festgehalten.
      <?php elseif ($regression && $wouldBe !== null): ?>
        Er ist der Mittelwert aus Ist ÷ Rohmodell über <?= (int) $sampleCount ?> abgeschlossene Tage, gerundet <?= e(num($wouldBe, 3)) ?>.
      <?php elseif ($sampleCount > 0 && $wouldBe !== null): ?>
        <?= e(paired_days_sentence($sampleCount)) ?> Der Mittelwert daraus wäre <?= e(num($wouldBe, 3)) ?> und bleibt noch ungenutzt. Bis fünf solcher Tage vorliegen, gilt der Anlagenwert <?= e(num($defaultFactor, 2)) ?>.
      <?php else: ?>
        <?= e(paired_days_sentence($sampleCount)) ?> Bis fünf solcher Tage vorliegen, gilt der Anlagenwert <?= e(num($defaultFactor, 2)) ?>.
      <?php endif; ?>
    </p>
    <p><strong>Streuung und Abweichung.</strong>
      <?php if ($sdRaw !== null && $spread !== null): ?>
        Die Streuung ist <?= e(num($sdRaw, 2)) ?> kWh. Sie misst, wie weit die <?= (int) max($runs, count($issues)) ?> gespeicherten DWD-Läufe um das Rohmodell liegen. Die Abweichung ist dieselbe Streuung auf der Skala der Prognose: <?= e(num($sdRaw, $sdDecimals)) ?> × <?= e(num($scale, $factorDecimals)) ?> = <?= e(num($spread, 2)) ?> kWh, angezeigt ± <?= e(num($spread, 1)) ?> kWh.
      <?php else: ?>
        Die Streuung entsteht ab dem zweiten vollständigen DWD-Lauf. Bis dahin bleibt die Abweichung leer.
      <?php endif; ?>
    </p>
    <p><strong>Güte<?php if ($gute !== null): ?> <?= e(num($gute, 2)) ?><?php endif; ?>.</strong>
      Standard sind die letzten drei abgeschlossenen Tage. 7 Tage, Monat und Quartal stellen dasselbe Fenster für die Kachel und für das Diagramm um. Der laufende Tag kommt erst dazu, wenn er vorbei ist.
      <?php if ($gute !== null): ?>
        In diesem Standardfenster ist die Güte <?= e(num($sumModel, 2)) ?> ÷ <?= e(num($sumActual, 2)) ?> = <?= e(num($gute, 2)) ?>.
      <?php endif; ?>
    </p>
    <p><strong>Kommende Tage.</strong>
      <?php if ($regression): ?>
        Jeder kommende Tag behält sein eigenes Rohmodell. Die Prognose wird daraus <?= e(num($regressA, 2)) ?> + <?= e(num($regressB, 2)) ?> × Rohmodell. Die Güte fließt in die kommenden Tage nicht ein.
      <?php else: ?>
        Jeder kommende Tag behält sein eigenes Rohmodell und wird mit dem Eichfaktor <?= e(num($factor, $factorDecimals)) ?> multipliziert. Die Güte fließt in die kommenden Tage nicht ein.
      <?php endif; ?>
    </p>
  </div>
  <div class="method-steps body-sm">
    <section>
      <h3>1. Was der DWD schickt</h3>
      <p>Die Prognose beginnt mit der MOSMIX-L-Datei des Deutschen Wetterdienstes<?= $where !== '' ? ' (' . e($where) . ')' : '' ?>. Darin steht keine Kilowattstunde. <strong>Rad1h</strong> ist die Strahlungsenergie der vorangegangenen Stunde in kJ/m². Geteilt durch 3,6 wird daraus die mittlere Bestrahlungsstärke in W/m². Der Zeitstempel in der Datei markiert das Ende der Stunde, gespeichert wird der Beginn, eine Stunde früher. Daneben kommen Bewölkung in Prozent, Sonnenscheindauer und Temperatur.</p>
      <p>Jeder Modelllauf bleibt eine eigene Zeile, sobald der Tag ab den frühen Morgenstunden in der Datei steht und die Summe über 0,05 kWh liegt. Ein späterer Lauf überschreibt den früheren nicht. Die Strahlung in der Tabelle ist die Summe der Stundenwerte in W/m², umgerechnet in kWh/m². Sonnenschein, Bewölkung und Temperatur sind die Werte dieses Laufs.</p>
      <?php if ($issues): ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Rechentabelle">
          <table class="table table-compact">
            <thead><tr>
              <th scope="col">Lauf</th>
              <th scope="col">Rohmodell</th>
              <th scope="col">Strahlung</th>
              <th scope="col">Sonne</th>
              <th scope="col">Wolken</th>
              <th scope="col">Temperatur</th>
              <th scope="col">Stunden</th>
            </tr></thead>
            <tbody>
              <?php foreach ($issues as $issue): ?>
                <?php $stamp = (new DateTimeImmutable('@' . (int) ($issue['issue'] ?? 0)))->setTimezone($tz); ?>
                <tr>
                  <td class="nowrap"><?= e($stamp->format('d.m. H:i')) ?></td>
                  <td class="num"><?= e(kwh(isset($issue['kwh']) ? (float) $issue['kwh'] : null, 3)) ?></td>
                  <td class="num"><?= isset($issue['radiation']) && $issue['radiation'] !== null ? e(num((float) $issue['radiation'] / 1000, 2)) . NNBSP . 'kWh/m²' : '—' ?></td>
                  <td class="num"><?= isset($issue['sunshine_s']) && $issue['sunshine_s'] !== null ? e(num((float) $issue['sunshine_s'] / 3600, 1)) . NNBSP . 'h' : '—' ?></td>
                  <td class="num"><?= isset($issue['cloud']) && $issue['cloud'] !== null ? e(num((float) $issue['cloud'], 0)) . NNBSP . '%' : '—' ?></td>
                  <td class="num"><?= isset($issue['temp_c']) && $issue['temp_c'] !== null ? e(num((float) $issue['temp_c'], 1)) . NNBSP . '°C' : '—' ?></td>
                  <td class="num"><?= (int) ($issue['hours'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p><?= count($issues) === 1 ? 'Für diesen Tag ist ein Lauf gespeichert.' : 'Für diesen Tag sind ' . count($issues) . ' Läufe gespeichert.' ?> Die Kilowattstunden darin sind das Rohmodell, gerechnet mit Eichfaktor 1.</p>
      <?php else: ?>
        <p>Für diesen Tag liegt noch kein gespeicherter Modelllauf vor, der den Morgen mit abdeckt.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>2. Aus einer Stunde wird Leistung</h3>
      <p>Jede Stunde mit der Bestrahlungsstärke G in W/m² wird zur Leistung P. Die Anlage hat <?= e(num($kwp, 2)) ?> kWp, das Wechselrichter-Limit liegt bei <?= e(num($inverter, 1)) ?> kW. Die festen Faktoren 1,04, 0,90 und 0,975 bilden die Verlustkette.</p>
      <?php formula('<mrow><mi>P</mi><mo>=</mo><mo>min</mo><mo>(</mo><msub><mi>P</mi><mtext>WR</mtext></msub><mo>,</mo><mi>G</mi><mo>×</mo><mo>(</mo>' . mnum($kwp, 2) . '<mo>×</mo><mn>1,04</mn><mo>×</mo><mn>0,90</mn><mo>×</mo><mn>0,975</mn><mo>/</mo><mn>1000</mn><mo>)</mo><mo>×</mo><mi>f</mi><mo>)</mo></mrow>'); ?>
      <p>Mit Eichfaktor 1 ist die Kette <?= e(num($chain1, 6)) ?> kW je W/m². Eine Stunde mit 1000 W/m² ergibt im Rohmodell <?= e(num($hourRaw, 2)) ?> kW und damit <?= e(num($hourRaw, 2)) ?> kWh in dieser Stunde. Mit dem aktuellen Eichfaktor <?= e(num($factor, $factorDecimals)) ?> wird dieselbe Stunde auf der Kurve zu <?= e(num($hourNow, 2)) ?> kW.</p>
      <?php formula('<mrow><msub><mi>K</mi><mn>1</mn></msub><mo>=</mo>' . mnum($chain1, 6) . '<mtext> kW je W/m²</mtext></mrow><mspace width="1.2em"></mspace><mrow><msub><mi>K</mi><mi>f</mi></msub><mo>=</mo>' . mnum($chain1, 6) . '<mo>×</mo>' . mnum($factor, $factorDecimals) . '<mo>=</mo>' . mnum($chainF, 6) . '</mrow>'); ?>
      <p>Der gespeicherte Lauf summiert die Stunden mit Faktor 1. Eine Stunde, die über dem Wechselrichter-Limit liegt, wird auf dieses Limit gekürzt. Die Kurve im Energiediagramm zeichnet den zuletzt geladenen Lauf und hat den Eichfaktor schon in jeder Stunde.</p>
    </section>
    <section>
      <h3>3. Rohmodell, der Mittelwert der Läufe</h3>
      <?php if ($raw !== null && $issues): ?>
        <p>Die Tagessumme eines Laufs ist die Summe seiner Stunden. Das Rohmodell des Tages ist der Mittelwert dieser Summen. Für <?= e(day_label($day)) ?> sind das <?= count($issues) === 1 ? 'ein Lauf' : count($issues) . ' Läufe' ?>.</p>
        <?php
          $terms = [];
          foreach ($issues as $issue) {
              $terms[] = mnum(isset($issue['kwh']) ? (float) $issue['kwh'] : null, 3);
          }
        ?>
        <?php if (count($terms) <= 6): ?>
          <?php formula('<mrow><mi>Rohmodell</mi><mo>=</mo><mfrac><mrow>' . implode('<mo>+</mo>', $terms) . '</mrow><mn>' . count($terms) . '</mn></mfrac><mo>=</mo>' . mnum($raw, 2) . '<mtext> kWh</mtext></mrow>'); ?>
        <?php else: ?>
          <?php formula('<mrow><mi>Rohmodell</mi><mo>=</mo><mfrac><mn>1</mn><mi>n</mi></mfrac><mo>∑</mo><msub><mi>E</mi><mi>i</mi></msub><mo>=</mo>' . mnum($raw, 2) . '<mtext> kWh</mtext><mspace width="1em"></mspace><mrow><mi>n</mi><mo>=</mo><mn>' . count($issues) . '</mn></mrow></mrow>'); ?>
        <?php endif; ?>
        <p>In der Spalte Rohmodell steht dieser Mittelwert auf einer Nachkommastelle: <?= e(kwh($raw, 1)) ?>. Die weitere Rechnung benutzt <?= e(num($raw, $rawDecimals)) ?> kWh, damit die Multiplikation zu der angezeigten Prognose passt.</p>
      <?php elseif ($raw !== null): ?>
        <p>Das Rohmodell für <?= e(day_label($day)) ?> ist der Mittelwert der gespeicherten Läufe: <?= e(num($raw, 2)) ?> kWh.</p>
        <?php formula('<mrow><mi>Rohmodell</mi><mo>=</mo>' . mnum($raw, 2) . '<mtext> kWh</mtext></mrow>'); ?>
      <?php else: ?>
        <p>Für <?= e($day !== '' ? day_label($day) : 'diesen Tag') ?> liegt kein Rohmodell aus DWD-Läufen vor<?php if ($prognosis !== null): ?>. Die Prognose ist der festgeschriebene Tageswert <?= e(kwh($prognosis, 1)) ?>. Der Eichfaktor ändert diese Zahl nicht, und eine Abweichung aus Läufen gibt es dabei nicht<?php endif; ?>.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>4. Streuung der Läufe</h3>
      <?php if ($sdRaw !== null && count($issues) >= 2): ?>
        <p>Die Streuung ist die empirische Standardabweichung der Rohmodell-Summen. Geteilt wird durch n − 1, also durch die Anzahl der Läufe minus eins. Sie liegt auf der Skala mit Eichfaktor 1.</p>
        <?php formula('<mrow><mi>s</mi><mo>=</mo><msqrt><mfrac><mrow><mo>∑</mo><msup><mrow><mo>(</mo><msub><mi>E</mi><mi>i</mi></msub><mo>−</mo><mover><mi>E</mi><mo>¯</mo></mover><mo>)</mo></mrow><mn>2</mn></msup></mrow><mrow><mi>n</mi><mo>−</mo><mn>1</mn></mrow></mfrac></msqrt></mrow>'); ?>
        <?php
          $meanIssue = 0.0;
          $values = [];
          foreach ($issues as $issue) {
              $values[] = (float) ($issue['kwh'] ?? 0);
          }
          $meanIssue = array_sum($values) / count($values);
          $squares = [];
          foreach ($values as $value) {
              $squares[] = ($value - $meanIssue) ** 2;
          }
        ?>
        <?php if (count($values) <= 4): ?>
          <p>Mit den gespeicherten Summen, Mittelwert <?= e(num($meanIssue, 3)) ?> kWh:</p>
          <ul class="method-list">
            <?php foreach ($values as $index => $value): ?>
              <li class="num">(<?= e(num($value, 3)) ?> − <?= e(num($meanIssue, 3)) ?>)² = <?= e(num($squares[$index], 5)) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php $squareSum = array_sum($squares); ?>
          <p>Summe der Quadrate <?= e(num($squareSum, 5)) ?>. Geteilt durch <?= count($values) ?> − 1 = <?= e(num($squareSum / (count($values) - 1), 5)) ?>. Die Wurzel ist die Streuung <?= e(num($sdRaw, $sdDecimals)) ?> kWh, auf zwei Stellen <?= e(num($sdRaw, 2)) ?> kWh.</p>
        <?php else: ?>
          <p>Aus den <?= count($values) ?> Läufen der Tabelle wird die Streuung <?= e(num($sdRaw, $sdDecimals)) ?> kWh, auf zwei Stellen <?= e(num($sdRaw, 2)) ?> kWh.</p>
        <?php endif; ?>
      <?php elseif ($runs <= 1): ?>
        <p>Für heute liegt <?= $runs === 1 ? 'ein vollständiger Lauf' : 'noch kein zweiter Lauf' ?> vor. Die Streuung braucht mindestens zwei Läufe. Bis dahin bleibt die Abweichung leer.</p>
      <?php else: ?>
        <p>Für die gespeicherten Läufe liegt noch keine Streuung vor.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>5. Woher der Eichfaktor kommt</h3>
      <p>Der Eichfaktor steht in der Stundenformel und skaliert das Rohmodell, bevor die Prognose feststeht. Neu ist er der Anlagenwert <?= e(num($defaultFactor, 2)) ?>. Berechnet wird er, sobald mindestens fünf abgeschlossene Tage zugleich einen gemessenen Ertrag und ein Rohmodell über 1 kWh haben. Der laufende Tag zählt nicht. Festgeschriebene Tage und verworfene Tage zählen nicht. Die Formel ist der Mittelwert der Verhältnisse Ist ÷ Rohmodell, gerundet auf drei Stellen.</p>
      <?php formula('<mrow><mi>f</mi><mo>=</mo><mfrac><mn>1</mn><mi>n</mi></mfrac><mo>∑</mo><mfrac><mi>Ist</mi><mi>Rohmodell</mi></mfrac></mrow>'); ?>
      <p><?= e(paired_days_sentence($sampleCount)) ?> Ab fünf Tagen schreibt die Anwendung diesen Mittelwert als Eichfaktor, sofern er in den Einstellungen nicht festgehalten ist. Ein von Hand geänderter Faktor bleibt stehen, bis er dort wieder freigegeben wird.</p>
      <?php if ($samples): ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Rechentabelle">
          <table class="table table-compact">
            <thead><tr>
              <th scope="col">Tag</th>
              <th scope="col">Ist</th>
              <th scope="col">Rohmodell</th>
              <th scope="col">Ist ÷ Rohmodell</th>
            </tr></thead>
            <tbody>
              <?php foreach ($samples as $sample): ?>
                <tr>
                  <td><?= e(day_label((string) $sample['day'])) ?></td>
                  <td class="num"><?= e(kwh((float) $sample['actual'], 2)) ?></td>
                  <td class="num"><?= e(kwh((float) $sample['raw'], 2)) ?></td>
                  <td class="num"><?= e(num((float) $sample['ratio'], 3)) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($wouldBe !== null): ?>
                <tr class="sum-row">
                  <td colspan="3">Mittelwert<?= (!$regression || $locked) ? ', noch ungenutzt' : '' ?></td>
                  <td class="num"><?= e(num($wouldBe, 3)) ?></td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($locked): ?>
          <p>Der Eichfaktor <?= e(num($factor, $factorDecimals)) ?> ist festgehalten. Die Verhältnisse ändern ihn nicht.</p>
        <?php elseif ($regression): ?>
          <p>Fünf Tage sind erreicht. Der gerundete Mittelwert ist der Eichfaktor <?= e(num($factor, $factorDecimals)) ?>.</p>
        <?php else: ?>
          <p>Der gerundete Mittelwert <?= $wouldBe !== null ? e(num($wouldBe, 3)) . ' ' : '' ?>bleibt noch ungenutzt. Der Eichfaktor bleibt <?= e(num($factor, $factorDecimals)) ?>.</p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($held): ?>
        <p>Festgeschriebene Tage behalten ihre Kilowattstunde und liefern kein Verhältnis: <?php foreach ($held as $index => $row): ?><?= $index > 0 ? ', ' : '' ?><?= e(day_label((string) $row['day'])) ?> <?= e(kwh((float) $row['model'], 1)) ?><?php endforeach; ?>.</p>
      <?php endif; ?>
      <?php if ($regression): ?>
        <p>Ab fünf Tagen kann die Prognose zusätzlich die Regression verwenden: Ist = <?= e(num($regressA, 2)) ?> + <?= e(num($regressB, 2)) ?> × Rohmodell. Die Spalte Faktor zeigt weiterhin Rohmodell × Eichfaktor.</p>
      <?php else: ?>
        <p>Die Regression löst den Eichfaktor ab fünf solchen Tagen ab. <?= $sampleCount === 0 ? 'Bisher liegt keiner vor.' : ($sampleCount === 1 ? 'Bisher liegt ein Tag vor.' : 'Bisher liegen ' . $sampleCount . ' Tage vor.') ?> Bis dahin bleibt die Spalte Regression leer, und Prognose und Faktor sind dieselbe Multiplikation.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>6. Die Prognose</h3>
      <?php if ($raw !== null && $product !== null && $prognosis !== null): ?>
        <?php if ($regression): ?>
          <p>Die Prognose ist das Maximum aus 0 und dem Regressionswert. Für <?= e(day_label($day)) ?>:</p>
          <?php formula('<mrow><mi>Prognose</mi><mo>=</mo><mo>max</mo><mo>(</mo><mn>0</mn><mo>,</mo>' . mnum($regressA, 2) . '<mo>+</mo>' . mnum($regressB, 2) . '<mo>×</mo>' . mnum($raw, 2) . '<mo>)</mo><mo>=</mo>' . mnum($product, 2) . '<mtext> kWh</mtext></mrow>'); ?>
        <?php else: ?>
          <p>Die Prognose ist das Rohmodell mal dem Eichfaktor. Der Faktor steckt im Rohmodell noch nicht, deshalb wird er hier genau einmal angewendet. Für <?= e(day_label($day)) ?>:</p>
          <?php formula('<mrow><mi>Prognose</mi><mo>=</mo>' . mnum($raw, $rawDecimals) . '<mtext> kWh</mtext><mo>×</mo>' . mnum($factor, $factorDecimals) . '<mo>=</mo>' . mnum($product, 2) . '<mtext> kWh</mtext></mrow>'); ?>
        <?php endif; ?>
        <p>Kachel, Zahl über der Kurve und die Spalte Prognose runden auf eine Nachkommastelle: <?= e(kwh($prognosis, 1)) ?>. Die Spalte Rohmodell zeigt den unskalierten Mittelwert auf einer Stelle, <?= e(kwh($raw, 1)) ?>. Die Prognose multipliziert den Mittelwert mit <?= e(num($raw, $rawDecimals)) ?> kWh und rundet erst das Ergebnis.</p>
      <?php elseif ($prognosis !== null): ?>
        <p>Die Prognose für <?= e(day_label($day)) ?> ist der festgeschriebene Wert <?= e(kwh($prognosis, 1)) ?>.</p>
      <?php else: ?>
        <p>Für <?= e(day_label($day)) ?> liegt noch keine Prognose vor.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>7. Die Abweichung</h3>
      <?php if ($sdRaw !== null && $spread !== null && $prognosis !== null): ?>
        <p>Die Abweichung nimmt die Streuung von der Skala des Rohmodells und multipliziert sie mit demselben Faktor wie die Prognose<?php if ($regression): ?>, hier mit dem Betrag der Steigung <?= e(num($scale, $factorDecimals)) ?><?php endif; ?>. So bleibt das ± in derselben Einheit wie die angezeigte Prognose.</p>
        <?php formula('<mrow><mi>Abweichung</mi><mo>=</mo><mi>s</mi><mo>×</mo>' . mnum($scale, $factorDecimals) . '<mo>=</mo>' . mnum($sdRaw, $sdDecimals) . '<mo>×</mo>' . mnum($scale, $factorDecimals) . '<mo>=</mo>' . mnum($spread, 2) . '<mtext> kWh</mtext></mrow>'); ?>
        <p>Angezeigt wird auf eine Nachkommastelle: ± <?= e(num($spread, 1)) ?> kWh. Über der Kurve steht damit <?= e(Forecast::captionText($prognosis, $spread)) ?>. Die Streuung sagt, wie weit die DWD-Läufe auseinanderliegen. Die Abweichung ist diese Spanne nach der Skalierung. Der Vergleich mit dem Zähler ist die Güte im nächsten Schritt.</p>
      <?php else: ?>
        <p>Ohne zweiten Lauf gibt es keine Streuung und damit keine Abweichung. In der Tabelle steht dann nur die Prognose, ohne ±.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>8. Die Güte der abgeschlossenen Tage</h3>
      <p>Die Güte prüft die fertige Prognose. Sie ist die Summe der Prognosen geteilt durch die Summe der gemessenen Erträge. Standard sind die letzten drei Kalendertage vor heute, sofern Ist und Prognose für den Tag vollständig sind. Die Knöpfe 7 Tage, Monat und Quartal stellen dieses Fenster für die Kachel und für das Diagramm gemeinsam um. Diese Rechnung zeigt den Standard.</p>
      <?php formula('<mrow><mi>Güte</mi><mo>=</mo><mfrac><mrow><mo>∑</mo><mi>Prognose</mi></mrow><mrow><mo>∑</mo><mi>Ist</mi></mrow></mfrac></mrow>'); ?>
      <p>Der laufende Tag<?php if ($actual !== null): ?>, bisher <?= e(kwh($actual, 1)) ?> gemessen,<?php endif; ?> bleibt außen vor, bis er abgeschlossen ist. Eine Güte über 1 heißt, die Prognose lag über die gewählten Tage höher als der Zähler. Eine Güte unter 1 heißt, der Zähler lag höher.</p>
      <?php if ($pairs): ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Rechentabelle">
          <table class="table table-compact">
            <thead><tr>
              <th scope="col">Tag</th>
              <th scope="col">Ist</th>
              <th scope="col">Prognose</th>
            </tr></thead>
            <tbody>
              <?php foreach ($pairs as $pair): ?>
                <tr>
                  <td><?= e(day_label((string) $pair['day'])) ?></td>
                  <td class="num"><?= e(kwh((float) $pair['actual'], 1)) ?></td>
                  <td class="num"><?= e(kwh((float) $pair['model'], 1)) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr class="sum-row">
                <td>Summe</td>
                <td class="num"><?= e(kwh($sumActual, 2)) ?></td>
                <td class="num"><?= e(kwh($sumModel, 2)) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        <?php if ($gute !== null): ?>
          <?php formula('<mrow><mi>Güte</mi><mo>=</mo>' . mnum($sumModel, 2) . '<mo>÷</mo>' . mnum($sumActual, 2) . '<mo>=</mo>' . mnum($gute, 2) . '</mrow>'); ?>
        <?php endif; ?>
        <p>Im Fenster <?= count($pairs) === 1 ? 'liegt ein abgeschlossener Tag' : 'liegen ' . count($pairs) . ' abgeschlossene Tage' ?>. Die Güte fließt in die kommenden Tage nicht ein. Sie ersetzt den Eichfaktor nicht.</p>
      <?php else: ?>
        <p>Für die Güte liegt noch kein abgeschlossener Tag mit Ist und Prognose vor. Die Güte fließt in die kommenden Tage nicht ein.</p>
      <?php endif; ?>
    </section>
    <section>
      <h3>9. Die kommenden Tage</h3>
      <?php if ($regression): ?>
        <p>Jeder der nächsten Tage hat sein eigenes Rohmodell aus seinen eigenen DWD-Läufen. Die Prognose ist <?= e(num($regressA, 2)) ?> + <?= e(num($regressB, 2)) ?> × Rohmodell, mindestens 0. Die Abweichung ist die Streuung dieses Tages mal <?= e(num($scale, $factorDecimals)) ?>. Die Güte der vergangenen Tage wird darauf nicht noch einmal multipliziert.</p>
      <?php else: ?>
        <p>Jeder der nächsten Tage hat sein eigenes Rohmodell aus seinen eigenen DWD-Läufen. Die Prognose ist dieses Rohmodell mal dem aktuellen Eichfaktor <?= e(num($factor, $factorDecimals)) ?>. Die Abweichung ist die Streuung dieses Tages mal demselben Faktor. Die Güte der vergangenen Tage wird darauf nicht noch einmal multipliziert.</p>
      <?php endif; ?>
      <?php if ($ahead): ?>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Rechentabelle">
          <table class="table table-compact">
            <thead><tr>
              <th scope="col">Tag</th>
              <th scope="col">Rohmodell</th>
              <th scope="col">Rechnung</th>
              <th scope="col">Prognose</th>
            </tr></thead>
            <tbody>
              <?php foreach ($ahead as $row): ?>
                <?php
                  $rowRaw = isset($row['raw']) && $row['raw'] !== null ? (float) $row['raw'] : null;
                  $rowModel = isset($row['model']) && $row['model'] !== null ? (float) $row['model'] : null;
                  $rowSd = isset($row['sd']) && $row['sd'] !== null ? (float) $row['sd'] : null;
                  $walk = '—';
                  if ($rowRaw !== null && $regression) {
                      $walk = num($regressA, 2) . ' + ' . num($regressB, 2) . ' × ' . num($rowRaw, 2);
                  } elseif ($rowRaw !== null) {
                      $walk = num($rowRaw, 2) . ' × ' . num($factor, $factorDecimals);
                  }
                ?>
                <tr>
                  <td class="nowrap"><?= e(day_label((string) $row['day'])) ?></td>
                  <td class="num"><?= e(kwh($rowRaw, 2)) ?></td>
                  <td class="num"><?= e($walk) ?></td>
                  <td class="num"><?= e(Forecast::captionText($rowModel, $rowSd) ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p>Dieselbe Prognose steht in der Kachel, über der Energiekurve und in der Spalte Prognose. Die Kurve selbst folgt dem letzten Lauf, Stunde für Stunde. Die Zahl darüber ist der Mittelwert der Läufe nach dieser Rechnung.</p>
      <?php else: ?>
        <p>Für die kommenden Tage liegt noch kein gespeicherter Lauf vor.</p>
      <?php endif; ?>
    </section>
  </div>
  </div>
</dialog>
    <?php
}
