# Virtual Flight Network (VFN) – Master-TODO

Letzte Prüfung: 25.07.2026

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
- [-] Vollständige Benutzer-Selbstverwaltung
  - [ ] Benutzername ändern
  - [ ] Realname ändern
  - [ ] Land ändern
  - [ ] Divisionstransfer beantragen
  - [ ] Passwort im eingeloggten Profil ändern
- [ ] Schutz gegen wiederholte Login- und Reset-Anfragen (Rate Limiting)
- [ ] Optional: Zwei-Faktor-Authentifizierung für Staff-Konten

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
- [ ] Eigene Pilot-Unterseite mit Flugbuch und Flughistorie
- [ ] Eigene ATC-Unterseite
- [ ] Profil-Einstellungen
- [ ] Private „Administrative History“ für den Profilbesitzer
- [ ] Staff-Ansicht für Warnungen, Banns, Trainings- und Prüfungsverlauf

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
- [-] Staff-Aktivitäten im Admin Panel
- [ ] Passwortänderung protokollieren
- [ ] Benutzername, Realname, Land und Division ändern/protokollieren
- [ ] Ersten Flug und abgeschlossenen Flug vollständig protokollieren
- [ ] Erste, 10. und 100. Landung protokollieren
- [ ] Prüfung bestanden/nicht bestanden protokollieren
- [ ] Training begonnen/abgeschlossen protokollieren
- [ ] Rating vergeben/entziehen mit Staff-Aktion
- [ ] Verwarnungen vergeben/entfernen
- [ ] Banns vergeben/aufheben
- [ ] Staff-Rolle vergeben/entziehen
- [ ] Interne Staff-, Trainings- und Prüfungsnotizen
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
- [-] Automatische Bereinigung veralteter Positionen und Tracks
- [ ] Eigene Airport-Traffic-Seiten
- [ ] Flughafenstatistiken
- [ ] Erweiterte Flugverlaufsanalyse
- [ ] Heatmaps
- [ ] Langfristiges Flugbuch statt nur aggregierter Statistiken

# 6. Flugpläne und D-ATIS

- [x] Flugplanfenster im Plugin
- [x] Textflugplan an Backend senden und in `pilot_flightplans` speichern
- [x] Flugplandaten in der Karte/API bereitstellen
- [x] D-ATIS-Fenster im Plugin
- [x] Automatisches METAR-D-ATIS
- [x] Controller-D-ATIS über `controller_atis`
- [ ] Flugplanbearbeitung und Historie auf der Webseite
- [ ] ATC-seitige Flugplanannahme/-änderung
- [ ] Voice Flightplans

# 7. Chat und Kommunikation

- [x] Frequenzbasierter Netzwerk-Chat
- [x] Plugin-Chatfenster und Nachrichtenton
- [x] Private Nachrichten (`/msg`)
- [x] Spielerliste (`/list`)
- [x] Spielerprofil öffnen (`/playerinfo`)
- [x] Staff-Chat (`/staff`)
- [x] Netzwerk-Announcements (`/announcement`)
- [x] Kick-Kommando und Plugin-Kick-Hinweis
- [x] Chat-Polling und Nachrichtenpersistenz
- [ ] SELCAL
- [ ] CPDLC
- [ ] Vollständiges Bann- und Verwarnungs-Kommando
- [ ] Chat-Spam-Schutz und serverseitiges Rate Limiting
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
- [ ] Versionsnummer zentral verwalten und im Plugin/Download anzeigen
- [ ] Automatischer Release-Build mit ZIP-Erstellung
- [ ] Signierte Releases und Prüfsumme für Downloads
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
- [-] OP-Level-basiertes Berechtigungssystem
- [ ] Benutzer im Admin Panel bearbeiten
- [ ] Ratings vergeben und entziehen
- [ ] Division ändern/Transfer genehmigen
- [ ] Benutzer aktivieren/deaktivieren
- [ ] Bann und Entbannung
- [ ] Verwarnungssystem
- [ ] Rollen und Rechte feiner als nur OP-Level modellieren
- [ ] Vollständiger Audit-Log aller Admin-Aktionen
- [ ] Pagination für Chat und Spielerliste statt fester Limits
- [ ] Exportfunktionen für Staff

# 11. Multiplayer

- [ ] Multiplayer-Flugzeuge in X-Plane darstellen
- [ ] Aircraft Visibility
- [ ] Model Matching
- [ ] Netzwerkverkehr und Interpolation
- [ ] Umgang mit Paketverlust und veralteten Positionen

# 12. Statistiksystem

- [-] Benutzer- und Pilotenstatistiken im Profil
- [-] Flugzeugstatistiken
- [ ] Controllerstatistiken und Controllerstunden
- [ ] Flughafenstatistiken
- [ ] Divisionsstatistiken
- [ ] Netzwerkstatistiken
- [ ] Leaderboards

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

## Offene SQL-Arbeiten

- [ ] Dringend: vollständiges versioniertes Basisschema ins Repository aufnehmen
- [ ] Dringend: Migrationsordner mit fortlaufenden SQL-Migrationen einführen
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
- [x] Passwort-Hashes
- [x] Gehashte Passwort-Reset-Tokens
- [x] Serverseitige Admin-Authentifizierung
- [-] API-Authentifizierung über Sessions/Tokens
- [ ] Datenbank-Zugangsdaten aus PHP-Dateien in sichere Umgebungsvariablen verschieben
- [ ] Voice-Service-Secrets ausschließlich über geschützte Umgebungsvariablen
- [ ] CSRF-Schutz für alle Webformulare und Admin-Aktionen prüfen
- [ ] Einheitliche Eingabevalidierung und API-Fehlerformate
- [ ] Rate Limiting für Login, Registrierung, Chat und API
- [ ] Security Header in IIS/web.config vervollständigen
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

# 16. Zukunft

- [ ] Eventsystem und Division-Events
- [ ] Badges/Karrieresystem
- [ ] VFN Launcher
- [ ] Mobile App
- [ ] Öffentliche Statusseite
