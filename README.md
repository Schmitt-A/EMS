# EMS

Energie-App für eine PV-Anlage, einen Speicher, einen Zähler und eine Wallbox. Sie rechnet Energiefluss, Ladevorschlag, Prognose und Ladestatistik selbst und liest dafür die zugeordneten Home-Assistant-Entitäten. Mit dem Hauptschalter „EMS regelt die Wallbox“ unter Einstellungen steuert sie die go-e selbst, mit derselben Logik wie evcc; ist er aus, schreibt sie nichts.

Im Add-on spricht sie über den Supervisor. Lokal liegen Adresse und Token in `.env` (Vorlage: `.env.example`). Die Datei wird nicht eingecheckt.

## Oberfläche

Fünf Bereiche, auf dem Handy als Tab-Leiste unten, ab 1024 px als Leiste links:

- **Laden:** oben Solaranteil, Ø Preis und gespartes CO₂ der letzten 30 Tage, darunter der Energiefluss über die ganze Breite, ohne Karte vor dem Hintergrund. Wahlweise (Einstellungen → Darstellung) als Balken mit Klammern oben für PV, Speicher und Netzbezug und unten für Haus, Ladepunkt, Speicher und Einspeisung, oder als Energie-Flow, Rein → Raus: links Sonne, Speicher und Netz, rechts Haus, Auto, Speicher und Einspeisung. Jede Quelle hat eine eigene Linie zu jedem Ziel, das sie gerade versorgt, breiter bei mehr Leistung, und die Punkte laufen in ihrer Farbe von links nach rechts, schneller bei mehr Leistung. Der Ring der Sonne zeigt, wie viel der Tagesprognose schon erzeugt ist, die Ringe an Haus und Auto den Anteil von Sonne, Speicher und Netz. Unter jedem Namen steht in Grau eine Zeile mit Icons: an der Sonne gemessen und Prognose, am Speicher Ladestand und was bis voll fehlt, am Netz der Bezugspreis, am Haus das Mittel der letzten 30 Tage, am Auto Ladestand und Reichweite, an der Einspeisung die Vergütung. Unter Einstellungen → Darstellung steht die gewählte Darstellung live als Vorschau. Die Karte darunter stellt Rein (PV, Prognose heute, Speicher, Netz) und Raus (Haus, Ladepunkt, Speicher, Einspeisung) zeilengleich nebeneinander. Dann die Ladepunkt-Karte mit Modus (Aus, Nur Solar, Min+Solar, Netzladen), Leistung, geladener Energie seit dem Anstecken, Restzeit, Ladeziel, der Übersicht des laufenden Ladevorgangs (Dauer, Strecke, Ø Leistung, Sonne, Kosten), Ladebalken mit ziehbarem Limit und, wenn zugeordnet, dem Kilometerstand. Darunter die Regelung: mit welcher Stufe EMS die Wallbox regelt oder, nur angezeigt, regeln würde, mit Countdown der Ein- und Ausschaltverzögerung, woher die Leistung käme und wohin der übrige Überschuss ginge, und die Phasen-Leiter mit der Umschaltung zwischen ein- und dreiphasig. Was der Sonne fehlt, kommt wie im Eigenverbrauch zuerst aus dem Speicher bis zu seinem Backup-Puffer, dann aus dem Netz. Aufgeklappt zeigt die Karte, was Nur Solar, Min+Solar und Netzladen gerade täten, und den Rechenweg. Ladeparameter und Fahrzeug öffnen sich als Sheet.
- **Speicher:** die Säule mit den Zonen Haus, Auto und batteriegestützt, Grenzen zum Ziehen, der Backup-Puffer, wann die Grenzen erreicht sind, und der Verlauf über 3 oder 7 Tage. Die Zonen gelten auch für die Regelung; eine Grenze auf 100 % schaltet Stützung und Start ohne Sonne ab.
- **Prognose:** Sonne der nächsten drei Tage in voller Breite, gemessen und Prognose im selben Diagramm. Über jedem Tag stehen die Prognose (Orange, mit Unsicherheit, wenn Platz ist) und, bis heute, was gemessen wurde, in der Farbe der Messlinie; zurückblättern zeigt vergangene Tage. Ist und Prognose mit Güte, Modelle, Rechnung und Wetter stehen unter Details.
- **Ladevorgänge:** Monat, Jahr oder Gesamt mit Energie, Kosten oder CO₂, Solaranteil und alle Vorgänge mit Kilometerstand und Löschen. Im laufenden Monat steht heute ganz rechts im Diagramm, davor die Tage bis in den Vormonat (blasser, ohne Summe). Ein Ladevorgang reicht vom Anstecken bis zum Abstecken; seine einzelnen Ladezyklen klappen auf.
- **Einstellungen** (Zahnrad, schmal „Optionen“): oben der Hauptschalter „EMS regelt die Wallbox“ mit den letzten Schaltvorgängen, darunter Ladepunkt, Fahrzeug, Speicher (Grenzen, Backup-Puffer, Entladeleistung), Energie (Zuordnung), Prognose, Tarif & CO₂, Mitteilungen, Darstellung und System mit Verbindung und JSON-Export. Alte Links auf `/mehr` leiten weiter.

Die Diagramme scrollen waagerecht in Vergangenheit und Zukunft; ein erneuter Tipp auf den Zeitraum springt zurück zu heute. Werte zeigt das Antippen (Handy) oder Darüberfahren (Maus).

Hell und Dunkel folgen dem Gerät oder lassen sich unter Einstellungen → Darstellung festlegen. Die Seiten laden nichts von fremden Servern.

## Regelung wie evcc

Ist der Hauptschalter an, stellt EMS die go-e über ihre Home-Assistant-Entitäten ein, so wie evcc: Zwangszustand `frc` (frei, gesperrt, neutral), Ladestrom `amp` und Phasen `psm` (einphasig, dreiphasig). Die Werte liest EMS aus der Optionsliste der Entität: Die MQTT-Integration von syssi nennt sie `charge`, `dont_charge`, `neutral` und `one_phase`, `three_phases`, die Integration von marq24 2, 1, 0 und 1, 2. Die Logik folgt evcc:

- Starten erst, wenn der Überschuss die ganze Einschaltverzögerung (Standard 60 s) für den Mindeststrom reicht.
- Reicht er nicht mehr, lädt das Auto während der Ausschaltverzögerung (Standard 180 s) mit dem Mindeststrom weiter und stoppt dann.
- Auf drei Phasen nach der Einschalt-, auf eine nach der Ausschaltverzögerung; vor dem Hochschalten erst der Mindeststrom.
- Strom auf ganze Ampere abgerundet, ohne Auto gesperrt; nach dem Anstecken darf Nur Solar sofort starten.
- Zwischen zwei Schaltvorgängen liegt die Schütz-Schutzzeit (Standard 60 s). Aus und Netzladen wirken sofort.
- Geregelt wird alle 30 s, nach einem Moduswechsel gleich. Der Recorder macht das; läuft er nicht, warnt die Oberfläche.

Weicht die Wallbox mehrmals von dem ab, was EMS geschrieben hat, schreibt offenbar ein anderer Regler mit. Nach drei solchen Fremdzugriffen in zehn Minuten pausiert EMS und sagt es. Deshalb: **Solange EMS regelt, darf evcc die go-e nicht steuern.** Ladepunkt in evcc herausnehmen oder evcc stoppen; „Aus“ in evcc reicht nicht, denn dann sperrt evcc die Wallbox selbst. Ausschalten gibt die Wallbox wieder frei (`frc` 0).

## Ladeziele

In der Ladepunkt-Karte setzt „Ladeziel setzen“ ein Ziel für den laufenden Ladevorgang: eine Energiemenge seit dem Anstecken (mit geschätzter Dauer), eine Uhrzeit (mit Countdown und bisher geladenen kWh), einen Ladestand oder eine Reichweite des Autos. Jedes Ziel nennt auch die Kilometer: was es bringt, was bis zur Uhrzeit zusammenkommt, die Reichweite danach; schon beim Eintippen rechnet das Sheet sie mit. Den Verbrauch dafür trägt man unter Einstellungen → Fahrzeug ein (kWh/100 km), sonst nimmt EMS den, mit dem das Auto seine Reichweite rechnet, und zieht 8 % Ladeverlust ab. Ist es erreicht, wechselt EMS in den Folgemodus; Aus stoppt die Wallbox. Beim Abstecken verfällt das Ziel. Unter Einstellungen → Ladepunkt stehen der vorgeschlagene Folgemodus und, was nach dem Abstecken gelten soll.

## Netzladen und Backup-Puffer

Unter Einstellungen → Speicher steht der Backup-Puffer als number-Entität, dazu sein Standardwert. Beim Speichern setzt die App den Standardwert im Speicher. Ist „Speicher schonen“ an, regelt EMS und lädt das Auto im Modus Netzladen, hebt sie den Puffer auf den aktuellen Ladestand: Der Speicher gibt dann nichts ab, weder ans Auto noch ans Haus, und was die Sonne nicht schafft, kommt aus dem Netz. Nach dem Moduswechsel, dem Ende des Ladevorgangs oder dem Abstecken setzt sie den Puffer zurück auf den Standardwert. Den Stand merkt sie sich in der Datenbank, so setzt auch ein Neustart mittendrin zurück. Wird das Add-on während des Netzladens gestoppt, bleibt der Puffer oben, bis es wieder läuft.

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
