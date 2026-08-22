# VFN-Hoppie-CPDLC-Gateway

VFN nutzt Hoppies ACARS-Netzwerk, um CPDLC-Nachrichten zwischen kompatiblen FMC/MCDU-Systemen in Flugzeugen und dem VFN-ATC-Client auszutauschen. Piloten benötigen dafür kein separates VFN-CPDLC-Fenster.

## Server einrichten (OP-Level 5)

1. Einen Hoppie-Netzwerkcode unter <https://www.hoppie.nl/acars/system/register.html> beantragen.
2. **Administrator > Einstellungen > CPDLC / Hoppie** öffnen.
3. Den Hoppie-Netzwerkcode eintragen.
4. Als Verbindungsadresse `https://www.hoppie.nl/acars/system/connect.html` beibehalten.
5. Ein Abfrageintervall zwischen 45 und 75 Sekunden wählen und das Gateway aktivieren.
6. Die Einstellungen speichern. Das Geheimnis wird in der von Git ausgeschlossenen Datei `runtime-secrets.json` gespeichert und im Einstellungsformular maskiert.
7. Eine aktive ATC-Position öffnen. Im CPDLC-Fenster erscheint `HOPPIE READY` oder der zuletzt aufgetretene Gateway-Fehler.

## Einrichtung und Ablauf für Piloten

1. Im Hoppie-/ACARS-System des Flugzeugs den persönlichen Hoppie-Netzwerkcode des Piloten eintragen. Der genaue Ablauf hängt vom Flugzeug ab.
2. Im FMC/MCDU die vierstellige VFN-ATC-Station eingeben, beispielsweise `EDDP` oder `EDMM`.
3. Eine CPDLC-Anmeldung anfordern.
4. Die Anfrage erscheint im CPDLC-Fenster des zuständigen VFN-ATC-Clients. Der Lotse kann sie annehmen oder ablehnen.
5. Nachrichten des Lotsen erscheinen im FMC/MCDU. Antworten aus dem Flugzeug erscheinen im Nachrichtenverlauf des VFN-ATC-Clients.
6. Die CPDLC-Verbindung abmelden, sobald sie nicht mehr benötigt wird.

Interne Sektorkennungen werden einer vierstelligen Hoppie-Station zugeordnet. Beispielsweise verwendet `EDMM_MEI` bei Hoppie die Station `EDMM`.

## Zustellungsdauer

Hoppie arbeitet nach dem Store-and-forward-Prinzip. Eine Nachricht kann daher ungefähr ein Abfrageintervall benötigen. Falls CPDLC nicht verfügbar ist, bleibt Voice die Rückfallebene.

## X-Plane-Plugin

Das Flugzeug kommuniziert direkt mit Hoppie. Für dieses Gateway wird keine neue VFN-XPL-Datei benötigt. Im Plugin wird auch kein zweites CPDLC-Pilotenfenster eingebaut.

## Checkliste für den Live-Test

- Der Administrationsstatus zeigt das Gateway als aktiviert an.
- Eine aktive Lotsenposition zeigt `HOPPIE READY` an.
- Das Flugzeug kann eine Anmeldung an der angezeigten vierstelligen Station anfordern.
- Der Lotse kann eine Anmeldung annehmen und ablehnen.
- Eine Nachricht des Lotsen kommt im FMC/MCDU an.
- Eine Antwort des Flugzeugs erscheint im VFN-ATC-Nachrichtenverlauf.
- Die Abmeldung funktioniert auf beiden Seiten.
- Der private Hoppie-Netzwerkcode erscheint weder in Protokollen noch in Screenshots, der Versionsverwaltung oder Bug-Reports.

## Aufnahme in Hoppies Softwareliste beantragen

Nach einem erfolgreichen Live-Test kann eine E-Mail an `hoppie@hoppie.nl` gesendet werden. Sie sollte Produktname, Website, Plattform, Protokoll, eine kurze Beschreibung der Integration, eine Kontaktadresse sowie möglichst einen Screenshot und die Bestätigung eines erfolgreichen Tests zwischen Flugzeug und Lotse enthalten.

Empfohlener Betreff: `Software list request - Virtual Flight Network ATC Radar Client`

Der private Hoppie-Netzwerkcode darf nicht in dieser E-Mail stehen.
