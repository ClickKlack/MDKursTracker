# SPEC.md – marego Kursnummer-Erfassungs-App: MDKursTracker

> Version: 1.3
> Stand: 2026-04-14
> Status: Implementiert (Schema-Version 2)

---

## 1. Projektziel

Eine Progressive Web App (PWA) zur kollektiven Erfassung von Kursnummern für
Straßenbahnfahrten im Verkehrsverbund **marego** (Magdeburg / MVB).

Mehrere Nutzer können gleichzeitig Kursnummern erfassen. Alle Einträge landen
in einer gemeinsamen zentralen Datenbank. Der Betreiber wertet zentral aus,
kann Einträge manuell übersteuern und bei einem Fahrplanwechsel eine neue
Erfassungsperiode starten.

---

## 2. Technologie-Stack

| Schicht | Technologie |
|---|---|
| Frontend | HTML5 + CSS + Vanilla JavaScript (Single Page App) |
| PWA | Web App Manifest + Service Worker |
| Backend | PHP (plain, kein Framework erforderlich) |
| Datenbank | MariaDB |
| Hosting | Bestehendes Webhosting mit PHP/MariaDB |
| Fahrplandaten | INSA/NASA HAFAS-API (`reiseauskunft.insa.de/bin/mgate.exe`) |
| HAFAS-Zugriff | PHP-curl-Proxy (löst CORS-Problem) |

> **Hinweis INSA API:** Die INSA/NASA HAFAS-API ist technisch öffentlich
> zugänglich, aber nicht offiziell als Open API dokumentiert. Vor
> Produktiveinsatz Anfrage bei NASA GmbH empfohlen: service@nasa.de
>
> **API-Parameter (aus hafas-client, Phase 0 verifiziert):**
> URL: `https://reiseauskunft.insa.de/bin/mgate.exe`
> AID: `nasa-apps`, client: `{ type: IPH, id: NASA, v: 4000200, name: nasaPROD }`, ver: `1.44`

---

## 3. Nutzer & Zugang

### 3.1 Erfasser (anonym)

Kein Login. Jeder mit der URL kann Kursnummern eintragen und alle Einträge
einsehen. Die App erzeugt beim Erstbesuch automatisch ein **UUID-v4-Token**
(ohne Bindestriche, 32 Hex-Zeichen) und speichert es in `localStorage`.

- Das Token ist dauerhaft (kein automatischer Ablauf)
- Aus dem Token wird eine **5-stellige Base36-Display-ID** abgeleitet
  (SHA-256 des Tokens → erste 6 Hex-Zeichen → Basis-36-Umwandlung,
  Großbuchstaben, führende Nullen auf 5 Stellen). Diese ID ist nur im
  eigenen Profil-Modal und im Admin-Frontend sichtbar.
- Bei jedem API-Call wird der Token als HTTP-Header `X-User-Token` übermittelt
- Optionaler Profilname: Nutzer können freiwillig ihren Namen angeben
  (hilft dem Admin bei Rückfragen)
- Alle eigenen Erfassungen sind in der Verlaufsansicht hervorgehoben

### 3.2 Admin

Eigenes Login-Formular (`/admin/`). PHP-Session-basiert, Gültigkeitsdauer
**30 Tage** (konfiguriert über `session.gc_maxlifetime` und Cookie-Lifetime).
Passwort als `password_hash()`-Wert in Konfigurationsdatei **außerhalb**
des Webroot gespeichert – niemals im JS, niemals im Klartext in der DB.

---

## 4. Fahrplanperioden

### 4.1 Konzept

Alle Erfassungsdaten gehören immer zu genau einer **Fahrplanperiode**. Eine
Periode repräsentiert einen zusammenhängenden Gültigkeitszeitraum eines
Fahrplans (z.B. „Fahrplan 2025/2026").

Beim **Fahrplanschnitt** (Knopfdruck im Admin-Frontend) wird eine neue Periode
angelegt. Die bisherigen Daten bleiben vollständig und unverändert erhalten
und sind weiterhin gemeinsam auswertbar. Die neue Periode startet mit leeren
Tabellen für Fahrten und Erfassungen – als wäre die App neu gestartet.

Da sich `serviceNr`-Werte zwischen Fahrplanperioden wiederholen können (gleiche
Nummer, aber möglicherweise andere Kursnummer), sind alle fahrtenspezifischen
Daten strikt periodengebunden. Es gibt keine Übernahme von Altdaten in die
neue Periode.

### 4.2 Aktive Periode

Die App arbeitet immer mit der **aktuellen** (neuesten) Periode. Ältere
Perioden sind im Admin-Frontend einsehbar und auswertbar, aber nicht mehr
beschreibbar.

Die aktive Periode ist immer diejenige mit dem höchsten `id`-Wert in
`schedule_periods`. Es gibt keine explizite „aktiv"-Spalte.

### 4.3 Initiale Periode

Beim allerersten Aufruf eines API-Endpunkts prüft das PHP-Backend, ob die
Tabelle `schedule_periods` leer ist. Ist sie leer, wird automatisch eine erste
Periode mit der Bezeichnung „Fahrplan (initial)" und dem aktuellen Datum als
Startdatum angelegt. Der Admin kann Bezeichnung und Startdatum dieser Periode
im Admin-Frontend nachträglich korrigieren.

### 4.4 Fahrplanschnitt-Vorgang (Admin)

1. Admin gibt Bezeichnung und Startdatum der neuen Periode ein
2. Bestätigungsdialog mit Warnhinweis: „Erfassung beginnt bei Null –
   Altdaten bleiben erhalten und auswertbar"
3. Neue Zeile wird in `schedule_periods` angelegt
4. Die App wechselt sofort auf die neue Periode
5. Alle Erfasser arbeiten ab sofort in der neuen, leeren Periode

---

## 5. Kalenderlogik

Jede logische Fahrt gehört zu einem Wochentagstyp:

| Typ | Regel |
|---|---|
| `MO-FR` | Montag–Freitag, kein Feiertag, kein Schulferientag |
| `SA` | Samstag |
| `SO` | Sonntag **und gesetzliche Feiertage** (Sachsen-Anhalt) |
| `SF` | Schulferientag Sachsen-Anhalt (Mo–Fr, kein Feiertag) |

Feiertage werden als `SO` behandelt, da sie nach Sonntagsfahrplan fahren
und gemeinsam mit Sonntagen ausgewertet werden. Der Typ `FT` existiert noch
im DB-ENUM (für Altdaten), wird aber nicht mehr neu vergeben.

**Feiertage Sachsen-Anhalt** sind im PHP-Backend fest hinterlegt (inkl.
Reformationstag 31.10., Weltfriedenstag 08.05.).

**Schulferien** werden in der Tabelle `school_holidays` verwaltet und über das
Admin-Frontend gepflegt. Schulferien gelten periodenübergreifend
(reine Kalenderdaten).

---

## 6. Fahrt-Identifikation & Duplikaterkennung

Eine **logische Fahrt** ist eindeutig identifiziert durch:

```
period_id + service_nr + line + day_type
```

Pro logischer Fahrt können beliebig viele **Einzelerfassungen** von
verschiedenen Nutzern, Tagen und Haltestellen vorliegen. Dies ist gewünscht.

### Aktive Kursnummer

Die anzuzeigende aktive Kursnummer wird zur Laufzeit berechnet:

1. **Manuelle Übersteuerung** durch Admin → hat immer Vorrang
2. **Mehrheitsregel:** Häufigste erfasste Kursnummer gewinnt
3. **Gleichstand:** Ältester Eintrag (`recorded_at`) gewinnt

---

## 7. Funktionen

### 7.1 Haltestellen suchen

Der Haltestellen-View bietet drei Reiter, deren letzter gewählter Zustand
in `localStorage` gespeichert wird:

**GPS-Reiter:**
- Standort via Browser Geolocation API
- PHP-Proxy → INSA HAFAS Nearby-Abfrage
- Anzeige als Liste sortiert nach Entfernung, gefiltert auf Trams

**Name-Reiter:**
- Freitexteingabe → `GET /api/nearby?name=…` → HAFAS LocMatch
- Der in `config.php` konfigurierte `stop_name_prefix` (z.B. `"Magdeburg, "`)
  wird serverseitig automatisch vorangestellt
- Suche nur auf Knopfdruck / Enter (kein Live-Search)
- Letzter Suchbegriff wird in `localStorage` wiederhergestellt

**Favoriten-Reiter:**
- Zeigt gespeicherte Lieblings-Haltestellen kompakt als Liste
- Klick navigiert direkt zu Abfahrten
- × entfernt den Favoriten

Bei GPS- und Name-Ergebnissen kann jede Haltestelle per ⭐-Button als
Favorit hinzugefügt oder entfernt werden.

### 7.2 Abfahrten an einer Haltestelle

- Nächste ~20 Straßenbahn-Abfahrten nach Haltestellenauswahl
- Anzeige je Abfahrt:
  - Linie, Richtung
  - Abfahrt **Soll** und **Ist** (Echtzeit, wenn verfügbar)
  - Bereits bekannte aktive Kursnummer der aktuellen Periode
    (farblich hervorgehoben)

### 7.3 Kursnummer erfassen

- **Schnellerfassung:** 18 Buttons für `01`–`18`
- **Freitextfeld:** Freie Eingabe, Validierung auf zweistelliges Format `01`–`99`
- Nach Bestätigung:
  - Speicherung der Erfassung inkl. `user_token` (wenn vorhanden)
  - Automatischer Abruf des vollständigen Laufwegs via HAFAS Trip-Endpunkt
  - Speicherung aller Halte normalisiert in `route_stops` + `stops`

### 7.4 Einträge einsehen (Verlauf)

- Liste aller Erfassungen der **aktuellen Periode**, sortiert nach
  `recorded_at` absteigend
- Filterbar nach: Linie, Wochentagstyp, Datum
- Kennzeichnung ob Kursnummer manuell übersteuert oder per Mehrheit ermittelt
- **Periodenumschalter:** Ansicht älterer Perioden möglich (read-only)
- **Eigene Erfassungen** sind mit orangem „Ich"-Badge und blauem linken Rand
  hervorgehoben
- **Kommentare** werden unter der Metazeile angezeigt
- **Inline-Bearbeitung** eigener Erfassungen (nur aktive Periode,
  max. 60 Minuten nach Erfassung):
  - Kurs-Buttons `00`–`39`
  - Kommentarfeld (max. 500 Zeichen)
  - Speichern aktualisiert die Karte direkt ohne Reload

### 7.5 Eigenes Profil

- Aufruf über den Profil-Tab in der Navigation
- Display-ID wird sofort client-seitig aus dem localStorage-Token berechnet
  (kein Ladevorgang)
- Optionaler Name: wird beim Verlassen des Feldes automatisch gespeichert
  (Debounce: 1,5 s nach Tippende, sofort bei Blur)
- Name ist nur im Admin-Frontend sichtbar (nicht in der Verlaufsliste)

### 7.6 Admin-Frontend (`/admin/`)

- Login: HTML-Formular → POST → PHP-Session (30 Tage gültig)
- Logout

**Schulferien:**
- Anlegen, Bearbeiten, Löschen

**Kursnummer-Übersteuerung:**
- Manuellen Wert je logischer Fahrt (aktuelle Periode) setzen oder
  zurücksetzen
- **Einzelerfassungen:** Aufklappbare Liste pro Fahrt (Accordion –
  immer nur eine offen) mit Zeitpunkt, Haltestelle, Kurs, Kommentar
  und Nutzerinformationen (Name, Display-ID, Gerät)

**Fahrplanperioden:**
- Übersicht aller Perioden (Bezeichnung, Startdatum, Anzahl Erfassungen)
- Initiale Periode nachträglich umbenennen/Startdatum korrigieren
- Neue Periode anlegen: Eingabe Bezeichnung + Startdatum,
  dann Bestätigungsdialog
- Ältere Perioden zur Ansicht auswählen (read-only)
- Aktuelle Periode klar gekennzeichnet

---

## 8. Datenbankmodell (MariaDB)

### 8.1 Tabelle `schedule_periods`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `name` | VARCHAR(100) NOT NULL | z.B. „Fahrplan 2025/2026" |
| `start_date` | DATE NOT NULL | Beginn der Gültigkeit dieser Periode |
| `created_at` | DATETIME NOT NULL | Zeitstempel der Anlage |

Die aktive Periode ist immer diejenige mit dem höchsten `id`-Wert.

---

### 8.2 Tabelle `trips`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `period_id` | INT NOT NULL FK | → `schedule_periods.id` |
| `service_nr` | VARCHAR(20) NOT NULL | HAFAS fahrtNr |
| `line` | VARCHAR(10) NOT NULL | Linienbezeichnung (z.B. „6") |
| `day_type` | ENUM('MO-FR','SA','SO','FT','SF') NOT NULL | Kalendertyp |
| `direction` | VARCHAR(100) NOT NULL | Zielhaltestellenname |
| `manual_course_number` | CHAR(2) NULL | Manuelle Übersteuerung (NULL = keine) |

Unique-Index auf `(period_id, service_nr, line, day_type)`.

---

### 8.3 Tabelle `recordings`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `trip_id` | INT NOT NULL FK | → `trips.id` |
| `recorded_at` | DATETIME NOT NULL | Zeitstempel der Erfassung (UTC) |
| `hafas_trip_id` | VARCHAR(100) NOT NULL | HAFAS tripId (tagesgebunden) |
| `service_date` | DATE NOT NULL | Betriebsdatum |
| `stop_id` | VARCHAR(20) NOT NULL FK | → `stops.hafas_id` |
| `departure_planned` | DATETIME NOT NULL | Planmäßige Abfahrt |
| `departure_actual` | DATETIME NULL | Echtzeit-Abfahrt |
| `course_number` | CHAR(2) NOT NULL | Erfasste Kursnummer |
| `user_token` | VARCHAR(64) NULL FK | → `users.token`; NULL für Altdaten |
| `comment` | VARCHAR(500) NULL | Optionaler Nutzerkommentar |

---

### 8.4 Tabelle `route_stops`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `recording_id` | INT NOT NULL FK | → `recordings.id` |
| `sequence` | TINYINT NOT NULL | Position im Laufweg |
| `stop_id` | VARCHAR(20) NOT NULL FK | → `stops.hafas_id` |
| `departure_planned` | DATETIME NULL | Planmäßige Abfahrt an diesem Halt |

---

### 8.5 Tabelle `stops`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `hafas_id` | VARCHAR(20) PK | HAFAS-Haltestellen-ID |
| `name` | VARCHAR(100) NOT NULL | Klartextname |

Periodenübergreifend gültig.

---

### 8.6 Tabelle `school_holidays`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `name` | VARCHAR(100) NOT NULL | z.B. „Sommerferien 2025" |
| `date_from` | DATE NOT NULL | Beginn (inklusiv) |
| `date_to` | DATE NOT NULL | Ende (inklusiv) |

Periodenübergreifend gültig.

---

### 8.7 Tabelle `users`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `token` | VARCHAR(64) NOT NULL UNIQUE | UUID v4 ohne Bindestriche, clientseitig generiert |
| `display_id` | CHAR(5) NOT NULL UNIQUE | Base36-Kurzkennung (aus SHA-256 des Tokens) |
| `name` | VARCHAR(100) NULL | Optionaler Nutzername |
| `last_user_agent` | VARCHAR(512) NULL | Browser-User-Agent beim letzten API-Call |
| `last_device` | VARCHAR(100) NULL | Gerätekurzname (z.B. „Chrome 124 / Android 14") |
| `created_at` | DATETIME NOT NULL | Zeitstempel der Erstanlage |
| `last_seen_at` | DATETIME NOT NULL | Zeitstempel des letzten API-Calls |

---

### 8.8 Tabelle `user_favorites`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `user_token` | VARCHAR(64) NOT NULL FK | → `users.token` |
| `stop_id` | VARCHAR(20) NOT NULL | HAFAS-Haltestellen-ID |
| `stop_name` | VARCHAR(100) NOT NULL | Anzeigename |
| `created_at` | DATETIME NOT NULL | Zeitstempel der Anlage |

Unique-Index auf `(user_token, stop_id)`.

---

### 8.9 Entity-Relationship-Übersicht

```
schedule_periods (1) ──< trips (1) ──< recordings (1) ──< route_stops
                                               │                 │
                                            stops <──────────────┘
                                               │
                                         users (0..1)
                                               │
                                      user_favorites (0..n)

school_holidays  (unabhängig, periodenübergreifend)
stops            (unabhängig, periodenübergreifend)
users            (unabhängig, periodenübergreifend)
```

---

## 9. Systemarchitektur

```
[Android Chrome / PWA]
         |
         | HTTPS (JSON)
         v
[PHP-Backend auf Webhosting]
    |                    |
    | curl (Proxy)        | SQL
    v                    v
[INSA HAFAS API]      [MariaDB]
insa.hafas.de/hafas/
mgate.exe
```

**Passwortablage Admin:** `password_hash()` in PHP-Konfigurationsdatei
**außerhalb des Webroot** – niemals im JS, niemals im Klartext in der DB.

---

## 10. API-Endpunkte

Siehe `API.md` für vollständige Request/Response-Dokumentation.

| Methode | Pfad | Funktion |
|---|---|---|
| `GET` | `/api/config` | Frontend-Konfiguration |
| `GET` | `/api/nearby?lat&lon` | Nahegelegene Tramhaltestellen (GPS) |
| `GET` | `/api/nearby?name` | Tramhaltestellen per Namenssuche |
| `GET` | `/api/departures` | Abfahrten an einer Haltestelle |
| `GET` | `/api/trip` | Vollständiger Laufweg eines Kurses |
| `GET` | `/api/calendar` | Wochentagstyp für ein Datum |
| `POST` | `/api/recordings` | Neue Erfassung speichern |
| `GET` | `/api/recordings` | Erfassungen abrufen |
| `PUT` | `/api/recordings/:id` | Eigene Erfassung bearbeiten (Kurs + Kommentar) |
| `GET` | `/api/recordings/:id/route` | Laufweg einer Erfassung |
| `GET` | `/api/trips` | Logische Fahrten mit aktiver Kursnummer |
| `GET` | `/api/periods` | Alle Fahrplanperioden |
| `POST` | `/api/user` | Token registrieren / `last_seen_at` aktualisieren |
| `GET` | `/api/user` | Eigenes Profil laden |
| `PUT` | `/api/user` | Profilname setzen |
| `GET` | `/api/user/favorites` | Favoriten laden |
| `POST` | `/api/user/favorites` | Favorit hinzufügen |
| `DELETE` | `/api/user/favorites/:stop_id` | Favorit entfernen |
| `POST` | `/admin-api/login` | Admin-Login |
| `POST` | `/admin-api/logout` | Admin-Logout |
| `*` | `/admin-api/school-holidays` | Schulferien CRUD |
| `PUT` | `/admin-api/trips/:id/override` | Kursnummer-Übersteuerung setzen |
| `DELETE` | `/admin-api/trips/:id/override` | Kursnummer-Übersteuerung zurücksetzen |
| `GET` | `/admin-api/trips/:id/recordings` | Einzelerfassungen einer Fahrt |
| `POST` | `/admin-api/periods` | Fahrplanschnitt |
| `PUT` | `/admin-api/periods/:id` | Periode umbenennen / Datum korrigieren |

---

## 11. Sicherheitsanforderungen

- Admin-Passwort: `password_hash()` in PHP-Konfigurationsdatei außerhalb Webroot
- Kein Passwort oder Hash im JavaScript
- Kein Passwort im Klartext in der Datenbank
- PHP-Session mit `session_regenerate_id()` nach Login
- Session-Cookie: `HttpOnly`, `Secure`, `SameSite=Lax`, 30 Tage Lifetime
- Alle Admin-Endpunkte prüfen Session-Status vor Ausführung
- Alle Datenbankzugriffe ausschließlich mit Prepared Statements
- Fahrplanschnitt nur nach explizitem Bestätigungsdialog auslösbar
- User-Token niemals im JSON-Response öffentlicher Endpunkte —
  nur `isOwn: bool` wird zurückgegeben
- Display-ID nur im eigenen Profil-Modal sichtbar, nicht in der Verlaufsliste

---

## 12. PWA-Anforderungen

- `manifest.json` mit Name, Icons, `display: standalone`
- Service Worker für Offline-Fähigkeit (App-Shell cachen, API-Calls ungecacht)
- Getestet auf Android Chrome

---

## 13. Datenbankmigrationen

| Version | Datei | Inhalt |
|---|---|---|
| v1 | `DATABASE.sql` | Initiales Schema (alle Tabellen, für Neuinstallation) |
| v2 | `DATABASE_migrate_v2.sql` | Neue Tabellen `users`, `user_favorites`; neue Spalten `user_token`, `comment` in `recordings` |

Migration v2 ist sicher wiederholbar (`IF NOT EXISTS`). Bestehende Erfassungen
bleiben unverändert (`user_token` ist nullable).
