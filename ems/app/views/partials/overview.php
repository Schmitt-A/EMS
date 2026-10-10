<?php
declare(strict_types=1);
/**
 * Energieübersicht (8.1): Solaranteil, Energiepreis und CO₂ der Ladevorgänge für 30 Tage, das Jahr und gesamt.
 * Die Zeitraum-Reiter schalten per :has() ohne JS.
 * @var array $overview ['periods' => ['30' => summary, 'year' => summary, 'all' => summary], 'tariffs' => array]
 */
$periods = ['30' => '30 Tage', 'year' => 'Dieses Jahr', 'all' => 'Gesamt'];
$tariffs = $overview['tariffs'];
ob_start();
echo ui_segment('ov-span', 'Zeitraum', $periods, '30', ['id' => 'ov-span', 'compact' => true]);
foreach ($periods as $key => $name) {
    $sum = $overview['periods'][$key];
    echo '<div class="overview-panel stack" data-ov-panel="' . e((string) $key) . '">';
    if (!$sum['count']) {
        echo ui_empty('chart-column', 'In diesem Zeitraum gibt es noch keinen Ladevorgang.');
        echo '</div>';
        continue;
    }
    echo ui_metric_block('sun', 'solar', 'Solaranteil', metric($sum['solar_pct'], '%', 1), [
        num($sum['solar'], 0) . NNBSP . 'kWh Solar · ' . num($sum['grid'], 0) . NNBSP . 'kWh Netz',
    ]);
    echo ui_metric_block('coins', 'grid-in', 'Energiepreis', metric($sum['ct'], 'ct/kWh', 1), [
        euro($sum['cost']) . ' für ' . $sum['count'] . ' Ladevorgänge',
        'gespart ' . euro($sum['saved']) . ' gegen reinen Netzbezug',
    ]);
    echo ui_metric_block('leaf', 'grid-out', 'CO₂ gespart', metric($sum['co2_saved_kg'], 'kg', 0), [
        'Netzstrom verursachte ' . num($sum['co2_caused_kg'], 0) . NNBSP . 'kg',
    ]);
    echo '</div>';
}
echo '<dl class="kv">';
echo '<div><dt>Grundlage</dt><dd>Alle gespeicherten Ladevorgänge, Sonnenanteil mit der entgangenen Vergütung bewertet.</dd></div>';
echo '<div><dt>Netzbezug</dt><dd>' . ui_inline(e(ct((float) $tariffs['import_ct'])), ['href' => url('/einstellungen/tarif')], 'align-start') . '</dd></div>';
echo '<div><dt>Einspeisung</dt><dd>' . ui_inline(e(ct((float) $tariffs['export_ct'])), ['href' => url('/einstellungen/tarif')], 'align-start') . '</dd></div>';
echo '<div><dt>CO₂-Faktor</dt><dd>' . ui_inline(e(num((float) ($tariffs['co2_g_kwh'] ?? 380), 0)) . NNBSP . 'g/kWh', ['href' => url('/einstellungen/tarif')], 'align-start') . '<span class="sub">Strommix, mit dem Netzstrom und gesparter Sonnenstrom gerechnet werden.</span></dd></div>';
echo '</dl>';
echo ui_dialog('energy-overview', 'Energieübersicht', '<div class="overview stack">' . ob_get_clean() . '</div>');
