# EMS

Energie-App für eine PV-Anlage, einen Speicher, einen Zähler und eine Wallbox. Sie rechnet Energiefluss, Ladevorschlag, Prognose und Ladestatistik selbst und liest dafür nur die zugeordneten Home-Assistant-Entitäten.

Im Add-on spricht sie über den Supervisor. Lokal liegen Adresse und Token in `.env` (Vorlage: `.env.example`). Die Datei wird nicht eingecheckt.

## Oberfläche

Fünf Bereiche, auf dem Handy als Tab-Leiste unten, ab 1024 px als Leiste links:

- **Laden:** oben Solaranteil, Ø Preis und gespartes CO₂ der letzten 30 Tage, darunter der Energiefluss als Balken: Klammern oben für PV, Speicher und Netzbezug, unten für Haus, Ladepunkt, Speicher und Einspeisung, jeweils mit Leistung. Die Tabelle darunter stellt Rein (PV, Prognose heute, Speicher, Netz) und Raus (Haus, Ladepunkt, Speicher, Einspeisung) zeilengleich nebeneinander. Dann die Ladepunkt-Karte mit Modus (Aus, Solar, Min+Solar, Schnell), Leistung, geladener Energie seit dem Anstecken, Restzeit und Ladebalken mit ziehbarem Limit. Darunter die Regelung: mit welcher Stufe die Wallbox jetzt laden würde, woher die Leistung käme (Sonne, Speicher, Netz) und wohin der übrige Überschuss ginge, die Phasen-Leiter mit der Umschaltung zwischen ein- und dreiphasig und was Solar, Min+Solar und Schnell gerade täten. Ladeparameter und Fahrzeug öffnen sich als Sheet.
- **Speicher:** die Säule mit den Zonen Haus, Auto und batteriegestützt, Grenzen zum Ziehen, wann sie erreicht sind, und der Verlauf über 3 oder 7 Tage. Die Zonen gelten auch für die Regelung; eine Grenze auf 100 % schaltet Stützung und Start ohne Sonne ab.
- **Prognose:** Sonne der nächsten drei Tage mit Unsicherheit, gemessen und Prognose im selben Diagramm, Güte, Modelle, Rechnung und Wetter.
- **Ladevorgänge:** Monat, Jahr oder Gesamt mit Energie, Kosten oder CO₂, Solaranteil und alle Vorgänge mit Kilometerstand und Löschen. Im laufenden Monat steht heute ganz rechts im Diagramm, davor die Tage bis in den Vormonat (blasser, ohne Summe). Ein Ladevorgang reicht vom Anstecken bis zum Abstecken; seine einzelnen Ladezyklen klappen auf.
- **Mehr:** Ladepunkt, Fahrzeug, Energie (Zuordnung), Prognose, Tarif & CO₂, Darstellung und System mit Verbindung und JSON-Export.

Die Diagramme scrollen waagerecht in Vergangenheit und Zukunft; ein erneuter Tipp auf den Zeitraum springt zurück zu heute. Werte zeigt das Antippen (Handy) oder Darüberfahren (Maus).

Hell und Dunkel folgen dem Gerät oder lassen sich unter Mehr → Darstellung festlegen. Die Seiten laden nichts von fremden Servern.

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

Konfiguration und Ladevorgänge liegen in `/data/ems.sqlite` und bleiben bei Updates erhalten. Die App schreibt im Moment nichts an die Wallbox.
