<?php
declare(strict_types=1);

/**
 * Ladepläne des Hausspeichers (kv.battery_plans), für statischen und dynamischen Tarif gleich:
 * - Wann: im günstigen Fenster (when window; statisch die Zeitfenster des Tarifs für den Speicher, dynamisch die
 *   Ladefenster von Prognose → Strompreis) oder bis zu einer Uhrzeit (when time), an gewählten Wochentagen oder einmalig
 *   (once; der Plan läuft einmal und verschwindet danach).
 * - Wie viel: auf einen Ladestand (amount soc) oder nach Bedarf (amount need): so viel, dass das Haus bis einen Tag nach
 *   dem Fenster mit Sonne und Speicher auskommt, nur wenn das nach Verlust und Verschleiß günstiger ist als später aus
 *   dem Netz (BatteryPlan::decide()), höchstens bis target_soc.
 * - Zeitpunkt im Fenster: so spät wie möglich (start late, die günstigsten, bei gleichem Preis die späteren
 *   Viertelstunden) oder sofort ab Beginn (start early).
 * - Bedingung: nur wenn der Speicher unter einem Ladestand liegt (soc_below); einmal erfüllt bleibt der Plan für dieses
 *   Mal dabei (kv.battery_plan_armed).
 * refresh() plant den Plan, dessen Fenster oder Uhrzeit als nächstes kommt (höchstens 24 Stunden voraus), mit
 * schedule(): Viertelstunde für Viertelstunde bis zum Ende, Sonne lädt, das Haus nimmt bis zum Backup-Puffer, geplante
 * Viertelstunden laden aus dem Netz. Der Stand liegt in kv.battery_plan_state; BatteryPlan::sync() lädt in diesen
 * Viertelstunden, nur bei aktivem EMS. Erreicht ist ein Plan bis zum nächsten Mal erledigt (kv.battery_plan_finished).
 */
final class BatteryPlans
{
    public const KEY = 'battery_plans';
    public const STATE = 'battery_plan_state';
    /** Bis 1.25.1 battery_plan_done; dort konnte auch ein Fenster stehen, das noch gar nicht begonnen hatte. */
    public const DONE = 'battery_plan_finished';
    public const ARMED = 'battery_plan_armed';
    public const MAX = 10;
    private const REPLAN_S = 900;
    /** Von der erwarteten Sonne zählt nur ein Teil, wie beim Auto. */
    private const SUN_SHARE = 0.7;

    /** @return list<array> gespeicherte Pläne, gültig */
    public static function all(ConfigStore $store): array
    {
        $out = [];
        foreach ((array) $store->get(self::KEY, []) as $raw) {
            if (is_array($raw)) {
                $plan = self::clean($raw, (string) ($raw['id'] ?? ''))['plan'];
                if ($plan !== null) {
                    $out[] = $plan;
                }
            }
        }
        return $out;
    }

    /**
     * Plan aus Formular oder Speicher: id, on, when (window oder time), once, days (1–7, ohne once nötig), time
     * „HH:MM“ (für time), amount (soc oder need), target_soc (Ziel oder bei need die Grenze, %), start (late oder
     * early) und optional soc_below (%). Pläne aus 1.23 hatten nur die Uhrzeit: sie gelten als time und soc.
     *
     * @return array{plan: ?array, error: ?string}
     */
    public static function clean(array $raw, string $id = ''): array
    {
        $number = static function (mixed $value): ?float {
            $text = str_replace(',', '.', trim((string) $value));
            return $text === '' || !is_numeric($text) ? null : (float) $text;
        };
        $when = ($raw['when'] ?? 'time') === 'window' ? 'window' : 'time';
        $once = filter_var($raw['once'] ?? false, FILTER_VALIDATE_BOOL);
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($raw['days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        if ($days === [] && !$once) {
            return ['plan' => null, 'error' => 'Bitte mindestens einen Wochentag wählen oder „Einmalig“.'];
        }
        $time = Tariff::clock(trim((string) ($raw['time'] ?? '')));
        if ($time === null && $when === 'time') {
            return ['plan' => null, 'error' => 'Bitte eine Uhrzeit wie 06:00 angeben.'];
        }
        $target = $number($raw['target_soc'] ?? null);
        if ($target === null || $target < 10 || $target > 100) {
            return ['plan' => null, 'error' => 'Bitte einen Ladestand zwischen 10 und 100 % angeben.'];
        }
        $socBelow = $number($raw['soc_below'] ?? null);
        if ($socBelow !== null && ($socBelow < 1 || $socBelow > 100)) {
            return ['plan' => null, 'error' => 'Der Ladestand der Bedingung muss zwischen 1 und 100 % liegen.'];
        }
        $id = preg_match('/^b[0-9a-f]{8}$/', $id) ? $id : 'b' . bin2hex(random_bytes(4));
        return ['plan' => [
            'id' => $id,
            'on' => !array_key_exists('on', $raw) || filter_var($raw['on'], FILTER_VALIDATE_BOOL),
            'when' => $when,
            'once' => $once,
            'days' => $once ? [] : $days,
            'time' => $time ?? '06:00',
            'amount' => ($raw['amount'] ?? 'soc') === 'need' ? 'need' : 'soc',
            'target_soc' => round($target),
            'start' => ($raw['start'] ?? 'late') === 'early' ? 'early' : 'late',
            'soc_below' => $socBelow === null ? null : round($socBelow),
        ], 'error' => null];
    }

    /** Überschrift: „Im Fenster auf 90 %“, „Im Fenster nach Bedarf“, „Bis 80 % um 06:00“, „Nach Bedarf bis 06:00“. */
    public static function headline(array $plan): string
    {
        $need = $plan['amount'] === 'need';
        if ($plan['when'] === 'window') {
            return $need ? 'Im Fenster nach Bedarf' : 'Im Fenster auf ' . pct((float) $plan['target_soc']);
        }
        return $need ? 'Nach Bedarf bis ' . $plan['time'] : 'Bis ' . pct((float) $plan['target_soc']) . ' um ' . $plan['time'];
    }

    /**
     * Gilt der Plan? Ohne Bedingung immer; mit soc_below nur unter diesem Ladestand. Fehlt der Wert, gilt der Plan.
     *
     * @return array{ok: bool, text: string}
     */
    public static function conditions(array $plan, ?float $soc): array
    {
        if ($plan['soc_below'] === null) {
            return ['ok' => true, 'text' => ''];
        }
        $hit = $soc === null || $soc < (float) $plan['soc_below'];
        return ['ok' => $hit, 'text' => 'Speicher ' . ($soc === null ? 'unbekannt' : pct($soc)) . ($hit ? ' unter ' : ' nicht unter ') . pct((float) $plan['soc_below'])];
    }

    /** Gesamtgröße des Speichers in kWh: gemeldet, sonst aus nutzbarem Inhalt und Ladestand; null ohne. */
    public static function capacity(array $values): ?float
    {
        if (is_numeric($values['battery_total_kwh'] ?? null) && (float) $values['battery_total_kwh'] > 0) {
            return (float) $values['battery_total_kwh'];
        }
        if (is_numeric($values['battery_capacity_kwh'] ?? null) && is_numeric($values['battery_soc'] ?? null) && (float) $values['battery_soc'] > 1) {
            return (float) $values['battery_capacity_kwh'] / ((float) $values['battery_soc'] / 100);
        }
        return null;
    }

    /**
     * Die günstigen Fenster für den Speicher ab jetzt, nach Beginn sortiert: statisch die Zeitfenster des Tarifs, die für
     * den Speicher gelten (an ihren Wochentagen, über Mitternacht vom Tag des Beginns), dynamisch die aktiven
     * Ladefenster, so weit die Preise reichen.
     *
     * @return list<array{start: int, end: int}>
     */
    public static function windows(ConfigStore $store, Tariff $tariff, int $now): array
    {
        $t = $tariff->tariffAt($now);
        $out = [];
        if ($t['mode'] === 'dynamic') {
            foreach ((new PriceWindows($store, $tariff))->offered($now, 40) as $w) {
                $out[] = ['start' => (int) $w['start'], 'end' => (int) $w['end']];
            }
        } else {
            $tz = new DateTimeZone('Europe/Berlin');
            $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
            foreach ((array) $t['windows'] as $w) {
                if (!in_array('battery', (array) $w['for'], true)) {
                    continue;
                }
                [$fh, $fm] = array_map('intval', explode(':', (string) $w['from']));
                [$th, $tm] = array_map('intval', explode(':', (string) $w['to']));
                for ($i = -1; $i <= 8; $i++) {
                    $day = $today->modify(($i >= 0 ? '+' : '') . $i . ' day');
                    if ((array) $w['days'] !== [] && !in_array((int) $day->format('N'), (array) $w['days'], true)) {
                        continue;
                    }
                    $start = $day->setTime($fh, $fm);
                    $end = ($w['to'] <= $w['from'] ? $day->modify('+1 day') : $day)->setTime($th, $tm);
                    if ($end->getTimestamp() > $now) {
                        $out[] = ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp()];
                    }
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * Das nächste Mal eines Plans: {start, end}; start null bei einer Uhrzeit (dann zählt jede Viertelstunde davor). Ein
     * erledigtes Ende ($done) zählt nicht. Im Fenster entscheidet der Wochentag, an dem es beginnt; einmalig jeder Tag.
     *
     * @param list<array{start: int, end: int}> $windows
     * @return ?array{start: ?int, end: int}
     */
    public static function occurrence(array $plan, array $windows, int $now, int $done = 0): ?array
    {
        $days = $plan['once'] ? [1, 2, 3, 4, 5, 6, 7] : (array) $plan['days'];
        if ($plan['when'] === 'time') {
            $probe = ['days' => $days, 'time' => $plan['time']];
            $at = GridPlans::next($probe, $now);
            if ($at !== null && $at === $done) {
                $at = GridPlans::next($probe, $at);
            }
            return $at === null ? null : ['start' => null, 'end' => $at];
        }
        $tz = new DateTimeZone('Europe/Berlin');
        foreach ($windows as $w) {
            $day = (int) (new DateTimeImmutable('@' . $w['start']))->setTimezone($tz)->format('N');
            if ($w['end'] > $now && $w['end'] !== $done && in_array($day, $days, true)) {
                return ['start' => (int) $w['start'], 'end' => (int) $w['end']];
            }
        }
        return null;
    }

    /**
     * Die Viertelstunden zum Laden aus dem Netz, ohne Seiteneffekte. $in: e_kwh (jetzt im Speicher), capacity_kwh,
     * floor_kwh (Backup-Puffer), target_kwh, power_kw (aus dem Netz), efficiency (0…1), quarters: Liste [t, Sonne kW,
     * Haus kW, ct/kWh oder null] ab jetzt bis zum Ende, optional allowed (Viertelstunden, in denen geladen werden darf,
     * sonst alle), order (late: günstigste, bei gleichem Preis spätere zuerst; early: frühere zuerst) und reach (end: der
     * Ladestand zum Ende zählt, peak: er wird einmal erreicht, danach darf das Haus nehmen). Sonne lädt erst
     * das Haus, dann den Speicher; fehlt sie, nimmt das Haus aus dem Speicher bis zum Puffer. In einer geplanten
     * Viertelstunde lädt das Netz mit voller Leistung, das Haus kommt dann aus Sonne und Netz.
     *
     * @return array{status: string, slots: list<int>, grid_kwh: float, stop_kwh?: float, sun_kwh: float, drain_kwh: float, avg_ct: ?float, end_kwh: float}
     */
    public static function schedule(array $in): array
    {
        $cap = max(0.1, (float) $in['capacity_kwh']);
        $floor = clamp_float((float) $in['floor_kwh'], 0, $cap);
        $target = min($cap, (float) $in['target_kwh']);
        $eta = max(0.5, min(1.0, (float) $in['efficiency']));
        $add = max(0.1, (float) $in['power_kw']) * $eta * 0.25;
        $quarters = (array) $in['quarters'];
        // Mit reach peak hört das Laden am Ziel auf; mit end darf es darüber, damit nach dem Verbrauch des Hauses zum Ende
        // das Ziel bleibt (stop_kwh sagt BatteryPlan::sync(), wo es aufhört).
        $once = ($in['reach'] ?? 'end') === 'peak';
        $stopAt = $once ? $target : $cap;
        $allowed = isset($in['allowed']) ? array_fill_keys(array_map('intval', (array) $in['allowed']), true) : null;
        $first = $allowed ? min(array_keys($allowed)) : null;
        $run = static function (array $charge) use ($in, $cap, $floor, $add, $quarters, $stopAt, $first): array {
            $e = clamp_float((float) $in['e_kwh'], 0, $cap);
            $sun = $drain = $charged = 0.0;
            // peak: der höchste Stand durchs Laden. top: der höchste Stand ab Beginn des Fensters; was davor im Speicher
            // ist, hat das Haus bis dahin vielleicht wieder verbraucht.
            $peak = $top = 0.0;
            foreach ($quarters as [$t, $pv, $house]) {
                if ($first === null || $t >= $first) {
                    $top = max($top, $e);
                }
                if (isset($charge[$t])) {
                    $next = max($e, min($stopAt, $e + $add));
                    $charged += $next - $e;
                    $e = $next;
                    $peak = max($peak, $e);
                    continue;
                }
                $net = (max(0.0, (float) $pv) * self::SUN_SHARE - max(0.0, (float) $house)) * 0.25;
                if ($net >= 0) {
                    $keep = min($net, $cap - $e);
                    $e += $keep;
                    $sun += $keep;
                } else {
                    $take = min(-$net, max(0.0, $e - $floor));
                    $e -= $take;
                    $drain += $take;
                }
            }
            return ['e' => $e, 'peak' => $peak, 'top' => max($top, $e), 'sun' => $sun, 'drain' => $drain, 'charged' => $charged];
        };
        $reached = static fn (array $r): bool => ($once ? $r['top'] : $r['e']) >= $target - 0.05;
        $base = $run([]);
        $out = ['status' => 'sun', 'slots' => [], 'grid_kwh' => 0.0, 'sun_kwh' => round($base['sun'], 1), 'drain_kwh' => round($base['drain'], 1), 'avg_ct' => null, 'end_kwh' => round($base['e'], 1)];
        // Erledigt ist nur, was im laufenden Fenster schon am Ziel ist. Davor sagt der Stand von jetzt nichts: Ein sonniger
        // Nachmittag kann über dem Ziel liegen und der Speicher zu Beginn des Fensters trotzdem leer sein.
        $started = $first === null || (int) ($quarters[0][0] ?? 0) >= $first;
        if ($once && $started && (float) $in['e_kwh'] >= $target - 0.05) {
            return ['status' => 'done'] + $out;
        }
        if ($reached($base)) {
            return $out;
        }
        $order = array_values(array_filter($quarters, static fn (array $q): bool => $allowed === null || isset($allowed[$q[0]])));
        if (($in['order'] ?? 'late') === 'early') {
            usort($order, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        } else {
            // Günstigste zuerst, bei gleichem Preis die späteren; Viertelstunden ohne Preis zuletzt.
            usort($order, static fn (array $a, array $b): int => [$a[3] === null ? 1 : 0, $a[3] ?? 0.0, -$a[0]] <=> [$b[3] === null ? 1 : 0, $b[3] ?? 0.0, -$b[0]]);
        }
        $charge = [];
        $result = $base;
        foreach ($order as $quarter) {
            $charge[$quarter[0]] = true;
            $result = $run($charge);
            if ($reached($result)) {
                break;
            }
        }
        $slots = array_keys($charge);
        sort($slots);
        $prices = array_values(array_filter(array_map(static fn (array $q): ?float => isset($charge[$q[0]]) ? $q[3] : null, $quarters), static fn (?float $v): bool => $v !== null));
        return [
            'status' => $reached($result) ? 'ok' : 'tight',
            'slots' => $slots,
            'grid_kwh' => round($result['charged'] / $eta, 1),
            'stop_kwh' => round(max($target, $result['peak']), 2),
            'sun_kwh' => round($result['sun'], 1),
            'drain_kwh' => round($result['drain'], 1),
            'avg_ct' => $prices ? round(array_sum($prices) / count($prices), 2) : null,
            'end_kwh' => round($result['e'], 1),
        ];
    }

    /** Der laufende oder letzte Plan aus kv.battery_plan_state, null ohne. */
    public static function state(ConfigStore $store): ?array
    {
        $state = $store->get(self::STATE, []);
        return is_array($state) && isset($state['plan_id'], $state['deadline']) ? $state : null;
    }

    /**
     * Der Lauf von Viertelstunden, der jetzt geplant ist, als {start, end, target_soc}; null, wenn jetzt keiner ist.
     * BatteryPlan::sync() lädt in diesem Lauf bis zum Stopp-Stand.
     */
    public static function current(?array $state, int $now): ?array
    {
        if ($state === null || !in_array($state['status'] ?? '', ['ok', 'tight'], true)) {
            return null;
        }
        foreach (ChargePlan::windows((array) ($state['slots'] ?? [])) as [$a, $b]) {
            if ($a <= $now && $now < $b) {
                return ['start' => $a, 'end' => $b, 'target_soc' => (float) ($state['stop_soc'] ?? $state['target_soc']), 'plan_id' => (string) $state['plan_id'], 'deadline' => (int) $state['deadline']];
            }
        }
        return null;
    }

    /**
     * Recorder und Demo: den Plan planen, der als nächstes dran ist (Fenster oder Uhrzeit in den nächsten 24 Stunden).
     * Neu, wenn Plan, Ende oder Preise sich geändert haben oder 15 min vorbei sind. Ein einmaliger Plan verschwindet,
     * sobald sein Mal vorbei oder erreicht ist.
     */
    public static function refresh(ConfigStore $store, array $snap, int $now, bool $force = false): ?array
    {
        $cfg = $snap['cfg'];
        $values = $snap['values'];
        $old = self::state($store);
        if ($old !== null && !empty($old['once']) && (($old['status'] ?? '') === 'done' || $now >= (int) $old['deadline'])) {
            $store->put(self::KEY, array_values(array_filter(self::all($store), static fn (array $p): bool => $p['id'] !== $old['plan_id'])));
            $store->put(self::STATE, []);
            $old = null;
        }
        $tariff = new Tariff($store);
        $windows = self::windows($store, $tariff, $now);
        $done = (int) $store->get(self::DONE, 0);
        $due = null;
        foreach (self::all($store) as $plan) {
            $occ = $plan['on'] ? self::occurrence($plan, $windows, $now, $done) : null;
            if ($occ !== null && ($occ['start'] ?? $occ['end']) - $now <= GridPlans::HORIZON_S && ($due === null || $occ['end'] < $due['occ']['end'])) {
                $due = ['plan' => $plan, 'occ' => $occ];
            }
        }
        if ($due === null) {
            if ($old !== null) {
                $store->put(self::STATE, []);
            }
            return null;
        }
        $plan = $due['plan'];
        $deadline = (int) $due['occ']['end'];
        $begin = $due['occ']['start'];
        $horizon = $tariff->horizon();
        $sig = md5((string) json_encode([$plan, $due['occ']]));
        $key = $plan['id'] . '@' . $deadline;
        // „Speicher unter“ zählt erst, wenn das Laden dran ist; bis dahin kann der Stand noch weit davon weg sein.
        $open = $plan['soc_below'] !== null && (string) $store->get(self::ARMED, '') !== $key;
        // Zu Beginn des Fensters und zur ersten Viertelstunde des Plans frisch rechnen, nicht erst nach den 15 min.
        $begun = $begin !== null && $now >= $begin && (int) ($old['computed_at'] ?? 0) < $begin;
        $check = $open && isset($old['slots'][0]) && (int) $old['slots'][0] <= $now;
        if (!$force && !$begun && !$check && $old !== null && $old['plan_id'] === $plan['id'] && (int) $old['deadline'] === $deadline && (int) ($old['horizon'] ?? 0) === $horizon
            && ($old['sig'] ?? '') === $sig && $now - (int) ($old['computed_at'] ?? 0) < self::REPLAN_S) {
            return $old;
        }
        $soc = is_numeric($values['battery_soc'] ?? null) ? (float) $values['battery_soc'] : null;
        $cond = self::conditions($plan, $soc);
        $s = BatteryPlan::settings($cfg);
        $capacity = self::capacity($values);
        $state = ['plan_id' => $plan['id'], 'once' => $plan['once'], 'amount' => $plan['amount'], 'start' => $begin, 'deadline' => $deadline, 'target_soc' => (float) $plan['target_soc'],
            'horizon' => $horizon, 'sig' => $sig, 'computed_at' => $now, 'conditions' => $cond['text'], 'check_soc' => $open ? (float) $plan['soc_below'] : null, 'slots' => [], 'grid_kwh' => null,
            'sun_kwh' => 0.0, 'drain_kwh' => 0.0, 'avg_ct' => null, 'saving_eur' => null, 'power_kw' => $s['power_kw']];
        if ($soc === null || $capacity === null) {
            $state['status'] = 'unknown';
        } else {
            $profile = is_array($snap['house_profile'] ?? null) ? $snap['house_profile'] : array_fill(0, 24, (float) ($snap['house_mean_kw'] ?? 0.5));
            $series = Forecast::fromRadiation((new WeatherFeed($store))->hours(intdiv($now, 3600) * 3600 - 3600, $deadline + 86400 + 3600), (array) $cfg['plant']);
            $quarters = BatteryPlan::quarters($series, $profile, $tariff, $now, $deadline);
            $target = (float) $plan['target_soc'];
            $state['status'] = 'pending';
            if ($plan['amount'] === 'need') {
                // Nach Bedarf: was das Haus bis einen Tag danach braucht, nur wenn es sich lohnt, höchstens bis zum Ziel.
                $decision = BatteryPlan::decide([
                    'soc' => $soc, 'capacity_kwh' => $capacity, 'floor_soc' => BatteryPlan::floorSoc($cfg, $values), 'max_soc' => min($s['max_soc'], $target),
                    'power_kw' => $s['power_kw'], 'efficiency' => $s['efficiency'], 'wear_ct' => $s['wear_ct'], 'export_ct' => (float) $tariff->tariffAt($now)['export_ct'],
                    'window' => ['start' => $begin ?? $now, 'end' => $deadline],
                    'quarters' => BatteryPlan::quarters($series, $profile, $tariff, $now, $deadline + 86400),
                ]);
                $state['saving_eur'] = $decision['saving_eur'];
                if ($decision['reason'] === 'charge') {
                    // Sofort ab Beginn: einmal bis zum Ziel der Rechnung, danach versorgt der Speicher das Haus. Spät: auf
                    // den Stand, den die Rechnung zum Ende des Fensters hat.
                    $target = (float) ($plan['start'] === 'early' || $begin === null ? $decision['target_soc'] : $decision['end_soc']);
                    $state['target_soc'] = $target;
                } else {
                    // Voll zählt als erledigt erst im Fenster; davor ist es nur die Erwartung.
                    $state['status'] = match ($decision['reason']) { 'sun' => 'enough', 'price' => 'price', 'full' => $begin !== null && $now < $begin ? 'enough' : 'done', default => 'unknown' };
                }
            }
            if ($state['status'] === 'pending') {
                $allowed = $begin === null ? null : array_values(array_filter(array_map(static fn (array $q): int => (int) $q[0], $quarters), static fn (int $q): bool => $q >= intdiv((int) $begin, 900) * 900));
                $run = self::schedule([
                    'e_kwh' => $capacity * $soc / 100,
                    'capacity_kwh' => $capacity,
                    'floor_kwh' => $capacity * BatteryPlan::floorSoc($cfg, $values) / 100,
                    'target_kwh' => $capacity * $target / 100,
                    'power_kw' => $s['power_kw'],
                    'efficiency' => $s['efficiency'],
                    'quarters' => $quarters,
                    'allowed' => $allowed,
                    'order' => $plan['start'],
                    // Sofort ab Beginn: einmal bis zum Ziel, danach darf das Haus nehmen; sonst zählt der Stand zum Ende.
                    'reach' => $plan['start'] === 'early' && $begin !== null ? 'peak' : 'end',
                ]);
                if ($run['status'] === 'done') {
                    $store->put(self::DONE, $deadline);
                }
                $state = array_merge($state, $run);
                $state['stop_soc'] = isset($run['stop_kwh']) ? round(min(100.0, (float) $run['stop_kwh'] / $capacity * 100), 1) : $target;
                // Ist das Laden dran, gilt die Bedingung: Trifft sie zu, lädt der Plan bis zum Ziel, auch wenn der Stand dabei
                // über die Grenze steigt. Sonst wartet er und prüft in 15 min wieder; das Haus kann ihn ja noch darunter bringen.
                if ($open && isset($state['slots'][0]) && (int) $state['slots'][0] <= $now) {
                    if ($cond['ok']) {
                        $store->put(self::ARMED, $key);
                        $state['check_soc'] = null;
                    } else {
                        $state['status'] = 'skip';
                        $state['slots'] = [];
                    }
                }
            }
        }
        $store->put(self::STATE, $state);
        return $state;
    }

    /** Ende oder Fenster als Text: „heute 00:00–06:00“ oder „morgen 06:00“. */
    public static function whenText(?int $start, int $end, int $now): string
    {
        if ($start !== null) {
            return PriceWindows::label(['start' => $start, 'end' => $end], $now);
        }
        $day = match (date('Y-m-d', $end)) {
            date('Y-m-d', $now) => 'heute',
            date('Y-m-d', strtotime('+1 day', $now)) => 'morgen',
            default => day_label(date('Y-m-d', $end)),
        };
        return $day . ' ' . date('H:i', $end);
    }

    /** Ein Satz zum Plan, der als nächstes dran ist, für Speicher → Ausblick; leer ohne. */
    public static function text(?array $state, int $now): string
    {
        if ($state === null) {
            return '';
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $time = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('H:i');
        $when = ($state['start'] !== null ? 'Fenster ' : 'Bis ') . self::whenText($state['start'] === null ? null : (int) $state['start'], (int) $state['deadline'], $now);
        $runs = ChargePlan::windows((array) $state['slots']);
        $list = implode(', ', array_map(static fn (array $w): string => $time($w[0]) . '–' . $time($w[1]), array_slice($runs, 0, 3))) . (count($runs) > 3 ? ' …' : '');
        $price = $state['avg_ct'] === null ? '' : ' zu Ø ' . ct((float) $state['avg_ct']);
        $saving = (float) ($state['saving_eur'] ?? 0) >= 0.05 ? ', spart etwa ' . euro((float) $state['saving_eur']) : '';
        $house = (float) ($state['drain_kwh'] ?? 0) >= 0.5 ? '; bis dahin nimmt das Haus etwa ' . kwh((float) $state['drain_kwh'], 1) . ' aus dem Speicher' : '';
        $if = ($state['check_soc'] ?? null) !== null ? ', falls der Speicher dann unter ' . pct((float) $state['check_soc']) . ' ist' : '';
        return match ((string) $state['status']) {
            'skip' => $when . ': lädt gerade nicht, ' . $state['conditions'] . '.',
            'unknown' => $when . ': Es fehlen Ladestand, Größe des Speichers oder der übliche Hausverbrauch.',
            'done' => $when . ': Der Speicher hat ' . pct((float) $state['target_soc']) . ' schon.',
            'sun' => $when . ': Sonne und Speicher schaffen ' . pct((float) $state['target_soc']) . ' voraussichtlich allein.',
            'enough' => $when . ': Sonne und Speicher reichen voraussichtlich bis einen Tag danach, kein Laden nötig.',
            'price' => $when . ': lohnt sich diesmal nicht, der Preisunterschied deckt Verlust und Verschleiß nicht.',
            'tight' => $when . ': Die Zeit ist knapp, der Speicher lädt durchgehend' . $price . $if . ($if === '' ? '' : ',') . ' und erreicht ' . pct((float) $state['target_soc']) . ' wohl nicht ganz.',
            default => $when . ': lädt ' . $list . ' auf ' . pct((float) $state['target_soc']) . ', etwa ' . kwh((float) $state['grid_kwh'], 1) . $price . $saving . $if . $house . '.',
        };
    }

    /**
     * Pläne für die Liste auf Speicher: Überschrift, darunter Tage oder „einmalig“, das nächste Mal, Zeitpunkt, Grenze
     * und Bedingung; an/aus und ob er gerade plant.
     *
     * @param list<array{start: int, end: int}> $windows
     */
    public static function rows(array $plans, ?array $state, array $windows, int $now, int $done = 0): array
    {
        $out = [];
        foreach ($plans as $p) {
            $occ = self::occurrence($p, $windows, $now, $done);
            $next = !$p['on'] ? 'aus' : ($occ === null ? ($p['when'] === 'window' ? 'kein Fenster bekannt' : '') : 'als Nächstes ' . self::whenText($occ['start'], $occ['end'], $now));
            $out[] = [
                'id' => (string) $p['id'],
                'label' => self::headline($p),
                'detail' => implode(' · ', array_filter([
                    $p['once'] ? 'einmalig' : GridPlans::daysText((array) $p['days']),
                    $next,
                    $p['when'] === 'window' && $p['start'] === 'early' ? 'sofort ab Beginn' : '',
                    $p['amount'] === 'need' && (float) $p['target_soc'] < 100 ? 'höchstens ' . pct((float) $p['target_soc']) : '',
                    $p['soc_below'] === null ? '' : 'nur bei Speicher < ' . pct((float) $p['soc_below']),
                ])),
                'on' => (bool) $p['on'],
                'active' => $state !== null && $state['plan_id'] === $p['id'] && in_array($state['status'] ?? '', ['ok', 'tight'], true),
            ];
        }
        return $out;
    }

    /** Woher die Fenster kommen, ein Satz fürs Sheet. */
    public static function sourceText(Tariff $tariff, int $now): string
    {
        $t = $tariff->tariffAt($now);
        if ($t['mode'] === 'dynamic') {
            return 'Dynamisch sind das die aktiven Ladefenster unter Prognose → Strompreis: je Tag das günstigste am Stück und dein Preisbereich.';
        }
        $mine = array_values(array_filter((array) $t['windows'], static fn (array $w): bool => in_array('battery', (array) $w['for'], true)));
        if ($mine === []) {
            return 'Dein statischer Tarif hat noch kein Zeitfenster für den Speicher; eintragen unter Einstellungen → Tarif.';
        }
        return 'Deine Zeitfenster für den Speicher: ' . implode(', ', array_map(static fn (array $w): string => Tariff::windowLabel($w) . ' zu ' . ct(Tariff::windowCt($t, $w)), $mine)) . '.';
    }
}
