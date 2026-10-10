# EMS

Energie-App für eine PV-Anlage, einen Speicher, einen Zähler und eine Wallbox. Sie rechnet Energiefluss, Ladevorschlag, Prognose und Ladestatistik selbst und liest dafür die zugeordneten Home-Assistant-Entitäten. Schreiben kann sie genau eine: den Backup-Puffer des Speichers, wenn er unter Mehr → Speicher zugeordnet ist.

Im Add-on spricht sie über den Supervisor. Lokal liegen Adresse und Token in `.env` (Vorlage: `.env.example`). Die Datei wird nicht eingecheckt.

## Oberfläche

Fünf Bereiche, auf dem Handy als Tab-Leiste unten, ab 1024 px als Leiste links:

- **Laden:** oben Solaranteil, Ø Preis und gespartes CO₂ der letzten 30 Tage, darunter der Energiefluss als Balken: Klammern oben für PV, Speicher und Netzbezug, unten für Haus, Ladepunkt, Speicher und Einspeisung, jeweils mit Leistung. Die Tabelle darunter stellt Rein (PV, Prognose heute, Speicher, Netz) und Raus (Haus, Ladepunkt, Speicher, Einspeisung) zeilengleich nebeneinander. Dann die Ladepunkt-Karte mit Modus (Aus, Nur Solar, Min+Solar, Netzladen), Leistung, geladener Energie seit dem Anstecken, Restzeit, Ladebalken mit ziehbarem Limit und, wenn zugeordnet, dem Kilometerstand. Darunter die Regelung: mit welcher Stufe die Wallbox jetzt laden würde, woher die Leistung käme und wohin der übrige Überschuss ginge, und die Phasen-Leiter mit der Umschaltung zwischen ein- und dreiphasig. Was der Sonne fehlt, kommt wie im Eigenverbrauch zuerst aus dem Speicher bis zu seinem Backup-Puffer, dann aus dem Netz. Aufgeklappt zeigt die Karte, was Nur Solar, Min+Solar und Netzladen gerade täten, und den Rechenweg. Ladeparameter und Fahrzeug öffnen sich als Sheet.
- **Speicher:** die Säule mit den Zonen Haus, Auto und batteriegestützt, Grenzen zum Ziehen, der Backup-Puffer, wann die Grenzen erreicht sind, und der Verlauf über 3 oder 7 Tage. Die Zonen gelten auch für die Regelung; eine Grenze auf 100 % schaltet Stützung und Start ohne Sonne ab.
- **Prognose:** Sonne der nächsten drei Tage mit Unsicherheit, gemessen und Prognose im selben Diagramm, Güte, Modelle, Rechnung und Wetter.
- **Ladevorgänge:** Monat, Jahr oder Gesamt mit Energie, Kosten oder CO₂, Solaranteil und alle Vorgänge mit Kilometerstand und Löschen. Im laufenden Monat steht heute ganz rechts im Diagramm, davor die Tage bis in den Vormonat (blasser, ohne Summe). Ein Ladevorgang reicht vom Anstecken bis zum Abstecken; seine einzelnen Ladezyklen klappen auf.
- **Mehr:** Ladepunkt, Fahrzeug, Speicher (Grenzen, Backup-Puffer, Entladeleistung), Energie (Zuordnung), Prognose, Tarif & CO₂, Darstellung und System mit Verbindung und JSON-Export.

Die Diagramme scrollen waagerecht in Vergangenheit und Zukunft; ein erneuter Tipp auf den Zeitraum springt zurück zu heute. Werte zeigt das Antippen (Handy) oder Darüberfahren (Maus).

Hell und Dunkel folgen dem Gerät oder lassen sich unter Mehr → Darstellung festlegen. Die Seiten laden nichts von fremden Servern.

## Netzladen und Backup-Puffer

Unter Mehr → Speicher steht der Backup-Puffer als number-Entität, dazu sein Standardwert. Beim Speichern setzt die App den Standardwert im Speicher. Ist „Speicher schonen“ an und lädt das Auto im Modus Netzladen, hebt sie den Puffer auf den aktuellen Ladestand: Der Speicher gibt dann nichts ab, weder ans Auto noch ans Haus, und was die Sonne nicht schafft, kommt aus dem Netz. Nach dem Moduswechsel, dem Ende des Ladevorgangs oder dem Abstecken setzt sie den Puffer zurück auf den Standardwert. Den Stand merkt sie sich in der Datenbank, so setzt auch ein Neustart mittendrin zurück. Wird das Add-on während des Netzladens gestoppt, bleibt der Puffer oben, bis es wieder läuft.

Bei der sonnenBatterie (Integration `sonnenbatterie`) heißt die Entität `number.sonnenbatterie_…_battery_reserve`. Sie erscheint erst, wenn im Speicher der Schreibzugriff der JSON-API freigeschaltet ist, und zeigt den echten Wert erst nach dem ersten Setzen.

## Fahrzeugdaten über Bluetooth

Ladestand, Reichweite, Kilometerstand und Ladelimit des Autos sind eigene Entitäten unter Mehr → Fahrzeug. Mit [ESPHome Tesla BLE](https://github.com/PedroKTFC/esphome-tesla-ble) heißen sie etwa `sensor.tesla_ble_charge_level`, `sensor.tesla_ble_range`, `sensor.tesla_ble_odometer` und `sensor.tesla_ble_charge_limit`; die App schlägt gefundene Sensoren vor und rechnet Meilen in Kilometer um. Meldet das Auto sein Ladelimit, gilt es statt des Reglers. Jeder neue Ladevorgang bekommt den Kilometerstand beim Start. Die Kapazität des Akkus lässt sich von Hand eintragen, weil Tesla BLE sie nicht meldet.

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

Konfiguration und Ladevorgänge liegen in `/data/ems.sqlite` und bleiben bei Updates erhalten. An die Wallbox schreibt die App nichts.
