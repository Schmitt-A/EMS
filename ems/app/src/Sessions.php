<?php
declare(strict_types=1);

final class Sessions
{
    public function __construct(private ConfigStore $store) {}

    public function open(): ?array
    {
        $row = $this->store->pdo()->query('SELECT * FROM sessions WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public function tick(array $live, array $charge, int $now): void
    {
        $power = $live['wallbox_kw'];
        if ($power === null) {
            return;
        }
        $car = strtolower((string) ($live['wallbox_car_raw'] ?? ''));
        $active = $power > 0.2 || $car === 'charging';
        $pdo = $this->store->pdo();
        $open = $this->open();
        $runtime = $this->store->get('session_runtime', ['idle_since' => null]);
        $plug = $this->plugState($live['wallbox_car_raw'] ?? null, (float) $power, $now);

        if ($active) {
            if (!$open) {
                $stmt = $pdo->prepare('INSERT INTO sessions (started_at, vehicle, loadpoint, source, plug_at) VALUES (?, ?, ?, "recorder", ?)');
                $stmt->execute([date('c', $now), (string) ($live['vehicle_name'] ?? ''), (string) ($live['loadpoint_name'] ?? 'Wallbox'), $plug]);
                $open = $this->open();
            } elseif ($plug !== null && empty($open['plug_at'])) {
                $pdo->prepare('UPDATE sessions SET plug_at = ? WHERE id = ?')->execute([$plug, $open['id']]);
            }
            $runtime['idle_since'] = null;
            $this->store->put('session_runtime', $runtime);
            if (!$open) {
                return;
            }
            $last = $open['duration_s'] > 0 && !empty($runtime['last_sample']) ? (int) $runtime['last_sample'] : (int) strtotime((string) $open['started_at']);
            $dt = $now - $last;
            if ($dt > 0 && $dt <= 30) {
                $kwh = max(0, $power) * ($dt / 3600);
                $grid = min($kwh, max(0, (float) ($live['grid_import_kw'] ?? 0)) * ($dt / 3600));
                $solar = max(0, $kwh - $grid);
                $stmt = $pdo->prepare('UPDATE sessions SET energy_kwh = energy_kwh + ?, solar_kwh = solar_kwh + ?, grid_kwh = grid_kwh + ?, duration_s = duration_s + ?, vehicle = ? WHERE id = ?');
                $stmt->execute([$kwh, $solar, $grid, $dt, (string) ($live['vehicle_name'] ?? $open['vehicle']), $open['id']]);
            }
            $runtime['last_sample'] = $now;
            $this->store->put('session_runtime', $runtime);
            return;
        }

        if (!$open) {
            return;
        }
        $idleSince = $runtime['idle_since'] ?? $now;
        $runtime['idle_since'] = $idleSince;
        $runtime['last_sample'] = $now;
        $this->store->put('session_runtime', $runtime);
        if ($now - (int) $idleSince >= (int) ($charge['off_delay_s'] ?? 60)) {
            $stmt = $pdo->prepare('UPDATE sessions SET ended_at = ? WHERE id = ?');
            $stmt->execute([date('c', $now), $open['id']]);
        }
    }

    /**
     * Steckt das Auto an der Wallbox? Ladeleistung zählt immer als angesteckt. null, wenn der Status
     * nichts sagt (nicht zugeordnet, unknown, unavailable, Fehler).
     */
    public static function carConnected(?string $raw, ?float $powerKw = null): ?bool
    {
        if ($powerKw !== null && $powerKw > 0.2) {
            return true;
        }
        return match (strtolower(str_replace([' ', '-'], '_', trim((string) $raw)))) {
            'charging', 'wait_car', 'waitcar', 'complete', 'completed' => true,
            'idle', 'standby', 'disconnected', 'not_connected' => false,
            default => null,
        };
    }

    /** Seit wann das Auto angesteckt ist (kv plug_state), null wenn nicht. */
    private function plugState(?string $raw, float $power, int $now): ?string
    {
        $state = $this->store->get('plug_state', ['since' => null]);
        $since = is_array($state) ? ($state['since'] ?? null) : null;
        $connected = self::carConnected($raw, $power);
        if ($connected === true && $since === null) {
            $since = date('c', $now);
            $this->store->put('plug_state', ['since' => $since]);
        } elseif ($connected === false && $since !== null) {
            $since = null;
            $this->store->put('plug_state', ['since' => null]);
        }
        return $since;
    }

    /**
     * Ordnet Vorgänge ohne plug_at nachträglich ihrem Anstecken zu, soweit Home Assistant den Fahrzeugstatus
     * noch kennt (meist zehn Tage). Höchstens einmal pro Stunde, nur die letzten 30 Tage.
     */
    public function backfillPlugs(HaSource $ha, string $entity, int $now): int
    {
        if ($entity === '' || !$ha->configured() || (int) $this->store->get('plug_backfill', 0) > $now - 3600) {
            return 0;
        }
        $this->store->put('plug_backfill', $now);
        $stmt = $this->store->pdo()->prepare("SELECT id, started_at FROM sessions WHERE (plug_at IS NULL OR plug_at = '') AND started_at >= ? ORDER BY started_at ASC");
        $stmt->execute([date('c', $now - 30 * 86400)]);
        $rows = $stmt->fetchAll() ?: [];
        if (!$rows) {
            return 0;
        }
        try {
            $states = $ha->stateHistory($entity, (int) strtotime((string) $rows[0]['started_at']) - 86400, $now);
        } catch (Throwable) {
            return 0;
        }
        $periods = self::plugPeriods($states);
        $update = $this->store->pdo()->prepare('UPDATE sessions SET plug_at = ? WHERE id = ?');
        $done = 0;
        foreach ($rows as $row) {
            $start = (int) strtotime((string) $row['started_at']);
            foreach ($periods as [$from, $to]) {
                if ($start >= $from - 60 && ($to === null || $start < $to)) {
                    $update->execute([date('c', $from), $row['id']]);
                    $done++;
                    break;
                }
            }
        }
        return $done;
    }

    /** Zeiträume, in denen das Auto angesteckt war, aus dem Statusverlauf. @return list<array{0:int, 1:?int}> */
    public static function plugPeriods(array $states): array
    {
        $periods = [];
        $from = null;
        foreach ($states as $state) {
            $connected = self::carConnected($state['s'] ?? null);
            if ($connected === true && $from === null) {
                $from = (int) $state['t'];
            } elseif ($connected === false && $from !== null) {
                $periods[] = [$from, (int) $state['t']];
                $from = null;
            }
        }
        if ($from !== null) {
            $periods[] = [$from, null];
        }
        return $periods;
    }

    /**
     * Fasst die Ladezyklen eines Ansteckens zu einem Ladevorgang zusammen; ohne plug_at bleibt jeder Zyklus für sich.
     * @return list<array> Ladevorgänge, neueste zuerst, mit 'cycles' (älteste zuerst)
     */
    public static function groups(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['started_at'], (string) $b['started_at']));
        $groups = [];
        $index = [];
        foreach ($rows as $row) {
            $key = (string) ($row['plug_at'] ?? '');
            if ($key !== '' && isset($index[$key])) {
                $groups[$index[$key]][] = $row;
                continue;
            }
            $groups[] = [$row];
            if ($key !== '') {
                $index[$key] = count($groups) - 1;
            }
        }
        return array_reverse(array_map([self::class, 'merge'], $groups));
    }

    /** Ein Ladevorgang aus seinen Zyklen: Summen, erster Start, letztes Ende, offen solange ein Zyklus läuft. */
    public static function merge(array $cycles): array
    {
        $first = $cycles[0];
        $last = $cycles[count($cycles) - 1];
        $sum = ['energy_kwh' => 0.0, 'solar_kwh' => 0.0, 'grid_kwh' => 0.0, 'duration_s' => 0];
        $running = false;
        $odometer = null;
        $vehicle = '';
        foreach ($cycles as $cycle) {
            $sum['energy_kwh'] += (float) $cycle['energy_kwh'];
            $sum['solar_kwh'] += (float) $cycle['solar_kwh'];
            $sum['grid_kwh'] += (float) $cycle['grid_kwh'];
            $sum['duration_s'] += (int) $cycle['duration_s'];
            $running = $running || empty($cycle['ended_at']);
            if ($odometer === null && ($cycle['odometer'] ?? null) !== null && $cycle['odometer'] !== '') {
                $odometer = $cycle['odometer'];
            }
            if ($vehicle === '' && !empty($cycle['vehicle'])) {
                $vehicle = (string) $cycle['vehicle'];
            }
        }
        return array_merge($first, $sum, [
            'id' => (int) $first['id'],
            'ended_at' => $running ? null : $last['ended_at'],
            'vehicle' => $vehicle,
            'odometer' => $odometer,
            'meter_start' => $first['meter_start'] ?? null,
            'meter_end' => $last['meter_end'] ?? null,
            'cycles' => $cycles,
        ]);
    }

    /** Der Ladevorgang, zu dem ein Zyklus gehört. */
    public function group(int $id): ?array
    {
        $row = $this->find($id);
        if (!$row) {
            return null;
        }
        if (empty($row['plug_at'])) {
            return self::merge([$row]);
        }
        $stmt = $this->store->pdo()->prepare('SELECT * FROM sessions WHERE plug_at = ? ORDER BY started_at ASC');
        $stmt->execute([$row['plug_at']]);
        return self::merge($stmt->fetchAll() ?: [$row]);
    }

    /** Löscht einen abgeschlossenen Ladevorgang mit allen Zyklen. */
    public function deleteGroup(int $id): bool
    {
        $group = $this->group($id);
        if (!$group || $group['ended_at'] === null) {
            return false;
        }
        $stmt = $this->store->pdo()->prepare('DELETE FROM sessions WHERE id = ? AND ended_at IS NOT NULL');
        foreach ($group['cycles'] as $cycle) {
            $stmt->execute([$cycle['id']]);
        }
        return true;
    }

    /**
     * Ältere Recorder-Zeilen tragen den Wallbox-Status („Laden“) als Fahrzeug. Einmalig auf den Fahrzeugnamen setzen.
     */
    public function repairVehicleNames(string $name): void
    {
        if ($this->store->get('vehicle_repair', '') === '1' || trim($name) === '') {
            return;
        }
        $labels = ['Laden', 'Standby', 'Wartet auf Auto', 'Abgeschlossen', 'Fehler', 'Initialisierung', 'Nicht verbunden', ''];
        $marks = implode(', ', array_fill(0, count($labels), '?'));
        $this->store->pdo()->prepare("UPDATE sessions SET vehicle = ? WHERE source = 'recorder' AND (vehicle IS NULL OR vehicle IN (" . $marks . '))')
            ->execute(array_merge([$name], $labels));
        $this->store->put('vehicle_repair', '1');
    }

    /** Eine Wallbox, ein Auto: bekommt das Auto einen neuen Namen, ziehen die Vorgänge des Recorders mit. */
    public function renameVehicle(string $from, string $to): void
    {
        if ($from === $to || trim($to) === '') {
            return;
        }
        $this->store->pdo()->prepare("UPDATE sessions SET vehicle = ? WHERE source = 'recorder' AND vehicle = ?")->execute([$to, $from]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->store->pdo()->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Löscht einen abgeschlossenen Vorgang. Der laufende Vorgang bleibt, sonst legt der Recorder ihn sofort neu an. */
    public function delete(int $id): bool
    {
        $stmt = $this->store->pdo()->prepare('DELETE FROM sessions WHERE id = ? AND ended_at IS NOT NULL');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function all(): array
    {
        return $this->store->pdo()->query('SELECT * FROM sessions ORDER BY started_at DESC')->fetchAll() ?: [];
    }

    public function latest(): ?array
    {
        $row = $this->store->pdo()->query('SELECT * FROM sessions ORDER BY started_at DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public function month(string $month): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
        return $this->between($start, $start->modify('+1 month'));
    }

    public function between(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT * FROM sessions WHERE started_at >= ? AND started_at < ? ORDER BY started_at DESC');
        $stmt->execute([$start->format('c'), $end->format('c')]);
        return $stmt->fetchAll() ?: [];
    }

    public function setOdometer(int $id, ?float $km): void
    {
        if ($id <= 0) {
            return;
        }
        $stmt = $this->store->pdo()->prepare('UPDATE sessions SET odometer = ? WHERE id = ?');
        $stmt->execute([$km, $id]);
    }

    public function import(HaSource $ha): array
    {
        $state = $ha->state('sensor.ems_ladelog_historie');
        $sessions = is_array($state) ? ($state['attributes']['sessions'] ?? null) : null;
        if (!is_array($sessions)) {
            return ['ok' => false, 'message' => 'Der Sensor sensor.ems_ladelog_historie hat keine Vorgänge.'];
        }
        $stmt = $this->store->pdo()->prepare(
            'INSERT OR IGNORE INTO sessions (started_at, ended_at, vehicle, loadpoint, energy_kwh, solar_kwh, grid_kwh, duration_s, source, odometer, meter_start, meter_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "import", ?, ?, ?)'
        );
        $count = 0;
        foreach ($sessions as $session) {
            if (!is_array($session) || empty($session['created'])) {
                continue;
            }
            $created = preg_replace('/\.(\d{6})\d+/', '.$1', (string) $session['created']) ?? (string) $session['created'];
            $parts = explode(':', (string) ($session['duration_fmt'] ?? '0:00'));
            $duration = ((int) ($parts[0] ?? 0)) * 3600 + ((int) ($parts[1] ?? 0)) * 60;
            $stmt->execute([
                $created,
                null,
                (string) ($session['vehicle'] ?? ''),
                (string) ($session['loadpoint'] ?? 'Wallbox'),
                (float) ($session['chargedEnergy'] ?? 0),
                (float) ($session['solarEnergy'] ?? 0),
                (float) ($session['gridEnergy'] ?? 0),
                $duration,
                self::optionalNumber($session['odometer'] ?? $session['vehicleOdometer'] ?? null),
                self::optionalNumber($session['meterStart'] ?? $session['meter_start'] ?? null),
                self::optionalNumber($session['meterEnd'] ?? $session['meter_end'] ?? null),
            ]);
            $count += $stmt->rowCount();
        }
        return ['ok' => true, 'message' => $count . ' Vorgänge übernommen.'];
    }

    private static function optionalNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /** CO₂ in kg: gespart ist Sonnenstrom mal Netzfaktor, verursacht ist Netzstrom mal Netzfaktor. */
    public static function co2(array $row, float $gramsPerKwh): array
    {
        return [
            'saved_kg' => (float) $row['solar_kwh'] * $gramsPerKwh / 1000,
            'caused_kg' => (float) $row['grid_kwh'] * $gramsPerKwh / 1000,
        ];
    }

    /** Summen über Vorgänge: Energie, Sonne, Netz, Kosten, Ø-Preis, Sonnenanteil, Ersparnis und CO₂. */
    public static function summary(array $rows, array $tariffs): array
    {
        $factor = (float) ($tariffs['co2_g_kwh'] ?? 380);
        $out = ['count' => count($rows), 'energy' => 0.0, 'solar' => 0.0, 'grid' => 0.0, 'cost' => 0.0, 'reference' => 0.0, 'saved' => 0.0, 'duration' => 0, 'co2_saved_kg' => 0.0, 'co2_caused_kg' => 0.0];
        foreach ($rows as $row) {
            $cost = self::cost($row, $tariffs);
            $co2 = self::co2($row, $factor);
            $out['energy'] += (float) $row['energy_kwh'];
            $out['solar'] += (float) $row['solar_kwh'];
            $out['grid'] += (float) $row['grid_kwh'];
            $out['cost'] += $cost['cost'];
            $out['reference'] += $cost['reference'];
            $out['saved'] += $cost['saved'];
            $out['duration'] += (int) $row['duration_s'];
            $out['co2_saved_kg'] += $co2['saved_kg'];
            $out['co2_caused_kg'] += $co2['caused_kg'];
        }
        $out['solar_pct'] = $out['energy'] > 0 ? $out['solar'] / $out['energy'] * 100 : null;
        $out['ct'] = $out['energy'] > 0 ? $out['cost'] / $out['energy'] * 100 : null;
        // Gezählt werden Ladevorgänge (Anstecken bis Abstecken), nicht einzelne Zyklen.
        $out['cycles'] = $out['count'];
        $out['count'] = count(self::groups($rows));
        return $out;
    }

    /**
     * Diagrammdaten für Ladevorgänge: Monat nach Tagen, Jahr nach Monaten, Gesamt nach Jahren.
     * Kennzahl energy (Sonne/Netz in kWh), cost (Kosten in €, dazu Ø ct/kWh) oder co2 (gespart/verursacht in kg).
     */
    public static function chart(array $rows, string $span, string $metric, array $tariffs, string $month, int $year): array
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $factor = (float) ($tariffs['co2_g_kwh'] ?? 380);
        $names = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        $labels = [];
        $keys = [];
        if ($span === 'all') {
            $years = [];
            foreach ($rows as $row) {
                $years[] = (int) substr((string) $row['started_at'], 0, 4);
            }
            $first = $years ? min($years) : $year;
            for ($y = $first; $y <= max($year, $years ? max($years) : $year); $y++) {
                $keys[] = (string) $y;
                $labels[] = (string) $y;
            }
        } elseif ($span === 'year') {
            for ($m = 1; $m <= 12; $m++) {
                $keys[] = sprintf('%04d-%02d', $year, $m);
                $labels[] = $names[$m];
            }
        } else {
            $start = new DateTimeImmutable($month . '-01', $tz);
            for ($d = 1; $d <= (int) $start->format('t'); $d++) {
                $keys[] = sprintf('%s-%02d', $month, $d);
                $labels[] = (string) $d;
            }
        }
        $index = array_flip($keys);
        $a = array_fill(0, count($keys), 0.0);
        $b = array_fill(0, count($keys), 0.0);
        $energy = array_fill(0, count($keys), 0.0);
        foreach ($rows as $row) {
            try {
                $when = (new DateTimeImmutable((string) $row['started_at']))->setTimezone($tz);
            } catch (Throwable) {
                continue;
            }
            $key = match ($span) {
                'all' => $when->format('Y'),
                'year' => $when->format('Y-m'),
                default => $when->format('Y-m-d'),
            };
            if (!isset($index[$key])) {
                continue;
            }
            $i = $index[$key];
            $cost = self::cost($row, $tariffs);
            $energy[$i] += (float) $row['energy_kwh'];
            if ($metric === 'cost') {
                $a[$i] += (float) $row['solar_kwh'] * (float) ($tariffs['export_ct'] ?? 0) / 100;
                $b[$i] += (float) $row['grid_kwh'] * (float) ($tariffs['import_ct'] ?? 0) / 100;
            } elseif ($metric === 'co2') {
                $co2 = self::co2($row, $factor);
                $a[$i] += $co2['saved_kg'];
                $b[$i] += $co2['caused_kg'];
            } else {
                $a[$i] += (float) $row['solar_kwh'];
                $b[$i] += (float) $row['grid_kwh'];
            }
        }
        $round = static fn (array $values): array => array_map(static fn (float $v): float => round($v, 2), $values);
        $series = match ($metric) {
            'cost' => [
                ['key' => 'solar', 'label' => 'Sonne (entgangene Vergütung)', 'color' => 'solar', 'type' => 'bar', 'stack' => 'x', 'data' => $round($a)],
                ['key' => 'grid', 'label' => 'Netz', 'color' => 'grid-in', 'type' => 'bar', 'stack' => 'x', 'data' => $round($b)],
            ],
            'co2' => [
                ['key' => 'saved', 'label' => 'Gespart (Sonne)', 'color' => 'grid-out', 'type' => 'bar', 'stack' => 'x', 'data' => $round($a)],
                ['key' => 'caused', 'label' => 'Verursacht (Netz)', 'color' => 'grid-in', 'type' => 'bar', 'stack' => 'x', 'data' => $round($b)],
            ],
            default => [
                ['key' => 'solar', 'label' => 'Sonne', 'color' => 'solar', 'type' => 'bar', 'stack' => 'x', 'data' => $round($a)],
                ['key' => 'grid', 'label' => 'Netz', 'color' => 'grid-in', 'type' => 'bar', 'stack' => 'x', 'data' => $round($b)],
            ],
        };
        if ($metric === 'cost') {
            $ct = [];
            foreach ($energy as $i => $kwh) {
                $ct[] = $kwh > 0 ? round(($a[$i] + $b[$i]) / $kwh * 100, 1) : null;
            }
            $series[] = ['key' => 'ct', 'label' => 'Ø Preis', 'color' => 'muted', 'type' => 'line', 'axis' => 'y1', 'width' => 'thin', 'unit' => 'ct/kWh', 'decimals' => 1, 'data' => $ct];
        }
        $last = max(0, count($keys) - 1);
        $now = new DateTimeImmutable('now', $tz);
        $focus = match ($span) {
            'month' => $now->format('Y-m') === $month ? (int) $now->format('j') - 1 : $last,
            default => $last,
        };
        $window = $span === 'month' ? 10 : 12;
        $hi = min($last, max($focus, $window - 1));
        return [
            'axis' => 'category',
            'stacked' => true,
            'labels' => $labels,
            'view' => [max(0, $hi - $window + 1), $hi],
            'bounds' => [0, $last],
            'yTitle' => match ($metric) { 'cost' => 'Kosten (€)', 'co2' => 'CO₂ (kg)', default => 'Energie (kWh)' },
            'y1Title' => $metric === 'cost' ? 'Ø Preis (ct/kWh)' : null,
            'series' => $series,
        ];
    }

    public static function cost(array $row, array $tariffs): array
    {
        $energy = (float) $row['energy_kwh'];
        $solar = (float) $row['solar_kwh'];
        $grid = (float) $row['grid_kwh'];
        $import = (float) ($tariffs['import_ct'] ?? 0) / 100;
        $export = (float) ($tariffs['export_ct'] ?? 0) / 100;
        $cost = $grid * $import + $solar * $export;
        $reference = $energy * $import;
        return [
            'cost' => $cost,
            'reference' => $reference,
            'saved' => $reference - $cost,
            'ct' => $energy > 0 ? ($cost / $energy) * 100 : 0,
            'solar_pct' => $energy > 0 ? ($solar / $energy) * 100 : 0,
        ];
    }
}
