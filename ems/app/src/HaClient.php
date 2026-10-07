<?php
declare(strict_types=1);

final class HaClient
{
    public function __construct(private string $baseUrl, private string $token) {}

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    public function ping(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Keine Verbindung hinterlegt.'];
        }
        try {
            $config = $this->get('/api/config');
            return [
                'ok' => true,
                'error' => null,
                'version' => $config['version'] ?? '',
                'location' => $config['location_name'] ?? '',
                'timezone' => $config['time_zone'] ?? 'Europe/Berlin',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function states(): array
    {
        $cache = data_dir() . '/states-cache.json';
        if (is_file($cache) && (time() - (int) filemtime($cache)) < 3) {
            $decoded = json_decode((string) file_get_contents($cache), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $states = $this->get('/api/states');
        if (!is_array($states)) {
            return [];
        }
        file_put_contents($cache, json_encode($states));
        return $states;
    }

    public function state(string $entityId): ?array
    {
        if (!is_entity_id($entityId)) {
            return null;
        }
        try {
            $row = $this->get('/api/states/' . rawurlencode($entityId));
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function index(): array
    {
        $map = [];
        foreach ($this->states() as $row) {
            if (is_array($row) && isset($row['entity_id'])) {
                $map[$row['entity_id']] = $row;
            }
        }
        return $map;
    }

    /** @return array<int, array{t:int, v:float}> */
    public function history(string $entityId, int $start, ?int $end = null): array
    {
        if (!is_entity_id($entityId)) {
            return [];
        }
        $stamp = gmdate('Y-m-d\TH:i:s\Z', $start);
        $query = '/api/history/period/' . $stamp . '?filter_entity_id=' . rawurlencode($entityId) . '&minimal_response=1';
        if ($end !== null) {
            $query .= '&end_time=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $end));
        }
        $payload = $this->get($query);
        $series = [];
        $rows = is_array($payload) && isset($payload[0]) && is_array($payload[0]) ? $payload[0] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['state']) || !is_numeric($row['state'])) {
                continue;
            }
            $when = isset($row['last_changed']) ? strtotime((string) $row['last_changed']) : false;
            if ($when === false && isset($row['lu'])) {
                $when = (int) ($row['lu'] ?? 0);
            }
            if ($when === false || $when <= 0) {
                continue;
            }
            $series[] = ['t' => (int) $when, 'v' => (float) $row['state']];
        }
        usort($series, fn ($a, $b) => $a['t'] <=> $b['t']);
        return $series;
    }

    /** @return array<int, array{start:int, mean:?float, change:?float}> */
    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array
    {
        if (!is_entity_id($entityId)) {
            return [];
        }
        $query = http_build_query([
            'start_time' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'end_time' => gmdate('Y-m-d\TH:i:s\Z', $end),
            'statistic_ids' => $entityId,
            'period' => $period,
        ]);
        $payload = $this->get('/api/recorder/statistics_during_period?' . $query);
        $rows = [];
        if (is_array($payload) && isset($payload[$entityId]) && is_array($payload[$entityId])) {
            $rows = $payload[$entityId];
        } elseif (is_array($payload) && array_is_list($payload)) {
            $rows = $payload;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['start'])) {
                continue;
            }
            $startAt = is_numeric($row['start']) ? (int) $row['start'] : (int) strtotime((string) $row['start']);
            if ($startAt > 20000000000) {
                $startAt = (int) round($startAt / 1000);
            }
            $out[] = [
                'start' => $startAt,
                'mean' => isset($row['mean']) && is_numeric($row['mean']) ? (float) $row['mean'] : null,
                'change' => isset($row['change']) && is_numeric($row['change']) ? (float) $row['change'] : null,
            ];
        }
        return $out;
    }

    public function search(string $query, int $limit = 20): array
    {
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach ($this->states() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['entity_id'] ?? '');
            $domain = strstr($id, '.', true) ?: '';
            if (!in_array($domain, ['sensor', 'number', 'select', 'binary_sensor', 'input_number'], true)) {
                continue;
            }
            $name = (string) ($row['attributes']['friendly_name'] ?? '');
            $hay = mb_strtolower($id . ' ' . $name);
            if ($q !== '' && !str_contains($hay, $q)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'unit' => $row['attributes']['unit_of_measurement'] ?? '',
                'state' => (string) ($row['state'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function get(string $path): mixed
    {
        if (!$this->configured()) {
            throw new RuntimeException('Home Assistant ist nicht verbunden.');
        }
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('HTTP-Client nicht verfügbar.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException($err !== '' ? $err : 'Home Assistant nicht erreichbar.');
        }
        if ($code === 401) {
            throw new RuntimeException('Zugriff abgelehnt. Der Token passt nicht.');
        }
        if ($code >= 400) {
            throw new RuntimeException('Home Assistant antwortet mit Status ' . $code . '.');
        }
        $decoded = json_decode($body, true);
        return $decoded ?? $body;
    }
}
