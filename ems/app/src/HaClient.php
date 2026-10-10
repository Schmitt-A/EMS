<?php
declare(strict_types=1);

final class HaClient implements HaSource
{
    private string $pending = '';

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
    public function stateHistory(string $entityId, int $start, ?int $end = null): array
    {
        if (!is_entity_id($entityId)) {
            return [];
        }
        $query = '/api/history/period/' . gmdate('Y-m-d\TH:i:s\Z', $start) . '?filter_entity_id=' . rawurlencode($entityId) . '&minimal_response=1&no_attributes=1';
        if ($end !== null) {
            $query .= '&end_time=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $end));
        }
        $payload = $this->get($query);
        $rows = is_array($payload) && isset($payload[0]) && is_array($payload[0]) ? $payload[0] : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $when = self::stamp($row);
            if ($when > 0) {
                $out[] = ['t' => $when, 's' => (string) ($row['state'] ?? $row['s'] ?? '')];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
        return $out;
    }

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
            if (!is_array($row)) {
                continue;
            }
            $state = $row['state'] ?? $row['s'] ?? null;
            if (!is_numeric($state)) {
                continue;
            }
            $when = self::stamp($row);
            if ($when <= 0) {
                continue;
            }
            $series[] = ['t' => $when, 'v' => (float) $state];
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
        $payload = $this->socket([
            'type' => 'recorder/statistics_during_period',
            'start_time' => gmdate('Y-m-d\TH:i:s+00:00', $start),
            'end_time' => gmdate('Y-m-d\TH:i:s+00:00', $end),
            'statistic_ids' => [$entityId],
            'period' => $period,
            'types' => ['mean', 'min', 'max', 'change'],
        ]);
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
                'min' => isset($row['min']) && is_numeric($row['min']) ? (float) $row['min'] : null,
                'max' => isset($row['max']) && is_numeric($row['max']) ? (float) $row['max'] : null,
                'change' => isset($row['change']) && is_numeric($row['change']) ? (float) $row['change'] : null,
            ];
        }
        return $out;
    }

    public function search(string $query, int $limit = 20): array
    {
        $q = self::fold(trim($query));
        if (strlen($q) < 2) {
            return [];
        }
        $scored = [];
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
            $idLower = self::fold($id);
            $nameLower = self::fold($name);
            $score = 0;
            if ($idLower === $q) {
                $score = 100;
            } elseif (str_starts_with($idLower, $q) || str_starts_with($nameLower, $q)) {
                $score = 80;
            } elseif (str_contains($idLower, $q)) {
                $score = 60;
            } elseif (str_contains($nameLower, $q)) {
                $score = 40;
            }
            if ($score === 0) {
                continue;
            }
            $scored[] = [$score, [
                'id' => $id,
                'name' => $name,
                'unit' => $row['attributes']['unit_of_measurement'] ?? '',
                'state' => (string) ($row['state'] ?? ''),
            ]];
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0] ?: strcmp($a[1]['id'], $b[1]['id']));
        return array_map(fn ($row) => $row[1], array_slice($scored, 0, $limit));
    }

    private function socket(array $command): mixed
    {
        $parts = parse_url($this->baseUrl);
        $host = (string) ($parts['host'] ?? '');
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = rtrim((string) ($parts['path'] ?? ''), '/') . '/api/websocket';
        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $socket = stream_socket_client($transport . '://' . $host . ':' . $port, $errno, $errstr, 8);
        if (!is_resource($socket)) {
            throw new RuntimeException($errstr !== '' ? $errstr : 'Statistik-Verbindung fehlgeschlagen.');
        }
        stream_set_timeout($socket, 20);
        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80) ? ':' . $port : '');
        $token = str_replace(["\r", "\n"], '', $this->token);
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: {$hostHeader}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nAuthorization: Bearer {$token}\r\n\r\n");
        $handshake = '';
        while (!str_contains($handshake, "\r\n\r\n")) {
            $chunk = fread($socket, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $handshake .= $chunk;
            if (strlen($handshake) > 16384) {
                break;
            }
        }
        $split = explode("\r\n\r\n", $handshake, 2);
        $this->pending = $split[1] ?? '';
        if (!str_contains($split[0], '101')) {
            fclose($socket);
            throw new RuntimeException('Home Assistant öffnet keine Statistik-Verbindung.');
        }
        try {
            $hello = json_decode($this->frame($socket), true);
            if (is_array($hello) && ($hello['type'] ?? '') === 'auth_required') {
                $this->sendFrame($socket, json_encode(['type' => 'auth', 'access_token' => $this->token], JSON_THROW_ON_ERROR));
                $hello = json_decode($this->frame($socket), true);
            }
            if (!is_array($hello) || ($hello['type'] ?? '') !== 'auth_ok') {
                throw new RuntimeException('Zugriff auf die Statistik abgelehnt.');
            }
            $command['id'] = 1;
            $this->sendFrame($socket, json_encode($command, JSON_THROW_ON_ERROR));
            $decoded = null;
            for ($i = 0; $i < 8; $i++) {
                $decoded = json_decode($this->frame($socket), true);
                if (is_array($decoded) && (int) ($decoded['id'] ?? 0) === 1) {
                    break;
                }
            }
        } finally {
            fclose($socket);
        }
        if (!is_array($decoded) || empty($decoded['success'])) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new RuntimeException($message !== '' ? $message : 'Home Assistant liefert keine Statistik.');
        }
        return $decoded['result'] ?? [];
    }

    private function frame($socket): string
    {
        $payload = '';
        while (true) {
            $header = $this->readExact($socket, 2);
            $first = ord($header[0]);
            $second = ord($header[1]);
            $opcode = $first & 0x0f;
            $fin = ($first & 0x80) !== 0;
            $length = $second & 0x7f;
            if ($length === 126) {
                $length = unpack('n', $this->readExact($socket, 2))[1];
            } elseif ($length === 127) {
                $wide = unpack('N2', $this->readExact($socket, 8));
                $length = $wide[1] * 4294967296 + $wide[2];
            }
            if ($length > 8000000) {
                throw new RuntimeException('Die Statistik-Antwort ist unerwartet groß.');
            }
            if (($second & 0x80) !== 0) {
                $mask = $this->readExact($socket, 4);
                $chunk = $this->readExact($socket, (int) $length);
                $plain = '';
                $n = strlen($chunk);
                for ($i = 0; $i < $n; $i++) {
                    $plain .= $chunk[$i] ^ $mask[$i % 4];
                }
                $chunk = $plain;
            } else {
                $chunk = $length > 0 ? $this->readExact($socket, (int) $length) : '';
            }
            if ($opcode === 0x9) {
                $this->sendFrame($socket, $chunk, 0xA);
                continue;
            }
            if ($opcode === 0xA) {
                continue;
            }
            if ($opcode === 0x8) {
                throw new RuntimeException('Die Statistik-Verbindung wurde geschlossen.');
            }
            if ($opcode === 0x1 || $opcode === 0x0 || $opcode === 0x2) {
                $payload .= $chunk;
                if ($fin) {
                    return $payload;
                }
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function stamp(array $row): int
    {
        foreach (['last_changed', 'last_updated', 'lc', 'lu'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $raw = $row[$key];
            if (is_numeric($raw)) {
                $when = (int) $raw;
                return $when > 20000000000 ? (int) round($when / 1000) : $when;
            }
            $when = strtotime((string) $raw);
            if ($when !== false && $when > 0) {
                return $when;
            }
        }
        return 0;
    }

    private static function fold(string $value): string
    {
        return strtolower(strtr($value, ['Ä' => 'ä', 'Ö' => 'ö', 'Ü' => 'ü', 'ẞ' => 'ss', 'ß' => 'ss']));
    }

    private function sendFrame($socket, string $payload, int $opcode = 0x1): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('NN', 0, $length);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($socket, $header . $mask . $masked);
    }

    private function readExact($socket, int $length): string
    {
        $data = $this->pending;
        $this->pending = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Die Statistik-Antwort ist unvollständig.');
            }
            $data .= $chunk;
        }
        if (strlen($data) > $length) {
            $this->pending = substr($data, $length);
            $data = substr($data, 0, $length);
        }
        return $data;
    }

    public function setNumber(string $entityId, float $value): void
    {
        $domain = explode('.', $entityId, 2)[0];
        if (!is_entity_id($entityId) || !in_array($domain, ['number', 'input_number'], true)) {
            throw new InvalidArgumentException('Setzen geht nur bei number- oder input_number-Entitäten.');
        }
        $this->service($domain, 'set_value', ['entity_id' => $entityId, 'value' => $value]);
    }

    /** Nur die Dienste, die EMS braucht: Zahlen, Auswahlen und Knöpfe. */
    public function service(string $domain, string $service, array $data): void
    {
        $allowed = ['number' => ['set_value'], 'input_number' => ['set_value'], 'select' => ['select_option'], 'input_select' => ['select_option'], 'button' => ['press']];
        if (!in_array($service, $allowed[$domain] ?? [], true) || !is_entity_id((string) ($data['entity_id'] ?? '')) || !str_starts_with((string) $data['entity_id'], $domain . '.')) {
            throw new InvalidArgumentException('Dienst ' . $domain . '.' . $service . ' ist für EMS nicht vorgesehen.');
        }
        $this->get('/api/services/' . $domain . '/' . $service, $data);
        // Der nächste Lesezugriff soll den neuen Wert sehen.
        @unlink(data_dir() . '/states-cache.json');
    }

    /** GET, mit $body als POST mit JSON. */
    private function get(string $path, ?array $body = null): mixed
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
        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
        }
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
            // Bei Diensten nennt HA den Grund, etwa eine fehlende Entität oder einen Wert außerhalb der Grenzen.
            $reply = json_decode((string) $body, true);
            $message = is_array($reply) && is_string($reply['message'] ?? null) ? rtrim($reply['message'], '.') : '';
            throw new RuntimeException('Home Assistant antwortet mit Status ' . $code . ($message !== '' ? ': ' . $message : '') . '.');
        }
        $decoded = json_decode($body, true);
        return $decoded ?? $body;
    }
}
