# SPEC.md – marego Kursnummer-Erfassungs-App: MDKursTracker

> Version: 1.2
> Stand: 2026-03-24
> Status: Anforderungskonzept abgeschlossen, Implementierung ausstehend

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

- **Erfasser:** Kein Login. Jeder mit der URL kann Kursnummern eintragen und
  alle Einträge einsehen.
- **Admin:** Eigenes Login-Formular (`/admin/`). PHP-Session-basiert.
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

### 7.1 Haltestellen in der Nähe

- GPS-Standort via Browser Geolocation API
- PHP-Proxy → INSA HAFAS Nearby-Abfrage
- Anzeige als Liste, sortiert nach Entfernung
- Gefiltert auf Modus `tram` (nur Straßenbahnen)

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
  - Speicherung der Erfassung in `recordings` (Referenz auf aktuelle Periode
    über `trip_id`)
  - Automatischer Abruf des vollständigen Laufwegs via HAFAS Trip-Endpunkt
  - Speicherung aller Halte normalisiert in `route_stops` + `stops`

### 7.4 Einträge einsehen

- Liste aller Erfassungen der **aktuellen Periode**, sortiert nach
  `recorded_at` absteigend
- Filterbar nach: Linie, Wochentagstyp, Datum
- Kennzeichnung ob Kursnummer manuell übersteuert oder per Mehrheit ermittelt
- **Periodenumschalter:** Ansicht älterer Perioden zur Auswertung möglich
  (read-only)

### 7.5 Admin-Frontend (`/admin/`)

- Login: HTML-Formular → POST → PHP-Session
- Logout

**Schulferien:**
- Anlegen, Bearbeiten, Löschen

**Kursnummer-Übersteuerung:**
- Manuellen Wert je logischer Fahrt (aktuelle Periode) setzen oder
  zurücksetzen

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
| `day_type` | ENUM('MO-FR','SA','SO','FT','SF') NOT NULL | Kalendertyp (FT nur in Altdaten, neu immer SO) |
| `direction` | VARCHAR(100) NOT NULL | Zielhaltestellenname |
| `manual_course_number` | CHAR(2) NULL | Manuelle Übersteuerung (NULL = keine) |

Unique-Index auf `(period_id, service_nr, line, day_type)`.

---

### 8.3 Tabelle `recordings`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `trip_id` | INT NOT NULL FK | → `trips.id` (trägt `period_id` implizit) |
| `recorded_at` | DATETIME NOT NULL | Zeitstempel der Erfassung (UTC) |
| `hafas_trip_id` | VARCHAR(100) NOT NULL | HAFAS tripId (tagesgebunden) |
| `service_date` | DATE NOT NULL | Betriebsdatum |
| `stop_id` | VARCHAR(20) NOT NULL FK | → `stops.hafas_id` |
| `departure_planned` | DATETIME NOT NULL | Planmäßige Abfahrt |
| `departure_actual` | DATETIME NULL | Echtzeit-Abfahrt (NULL wenn nicht verfügbar) |
| `course_number` | CHAR(2) NOT NULL | Erfasste Kursnummer |

---

### 8.4 Tabelle `route_stops`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | Primärschlüssel |
| `recording_id` | INT NOT NULL FK | → `recordings.id` |
| `sequence` | TINYINT NOT NULL | Position im Laufweg (1, 2, 3 …) |
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

### 8.7 Entity-Relationship-Übersicht

```
schedule_periods (1) ──< trips (1) ──< recordings (1) ──< route_stops
                                               │                 │
                                            stops <──────────────┘

school_holidays  (unabhängig, periodenübergreifend)
stops            (unabhängig, periodenübergreifend)
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
| `GET` | `/api/nearby` | Nahegelegene Tramhaltestellen |
| `GET` | `/api/departures` | Abfahrten an einer Haltestelle |
| `GET` | `/api/trip` | Vollständiger Laufweg eines Kurses |
| `GET` | `/api/calendar` | Wochentagstyp für ein Datum |
| `POST` | `/api/recordings` | Neue Erfassung speichern |
| `GET` | `/api/recordings` | Erfassungen abrufen |
| `GET` | `/api/trips` | Logische Fahrten mit aktiver Kursnummer |
| `GET` | `/api/periods` | Alle Fahrplanperioden |
| `POST` | `/admin/login` | Admin-Login |
| `POST` | `/admin/logout` | Admin-Logout |
| `*` | `/admin/school-holidays` | Schulferien CRUD |
| `*` | `/admin/trips/:id/override` | Kursnummer-Übersteuerung |
| `POST` | `/admin/periods` | Fahrplanschnitt |
| `PUT` | `/admin/periods/:id` | Periode umbenennen / Startdatum korrigieren |

---

## 11. Sicherheitsanforderungen

- Admin-Passwort: `password_hash()` in PHP-Konfigurationsdatei außerhalb Webroot
- Kein Passwort oder Hash im JavaScript
- Kein Passwort im Klartext in der Datenbank
- PHP-Session mit `session_regenerate_id()` nach Login
- Alle Admin-Endpunkte prüfen Session-Status vor Ausführung
- Alle Datenbankzugriffe ausschließlich mit Prepared Statements
- Fahrplanschnitt nur nach explizitem Bestätigungsdialog auslösbar

---

## 12. PWA-Anforderungen

- `manifest.json` mit Name, Icons, `display: standalone`
- Service Worker für Offline-Fähigkeit (mindestens App-Shell cachen)
- Getestet auf Android Chrome

---

## 13. Offene Punkte vor Implementierungsstart

- [ ] Nutzungsrechtliche Klärung INSA HAFAS-API (service@nasa.de)
- [ ] Webhosting prüfen: PHP-Version ≥ 8.0, curl aktiviert, MariaDB-Version
- [ ] Konfigurationsdatei-Ablageort außerhalb Webroot mit Hoster abstimmen
- [ ] Domainstruktur festlegen (Subdomain vs. Unterverzeichnis)
