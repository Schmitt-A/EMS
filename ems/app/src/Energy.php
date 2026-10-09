<?php
declare(strict_types=1);

final class Energy
{
    public const VOLT = 230.0;
    public const KW_PER_AMP = 0.23;

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

    public static function suggest(array $live, array $charge, ?int $reportedPhases): array
    {
        $mode = (string) ($charge['mode'] ?? 'smart');
        $phaseMode = (string) ($charge['phase_mode'] ?? 'auto');
        $minA = min(16, max(6, (int) ($charge['min_a'] ?? 6)));
        $maxA = min(16, max($minA, (int) ($charge['max_a'] ?? 16)));
        $share = clamp_float((float) ($charge['solar_share'] ?? 100), 0, 100) / 100;
        $reserve = max(0, (float) ($charge['reserve_w'] ?? 0)) / 1000;
        $wallboxRaw = $live['wallbox_kw'] ?? null;
        $importRaw = $live['grid_import_kw'] ?? null;
        $exportRaw = $live['grid_export_kw'] ?? null;
        if ($wallboxRaw === null && $importRaw === null && $exportRaw === null && $mode !== 'schnell' && $mode !== 'aus') {
            return [
                'mode' => $mode,
                'p_soll_kw' => null,
                'surplus_kw' => $live['surplus_kw'] ?? null,
                'grid_signed_kw' => null,
                'delta_kw' => null,
                'target_kw' => null,
                'amps' => 0,
                'phases' => $reportedPhases ?: 1,
                'offered_kw' => 0.0,
                'reason' => 'Noch keine Zählerwerte.',
            ];
        }
        $wallbox = max(0, (float) ($wallboxRaw ?? 0));
        $grid = (float) ($importRaw ?? 0) - (float) ($exportRaw ?? 0);
        $pSoll = $wallbox - $grid - $reserve;
        $surplus = max(0, (float) ($live['surplus_kw'] ?? 0));
        $cap = $share >= 0.999 ? $surplus : ($share > 0 ? $surplus / $share : $pSoll);
        $reason = '';

        if ($mode === 'aus') {
            $target = 0.0;
            $reason = 'Modus Aus.';
        } elseif ($mode === 'schnell') {
            $phases = $phaseMode === '1p' ? 1 : 3;
            $target = self::KW_PER_AMP * $maxA * $phases;
            $reason = 'Schnellladen ohne PV-Grenze.';
        } else {
            $target = min(max(0, $pSoll), max(0, $cap));
            if ($share >= 0.999 && $target + 0.05 < max(0, $pSoll)) {
                $reason = 'Durch den Sonnenanteil auf den Überschuss begrenzt.';
            }
            if ($mode === 'smart_dauerhaft') {
                $floor = self::KW_PER_AMP * $minA * ($phaseMode === '3p' ? 3 : 1);
                if ($target < $floor) {
                    $target = $floor;
                    $reason = 'Dauerhaft mindestens ' . $minA . NNBSP . 'A.';
                }
            } elseif ($target < self::KW_PER_AMP * $minA) {
                $target = 0.0;
                $reason = 'Unter ' . num(self::KW_PER_AMP * $minA, 2) . NNBSP . 'kW, Laden würde aussetzen.';
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
            'p_soll_kw' => $pSoll,
            'surplus_kw' => $surplus,
            'grid_signed_kw' => $grid,
            'delta_kw' => $pSoll - $wallbox,
            'target_kw' => $target,
            'amps' => $amps,
            'phases' => $phases,
            'offered_kw' => $offered,
            'reason' => $reason,
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
        if ($target >= 3.68 && $target < 4.14) {
            return $reported === 3 ? 3 : 1;
        }
        return $target >= 4.14 ? 3 : 1;
    }

    /** @return array{0:int,1:int} */
    private static function quantize(float $target, int $phases, int $minA, int $maxA, bool $hold): array
    {
        if ($target <= 0 && !$hold) {
            return [0, $phases];
        }
        $raw = (int) round($target / (self::KW_PER_AMP * $phases));
        if (!$hold && $raw < $minA) {
            if ($phases === 3 && $target <= 3.68) {
                return self::quantize($target, 1, $minA, $maxA, false);
            }
            return [0, $phases];
        }
        $amps = max($hold ? $minA : 0, min($maxA, $raw));
        if ($phases === 1 && $amps === $maxA && $target > self::KW_PER_AMP * $maxA && $target < 4.14) {
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
            'schnell' => 'Schnell',
            'smart_dauerhaft' => 'Smart (+ Dauerhaft)',
            default => 'Smart',
        };
    }

    public static function modeText(string $mode): string
    {
        return match ($mode) {
            'aus' => 'Ladestrom 0 A. Die Wallbox bliebe aus.',
            'schnell' => 'Maximale Ladeleistung ohne Rücksicht auf den Solarüberschuss.',
            'smart_dauerhaft' => 'Es wird mindestens mit 6 A geladen. Überschuss hebt die Leistung an.',
            default => 'PV-Überschuss, Ziel nahe 0 W am Zähler. Unter 1,38 kW setzt der Vorschlag aus.',
        };
    }
}
