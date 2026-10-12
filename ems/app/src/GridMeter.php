<?php
declare(strict_types=1);

/**
 * Netzbezug und Einspeisung in Viertelstunden, wie ein Smart Meter abrechnet. Home Assistant hebt Leistungen nur als
 * Stundenmittel auf; der Recorder liest den Zähler alle 10 s und schreibt hier, was seit dem letzten Wert floss
 * (die Leistung des letzten Werts mal die Zeit). Liegen mehr als zwei Minuten dazwischen, etwa nach einem Neustart,
 * bleibt die Lücke leer statt geschätzt. Der Vergleich der Tarife nimmt diese Viertelstunden, wo es sie gibt.
 */
final class GridMeter
{
    private const KEY = 'grid_meter';
    private const MAX_GAP_S = 120;

    public function __construct(private ConfigStore $store) {}

    public function record(array $values, int $now): void
    {
        $import = isset($values['grid_import_kw']) && is_numeric($values['grid_import_kw']) ? max(0.0, (float) $values['grid_import_kw']) : null;
        $export = isset($values['grid_export_kw']) && is_numeric($values['grid_export_kw']) ? max(0.0, (float) $values['grid_export_kw']) : null;
        $state = $this->store->get(self::KEY, []);
        $last = is_array($state) ? (int) ($state['t'] ?? 0) : 0;
        if ($last > 0 && $now > $last && $now - $last <= self::MAX_GAP_S && ($state['import_kw'] ?? null) !== null) {
            $stmt = $this->store->pdo()->prepare(
                'INSERT INTO grid_quarters (start, import_kwh, export_kwh, seconds) VALUES (?, ?, ?, ?)
                 ON CONFLICT(start) DO UPDATE SET import_kwh = import_kwh + excluded.import_kwh, export_kwh = export_kwh + excluded.export_kwh, seconds = seconds + excluded.seconds'
            );
            foreach (self::split($last, $now) as [$quarter, $seconds]) {
                $stmt->execute([
                    $quarter,
                    (float) $state['import_kw'] * $seconds / 3600,
                    (float) ($state['export_kw'] ?? 0) * $seconds / 3600,
                    $seconds,
                ]);
            }
        }
        if ($import !== null || $export !== null) {
            $this->store->put(self::KEY, ['t' => $now, 'import_kw' => $import ?? 0.0, 'export_kw' => $export ?? 0.0]);
        }
    }

    /**
     * Teilt [from, to) an den Viertelstundengrenzen.
     *
     * @return list<array{0: int, 1: int}> [Beginn der Viertelstunde, Sekunden darin]
     */
    public static function split(int $from, int $to): array
    {
        $out = [];
        for ($t = $from; $t < $to; $t = $next) {
            $quarter = intdiv($t, 900) * 900;
            $next = min($to, $quarter + 900);
            $out[] = [$quarter, $next - $t];
        }
        return $out;
    }

    /**
     * Gemessene Viertelstunden zwischen $from und $to.
     *
     * @return array<int, array{import: float, export: float, seconds: int}>
     */
    public function quarters(int $from, int $to): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT start, import_kwh, export_kwh, seconds FROM grid_quarters WHERE start >= ? AND start < ? ORDER BY start');
        $stmt->execute([$from, $to]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(int) $row['start']] = ['import' => (float) $row['import_kwh'], 'export' => (float) $row['export_kwh'], 'seconds' => (int) $row['seconds']];
        }
        return $out;
    }

    /** Seit wann EMS selbst misst (null, solange nichts da ist). */
    public function since(): ?int
    {
        $first = $this->store->pdo()->query('SELECT MIN(start) FROM grid_quarters')->fetchColumn();
        return $first === null || $first === false ? null : (int) $first;
    }

    /**
     * Netzbezug für den Tarifvergleich: Stunden, die EMS fast ganz gemessen hat (mindestens 50 min), als Viertelstunden,
     * die übrigen aus den Stundenwerten von Home Assistant.
     *
     * @param array<int, float> $hours Stunde => kWh aus Home Assistant
     * @param array<int, array{import: float, export: float, seconds: int}> $quarters
     * @return array<int, array{0: float, 1: int}> Beginn => [kWh, Länge in s]
     */
    public static function merge(array $hours, array $quarters): array
    {
        $byHour = [];
        foreach ($quarters as $q => $row) {
            $byHour[intdiv($q, 3600) * 3600][$q] = $row;
        }
        $out = [];
        foreach ($hours as $hour => $kwh) {
            if (!isset($byHour[$hour])) {
                $out[$hour] = [(float) $kwh, 3600];
            }
        }
        foreach ($byHour as $hour => $rows) {
            if (array_sum(array_column($rows, 'seconds')) < 3000) {
                if (!isset($out[$hour]) && isset($hours[$hour])) {
                    $out[$hour] = [(float) $hours[$hour], 3600];
                }
                continue;
            }
            foreach ($rows as $q => $row) {
                $out[$q] = [(float) $row['import'], 900];
            }
        }
        ksort($out);
        return $out;
    }
}
