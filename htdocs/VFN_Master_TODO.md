# Virtual Flight Network (VFN) – Master-TODO

Letzte Prüfung: 05.08.2026

## Status

- `[x]` im aktuellen Code oder in der produktiven Datenbank nachgewiesen
- `[-]` teilweise umgesetzt, noch nicht vollständig
- `[ ]` offen

## Prüfungsumfang

Geprüft wurden die Quell- und Konfigurationsdateien des X-Plane-Plugins, die
PHP-Webseite und API, der Node.js-Voice-Service sowie die Struktur der
produktiven MySQL-Datenbank. Abhängigkeiten (`node_modules`), Build-Artefakte,
Bilder und Binärdateien wurden nur auf Vorhandensein geprüft.

---

# 1. Benutzer und Konten

- [x] Registrierung mit Division und Herkunftsland
- [x] E-Mail-Verifizierung
- [x] Web-Login und Web-Logout
- [x] Plugin-Login und Plugin-Logout
- [x] Session- und Token-System
- [x] Websession mit gleitender Laufzeit von 30 Tagen
- [x] Sprache im Benutzerkonto und für ausgeloggte Besucher per Cookie speichern
- [x] „Angemeldet bleiben“ im Plugin
- [x] Passwort-Recovery per E-Mail
- [x] Einmalige, gehashte und zeitlich begrenzte Reset-Tokens
- [x] Aktiver/inaktiver Benutzerstatus
- [x] Bann-Felder in der Datenbank
- [x] Benutzer-Selbstverwaltung im eigenen Profil
  - [x] Benutzername ändern
  - [x] Realname ändern
  - [x] Land ändern
  - [x] Divisionstransfer beantragen
  - [x] Passwort im eingeloggten Profil ändern
- [x] Schutz gegen wiederholte Login- und Reset-Anfragen (Rate Limiting)
- [-] Zwei-Faktor-Authentifizierung für Staff-Konten
  - [x] Authenticator-App (TOTP)
  - [x] Einmalcode per E-Mail
  - [x] Absicherung des Web-Logins
  - [ ] 2FA-Ablauf für den Plugin-Login
  - [ ] Wiederherstellungscodes

# 2. Profile

- [x] Öffentliche Profilseite
- [x] Overview-Unterseite
- [x] Activity-Unterseite
- [x] Awards-Unterseite
- [x] Mehrsprachigkeit Deutsch/Englisch
- [x] Land und Flagge
- [x] Division
- [x] Online-/Offline-Status
- [x] Pilot-, ATC- und Spezialrating
- [x] Lieblingsflugzeug
- [x] Letzter Flug
- [x] Flugstunden und Distanz
- [x] Landungsanzahl und letzte Aufsetzrate
- [x] Flugzeugstatistiken
- [x] Öffentliche Pilot-Unterseite mit paginiertem Flugbuch und Flughistorie
- [x] Verlinkte Flugdetailseite mit Flugdaten und gespeicherter Route
- [ ] Eigene ATC-Unterseite
- [x] Profil-Einstellungen für das eigene Profil
- [x] Profilbild hochladen, verschieben, zoomen und löschen
- [x] Sichere serverseitige Avatar-Neucodierung als einheitliches 512×512-JPEG
- [x] Private „Administrative History“ für Staff ab OP-Level 4
- [x] Staff-Ansicht für Warnungen, Banns, Trainings- und Prüfungsverlauf

# 3. Activity-System

- [x] Zentrales Web-Benachrichtigungszentrum für Activities, PMs und Staff-Anfragen
- [x] Ungelesene Activities und private Nachrichten im Header kennzeichnen
- [x] Activities nur durch den jeweiligen Profilinhaber als gelesen markieren; Staff-Ansichten bleiben lesend
- [x] Activity- und Award-Schlüssel im Benachrichtigungszentrum übersetzt darstellen

- [x] Tabelle `user_activity_log`
- [x] Activity-Logging-Funktion
- [x] Activity-Unterseite im Profil
- [x] Actor-System für System, Benutzer und Staff
- [x] Registrierung protokollieren
- [x] E-Mail-Bestätigung protokollieren
- [x] Award-Freischaltung protokollieren
- [x] Warnungs-Aktivität technisch darstellbar
- [x] Rating-Änderung technisch darstellbar
- [x] Staff-Aktivitäten und paginierte administrative Gesamthistorie im Admin Panel
- [x] Passwortänderung protokollieren
- [x] Benutzername, Realname und Land ändern/protokollieren
- [x] Divisionstransfer beantragen, genehmigen/ablehnen und protokollieren
- [-] Flugbeginn und Flugabschluss vollständig protokollieren
  - [x] Einzelne aktive, abgeschlossene und abgebrochene Flüge in `pilot_flights` speichern
  - [x] Landungen außerhalb von Ziel-/Ausweichflughäfen als `Falscher Zielflughafen` markieren und tatsächlichen Landeplatz speichern
  - [ ] Flugbeginn und Flugabschluss zusätzlich als Activity-Einträge erfassen
- [-] Erste, 10. und 100. Landung protokollieren
  - [x] Erste Landung protokollieren
  - [ ] 10. und 100. Landung protokollieren
- [ ] Prüfung bestanden/nicht bestanden protokollieren
- [ ] Training begonnen/abgeschlossen protokollieren
- [x] Rating vergeben/entziehen mit Staff-Aktion und Activity-Eintrag
- [x] Verwarnungen mit Laufzeit vergeben und ab OP-Level 4 begründet aufheben
- [x] Kick, zeitlich begrenzte/permanente Banns und Entbannung protokollieren
- [x] Entbannungsanträge stellen, genehmigen/ablehnen und protokollieren
- [x] Staff-Rolle vergeben/entziehen
- [x] Interne Staff-, Trainings- und Prüfungsnotizen
- [ ] Sichtbarkeit der Activities zentral nach öffentlich/privat/Staff regeln

# 4. Awards

- [x] Tabellen und Vergabe-System (`user_awards`)
- [x] Anzeige im Profil
- [x] Benachrichtigung im Plugin-Chat
- [x] First Flight
- [x] First Landing
- [x] Butter Landing
- [x] Hard Landing
- [x] Crash Pilot
- [x] Fuel Gambler
- [x] World Traveler
- [x] Global Explorer
- [x] International Ace
- [x] Globe Master
- [x] Night Owl
- [x] Moon Walker
- [x] Master of Night
- [x] Founder’s House
- [ ] Awards für Flugstunden und Anzahl der Flüge
- [ ] Awards für 10/100/1000 Landungen
- [ ] ATC-Awards
- [ ] Trainings- und Prüfungs-Awards
- [ ] Administrative Awards/Badges für Warnungen und Banns prüfen

# 5. Flugtracking und Karte

- [x] Live-Positionen
- [x] Kartenansicht
- [x] Flugspuren
- [x] Start- und Zielflughafen
- [x] Flugzeugtyp und Flugzeugkategorie
- [x] Höhe, Kurs, Geschwindigkeit und Vertical Speed
- [x] COM-Frequenzen und Transponder
- [x] Unsichtbar-Modus für berechtigte Staff-Mitglieder
- [x] Flugzeugstatistiken
- [x] Landungserkennung und Aufsetzrate
- [x] Airport-Traffic-Gruppierung auf der Karte
- [x] Durchsuchbare Online-Pilotenliste auf der Karte
- [x] Optionaler 30-NM-Filter mit laufender Entfernung zur eigenen Simulatorposition
- [x] Spectators für Staff ab OP-Level 1 in der Kartenliste kennzeichnen, aber nicht als Flugzeug darstellen
- [x] KI-gesteuerte Flugzeuge für Staff ab OP-Level 1 mit Bot-Symbol kennzeichnen
- [x] Kartenlegende und persistente Filter-/Ebeneneinstellungen
- [x] Live-Follow-Modus für ein ausgewähltes Flugzeug
  - [x] Sichtbarer Start-/Stopp-Schalter direkt im Piloten-Detailpanel
- [x] Flugfortschritt im Kartenpanel mit geflogener Zeit, AIRAC-Routendistanz und geschätzter Restflugdauer
- [x] Schaltbare orange Flugplanroute mit geglätteter Wegpunktkurve und benutzerspezifischer Beschriftungsanzeige
- [x] Direkter Sprung vom Spielerprofil zur aktuellen Kartenposition
- [x] Klickbare Abflug- und Zielflughäfen mit linkem Trafficpanel
- [x] Flughafensuche nach ICAO, Name und Stadt auch ohne aktuellen Traffic
- [x] Flughafenpanel mit Inbound-, Outbound- und METAR-Reiter
- [x] Ausgewählten Flughafen bis zum Schließen des Panels blau markieren
- [x] AIRAC-Wegpunkte und Navaids in der Karte suchen und markieren
- [x] Radarstationen und FIR/UIR-Sektoren in der Kartensuche finden, markieren und im linken Detailpanel anzeigen
- [x] Helipads und Flughäfen mit nichtstandardisierten Kennungen auf der Flughafen-Detailseite darstellen
- [x] AIRAC-Abfragen und aktuellen Zyklus serverseitig für 24 Stunden cachen
- [x] Kartensuche nach Piloten, Flughäfen, Wegpunkten und Navaids filtern sowie auf exakte Kennungen begrenzen
- [-] ATC-Sektoren und Zuständigkeitsgrenzen als schaltbare Kartenebene darstellen
  - [x] Weltweite FIR/ARTCC-Grenzen aus dem CC-BY-SA-lizenzierten VATSpy-GeoJSON lokal importieren
  - [x] FIR/ARTCC-Ebene in der Karte schaltbar, verzögert geladen und anklickbar machen
  - [ ] Weltweite reale CTR-, CTA- und TMA-Lufträume aus einer zulässigen Quelle importieren
  - [ ] Eigenes GeoJSON-Format und Admin-Import für operative VFN-Untersektoren bereitstellen
  - [ ] FIR/ACC-, APP- und weitere Sektorebenen mit Höhenband und Bezeichnung unterscheiden
- [x] Airways mit allen Segmenten auf der Karte darstellen
  - [x] Airways über die AIRAC-Suche als eigenen Ergebnistyp finden
  - [x] Alle verfügbaren Fixes und zusammenhängenden Teilstrecken einer ausgewählten Airway darstellen
  - [x] Gleichnamige Airways verschiedener Weltregionen ohne falsche interkontinentale Verbindung trennen
  - [x] Airway-Details serverseitig zwischenspeichern und Wegpunkte auf der Karte beschriften
- [x] Direkt verlinkbare Kartenansicht über stabile Benutzer-ID
- [x] Automatische Bereinigung veralteter Positionen und Tracks
  - [x] Veraltete Live-Positionen nur bei inaktiver oder ebenfalls veralteter Sitzung entfernen
  - [x] Verwaiste Trackpunkte ohne zugehörigen Flug nach sieben Tagen löschen
  - [x] Abgeschlossene Tracks nach 30 beziehungsweise 180 Tagen verlustarm verdichten
  - [x] Sehr alte Tracks abgebrochener Flüge nach 180 Tagen entfernen
  - [x] Täglichen, konkurrenzsicheren Wartungslauf mit Systemstatus-Protokollierung einbauen
- [x] Eigene Airport-Traffic-Seiten
  - [x] Stammdaten, Kartenposition und aktuelles METAR
  - [x] Sichtbarer Live-Verkehr sowie letzte abgeschlossene Flüge
  - [x] Bewegungen, Abflüge, Ankünfte und eindeutige Piloten
  - [x] Top-Routen, Flugzeugtypen und Piloten
  - [x] Verlinkung aus Karte, Flugbuch, Flugplänen und Statistik
- [x] Netzwerkstatistikseite mit Top Airports, Piloten-Herkunftsländern und Movement-Ländern
- [x] Erweiterte Flugverlaufsanalyse
  - [x] Höhen- und Geschwindigkeitsprofil auf der Flugdetailseite darstellen
  - [x] Maximale Höhe, Durchschnitts-/Maximalgeschwindigkeit und Trackdistanz berechnen
  - [x] Maximale Steig-/Sinkrate und Zeitanteile der Flugphasen auswerten
  - [x] Streckeneffizienz aus direkter Distanz und tatsächlich geflogenem Track berechnen
- [x] Zeitraumabhängige Traffic-Heatmap direkt in `map.php`
- [x] Langfristiges Flugbuch mit einzelnen aktiven, abgeschlossenen und abgebrochenen Flügen

# 6. Flugpläne und D-ATIS

- [x] Flugplanfenster im Plugin
- [x] Textflugplan an Backend senden und in `pilot_flightplans` speichern
- [x] Flugplandaten in der Karte/API bereitstellen
- [x] D-ATIS-Fenster im Plugin
- [x] Automatisches METAR-D-ATIS
- [x] Controller-D-ATIS über `controller_atis`
- [x] Flugplanbearbeitung und paginierte Historie auf der Webseite
- [ ] ATC-seitige Flugplanannahme/-änderung
- [ ] Voice Flightplans

# 7. Chat und Kommunikation

- [x] Frequenzbasierter Netzwerk-Chat
- [x] Plugin-Chatfenster und Nachrichtenton
- [x] Private Nachrichten (`/msg`)
- [x] Web-Postfach mit gemeinsamen Plugin-/Website-PMs, Spielersuche und neuen Unterhaltungen
- [x] Spielerliste (`/list`)
- [x] Spielerprofil öffnen (`/playerinfo`)
- [x] Staff-Chat (`/staff`)
- [x] Staff-Frequenzchat über das Web-Admin-Panel
- [x] Positionslose Web-Staff-Nachrichten auf der gewählten Frequenz global zustellen
- [x] Im Web-Staff-Chat zwischen globaler Frequenz und regionalem Bezugspiloten wählen
- [x] Frequenzunabhängige globale Announcements über das Web-Admin-Panel (OP-Level 5)
- [x] Netzwerk-Announcements (`/announcement`)
- [x] Kick-Kommando und Plugin-Kick-Hinweis
- [x] Chat-Polling und Nachrichtenpersistenz
- [x] Chat-Polling für normale Spieler unabhängig von der Unsichtbar-Berechtigung
- [x] Sendefrequenz im Plugin-Chat und MSG-Fenster anzeigen
- [x] Zentraler Schimpfwortfilter mit Sternmaskierung im Plugin
- [x] Rote Originalwort-Hervorhebung nur im geschützten Admin-Monitor
- [x] Filterwort-Verwaltung im Admin Panel ab OP-Level 4
- [x] Serverseitiger Plugin-Chat-Spamschutz mit automatischem Kick
- [x] Spam-Kick als Benutzer-Activity
- [ ] SELCAL
- [ ] CPDLC
- [x] Bann-, Kick-, Verwarnungs- und PM-Kommandos mit serverseitiger OP-Hierarchie
- [x] Chat-Spam-Schutz mit hohen Burst-, Minuten- und Wiederholungsschwellen
- [ ] Nachrichtenaufbewahrung und automatische Archivierung definieren

# 8. Voice-System

## Voice-Service

- [x] Eigener Node.js-WebSocket-Service
- [x] Session-Authentifizierung über MySQL
- [x] Frequenzbasiertes Routing
- [x] Positions-/Reichweitenrouting
- [x] Globale UNICOM-Konfiguration
- [x] Admin-Monitor-Berechtigung
- [x] IIS-Reverse-Proxy unter `wss://virtualflightnetwork.com/voice`
- [x] TLS/WSS über die öffentliche HTTPS-Domain
- [x] Lokaler Dienst auf `127.0.0.1:8090`
- [x] Installation als Windows-Dienst über NSSM
- [x] Browser Voice Monitor im Admin Panel
- [x] Ein-/Ausgabegeräte, Pegel, PTT und Dauersenden im Browser
- [x] Verbindung nach F5 wiederherstellen
- [x] Voice-Monitor-Frequenz trennen

## X-Plane-Plugin

- [x] WebSocket-Verbindung zum Voice-Service
- [x] Mikrofonaufnahme und Audioausgabe
- [x] Auswahl von Ein- und Ausgabegeräten
- [x] Push-to-Talk-Kommando und Shortkey-Anzeige
- [x] Link zur X-Plane-Tastaturbelegung
- [x] Dauersenden
- [x] TX-/RX-Anzeige im Hauptfenster
- [x] TX-/RX-Pegelanzeigen in den Einstellungen
- [x] Audioempfang und Wiedergabe
- [x] Voice-Einstellungen dauerhaft speichern
- [x] Spectator-Modus auf reinen Voice-Empfang begrenzen
- [ ] Opus statt unkomprimierter PCM/Base64-Übertragung
- [ ] Jitter-Buffer, Paketverlustbehandlung und adaptive Audiopufferung
- [ ] Echo-Unterdrückung, Noise Gate und automatische Verstärkung
- [ ] Voice-Service-README an den inzwischen implementierten Plugin-Stand anpassen
- [ ] Dienstüberwachung, Logrotation und automatischer Healthcheck
- [ ] Reproduzierbare NSSM-/IIS-Installationsdokumentation
- [ ] Lasttest mit vielen gleichzeitigen Sendern und Empfängern

# 9. X-Plane-Plugin und Build

- [x] Neue benutzerdefinierte Oberfläche
- [x] Hauptfenster mit COM1/COM2 und Transponder
- [x] Separate/dockbare Popout-Fenster
- [x] Login-, Flugplan-, Chat-, ATC-, D-ATIS- und Einstellungsfenster
- [x] Deutsch/Englisch und automatische X-Plane-Spracherkennung
- [x] ATC-Online-Liste
- [x] Unsichtbar-Modus nach erneutem Login wiederherstellen
- [x] Zusatzfenster beim Logout schließen
- [x] X-Plane-SDK im Build eingebunden
- [x] Build-Skript erzeugt die `.xpl`-Datei im Downloadordner
- [x] Downloadpaket auf der Webseite
- [x] Versionsnummer zentral über `VERSION` verwalten und im Plugin/Download anzeigen
- [x] Verbindlicher Plugin-Versionscheck beim Login gegen die OP-Level-5-Konfiguration
- [x] Automatischer Release-Build mit XPL- und ZIP-Erstellung
- [-] Signierte Releases und Prüfsumme für Downloads
  - [x] SHA-256-Prüfsumme automatisch erzeugen
  - [ ] Binäre Codesignatur für die XPL
- [ ] Tests unter mehreren X-Plane-12-Versionen
- [ ] Linux- und macOS-Plugin-Builds
- [x] Spectator-Modus mit deaktiviertem Flugplan, D-ATIS und Voice-Senden
- [x] 30-NM-Spielerliste mit laufender Distanzanzeige
- [x] Spieler aus der Plugin-Liste per Kamera verfolgen
- [x] Kontextaktionen für PM sowie rangabhängige Verwarnung, Kick und Bann
- [x] Wechselnde Multiplayer-Beschriftung mit Callsign, Flugzeugtyp, Route und Entfernung

# 10. Admin Panel und Berechtigungen

- [x] Admin-Zugriff anhand `op_permission`
- [x] Chat-/Frequenzmonitor
- [x] Chat-Suche
- [x] Filter nach Benutzer, Frequenz, Datum/Uhrzeit und Typ
- [x] Staff-Activity-Reiter
- [x] Browser Voice Monitor
- [x] Spielerliste mit Suche und Filtern
- [x] Divisionen direkt aus MySQL
- [x] Verlinkung zum Spielerprofil
- [x] Letzten Admin-Reiter nach F5 wiederherstellen
- [x] Master-TODO als sicher gerenderter OP-Level-5-Reiter im Admin Panel anzeigen
- [x] OP-Level-5-Datenbank-Reset mit Passwort- und Textbestätigung
  - [x] `airports`, `divisions` und `chat_filter_words` erhalten
  - [x] Bootstrap-Account `admin` mit OP-Level 5 für den Wiederzugang neu anlegen
  - [x] Alle Web- und Plugin-Sitzungen zuverlässig ungültig machen
  - [x] Sessions, Chat-Cursor und AUTO_INCREMENT-Werte kontrolliert zurücksetzen
  - [x] Hochgeladene Profilbilder beim Reset vollständig entfernen
- [x] Typisierte OP-Level-5-Konfigurationsverwaltung ohne SQL-Zugangsdaten
  - [x] Separate, DB-Reset-feste Runtime-Override-Datei
  - [x] Allowlist und Validierung für URLs, Zahlen, Boolean, E-Mail und Dateiname
- [-] OP-Level-basiertes Berechtigungssystem
- [x] Benutzer im Admin Panel bearbeiten
- [x] Ratings vergeben und entziehen
- [x] Divisionstransfers genehmigen oder ablehnen
- [x] Benutzer aktivieren/deaktivieren und aktive Plugin-Sitzungen beim Deaktivieren beenden
- [x] Profil-Moderation mit Online-Kick und Pflichtgrund
- [x] Zeitlich begrenzte und permanente Banns
- [x] Entbannung ab OP-Level 4 mit Pflichtgrund
- [x] Entbannungsanträge im Admin Panel mit rotem Benachrichtigungspunkt
- [x] Spieler über die Entscheidung eines Entbannungsantrags per E-Mail informieren
- [x] Verwarnungssystem mit Ablauf, OP-Hierarchie und Activity
- [ ] Rollen und Rechte feiner als nur OP-Level modellieren
- [ ] Vollständiger Audit-Log aller Admin-Aktionen
- [x] Serverseitige Pagination für Chat, Spielerliste und Staff-Activities
- [ ] Exportfunktionen für Staff

# 11. Multiplayer

- [x] Multiplayer-Flugzeuge in X-Plane darstellen
  - [x] XPMP2 3.6.1 statisch in den Windows-x64-Plugin-Build eingebunden
  - [x] Kompatibilitaet mit X-Plane 11.55r2 und X-Plane 12 vorgesehen
  - [x] Bis zu 100 Flugzeuge innerhalb von 30 NM über den Traffic-Endpunkt bereitstellen
  - [x] Grundlegender Laufzeittest mit zwei verbundenen Simulatoren unter X-Plane 11 und 12
  - [x] X-CSL-Modell- und Livery-Test mit zwei verbundenen Simulatoren
  - [x] Fahrwerk, Klappen, Vorflügel, Stör-/Bremsklappen und Steuerflächen übertragen
  - [x] Schub, Schubumkehr, Triebwerks-/Propellerdrehung, Bugradlenkung und Raddrehung übertragen
  - [x] Taxi-, Beacon-, Strobe- und Navigationslichter übertragen
  - [x] Touchdown-Animation und Schwenkflügelzustand übertragen
  - [x] TCAS-Ziele mit Callsign, ICAO-Typ, Squawk und Transponderstatus über XPMP2 bereitstellen
  - [x] Automatische Jet-Kondensstreifen zwischen 25.000 und 45.000 ft einschließlich mehrerer Triebwerksstreifen
  - [x] Dynamische Wake-Turbulence-Daten für X-Plane 12; kompatibler Betrieb ohne Wake-Datenrefs unter X-Plane 11
- [-] Aircraft Visibility
  - [x] Nur aktive Positionen der letzten 10 Sekunden
  - [x] Unsichtbare Staff-Sitzungen bleiben für OP 0 und OP 1 verborgen
  - [x] Staff ab OP-Level 2 sieht nur unsichtbare Spieler mit gleichem oder niedrigerem Rang
  - [x] Option zum Ausblenden erlaubter unsichtbarer Spieler in Karte und Plugin
  - [x] Spectator-Sitzungen nur für Staff ab OP-Level 1 in der Spielerliste anzeigen und nie als Flugzeug erzeugen
  - [ ] Einstellbare Sichtweite und Flugzeuganzahl
- [x] Model Matching
  - [x] Neutrales, leichtgewichtiges VFN-OBJ8-Fallbackmodell
  - [x] XPMP2-Laufzeitressourcen und VFN-Fallbackmodell im Download-ZIP
  - [x] Reale X-CSL-Modellpakete und ICAO-/Airline-/Livery-Matching
    - [x] Vollständiges X-CSL-Paket heruntergeladen
    - [x] Einbindung und Laufzeittest mit zwei Simulatoren
    - [x] Ähnliche ICAO-Typen auf vorhandene CSL-Modelle abbilden, bevor das Fallback verwendet wird
    - [x] Unbekannte Modelle bevorzugt als neutrales B738-CSL statt als blaues VFN-Platzhaltermodell anzeigen
  - [x] Beim Abheben mit Transponder auf STBY/OFF eine rote Chatwarnung ausgeben
  - [x] Bodenfahrzeuge serverseitig auf mindestens ATC-Rang TWR oder Spezialrang Operations Officer beschränken
  - [ ] Model-Matching-Diagnose im Plugin anzeigen
- [-] Netzwerkverkehr und Interpolation
  - [x] Eigener authentifizierter, kompakter Traffic-Endpunkt
  - [x] Asynchroner Poll im Plugin ohne Blockierung des X-Plane-Threads
  - [x] Geglaettete Position, Hoehe und Fluglage
  - [x] Zustandsübertragung für Fahrwerk, Klappen und Beleuchtung
  - [ ] Adaptive Update-Rate und Extrapolation nach Geschwindigkeit
- [-] Umgang mit Paketverlust und veralteten Positionen
  - [x] Fehlgeschlagene Polls behalten vorhandene Flugzeuge
  - [x] Nicht mehr gelieferte Flugzeuge werden nach drei erfolgreichen Polls entfernt
  - [ ] Telemetrie und sichtbare Diagnose fuer Traffic-Verbindungsfehler

## Verbindlicher Zwei-Simulator-Abnahmetest (XP11 + XP12)

### Vorbereitung und Verbindung

- [ ] Auf beiden Simulatoren dieselbe aktuelle VFN-XPL und dasselbe X-CSL-Paket installieren
- [ ] X-Plane 11.55r2 und X-Plane 12.1.3-r2 jeweils einmal als Sender und Empfänger verwenden
- [ ] Gegenseitige Darstellung nach Login sowie Entfernung nach Logout/Verbindungsabbruch prüfen
- [ ] Wechsel des Flugzeugtyps im laufenden Betrieb und erneutes Model-Matching prüfen
- [ ] Passende ICAO-Modelle/Liveries sowie ähnliches Modell und B738-Fallback bei unbekanntem Typ prüfen
- [ ] Ruhige Interpolation beim Rollen, Start, Steigflug, Kurvenflug, Sinkflug und Aufsetzen beobachten
- [ ] Verhalten bei kurzzeitigem API-/Traffic-Ausfall und anschließender Wiederverbindung prüfen

### Sichtbare Flugzeugzustände

- [ ] Fahrwerk vollständig ein-/ausfahren; insbesondere A380/A388 und weitere komplexe Add-ons prüfen
- [ ] Klappen und Vorflügel stufenweise ein-/ausfahren
- [ ] Störklappen, Speedbrakes und Ground Spoiler prüfen
- [ ] Schubumkehr nach der Landung und anschließendes Einfahren prüfen
- [ ] Querruder, Höhenruder und Seitenruder in beide Richtungen prüfen
- [ ] Bugradlenkung, rollende Räder sowie weiterhin drehende Propeller/Triebwerke prüfen
- [ ] Taxi-, Positions-, Beacon- und Strobe-Lichter einzeln schalten und auf dem Gegensimulator prüfen
- [ ] Touchdown-Animation nur beim tatsächlichen Aufsetzen prüfen
- [ ] Schwenkflügel mit einem geeigneten Flugzeugtyp prüfen
- [ ] Sicherstellen, dass das unterdrückte CSL-Landelicht keinen extremen Bloom im Flügel-/Fahrwerksbereich mehr erzeugt

### TCAS, Kondensstreifen und Wake

- [ ] Transponder OFF/STBY: Flugzeug erscheint nicht als aktives TCAS-Ziel
- [ ] Transponder ON: Ziel erscheint mit richtigem Callsign, Flugzeugtyp und Squawk im TCAS
- [ ] Transpondercode und Modus während der Verbindung ändern und Aktualisierung kontrollieren
- [ ] TCAS mit einem weiteren installierten Traffic-Plugin testen; verständliche Meldung bei belegter TCAS-Kontrolle prüfen
- [ ] Jet unter 25.000 ft ohne und zwischen 25.000–45.000 ft mit Kondensstreifen prüfen
- [ ] Mehrstrahliges Flugzeug auf plausible Anzahl/Position der Kondensstreifen prüfen
- [ ] Propellerflugzeug darf keine Jet-Kondensstreifen erhalten
- [ ] Wake-Turbulence hinter Light-, Medium-, Heavy- und Super-Flugzeugen in X-Plane 12 prüfen
- [ ] X-Plane 11 muss trotz fehlender Wake-Datenrefs stabil bleiben

### Spielerliste, Sichtbarkeit und Kamera

- [x] 30-NM-Spielerliste, laufende Distanz, Entfernen außerhalb der Reichweite und Rückkehr aus dem Kamera-Follow geprüft
- [ ] Wechselnde Labels: Callsign, ICAO-Typ, Route und Entfernung prüfen
- [ ] Kamera-Follow starten/stoppen sowie Zoom per Mausrad und Orbit mit rechter Maustaste prüfen
- [ ] Spectator bleibt unsichtbar und darf nur für Staff ab OP-Level 1 in der Liste erscheinen
- [ ] Unsichtbar-Modus und OP-Hierarchie mit OP 0, OP 1, OP 2 und höher prüfen
- [ ] Option „Unsichtbare Spieler ausblenden“ in Plugin und Karte prüfen
- [ ] PM für normale Spieler sowie Verwarnung, Kick und Bann über das Kontextmenü mit Staff-Konto prüfen

### Chat und Sitzungszustand

- [ ] Plugin-zu-Plugin-Chat in beide Richtungen: beim Sender und Empfänger jeweils genau eine Nachricht
- [ ] Frequenzwechsel: keine alten Nachrichten/Awards erneut als neue Nachrichten ausgeben
- [ ] Staff-Nachricht aus dem Webmonitor auf UNICOM und einer anderen Frequenz im Plugin empfangen
- [ ] Globale Announcement-Nachricht im Plugin empfangen
- [ ] Private Nachricht zwischen Plugin und Web-Postfach in beide Richtungen prüfen
- [ ] Umlaute/UTF-8 und Schimpfwortmaskierung im Plugin prüfen
- [ ] Spam-Kick und sofortigen Wechsel zurück zum Loginfenster ohne einminütige Verzögerung prüfen

### Voice

- [ ] Plugin-zu-Plugin-Audio in beide Richtungen auf derselben aktiven COM-Frequenz prüfen
- [ ] COM1/COM2-Umschaltung und getrennte PTT-Zuordnung prüfen
- [ ] PTT, Dauersenden und TX/RX-Pegel auf beiden Geräten prüfen
- [ ] Ohne PTT/Dauersenden darf trotz Mikrofonpegel kein Audio übertragen werden
- [ ] Browser Voice Monitor ↔ Plugin in beide Richtungen prüfen
- [ ] UNICOM global sowie normale Frequenz innerhalb und außerhalb der eingestellten Reichweite prüfen
- [ ] Gleiche Frequenz an zwei weit entfernten Referenzorten muss getrennt funktionieren
- [ ] Kanalbelegung: innerhalb derselben Funkzelle darf nur ein Sender gleichzeitig senden
- [ ] Spectator darf Voice empfangen, aber nicht senden
- [ ] Mehrminütigen Betrieb auf Aussetzer, Verzögerung, Kratzen und erforderliches F5/Neuverbinden prüfen

# 12. Statistiksystem

- [-] Benutzer- und Pilotenstatistiken im Profil
- [-] Flugzeugstatistiken
- [ ] Controllerstatistiken und Controllerstunden
- [x] Flughafenstatistiken mit Bewegungs-Rangliste
- [ ] Divisionsstatistiken
- [x] Netzwerkstatistiken nach Zeitraum, Flügen, Piloten, Distanz und Flugstunden
- [-] Leaderboards
  - [x] Top Airports
  - [x] Top Herkunftsländer nach registrierten Piloten
  - [x] Top Länder nach Bewegungen an Abflug- und Zielflughäfen
  - [x] Top Piloten nach abgeschlossenen Flügen, NM, Flugstunden und Landungen
  - [ ] Top Controller

# 13. Datenbank und SQL

## Produktiv vorhandene Tabellen

- [x] `users`
- [x] `user_sessions`
- [x] `divisions`
- [x] `airports`
- [x] `pilot_positions`
- [x] `pilot_tracks`
- [x] `pilot_aircraft_stats`
- [x] `pilot_landings`
- [x] `pilot_flightplans`
- [x] `chat_messages`
- [x] `controller_atis`
- [x] `user_activity_log`
- [x] `user_awards`
- [x] `user_night_flights`
- [x] `user_visited_countries`
- [x] `password_reset_tokens`
- [x] `ban_appeal_requests`
- [x] `division_transfer_requests`
- [x] `chat_filter_words`
- [x] `chat_spam_events`
- [x] `user_warnings`
- [x] `pilot_flights`
- [x] `users.preferred_language`
- [x] `web_notification_state`
- [x] `system_job_status`

## Offene SQL-Arbeiten

- [ ] Dringend: vollständiges versioniertes Basisschema ins Repository aufnehmen
- [x] Migrationsordner mit fortlaufenden SQL-Migrationen eingeführt
- [ ] Laufzeit-`CREATE TABLE` aus PHP in Migrationen überführen
- [ ] Fremdschlüssel und Löschregeln aller Tabellen prüfen/ergänzen
- [ ] Indizes für Chatfilter, Sessions, Positionen, Activities und Spielerfilter prüfen
- [ ] Eindeutige Constraints für Tokens, Callsigns und fachliche Schlüssel prüfen
- [ ] Zeichensatz und Kollation einheitlich auf `utf8mb4` festlegen
- [ ] Automatisierte Backups und getesteten Restore-Prozess dokumentieren
- [ ] Aufbewahrungsfristen für Tracks, Chat, Sessions und Reset-Tokens festlegen
- [ ] Datenbankzugriff des Voice-Service auf minimal notwendige Leserechte begrenzen

# 14. Sicherheit und Betrieb

- [x] HTTPS für die Webseite
- [x] WSS für Voice
- [x] OP-Level-5-Wartungsmodus für Website und Plugin
- [x] Registrierungen im Wartungsmodus automatisch sperren
- [x] Registrierung unabhängig über die OP-Level-5-Konfiguration aktivieren/deaktivieren
- [x] Bestehende Web- und Plugin-Sitzungen während der Wartung für niedrigere OP-Level beenden
- [x] Passwort-Hashes
- [x] Gehashte Passwort-Reset-Tokens
- [x] Serverseitige Admin-Authentifizierung
- [-] API-Authentifizierung über Sessions/Tokens
- [ ] Datenbank-Zugangsdaten aus PHP-Dateien in sichere Umgebungsvariablen verschieben
- [ ] Voice-Service-Secrets ausschließlich über geschützte Umgebungsvariablen
- [-] CSRF-Schutz für Webformulare und Admin-Aktionen
  - [x] Login, Registrierung, 2FA, Profil, Moderation, Flugpläne und Admin-Aktionen geschützt
  - [ ] Zustandsändernde Logout-Anfrage von GET auf POST umstellen
- [ ] Einheitliche Eingabevalidierung und API-Fehlerformate
- [-] Rate Limiting
  - [x] Web-Login
  - [x] Passwort-Recovery
  - [x] Registrierung nach IP und Benutzerkennung
  - [x] Plugin-Chat mit Burst-, Minuten- und Wiederholungsschutz
  - [ ] Weitere API-Endpunkte
- [x] Security Header und deaktiviertes Directory Browsing in IIS/web.config
- [ ] Produktionsfehler protokollieren, ohne interne Details auszugeben
- [-] Zentrales Monitoring für IIS, PHP, MySQL und Voice-Service
  - [x] OP-5-Systemzustandsseite für Datenbank, Voice-Port, Plugin-API, Datenbestände und Release
  - [x] Statusspeicher für Daten- und Hintergrundjobs
  - [ ] IIS-Worker-, Zertifikatsablauf- und Windows-Dienststatus direkt auslesen
- [ ] Alarmierung bei Ausfall von Webseite, Datenbank oder Voice
- [ ] Abhängigkeiten regelmäßig auf Sicherheitsupdates prüfen
- [ ] Test-/Debug-Dateien aus der Produktionswebseite entfernen

# 15. Dokumentation und Qualität

- [x] Voice-Service-README vorhanden
- [x] Lokales Build-Skript vorhanden
- [x] Git/GitHub-Repository verbunden
- [ ] Haupt-README mit Architektur, Installation und Entwicklungsstart
- [ ] Installationsanleitung für IIS, PHP, MySQL, Node.js, NSSM und X-Plane-SDK
- [ ] API-Dokumentation
- [ ] Datenbankschema/ER-Diagramm
- [ ] Automatisierte PHP-, JavaScript-, Node- und C++-Tests
- [ ] CI-Build und Syntaxprüfungen über GitHub Actions
- [ ] Staging-Umgebung vor Änderungen an Produktion
- [ ] Changelog und Release-Prozess
- [x] Startseite um Voice, Chat, Flugbuch, Flugpläne, D-ATIS, Multiplayer/TCAS, Statistiken und Divisionen erweitert

# 16. Zukunft

- [ ] Eventsystem und Division-Events
- [ ] Badges/Karrieresystem
- [ ] VFN Launcher
- [ ] Mobile App
- [ ] Öffentliche Statusseite
