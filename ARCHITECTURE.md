# ARCHITECTURE.md – marego Kursnummer-Erfassungs-App

> Version: 1.1 | Stand: 2026-03-24

---

## 1. Überblick

```
[Android Chrome / PWA]
         |
         | HTTPS (JSON)
         v
[PHP-Backend auf Webhosting]
    |                    |
    | curl (Proxy)        | PDO / Prepared Statements
    v                    v
[INSA HAFAS API]      [MariaDB]
```

Das Backend hat zwei Aufgaben: HAFAS-Proxy (damit das Frontend keine
CORS-Probleme hat) und REST-API für alle Datenbankoperationen.

---

## 2. Verzeichnisstruktur

Der Webserver zeigt ausschließlich auf `public/` als Document Root.
Alle übrigen Verzeichnisse liegen außerhalb des Webroots und sind
damit vom Browser nicht direkt erreichbar.

```
/                              ← Projektverzeichnis (NICHT öffentlich)
├── config.php                 ← DB-Zugangsdaten, Admin-Passwort-Hash
│
├── lib/                       ← Gemeinsam genutzte PHP-Bibliotheken
│   ├── db.php                 ← PDO-Verbindung, auto-init Periode
│   ├── hafas.php              ← curl-Wrapper für INSA HAFAS API
│   ├── calendar.php           ← Wochentagstyp-Berechnung (Feiertage, Ferien)
│   ├── auth.php               ← Session-Prüfung für Admin-Endpunkte
│   └── response.php           ← JSON-Ausgabe-Helfer (header + echo + exit)
│
└── public/                    ← Webroot (Document Root des Webservers)
    ├── index.html             ← PWA Shell (einzige HTML-Seite)
    ├── manifest.json          ← PWA Manifest
    ├── sw.js                  ← Service Worker
    ├── favicon.ico
    │
    ├── css/
    │   └── app.css            ← Gesamtes Styling
    │
    ├── js/
    │   ├── app.js             ← Einstiegspunkt, Router zwischen Views
    │   ├── api.js             ← Alle fetch()-Aufrufe ans PHP-Backend
    │   ├── views/
    │   │   ├── nearby.js      ← View: Haltestellen in der Nähe
    │   │   ├── departures.js  ← View: Abfahrtstafel
    │   │   ├── capture.js     ← View: Kursnummer erfassen
    │   │   └── history.js     ← View: Erfassungen einsehen / Periodenumschalter
    │   └── utils/
    │       ├── geolocation.js ← GPS-Wrapper
    │       └── format.js      ← Datum/Zeit-Formatierung, zweistellige Kursnr.
    │
    ├── admin/                 ← Admin-Frontend (öffentlich erreichbar, aber Login-geschützt)
    │   ├── index.html         ← Admin-Shell (Login + Dashboard)
    │   ├── css/
    │   │   └── admin.css
    │   └── js/
    │       ├── admin.js       ← Einstiegspunkt Admin-Frontend
    │       ├── school_holidays.js ← Schulferien CRUD
    │       ├── periods.js         ← Fahrplanperioden, Fahrplanschnitt
    │       └── override.js        ← Manuelle Kursnummer-Übersteuerung
    │
    ├── api/                   ← Öffentliche API-Endpunkte
    │   ├── index.php          ← Router: leitet Requests an Handler weiter
    │   ├── nearby.php         ← GET  /api/nearby
    │   ├── departures.php     ← GET  /api/departures
    │   ├── trip.php           ← GET  /api/trip
    │   ├── calendar.php       ← GET  /api/calendar
    │   ├── recordings.php     ← GET + POST /api/recordings
    │   ├── trips.php          ← GET  /api/trips
    │   └── periods.php        ← GET  /api/periods
    │
    └── admin-api/             ← Admin-API-Endpunkte (Session-geschützt)
        ├── index.php          ← Router Admin
        ├── login.php          ← POST /admin/login
        ├── logout.php         ← POST /admin/logout
        ├── school_holidays.php ← GET/POST/PUT/DELETE /admin/school-holidays
        ├── periods.php        ← POST/PUT /admin/periods
        └── override.php       ← PUT/DELETE /admin/trips/:id/override
```

---

## 3. Komponentenbeschreibung

### 3.1 Frontend – `js/app.js`

Zentraler Einstiegspunkt. Implementiert einen einfachen Hash-basierten Router
(`#nearby`, `#departures?stopId=…`, `#capture?…`, `#history`). Lädt beim
Start die aktive Periode vom Backend und hält sie als globale Variable vor.

### 3.2 Frontend – `js/api.js`

Kapselt alle `fetch()`-Aufrufe. Gibt normalisierte JS-Objekte zurück.
Wirft bei HTTP-Fehlern einheitliche Exceptions, die in den Views als
Fehlermeldungen dargestellt werden.

### 3.3 Frontend – Views

Jeder View ist ein Modul mit den Funktionen `render()` und `destroy()`.
`render()` schreibt HTML in den `<main>`-Container. `destroy()` entfernt
Event-Listener. Kein Framework – nur DOM-API.

### 3.4 Backend – `lib/db.php`

Baut die PDO-Verbindung auf. Prüft beim ersten Aufruf ob
`schedule_periods` leer ist und legt ggf. die initiale Periode an
(Bezeichnung „Fahrplan (initial)", Startdatum = `CURDATE()`).
Bindet `config.php` per absolutem Pfad ein (außerhalb des Webroots).

### 3.5 Backend – `lib/hafas.php`

Kapselt alle curl-Aufrufe an `insa.hafas.de`. Stellt folgende Funktionen
bereit:

- `hafas_nearby(float $lat, float $lon): array`
- `hafas_departures(string $stopId): array`
- `hafas_trip(string $tripId): array`

Gibt normalisierte PHP-Arrays zurück. Fehler der HAFAS-API werden als
Exception weitergegeben.

### 3.6 Backend – `lib/calendar.php`

Berechnet den Wochentagstyp (`MO-FR`, `SA`, `SO`, `FT`, `SF`) für ein
gegebenes Datum. Feiertage Sachsen-Anhalt sind als statische Liste
hinterlegt. Schulferien werden aus der DB gelesen (gecacht pro Request).

Funktion: `getDayType(DateTimeInterface $date, PDO $db): string`

### 3.7 Backend – `lib/auth.php`

Prüft ob eine gültige Admin-Session existiert. Bricht den Request mit
HTTP 401 ab, wenn nicht. Wird in allen Admin-Endpunkten als erstes
aufgerufen.

### 3.8 Service Worker – `sw.js`

Cacht beim Install-Event die App-Shell (HTML, CSS, JS, Manifest).
Network-first für alle API-Calls. Bei Netzwerkfehler auf gecachte Shell
zurückfallen. Kein Offline-Betrieb für HAFAS-Daten vorgesehen.

---

## 4. Datenfluss: Kursnummer erfassen

```
Nutzer wählt Abfahrt + gibt Kursnummer ein
         │
         ▼
capture.js → api.js
  POST /api/recordings
  { hafasTripId, stopId, departurePlanned, departureActual, courseNumber, serviceDate }
         │
         ▼
recordings.php
  1. day_type berechnen (lib/calendar.php)
  2. trips: INSERT IGNORE (period_id, service_nr, line, day_type, direction)
  3. trips: SELECT id für FK
  4. stops: INSERT IGNORE (hafas_id, name)
  5. recordings: INSERT
  6. hafas_trip(hafasTripId) → alle Halte laden
  7. stops: INSERT IGNORE für jeden Halt
  8. route_stops: INSERT für jeden Halt
  9. HTTP 201 + { recordingId }
```

---

## 5. Datenfluss: Fahrplanschnitt

```
Admin klickt "Neuer Fahrplan"
         │
         ▼
periods.js → Bestätigungsdialog
         │ bestätigt
         ▼
admin-api/periods.php
  POST { name, startDate }
  → INSERT INTO schedule_periods
  → HTTP 201 + { id, name, startDate }
         │
         ▼
Frontend aktualisiert aktive Periode im globalen State
Alle nachfolgenden Erfassungen nutzen neue period_id
```

---

## 6. Konfigurationsdatei (außerhalb Webroot)

Die Datei liegt im Projektwurzelverzeichnis, das heißt eine Ebene über
`public/` und damit außerhalb des Document Root. `lib/db.php` und
`lib/auth.php` binden sie per absolutem Pfad ein.

```php
<?php
// config.php – liegt im Projektverzeichnis, NICHT in public/
return [
    'db_host'       => 'localhost',
    'db_name'       => 'marego_kurse',
    'db_user'       => 'marego_user',
    'db_pass'       => 'geheimes_passwort',
    'admin_hash'    => password_hash('adminpasswort', PASSWORD_BCRYPT),
    // Einbindung in lib/db.php und lib/auth.php:
    // require_once dirname(__DIR__, 2) . '/config.php';
    //   (von public/api/ aus: zwei Ebenen hoch)
];
```

---

## 7. Entwicklungsrichtlinien

### 7.1 Namenskonventionen

| Artefakt | Sprache | Beispiele |
|---|---|---|
| PHP-Funktionen, -Variablen, -Parameter | Englisch | `getPeriod()`, `$tripId`, `$departures` |
| JS-Funktionen, -Variablen | Englisch | `renderDepartures()`, `activeStop` |
| DB-Tabellennamen | Englisch | `trips`, `stops`, `recordings`, `schedule_periods`, `school_holidays` |
| DB-Spaltennamen | Englisch | `recorded_at`, `line`, `course_number`, `period_id`, `service_nr` |
| Quellcode-Kommentare (PHP + JS) | **Deutsch** | `// Aktive Periode ermitteln` |

### 7.2 Logging mit Monolog

Das Backend verwendet **Monolog** als einzige Logging-Bibliothek.
Kein `error_log()`, kein direktes Schreiben in Dateien.

**Installation:** `composer require monolog/monolog`

**Log-Datei:** `../logs/app.log` (außerhalb `public/`, nicht per HTTP erreichbar)

**Rotation:** `RotatingFileHandler` mit `maxFiles = 14` (14 Tage).

**Initialisierung** (einmalig in `lib/logger.php`):

```php
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

function createLogger(): Logger {
    $logger = new Logger('mdkurstracker');
    $handler = new RotatingFileHandler(
        dirname(__DIR__) . '/logs/app.log',
        maxFiles: 14,
        level: Level::Debug
    );
    $logger->pushHandler($handler);
    return $logger;
}
```

**Log-Level-Richtlinie:**

| Level | Wann verwenden |
|---|---|
| `DEBUG` | Interne Zwischenschritte (HAFAS-Request-Parameter, SQL-Ergebnisse) – nur während Entwicklung relevant |
| `INFO` | Normale Geschäftsereignisse: neue Erfassung gespeichert, Fahrplanschnitt durchgeführt, Login erfolgreich |
| `WARNING` | Unerwartete, aber behandelbare Zustände: HAFAS liefert leere Antwort, optionales Feld fehlt |
| `ERROR` | Fehler, die den Request abbrechen: DB-Verbindung fehlgeschlagen, HAFAS nicht erreichbar, ungültige Daten |

**Beispiele:**

```php
$logger->info('Recording saved', ['recording_id' => $id, 'trip_nr' => $tripNr]);
$logger->warning('HAFAS returned empty stop list', ['trip_id' => $tripId]);
$logger->error('Database connection failed', ['exception' => $e->getMessage()]);
```

---

## 8. Technische Randbedingungen

| Thema | Entscheidung |
|---|---|
| PHP-Mindestversion | 8.0 (Konstruktor-Promotion, match, nullsafe) |
| MariaDB-Mindestversion | 10.4 (CHECK constraints stabil) |
| curl | Muss als PHP-Extension aktiviert sein |
| Composer | Erforderlich für Monolog |
| Kein npm / kein Build-Step | Vanilla JS, kein Bundler, direkt deploybar |
| Kein Framework | Weder Frontend- noch Backend-Framework |
| Zeitzone | Backend arbeitet in UTC, Frontend zeigt in Europe/Berlin |
