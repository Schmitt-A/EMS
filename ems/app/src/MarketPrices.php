<?php
declare(strict_types=1);

/**
 * Börsenstrompreis (Day-Ahead, Gebotszone DE-LU) in Viertelstunden, seit Oktober 2025 die Auflösung der Auktion,
 * davor Stunden. Quelle ist Energy-Charts (Fraunhofer ISE) mit den Daten der Bundesnetzagentur | SMARD.de unter
 * CC BY 4.0, die Quelle steht darum unter jedem Diagramm. Fällt sie aus, springt aWATTar ein (stündlich, Fair Use
 * mit etwa 100 Abrufen am Tag). Die Auktion für morgen endet gegen 13 Uhr; bis dahin reichen die Preise bis
 * Mitternacht. Alles bleibt in market_prices, auch die Vergangenheit: einmalig lädt der Recorder zwei Jahre nach,
 * einen Monat je Schritt, damit „Lohnt sich dynamisch?“ und Statistik sofort rechnen können. Im Demo-Modus rechnet DemoModel die
 * Preise, ohne Netz.
 */
final class MarketPrices
{
    public const SOURCE = 'Börsenstrompreis DE-LU: Bundesnetzagentur | SMARD.de, über Energy-Charts (Fraunhofer ISE), Lizenz CC BY 4.0';
    private const META = 'market_meta';
    private const BACKFILL_DAYS = 730;
    private const BACKFILL_CHUNK_DAYS = 31;
    /** Abstand zwischen zwei Abrufen, solange heute oder morgen fehlt, und zwischen zwei Stücken Vergangenheit. */
    private const RETRY_S = 900;
    private const BACKFILL_GAP_S = 30;
    private const EC_URL = 'https://api.energy-charts.info/price';
    private const AWATTAR_URL = 'https://api.awattar.de/v1/marketdata';

    /** @var array<int, float> Viertelstunde → ct/kWh netto, je Anfrage zwischengespeichert. */
    private array $quarters = [];
    /** @var array<string, true> geladene Monate. */
    private array $loaded = [];

    public function __construct(private ConfigStore $store) {}

    public function meta(): array
    {
        $meta = $this->store->get(self::META, []);
        return array_merge(['fetched_at' => null, 'tried_at' => null, 'source' => null, 'error' => null, 'backfill_until' => null, 'backfill_at' => null], is_array($meta) ? $meta : []);
    }

    /**
     * Recorder alle 10 s: Fehlt der Rest von heute, oder ab 13 Uhr morgen, wird abgerufen, höchstens alle 15 min.
     * Sonst lädt jeder Aufruf höchstens ein Stück Vergangenheit nach, bis zwei Jahre da sind.
     */
    public function refresh(int $now, bool $force = false): array
    {
        $meta = $this->meta();
        $tz = new DateTimeZone('Europe/Berlin');
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $today = $local->setTime(0, 0);
        $last = $this->lastEnd();
        $missingToday = $last < $today->modify('+1 day')->getTimestamp();
        $missingTomorrow = (int) $local->format('G') >= 13 && $last < $today->modify('+2 days')->getTimestamp();
        if ($force || (($missingToday || $missingTomorrow) && $now - (int) $meta['tried_at'] >= self::RETRY_S)) {
            $meta['tried_at'] = $now;
            try {
                [$rows, $source] = $this->load($today->modify('-1 day'), $today->modify('+1 day'));
                $this->save($rows, $source);
                $meta = array_merge($meta, ['fetched_at' => $now, 'source' => $source, 'error' => null]);
            } catch (Throwable $e) {
                $meta['error'] = $e->getMessage();
            }
            $this->store->put(self::META, $meta);
            return $meta;
        }
        $target = $today->modify('-' . self::BACKFILL_DAYS . ' days');
        $until = $meta['backfill_until'] !== null ? (new DateTimeImmutable((string) $meta['backfill_until'], $tz)) : $today->modify('-1 day');
        if ($until > $target && $now - (int) $meta['backfill_at'] >= self::BACKFILL_GAP_S) {
            $from = max($target, $until->modify('-' . self::BACKFILL_CHUNK_DAYS . ' days'));
            $meta['backfill_at'] = $now;
            try {
                [$rows, $source] = $this->load($from, $until->modify('-1 day'), true);
                $this->save($rows, $source);
                $meta['backfill_until'] = $from->format('Y-m-d');
            } catch (Throwable $e) {
                // Ein Loch in der Vergangenheit ist kein Fehler fürs Heute; es wird später noch einmal versucht.
                $meta['backfill_error'] = $e->getMessage();
            }
            $this->store->put(self::META, $meta);
        }
        return $meta;
    }

    /**
     * Preise der Tage $from bis $to (beide eingeschlossen): Energy-Charts, sonst aWATTar; im Demo-Modus gerechnet.
     *
     * @return array{0: list<array{start:int, end:int, eur_mwh:float}>, 1: string}
     */
    private function load(DateTimeImmutable $from, DateTimeImmutable $to, bool $onlyPrimary = false): array
    {
        if (demo_mode()) {
            return [DemoModel::load()->marketPrices($from->getTimestamp(), $to->modify('+1 day')->getTimestamp()), 'demo'];
        }
        try {
            $url = self::EC_URL . '?' . http_build_query(['bzn' => 'DE-LU', 'start' => $from->format('Y-m-d'), 'end' => $to->format('Y-m-d')]);
            return [self::parseEnergyCharts(self::download($url)), 'energy-charts'];
        } catch (Throwable $e) {
            if ($onlyPrimary) {
                throw $e;
            }
            $url = self::AWATTAR_URL . '?' . http_build_query(['start' => $from->getTimestamp() * 1000, 'end' => $to->modify('+1 day')->getTimestamp() * 1000]);
            try {
                return [self::parseAwattar(self::download($url)), 'awattar'];
            } catch (Throwable $fallback) {
                throw new RuntimeException('Börsenpreise nicht erreichbar: ' . $e->getMessage() . ' Ersatz: ' . $fallback->getMessage());
            }
        }
    }

    /**
     * Energy-Charts: unix_seconds und price (EUR/MWh) als gleich lange Listen. Die Länge einer Zeile ergibt der Abstand
     * zum nächsten Zeitpunkt (15 oder 60 min), die letzte übernimmt den davor.
     *
     * @return list<array{start:int, end:int, eur_mwh:float}>
     */
    public static function parseEnergyCharts(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['unix_seconds'] ?? null) || !is_array($data['price'] ?? null)) {
            throw new RuntimeException('Energy-Charts liefert keine Preise.');
        }
        $t = array_values($data['unix_seconds']);
        $p = array_values($data['price']);
        $n = min(count($t), count($p));
        $rows = [];
        $len = 900;
        for ($i = 0; $i < $n; $i++) {
            if (!is_numeric($t[$i])) {
                continue;
            }
            $start = (int) $t[$i];
            if ($i + 1 < $n && is_numeric($t[$i + 1])) {
                $gap = (int) $t[$i + 1] - $start;
                $len = $gap === 900 || $gap === 3600 ? $gap : $len;
            }
            if (is_numeric($p[$i])) {
                $rows[] = ['start' => $start, 'end' => $start + $len, 'eur_mwh' => (float) $p[$i]];
            }
        }
        if (!$rows) {
            throw new RuntimeException('Energy-Charts liefert für diesen Zeitraum keine Preise.');
        }
        return $rows;
    }

    /**
     * aWATTar: data mit start_timestamp, end_timestamp (Millisekunden) und marketprice (EUR/MWh).
     *
     * @return list<array{start:int, end:int, eur_mwh:float}>
     */
    public static function parseAwattar(string $json): array
    {
        $data = json_decode($json, true);
        $rows = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (!is_array($row) || !is_numeric($row['start_timestamp'] ?? null) || !is_numeric($row['end_timestamp'] ?? null) || !is_numeric($row['marketprice'] ?? null)) {
                continue;
            }
            $rows[] = ['start' => intdiv((int) $row['start_timestamp'], 1000), 'end' => intdiv((int) $row['end_timestamp'], 1000), 'eur_mwh' => (float) $row['marketprice']];
        }
        if (!$rows) {
            throw new RuntimeException('aWATTar liefert keine Preise.');
        }
        return $rows;
    }

    /**
     * Speichert Preise. Energy-Charts (und der Demo) überschreibt; aWATTar füllt nur Stunden, für die noch nichts da
     * ist, damit eine Stunde nie über genaueren Viertelstunden liegt.
     */
    public function save(array $rows, string $source): void
    {
        $pdo = $this->store->pdo();
        $pdo->beginTransaction();
        try {
            $upsert = $pdo->prepare('INSERT INTO market_prices (start, "end", eur_mwh, source) VALUES (?, ?, ?, ?) ON CONFLICT(start) DO UPDATE SET "end" = excluded."end", eur_mwh = excluded.eur_mwh, source = excluded.source');
            $taken = $pdo->prepare('SELECT COUNT(*) FROM market_prices WHERE start >= ? AND start < ?');
            $drop = $pdo->prepare('DELETE FROM market_prices WHERE source = "awattar" AND start < ? AND "end" > ? AND start <> ?');
            foreach ($rows as $row) {
                if ($source === 'awattar') {
                    $taken->execute([$row['start'], $row['end']]);
                    if ((int) $taken->fetchColumn() > 0) {
                        continue;
                    }
                } else {
                    // Genauere Preise ersetzen eine Stunde aus dem Ersatz, die sie überdeckt.
                    $drop->execute([$row['end'], $row['start'], $row['start']]);
                }
                $upsert->execute([$row['start'], $row['end'], $row['eur_mwh'], $source]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->quarters = [];
        $this->loaded = [];
    }

    /** Ende des letzten bekannten Preises (0 ohne Preise). */
    public function lastEnd(): int
    {
        return (int) ($this->store->pdo()->query('SELECT MAX("end") FROM market_prices')->fetchColumn() ?: 0);
    }

    /** Beginn des ersten bekannten Preises (null ohne Preise). */
    public function firstStart(): ?int
    {
        $first = $this->store->pdo()->query('SELECT MIN(start) FROM market_prices')->fetchColumn();
        return $first === null || $first === false ? null : (int) $first;
    }

    /**
     * Börsenpreis in ct/kWh netto je Viertelstunde zwischen $from und $to; Stunden zählen für alle vier Viertel.
     *
     * @return array<int, float>
     */
    public function quarters(int $from, int $to): array
    {
        $out = [];
        for ($month = self::monthOf($from); $month < $to; $month = self::nextMonth($month)) {
            $this->loadMonth($month);
        }
        for ($q = intdiv($from, 900) * 900; $q < $to; $q += 900) {
            if (isset($this->quarters[$q])) {
                $out[$q] = $this->quarters[$q];
            }
        }
        return $out;
    }

    /** Börsenpreis in ct/kWh netto für den Zeitpunkt, null ohne Preis. */
    public function ctAt(int $ts): ?float
    {
        $q = intdiv($ts, 900) * 900;
        return $this->quarters($q, $q + 900)[$q] ?? null;
    }

    private function loadMonth(int $month): void
    {
        $key = (string) $month;
        if (isset($this->loaded[$key])) {
            return;
        }
        $this->loaded[$key] = true;
        $stmt = $this->store->pdo()->prepare('SELECT start, "end", eur_mwh FROM market_prices WHERE start >= ? AND start < ? ORDER BY start');
        $stmt->execute([$month - 3600, self::nextMonth($month)]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            for ($q = (int) $row['start']; $q < (int) $row['end']; $q += 900) {
                $this->quarters[$q] = (float) $row['eur_mwh'] / 10;
            }
        }
    }

    private static function monthOf(int $ts): int
    {
        $tz = new DateTimeZone('Europe/Berlin');
        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->modify('first day of this month')->setTime(0, 0)->getTimestamp();
    }

    private static function nextMonth(int $month): int
    {
        $tz = new DateTimeZone('Europe/Berlin');
        return (new DateTimeImmutable('@' . $month))->setTimezone($tz)->modify('first day of next month')->setTime(0, 0)->getTimestamp();
    }

    private static function download(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Keine Verbindung möglich.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'EMS-Home-Assistant',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code >= 400) {
            throw new RuntimeException('Status ' . ($code ?: 'ohne Verbindung') . '.');
        }
        if (strlen($body) > 2000000) {
            throw new RuntimeException('Antwort unerwartet groß.');
        }
        return $body;
    }
}
