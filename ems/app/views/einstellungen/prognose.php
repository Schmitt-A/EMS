<?php
declare(strict_types=1);
/**
 * Einstellungen → Prognose: Anlagenwerte, Eichfaktor und Regression, der Jahresertrag von PVGIS (Standort, Verluste,
 * Wetterjahr) und die Adresse der DWD-Datei.
 * @var array $cfg
 * @var string $back
 */
$p = $cfg['plant'];
$factorHint = !empty($p['factor_locked']) ? 'Von Hand gesetzt, bleibt stehen.' : 'Aus der Kalibrierung.';
$regressHint = !empty($p['regress_locked']) ? 'Von Hand gesetzt, bleibt stehen.' : 'Aus der Kalibrierung.';
$dwd = 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/';
$catalog = 'https://www.dwd.de/DE/leistungen/met_verfahren_mosmix/mosmix_stationskatalog.cfg?view=nasPublication&nn=16102';
?>
<section class="card stack" aria-labelledby="plant-title">
  <h3 class="card-title" id="plant-title">Anlage</h3>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="plant"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows">
      <?= ui_number_row('kwp', 'Generator', (float) $p['kwp'], 2, 'kWp') ?>
      <?= ui_number_row('inverter_kw', 'Wechselrichter-Limit', (float) $p['inverter_kw'], 2, 'kW') ?>
      <?= ui_number_row('tilt', 'Neigung', (float) $p['tilt'], 1, 'Grad, 0 ist waagerecht.') ?>
      <?= ui_number_row('azimuth', 'Ausrichtung', (float) $p['azimuth'], 1, 'Grad, 180 ist Süd, 270 West.') ?>
      <?= ui_number_row('n_days', 'Kalibrierung', (float) $p['n_days'], 0, 'Abgeschlossene Tage, 3 bis 30.', ['inputmode' => 'numeric']) ?>
      <?= ui_number_row('factor', 'Eichfaktor', (float) $p['factor'], 3, $factorHint) ?>
      <?= ui_number_row('regress_a', 'Regression a', (float) $p['regress_a'], 2, 'kWh. ' . $regressHint, ['inputmode' => null]) ?>
      <?= ui_number_row('regress_b', 'Regression b', (float) $p['regress_b'], 3, $regressHint, ['inputmode' => null]) ?>
    </div>
    <p class="body-sm muted">Die Kalibrierung schaut so viele abgeschlossene Tage zurück. Die Güte auf der Prognoseseite wählt ihr Fenster selbst: 3 Tage, 7 Tage, Monat oder Quartal. Ein geänderter Eichfaktor oder eine geänderte Regression bleibt stehen, bis du sie wieder freigibst.</p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Anlage speichern</button>
      <button class="btn btn-secondary" type="submit" name="unlock_factor" value="1">Eichfaktor freigeben</button>
      <button class="btn btn-secondary" type="submit" name="unlock_regress" value="1">Regression neu schätzen</button>
    </div>
  </form>
</section>
<?php
// Jahresertrag von PVGIS für „Lohnt sich dynamisch?“: Stand, Standort (von Hand oder aus Home Assistant), Verluste, Wetterjahr.
$pvgis = (new YearCheck(store(), ha()))->status($p, time());
$pvgisSet = YearCheck::settings($p);
$ha = $pvgis['ha'];
$manual = is_numeric($p['lat'] ?? null) && is_numeric($p['lon'] ?? null);
$stand = match ($pvgis['source']) {
    'pvgis' => kwh($pvgis['year_kwh'], 0) . ' im Jahr' . ($pvgis['weather_year'] ? ', Wetter von ' . $pvgis['weather_year'] . ', Stunde für Stunde' : ', Monatsmittel') . ($pvgis['at'] ? '. Abgefragt am ' . date('d.m.Y', $pvgis['at']) . '.' : '.'),
    'demo' => 'Demo-Modus: Beispielwerte, ohne Abfrage.',
    'pending' => 'Wird gleich im Hintergrund abgefragt; bis dahin gilt eine Schätzung von ' . kwh($pvgis['year_kwh'], 0) . ' im Jahr.',
    default => 'Schätzung ' . kwh($pvgis['year_kwh'], 0) . ' im Jahr aus 950 kWh je kWp' . ($pvgis['error'] ? '. ' . $pvgis['error'] : '.'),
};
$years = [];
for ($year = YearCheck::LAST_YEAR; $year >= YearCheck::FIRST_YEAR; $year--) {
    $years[(string) $year] = (string) $year;
}
?>
<section class="card stack" aria-labelledby="pvgis-title">
  <h3 class="card-title" id="pvgis-title">Jahresertrag von PVGIS</h3>
  <p class="body-sm muted">„Lohnt sich dynamisch?“ unter Prognose → Strompreis rechnet mit dem Ertrag deiner Anlage Stunde für Stunde in einem echten Wetterjahr. Den liefert PVGIS, ein Dienst der Gemeinsamen Forschungsstelle der EU-Kommission, aus Satellitendaten für jeden Ort in Europa, Afrika und großen Teilen Asiens und Amerikas. EMS schickt dafür den Standort, kWp, Neigung und Ausrichtung von oben und die Verluste dorthin, im Hintergrund, höchstens alle sechs Monate und wenn sich etwas davon ändert.</p>
  <dl class="kv">
    <div><dt>Stand</dt><dd><?= e($stand) ?></dd></div>
    <div><dt>Standort</dt><dd><?= e($pvgis['location'] === null ? 'unbekannt, bitte unten eintragen' : YearCheck::place($pvgis['location']) . ($pvgis['location']['source'] === 'manual' ? ', von Hand' : ', aus Home Assistant')) ?></dd></div>
  </dl>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="pvgis"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows">
      <?= ui_form_row('Breite', ui_input('lat', $manual ? ui_field_num((float) $p['lat'], 4) : '', ['id' => 'f-lat', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => $ha ? ui_field_num($ha['lat'], 4) : '']), ['for' => 'f-lat', 'hint' => 'Grad, Norden positiv. Leer: der Standort von Home Assistant.']) ?>
      <?= ui_form_row('Länge', ui_input('lon', $manual ? ui_field_num((float) $p['lon'], 4) : '', ['id' => 'f-lon', 'class' => 'field-num', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => $ha ? ui_field_num($ha['lon'], 4) : '']), ['for' => 'f-lon', 'hint' => 'Grad, Osten positiv. So lässt sich auch eine Anlage an einem anderen Ort rechnen.']) ?>
      <?= ui_number_row('pvgis_loss', 'Systemverluste', $pvgisSet['loss'], 1, 'Prozent für Kabel, Wechselrichter, Verschmutzung und Alterung. PVGIS empfiehlt 14.') ?>
      <?= ui_form_row('Wetterjahr', ui_select('pvgis_year', $years, (string) $pvgisSet['year'], ['id' => 'f-pvgis-year']), ['for' => 'f-pvgis-year', 'hint' => 'Das Wetter dieses Jahres, Stunde für Stunde, mit trüben und sonnigen Tagen.']) ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit" name="do" value="save">PVGIS speichern</button>
      <button class="btn btn-secondary" type="submit" name="do" value="refresh">Neu abfragen</button>
    </div>
  </form>
  <p class="caption muted"><?= e(YearCheck::SOURCE) ?>, re.jrc.ec.europa.eu.</p>
</section>
<section class="card stack" aria-labelledby="dwd-title">
  <h3 class="card-title" id="dwd-title">DWD-Prognose</h3>
  <p class="body-sm muted">Die Strahlungsprognose kommt direkt vom Deutschen Wetterdienst, stündlich für etwa zehn Tage. Die App holt die Datei ungefähr alle 30 Minuten neu.</p>
  <form method="post" action="<?= e(url('/einstellungen')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="weather"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="form-rows"><?= ui_form_row('KMZ-Adresse', ui_input('weather_url', (new WeatherFeed(store()))->url(), ['id' => 'f-weather-url', 'type' => 'url', 'spellcheck' => 'false', 'autocomplete' => 'off']), ['for' => 'f-weather-url', 'stack' => true]) ?></div>
    <ol class="prose howto body-sm">
      <li>Öffne <a href="<?= e($dwd) ?>" target="_blank" rel="noopener">die Stationsliste MOSMIX_L</a>.</li>
      <li>Die Kennung steht im <a href="<?= e($catalog) ?>" target="_blank" rel="noopener">Stationskatalog</a>. Soonwald West ist F9519.</li>
      <li>Im Ordner der Station liegt kml/MOSMIX_L_LATEST_F9519.kmz.</li>
      <li>Die Adresse endet auf diese Datei und beginnt mit https://opendata.dwd.de/.</li>
    </ol>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Wetteradresse speichern</button></div>
  </form>
</section>
