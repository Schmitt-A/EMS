<?php
declare(strict_types=1);

final class Energy
{
    public const VOLT = 230.0;
    public const KW_PER_AMP = 0.23;
    /** Automatik: ab 4,14 kW (6 A dreiphasig) auf drei Phasen, unter 3,68 kW (16 A einphasig) auf eine; dazwischen bleibt die Phase. */
    public const PHASE_UP_KW = 4.14;
    public const PHASE_DOWN_KW = 3.68;

    /** @return array{0:?float,1:?string} */
    public static function powerToKw(?float $value, ?string $unit): array
    {
        if ($value === null) {
            return [null, null];
        }
        $u = strtolower(str_replace([' ', '·'], '', (string) $unit));
        if ($u === 'kw') {
            return [$value, null];
        }
        if ($u === 'w') {
            return [$value / 1000, null];
        }
        if ($u === '') {
            return [$value / 1000, 'Einheit fehlt, als Watt gelesen.'];
        }
        return [null, 'Einheit ' . $unit . ' ist keine Leistung.'];
    }

    public static function energyToKwh(?float $value, ?string $unit): array
    {
        if ($value === null) {
            return [null, null];
        }
        $u = strtolower(str_replace(' ', '', (string) $unit));
        return match ($u) {
            'kwh' => [$value, null],
            'wh' => [$value / 1000, null],
            'mwh' => [$value * 1000, null],
            default => [null, $unit ? 'Einheit ' . $unit . ' ist keine Energie.' : 'Energieeinheit fehlt.'],
        };
    }

    /** Strecke in km. Tesla BLE meldet Reichweite und Kilometerstand in Meilen, sofern das Sprachpaket nicht umrechnet. */
    public static function distanceKm(?float $value, ?string $unit): array
    {
        if ($value === null) {
            return [null, null];
        }
        return match (strtolower(str_replace([' ', '.'], '', (string) $unit))) {
            'km', '' => [$value, null],
            'mi', 'mile', 'miles' => [$value * 1.609344, null],
            'm' => [$value / 1000, null],
            default => [null, 'Einheit ' . $unit . ' ist keine Strecke.'],
        };
    }

    public static function splitSigned(?float $value, string $positive): array
    {
        if ($value === null) {
            return [null, null];
        }
        if ($positive === 'positive_charge' || $positive === 'positive_import') {
            return [max(0, $value), max(0, -$value)];
        }
        return [max(0, -$value), max(0, $value)];
    }

    public static function balance(array $p): array
    {
        $pv = self::nullable($p['pv_kw'] ?? null);
        $discharge = self::nullable($p['battery_discharge_kw'] ?? null);
        $charge = self::nullable($p['battery_charge_kw'] ?? null);
        $import = self::nullable($p['grid_import_kw'] ?? null);
        $export = self::nullable($p['grid_export_kw'] ?? null);
        $house = self::nullable($p['house_kw'] ?? null);
        $wallbox = self::nullable($p['wallbox_kw'] ?? null);
        $includes = (bool) ($p['house_includes_wallbox'] ?? true);
        $base = null;
        if ($house !== null) {
            $base = $includes ? max(0, $house - (float) ($wallbox ?? 0)) : max(0, $house);
        }
        $in = self::sumOrNull([$pv, $discharge, $import]);
        $out = self::sumOrNull([$base, $wallbox, $charge, $export]);
        $soc = self::nullable($p['battery_soc'] ?? null);
        $priority = (float) ($p['priority_soc'] ?? 80);
        $storage = $soc === null ? null : (($soc < $priority) ? (float) ($charge ?? 0) : 0.0);
        $surplus = ($pv === null && $base === null)
            ? null
            : max(0, (float) ($pv ?? 0) - (float) ($base ?? 0) - (float) ($storage ?? 0));
        return [
            'house_base_kw' => $base,
            'in_kw' => $in,
            'out_kw' => $out,
            'diff_kw' => ($in === null && $out === null) ? null : (float) ($in ?? 0) - (float) ($out ?? 0),
            'storage_priority_kw' => $storage,
            'surplus_kw' => $surplus,
        ];
    }

    /**
     * Energiefluss-Balken: Quellen (Netzbezug, Speicher entladen, PV) und Verbraucher (Haus, Ladepunkt,
     * Speicher laden, Einspeisung). Jede Seite füllt die ganze Breite für sich, auch wenn die Zähler nicht
     * genau dieselbe Summe melden. Der Balken zeigt die Quellen, PV geteilt in Eigenverbrauch und Einspeisung.
     */
    public static function flowBar(array $values, array $balance): array
    {
        $read = static fn (mixed $value): ?float => $value === null ? null : max(0.0, (float) $value);
        $parts = [
            'pv' => $read($values['pv_kw'] ?? null),
            'discharge' => $read($values['battery_discharge_kw'] ?? null),
            'import' => $read($values['grid_import_kw'] ?? null),
            'house' => $read($balance['house_base_kw'] ?? null),
            'wallbox' => $read($values['wallbox_kw'] ?? null),
            'charge' => $read($values['battery_charge_kw'] ?? null),
            'export' => $read($values['grid_export_kw'] ?? null),
        ];
        $soc = isset($values['battery_soc']) ? round((float) $values['battery_soc'], 1) : null;
        $empty = ['total_kw' => null, 'in_kw' => null, 'out_kw' => null, 'soc' => $soc, 'segments' => [], 'sources' => [], 'sinks' => []];
        if (count(array_filter($parts, static fn (?float $v): bool => $v !== null)) === 0) {
            return $empty;
        }
        $kw = array_map(static fn (?float $v): float => ($v ?? 0.0) < 0.01 ? 0.0 : (float) $v, $parts);
        $in = $kw['import'] + $kw['discharge'] + $kw['pv'];
        $out = $kw['house'] + $kw['wallbox'] + $kw['charge'] + $kw['export'];
        $pvExport = min($kw['export'], $kw['pv']);
        $span = static function (array $items, float $total): array {
            $list = [];
            $cursor = 0.0;
            foreach ($items as [$key, $value]) {
                if ($total <= 0.0 || $value <= 0.0) {
                    continue;
                }
                $from = $cursor / $total;
                $cursor += $value;
                $list[] = ['key' => $key, 'kw' => round($value, 3), 'from' => round($from, 4), 'to' => round(min(1.0, $cursor / $total), 4)];
            }
            return $list;
        };
        return [
            'total_kw' => round(max($in, $out), 3),
            'in_kw' => round($in, 3),
            'out_kw' => round($out, 3),
            'soc' => $soc,
            'segments' => $in > 0.0 ? [
                ['key' => 'grid_in', 'kw' => round($kw['import'], 3)],
                ['key' => 'battery', 'kw' => round($kw['discharge'], 3)],
                ['key' => 'solar', 'kw' => round($kw['pv'] - $pvExport, 3)],
                ['key' => 'grid_out', 'kw' => round($pvExport, 3)],
            ] : [],
            'sources' => $span([['grid', $kw['import']], ['battery', $kw['discharge']], ['pv', $kw['pv']]], $in),
            'sinks' => $span([['house', $kw['house']], ['wallbox', $kw['wallbox']], ['battery', $kw['charge']], ['grid', $kw['export']]], $out),
        ];
    }

    /** Sekunden bis zum Limit bei gleicher Leistung, null wenn etwas fehlt oder nicht geladen wird. */
    public static function timeToLimit(?float $soc, ?float $limit, ?float $capacityKwh, ?float $powerKw): ?int
    {
        if ($soc === null || $limit === null || $capacityKwh === null || $powerKw === null || $capacityKwh <= 0 || $powerKw < 0.05) {
            return null;
        }
        $missing = max(0.0, $limit - $soc) / 100 * $capacityKwh;
        return (int) round($missing / ($powerKw * 0.92) * 3600);
    }

    /** Reichweite beim Limit, linear aus der aktuellen Reichweite geschätzt. */
    public static function rangeAt(?float $rangeKm, ?float $soc, ?float $limit): ?float
    {
        if ($rangeKm === null || $soc === null || $limit === null || $soc < 1) {
            return null;
        }
        return round($rangeKm / $soc * $limit);
    }

    /**
     * Mittel der Hausleistung. Enthält der Hauszähler die Wallbox, wird sie Stunde für Stunde abgezogen.
     *
     * @param array<int, array{start?:int, kw?:float}> $house
     * @param array<int, array{start?:int, kw?:float}> $wallbox
     */
    public static function meanHouseBase(array $house, array $wallbox, bool $includesWallbox): ?float
    {
        if (!$house) {
            return null;
        }
        $wall = [];
        foreach ($wallbox as $row) {
            if (!isset($row['start'])) {
                continue;
            }
            $wall[(int) $row['start']] = max(0, (float) ($row['kw'] ?? 0));
        }
        $sum = 0.0;
        $n = 0;
        foreach ($house as $row) {
            if (!isset($row['start'])) {
                continue;
            }
            $base = max(0, (float) ($row['kw'] ?? 0));
            if ($includesWallbox) {
                $base = max(0, $base - (float) ($wall[(int) $row['start']] ?? 0));
            }
            $sum += $base;
            $n++;
        }
        return $n > 0 ? $sum / $n : null;
    }

    private static function nullable(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /** @param array<int, ?float> $parts */
    private static function sumOrNull(array $parts): ?float
    {
        $seen = false;
        $sum = 0.0;
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $seen = true;
            $sum += $part;
        }
        return $seen ? $sum : null;
    }

    /**
     * Zone des Hausspeichers für die Regelung: house (unter der Hausgrenze, der Speicher lädt zuerst),
     * car (das Auto hat den Überschuss), boost (der Speicher darf eine Ladung stützen) und start (Laden startet
     * auch ohne Sonne). Eine Grenze auf 100 % schaltet Stützung und Start ab. Ohne Ladestand: none.
     */
    public static function zone(?float $soc, array $strategy): string
    {
        if ($soc === null) {
            return 'none';
        }
        $priority = (float) ($strategy['priority_soc'] ?? 80);
        $buffer = max($priority, (float) ($strategy['car_buffer_soc'] ?? 100));
        $auto = max($buffer, (float) ($strategy['car_auto_soc'] ?? 100));
        if ($soc < $priority) {
            return 'house';
        }
        if ($auto < 100 && $soc >= $auto) {
            return 'start';
        }
        if ($buffer < 100 && $soc >= $buffer) {
            return 'boost';
        }
        return 'car';
    }

    /**
     * Vorschlag für den Modus aus $charge['mode']. Wie viel Sonne das Auto bekommt, regeln die Zonen des
     * Hausspeichers ($strategy aus battery_strategy): unter der Hausgrenze lädt der Speicher zuerst, darüber hat
     * das Auto den Überschuss, ab der Stützung hält der Speicher eine laufende Ladung, ab dem Start beginnt
     * sie auch ohne Sonne. 'flows' sagt, woher die Leistung käme und wohin der übrige Überschuss ginge.
     * $battery: Backup-Puffer, Schonen beim Netzladen und Entladeleistung, siehe allot().
     */
    public static function suggest(array $live, array $charge, ?int $reportedPhases, array $strategy = [], array $battery = []): array
    {
        $battery = self::batteryFor((string) ($charge['mode'] ?? 'smart'), $battery);
        $mode = (string) ($charge['mode'] ?? 'smart');
        $phaseMode = (string) ($charge['phase_mode'] ?? 'auto');
        $minA = min(16, max(6, (int) ($charge['min_a'] ?? 6)));
        $maxA = min(16, max($minA, (int) ($charge['max_a'] ?? 16)));
        $share = clamp_float((float) ($charge['solar_share'] ?? 100), 0, 100) / 100;
        $reserve = max(0, (float) ($charge['reserve_w'] ?? 0)) / 1000;
        $soc = isset($live['battery_soc']) ? (float) $live['battery_soc'] : null;
        $zone = self::zone($soc, $strategy + ['priority_soc' => (float) ($live['priority_soc'] ?? 80)]);
        $batteryIn = max(0.0, (float) ($live['battery_charge_kw'] ?? 0));
        $batteryOut = max(0.0, (float) ($live['battery_discharge_kw'] ?? 0));
        $wallboxRaw = $live['wallbox_kw'] ?? null;
        $importRaw = $live['grid_import_kw'] ?? null;
        $exportRaw = $live['grid_export_kw'] ?? null;
        $running = (float) ($wallboxRaw ?? 0) > 0.05 || strtolower((string) ($live['wallbox_car_raw'] ?? '')) === 'charging';
        if ($wallboxRaw === null && $importRaw === null && $exportRaw === null && $mode !== 'schnell' && $mode !== 'aus') {
            return [
                'mode' => $mode,
                'zone' => $zone,
                'p_soll_kw' => null,
                'surplus_kw' => $live['surplus_kw'] ?? null,
                'solar_kw' => null,
                'grid_signed_kw' => null,
                'delta_kw' => null,
                'target_kw' => null,
                'amps' => 0,
                'phases' => $reportedPhases ?: 1,
                'offered_kw' => 0.0,
                'reason' => 'Noch keine Zählerwerte.',
                'flows' => self::allot(0.0, $mode, $live, $battery),
            ];
        }
        $wallbox = max(0, (float) ($wallboxRaw ?? 0));
        $grid = (float) ($importRaw ?? 0) - (float) ($exportRaw ?? 0);
        // Am Zähler: was das Auto schon nimmt, plus Einspeisung, minus Bezug und Reserve. Ab der Hausgrenze darf
        // es auch nehmen, was der Speicher gerade lädt; bis zur Stützung zählt Entladen dagegen.
        $supported = $zone === 'boost' || $zone === 'start';
        $pSoll = $wallbox - $grid - $reserve + ($zone === 'house' ? 0.0 : $batteryIn) - ($supported ? 0.0 : $batteryOut);
        $surplus = max(0, (float) ($live['surplus_kw'] ?? 0));
        $solar = min(max(0.0, $pSoll), $surplus);
        $reason = '';

        if ($mode === 'aus') {
            $target = 0.0;
            $reason = 'Modus Aus.';
        } elseif ($mode === 'schnell') {
            $phases = $phaseMode === '1p' ? 1 : 3;
            $target = self::KW_PER_AMP * $maxA * $phases;
            $reason = 'Netzladen mit voller Leistung.';
        } else {
            $target = $share >= 0.999 ? $solar : ($share > 0 ? $solar / $share : max(0.0, $pSoll));
            if ($mode === 'smart_dauerhaft') {
                $floor = self::KW_PER_AMP * $minA * ($phaseMode === '3p' ? 3 : 1);
                if ($target < $floor) {
                    $target = $floor;
                    $reason = 'Dauerhaft mindestens ' . $minA . NNBSP . 'A.';
                }
            } elseif ($target < self::KW_PER_AMP * $minA) {
                if ($zone === 'start' || ($zone === 'boost' && $running)) {
                    // Der Speicher hält die kleinste Stufe, bis er auf die Grenze der Stützung fällt.
                    $target = self::KW_PER_AMP * $minA * ($phaseMode === '3p' ? 3 : 1);
                    $reason = $zone === 'start' ? 'Start ohne Sonne, der Speicher liefert.' : 'Der Speicher stützt die laufende Ladung.';
                } else {
                    $target = 0.0;
                    $reason = 'Unter ' . num(self::KW_PER_AMP * $minA, 2) . NNBSP . 'kW, Laden würde aussetzen.';
                }
            }
        }

        $phases = match ($phaseMode) {
            '1p' => 1,
            '3p' => 3,
            default => self::autoPhases($target, $reportedPhases, $mode),
        };
        if ($mode === 'aus') {
            $amps = 0;
            $phases = $reportedPhases ?: 1;
        } else {
            [$amps, $phases] = self::quantize($target, $phases, $minA, $maxA, $mode === 'smart_dauerhaft');
        }
        $offered = $amps === 0 ? 0.0 : self::KW_PER_AMP * $amps * $phases;

        return [
            'mode' => $mode,
            'zone' => $zone,
            'p_soll_kw' => $pSoll,
            'surplus_kw' => $surplus,
            'solar_kw' => $solar,
            'grid_signed_kw' => $grid,
            'delta_kw' => $pSoll - $wallbox,
            'target_kw' => $target,
            'amps' => $amps,
            'phases' => $phases,
            'offered_kw' => $offered,
            'reason' => $reason,
            'flows' => self::allot($offered, $mode, $live, $battery),
        ];
    }

    /**
     * Speicher-Angaben für einen Modus: geschont wird nur beim Netzladen. Ein Wechsel in einen anderen Modus
     * setzt den Puffer zurück, darum rechnen die anderen Modi mit dem Standardwert.
     *
     * @param array{reserve_soc?: ?float, protect?: bool, max_discharge_kw?: ?float} $battery
     */
    public static function batteryFor(string $mode, array $battery): array
    {
        $battery['protect'] = $mode === 'schnell' && !empty($battery['protect']);
        return $battery;
    }

    /**
     * Aufteilung für eine Ladeleistung, wie sie im Eigenverbrauch käme: Die Sonne geht zuerst ans Auto, der Rest
     * in den Speicher und ins Netz. Was dem Auto fehlt, deckt der Speicher, solange er über dem Backup-Puffer
     * liegt und nicht geschont wird (Netzladen mit angehobenem Puffer), höchstens mit seiner Entladeleistung
     * abzüglich dessen, was er schon fürs Haus liefert; den Rest das Netz. Ohne bekannte Entladeleistung bleibt
     * beim Netzladen offen, wie viel der Speicher schafft (mixed: erst Speicher, dann Netz).
     * spare ist der Überschuss über dem Haus, ohne Auto und Speicher.
     *
     * @param array{reserve_soc?: ?float, protect?: bool, max_discharge_kw?: ?float} $battery
     */
    public static function allot(float $carKw, string $mode, array $live, array $battery = []): array
    {
        $in = max(0.0, (float) ($live['battery_charge_kw'] ?? 0));
        $out = max(0.0, (float) ($live['battery_discharge_kw'] ?? 0));
        if (isset($live['pv_kw'], $live['house_base_kw'])) {
            $net = (float) $live['pv_kw'] - (float) $live['house_base_kw'];
        } else {
            $net = max(0.0, (float) ($live['wallbox_kw'] ?? 0)) + (float) ($live['grid_export_kw'] ?? 0) - (float) ($live['grid_import_kw'] ?? 0) + $in - $out;
        }
        $spare = max(0.0, $net);
        // Was das Haus über der Sonne braucht, liefert der Speicher schon; das fehlt ihm fürs Auto.
        $deficit = max(0.0, -$net);
        $car = max(0.0, $carKw);
        $soc = isset($live['battery_soc']) ? (float) $live['battery_soc'] : null;
        $sun = min($car, $spare);
        // Speist die Anlage gerade ein, nimmt der Speicher nicht mehr auf, als er schon lädt.
        $cap = (float) ($live['grid_export_kw'] ?? 0) > 0.05 ? $in : INF;
        $charge = $soc !== null && $soc < 99.5 ? min($spare - $sun, $cap) : 0.0;
        $short = $car - $sun;
        $fromBattery = $fromGrid = $mixed = 0.0;
        if ($short > 0.0) {
            $floor = (float) ($battery['reserve_soc'] ?? 0);
            $max = isset($battery['max_discharge_kw']) && (float) $battery['max_discharge_kw'] > 0 ? (float) $battery['max_discharge_kw'] : null;
            if ($soc === null || !empty($battery['protect']) || $soc <= $floor + 0.5) {
                $fromGrid = $short;
            } elseif ($max !== null) {
                $fromBattery = min($short, max(0.0, $max - $deficit));
                $fromGrid = $short - $fromBattery;
            } elseif ($mode === 'schnell') {
                $mixed = $short;
            } else {
                $fromBattery = $short;
            }
        }
        $r = static fn (float $v): float => round($v, 3);
        return [
            'spare_kw' => $r($spare),
            'car_kw' => $r($car),
            'sun_kw' => $r($sun),
            'battery_kw' => $r($fromBattery),
            'grid_kw' => $r($fromGrid),
            'mixed_kw' => $r($mixed),
            'charge_kw' => $r($charge),
            'export_kw' => $r(max(0.0, $spare - $sun - $charge)),
        ];
    }

    public static function latch(array $desired, array $state, int $now, array $charge): array
    {
        $amps = (int) ($desired['amps'] ?? 0);
        $phases = (int) ($desired['phases'] ?? 1);
        $latchedA = (int) ($state['amps'] ?? 0);
        $latchedP = (int) ($state['phases'] ?? 1);
        $pendingA = $state['pending_amps'] ?? null;
        $pendingP = $state['pending_phases'] ?? null;
        $since = isset($state['pending_since']) ? (int) $state['pending_since'] : 0;

        $same = $amps === $latchedA && ($amps === 0 || $phases === $latchedP);
        if ($same) {
            return self::latchResult($latchedA, $latchedP, null, null, null, 0, $desired);
        }

        $phaseChange = $latchedA > 0 && $amps > 0 && $phases !== $latchedP;
        $delay = $phaseChange
            ? (int) ($charge['switch_s'] ?? 60)
            : ($amps === 0 ? (int) ($charge['off_delay_s'] ?? 60) : ($latchedA === 0 ? (int) ($charge['on_delay_s'] ?? 60) : 0));
        $delay = $amps !== 0 && $latchedA !== 0 && !$phaseChange ? 0 : max(60, $delay);
        if ($amps !== 0 && $latchedA !== 0 && !$phaseChange) {
            $delay = 0;
        }
        // Die Verzögerungen fangen Wolken ab. Aus und Netzladen sind gewählt und gelten sofort, nur ein
        // Phasenwechsel wartet weiter die Schütz-Schutzzeit ab.
        if (!$phaseChange && in_array((string) ($desired['mode'] ?? ''), ['aus', 'schnell'], true)) {
            $delay = 0;
        }

        if ($pendingA !== $amps || $pendingP !== $phases || $since === 0) {
            $since = $now;
        }
        $wait = max(0, $delay - ($now - $since));
        if ($wait === 0) {
            return self::latchResult($amps, $phases, null, null, null, 0, $desired);
        }
        return self::latchResult($latchedA, $latchedP, $amps, $phases, $since, $wait, $desired);
    }

    private static function latchResult(int $amps, int $phases, ?int $pendingA, ?int $pendingP, ?int $since, int $wait, array $desired): array
    {
        $desired['latched_amps'] = $amps;
        $desired['latched_phases'] = $phases;
        $desired['pending_amps'] = $pendingA;
        $desired['pending_phases'] = $pendingP;
        $desired['pending_since'] = $since;
        $desired['wait_s'] = $wait;
        $desired['latched_kw'] = $amps === 0 ? 0.0 : self::KW_PER_AMP * $amps * $phases;
        return $desired;
    }

    private static function autoPhases(float $target, ?int $reported, string $mode): int
    {
        if ($mode === 'schnell') {
            return 3;
        }
        if ($target >= self::PHASE_DOWN_KW && $target < self::PHASE_UP_KW) {
            return $reported === 3 ? 3 : 1;
        }
        return $target >= self::PHASE_UP_KW ? 3 : 1;
    }

    /** @return array{0:int,1:int} */
    private static function quantize(float $target, int $phases, int $minA, int $maxA, bool $hold): array
    {
        if ($target <= 0 && !$hold) {
            return [0, $phases];
        }
        $raw = (int) round($target / (self::KW_PER_AMP * $phases));
        if (!$hold && $raw < $minA) {
            if ($phases === 3 && $target <= self::PHASE_DOWN_KW) {
                return self::quantize($target, 1, $minA, $maxA, false);
            }
            return [0, $phases];
        }
        $amps = max($hold ? $minA : 0, min($maxA, $raw));
        if ($phases === 1 && $amps === $maxA && $target > self::KW_PER_AMP * $maxA && $target < self::PHASE_UP_KW) {
            return [$maxA, 1];
        }
        return [$amps, $phases];
    }

    public static function carLabel(?string $raw): string
    {
        $key = strtolower(str_replace([' ', '-'], '_', (string) $raw));
        return match ($key) {
            'charging' => 'Laden',
            'idle' => 'Standby',
            'wait_car', 'waitcar' => 'Wartet auf Auto',
            'complete', 'completed' => 'Abgeschlossen',
            'error' => 'Fehler',
            'initializing' => 'Initialisierung',
            'unknown', '' => 'Nicht verbunden',
            default => $raw ?: 'Nicht verbunden',
        };
    }

    public static function phaseLabel(?string $raw): string
    {
        $key = strtolower((string) $raw);
        return match ($key) {
            'three_phases', '3', '3p' => '3-phasig',
            'one_phase', '1', '1p' => '1-phasig',
            'auto' => 'Automatisch',
            '' => '—',
            default => (string) $raw,
        };
    }

    public static function reportedPhases(?string $raw): ?int
    {
        return match (strtolower((string) $raw)) {
            'three_phases', '3', '3p' => 3,
            'one_phase', '1', '1p' => 1,
            default => null,
        };
    }

    public static function activity(?float $charge, ?float $discharge): string
    {
        $c = (float) ($charge ?? 0);
        $d = (float) ($discharge ?? 0);
        if ($c > 0.05 && $c >= $d) {
            return 'Laden';
        }
        if ($d > 0.05) {
            return 'Entladen';
        }
        return 'Ruhe';
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'aus' => 'Aus',
            'schnell' => 'Netzladen',
            'smart_dauerhaft' => 'Min+Solar',
            default => 'Nur Solar',
        };
    }

    public static function modeText(string $mode): string
    {
        return match ($mode) {
            'aus' => 'Ladestrom 0 A. Die Wallbox bliebe aus.',
            'schnell' => 'Volle Ladeleistung, was die Sonne nicht schafft, kommt aus dem Netz. Mit „Speicher schonen“ hebt die App den Backup-Puffer, solange das Auto lädt; sonst deckt der Speicher zuerst mit.',
            'smart_dauerhaft' => 'Lädt immer mit dem Mindeststrom. Sonnenüberschuss hebt die Leistung an, was fehlt, deckt der Speicher bis zum Backup-Puffer, danach das Netz.',
            default => 'Nur Sonnenüberschuss, Ziel nahe 0 W am Zähler. Unter 1,38 kW setzt der Vorschlag aus, außer der Speicher stützt die Ladung.',
        };
    }
}
