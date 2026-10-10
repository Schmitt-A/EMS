<?php
declare(strict_types=1);

/**
 * Backup-Puffer des Hausspeichers, die einzige Stelle, an der die App etwas in Home Assistant schreibt
 * (number.set_value auf mapping.battery_reserve). Beim Netzladen hebt sie den Puffer auf den Ladestand,
 * solange das Auto lädt, damit der Speicher nichts ins Auto gibt; danach setzt sie ihn auf den Standardwert
 * zurück. Der Zustand liegt in kv.reserve_guard, so setzt auch ein Neustart mitten im Netzladen zurück.
 */
final class Reserve
{
    private const KEY = 'reserve_guard';
    /** Nach einem Fehler schreibt der Recorder frühestens nach dieser Zeit wieder. */
    private const RETRY_S = 60;

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    /** @return array{entity:string, default:?int, protect:bool, discharge_kw:?float, active:bool} */
    public static function settings(array $cfg): array
    {
        $b = is_array($cfg['battery_strategy'] ?? null) ? $cfg['battery_strategy'] : [];
        $default = is_numeric($b['backup_soc'] ?? null) ? (int) round(clamp_float((float) $b['backup_soc'], 0, 100)) : null;
        $discharge = is_numeric($b['discharge_kw'] ?? null) && (float) $b['discharge_kw'] > 0 ? (float) $b['discharge_kw'] : null;
        $entity = (string) ($cfg['mapping']['battery_reserve'] ?? '');
        $protect = !empty($b['grid_protect']);
        return [
            'entity' => $entity,
            'default' => $default,
            'protect' => $protect,
            'discharge_kw' => $discharge,
            'active' => $protect && $entity !== '' && $default !== null,
        ];
    }

    /** Angaben für Energy::suggest() und Energy::allot(). */
    public static function battery(array $cfg): array
    {
        $s = self::settings($cfg);
        return ['reserve_soc' => $s['default'], 'protect' => $s['active'], 'max_discharge_kw' => $s['discharge_kw']];
    }

    public function state(): array
    {
        $state = $this->store->get(self::KEY, []);
        return array_merge(
            ['raised' => false, 'value' => null, 'restore' => null, 'entity' => '', 'since' => null, 'error' => null, 'error_at' => null, 'log' => []],
            is_array($state) ? $state : []
        );
    }

    /**
     * Was jetzt zu tun wäre, ohne zu schreiben. Angehoben wird beim Netzladen, sobald das Auto lädt und der
     * Speicher über dem Standardwert steht. Gehalten wird, solange der Ladevorgang offen ist (Pausen bis zur
     * Ausschaltverzögerung). Zurückgesetzt wird nach dem Moduswechsel, dem Ende des Ladevorgangs, dem Abstecken
     * oder wenn das Schonen abgeschaltet wird.
     *
     * @param array{entity:string, default:?int, active:bool} $settings
     * @return array{action:string, value?:int, entity?:string}
     */
    public static function decide(array $settings, array $state, string $mode, ?float $soc, bool $charging, bool $open, ?bool $connected): array
    {
        $raised = !empty($state['raised']);
        $keep = $settings['active'] && $mode === 'schnell' && $connected !== false && ($charging || ($raised && $open));
        if ($raised && !$keep) {
            $value = $settings['default'] ?? (is_numeric($state['restore'] ?? null) ? (int) $state['restore'] : null);
            $entity = (string) (($state['entity'] ?? '') ?: $settings['entity']);
            return $value === null || $entity === '' ? ['action' => 'lost'] : ['action' => 'restore', 'value' => $value, 'entity' => $entity];
        }
        if (!$raised && $keep && $charging && $soc !== null) {
            $target = min(100, (int) floor($soc));
            if ($target > (int) $settings['default']) {
                return ['action' => 'raise', 'value' => $target, 'entity' => $settings['entity']];
            }
        }
        return ['action' => 'none'];
    }

    /** Prüft und schreibt bei Bedarf. Der Recorder ruft das alle 10 s auf, die Oberfläche nach einem Moduswechsel. */
    public function sync(array $values, array $cfg, int $now, bool $open): array
    {
        $settings = self::settings($cfg);
        $state = $this->state();
        $power = isset($values['wallbox_kw']) ? (float) $values['wallbox_kw'] : null;
        $raw = isset($values['wallbox_car_raw']) ? (string) $values['wallbox_car_raw'] : null;
        $charging = ($power ?? 0.0) > 0.2 || strtolower((string) $raw) === 'charging';
        $soc = isset($values['battery_soc']) ? (float) $values['battery_soc'] : null;
        $plan = self::decide($settings, $state, (string) ($cfg['charge']['mode'] ?? 'smart'), $soc, $charging, $open, Sessions::carConnected($raw, $power));
        if ($plan['action'] === 'none') {
            return $state;
        }
        if ($plan['action'] === 'lost') {
            $state = array_merge($state, ['raised' => false, 'since' => null, 'error' => 'Der Puffer ließ sich nicht zurücksetzen, Standardwert oder Entität fehlt. Bitte in Home Assistant prüfen.', 'error_at' => $now]);
            $this->store->put(self::KEY, $state);
            return $state;
        }
        if (!empty($state['error_at']) && $now - (int) $state['error_at'] < self::RETRY_S) {
            return $state;
        }
        try {
            $this->ha->setNumber((string) $plan['entity'], (float) $plan['value']);
        } catch (Throwable $e) {
            $state = array_merge($state, ['error' => ($plan['action'] === 'raise' ? 'Anheben' : 'Zurücksetzen') . ' fehlgeschlagen: ' . $e->getMessage(), 'error_at' => $now]);
            $this->store->put(self::KEY, $state);
            return $state;
        }
        if ($plan['action'] === 'raise') {
            $state = array_merge($state, ['raised' => true, 'value' => $plan['value'], 'restore' => $settings['default'], 'entity' => $plan['entity'], 'since' => $now, 'error' => null, 'error_at' => null]);
            $state['log'] = self::log($state['log'], $now, 'Netzladen: Puffer auf ' . pct((float) $plan['value']) . ' angehoben.');
        } else {
            $state = array_merge($state, ['raised' => false, 'value' => $plan['value'], 'since' => null, 'error' => null, 'error_at' => null]);
            $state['log'] = self::log($state['log'], $now, 'Puffer zurück auf ' . pct((float) $plan['value']) . '.');
        }
        $this->store->put(self::KEY, $state);
        return $state;
    }

    /**
     * Nach dem Speichern unter Mehr → Speicher: Der Standardwert geht sofort an den Speicher, außer der Puffer ist
     * gerade fürs Netzladen angehoben; dann gilt er beim Zurücksetzen. Gibt den Satz für die Rückmeldung zurück.
     */
    public function apply(array $cfg, int $now): string
    {
        $settings = self::settings($cfg);
        $state = $this->state();
        if ($settings['entity'] === '' || $settings['default'] === null) {
            return 'Gespeichert.';
        }
        if (!empty($state['raised'])) {
            return 'Gespeichert. Der Standardwert gilt, sobald das Netzladen endet.';
        }
        try {
            $this->ha->setNumber($settings['entity'], (float) $settings['default']);
        } catch (Throwable $e) {
            $state = array_merge($state, ['error' => 'Setzen fehlgeschlagen: ' . $e->getMessage(), 'error_at' => $now]);
            $this->store->put(self::KEY, $state);
            return 'Gespeichert, aber Home Assistant hat den Puffer nicht übernommen: ' . $e->getMessage();
        }
        $state = array_merge($state, ['value' => $settings['default'], 'error' => null, 'error_at' => null]);
        $state['log'] = self::log($state['log'], $now, 'Standardwert ' . pct((float) $settings['default']) . ' gesetzt.');
        $this->store->put(self::KEY, $state);
        return 'Gespeichert. Backup-Puffer auf ' . pct((float) $settings['default']) . ' gesetzt.';
    }

    /** Für Speicher, Regelung und Mehr: Stand in einem Satz, dazu Fehler und die letzten Schritte. */
    public function view(array $cfg, ?float $reported = null): array
    {
        $s = self::settings($cfg);
        $state = $this->state();
        $raised = !empty($state['raised']);
        $default = $s['default'] === null ? '' : pct((float) $s['default']);
        $text = match (true) {
            $s['entity'] === '' => 'Kein Backup-Puffer zugeordnet. Beim Netzladen deckt der Speicher mit, bis er seinen Puffer erreicht.',
            $raised => 'Netzladen: Puffer seit ' . date('H:i', (int) $state['since']) . ' auf ' . pct((float) $state['value']) . ' angehoben, der Speicher gibt nichts ab. Danach zurück auf ' . ($default ?: pct((float) $state['restore'])) . '.',
            $s['default'] === null => 'Der Standardwert fehlt. Ohne ihn hebt die App den Puffer beim Netzladen nicht an.',
            !$s['protect'] => 'Backup-Puffer ' . $default . '. Beim Netzladen deckt der Speicher mit.',
            default => 'Backup-Puffer ' . $default . '. Lädt das Auto im Modus Netzladen, hebt die App ihn auf den Ladestand.',
        };
        return [
            'mapped' => $s['entity'] !== '',
            'active' => $s['active'],
            'raised' => $raised,
            'value' => $raised ? (int) $state['value'] : $s['default'],
            'default' => $s['default'],
            'reported' => $reported,
            'text' => $text,
            'error' => $state['error'],
            'log' => array_map(static fn (array $row): array => ['time' => date('d.m. H:i', (int) $row['t']), 'text' => (string) $row['text']], (array) $state['log']),
        ];
    }

    /** @param list<array{t:int, text:string}> $log */
    private static function log(array $log, int $now, string $text): array
    {
        array_unshift($log, ['t' => $now, 'text' => $text]);
        return array_slice($log, 0, 6);
    }
}
