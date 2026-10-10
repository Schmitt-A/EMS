<?php
declare(strict_types=1);

/**
 * Was die App von Home Assistant liest, dazu die Schreibzugriffe bei aktivem EMS: Wallbox (Controller) und
 * Backup-Puffer (Reserve). HaClient spricht mit HA, DemoHaClient liefert Beispieldaten.
 */
interface HaSource
{
    /** Setzt eine number- oder input_number-Entität (Dienst set_value). Wirft bei Fehlern. */
    public function setNumber(string $entityId, float $value): void;

    /** Ruft einen Dienst auf, etwa select.select_option oder button.press. Wirft bei Fehlern. */
    public function service(string $domain, string $service, array $data): void;

    public function configured(): bool;

    public function ping(): array;

    public function states(): array;

    public function state(string $entityId): ?array;

    /** @return array<string, array<string, mixed>> */
    public function index(): array;

    /** @return array<int, array{t:int, v:float}> */
    public function history(string $entityId, int $start, ?int $end = null): array;

    /** Zustände als Text, etwa der Fahrzeugstatus der Wallbox. @return list<array{t:int, s:string}> */
    public function stateHistory(string $entityId, int $start, ?int $end = null): array;

    /** @return array<int, array{start:int, mean:?float, min:?float, max:?float, change:?float}> */
    public function statistics(string $entityId, int $start, int $end, string $period = 'hour'): array;

    public function search(string $query, int $limit = 20): array;
}
