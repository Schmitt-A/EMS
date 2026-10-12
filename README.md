# EMS

Energie-App für eine PV-Anlage, einen Speicher, einen Zähler und eine Wallbox. Sie rechnet Energiefluss, Ladevorschlag, Prognose und Ladestatistik selbst und liest dafür die zugeordneten Home-Assistant-Entitäten. Mit dem Hauptschalter „EMS regelt die Wallbox“ unter Einstellungen steuert sie die go-e selbst; ist er aus, schreibt sie nichts.

Im Add-on spricht sie über den Supervisor. Lokal liegen Adresse und Token in `.env` (Vorlage: `.env.example`). Die Datei wird nicht eingecheckt.

## Oberfläche

Sechs Bereiche, auf dem Handy als Tab-Leiste unten, ab 1024 px als Leiste links:

- **Laden:** oben Solaranteil, Ø Preis und gespartes CO₂ der letzten 30 Tage, darunter der Energiefluss über die ganze Breite, ohne Karte vor dem Hintergrund. Wahlweise (Einstellungen → Darstellung) als Balken mit Klammern oben für PV, Speicher und Netzbezug und unten für Haus, Ladepunkt, Speicher und Einspeisung, oder als Energie-Flow, Rein → Raus: links Sonne, Speicher und Netz, rechts Haus, Auto, Speicher und Einspeisung. Jede Quelle hat eine eigene Linie zu jedem Ziel, das sie gerade versorgt, und die Punkte laufen in ihrer Farbe von links nach rechts. Breite, Zahl und Tempo der Punkte richten sich nach dem Anteil der Linie an allem, was hereinkommt: Kommen drei Viertel aus dem Netz, laufen dort mehr und schnellere Punkte als auf der Linie der Sonne. Der Ring der Sonne zeigt, wie viel der Tagesprognose schon erzeugt ist; die Ringe rechts an Haus, Auto, Speicher und Einspeisung zeigen als ganzer Kreis = 100 %, woher ihre Energie kommt. Unter jedem Namen steht in Grau eine Zeile mit Icons: an der Sonne gemessen und Prognose, am Speicher Ladestand und was bis voll fehlt, am Netz der Bezugspreis, am Haus das Mittel der letzten 30 Tage, am Auto Ladestand und Reichweite, an der Einspeisung die Vergütung. Unter Einstellungen → Darstellung steht die gewählte Darstellung live als Vorschau. Die Karte darunter stellt Rein (PV, Prognose heute, Speicher, Netz) und Raus (Haus, Ladepunkt, Speicher, Einspeisung) zeilengleich nebeneinander. Dann die Ladepunkt-Karte: oben der Schalter „Laden“ (an oder aus), darunter Leistung, geladene Energie seit dem Anstecken und Restzeit, die Übersicht des laufenden Ladevorgangs (Dauer, Strecke, Ø Leistung, Sonne, Kosten), die Modi für Sonne und Speicher (Intelligent, Min+Sonne, Schnell), das Ladeziel, Netzladen (Aus, Jetzt, In Ladefenstern) mit den nächsten Ladefenstern, der Ladebalken mit ziehbarem Limit und, wenn zugeordnet, der Kilometerstand. Darunter die Regelung: mit welcher Stufe EMS die Wallbox regelt oder, nur angezeigt, regeln würde, mit Countdown der Ein- und Ausschaltverzögerung, woher die Leistung käme und wohin der übrige Überschuss ginge, und die Phasen-Leiter mit der Umschaltung zwischen ein- und dreiphasig. Was der Sonne fehlt, kommt wie im Eigenverbrauch zuerst aus dem Speicher bis zu seinem Backup-Puffer, dann aus dem Netz. Aufgeklappt zeigt die Karte, was Intelligent, Min+Sonne, Schnell und Netzladen gerade täten, und den Rechenweg. Ladeparameter und Fahrzeug öffnen sich als Sheet.
- **Speicher:** die Säule mit den Zonen Haus, Auto und batteriegestützt, Grenzen zum Ziehen, der Backup-Puffer, der Ausblick (wann die Grenzen erreicht sind, die nächsten Tage mit Sonne gegen Haus, Laden aus dem Netz in Ladefenstern) und der Verlauf über 3 oder 7 Tage. Die Zonen gelten auch für die Regelung; eine Grenze auf 100 % schaltet Stützung und Start ohne Sonne ab.
- **Prognose:** oben umschaltbar zwischen Sonne und Strompreis. Sonne: die nächsten drei Tage in voller Breite, gemessen und Prognose im selben Diagramm. Über jedem Tag stehen die Prognose (Orange, mit Unsicherheit, wenn Platz ist) und, bis heute, was gemessen wurde, in der Farbe der Messlinie; zurückblättern zeigt vergangene Tage. Ist und Prognose mit Güte, Modelle, Rechnung und Wetter stehen unter Details. Strompreis: siehe unten.
- **Statistik:** eine Kennzahl als Heatmap. Zur Wahl stehen PV gemessen, Prognose und ihre Güte; Hausverbrauch, Netzbezug und seine Kosten, Einspeisung und ihre Vergütung, Autarkie, Eigenverbrauch der PV und gespartes CO₂; Börsenpreis, bezahlter Preis, Netzbezug statisch und dynamisch und die Ersparnis; Speicherstand, geladen und entladen; beim Laden Energie, Dauer, Sonnenanteil, Kosten und Anzahl der Ladevorgänge; vom DWD Strahlung, Sonnenschein, Bewölkung und Temperatur. Zeitraum Tag (24 Stunden), Monat (Kalender, umschaltbar auf Tage × Stunden), Quartal und Jahr (Monatsblöcke), Gesamt (Jahre × Monate) oder ein eigener Zeitraum, jeweils mit ‹ › zum Blättern. Ein Feld antippen zeigt seinen Wert; darunter Summe oder Gesamtanteil, Mittel, höchster und niedrigster Wert und alle Werte als Tabelle. Leistungen und Ladestand kommen aus der Langzeitstatistik von Home Assistant, also so weit zurück, wie Home Assistant sie hat; Ladevorgänge, Prognose und Wetter so weit, wie EMS sie gespeichert hat. Stündliches Wetter hebt EMS seit dieser Version gut zwei Jahre auf.
- **Ladevorgänge:** Monat, Jahr oder Gesamt mit Energie, Kosten oder CO₂, Solaranteil und alle Vorgänge mit Kilometerstand und Löschen. Im laufenden Monat steht heute ganz rechts im Diagramm, davor die Tage bis in den Vormonat (blasser, ohne Summe). Ein Ladevorgang reicht vom Anstecken bis zum Abstecken; seine einzelnen Ladezyklen klappen auf.
- **Einstellungen** (Zahnrad, schmal „Optionen“): oben der Hauptschalter „EMS regelt die Wallbox“ mit den letzten Schaltvorgängen, darunter Ladepunkt, Fahrzeug, Speicher (Grenzen, Backup-Puffer, Laden aus dem Netz, Entladeleistung), Energie (Zuordnung), Prognose, Tarif & CO₂, Mitteilungen, Darstellung und System mit Verbindung und JSON-Export. Alte Links auf `/mehr` leiten weiter.

Die Diagramme scrollen waagerecht in Vergangenheit und Zukunft; ein erneuter Tipp auf den Zeitraum springt zurück zu heute. Werte zeigt das Antippen (Handy) oder Darüberfahren (Maus).

Hell und Dunkel folgen dem Gerät oder lassen sich unter Einstellungen → Darstellung festlegen. Die Seiten laden nichts von fremden Servern.

## Lademodi

Der Schalter „Laden“ oben in der Ladepunkt-Karte sperrt die Wallbox (aus) oder gibt sie frei (an). Darunter wählt man, wie Sonne und Speicher laden; ein Modus schaltet Laden auch wieder an:

- **Intelligent:** nur Sonnenüberschuss, nach den Zonen des Speichers.
- **Min+Sonne:** immer mit dem Mindeststrom, Sonnenüberschuss hebt an. Was fehlt, deckt der Speicher bis zum Backup-Puffer, dann das Netz.
- **Schnell:** sofort volle Leistung. Was die Sonne nicht schafft, deckt der Speicher bis zum Backup-Puffer, dann das Netz.

Getrennt davon steht **Netzladen**: Aus, Jetzt oder In Ladefenstern (siehe unten). Dann lädt das Auto voll, was die Sonne nicht schafft, kommt aus dem Netz, und der Speicher gibt nichts ab.

Bis 1.12 war Netzladen ein eigener Modus. Beim Update auf 1.13 stellt EMS das einmal um: Aus Netzladen wird Intelligent mit Netzladen „Jetzt“, so lädt es weiter wie vorher; „Nach dem Abstecken: Netzladen“ wird zu „Modus behalten“. Ältere Sicherungen stellt der Import genauso um. Bis 1.9 hieß Netzladen intern „schnell“ und schonte den Speicher nur mit dem Schalter „Speicher schonen“; auch das stellt EMS beim Update und beim Import um.

## Regelung

Ist der Hauptschalter an, stellt EMS die go-e über ihre Home-Assistant-Entitäten ein: Zwangszustand `frc` (frei, gesperrt, neutral), Ladestrom `amp` und Phasen `psm` (einphasig, dreiphasig). Die Werte liest EMS aus der Optionsliste der Entität: Die Integration „go-eCharger API v2“ von marq24 nennt sie 2, 1, 0 und 1, 2, die MQTT-Integration von syssi `charge`, `dont_charge`, `neutral` und `one_phase`, `three_phases`. Zum Regeln eignet sich die Integration von marq24 am besten, sie schreibt über die lokale HTTP-API der go-e. Über MQTT nehmen manche go-e keine Befehle an, auch wenn in der go-e-App „MQTT readonly“ aus ist (so eine go-e Charger PRO mit Firmware 60.4); EMS meldet das dann als „Die Wallbox hat den Befehl nicht übernommen“. Den Fahrzeugstatus versteht EMS von beiden, bei marq24 auch als Text wie „Warte auf Fahrzeug“. So regelt EMS:

- Starten erst, wenn der Überschuss die ganze Einschaltverzögerung (Standard 60 s) für den Mindeststrom reicht.
- Reicht er nicht mehr, lädt das Auto während der Ausschaltverzögerung (Standard 180 s) mit dem Mindeststrom weiter und stoppt dann.
- Auf drei Phasen nach der Einschalt-, auf eine nach der Ausschaltverzögerung; vor dem Hochschalten erst der Mindeststrom.
- Strom auf ganze Ampere abgerundet, ohne Auto gesperrt; nach dem Anstecken darf Intelligent sofort starten.
- Zwischen zwei Schaltvorgängen liegt die Schütz-Schutzzeit (Standard 60 s). Laden aus, Schnell und Netzladen wirken sofort.
- Geregelt wird alle 30 s, nach einem Moduswechsel gleich. Der Recorder macht das; läuft er nicht, warnt die Oberfläche.

Weicht die Wallbox mehrmals von dem ab, was EMS geschrieben hat, schreibt offenbar ein anderer Regler mit. Nach drei solchen Fremdzugriffen in zehn Minuten pausiert EMS und sagt es. Bleibt die Wallbox dagegen beim alten Wert, hat sie den Befehl nicht übernommen; auch das pausiert nach drei Fällen. Deshalb: **Solange EMS regelt, darf kein anderer Regler die go-e steuern.** Auch ein anderer Regler auf „Aus“ sperrt die Wallbox meist selbst. Ausschalten gibt die Wallbox wieder frei (`frc` 0).

## Strompreis und Tarif

Unter Prognose → Strompreis steht der Preis von jetzt im aktiven Tarif, der Börsenpreis, der Tag heute und morgen, ein Diagramm je Viertelstunde (dynamischer und statischer Preis, Börsenpreis netto, Einspeisevergütung; 14 Tage zurück bis zum letzten bekannten Preis, je Tag der günstigste und der teuerste Preis, die aktiven Ladefenster hinterlegt), die Ladefenster selbst (siehe unten), die Entscheidungshilfe „Lohnt sich dynamisch?“ und der Vergleich mit dem gemessenen Netzbezug für diesen Monat, den letzten Monat oder zwölf Monate.

„Lohnt sich dynamisch?“ rechnet mit deinen Preisen von heute und den echten Börsenpreisen der letzten 24 Monate: je Monat, was eine kWh im Mittel über den Tag und im Ladefenster kostet und was das Ladefenster gegenüber dem statischen Preis spart. Trägt man ein, wie viele kWh im Jahr ins Ladefenster gehen (Auto und Speicher) und wie viel sonst aus dem Netz kommt, nennt die Karte die Jahreskosten in beiden Tarifen mit Grundpreisen und, ab wie vielen kWh im Ladefenster sich dynamisch lohnt; sie rechnet beim Tippen mit. Der übrige Netzbezug ist dabei über den Tag gleich verteilt. Der Vergleich darunter zählt nur, was Home Assistant oder EMS wirklich gemessen hat, und schlüsselt den dynamischen Tarif in Börsenpreis, Netzentgelt, Steuern und Umlagen, Anbieteraufschlag, MwSt und Grundpreis auf und nennt, was zusätzlich drin gewesen wäre, wenn das Auto am selben Tag in den günstigsten Viertelstunden geladen hätte.

Den Börsenpreis (Day-Ahead, DE-LU) holt EMS selbst: von Energy-Charts (Fraunhofer ISE, Daten der Bundesnetzagentur | SMARD.de, Lizenz CC BY 4.0, die Quelle steht unter dem Diagramm), bei einem Ausfall von aWATTar. Die Preise für morgen gibt es ab etwa 13 Uhr. Einmalig lädt EMS zwei Jahre Vergangenheit nach, einen Monat je Schritt, damit Vergleich und Statistik sofort rechnen.

Unter Einstellungen → Tarif stehen beide Tarife und welcher aktiv ist: statisch mit Arbeitspreis und Grundpreis, dynamisch mit Netzentgelt, Steuern und Umlagen, Anbieteraufschlag (netto), MwSt und Grundpreis. Vorbelegt sind typische Werte; die echten stehen im Preisblatt von Netzbetreiber und Anbieter. Mit §14a Modul 3 lässt sich ein Netzentgelt für bis zu drei Zeitfenster eintragen. Jede Änderung gilt ab einem Datum: Ladevorgänge, Energieübersicht, Statistik und Vergleich rechnen jede Zeit mit dem Preis, der damals galt. Mit Tibber oder Octopus trägt man dort zusätzlich die Preis-Entität des Anbieters ein; EMS zeichnet ihre Preise auf und nimmt sie im dynamischen Tarif statt Börse plus Aufschlägen.

Ein dynamischer Tarif braucht ein Smart Meter und rechnet je Viertelstunde ab. Home Assistant hebt Leistungen nur als Stundenmittel auf; darum misst EMS den Netzbezug seit 1.11 selbst je Viertelstunde und nimmt diese Werte, wo es sie gibt.

## Netzladen und Ladefenster

Unter Prognose → Strompreis sucht EMS für jeden Tag, sobald die Preise da sind, das günstigste Fenster am Stück nach dem dynamischen Preis, Länge einstellbar (Standard 3 Stunden). Jedes Fenster lässt sich dort abschalten, eigene Fenster lassen sich anlegen und löschen. Aktive Fenster sind im Diagramm hinterlegt. Dieselben Fenster nutzen Auto und Speicher.

Netzladen in der Ladepunkt-Karte:

- **Aus:** Es gilt nur der Modus für Sonne und Speicher.
- **Jetzt:** sofort volle Leistung aus dem Netz, bis das Ladeziel erreicht ist oder das Auto abgesteckt wird; dann ist Netzladen wieder aus.
- **In Ladefenstern:** in jedem aktiven Fenster volle Leistung aus dem Netz, bis das Ladeziel erreicht ist; danach bis zum Abstecken nicht mehr. Zwischen den Fenstern gilt der Modus. Die nächsten Fenster stehen in der Karte.

Hat das Ladeziel (Energie, Ladestand oder Reichweite) ein „fertig bis“, prüft EMS, ob die Fenster davor reichen: was noch fehlt (mit 8 % Ladeverlust), die erwartete Sonne zwischen den Fenstern (Prognose minus Ø Haus, davon 70 %) und die Fenster bei voller Leistung. Reichen sie nicht, nimmt es die günstigsten weiteren Viertelstunden vor der Uhrzeit dazu, bei gleichem Preis die späteren, und nennt sie in der Karte; im Diagramm sind sie kräftiger hinterlegt. Kennt EMS die Preise für morgen noch nicht und reicht die Zeit danach, wartet der Rest auf sie (gegen 13 Uhr). EMS rechnet das alle 15 min neu und wenn sich Ziel, Modus oder Fenster ändern.

Für Regelung und Backup-Puffer gilt dann Netzladen; die Regelung zeigt „Netzladen (jetzt)“, „(Ladefenster)“ oder „(Ladeplan)“ mit dem Grund. Die Preisgrenze aus 1.12 gibt es nicht mehr, ihre Aufgabe übernehmen die Ladefenster.

## Speicher aus dem Netz laden

Unter Speicher → Ausblick lässt sich einschalten, dass der Speicher in aktiven Ladefenstern aus dem Netz lädt, nur wenn die Sonne nicht reicht. Für das laufende oder nächste Fenster rechnet EMS je Viertelstunde bis einen Tag danach: Sonne laut Prognose, Haus mit dem Mittel der letzten 30 Tage, Preise im aktiven Tarif (noch unbekannte wie am Vortag). Es probiert, bis zu welchem Stand der Speicher im Fenster laden sollte, und nimmt den günstigsten: Netzbezug im Fenster und danach, minus Einspeisung, mit Wirkungsgrad (Standard 90 %) und Verschleiß (Standard 2 ct je gespeicherter kWh). Füllt die Sonne den Speicher ohnehin, oder deckt der Preisunterschied Verluste und Verschleiß nicht, lädt er nicht; mit statischem Tarif also nie. Der Ausblick zeigt die Rechnung und die nächsten Tage mit Sonne gegen Haus.

Geschaltet wird nur, wenn EMS regelt. EMS stellt dann den Betriebsmodus des Speichers auf manuell und „Laden erzwingen“ auf die Ladeleistung (Standard die Entladeleistung, sonst 2,5 kW), höchstens bis zum eingestellten Stand, und am Ziel, am Ende des Fensters oder beim Abschalten zurück auf 0 W und den Betriebsmodus von vorher. Beide Entitäten stehen unter Einstellungen → Speicher; bei der sonnenBatterie heißen sie `select.sonnenbatterie_…_select_operating_mode` und `number.sonnenbatterie_…_number_charge` und erscheinen erst, wenn die Integration schreiben darf. Ins Netz geht von der geladenen Energie nichts zurück.

## Ladeziele

In der Ladepunkt-Karte setzt „Ladeziel setzen“ ein Ziel für den laufenden Ladevorgang: eine Energiemenge seit dem Anstecken (mit geschätzter Dauer), eine Uhrzeit (mit Countdown und bisher geladenen kWh), einen Ladestand oder eine Reichweite des Autos, alle außer der Uhrzeit optional mit „fertig bis“ (siehe Netzladen und Ladefenster). Jedes Ziel nennt auch die Kilometer: was es bringt, was bis zur Uhrzeit zusammenkommt, die Reichweite danach; schon beim Eintippen rechnet das Sheet sie mit. Den Verbrauch dafür trägt man unter Einstellungen → Fahrzeug ein (kWh/100 km), sonst nimmt EMS den, mit dem das Auto seine Reichweite rechnet, und zieht 8 % Ladeverlust ab. Ist es erreicht, wechselt EMS in den Folgemodus; „Laden aus“ stoppt die Wallbox. Beim Abstecken verfällt das Ziel. Unter Einstellungen → Ladepunkt stehen der vorgeschlagene Folgemodus und, was nach dem Abstecken gelten soll.

## Netzladen und Backup-Puffer

Unter Einstellungen → Speicher steht der Backup-Puffer als number-Entität, dazu sein Standardwert. Beim Speichern setzt die App den Standardwert im Speicher. Regelt EMS und lädt das Auto mit Netzladen (jetzt oder im Ladefenster), hebt sie den Puffer auf den aktuellen Ladestand: Der Speicher gibt dann nichts ab, weder ans Auto noch ans Haus, und was die Sonne nicht schafft, kommt aus dem Netz. Nach dem Moduswechsel, dem Ende des Ladevorgangs oder dem Abstecken setzt sie den Puffer zurück auf den Standardwert. Den Stand merkt sie sich in der Datenbank, so setzt auch ein Neustart mittendrin zurück. Wird das Add-on während des Netzladens gestoppt, bleibt der Puffer oben, bis es wieder läuft.

Bei der sonnenBatterie (Integration `sonnenbatterie`) heißt die Entität `number.sonnenbatterie_…_battery_reserve`. Sie erscheint erst, wenn im Speicher der Schreibzugriff der JSON-API freigeschaltet ist, und zeigt den echten Wert erst nach dem ersten Setzen.

## Mitteilungen aufs Handy

Unter Einstellungen → Mitteilungen schickt EMS Push-Mitteilungen über die Home-Assistant-App (`notify.mobile_app_…`). Jedes Handy, auf dem die App bei diesem Home Assistant angemeldet ist, steht dort zum Ankreuzen. Mitteilungen gehen auch raus, wenn der Hauptschalter aus ist.

Zum Ankreuzen, in vier Gruppen:

- **Wichtig:** Störung der Regelung (ein anderer Regler schreibt mit, ein Schreibzugriff schlägt fehl, der Backup-Puffer lässt sich nicht setzen), keine Messwerte seit 10 Minuten, Auto lädt trotz Freigabe seit 5 Minuten nicht.
- **Laden:** angesteckt, gestartet, beendet, Ladeziel erreicht, abgesteckt mit der Bilanz des Ladevorgangs, Lademodus geändert (auch von jemand anderem oder nach einem Ladeziel) und Regelung ein oder aus. Kurze Pausen der Sonne unter 10 Minuten melden weder Ende noch Start; ist das Auto voll oder abgesteckt, kommt das Ende sofort.
- **Speicher und Sonne:** Speicher voll, Speicher fast leer, Sonne übrig ohne Auto, sonniger Tag morgen, jeweils mit einstellbarer Grenze und höchstens einmal am Tag.
- **Berichte:** Tagesbericht und am Ersten der Monatsbericht des Vormonats, zur Berichtszeit.

Ab Werk sind Wichtig, gestartet, beendet und Ladeziel an. Der Stift neben jeder Meldung öffnet Titel und Text mit Platzhaltern wie `{auto}`, `{geladen}`, `{sonnenanteil}`, `{kosten}`, `{pv}` oder `{prognose_morgen}`; ein Tippen auf einen Platzhalter fügt ihn ein, die Vorschau zeigt die Mitteilung mit den Werten von jetzt, und „Probe senden“ schickt sie sofort. Wichtige Meldungen kommen auf dem iPhone zeitkritisch und unter Android im Kanal „EMS wichtig“, auch in der einstellbaren Ruhezeit; die übrigen kommen dann leise. Ein Tippen auf die Mitteilung öffnet EMS in der App. Darunter steht die Test-Mitteilung mit eigenem Titel und Text und die Liste der zuletzt gesendeten.

## Fahrzeugdaten über Bluetooth

Ladestand, Reichweite, Kilometerstand und Ladelimit des Autos sind eigene Entitäten unter Einstellungen → Fahrzeug, dazu ein Weck-Button, den EMS drückt, wenn das Auto 60 s nach der Freigabe nicht lädt. Mit [ESPHome Tesla BLE](https://github.com/PedroKTFC/esphome-tesla-ble) heißen sie etwa `sensor.tesla_ble_charge_level`, `sensor.tesla_ble_range`, `sensor.tesla_ble_odometer` und `sensor.tesla_ble_charge_limit`; die App schlägt gefundene Sensoren vor und rechnet Meilen in Kilometer um. Meldet das Auto sein Ladelimit, gilt es statt des Reglers. Jeder neue Ladevorgang bekommt den Kilometerstand beim Start. Die Kapazität des Akkus lässt sich von Hand eintragen, weil Tesla BLE sie nicht meldet. Einen Verbrauchssensor hat Tesla BLE nicht; die Reichweite kommt direkt als Sensor. Fehlt eine Reichweiten-Entität, rechnet EMS sie aus Ladestand, Akku und dem eingetragenen Verbrauch.

## Lokal

```sh
cd ems && npm install && npm run build
cd ..
cp .env.example .env
# HA_TOKEN eintragen
EMS_DATA=./data HA_URL=http://192.168.180.27:8123 HA_TOKEN=… php -S 127.0.0.1:8099 -t ems/app/public ems/app/public/router.php
EMS_DATA=./data php ems/bin/recorder.php
```

Dieselbe Anwendung im Container: `docker compose up --build` und danach http://127.0.0.1:8099.

Ohne Home Assistant zeigt der Demo-Modus eine Beispielanlage mit drei Jahren Ladevorgängen:

```sh
EMS_DEMO=1 EMS_DATA=/tmp/ems-demo php ems/bin/demo-seed.php
EMS_DEMO=1 EMS_DATA=/tmp/ems-demo php -S 127.0.0.1:8099 -t ems/app/public ems/app/public/router.php
```

Prüfungen: `php ems/bin/selftest.php` für die Rechnungen, im Ordner `ems` dann `npm run check:contrast` für die Farben und `npm run check:ui` (braucht Google Chrome) für alle Seiten von 360 bis 1920 px, hell und dunkel, mit axe.

## Home Assistant

Der Ordner `ems/` ist das Add-on (`config.yaml`, Ingress auf Port 8099, Seitenleiste, Architektur amd64 und aarch64). `version` ist die Nummer, die der Supervisor für automatische Updates vergleicht.

Repository-URL im App-Store:

```
https://github.com/Schmitt-A/EMS
```

Einstellungen → Apps → Add-on-Store → Menü → Repositories, die URL eintragen, EMS installieren und starten. „In der Seitenleiste anzeigen“ und „Automatische Aktualisierung“ liegen auf der App-Seite. Das Repository muss öffentlich sein, damit der Supervisor es klonen kann.

Konfiguration und Ladevorgänge liegen in `/data/ems.sqlite` und bleiben bei Updates erhalten. An die Wallbox schreibt die App nur bei eingeschaltetem Hauptschalter.
