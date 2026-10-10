<?php
declare(strict_types=1);

/**
 * Ladeziel für den laufenden Ladevorgang, wie Energie- und SoC-Limit in evcc: eine Energiemenge seit dem
 * Anstecken, eine Uhrzeit, ein Ladestand oder eine Reichweite des Autos. Ist es erreicht, wechselt die App in den
 * Folgemodus (Aus stoppt die Wallbox); beim Abstecken verfällt es. Gespeichert in kv.charge_target. Mit dem
 * Verbrauch des Autos nennt jedes Ziel auch die Kilometer.
 */
final class Target
{
    public const KEY = 'charge_target';
    /** Zuletzt erreichtes Ziel (Zeit, Beschreibung, Folgemodus), für die Mitteilung „Ladeziel erreicht“. */
    public const DONE = 'charge_target_done';
    public const THEN = ['aus', 'smart', 'smart_dauerhaft'];

    public static function get(ConfigStore $store): ?array
    {
        $target = $store->get(self::KEY, []);
        return is_array($target) && isset($target['type'], $target['value']) ? $target : null;
    }

    /**
     * Ziel aus Formular oder JSON: type energy (kwh), time (until „HH:MM“ oder hours), soc (soc) oder range (km),
     * dazu then.
     *
     * @return array{target: ?array, error: ?string}
     */
    public static function build(array $input, int $now, ?float $carSoc, ?float $carRange = null): array
    {
        $number = static function (mixed $value): ?float {
            $raw = str_replace(',', '.', trim((string) $value));
            return $raw === '' || !is_numeric($raw) ? null : (float) $raw;
        };
        $then = (string) ($input['then'] ?? 'smart');
        $then = in_array($then, self::THEN, true) ? $then : 'smart';
        $type = (string) ($input['type'] ?? '');
        $value = null;
        $extra = [];
        if ($type === 'energy') {
            $kwh = $number($input['kwh'] ?? null);
            if ($kwh === null || $kwh < 1 || $kwh > 150) {
                return ['target' => null, 'error' => 'Bitte eine Energiemenge zwischen 1 und 150 kWh angeben.'];
            }
            $value = round($kwh, 1);
        } elseif ($type === 'time') {
            $hours = $number($input['hours'] ?? null);
            $until = trim((string) ($input['until'] ?? ''));
            if ($hours !== null && $hours > 0) {
                $value = $now + (int) round(min(24, $hours) * 3600);
            } elseif (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $until, $m)) {
                $tz = new DateTimeZone('Europe/Berlin');
                $at = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime((int) $m[1], (int) $m[2]);
                // Eine Uhrzeit, die heute schon vorbei ist, meint morgen.
                if ($at->getTimestamp() <= $now) {
                    $at = $at->modify('+1 day');
                }
                $value = $at->getTimestamp();
            } else {
                return ['target' => null, 'error' => 'Bitte eine Uhrzeit wie 18:30 angeben.'];
            }
        } elseif ($type === 'soc') {
            $soc = $number($input['soc'] ?? null);
            if ($soc === null || $soc < 1 || $soc > 100) {
                return ['target' => null, 'error' => 'Bitte einen Ladestand zwischen 1 und 100 % angeben.'];
            }
            if ($carSoc === null) {
                return ['target' => null, 'error' => 'Für ein Ziel nach Ladestand braucht die App den Ladestand des Autos.'];
            }
            $value = (float) round($soc);
            $extra['start_soc'] = $carSoc;
        } elseif ($type === 'range') {
            $km = $number($input['km'] ?? null);
            if ($km === null || $km < 10 || $km > 1500) {
                return ['target' => null, 'error' => 'Bitte eine Reichweite zwischen 10 und 1500 km angeben.'];
            }
            if ($carRange === null) {
                return ['target' => null, 'error' => 'Für ein Ziel nach Reichweite braucht die App die Reichweite des Autos, etwa über Tesla BLE, oder Ladestand, Akku und Verbrauch.'];
            }
            $value = (float) round($km);
            $extra['start_range'] = $carRange;
        } else {
            return ['target' => null, 'error' => 'Unbekannte Zielart.'];
        }
        return ['target' => ['type' => $type, 'value' => $value, 'then' => $then, 'set_at' => $now] + $extra, 'error' => null];
    }

    /**
     * Fortschritt, Restzeit und Sätze für die Karte. $in: session_kwh (seit dem Anstecken), power_kw (jetzt),
     * plan_kw (was der Modus einstellen würde), car_soc, capacity_kwh, range_km und consumption_kwh (kWh/100 km).
     * Mit Verbrauch nennt das Ziel die Kilometer: was geladen ist, was das Ziel bringt, die Reichweite danach.
     */
    public static function view(?array $target, array $in, int $now): ?array
    {
        if ($target === null) {
            return null;
        }
        $kwh = max(0.0, (float) ($in['session_kwh'] ?? 0));
        $power = (float) ($in['power_kw'] ?? 0);
        $plan = (float) ($in['plan_kw'] ?? 0);
        $rate = $power > 0.1 ? $power : ($plan > 0.1 ? $plan : null);
        $use = isset($in['consumption_kwh']) && is_numeric($in['consumption_kwh']) ? (float) $in['consumption_kwh'] : null;
        $soc = isset($in['car_soc']) && is_numeric($in['car_soc']) ? (float) $in['car_soc'] : null;
        $range = isset($in['range_km']) && is_numeric($in['range_km']) ? (float) $in['range_km'] : null;
        $capacity = isset($in['capacity_kwh']) && is_numeric($in['capacity_kwh']) && (float) $in['capacity_kwh'] > 0 ? (float) $in['capacity_kwh'] : null;
        // „≈ 80 km“ hinter einer Energiemenge oder einem Ziel, leer ohne Verbrauch.
        $km = static fn (?float $value): string => $value === null ? '' : ' ≈ ' . with_unit($value, 0, 'km');
        $tag = static fn (?float $value): string => $value === null ? '' : ' · ≈ ' . with_unit($value, 0, 'km');
        $loaded = 'bisher ' . kwh($kwh) . $km(Energy::kmFromKwh($kwh, $use));
        $value = (float) $target['value'];
        $remaining = null;
        $reached = false;
        $progress = 0.0;
        switch ((string) $target['type']) {
            case 'energy':
                $left = max(0.0, $value - $kwh);
                $reached = $kwh >= $value - 0.005;
                $progress = $value > 0 ? $kwh / $value : 1.0;
                $remaining = $rate === null ? null : (int) round($left / $rate * 3600);
                $label = 'Ziel ' . kwh($value) . $tag(Energy::kmFromKwh($value, $use));
                $text = $loaded . ($reached ? '' : ($remaining === null ? ', Dauer offen' : ', noch ca. ' . duration_clock($remaining)));
                break;
            case 'time':
                $until = (int) $value;
                $start = (int) ($target['set_at'] ?? $now);
                $remaining = max(0, $until - $now);
                $reached = $now >= $until;
                $progress = $until > $start ? ($now - $start) / ($until - $start) : 1.0;
                // Bis zur Uhrzeit bei der Leistung von jetzt (ohne Ladung der des Modus), höchstens bis zum Limit nicht gerechnet.
                $expected = $rate === null ? null : Energy::kmFromKwh($kwh + $rate * $remaining / 3600, $use);
                $label = 'Bis ' . date('H:i', $until) . ($reached ? '' : $tag($expected));
                $text = ($reached ? 'Zeit erreicht' : 'noch ' . self::countdown($remaining)) . ' · ' . $loaded;
                break;
            case 'range':
                $from = (float) ($target['start_range'] ?? ($range ?? 0));
                $reached = $range !== null && $range >= $value;
                $progress = $range === null ? 0.0 : ($value > $from ? ($range - $from) / ($value - $from) : 1.0);
                $missing = $range === null ? null : max(0.0, $value - $range);
                $need = Energy::kwhForKm($missing, $use);
                $remaining = $need === null || $rate === null ? null : (int) round($need / $rate * 3600);
                $label = 'Bis ' . with_unit($value, 0, 'km');
                $text = $range === null ? 'Reichweite unbekannt'
                    : 'jetzt ' . with_unit($range, 0, 'km') . ($reached ? '' : ' · noch ' . with_unit($missing, 0, 'km') . ($remaining === null ? '' : ', ca. ' . duration_clock($remaining)));
                break;
            default:
                $from = (float) ($target['start_soc'] ?? ($soc ?? 0));
                $reached = $soc !== null && $soc >= $value;
                $progress = $soc === null ? 0.0 : ($value > $from ? ($soc - $from) / ($value - $from) : 1.0);
                if ($soc !== null && $capacity !== null && $rate !== null) {
                    // Ladeverluste wie bei der Restzeit bis zum Limit.
                    $remaining = (int) round(max(0.0, $value - $soc) / 100 * $capacity / ($rate * Energy::CHARGE_EFFICIENCY) * 3600);
                }
                // Reichweite beim Ziel und was bis dahin dazukommt, aus Akku und Verbrauch.
                $add = $soc !== null && $capacity !== null && $use !== null ? round(max(0.0, $value - $soc) / 100 * $capacity / $use * 100) : null;
                $at = $range !== null && $soc !== null ? Energy::rangeAt($range, $soc, $value) : ($capacity !== null && $use !== null ? round($value / 100 * $capacity / $use * 100) : null);
                $label = 'Bis ' . pct($value) . $tag($at);
                $text = ($soc === null ? 'Ladestand unbekannt' : 'jetzt ' . pct($soc))
                    . ($reached ? '' : ($add !== null ? ' · noch ≈ ' . with_unit($add, 0, 'km') : '') . ($remaining === null ? '' : ', ca. ' . duration_clock($remaining)));
        }
        return [
            'type' => (string) $target['type'],
            'label' => $label,
            'text' => $text,
            'then' => (string) $target['then'],
            'then_text' => 'Danach ' . Energy::modeLabel((string) $target['then']),
            'progress' => round(max(0.0, min(1.0, $progress)), 3),
            'remaining_s' => $remaining,
            'reached' => $reached,
        ];
    }

    /** Countdown als 1:23:45 oder 12:05. */
    public static function countdown(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** Werte für view() aus dem Snapshot. */
    public static function inputs(array $snap): array
    {
        return [
            'session_kwh' => (float) ($snap['chargepoint']['session_kwh'] ?? 0),
            'power_kw' => (float) ($snap['values']['wallbox_kw'] ?? 0),
            'plan_kw' => (float) ($snap['suggestion']['offered_kw'] ?? 0),
            'car_soc' => $snap['vehicle']['soc'] ?? null,
            'capacity_kwh' => $snap['vehicle']['capacity_kwh'] ?? null,
            'range_km' => $snap['vehicle']['range_km'] ?? null,
            'consumption_kwh' => $snap['vehicle']['consumption_kwh'] ?? null,
        ];
    }

    /**
     * Recorder: beim Abstecken verfällt das Ziel und der Modus geht, falls eingestellt, auf „Nach dem Abstecken“.
     * Ist das Ziel erreicht, gilt der Folgemodus. Gibt den neuen Modus und eine Protokollzeile zurück, sonst null.
     *
     * @return ?array{mode: string, text: string}
     */
    public static function check(ConfigStore $store, array $snap, int $now, ?bool $wasConnected): ?array
    {
        $cfg = $snap['cfg'];
        $values = $snap['values'];
        $mode = (string) ($cfg['charge']['mode'] ?? 'smart');
        $target = self::get($store);
        $connected = Sessions::carConnected($values['wallbox_car_raw'] ?? null, isset($values['wallbox_kw']) ? (float) $values['wallbox_kw'] : null);
        if ($connected === false && $wasConnected === true) {
            $after = (string) ($cfg['charge']['after_unplug'] ?? '');
            $parts = ['Auto abgesteckt.'];
            if ($target !== null) {
                $store->put(self::KEY, []);
                $parts[] = 'Ladeziel gelöscht.';
            }
            if ($after !== '' && $after !== $mode) {
                $store->merge('charge', ['mode' => $after]);
                $mode = $after;
                $parts[] = 'Weiter mit ' . Energy::modeLabel($after) . '.';
            }
            return count($parts) > 1 ? ['mode' => $mode, 'text' => implode(' ', $parts)] : null;
        }
        if ($target === null) {
            return null;
        }
        $view = self::view($target, self::inputs($snap), $now);
        if ($view === null || !$view['reached']) {
            return null;
        }
        $then = in_array($target['then'] ?? '', self::THEN, true) ? (string) $target['then'] : 'smart';
        $store->put(self::KEY, []);
        $store->merge('charge', ['mode' => $then]);
        $store->put(self::DONE, ['t' => $now, 'label' => $view['label'], 'then' => $then]);
        return ['mode' => $then, 'text' => 'Ladeziel erreicht (' . $view['label'] . '), weiter mit ' . Energy::modeLabel($then) . '.'];
    }
}
