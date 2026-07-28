# Virtual Flight Network (VFN) – Master-TODO

Letzte Prüfung: 27.07.2026

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
- [ ] Eigene ATC-Unterseite
- [x] Profil-Einstellungen für das eigene Profil
- [x] Private „Administrative History“ für Staff ab OP-Level 4
- [x] Staff-Ansicht für Warnungen, Banns, Trainings- und Prüfungsverlauf

# 3. Activity-System

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
  - [ ] Flugbeginn und Flugabschluss zusätzlich als Activity-Einträge erfassen
- [ ] Erste, 10. und 100. Landung protokollieren
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
- [x] Live-Follow-Modus für ein ausgewähltes Flugzeug
- [x] Direkter Sprung vom Spielerprofil zur aktuellen Kartenposition
- [x] Klickbare Abflug- und Zielflughäfen mit linkem Trafficpanel
- [x] Direkt verlinkbare Kartenansicht über stabile Benutzer-ID
- [-] Automatische Bereinigung veralteter Positionen und Tracks
- [ ] Eigene Airport-Traffic-Seiten
- [x] Netzwerkstatistikseite mit Top Airports, Piloten-Herkunftsländern und Movement-Ländern
- [ ] Erweiterte Flugverlaufsanalyse
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
- [ ] Vollständiges Bann- und Verwarnungs-Kommando
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
- [x] OP-Level-5-Datenbank-Reset mit Passwort- und Textbestätigung
  - [x] `airports`, `divisions` und `chat_filter_words` erhalten
  - [x] Bootstrap-Account `admin` mit OP-Level 5 für den Wiederzugang neu anlegen
  - [x] Alle Web- und Plugin-Sitzungen zuverlässig ungültig machen
  - [x] Sessions, Chat-Cursor und AUTO_INCREMENT-Werte kontrolliert zurücksetzen
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

- [-] Multiplayer-Flugzeuge in X-Plane darstellen
  - [x] XPMP2 3.6.1 statisch in den Windows-x64-Plugin-Build eingebunden
  - [x] Kompatibilitaet mit X-Plane 11.55r2 und X-Plane 12 vorgesehen
  - [x] Erste Stufe: maximal 10 Flugzeuge innerhalb von 50 NM
  - [ ] Laufzeittest mit zwei verbundenen Simulatoren
- [-] Aircraft Visibility
  - [x] Nur aktive Positionen der letzten 10 Sekunden
  - [x] Unsichtbare Staff-Sitzungen werden nicht ausgeliefert
  - [ ] Einstellbare Sichtweite und Flugzeuganzahl
- [-] Model Matching
  - [x] Neutrales, leichtgewichtiges VFN-OBJ8-Fallbackmodell
  - [x] XPMP2-Ressourcen und CSL-Paket im Download-ZIP
  - [ ] Reale CSL-Modellpakete und ICAO-/Airline-/Livery-Matching
- [-] Netzwerkverkehr und Interpolation
  - [x] Eigener authentifizierter, kompakter Traffic-Endpunkt
  - [x] Asynchroner Poll im Plugin ohne Blockierung des X-Plane-Threads
  - [x] Geglaettete Position, Hoehe und Fluglage
  - [ ] Adaptive Update-Rate und Extrapolation nach Geschwindigkeit
- [-] Umgang mit Paketverlust und veralteten Positionen
  - [x] Fehlgeschlagene Polls behalten vorhandene Flugzeuge
  - [x] Nicht mehr gelieferte Flugzeuge werden nach drei erfolgreichen Polls entfernt
  - [ ] Telemetrie und sichtbare Diagnose fuer Traffic-Verbindungsfehler

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
  - [ ] Chat und weitere API-Endpunkte
- [x] Security Header und deaktiviertes Directory Browsing in IIS/web.config
- [ ] Produktionsfehler protokollieren, ohne interne Details auszugeben
- [ ] Zentrales Monitoring für IIS, PHP, MySQL und Voice-Service
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
- [x] Startseite um Voice, Chat, Flugbuch, Flugpläne und D-ATIS erweitert

# 16. Zukunft

- [ ] Eventsystem und Division-Events
- [ ] Badges/Karrieresystem
- [ ] VFN Launcher
- [ ] Mobile App
- [ ] Öffentliche Statusseite
