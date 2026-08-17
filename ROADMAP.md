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

- [x] `lib/logger.php` – Monolog, RotatingFileHandler, 14 Tage
- [x] `lib/response.php` – JSON-Ausgabe-Helfer (json_response, json_error)
- [x] `lib/db.php` – PDO-Verbindung + Auto-Init der initialen Periode;
      Socket-Unterstützung für XAMPP-Entwicklungsumgebung
- [x] `lib/hafas.php` – curl-Wrapper für INSA HAFAS
      (hafas_nearby, hafas_departures, hafas_trip; Tram-Filter, ISO-UTC)
- [x] `lib/calendar.php` – Wochentagstyp-Berechnung:
      Gaußsche Osterformel, Feiertage Sachsen-Anhalt, Schulferien aus DB
- [x] `lib/auth.php` – Session-Prüfung + Login/Logout-Helfer
- [x] `public/api/index.php` – URL-Router (leitet auf Handler-Dateien)

**Testen:** PHP-Unit-Tests oder manuelle curl-Aufrufe gegen die lib-Funktionen.

**Abnahmekriterium:** `lib/calendar.php` gibt für Feiertage `FT`, für
Werktage `MO-FR` zurück. `lib/db.php` legt initiale Periode an wenn DB leer.

---

## Phase 2 – PHP-Backend: HAFAS-Proxy-Endpunkte

**Ziel:** Die drei HAFAS-Proxy-Endpunkte liefern gefilterte Daten.

- [x] `GET /api/nearby` – Haltestellen in der Nähe (Filter: nur tram)
- [x] `GET /api/departures` – Abfahrten inkl. `activeCourseNumber` aus DB
- [x] `GET /api/trip` – Laufweg-Halte normalisiert
- [x] `GET /api/calendar` – Wochentagstyp für Datum

**Testen:** curl-Aufrufe mit echten Magdeburger Koordinaten und
Haltestellen-IDs. Prüfen ob Tram-Filter greift.

**Abnahmekriterium:** `/api/departures` liefert nur Straßenbahnen,
`activeCourseNumber` ist `null` (noch keine Erfassungen).

---

## Phase 3 – PHP-Backend: Erfassungs-Endpunkte

**Ziel:** Kursnummern können gespeichert und abgerufen werden.

- [x] `POST /api/recordings` – vollständige Speicherlogik:
      day_type berechnen → trip anlegen (INSERT IGNORE) →
      stop anlegen (INSERT IGNORE) → recording anlegen →
      trip abrufen → route_stops anlegen
- [x] `GET /api/recordings` – mit allen Filterparametern
- [x] `GET /api/trips` – inkl. Mehrheitsregel-Berechnung per SQL
- [x] `GET /api/periods` – mit `recordingCount` und `active`-Flag

**Testen:** Vollständiger Erfassungsdurchlauf per curl. Prüfen ob
Laufweg korrekt gespeichert wird. Mehrheitsregel mit 2+ Erfassungen testen.

**Abnahmekriterium:** Nach POST erscheint Eintrag in GET /api/recordings
und GET /api/departures zeigt `activeCourseNumber`.

---

## Phase 4 – PHP-Backend: Admin-API

**Ziel:** Alle Admin-Funktionen sind über die API erreichbar.

- [x] `POST /admin-api/login` + `POST /admin-api/logout` mit PHP-Session
- [x] `GET/POST/PUT/DELETE /admin-api/school-holidays`
- [x] `PUT/DELETE /admin-api/trips/:id/override`
- [x] `POST /admin-api/periods` – Fahrplanschnitt
- [x] `PUT /admin-api/periods/:id` – Periode umbenennen/korrigieren

**Testen:** Login/Logout-Flow. Ohne Session: 401 prüfen.
Fahrplanschnitt: neue Periode anlegen, prüfen dass alte Erfassungen
erhalten bleiben und neue Erfassungen in neuer Periode landen.

**Abnahmekriterium:** Alle Admin-Endpunkte ohne Session liefern 401.
Fahrplanschnitt-Endpunkt legt neue Periode an.

---

## Phase 5 – Frontend: Grundstruktur & PWA-Shell

**Ziel:** Die App ist im Browser aufrufbar und auf dem Android-Homescreen
installierbar. Noch keine echten Daten.

- [x] `index.html` – semantisches Grundgerüst, `<main>` als View-Container
- [x] `manifest.json` – Name, Icons (mind. 192×192 und 512×512), theme-color,
      `display: standalone`, `start_url`
- [x] `sw.js` – App-Shell cachen (HTML, CSS, JS, Manifest)
- [x] `css/app.css` – Basis-Layout, mobile-first, lesbar auf kleinem Bildschirm
- [x] `js/app.js` – Hash-Router, globaler Perioden-State, View-Lifecycle
- [x] `js/api.js` – fetch()-Wrapper für alle Backend-Aufrufe
- [x] `icons/icon-192.svg` + `icons/icon-512.svg` – SVG-Icons (Straßenbahn-Motiv)

**Testen:** Chrome DevTools → Application → Manifest prüfen.
„Zum Homescreen hinzufügen" auf Android testen.

**Abnahmekriterium:** App ist installierbar, Service Worker aktiv,
App-Shell lädt offline.

---

## Phase 6 – Frontend: Haltestellen & Abfahrten

**Ziel:** Nutzer sieht Haltestellen in der Nähe und Abfahrten.

- [x] `js/utils/geolocation.js` – GPS-Wrapper mit Fehlerbehandlung
      (PERMISSION_DENIED, POSITION_UNAVAILABLE, TIMEOUT; maximumAge 60 s)
- [x] `js/views/nearby.js` – Liste nahegelegener Haltestellen,
      sortiert nach Entfernung, Tap → Abfahrten
- [x] `js/views/departures.js` – Abfahrtstafel mit Soll/Ist,
      bekannte Kursnummer farblich hervorgehoben, Auto-Refresh alle 30 s;
      Tap → sessionStorage → #capture (Phase 7)
- [x] `js/utils/format.js` – Zeitformatierung (Europe/Berlin),
      Verspätungsberechnung, Betriebsdatum, zweistellige Kursnummer

**Testen:** Auf mobilem Chrome mit aktivem GPS testen.
Prüfen ob Tram-Filter greift (keine Busse in der Liste).

**Abnahmekriterium:** Haltestellen erscheinen sortiert nach GPS-Entfernung.
Abfahrten zeigen Soll und Ist korrekt in Berliner Zeit.

---

## Phase 7 – Frontend: Kursnummer erfassen

**Ziel:** Kernfunktion der App: Kursnummer einer Abfahrt zuordnen.

- [x] `js/views/capture.js` – Erfassungsview:
      18 Schnell-Buttons (01–18), Freitextfeld (01–99),
      Validierung, Bestätigungs-Feedback
- [x] Nach erfolgreicher Erfassung: Rückkehr zur Abfahrtstafel,
      `activeCourseNumber` der betreffenden Zeile sofort aktualisieren
      (durch Neu-Laden der Abfahrten nach Rückkehr zu #departures)

**Testen:** Erfassung mit Schnellbutton + Freitextfeld. Ungültige Eingaben
abfangen (00, 100, Buchstaben). Prüfen ob Laufweg in DB gespeichert wird.

**Abnahmekriterium:** Nach Erfassung zeigt Abfahrtstafel die Kursnummer
farblich hervorgehoben. DB-Tabelle `route_stops` enthält alle Halte.

---

## Phase 8 – Frontend: Erfassungen einsehen

**Ziel:** Übersicht aller Erfassungen mit Filter und Periodenumschalter.

- [x] `js/views/history.js` – Liste aller Erfassungen,
      Filtermöglichkeiten (Linie, Wochentagstyp, Datum),
      Anzeige ob Kursnummer manuell übersteuert
- [x] Periodenumschalter: Dropdown mit allen Perioden,
      ältere Perioden als read-only kennzeichnen (Banner + keine Erfassung möglich)

**Abnahmekriterium:** Filter funktionieren kombiniert.
Periodenumschalter zeigt Altdaten korrekt an.

---

## Phase 9 – Admin-Frontend

**Ziel:** Vollständiges Admin-Interface für Betreiber.

- [x] `admin/index.html` – Login-Formular + Dashboard-Shell mit Tab-Navigation
- [x] `admin/css/admin.css` – Desktop-first Styling, responsive
- [x] `admin/js/admin.js` – Login/Logout-Flow, Session-Check, Tab-Router
- [x] `admin/js/school_holidays.js` – Tabelle mit CRUD (anlegen, inline bearbeiten,
      löschen), Datumsvalidierung
- [x] `admin/js/periods.js` – Perioden-Übersicht, Fahrplanschnitt-Button
      mit Bestätigungsdialog und Warntext, Periode inline umbenennen/Datum korrigieren
- [x] `admin/js/override.js` – Fahrten nach Linie/Typ filtern, Kursnummer manuell
      setzen oder zurücksetzen; aktive Periode wählbar

**Testen:** Fahrplanschnitt durchführen, prüfen dass Erfassungen in
alter Periode bleiben. Override setzen und in Abfahrtstafel prüfen.

**Abnahmekriterium:** Fahrplanschnitt funktioniert vollständig.
Ohne Login kein Zugriff auf Admin-Funktionen.

---

## Phase 10 – Abschluss & Härtung

**Ziel:** Produktionsreife.

- [x] Eingabevalidierung nochmals durchgehen (alle POST/PUT-Endpunkte):
      Feldlängen (hafasTripId≤512, serviceNr≤20, line≤10, direction≤100, stopId≤20),
      ISO-8601-Format für departurePlanned/departureActual, Kursnummer-Regex 01–99
- [x] Rate-Limiting: Filesystem-Cache für HAFAS-Antworten implementiert
      (nearby 1 Tag, departures 30 s, trip 1 Tag) → weit unter 100 req/min
- [x] `.htaccess`: Security-Header (CSP, X-Content-Type-Options, X-Frame-Options,
      Referrer-Policy), sensible Dateitypen gesperrt (.cache, .log, .sql, .sh, .bru),
      Admin-Bereich mit X-Robots-Tag noindex
- [x] HTTPS erzwingen: Redirect-Block in `.htaccess` aktiviert (301-Redirect auf https)
- [ ] PWA auf verschiedenen Android-Geräten / Chrome-Versionen testen
- [ ] Ladezeiten prüfen (HAFAS-Proxy-Latenz, DB-Abfragen)
- [ ] Backup-Konzept für MariaDB klären (Hoster-seitig oder eigenes Skript)
- [0] INSA HAFAS Nutzungsrechte abschließend bestätigt

**Abnahmekriterium:** App läuft stabil im Produktivbetrieb. HTTPS aktiv.
Kein öffentlicher Zugriff auf `config.php` möglich.

---

## Phase 11 - GoLive

**Ziel:** Produktivsetzung auf dem Webhosting

- [x] Webhosting-Umgebung prüfen: PHP ≥ 8.0, curl-Extension, MariaDB-Version
- [x] deploy-Skript erstellen (`local_scripts/deploy.sh`: Tests → Git-Check → Tag → rsync → composer install remote)
- [x] Datenbank bereitstellen (`local_scripts/setup_db.sh`: Schema mit db_prefix-Platzhalter, lokal/remote/--print)
- [x] Tabellen-Prefix (`db_prefix` in config.php) für Multi-Instanz-Betrieb auf einer DB
- [x] HTTPS erzwingen: Redirect-Block in `.htaccess` aktiviert
- [x] initiale Verzeichnisstruktur und nicht kopierte Dateien: deploy.sh legt `cache/hafas/`, `logs/` an und erstellt `config.php` beim Erst-Deployment
- [x] README aktualisieren: Deployment-Abschnitt mit deploy.sh und setup_db.sh
- [x] Bruno-Tests: Production-Umgebung (`bruno/environments/Production.bru`) ergänzt
- [x] Deployment ausführen und abschließend testen
- [ ] Progressive Web-App auf Zielgerät installieren

**Abnahmekriterium:** App läuft stabil im Produktivbetrieb. HTTPS aktiv.
Kein öffentlicher Zugriff auf `config.php` möglich.

---

## Phase 12 – Umzug der Code-Verwaltung zu GitHub

**Ziel:** Wechsel des Hostings von Codeberg zu GitHub (geänderte Regeln für
KI-gestützt entwickelten Code). Das Repository heißt dort `MDKursTracker`
statt bisher `JSKursTracker`.

- [x] SSH-Schlüssel für GitHub erzeugen (ed25519) und in `~/.ssh/config` eintragen
- [x] Repository `ClickKlack/MDKursTracker` auf GitHub anlegen
- [x] Codeberg-Verweise ersetzen: Info-Ansicht, HAFAS-User-Agent, README, composer.json
- [x] Git-Remote `origin` auf GitHub umstellen
- [x] Historie inklusive aller Tags nach GitHub pushen

**Abnahmekriterium:** Vollständige Historie und alle Tags auf GitHub vorhanden.
Kein Verweis auf Codeberg mehr im ausgelieferten Code.

**Hinweis:** Die Einträge in Phase 0 dokumentieren den damaligen Stand
(Codeberg) und bleiben als Historie unverändert stehen.

---

## Reihenfolge für KI-gestützte Implementierung

Jede Phase als eigene Sitzung starten. Kontext am Anfang jeder Sitzung
mitgeben:

```
Lies SPEC.md, ARCHITECTURE.md, API.md und DATABASE.sql.
Wir implementieren jetzt aus (ROADMAP.md) Phase X : [Phasenbeschreibung].

Aktualisiere danach die ROADMAP und die Bruno-Tests.
```

Phasen 1–4 (Backend) vollständig abschließen und testen,
bevor Phase 5 (Frontend) beginnt. Das Backend ist die stabile Grundlage
für alle Frontend-Arbeiten.
