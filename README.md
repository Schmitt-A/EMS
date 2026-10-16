# EMS

Energie-App für eine PV-Anlage, einen Speicher, einen Zähler und eine Wallbox. Sie rechnet Energiefluss, Ladevorschlag, Prognose und Ladestatistik selbst und liest dafür die zugeordneten Home-Assistant-Entitäten. Mit dem Hauptschalter „EMS regelt die Wallbox“ unter Einstellungen steuert sie die go-e selbst; ist er aus, schreibt sie nichts.

Im Add-on spricht sie über den Supervisor. Lokal liegen Adresse und Token in `.env` (Vorlage: `.env.example`). Die Datei wird nicht eingecheckt.

## Oberfläche

Sechs Bereiche, auf dem Handy als Tab-Leiste unten, ab 1024 px als Leiste links:

- **Laden:** oben Solaranteil, Ø Preis und gespartes CO₂ der letzten 30 Tage, darunter der Energiefluss über die ganze Breite, ohne Karte vor dem Hintergrund. Wahlweise (Einstellungen → Darstellung) als Balken mit Klammern oben für PV, Speicher und Netzbezug und unten für Haus, Ladepunkt, Speicher und Einspeisung, oder als Energie-Flow, Rein → Raus: links Sonne, Speicher und Netz, rechts Haus, Auto, Speicher und Einspeisung. Jede Quelle hat eine eigene Linie zu jedem Ziel, das sie gerade versorgt, und die Punkte laufen in ihrer Farbe von links nach rechts. Breite, Zahl und Tempo der Punkte richten sich nach dem Anteil der Linie an allem, was hereinkommt: Kommen drei Viertel aus dem Netz, laufen dort mehr und schnellere Punkte als auf der Linie der Sonne. Der Ring der Sonne zeigt, wie viel der Tagesprognose schon erzeugt ist; die Ringe rechts an Haus, Auto, Speicher und Einspeisung zeigen als ganzer Kreis = 100 %, woher ihre Energie kommt. Unter jedem Namen steht in Grau eine Zeile mit Icons: an der Sonne gemessen und Prognose, am Speicher Ladestand und was bis voll fehlt, am Netz der Bezugspreis, am Haus der übliche Verbrauch (siehe unten), am Auto Ladestand und Reichweite, an der Einspeisung die Vergütung. Unter Einstellungen → Darstellung steht die gewählte Darstellung live als Vorschau. Die Karte darunter stellt Rein (PV, Prognose heute, Speicher, Netz) und Raus (Haus, Ladepunkt, Speicher, Einspeisung) zeilengleich nebeneinander. Dann die Ladepunkt-Karte: oben der Schalter „Laden“ (an oder aus), darunter Leistung, geladene Energie seit dem Anstecken und Restzeit, die laufende Wartezeit der Regelung (Einschalt-, Ausschaltverzögerung, Phasenwechsel oder Schutzzeit) mit Countdown, schmalem Balken und dem Grund, nur wenn EMS die Wallbox regelt und die Ausschaltverzögerung nur, solange das Auto lädt, die Übersicht des heutigen Ladevorgangs wie unter Ladevorgänge (alle Zyklen des Tages: Dauer, Strecke, Ø Leistung, Sonne, Kosten), die Modi für Sonne und Speicher (Intelligent, Min+Sonne, Schnell) mit ihrem Ladeziel, Netzladen („Jetzt aus dem Netz laden …“ mit eigenem Ziel, der Schalter „Ladefenster nutzen“ mit der Wahl der Fenster und die Ladepläne), der Ladebalken mit ziehbarem Limit und, wenn zugeordnet, der Kilometerstand. Darunter die Regelung: mit welcher Stufe EMS die Wallbox regelt oder, nur angezeigt, regeln würde, mit Countdown der Ein- und Ausschaltverzögerung, woher die Leistung käme und wohin der übrige Überschuss ginge, und die Phasen-Leiter mit der Umschaltung zwischen ein- und dreiphasig. Was der Sonne fehlt, kommt wie im Eigenverbrauch zuerst aus dem Speicher bis zu seinem Backup-Puffer, dann aus dem Netz. Aufgeklappt zeigt die Karte, was Intelligent, Min+Sonne, Schnell und Netzladen gerade täten, und den Rechenweg. Ladeparameter und Fahrzeug öffnen sich als Sheet.
- **Speicher:** oben die Säule mit den Zonen Haus, Auto und batteriegestützt und den Grenzen zum Ziehen, daneben gleich hoch der Verlauf über 3 oder 7 Tage; darunter „So wird geladen“ mit dem Backup-Puffer und der Ausblick: wann Speicher-Vorrang, batteriegestütztes Laden und voll erreicht sind, Überschuss und üblicher Hausverbrauch, die nächsten Tage als Rechnung (siehe unten) und der Energie-Boost mit der Empfehlung. Die Zonen gelten auch für die Regelung; eine Grenze auf 100 % schaltet Stützung und Start ohne Sonne ab.
- **Prognose:** oben umschaltbar zwischen Sonne und Strompreis. Sonne: die nächsten drei Tage in voller Breite, gemessen und Prognose im selben Diagramm. Über jedem Tag stehen die Prognose (Orange, mit Unsicherheit, wenn Platz ist) und, bis heute, was gemessen wurde, in der Farbe der Messlinie; zurückblättern zeigt vergangene Tage. Ist und Prognose mit Güte, Modelle, Rechnung und Wetter stehen unter Details. Strompreis: siehe unten.
- **Statistik:** eine Kennzahl als Heatmap. Zur Wahl stehen PV gemessen, Prognose und ihre Güte; Hausverbrauch, Netzbezug und seine Kosten, Einspeisung und ihre Vergütung, Autarkie, Eigenverbrauch der PV und gespartes CO₂; Börsenpreis, bezahlter Preis, Netzbezug statisch und dynamisch und die Ersparnis; Speicherstand, geladen und entladen; beim Laden Energie, Dauer, Sonnenanteil, Kosten und Anzahl der Ladevorgänge; vom DWD Strahlung, Sonnenschein, Bewölkung und Temperatur. Zeitraum Tag (24 Stunden), Monat (Kalender, umschaltbar auf Tage × Stunden), Quartal und Jahr (Monatsblöcke), Gesamt (Jahre × Monate) oder ein eigener Zeitraum, jeweils mit ‹ › zum Blättern. Ein Feld antippen zeigt seinen Wert; darunter Summe oder Gesamtanteil, Mittel, höchster und niedrigster Wert und alle Werte als Tabelle. Leistungen und Ladestand kommen aus der Langzeitstatistik von Home Assistant, also so weit zurück, wie Home Assistant sie hat; Ladevorgänge, Prognose und Wetter so weit, wie EMS sie gespeichert hat. Stündliches Wetter hebt EMS seit dieser Version gut zwei Jahre auf.
- **Ladevorgänge:** Monat, Jahr oder Gesamt mit Energie, Kosten oder CO₂, Solaranteil und alle Vorgänge mit Kilometerstand und Löschen. Im laufenden Monat steht heute ganz rechts im Diagramm, davor die Tage bis in den Vormonat (blasser, ohne Summe). Ein Ladevorgang sind alle Ladezyklen eines Tages, auch wenn das Auto über Nacht steckt; die Zyklen klappen auf.
- **Einstellungen** (Zahnrad, schmal „Optionen“): oben der Hauptschalter „EMS regelt die Wallbox“ mit den letzten Schaltvorgängen, darunter Ladepunkt, Fahrzeug, Speicher (Grenzen, Backup-Puffer, Laden aus dem Netz, Entladeleistung), Energie (Zuordnung), Prognose, Tarif & CO₂, Mitteilungen, Darstellung und System mit Verbindung und JSON-Export. Alte Links auf `/mehr` leiten weiter.

Die Diagramme scrollen waagerecht in Vergangenheit und Zukunft; ein erneuter Tipp auf den Zeitraum springt zurück zu heute. Werte zeigt das Antippen (Handy) oder Darüberfahren (Maus).

Hell und Dunkel folgen dem Gerät oder lassen sich unter Einstellungen → Darstellung festlegen. Die Seiten laden nichts von fremden Servern.

## Lademodi

Der Schalter „Laden“ oben in der Ladepunkt-Karte sperrt die Wallbox (aus) oder gibt sie frei (an). Darunter wählt man, wie Sonne und Speicher laden; ein Modus schaltet Laden auch wieder an:

- **Intelligent:** nur Sonnenüberschuss, nach den Zonen des Speichers.
- **Min+Sonne:** immer mit dem Mindeststrom, Sonnenüberschuss hebt an. Was fehlt, deckt der Speicher bis zum Backup-Puffer, dann das Netz.
- **Schnell:** sofort volle Leistung. Was die Sonne nicht schafft, deckt der Speicher bis zum Backup-Puffer, dann das Netz.

Getrennt davon steht **Netzladen**: Jetzt, in Ladefenstern oder nach Ladeplan (siehe unten). Dann lädt das Auto voll, was die Sonne nicht schafft, kommt aus dem Netz, und der Speicher gibt nichts ab.

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

Unter Prognose → Strompreis steht der Preis von jetzt im aktiven Tarif, der Börsenpreis, der Tag heute und morgen, ein Diagramm je Viertelstunde (dynamischer und statischer Preis, Börsenpreis netto, Einspeisevergütung; 14 Tage zurück bis zum letzten bekannten Preis, je Tag der günstigste und der teuerste Preis, die aktiven Ladefenster hinterlegt, ein- und ausblendbar mit „Ladefenster“), die Ladefenster selbst (siehe unten) und die Entscheidungshilfe „Lohnt sich dynamisch?“.

„Lohnt sich dynamisch?“ rechnet ein Jahr Tag für Tag durch. Einzutragen sind nur der Hausverbrauch im Jahr, beim Auto die Kilometer im Jahr und der Verbrauch je 100 km und wie es lädt: zuerst mit Sonnenüberschuss oder nur im Ladefenster, etwa weil es tagsüber unterwegs ist. Vorgeschlagen werden Werte aus deinen Daten (üblicher Hausverbrauch, Kilometerstand oder Energie der Ladevorgänge, Verbrauch des Autos). Die Preise sind die Börsenpreise der letzten 365 Tage mit deinen Aufschlägen von heute (Einstellungen → Tarif, dort stehen beide Tarife); Tage ohne Börsenpreis rechnen mit dem Ø Tagesverlauf der übrigen. Die Sonne kommt von PVGIS der EU-Kommission: Stunde für Stunde der Ertrag deiner Anlage in einem echten Wetterjahr an deinem Standort, mit trüben und sonnigen Tagen; dafür schickt EMS Standort, kWp, Neigung, Ausrichtung und Systemverluste dorthin, im Hintergrund, höchstens alle sechs Monate und wenn sich etwas davon ändert. Unter Einstellungen → Prognose stehen Stand und Standort der Abfrage; dort lassen sich ein anderer Standort (Breite und Länge, sonst der von Home Assistant), die Verluste (Standard 14 %) und das Wetterjahr (2005 bis 2023) einstellen und die Abfrage wiederholen. Stunde für Stunde nimmt das Auto zuerst Sonnenüberschuss (außer es lädt nur im Fenster), der Speicher den Rest bis voll und gibt ihn abends und nachts ab, nur bis zum Backup-Puffer (Notstrom), unter den er nie entlädt; Überschuss aus dem Sommer trägt er nicht in den Winter. Was fehlt, kommt aus dem Netz: statisch zum Arbeitspreis, dynamisch beim Haus zum Preis der Stunde und beim Auto im günstigsten Fenster des Tages, so weit es in dessen Länge passt (der Rest zum Ø Preis des Tages). Reicht die Sonne nicht, um den Speicher zu füllen, lädt er dynamisch im Fenster vor, wenn das Verluste und Verschleiß übersteigt. Das Ergebnis sind die Jahreskosten in beiden Tarifen, getrennt nach Arbeitspreis und Grundpreis (zwölf Monatspreise); die Einspeisevergütung ist in beiden gleich und zählt nicht mit. Die Heatmap zeigt die Kalenderwochen nach Quartalen, umschaltbar: Ø dynamischer Preis über den Tag und im günstigsten Fenster (ct/kWh brutto), was eine kWh im Fenster gegenüber deinem statischen Arbeitspreis spart (ct/kWh), Sonne je Woche (kWh) und wie viel von Haus und Auto Sonne und Speicher decken (%).

Den Börsenpreis (Day-Ahead, DE-LU) holt EMS selbst: von Energy-Charts (Fraunhofer ISE, Daten der Bundesnetzagentur | SMARD.de, Lizenz CC BY 4.0, die Quelle steht unter dem Diagramm), bei einem Ausfall von aWATTar. Die Preise für morgen gibt es ab etwa 13 Uhr. Einmalig lädt EMS zwei Jahre Vergangenheit nach, einen Monat je Schritt, damit „Lohnt sich dynamisch?“ und Statistik sofort rechnen.

Unter Einstellungen → Tarif stehen beide Tarife und welcher aktiv ist: statisch mit Arbeitspreis und Grundpreis, dynamisch mit Netzentgelt, Steuern und Umlagen, Anbieteraufschlag (netto), MwSt und Grundpreis. Vorbelegt sind typische Werte; die echten stehen im Preisblatt von Netzbetreiber und Anbieter. Mit §14a Modul 3 lässt sich ein Netzentgelt für bis zu drei Zeitfenster eintragen. Jede Änderung gilt ab einem Datum: Ladevorgänge, Energieübersicht und Statistik rechnen jede Zeit mit dem Preis, der damals galt. Mit Tibber oder Octopus trägt man dort zusätzlich die Preis-Entität des Anbieters ein; EMS zeichnet ihre Preise auf und nimmt sie im dynamischen Tarif statt Börse plus Aufschlägen.

Ein dynamischer Tarif braucht ein Smart Meter und rechnet je Viertelstunde ab. Home Assistant hebt Leistungen nur als Stundenmittel auf; darum misst EMS den Netzbezug seit 1.11 selbst je Viertelstunde und nimmt diese Werte, wo es sie gibt.

## Netzladen und Ladefenster

Ladefenster gibt es nur mit dynamischem Tarif; mit einem statischen kostet jede Viertelstunde gleich viel, darum fehlen sie dann überall: unter Prognose → Strompreis, im Diagramm, in der Kachel der Wallbox und beim Energie-Boost.

Mit dynamischem Tarif sucht EMS für jeden Tag, sobald die Preise da sind, das günstigste Fenster am Stück, Länge einstellbar (Standard 3 Stunden). Neue Preise bringen ihre Fenster von selbst mit: gegen 13 Uhr die für morgen, mit einer Preis-Entität von Tibber oder Octopus auch deren Preise, so weit sie reichen. Dazu kann man einen Preisbereich festlegen (bis, optional ab, in ct/kWh): Jede Zeit, in der der Preis darin liegt, wird ein Ladefenster, für alle kommenden Tage; im Diagramm ist der Bereich als waagerechtes Band markiert. Jedes Fenster lässt sich einzeln abschalten, eigene Fenster lassen sich anlegen und löschen. Aktive Fenster sind im Diagramm hinterlegt.

Netzladen in der Ladepunkt-Karte:

- **Jetzt aus dem Netz laden …:** öffnet zuerst das Ziel (Energie, Uhrzeit, Ladestand oder Reichweite); erst „Starten“ lädt sofort mit voller Leistung aus dem Netz, bis das Ziel erreicht ist, „Stoppen“ beendet es vorher, Abstecken ebenso. Danach gelten wieder die Ladefenster, wenn ihr Schalter an ist.
- **Ladefenster nutzen:** nur mit dynamischem Tarif zu sehen; ein eigener Schalter, der dauerhaft gilt, auch neben und nach „Jetzt“. In der Karte kreuzt man an, in welchen der aktiven Fenster das Auto laden soll, und bestätigt mit „Fenster übernehmen“. In diesen Fenstern lädt es voll aus dem Netz, bis das Ziel für die Fenster erreicht ist; danach bis zum Abstecken nicht mehr. Zwischen den Fenstern gilt der Modus. Beim Abstecken verfällt die Wahl.
- **Ladepläne:** an gewählten Wochentagen bis zu einer Uhrzeit einen Ladestand (mit der Reichweite dazu) oder eine Energiemenge erreichen, mehrere Pläne möglich; die Tage wählt man selbst. Steckt das Auto und kommt die Uhrzeit eines eingeschalteten Plans in den nächsten 24 Stunden, plant EMS die Viertelstunden davor: mit dynamischem Tarif die günstigsten, mit statischem die spätesten, damit die Sonne vorher noch hilft. Optional gilt ein Plan nur, wenn der Hausspeicher unter einem Ladestand liegt, bis zur Uhrzeit weniger Sonne erwartet wird und/oder der Ladestand des Autos unter einer Grenze liegt (der Regler nennt die Reichweite dazu); das Sheet zeigt die Werte von jetzt und je gewähltem Tag die Sonnenprognose bis zur Uhrzeit. Geprüft wird, sobald das Auto steckt und der Plan dran ist; gelten die Bedingungen dann, lädt der Plan bis zum Ziel, auch wenn das Auto dabei über seine Grenze kommt. Ein Ziel nach Ladestand endet am Ladelimit, das das Auto meldet; EMS ändert das Limit im Auto nicht. In der Kachel steht je Plan oben Ziel und Uhrzeit, darunter Wochentage, wann als Nächstes und die Bedingungen. Beim Tippen rechnet es außerdem, wann das Laden etwa startet (was zum Ziel fehlt geteilt durch die Ladeleistung) und was der Strom dafür kostet (statisch zum Arbeitspreis, dynamisch zum Ø der nächsten 24 Stunden). Erreicht, ruht der Plan bis zu seiner nächsten Uhrzeit.

Gerechnet wird mit der Ladeleistung der Wallbox (Höchststrom × Phasen) oder, wenn das Auto weniger nimmt, mit der des Autos: fest unter Fahrzeug eingetragen oder, solange das Feld leer ist, von EMS beim Netzladen gelernt (gleitender Mittelwert, einmal je Minute gemessen).

Das Ziel für die Ladefenster ist getrennt von dem für Sonne und Speicher und dem für „Jetzt“. Hat es (Energie, Ladestand oder Reichweite) ein „fertig bis“, prüft EMS, ob die gewählten Fenster davor reichen: was noch fehlt (mit 8 % Ladeverlust), die erwartete Sonne zwischen den Fenstern (Prognose minus Ø Haus, davon 70 %) und die Fenster bei voller Leistung. Reichen sie nicht, nimmt es die günstigsten weiteren Viertelstunden vor der Uhrzeit dazu, bei gleichem Preis die späteren, und nennt sie in der Karte; im Diagramm sind sie kräftiger hinterlegt. Kennt EMS die Preise für morgen noch nicht und reicht die Zeit danach, wartet der Rest auf sie (gegen 13 Uhr). EMS rechnet das alle 15 min neu und wenn sich Ziel, Modus oder Fenster ändern.

Für Regelung und Backup-Puffer gilt dann Netzladen; die Regelung zeigt „Netzladen (jetzt)“, „(Ladefenster)“ oder „(Ladeplan)“ mit dem Grund. Die Preisgrenze aus 1.12 gibt es nicht mehr, ihre Aufgabe übernimmt der Preisbereich der Ladefenster.

## Energie-Boost für den Speicher

Unter Speicher → Ausblick steht, nur mit dynamischem Tarif, eine Empfehlung: Für jedes aktive Fenster, das läuft oder kommt, rechnet EMS je Viertelstunde bis einen Tag danach, mit der Sonne laut Prognose, dem üblichen Hausverbrauch und den Preisen im aktiven Tarif (noch unbekannte wie am Vortag). Es probiert, bis zu welchem Stand der Speicher im Fenster laden sollte, und nimmt den günstigsten: Netzbezug im Fenster und danach, minus Einspeisung, mit Wirkungsgrad (Standard 90 %) und Verschleiß (Standard 2 ct je gespeicherter kWh). Empfohlen wird das Fenster, das am meisten spart; füllt die Sonne den Speicher ohnehin, oder deckt der Preisunterschied Verluste und Verschleiß nicht, rät EMS ab und sagt, warum.

Geladen wird nur mit einem Energie-Boost: einmalig in einem gewählten Fenster bis zu einem Ladestand, von Hand gesetzt oder mit „Empfehlung übernehmen“. Am Ziel oder am Ende des Fensters ist er verbraucht; abbrechen geht jederzeit. Geschaltet wird nur, wenn EMS regelt. EMS stellt dann den Betriebsmodus des Speichers auf manuell und „Laden erzwingen“ auf die Ladeleistung (Standard die Entladeleistung, sonst 2,5 kW) und am Ziel, am Ende des Fensters oder beim Abbrechen zurück auf 0 W und den Betriebsmodus von vorher. Beide Entitäten stehen unter Einstellungen → Speicher; bei der sonnenBatterie heißen sie `select.sonnenbatterie_…_select_operating_mode` und `number.sonnenbatterie_…_number_charge` und erscheinen erst, wenn die Integration schreiben darf. Ins Netz geht von der geladenen Energie nichts zurück.

## Ladeziele

In der Ladepunkt-Karte setzt „Ladeziel setzen“ unter den Modi ein Ziel für Sonne und Speicher im laufenden Ladevorgang: einen Ladestand des Autos (der Regler nennt die Reichweite dazu), eine Energiemenge seit dem Anstecken (mit geschätzter Dauer) oder eine Uhrzeit (mit Countdown und bisher geladenen kWh). Unter Netzladen gibt es ein eigenes Ziel fürs Netzladen, dort mit „fertig bis“ (siehe Netzladen und Ladefenster). Jedes Ziel nennt auch die Kilometer: was es bringt, was bis zur Uhrzeit zusammenkommt, die Reichweite danach; schon beim Eintippen rechnet das Sheet sie mit. Den Verbrauch dafür trägt man unter Einstellungen → Fahrzeug ein (kWh/100 km), sonst nimmt EMS den, mit dem das Auto seine Reichweite rechnet, und zieht 8 % Ladeverlust ab. Ist das Ziel für Sonne und Speicher erreicht, wechselt EMS in den Folgemodus; „Laden aus“ stoppt die Wallbox. Beim Abstecken verfallen beide Ziele. Unter Einstellungen → Ladepunkt stehen der vorgeschlagene Folgemodus und, was nach dem Abstecken gelten soll.

## Üblicher Hausverbrauch

Ausblick, Speicherplanung, Energie-Flow und Ladeplan rechnen mit dem üblichen Hausverbrauch ohne Wallbox: je Stunde des Tages der Median der letzten 30 Tage. Zieht der Hauszähler die Wallbox mit ein, rechnet EMS sie heraus, mit ihrer Statistik oder, wo die fehlt, mit den Ladevorgängen. Der Median sorgt dafür, dass einzelne Tage, an denen das Auto geladen hat, bevor EMS die Wallbox gemessen hat, den Wert nicht hochziehen.

Die Tabelle „Die nächsten Tage“ unter Speicher → Ausblick rechnet je Tag: was im Speicher über dem Backup-Puffer nutzbar ist (heute das von jetzt, mit Ladestand), plus Sonne laut Prognose, minus Haus, gleich Bilanz. Darunter, Viertelstunde für Viertelstunde gerechnet, was ins Netz geht, weil der Speicher voll ist, was aus dem Netz kommt, weil er bis zum Puffer leer ist, und was am Ende des Tages im Speicher bleibt; damit beginnt der nächste Tag. Heute zählt nur der Rest des Tages; Verluste beim Laden und Entladen rechnet die Tabelle nicht.

## Netzladen und Backup-Puffer

Unter Einstellungen → Speicher steht der Backup-Puffer als number-Entität, dazu sein Standardwert. Beim Speichern setzt die App den Standardwert im Speicher. Regelt EMS und lädt das Auto mit Netzladen (jetzt oder im Ladefenster), hebt sie den Puffer auf den aktuellen Ladestand: Der Speicher gibt dann nichts ab, weder ans Auto noch ans Haus, und was die Sonne nicht schafft, kommt aus dem Netz. Nach dem Moduswechsel, dem Ende des Ladevorgangs oder dem Abstecken setzt sie den Puffer zurück auf den Standardwert. Den Stand merkt sie sich in der Datenbank, so setzt auch ein Neustart mittendrin zurück. Wird das Add-on während des Netzladens gestoppt, bleibt der Puffer oben, bis es wieder läuft.

Bei der sonnenBatterie (Integration `sonnenbatterie`) heißt die Entität `number.sonnenbatterie_…_battery_reserve`. Sie erscheint erst, wenn im Speicher der Schreibzugriff der JSON-API freigeschaltet ist, und zeigt den echten Wert erst nach dem ersten Setzen.

## Mitteilungen aufs Handy

Unter Einstellungen → Mitteilungen schickt EMS Push-Mitteilungen über die Home-Assistant-App (`notify.mobile_app_…`). Jedes Handy, auf dem die App bei diesem Home Assistant angemeldet ist, steht dort zum Ankreuzen. Mitteilungen gehen auch raus, wenn der Hauptschalter aus ist.

Zum Ankreuzen, in fünf Gruppen:

- **Wichtig:** Störung der Regelung (ein anderer Regler schreibt mit, ein Schreibzugriff schlägt fehl, der Backup-Puffer lässt sich nicht setzen), keine Messwerte seit 10 Minuten, Auto lädt trotz Freigabe seit 5 Minuten nicht.
- **Laden:** angesteckt, gestartet, beendet, Ladeziel erreicht, abgesteckt mit der Bilanz des Ladevorgangs, Lademodus geändert (auch von jemand anderem oder nach einem Ladeziel), Netzladen geändert (Aus, Jetzt, In Ladefenstern), Ladeplan wird knapp (ein Ziel fürs Netzladen mit „fertig bis“ reicht nur noch mit durchgehendem Laden, einmal je Ziel) und Regelung ein oder aus. Kurze Pausen der Sonne unter 10 Minuten melden weder Ende noch Start; ist das Auto voll oder abgesteckt, kommt das Ende sofort.
- **Speicher und Sonne:** Speicher voll, Speicher fast leer, Sonne übrig ohne Auto, sonniger Tag morgen, jeweils mit einstellbarer Grenze und höchstens einmal am Tag, und Energie-Boost (Beginn und Ende).
- **Strompreis:** Preise für morgen, sobald sie da sind, mit dem günstigsten Fenster; nur mit dynamischem Tarif.
- **Berichte:** Tagesbericht und am Ersten der Monatsbericht des Vormonats, zur Berichtszeit.

Ab Werk sind Wichtig, gestartet, beendet, Ladeziel, Ladeplan knapp und Energie-Boost an. Der Stift neben jeder Meldung öffnet Titel und Text mit Platzhaltern wie `{auto}`, `{geladen}`, `{sonnenanteil}`, `{kosten}`, `{pv}` oder `{prognose_morgen}`; ein Tippen auf einen Platzhalter fügt ihn ein, die Vorschau zeigt die Mitteilung mit den Werten von jetzt, und „Probe senden“ schickt sie sofort. Wichtige Meldungen kommen auf dem iPhone zeitkritisch und unter Android im Kanal „EMS wichtig“, auch in der einstellbaren Ruhezeit; die übrigen kommen dann leise. Ein Tippen auf die Mitteilung öffnet EMS in der App auf der passenden Seite, etwa Speicher bei „Speicher voll“ oder Strompreis bei den Preisen für morgen. Darunter steht die Test-Mitteilung mit eigenem Titel und Text und die Liste der zuletzt gesendeten.

Eigene Mitteilungen legt man unter „Eigene Mitteilungen“ ganz frei an, bis zu 20: einen Namen, bis zu fünf Bedingungen, verknüpft mit „alle“ oder „mindestens eine“, wie lange sie am Stück gelten müssen (Minuten) und wie oft sie melden (jedes Mal, wenn sie eintritt, frühestens nach 10 Minuten wieder; stündlich, solange sie gilt; einmal am Tag), dazu Titel und Text mit allen Platzhaltern. Bedingungen gibt es für Sonne, Haus, Überschuss, Einspeisung und Netzbezug jetzt, Sonne heute erzeugt und noch erwartet, Prognose heute und morgen, Ladestand und Energie des Speichers, Auto angesteckt oder lädt, Ladeleistung, geladene Energie, Ladestand und Reichweite des Autos, Lademodus, Netzladen, EMS-Regelung, Strompreis jetzt, dynamischen Preis und Börsenpreis, ob gerade ein Ladefenster läuft, Uhrzeit und Wochentag. Neben jeder Bedingung steht ihr Wert von jetzt und darunter, ob sie gerade gilt. Gilt eine Mitteilung schon beim Anlegen, meldet „jedes Mal, wenn sie eintritt“ erst, nachdem sie einmal nicht galt. Neue Platzhalter für alle Mitteilungen sind unter anderem `{preis}`, `{preis_dynamisch}`, `{boerse}`, `{preis_morgen}`, `{fenster}`, `{fenster_morgen}`, `{netzladen}`, `{ziel_netz}`, `{plan}`, `{ueberschuss}`, `{speicher_kwh}`, `{boost}` und `{sonne_rest}`.

## Fahrzeugdaten über Bluetooth

Ladestand, Reichweite, Kilometerstand und Ladelimit des Autos sind eigene Entitäten unter Einstellungen → Fahrzeug, dazu ein Weck-Button, den EMS drückt, wenn das Auto 60 s nach der Freigabe nicht lädt. Mit [ESPHome Tesla BLE](https://github.com/PedroKTFC/esphome-tesla-ble) heißen sie etwa `sensor.tesla_ble_charge_level`, `sensor.tesla_ble_range`, `sensor.tesla_ble_odometer` und `sensor.tesla_ble_charge_limit`; die App schlägt gefundene Sensoren vor und rechnet Meilen in Kilometer um. Meldet das Auto sein Ladelimit, gilt es statt des Reglers. Jeder neue Ladevorgang bekommt den Kilometerstand beim Start. Die Kapazität des Akkus lässt sich von Hand eintragen, weil Tesla BLE sie nicht meldet; ohne sie bleibt die Restzeit leer, die Karte sagt dann „Akku fehlt“. Meldet das Auto seine Restzeit bis voll selbst, lässt sie sich als „Restzeit vom Auto“ zuordnen und gilt dann statt der gerechneten. Einen Verbrauchssensor hat Tesla BLE nicht; die Reichweite kommt direkt als Sensor. Fehlt eine Reichweiten-Entität, rechnet EMS sie aus Ladestand, Akku und dem eingetragenen Verbrauch.

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

Links und Vollbild (ab Home Assistant 2026.2): Die Adresse oben im Browser folgt der Seite in EMS, etwa `https://…/<slug>/speicher`, und so ein Link öffnet die Seite direkt, auch als Lesezeichen. Mit `?vollbild` am Ende blendet Home Assistant Seitenleiste und Kopfzeile aus, und EMS steht allein im Fenster; der Knopf links vom Seitentitel öffnet dann das Menü von Home Assistant. Unter Einstellungen → Darstellung stehen beide Links zum Kopieren, und dort lässt sich EMS als Startseite von Home Assistant festlegen, für dich oder als Admin für alle (dieselbe Einstellung wie Profil → Dashboard und Einstellungen → Dashboards → Als Standard festlegen; dafür muss EMS in der Seitenleiste stehen). Angemeldet wird immer bei Home Assistant, EMS hat keinen eigenen Port.

Konfiguration und Ladevorgänge liegen in `/data/ems.sqlite` und bleiben bei Updates erhalten. An die Wallbox schreibt die App nur bei eingeschaltetem Hauptschalter.
