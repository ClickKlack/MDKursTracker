# ROADMAP.md – marego Kursnummer-Erfassungs-App

> Version: 1.0 | Stand: 2026-03-24

Jede Phase ist eigenständig deploybar und testbar, bevor die nächste beginnt.
KI-gestützte Implementierung sollte phasenweise erfolgen – pro Phase eine
separate Sitzung mit klarem Kontext aus SPEC.md, ARCHITECTURE.md und API.md.

---

## Phase 0 – Infrastruktur & Voraussetzungen

**Ziel:** Funktionsfähige Basis auf der lokalen Entwicklungsumgebung, bevor eine Zeile
Anwendungscode geschrieben wird.

- [x] Code-Verwaltung herstellen: Codeberg (git@codeberg.org:ClickKlack/JSKursTracker.git)
- [x] GIT-Verbindung zu Codeberg herstellen (ed25519-Schlüssel, SSH-Config)
- [x] wichtige Standarddokumente für Codeberg erstellen (README.md, LICENSE)
- [x] Umgebung prüfen: PHP 8.5.4, curl ✓, pdo_mysql ✓, MariaDB 10.4.28 (XAMPP)
- [x] Datenbankbenutzer anlegen (minimale Rechte: SELECT, INSERT, UPDATE,
      DELETE auf eigene DB) → `marego_user`@localhost
- [x] `DATABASE.sql` einspielen → alle 6 Tabellen angelegt
- [x] Konfigurationsdatei `config.php` außerhalb Webroot anlegen
      (DB-Zugangsdaten + Admin-Passwort-Hash); `config.php.example` im Repo
- [x] Verzeichnisstruktur gemäß `ARCHITECTURE.md` anlegen
- [x] `.htaccess` einrichten: API-Routing, Directory-Listing deaktivieren,
      `admin-api/` nur über Session erreichbar
- [x] INSA HAFAS-API Testabfrage erfolgreich:
      URL `https://reiseauskunft.insa.de/bin/mgate.exe`,
      AID `nasa-apps`, client `{ type: IPH, id: NASA, v: 4000200, name: nasaPROD }`, ver `1.44`
      (Quelle: hafas-client, verifiziert durch Testabfrage)

**Abnahmekriterium:** `DATABASE.sql` ist eingespielt, HAFAS-Testabfrage
liefert Daten, Konfigurationsdatei liegt außerhalb Webroot.

---

## Phase 1 – PHP-Backend: Fundament

**Ziel:** Alle Backend-Bibliotheken und die DB-Verbindung stehen.
Noch kein Frontend.

- [ ] `lib/response.php` – JSON-Ausgabe-Helfer mit korrekten Headern
- [ ] `lib/db.php` – PDO-Verbindung + Auto-Init der initialen Periode
- [ ] `lib/hafas.php` – curl-Wrapper für INSA HAFAS
      (Funktionen: nearby, departures, trip)
- [ ] `lib/calendar.php` – Wochentagstyp-Berechnung:
      gesetzliche Feiertage ST (statische Liste) + Schulferien aus DB
- [ ] `lib/auth.php` – Session-Prüfung, HTTP 401 bei Fehler
- [ ] `public/api/index.php` – URL-Router (leitet auf Handler-Dateien)

**Testen:** PHP-Unit-Tests oder manuelle curl-Aufrufe gegen die lib-Funktionen.

**Abnahmekriterium:** `lib/calendar.php` gibt für Feiertage `FT`, für
Werktage `MO-FR` zurück. `lib/db.php` legt initiale Periode an wenn DB leer.

---

## Phase 2 – PHP-Backend: HAFAS-Proxy-Endpunkte

**Ziel:** Die drei HAFAS-Proxy-Endpunkte liefern gefilterte Daten.

- [ ] `GET /api/nearby` – Haltestellen in der Nähe (Filter: nur tram)
- [ ] `GET /api/departures` – Abfahrten inkl. `activeCourseNumber` aus DB
- [ ] `GET /api/trip` – Laufweg-Halte normalisiert
- [ ] `GET /api/calendar` – Wochentagstyp für Datum

**Testen:** curl-Aufrufe mit echten Magdeburger Koordinaten und
Haltestellen-IDs. Prüfen ob Tram-Filter greift.

**Abnahmekriterium:** `/api/departures` liefert nur Straßenbahnen,
`activeCourseNumber` ist `null` (noch keine Erfassungen).

---

## Phase 3 – PHP-Backend: Erfassungs-Endpunkte

**Ziel:** Kursnummern können gespeichert und abgerufen werden.

- [ ] `POST /api/recordings` – vollständige Speicherlogik:
      day_type berechnen → trip anlegen (INSERT IGNORE) →
      stop anlegen (INSERT IGNORE) → recording anlegen →
      trip abrufen → route_stops anlegen
- [ ] `GET /api/recordings` – mit allen Filterparametern
- [ ] `GET /api/trips` – inkl. Mehrheitsregel-Berechnung per SQL
- [ ] `GET /api/periods` – mit `recordingCount` und `active`-Flag

**Testen:** Vollständiger Erfassungsdurchlauf per curl. Prüfen ob
Laufweg korrekt gespeichert wird. Mehrheitsregel mit 2+ Erfassungen testen.

**Abnahmekriterium:** Nach POST erscheint Eintrag in GET /api/recordings
und GET /api/departures zeigt `activeCourseNumber`.

---

## Phase 4 – PHP-Backend: Admin-API

**Ziel:** Alle Admin-Funktionen sind über die API erreichbar.

- [ ] `POST /admin/login` + `POST /admin/logout` mit PHP-Session
- [ ] `GET/POST/PUT/DELETE /admin/school-holidays`
- [ ] `PUT/DELETE /admin/trips/:id/override`
- [ ] `POST /admin/periods` – Fahrplanschnitt
- [ ] `PUT /admin/periods/:id` – Periode umbenennen/korrigieren

**Testen:** Login/Logout-Flow. Ohne Session: 401 prüfen.
Fahrplanschnitt: neue Periode anlegen, prüfen dass alte Erfassungen
erhalten bleiben und neue Erfassungen in neuer Periode landen.

**Abnahmekriterium:** Alle Admin-Endpunkte ohne Session liefern 401.
Fahrplanschnitt-Endpunkt legt neue Periode an.

---

## Phase 5 – Frontend: Grundstruktur & PWA-Shell

**Ziel:** Die App ist im Browser aufrufbar und auf dem Android-Homescreen
installierbar. Noch keine echten Daten.

- [ ] `index.html` – semantisches Grundgerüst, `<main>` als View-Container
- [ ] `manifest.json` – Name, Icons (mind. 192×192 und 512×512), theme-color,
      `display: standalone`, `start_url`
- [ ] `sw.js` – App-Shell cachen (HTML, CSS, JS, Manifest)
- [ ] `css/app.css` – Basis-Layout, mobile-first, lesbar auf kleinem Bildschirm
- [ ] `js/app.js` – Hash-Router, globaler Perioden-State, View-Lifecycle
- [ ] `js/api.js` – fetch()-Wrapper für alle Backend-Aufrufe

**Testen:** Chrome DevTools → Application → Manifest prüfen.
„Zum Homescreen hinzufügen" auf Android testen.

**Abnahmekriterium:** App ist installierbar, Service Worker aktiv,
App-Shell lädt offline.

---

## Phase 6 – Frontend: Haltestellen & Abfahrten

**Ziel:** Nutzer sieht Haltestellen in der Nähe und Abfahrten.

- [ ] `js/utils/geolocation.js` – GPS-Wrapper mit Fehlerbehandlung
- [ ] `js/views/nearby.js` – Liste nahegelegener Haltestellen,
      sortiert nach Entfernung, Tap → Abfahrten
- [ ] `js/views/departures.js` – Abfahrtstafel mit Soll/Ist,
      bekannte Kursnummer farblich hervorgehoben
- [ ] `js/utils/format.js` – Zeitformatierung (Europe/Berlin),
      zweistellige Kursnummer-Darstellung

**Testen:** Auf mobilem Chrome mit aktivem GPS testen.
Prüfen ob Tram-Filter greift (keine Busse in der Liste).

**Abnahmekriterium:** Haltestellen erscheinen sortiert nach GPS-Entfernung.
Abfahrten zeigen Soll und Ist korrekt in Berliner Zeit.

---

## Phase 7 – Frontend: Kursnummer erfassen

**Ziel:** Kernfunktion der App: Kursnummer einer Abfahrt zuordnen.

- [ ] `js/views/capture.js` – Erfassungsview:
      18 Schnell-Buttons (01–18), Freitextfeld (01–99),
      Validierung, Bestätigungs-Feedback
- [ ] Nach erfolgreicher Erfassung: Rückkehr zur Abfahrtstafel,
      `activeCourseNumber` der betreffenden Zeile sofort aktualisieren

**Testen:** Erfassung mit Schnellbutton + Freitextfeld. Ungültige Eingaben
abfangen (00, 100, Buchstaben). Prüfen ob Laufweg in DB gespeichert wird.

**Abnahmekriterium:** Nach Erfassung zeigt Abfahrtstafel die Kursnummer
farblich hervorgehoben. DB-Tabelle `route_stops` enthält alle Halte.

---

## Phase 8 – Frontend: Erfassungen einsehen

**Ziel:** Übersicht aller Erfassungen mit Filter und Periodenumschalter.

- [ ] `js/views/history.js` – Liste aller Erfassungen,
      Filtermöglichkeiten (Linie, Wochentagstyp, Datum),
      Anzeige ob Kursnummer manuell oder per Mehrheit
- [ ] Periodenumschalter: Dropdown mit allen Perioden,
      ältere Perioden als read-only kennzeichnen

**Abnahmekriterium:** Filter funktionieren kombiniert.
Periodenumschalter zeigt Altdaten korrekt an.

---

## Phase 9 – Admin-Frontend

**Ziel:** Vollständiges Admin-Interface für Betreiber.

- [ ] `admin/index.html` – Login-Formular + Dashboard-Shell
- [ ] `admin/js/admin.js` – Login/Logout-Flow, Session-State
- [ ] `admin/js/school_holidays.js` – Tabelle mit CRUD (anlegen, bearbeiten,
      löschen), Datumsvalidierung
- [ ] `admin/js/periods.js` – Perioden-Übersicht, Fahrplanschnitt-Button
      mit Bestätigungsdialog und Warntext, initiale Periode umbenennen
- [ ] `admin/js/override.js` – Suche nach Fahrt, Kursnummer manuell
      setzen oder zurücksetzen

**Testen:** Fahrplanschnitt durchführen, prüfen dass Erfassungen in
alter Periode bleiben. Override setzen und in Abfahrtstafel prüfen.

**Abnahmekriterium:** Fahrplanschnitt funktioniert vollständig.
Ohne Login kein Zugriff auf Admin-Funktionen.

---

## Phase 10 – Abschluss & Härtung

**Ziel:** Produktionsreife.

- [ ] Eingabevalidierung nochmals durchgehen (alle POST/PUT-Endpunkte)
- [ ] Rate-Limiting prüfen (HAFAS-API: max. 100 req/min beachten)
- [ ] `.htaccess`: `admin-api/` für Direktzugriff ohne PHP sperren
- [ ] HTTPS erzwingen (Redirect HTTP → HTTPS)
- [ ] PWA auf verschiedenen Android-Geräten / Chrome-Versionen testen
- [ ] Ladezeiten prüfen (HAFAS-Proxy-Latenz, DB-Abfragen)
- [ ] Backup-Konzept für MariaDB klären (Hoster-seitig oder eigenes Skript)
- [ ] INSA HAFAS Nutzungsrechte abschließend bestätigt

**Abnahmekriterium:** App läuft stabil im Produktivbetrieb. HTTPS aktiv.
Kein öffentlicher Zugriff auf `config.php` möglich.

---

## Phase 11 - GoLive

**Ziel:** Produktivsetzung auf dem Webhosting

- [ ] Webhosting-Umgebung prüfen: PHP ≥ 8.0, curl-Extension, MariaDB-Version
- [ ] deploy-Skript erstellen (übertragung per SSH, Schritt für Schritt inkl. Schlüsselerzeugung beim Hoster)
- [ ] Datenbank bereitstellen
- [ ] initiale Verzeichnisstruktur und nicht kopierte Dateien mit korrekten Paramtern (produktive Server, Passwörter) kopieren
- [ ] Deplayment ausführen
- [ ] Testen
- [ ] Progressive Web-App herstellen
- [ ] README aktualisieren

**Abnahmekriterium:** App läuft stabil im Produktivbetrieb. HTTPS aktiv.
Kein öffentlicher Zugriff auf `config.php` möglich.

---

## Reihenfolge für KI-gestützte Implementierung

Jede Phase als eigene Sitzung starten. Kontext am Anfang jeder Sitzung
mitgeben:

```
Lies SPEC.md, ARCHITECTURE.md, API.md und DATABASE.sql.
Wir implementieren jetzt Phase X: [Phasenbeschreibung].
```

Phasen 1–4 (Backend) vollständig abschließen und testen,
bevor Phase 5 (Frontend) beginnt. Das Backend ist die stabile Grundlage
für alle Frontend-Arbeiten.
