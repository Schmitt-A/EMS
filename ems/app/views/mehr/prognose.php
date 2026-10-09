<?php
declare(strict_types=1);
/**
 * Mehr → Prognose: Anlagenwerte, Eichfaktor und Regression, Adresse der DWD-Datei.
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
  <form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
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
<section class="card stack" aria-labelledby="dwd-title">
  <h3 class="card-title" id="dwd-title">DWD-Prognose</h3>
  <p class="body-sm muted">Die Strahlungsprognose kommt direkt vom Deutschen Wetterdienst, stündlich für etwa zehn Tage. Die App holt die Datei ungefähr alle 30 Minuten neu.</p>
  <form method="post" action="<?= e(url('/mehr')) ?>" class="stack">
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
