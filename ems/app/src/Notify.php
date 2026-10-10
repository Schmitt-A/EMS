<?php
declare(strict_types=1);

/**
 * Mitteilungen über die Home-Assistant-App (notify.mobile_app_…): welche Ereignisse, an welche Geräte, mit Titel
 * und Text aus Vorlagen mit Platzhaltern wie {auto} oder {geladen}. tick() läuft im Recorder nach Ladevorgängen,
 * Regelung und Puffer. Es erkennt Ereignisse an Zustandswechseln (detect() ohne Seiteneffekte) und schickt sie an
 * die gewählten Geräte. Einstellungen in kv.notify, Zustand in kv.notify_state, gesendete Mitteilungen in der
 * Tabelle notifications. Mit dem Hauptschalter hat das nichts zu tun: Mitteilungen gehen auch, wenn EMS nur anzeigt.
 */
final class Notify
{
    public const KEY = 'notify';
    private const STATE = 'notify_state';
    /** Ruht das Laden länger, gilt es als beendet und der nächste Zyklus als neuer Start. */
    public const PAUSE_S = 600;
    /** So lange müssen Messwerte fehlen, das Auto trotz Freigabe nicht laden oder Sonne übrig sein. */
    public const OFFLINE_S = 600;
    public const STUCK_S = 300;
    public const SURPLUS_S = 600;
    public const TITLE_MAX = 120;
    public const MESSAGE_MAX = 500;
    private const LOG_KEEP = 200;

    public const GROUPS = [
        'wichtig' => 'Wichtig',
        'laden' => 'Laden',
        'speicher' => 'Speicher und Sonne',
        'berichte' => 'Berichte',
    ];

    /**
     * Ereignisse mit Gruppe, Name, Icon, Werkseinstellung (on, important), wann sie kommen, Standardtext, den
     * passenden Platzhaltern (vars), eigenen Beschreibungen dazu (labels) und Grenzen (params).
     */
    public const EVENTS = [
        'fault' => [
            'group' => 'wichtig', 'label' => 'Störung der Regelung', 'icon' => 'triangle-alert', 'on' => true, 'important' => true,
            'hint' => 'Ein anderer Regler schreibt an die Wallbox, ein Schreibzugriff schlägt fehl oder der Backup-Puffer lässt sich nicht setzen.',
            'title' => 'EMS: Störung', 'message' => '{grund}',
            'vars' => ['grund'], 'params' => [],
        ],
        'offline' => [
            'group' => 'wichtig', 'label' => 'Keine Messwerte', 'icon' => 'wifi-off', 'on' => true, 'important' => true,
            'hint' => 'PV, Speicher, Zähler, Haus oder Wallbox liefern seit 10 Minuten keine Werte.',
            'title' => 'EMS: keine Messwerte', 'message' => 'Seit {seit} ohne Werte: {grund}.',
            'vars' => ['grund', 'seit'], 'params' => [],
        ],
        'stuck' => [
            'group' => 'wichtig', 'label' => 'Auto lädt nicht', 'icon' => 'circle-alert', 'on' => true, 'important' => true,
            'hint' => 'EMS hat die Wallbox freigegeben, aber das Auto nimmt seit 5 Minuten keinen Strom ab. Nur, wenn EMS regelt.',
            'title' => '{auto} lädt nicht', 'message' => 'Die Wallbox ist seit {seit} freigegeben, das Auto nimmt aber keinen Strom ab. Ladestand {ladestand}.',
            'vars' => ['seit', 'ladestand', 'modus'], 'params' => [],
        ],
        'plug' => [
            'group' => 'laden', 'label' => 'Auto angesteckt', 'icon' => 'plug', 'on' => false, 'important' => false,
            'hint' => 'Sobald die Wallbox ein Auto meldet.',
            'title' => '{auto} angesteckt', 'message' => 'Modus {modus}, Sonne gerade {pv}. Ladestand {ladestand}.',
            'vars' => ['modus', 'pv', 'ladestand', 'reichweite'], 'params' => [],
        ],
        'start' => [
            'group' => 'laden', 'label' => 'Laden gestartet', 'icon' => 'zap', 'on' => true, 'important' => false,
            'hint' => 'Beim ersten Laden nach dem Anstecken und wieder nach einer Pause von mehr als 10 Minuten.',
            'title' => '{auto} lädt', 'message' => 'Mit {leistung} im Modus {modus}. Ladestand {ladestand}.',
            'vars' => ['leistung', 'modus', 'ladestand', 'pv'], 'params' => [],
        ],
        'stop' => [
            'group' => 'laden', 'label' => 'Laden beendet', 'icon' => 'circle-check', 'on' => true, 'important' => false,
            'hint' => 'Wenn das Auto voll oder abgesteckt ist oder das Laden länger als 10 Minuten ruht. Kurze Pausen der Sonne melden sich nicht.',
            'title' => '{auto}: Laden beendet', 'message' => '{geladen} in {dauer}, {sonnenanteil} aus der Sonne, {kosten}. Ladestand {ladestand}.',
            'vars' => ['geladen', 'strecke', 'dauer', 'sonnenanteil', 'kosten', 'ladestand'], 'params' => [],
        ],
        'target' => [
            'group' => 'laden', 'label' => 'Ladeziel erreicht', 'icon' => 'target', 'on' => true, 'important' => false,
            'hint' => 'Wenn ein Ladeziel erreicht ist; EMS wechselt dann in den Folgemodus.',
            'title' => 'Ladeziel erreicht', 'message' => '{ziel} erreicht, {geladen} geladen. Weiter mit {modus}.',
            'vars' => ['ziel', 'geladen', 'strecke', 'modus', 'ladestand'], 'labels' => ['modus' => 'Folgemodus'], 'params' => [],
        ],
        'unplug' => [
            'group' => 'laden', 'label' => 'Auto abgesteckt', 'icon' => 'unplug', 'on' => false, 'important' => false,
            'hint' => 'Mit der Bilanz des ganzen Ladevorgangs vom Anstecken bis zum Abstecken.',
            'title' => '{auto} abgesteckt', 'message' => '{geladen} in {dauer}, {sonnenanteil} aus der Sonne, {kosten}, gespart {ersparnis}.',
            'vars' => ['geladen', 'strecke', 'dauer', 'sonnenanteil', 'kosten', 'ersparnis'], 'params' => [],
        ],
        'mode' => [
            'group' => 'laden', 'label' => 'Lademodus geändert', 'icon' => 'sliders-horizontal', 'on' => false, 'important' => false,
            'hint' => 'Wenn jemand den Modus wechselt oder EMS nach einem Ladeziel oder dem Abstecken umschaltet.',
            'title' => 'Lademodus {modus}', 'message' => 'Vorher {vorher}. Sonne gerade {pv}, {auto} bei {ladestand}.',
            'vars' => ['modus', 'vorher', 'pv', 'ladestand'], 'params' => [],
        ],
        'control' => [
            'group' => 'laden', 'label' => 'Regelung ein oder aus', 'icon' => 'power', 'on' => false, 'important' => false,
            'hint' => 'Wenn der Hauptschalter „EMS regelt die Wallbox“ umgelegt wird.',
            'title' => 'EMS-Regelung {regelung}', 'message' => 'Seit {uhrzeit}, Lademodus {modus}.',
            'vars' => ['regelung', 'modus', 'uhrzeit'], 'params' => [],
        ],
        'battery_full' => [
            'group' => 'speicher', 'label' => 'Speicher voll', 'icon' => 'battery-full', 'on' => false, 'important' => false,
            'hint' => 'Wenn der Speicher die Grenze erreicht, höchstens einmal am Tag.',
            'title' => 'Speicher voll', 'message' => 'Speicher bei {speicher}, {einspeisung} gehen ins Netz.',
            'vars' => ['speicher', 'einspeisung', 'pv'], 'params' => ['full_soc'],
        ],
        'battery_low' => [
            'group' => 'speicher', 'label' => 'Speicher fast leer', 'icon' => 'battery-low', 'on' => false, 'important' => false,
            'hint' => 'Wenn der Speicher unter die Grenze fällt, höchstens einmal am Tag.',
            'title' => 'Speicher bei {speicher}', 'message' => 'Haus {haus}, Sonne {pv}, aus dem Netz {netzbezug}.',
            'vars' => ['speicher', 'haus', 'pv', 'netzbezug'], 'params' => ['low_soc'],
        ],
        'surplus' => [
            'group' => 'speicher', 'label' => 'Sonne übrig', 'icon' => 'sun', 'on' => false, 'important' => false,
            'hint' => 'Wenn 10 Minuten lang mehr als die Grenze ins Netz geht und kein Auto steckt, höchstens einmal am Tag.',
            'title' => 'Sonne übrig', 'message' => '{einspeisung} gehen ins Netz, {auto} ist nicht angesteckt. Speicher {speicher}.',
            'vars' => ['einspeisung', 'speicher', 'pv'], 'params' => ['surplus_kw'],
        ],
        'sunny' => [
            'group' => 'speicher', 'label' => 'Sonniger Tag morgen', 'icon' => 'sun-medium', 'on' => false, 'important' => false,
            'hint' => 'Zur Berichtszeit, wenn die Prognose für morgen über der Grenze liegt.',
            'title' => 'Morgen viel Sonne', 'message' => 'Erwartet: {prognose_morgen}. Gute Gelegenheit, {auto} mit Sonne zu laden.',
            'vars' => ['prognose_morgen', 'prognose_heute', 'ertrag_heute'], 'params' => ['sunny_kwh', 'report_time'],
        ],
        'daily' => [
            'group' => 'berichte', 'label' => 'Tagesbericht', 'icon' => 'calendar', 'on' => false, 'important' => false,
            'hint' => 'Jeden Tag zur Berichtszeit.',
            'title' => 'Tagesbericht {datum}', 'message' => 'Sonne {ertrag_heute} (Prognose {prognose_heute}), ins Auto {geladen_heute}, Speicher {speicher}. Morgen erwartet: {prognose_morgen}.',
            'vars' => ['ertrag_heute', 'prognose_heute', 'geladen_heute', 'speicher', 'prognose_morgen', 'datum'], 'params' => ['report_time'],
        ],
        'monthly' => [
            'group' => 'berichte', 'label' => 'Monatsbericht', 'icon' => 'chart-column', 'on' => false, 'important' => false,
            'hint' => 'Am 1. des Monats zur Berichtszeit, mit den Ladevorgängen des Vormonats.',
            'title' => '{monat}: {geladen} geladen', 'message' => '{vorgaenge} Ladevorgänge, {sonnenanteil} aus der Sonne, {kosten}, gespart {ersparnis}.',
            'vars' => ['monat', 'geladen', 'strecke', 'vorgaenge', 'sonnenanteil', 'kosten', 'ersparnis', 'dauer'],
            'labels' => ['geladen' => 'Im Monat geladen', 'strecke' => 'Kilometer aus der Energie des Monats', 'sonnenanteil' => 'Anteil Sonne im Monat', 'kosten' => 'Kosten im Monat', 'ersparnis' => 'Im Monat gespart', 'dauer' => 'Ladedauer im Monat'],
            'params' => ['report_time'],
        ],
    ];

    /** Vorlage der Test-Mitteilung. */
    public const TEST = ['title' => 'EMS Test', 'message' => 'Mitteilungen kommen an. Sonne {pv}, Speicher {speicher}, {uhrzeit}.'];

    /** Platzhalter und was sie bedeuten. grund, seit, monat und vorgaenge haben nur bei ihren Ereignissen einen Wert. */
    public const VARS = [
        'auto' => 'Name des Autos',
        'ladepunkt' => 'Name des Ladepunkts',
        'modus' => 'Lademodus',
        'leistung' => 'Ladeleistung jetzt',
        'ladestand' => 'Ladestand des Autos',
        'reichweite' => 'Reichweite',
        'limit' => 'Ladelimit',
        'geladen' => 'Geladen seit dem Anstecken',
        'strecke' => 'Kilometer aus der geladenen Energie',
        'dauer' => 'Ladedauer',
        'sonnenanteil' => 'Anteil aus der Sonne',
        'kosten' => 'Kosten des Ladevorgangs',
        'ersparnis' => 'Gespart gegenüber Netzstrom',
        'ziel' => 'Ladeziel',
        'regelung' => 'EMS-Regelung an oder aus',
        'pv' => 'Sonne jetzt',
        'haus' => 'Haus jetzt',
        'speicher' => 'Ladestand des Speichers',
        'einspeisung' => 'Einspeisung jetzt',
        'netzbezug' => 'Netzbezug jetzt',
        'ertrag_heute' => 'Sonne heute erzeugt',
        'prognose_heute' => 'Prognose heute',
        'prognose_morgen' => 'Prognose morgen',
        'geladen_heute' => 'Heute ins Auto geladen',
        'uhrzeit' => 'Uhrzeit',
        'datum' => 'Datum',
        'grund' => 'Was los ist',
        'seit' => 'Seit wann',
        'vorher' => 'Modus davor',
        'monat' => 'Monat',
        'vorgaenge' => 'Ladevorgänge im Monat',
    ];

    /** Platzhalter, die nur bei diesen Ereignissen einen Wert haben. */
    private const ONLY = ['grund' => ['fault', 'offline'], 'seit' => ['offline', 'stuck'], 'vorher' => ['mode'], 'monat' => ['monthly'], 'vorgaenge' => ['monthly']];

    /** Grenzen der Ereignisse: Name, Einheit, Bereich, Standard, Nachkommastellen, Hinweis. report_time ist eine Uhrzeit. */
    public const PARAMS = [
        'full_soc' => ['label' => 'Voll ab', 'unit' => '%', 'min' => 50, 'max' => 100, 'default' => 100, 'decimals' => 0, 'hint' => 'Prozent. Die sonnenBatterie meldet voll mit 100.'],
        'low_soc' => ['label' => 'Fast leer bei', 'unit' => '%', 'min' => 5, 'max' => 80, 'default' => 20, 'decimals' => 0, 'hint' => 'Prozent.'],
        'surplus_kw' => ['label' => 'Ab Einspeisung', 'unit' => 'kW', 'min' => 0.5, 'max' => 30, 'default' => 2, 'decimals' => 1, 'hint' => 'kW, die mindestens 10 Minuten ins Netz gehen.'],
        'sunny_kwh' => ['label' => 'Ab Prognose', 'unit' => 'kWh', 'min' => 1, 'max' => 300, 'default' => 25, 'decimals' => 0, 'hint' => 'kWh, die für morgen mindestens erwartet werden.'],
        'report_time' => ['label' => 'Berichtszeit', 'unit' => '', 'default' => '20:00', 'hint' => 'Gilt für Tagesbericht, Monatsbericht und den sonnigen Tag.'],
    ];

    /** Fehlende Messwerte nach Gerät, für {grund} bei „Keine Messwerte“. */
    private const DEVICES = [
        'pv_power' => 'PV', 'battery_soc' => 'Speicher', 'battery_charge' => 'Speicher', 'battery_discharge' => 'Speicher', 'battery_signed' => 'Speicher',
        'grid_import' => 'Zähler', 'grid_export' => 'Zähler', 'grid_signed' => 'Zähler', 'house_power' => 'Haus',
        'wallbox_power' => 'Wallbox', 'wallbox_car' => 'Wallbox', 'wallbox_force' => 'Wallbox', 'wallbox_amps' => 'Wallbox', 'wallbox_phases' => 'Wallbox',
    ];

    public const STATUS = ['sent' => 'gesendet', 'silent' => 'leise', 'demo' => 'Demo', 'error' => 'Fehler'];

    private static int $slugTried = 0;
    /** @var ?array{0: int, 1: ?string} Zeitpunkt der Abfrage und Version von Home Assistant */
    private static ?array $coreVersion = null;

    public function __construct(private ConfigStore $store, private HaSource $ha) {}

    /**
     * Einstellungen geprüft und mit Werkseinstellungen ergänzt: Geräte, Ruhezeit, Grenzen und je Ereignis on,
     * important, title, message und custom (eigener Text).
     */
    public static function settings(array $cfg): array
    {
        $raw = is_array($cfg[self::KEY] ?? null) ? $cfg[self::KEY] : [];
        $events = [];
        foreach (self::EVENTS as $key => $def) {
            $own = is_array($raw['events'][$key] ?? null) ? $raw['events'][$key] : [];
            $title = self::text($own['title'] ?? null, self::TITLE_MAX);
            $message = self::text($own['message'] ?? null, self::MESSAGE_MAX, true);
            $events[$key] = [
                'on' => array_key_exists('on', $own) ? (bool) $own['on'] : $def['on'],
                'important' => array_key_exists('important', $own) ? (bool) $own['important'] : $def['important'],
                'title' => $title ?? $def['title'],
                'message' => $message ?? $def['message'],
                'custom' => ($title !== null && $title !== $def['title']) || ($message !== null && $message !== $def['message']),
            ];
        }
        $test = is_array($raw['test'] ?? null) ? $raw['test'] : [];
        $out = [
            'targets' => self::targets($raw['targets'] ?? []),
            'open_app' => (bool) ($raw['open_app'] ?? true),
            'quiet' => (bool) ($raw['quiet'] ?? false),
            'quiet_from' => self::clock($raw['quiet_from'] ?? null, '22:00'),
            'quiet_to' => self::clock($raw['quiet_to'] ?? null, '07:00'),
            'report_time' => self::clock($raw['report_time'] ?? null, (string) self::PARAMS['report_time']['default']),
            'events' => $events,
            'test' => [
                'title' => self::text($test['title'] ?? null, self::TITLE_MAX) ?? self::TEST['title'],
                'message' => self::text($test['message'] ?? null, self::MESSAGE_MAX, true) ?? self::TEST['message'],
            ],
        ];
        foreach (self::PARAMS as $key => $param) {
            if (isset($param['min'])) {
                $out[$key] = is_numeric($raw[$key] ?? null) ? clamp_float((float) $raw[$key], (float) $param['min'], (float) $param['max']) : (float) $param['default'];
            }
        }
        return $out;
    }

    /** Speicherform für kv.notify und den Export: eigene Texte nur, wenn sie vom Standard abweichen. */
    public static function stored(array $settings): array
    {
        $events = [];
        foreach ($settings['events'] as $key => $event) {
            $def = self::EVENTS[$key];
            $events[$key] = [
                'on' => $event['on'],
                'important' => $event['important'],
                'title' => $event['title'] !== $def['title'] ? $event['title'] : null,
                'message' => $event['message'] !== $def['message'] ? $event['message'] : null,
            ];
        }
        $out = array_intersect_key($settings, array_flip(['targets', 'open_app', 'quiet', 'quiet_from', 'quiet_to', 'report_time', 'full_soc', 'low_soc', 'surplus_kw', 'sunny_kwh']));
        $out['events'] = $events;
        $out['test'] = [
            'title' => $settings['test']['title'] !== self::TEST['title'] ? $settings['test']['title'] : null,
            'message' => $settings['test']['message'] !== self::TEST['message'] ? $settings['test']['message'] : null,
        ];
        return $out;
    }

    /** Einstellungen aus einer importierten Datei: geprüft wie ein Formular. */
    public static function clean(array $raw): array
    {
        return self::stored(self::settings([self::KEY => $raw]));
    }

    /** Kurzfassung für die Liste der Bereiche. */
    public static function summary(array $cfg): string
    {
        $s = self::settings($cfg);
        $on = count(array_filter($s['events'], static fn (array $event): bool => $event['on']));
        $devices = count($s['targets']);
        return ($devices === 0 ? 'Kein Gerät gewählt' : ($devices === 1 ? '1 Gerät' : $devices . ' Geräte')) . ' · ' . $on . ' von ' . count(self::EVENTS) . ' Meldungen an';
    }

    /** Vorlage aus dem Formular: ohne Steuerzeichen, der Titel einzeilig, gekürzt; leer heißt Standard (null). */
    public static function text(mixed $value, int $max, bool $lines = false): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = $lines
            ? preg_replace(['/[^\S\n]*\n[^\S\n]*/u', '/\n{3,}/', '/(?!\n)\p{Cc}/u'], ["\n", "\n\n", ' '], $text)
            : preg_replace('/\p{Cc}+/u', ' ', $text);
        $text = trim((string) $text);
        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** Uhrzeit als HH:MM, sonst $fallback; nimmt auch 7:05 und 07:05:00. */
    public static function clock(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($value), $m) ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : $fallback;
    }

    /** Geräte aus Formular oder Datei: Namen von notify-Diensten, ohne doppelte. @return list<string> */
    public static function targets(mixed $value): array
    {
        $list = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $out = [];
        foreach ($list as $item) {
            $item = strtolower(trim((string) (is_scalar($item) ? $item : '')));
            if (preg_match('/^[a-z0-9_]{1,64}$/', $item) && $item !== 'send_message' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Setzt Platzhalter ein; unbekannte bleiben stehen, damit man den Tippfehler sieht. */
    public static function render(string $template, array $context): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => array_key_exists($m[1], $context) ? (string) $context[$m[1]] : $m[0], $template) ?? $template;
    }

    /** Platzhalter, die es nicht gibt. @return list<string> */
    public static function unknown(string $template): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $template, $m);
        return array_values(array_unique(array_filter($m[1], static fn (string $key): bool => !isset(self::VARS[$key]))));
    }

    /** Platzhalter fürs Sheet: erst die des Ereignisses, dann alle übrigen mit Wert. @return array{0: list<string>, 1: list<string>} */
    public static function varsFor(string $event): array
    {
        $own = $event === 'test' ? ['pv', 'speicher', 'uhrzeit', 'auto'] : self::EVENTS[$event]['vars'];
        $rest = [];
        foreach (array_keys(self::VARS) as $key) {
            if (!in_array($key, $own, true) && (!isset(self::ONLY[$key]) || in_array($event, self::ONLY[$key], true))) {
                $rest[] = $key;
            }
        }
        return [$own, $rest];
    }

    /** Beschreibung eines Platzhalters bei einem Ereignis. */
    public static function varLabel(string $event, string $key): string
    {
        return self::EVENTS[$event]['labels'][$key] ?? self::VARS[$key] ?? $key;
    }

    /**
     * Werte der Platzhalter aus dem Snapshot, formatiert wie auf den Seiten. $session (Sessions::merge) liefert
     * geladen, dauer, sonnenanteil, kosten und ersparnis; $extra ergänzt oder ersetzt, etwa grund oder ziel.
     */
    public static function context(array $snap, int $now, ?array $session = null, array $extra = []): array
    {
        $v = is_array($snap['values'] ?? null) ? $snap['values'] : [];
        $cfg = is_array($snap['cfg'] ?? null) ? $snap['cfg'] : [];
        $vehicle = is_array($snap['vehicle'] ?? null) ? $snap['vehicle'] : [];
        $forecast = is_array($snap['forecast'] ?? null) ? $snap['forecast'] : [];
        $n = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $out = [
            'auto' => trim((string) ($vehicle['name'] ?? $cfg['vehicle']['name'] ?? '')) ?: 'Auto',
            'ladepunkt' => trim((string) ($snap['chargepoint']['name'] ?? $cfg['chargepoint']['name'] ?? '')) ?: 'Wallbox',
            'modus' => Energy::modeLabel((string) ($cfg['charge']['mode'] ?? 'smart')),
            'leistung' => kw($n($v['wallbox_kw'] ?? null)),
            'ladestand' => pct($n($vehicle['soc'] ?? null)),
            'reichweite' => with_unit($n($vehicle['range_km'] ?? null), 0, 'km'),
            'limit' => pct($n($vehicle['limit'] ?? null)),
            'geladen' => '—',
            'strecke' => '—',
            'dauer' => '—',
            'sonnenanteil' => '—',
            'kosten' => '—',
            'ersparnis' => '—',
            'ziel' => (string) ($snap['target']['label'] ?? '') ?: '—',
            'regelung' => Controller::active($cfg) ? 'an' : 'aus',
            'pv' => kw($n($v['pv_kw'] ?? null)),
            'haus' => kw($n($snap['balance']['house_base_kw'] ?? $v['house_kw'] ?? null)),
            'speicher' => pct($n($v['battery_soc'] ?? null)),
            'einspeisung' => kw($n($v['grid_export_kw'] ?? null)),
            'netzbezug' => kw($n($v['grid_import_kw'] ?? null)),
            'ertrag_heute' => kwh($n($snap['yield_today'] ?? null)),
            'prognose_heute' => kwh($n($forecast['today_kwh'] ?? null), 0),
            'prognose_morgen' => kwh($n($forecast['tomorrow_kwh'] ?? null), 0),
            'geladen_heute' => '—',
            'uhrzeit' => $local->format('H:i'),
            'datum' => short_day_label($local),
            'grund' => '—',
            'seit' => '—',
            'vorher' => '—',
            'monat' => '—',
            'vorgaenge' => '—',
        ];
        if ($session !== null) {
            $cost = Sessions::cost($session, (array) ($cfg['tariffs'] ?? []));
            $out['geladen'] = kwh((float) $session['energy_kwh']);
            $out['strecke'] = self::km((float) $session['energy_kwh'], $vehicle['consumption_kwh'] ?? null);
            $out['dauer'] = duration_clock((int) $session['duration_s']);
            $out['sonnenanteil'] = pct((float) $cost['solar_pct']);
            $out['kosten'] = euro((float) $cost['cost']);
            $out['ersparnis'] = euro((float) $cost['saved']);
        }
        return array_merge($out, array_map('strval', $extra));
    }

    /**
     * Werte für ein Ereignis: der Ladevorgang aus $data['session'] (sonst der laufende oder letzte), der Modus nach
     * einem Wechsel, heute geladen, beim Monatsbericht die Summen des Monats. Steckt das Auto noch, gilt für
     * {geladen} derselbe Zähler wie auf der Karte.
     */
    public function contextFor(string $event, array $data, array $snap, int $now): array
    {
        $cfg = $this->store->all();
        $snap['cfg'] = $cfg;
        $sessions = new Sessions($this->store);
        $session = null;
        if (isset($data['session'])) {
            $session = $sessions->group((int) $data['session']);
        } elseif (is_array($snap['plug_group'] ?? null)) {
            $session = $snap['plug_group'];
        } elseif (is_array($snap['session'] ?? null)) {
            $session = Sessions::merge([$snap['session']]);
        }
        $extra = array_intersect_key($data, array_flip(['grund', 'seit', 'ziel', 'vorher']));
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $stmt = $this->store->pdo()->prepare('SELECT COALESCE(SUM(energy_kwh), 0) FROM sessions WHERE started_at >= ?');
        $stmt->execute([$local->setTime(0, 0)->format('c')]);
        $extra['geladen_heute'] = kwh((float) $stmt->fetchColumn());
        $current = $snap['plug_group']['id'] ?? $snap['session']['id'] ?? null;
        $counter = $snap['chargepoint']['session_kwh'] ?? null;
        if ($counter !== null && !empty($snap['vehicle']['connected']) && $session !== null && $current !== null && (int) $session['id'] === (int) $current) {
            $extra['geladen'] = kwh((float) $counter);
            $extra['strecke'] = self::km((float) $counter, $snap['vehicle']['consumption_kwh'] ?? null);
        }
        if ($event === 'monthly') {
            $month = (string) ($data['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $month = $local->modify('first day of last month')->format('Y-m');
            }
            $sum = Sessions::summary($sessions->month($month), (array) $cfg['tariffs']);
            $extra = array_merge($extra, [
                'monat' => month_label($month),
                'geladen' => kwh((float) $sum['energy']),
                'strecke' => self::km((float) $sum['energy'], $snap['vehicle']['consumption_kwh'] ?? null),
                'dauer' => duration_clock((int) $sum['duration']),
                'sonnenanteil' => pct($sum['solar_pct'] === null ? null : (float) $sum['solar_pct']),
                'kosten' => euro((float) $sum['cost']),
                'ersparnis' => euro((float) $sum['saved']),
                'vorgaenge' => (string) $sum['count'],
            ]);
        }
        return self::context($snap, $now, $session, $extra);
    }

    /** „≈ 80 km“ aus Energie ab Wallbox und Verbrauch, „—“ ohne Verbrauch. */
    private static function km(float $kwh, mixed $consumption): string
    {
        $km = Energy::kmFromKwh($kwh, is_numeric($consumption) ? (float) $consumption : null);
        return $km === null ? '—' : '≈ ' . with_unit($km, 0, 'km');
    }

    /** Beispielwerte für Vorschau und Test, wo ein Ereignis eigene Werte mitbringt. */
    public static function sampleData(string $event, int $now): array
    {
        return match ($event) {
            'fault' => ['grund' => 'Ein anderer Regler schreibt ebenfalls an die Wallbox, etwa evcc. EMS hat die Regelung pausiert.'],
            'offline' => ['grund' => 'Wallbox', 'seit' => date('H:i', $now - self::OFFLINE_S)],
            'stuck' => ['seit' => date('H:i', $now - self::STUCK_S)],
            'mode' => ['vorher' => Energy::modeLabel('aus')],
            default => [],
        };
    }

    public function state(): array
    {
        $state = $this->store->get(self::STATE, []);
        return array_merge(self::blank(), is_array($state) ? $state : []);
    }

    private static function blank(): array
    {
        return [
            'init' => false, 'plugged' => null, 'plug_at' => null, 'open_id' => null, 'stop' => null, 'done' => null,
            'target_t' => 0, 'faults' => [], 'offline_since' => null, 'offline_sent' => false, 'stuck_since' => null, 'stuck_sent' => false,
            'full_armed' => true, 'full_day' => null, 'low_armed' => true, 'low_day' => null, 'surplus_since' => null, 'surplus_day' => null,
            'report_day' => null, 'mode' => null, 'control' => null,
        ];
    }

    /**
     * Ein Schritt ohne Seiteneffekte: welche Ereignisse seit dem letzten Stand eingetreten sind. Beim ersten Lauf
     * übernimmt er nur den Stand. Erkannt werden alle Ereignisse; ob eines rausgeht, entscheiden die Einstellungen.
     *
     * @param array $in aus inputs(): connected (?bool), charging, full, open_id, latest_id, latest_at, battery_soc,
     *                  export_kw, missing (Geräte), faults (Art => Text), stuck, target_done, tomorrow_kwh, mode, control
     * @param array $p Grenzen aus settings(): full_soc, low_soc, surplus_kw, sunny_kwh, report_time
     * @return array{state: array, events: list<array{event: string, data: array}>}
     */
    public static function detect(array $state, array $in, array $p, int $now): array
    {
        $s = array_merge(self::blank(), $state);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $today = $local->format('Y-m-d');
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $soc = isset($in['battery_soc']) ? (float) $in['battery_soc'] : null;
        $full = (float) ($p['full_soc'] ?? 100);
        $low = (float) ($p['low_soc'] ?? 20);
        $report = self::minutes((string) ($p['report_time'] ?? '20:00'));
        $connected = $in['connected'] ?? null;
        $open = isset($in['open_id']) ? (int) $in['open_id'] : null;
        $faults = (array) ($in['faults'] ?? []);
        if (!$s['init']) {
            // Erster Lauf: den Stand übernehmen, nichts für Vergangenes melden.
            return ['state' => array_merge($s, [
                'init' => true,
                'plugged' => $connected,
                'plug_at' => $connected === true ? $now : null,
                'open_id' => $open,
                'target_t' => (int) ($in['target_done']['t'] ?? 0),
                'faults' => array_fill_keys(array_keys($faults), true),
                'full_armed' => $soc === null || $soc < $full - 5,
                'low_armed' => $soc === null || $soc > $low + 5,
                'report_day' => $minute >= $report ? $today : null,
                'mode' => $in['mode'] ?? null,
                'control' => isset($in['control']) ? (bool) $in['control'] : null,
            ]), 'events' => []];
        }
        $events = [];
        $emit = static function (string $event, array $data = []) use (&$events): void {
            $events[] = ['event' => $event, 'data' => $data];
        };

        // Angesteckt, vor einem Start im selben Schritt; aus einem unbekannten Stand heraus ohne Meldung.
        if ($connected === true && $s['plugged'] !== true) {
            if ($s['plugged'] === false) {
                $emit('plug');
            }
            $s['plug_at'] = $now;
        }

        // Ladezyklen: Start beim ersten Zyklus und nach einer Pause über PAUSE_S. Das Ende kommt nach dieser Pause,
        // sofort, wenn das Auto voll oder abgesteckt ist. Kürzere Pausen der Sonne melden weder Ende noch Start.
        if ($open !== $s['open_id']) {
            $was = $s['open_id'] === null ? null : (int) $s['open_id'];
            if ($was !== null && $was !== $s['done']) {
                if (!empty($in['full']) || $connected === false) {
                    $emit('stop', ['session' => $was]);
                } else {
                    $s['stop'] = ['id' => $was, 'at' => $now];
                }
            }
            if ($open !== null) {
                if ($s['stop'] !== null) {
                    $s['stop'] = null;
                } else {
                    $emit('start', ['session' => $open]);
                }
            }
            $s['open_id'] = $open;
            $s['done'] = null;
        }
        if ($s['stop'] !== null && (!empty($in['full']) || $connected === false || $now - (int) $s['stop']['at'] >= self::PAUSE_S)) {
            $emit('stop', ['session' => (int) $s['stop']['id']]);
            $s['stop'] = null;
        }

        // Ladevorgang dieses Ansteckens: der letzte Zyklus, wenn er danach begann.
        $current = isset($in['latest_id'], $in['latest_at']) && $s['plug_at'] !== null && (int) $in['latest_at'] >= (int) $s['plug_at'] - 120 ? (int) $in['latest_id'] : null;

        // Abgesteckt: ein noch offener Zyklus endet damit (beim Schließen nicht noch einmal), dann die Bilanz.
        if ($connected === false && $s['plugged'] !== false) {
            if ($s['plugged'] === true) {
                if ($s['open_id'] !== null && $s['done'] !== (int) $s['open_id']) {
                    $emit('stop', ['session' => (int) $s['open_id']]);
                    $s['done'] = (int) $s['open_id'];
                }
                $emit('unplug', ['session' => $current]);
            }
            $s['plug_at'] = null;
            $s['stuck_sent'] = false;
        }
        if ($connected !== null) {
            $s['plugged'] = $connected;
        }

        $done = $in['target_done'] ?? null;
        if (is_array($done) && (int) ($done['t'] ?? 0) > (int) $s['target_t']) {
            $emit('target', ['ziel' => (string) ($done['label'] ?? ''), 'session' => $open ?? $current]);
            $s['target_t'] = (int) $done['t'];
        }

        // Lademodus und Hauptschalter: jeder Wechsel, gleich von wem.
        $mode = isset($in['mode']) ? (string) $in['mode'] : null;
        if ($mode !== null && $s['mode'] !== null && $mode !== $s['mode']) {
            $emit('mode', ['vorher' => Energy::modeLabel((string) $s['mode'])]);
        }
        $s['mode'] = $mode ?? $s['mode'];
        if (isset($in['control']) && $s['control'] !== null && (bool) $in['control'] !== (bool) $s['control']) {
            $emit('control');
        }
        $s['control'] = isset($in['control']) ? (bool) $in['control'] : $s['control'];

        // Störungen: jede Art einmal, wenn sie neu auftritt.
        foreach ($faults as $key => $text) {
            if (empty($s['faults'][$key])) {
                $emit('fault', ['grund' => (string) $text]);
            }
        }
        $s['faults'] = array_fill_keys(array_keys($faults), true);

        $missing = (array) ($in['missing'] ?? []);
        if ($missing) {
            $s['offline_since'] ??= $now;
            if (!$s['offline_sent'] && $now - (int) $s['offline_since'] >= self::OFFLINE_S) {
                $emit('offline', ['grund' => implode(', ', $missing), 'seit' => date('H:i', (int) $s['offline_since'])]);
                $s['offline_sent'] = true;
            }
        } else {
            $s['offline_since'] = null;
            $s['offline_sent'] = false;
        }

        if (!empty($in['stuck'])) {
            $s['stuck_since'] ??= $now;
            if (!$s['stuck_sent'] && $now - (int) $s['stuck_since'] >= self::STUCK_S) {
                $emit('stuck', ['seit' => date('H:i', (int) $s['stuck_since'])]);
                $s['stuck_sent'] = true;
            }
        } else {
            $s['stuck_since'] = null;
            if (!empty($in['charging'])) {
                $s['stuck_sent'] = false;
            }
        }

        // Speicher voll oder fast leer: einmal am Tag, wieder scharf erst nach 5 Prozentpunkten Abstand.
        if ($soc !== null) {
            if ($soc >= $full - 0.5) {
                if ($s['full_armed'] && $s['full_day'] !== $today) {
                    $emit('battery_full');
                    $s['full_day'] = $today;
                }
                $s['full_armed'] = false;
            } elseif ($soc < $full - 5) {
                $s['full_armed'] = true;
            }
            if ($soc <= $low + 0.5) {
                if ($s['low_armed'] && $s['low_day'] !== $today) {
                    $emit('battery_low');
                    $s['low_day'] = $today;
                }
                $s['low_armed'] = false;
            } elseif ($soc > $low + 5) {
                $s['low_armed'] = true;
            }
        }

        if ($connected === false && (float) ($in['export_kw'] ?? 0) >= (float) ($p['surplus_kw'] ?? 2)) {
            $s['surplus_since'] ??= $now;
            if ($s['surplus_day'] !== $today && $now - (int) $s['surplus_since'] >= self::SURPLUS_S) {
                $emit('surplus');
                $s['surplus_day'] = $today;
            }
        } else {
            $s['surplus_since'] = null;
        }

        // Berichte zur Berichtszeit, bis zu zwei Stunden später nachgeholt (etwa nach einem Neustart).
        if ($s['report_day'] !== $today && $minute >= $report && $minute < $report + 120) {
            $s['report_day'] = $today;
            $emit('daily');
            if (isset($in['tomorrow_kwh']) && (float) $in['tomorrow_kwh'] >= (float) ($p['sunny_kwh'] ?? 25)) {
                $emit('sunny');
            }
            if ($local->format('j') === '1') {
                $emit('monthly', ['month' => $local->modify('first day of last month')->format('Y-m')]);
            }
        }
        return ['state' => $s, 'events' => $events];
    }

    /** Eingang für detect() aus Snapshot, Ladevorgängen, Regelung, Puffer und Ladeziel. */
    public function inputs(array $snap, int $now): array
    {
        $v = $snap['values'];
        $cfg = $this->store->all();
        $sessions = new Sessions($this->store);
        $open = $sessions->open();
        $latest = $sessions->latest();
        $power = isset($v['wallbox_kw']) ? (float) $v['wallbox_kw'] : null;
        $raw = strtolower(str_replace([' ', '-'], '_', (string) ($v['wallbox_car_raw'] ?? '')));
        $connected = Sessions::carConnected($v['wallbox_car_raw'] ?? null, $power);
        $charging = ($power ?? 0.0) > 0.2 || $raw === 'charging';
        $soc = $snap['vehicle']['soc'] ?? null;
        $limit = $snap['vehicle']['limit'] ?? null;
        // Voll nur nach dem Ladestand des Autos; „complete“ meldet die go-e auch, wenn sie gesperrt ist.
        $full = $soc !== null && $limit !== null && (float) $soc >= (float) $limit - 1;
        $active = Controller::active($cfg);
        $control = (new Controller($this->store, $this->ha))->state();
        $reserve = (new Reserve($this->store, $this->ha))->state();
        $faults = [];
        if ($active && !empty($control['paused'])) {
            $faults['paused'] = (string) $control['paused'];
        }
        if ($active) {
            foreach ((array) $control['log'] as $row) {
                if ($now - (int) ($row['t'] ?? 0) <= 300 && str_contains((string) ($row['text'] ?? ''), 'fehlgeschlagen')) {
                    $faults['write'] = (string) $row['text'];
                    break;
                }
            }
        }
        if (!empty($reserve['error'])) {
            $faults['reserve'] = 'Backup-Puffer: ' . $reserve['error'];
        }
        $missing = [];
        foreach ((array) ($snap['missing'] ?? []) as $key) {
            if (isset(self::DEVICES[$key])) {
                $missing[self::DEVICES[$key]] = true;
            }
        }
        $done = $this->store->get(Target::DONE, null);
        return [
            'connected' => $connected,
            'charging' => $charging,
            'full' => $full,
            'open_id' => $open ? (int) $open['id'] : null,
            'latest_id' => $latest ? (int) $latest['id'] : null,
            'latest_at' => $latest ? (int) strtotime((string) $latest['started_at']) : null,
            'battery_soc' => isset($v['battery_soc']) ? (float) $v['battery_soc'] : null,
            'export_kw' => isset($v['grid_export_kw']) ? (float) $v['grid_export_kw'] : null,
            'missing' => array_keys($missing),
            'faults' => $faults,
            'stuck' => $active && !empty($control['enabled']) && empty($control['paused']) && $connected === true && !$charging && !$full && !in_array($raw, ['complete', 'completed'], true),
            'target_done' => is_array($done) && isset($done['t']) ? $done : null,
            'tomorrow_kwh' => isset($snap['forecast']['tomorrow_kwh']) ? (float) $snap['forecast']['tomorrow_kwh'] : null,
            'mode' => (string) ($cfg['charge']['mode'] ?? 'smart'),
            'control' => $active,
        ];
    }

    /**
     * Recorder alle 10 s (im Demo-Modus /api/live): Ereignisse erkennen und die eingeschalteten an die Geräte
     * schicken. Der Zustand wird vor dem Senden gespeichert, so meldet ein Ereignis sich auch bei zwei
     * gleichzeitigen Aufrufen nur einmal. Gibt die Ergebnisse von deliver() zurück.
     */
    public function tick(array $snap, int $now): array
    {
        if (empty($snap['connected'])) {
            return [];
        }
        $settings = self::settings($this->store->all());
        $in = $this->inputs($snap, $now);
        $pdo = $this->store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = self::detect($this->state(), $in, $settings, $now);
            $this->store->put(self::STATE, $result['state']);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        $out = [];
        foreach ($result['events'] as $item) {
            $conf = $settings['events'][$item['event']] ?? null;
            if ($conf === null || !$conf['on'] || $settings['targets'] === []) {
                continue;
            }
            $context = $this->contextFor($item['event'], $item['data'], $snap, $now);
            $out[] = $this->deliver($item['event'], self::render($conf['title'], $context), self::render($conf['message'], $context), $settings, $conf['important'], $now);
        }
        return $out;
    }

    /**
     * Schickt eine Mitteilung an die Geräte und schreibt sie ins Protokoll. In der Ruhezeit geht sie ohne Ton raus,
     * wichtige und Tests wie immer.
     *
     * @param ?list<string> $targets andere Geräte als die gespeicherten, etwa beim Test
     * @return array{ok: bool, status: string, error: ?string, sent: list<string>}
     */
    public function deliver(string $event, string $title, string $message, array $settings, bool $important, int $now, ?array $targets = null, bool $test = false): array
    {
        $targets = array_values($targets ?? $settings['targets']);
        $silent = !$test && !$important && self::quiet($settings, $now);
        $payload = ['title' => $title, 'message' => $message, 'data' => self::data($event, $important, $silent, !empty($settings['open_app']) ? $this->appPath($now) : null)];
        $sent = [];
        $errors = [];
        foreach ($targets as $service) {
            try {
                $this->ha->notify($service, $payload);
                $sent[] = $service;
            } catch (Throwable $e) {
                $errors[] = self::deviceName($service) . ': ' . $e->getMessage();
            }
        }
        $status = match (true) {
            $targets === [] || $errors !== [] => 'error',
            demo_mode() => 'demo',
            $silent => 'silent',
            default => 'sent',
        };
        $error = $targets === [] ? 'Kein Gerät gewählt.' : ($errors ? implode(' ', $errors) : null);
        $this->remember($event, $title, $message, $targets, $status, $error, $now, $test);
        return ['ok' => $targets !== [] && $errors === [], 'status' => $status, 'error' => $error, 'sent' => $sent];
    }

    /**
     * Zusatz für die Companion-App: ersetzt die vorige Mitteilung desselben Ereignisses (tag), gruppiert unter EMS
     * und öffnet beim Tippen EMS. Wichtige kommen auf dem iPhone zeitkritisch und unter Android im Kanal
     * „EMS wichtig“ mit hoher Priorität, in der Ruhezeit kommen die übrigen leise („EMS leise“, passiv).
     */
    public static function data(string $event, bool $important, bool $silent, ?string $path): array
    {
        $data = ['tag' => 'ems-' . $event, 'group' => 'ems'];
        if ($path !== null && $path !== '') {
            $data['url'] = $path;
            $data['clickAction'] = $path;
        }
        if ($important) {
            return $data + ['channel' => 'EMS wichtig', 'importance' => 'high', 'priority' => 'high', 'ttl' => 0, 'push' => ['interruption-level' => 'time-sensitive']];
        }
        if ($silent) {
            return $data + ['channel' => 'EMS leise', 'importance' => 'low', 'push' => ['interruption-level' => 'passive']];
        }
        return $data + ['channel' => 'EMS'];
    }

    /** Liegt $now in der Ruhezeit? Sie darf über Mitternacht reichen. */
    public static function quiet(array $settings, int $now): bool
    {
        if (empty($settings['quiet'])) {
            return false;
        }
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $from = self::minutes((string) $settings['quiet_from']);
        $to = self::minutes((string) $settings['quiet_to']);
        if ($from === $to) {
            return false;
        }
        return $from < $to ? $minute >= $from && $minute < $to : $minute >= $from || $minute < $to;
    }

    public static function minutes(string $clock): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', self::clock($clock, '00:00')));
        return $hours * 60 + $minutes;
    }

    private function remember(string $event, string $title, string $message, array $targets, string $status, ?string $error, int $now, bool $test): void
    {
        $pdo = $this->store->pdo();
        $pdo->prepare('INSERT INTO notifications (at, event, title, message, targets, status, error, test) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$now, $event, $title, $message, implode(',', $targets), $status, $error, $test ? 1 : 0]);
        $pdo->exec('DELETE FROM notifications WHERE id <= (SELECT id FROM notifications ORDER BY id DESC LIMIT 1 OFFSET ' . self::LOG_KEEP . ')');
    }

    /** Die letzten Mitteilungen, neueste zuerst, aufbereitet für die Liste. */
    public function log(int $limit = 15): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT * FROM notifications ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'logRow'], $stmt->fetchAll() ?: []);
    }

    public static function logRow(array $row): array
    {
        $event = (string) $row['event'];
        $label = $event === 'test' ? 'Test-Mitteilung' : (self::EVENTS[$event]['label'] ?? $event);
        $targets = array_filter(explode(',', (string) $row['targets']));
        return [
            'time' => date('d.m. H:i', (int) $row['at']),
            'event' => (!empty($row['test']) && $event !== 'test' ? 'Test · ' : '') . $label,
            'title' => (string) $row['title'],
            'message' => (string) $row['message'],
            'to' => implode(', ', array_map([self::class, 'deviceName'], $targets)),
            'status' => (string) $row['status'],
            'status_text' => self::STATUS[(string) $row['status']] ?? (string) $row['status'],
            'error' => $row['error'] === null ? null : (string) $row['error'],
        ];
    }

    /**
     * Geräte und Dienste zur Auswahl, Handys mit der App zuerst. Ein gespeichertes Gerät, das Home Assistant nicht
     * mehr kennt, steht mit missing dabei.
     *
     * @return array{list: list<array{service: string, name: string, app: bool, missing: bool}>, error: ?string}
     */
    public function services(array $saved = []): array
    {
        $error = null;
        try {
            $names = $this->ha->notifyServices();
        } catch (Throwable $e) {
            $names = [];
            $error = $e->getMessage();
        }
        try {
            $index = $names ? $this->ha->index() : [];
        } catch (Throwable) {
            $index = [];
        }
        $list = [];
        foreach (array_unique(array_merge($names, $saved)) as $service) {
            $list[] = ['service' => $service, 'name' => self::deviceName($service, $index), 'app' => str_starts_with($service, 'mobile_app_'), 'missing' => $error === null && !in_array($service, $names, true)];
        }
        usort($list, static fn (array $a, array $b): int => [$b['app'], $a['name']] <=> [$a['app'], $b['name']]);
        return ['list' => $list, 'error' => $error];
    }

    /** Name eines Geräts: der device_tracker der App, sonst aus der Kennung; andere Dienste als notify.<name>. */
    public static function deviceName(string $service, array $index = []): string
    {
        if (!str_starts_with($service, 'mobile_app_')) {
            return 'notify.' . $service;
        }
        $slug = substr($service, strlen('mobile_app_'));
        $tracker = $index['device_tracker.' . $slug]['attributes']['friendly_name'] ?? null;
        if (is_string($tracker) && trim($tracker) !== '') {
            return trim($tracker);
        }
        $words = array_filter(explode('_', $slug), 'strlen');
        return implode(' ', array_map(static fn (string $word): string => ['iphone' => 'iPhone', 'ipad' => 'iPad', 'macbook' => 'MacBook'][$word] ?? ucfirst($word), $words)) ?: $service;
    }

    /**
     * Was ein Tippen auf die Mitteilung in der App öffnet: die Oberfläche dieser App in Home Assistant. Ihren Namen
     * (slug) fragt EMS einmal beim Supervisor nach (addons/self/info, für jede App frei) und merkt ihn sich in
     * kv.notify_slug; ohne Supervisor öffnet ein Tippen nur die Home-Assistant-App.
     */
    public function appPath(int $now): ?string
    {
        $slug = $this->store->get('notify_slug', '');
        if (!is_string($slug) || $slug === '') {
            $slug = $this->askSlug($now);
            if ($slug === null) {
                return null;
            }
            $this->store->put('notify_slug', $slug);
        }
        return self::panelPath($slug, $this->coreVersion($now));
    }

    /**
     * Seit Home Assistant 2026.2 öffnet /app/<slug> die Oberfläche einer App, /hassio/ingress/<slug> antwortet dort
     * mit 404. Ältere Versionen kennen nur diesen Weg. Ist die Version unbekannt, gilt der neue.
     */
    public static function panelPath(string $slug, ?string $version): string
    {
        if ($version !== null && preg_match('/^(\d+)\.(\d+)/', $version, $m) && ((int) $m[1] < 2026 || ((int) $m[1] === 2026 && (int) $m[2] < 2))) {
            return '/hassio/ingress/' . $slug;
        }
        return '/app/' . $slug;
    }

    /** Version von Home Assistant aus /api/config, je Prozess höchstens einmal in der Stunde gefragt. */
    private function coreVersion(int $now): ?string
    {
        if (self::$coreVersion === null || $now - self::$coreVersion[0] >= 3600) {
            $ping = $this->ha->ping();
            $version = $ping['version'] ?? null;
            self::$coreVersion = [$now, !empty($ping['ok']) && is_string($version) && $version !== '' ? $version : null];
        }
        return self::$coreVersion[1];
    }

    /** Name dieser App beim Supervisor; nach einem Fehlschlag frühestens in einer Stunde wieder. */
    private function askSlug(int $now): ?string
    {
        $token = (string) (getenv('SUPERVISOR_TOKEN') ?: '');
        if ($token === '' || demo_mode() || $now - self::$slugTried < 3600 || !function_exists('curl_init')) {
            return null;
        }
        self::$slugTried = $now;
        $ch = curl_init('http://supervisor/addons/self/info');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch);
        curl_close($ch);
        $reply = is_string($body) ? json_decode($body, true) : null;
        $slug = is_array($reply) ? (string) ($reply['data']['slug'] ?? '') : '';
        return preg_match('/^[a-z0-9_]{1,64}$/', $slug) ? $slug : null;
    }
}
