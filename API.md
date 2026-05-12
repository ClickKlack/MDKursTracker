# API.md – marego Kursnummer-Erfassungs-App

> Version: 1.2 | Stand: 2026-04-14

Alle Endpunkte liefern und erwarten `Content-Type: application/json`.
Zeitangaben immer in ISO 8601 / UTC. Kursnummern immer zweistellig (`"07"`).

---

## Fehlerformat (alle Endpunkte)

```json
{
  "error": "Beschreibung des Fehlers"
}
```

HTTP-Statuscodes: `200 OK`, `201 Created`, `400 Bad Request`,
`401 Unauthorized`, `404 Not Found`, `500 Internal Server Error`.

---

## 1. Frontend-Konfiguration

### GET `/api/config`

Öffentliche Frontend-Konfiguration (keine Authentifizierung erforderlich).

**Erfolg (200):**
```json
{
  "stopNamePrefix": "Magdeburg, "
}
```

`stopNamePrefix` ist leer (`""`), wenn in `config.php` kein `stop_name_prefix` gesetzt ist.
Das Frontend entfernt diesen Präfix vor der Anzeige von Haltestellennamen.

---

### GET `/api/notices`

Liefert alle aktuell anzuzeigenden Nachrichten und den Wartungsstatus.
Öffentlich (keine Authentifizierung). Das Frontend pollt diesen Endpunkt
beim App-Start, periodisch alle 60 s und bei `visibilitychange`.

**Erfolg (200):**
```json
{
  "announcements": [
    {
      "id": 7,
      "body": "App-Update verfügbar",
      "expiresAt": "2026-05-20T22:00:00Z"
    },
    {
      "id": 5,
      "body": "Sonntags Schienenersatzverkehr Linie 6",
      "expiresAt": "2026-05-18T23:59:00Z"
    }
  ],
  "maintenance": {
    "id": 3,
    "message": "Wartungsarbeiten – Erfassen, Bearbeiten und Löschen sind gerade nicht möglich.",
    "startedAt": "2026-05-12T18:30:00Z"
  }
}
```

`announcements` ist immer ein Array – alle Nachrichten mit `expires_at > now`,
sortiert `id DESC`. Das Frontend zeigt die jüngste an, die der User noch nicht
weggeklickt hat (Dismissal pro ID im localStorage); klickt er sie weg, rückt
die nächst-jüngere nach. `maintenance` ist gesetzt, solange eine Wartung läuft
(Zeile mit `ended_at IS NULL`), sonst `null`.

---

## 2. HAFAS-Proxy-Endpunkte

### GET `/api/nearby`

Haltestellen in der Nähe eines GPS-Punkts **oder** per Namenssuche, gefiltert auf Straßenbahnen.
Genau einer der beiden Modi muss angegeben werden.

**Parameter – GPS-Modus:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `lat` | float | ja (GPS) | Breitengrad |
| `lon` | float | ja (GPS) | Längengrad |
| `results` | int | nein | Max. Anzahl Ergebnisse (Standard: 10) |

**Parameter – Namens-Modus:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `name` | string | ja (Name) | Haltestellenname (Kurzform; `stop_name_prefix` wird server-seitig vorangestellt) |
| `results` | int | nein | Max. Anzahl Ergebnisse (Standard: 10) |

**Beispiel-Request GPS:**
```
GET /api/nearby?lat=52.1205&lon=11.6276&results=5
```

**Beispiel-Response GPS:**
```json
[
  {
    "id": "de:15003:4000",
    "name": "Magdeburg, Hauptbahnhof",
    "distance": 142
  }
]
```

**Beispiel-Request Name:**
```
GET /api/nearby?name=Hauptbahnhof
```

**Beispiel-Response Name** (kein `distance`-Feld):
```json
[
  {
    "id": "de:15003:4000",
    "name": "Magdeburg, Hauptbahnhof"
  }
]
```

---

### GET `/api/departures`

Nächste Straßenbahn-Abfahrten an einer Haltestelle.

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `stopId` | string | ja | HAFAS-Haltestellen-ID |
| `results` | int | nein | Max. Anzahl (Standard: 20) |

**Beispiel-Request:**
```
GET /api/departures?stopId=de:15003:4000
```

**Beispiel-Response:**
```json
[
  {
    "hafasTripId": "2|#VN#1#…#ZI#120882#TA#10#…",
    "serviceNr": "120882_10",
    "line": "2",
    "direction": "Westerhüsen",
    "cancelled": false,
    "departurePlanned": "2026-04-12T12:48:00Z",
    "departureActual":  "2026-04-12T12:48:00Z",
    "journeyStart":     "Magdeburg, City Carré",
    "journeyStartTime": "2026-04-12T12:44:00Z",
    "stopId":           "300754301",
    "journeyEnd":       "Magdeburg, Westerhüsen (Betriebshof)",
    "journeyEndTime":   "2026-04-12T13:13:00Z",
    "activeCourseNumber": "07",
    "courseSource": "recorded"
  }
]
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `hafasTripId` | string | HAFAS-Journey-ID |
| `serviceNr` | string | Fahrtennummer (ZI_TA im neuen Format) |
| `line` | string | Linienbezeichnung (aus jid-ZB#, zuverlässiger als prodL) |
| `direction` | string | Richtungstext (Endhaltestellenname, Marketing-Name) |
| `cancelled` | bool | `true` = Fahrt (isCncl) oder Halt (dCncl) ist ausgefallen; Erfassung gesperrt |
| `stopId` | string | Lang-ID des konkreten Bahnsteigs (extId aus HAFAS locL); enthält die Steig-Stelle. Frontend nutzt sie für `pendingCapture.stopId`, damit der Heuristik-Lookup gegen `route_stops.stop_id` matchen kann. |
| `departurePlanned` | string\|null | Geplante Abfahrtszeit (ISO 8601 UTC) |
| `departureActual` | string\|null | Echtzeit-Abfahrtszeit; `null` = keine Echtzeit (klar von "pünktlich" unterschieden — pünktlich heißt `departureActual == departurePlanned`, das Frontend zeigt dafür ein "Live"-Badge) |
| `journeyStart` | string\|null | Name der Starthaltestelle; `null` = nicht im Response verfügbar |
| `journeyStartTime` | string\|null | Abfahrtszeit an der Starthaltestelle (ISO 8601 UTC) |
| `journeyEnd` | string\|null | Name der Endhaltestelle |
| `journeyEndTime` | string\|null | Planmäßige Ankunftszeit an der Endhaltestelle |
| `activeCourseNumber` | string\|null | Kursnummer aus eigener DB; `null` = noch nicht erfasst |
| `courseSource` | string\|null | Quelle der `activeCourseNumber`: `manual` (Override), `recorded` (Mehrheit aus Erfassungen), `heuristic` (eindeutiger route_stops-Treffer) oder `null` |
| `originalLine` | string\|null | Linie zu Fahrtbeginn, wenn unterschiedlich zur aktuellen Linie (durchgebundene Fahrt); `null` = kein Linienwechsel |

---

### GET `/api/trip`

Vollständiger Laufweg eines Kurses (alle planmäßigen Halte).

Wird `serviceDate` angegeben, wird die Fahrt zusätzlich per `schedule_fingerprint` in der DB
aufgelöst: `activeCourseNumber` und `tripId` werden zurückgegeben, und `last_hafas_trip_id`
auf dem Trip wird lazy nachgeführt (Hintergrund-Aktualisierung beim Öffnen des Detail-Views).

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `tripId` | string | ja | HAFAS tripId |
| `serviceDate` | string | nein | Betriebsdatum `YYYY-MM-DD`; aktiviert Fingerprint-Auflösung |

**Beispiel-Request ohne serviceDate (nur Laufweg):**
```
GET /api/trip?tripId=2|%23VN%231%23ZI%23125364%23TA%2346%23...
```

**Beispiel-Response ohne serviceDate:**
```json
[
  {
    "sequence": 1,
    "stopId": "de:15003:3900",
    "stop": "Magdeburg, Westerhüsen",
    "departurePlanned": "2026-03-24T14:10:00Z",
    "departureActual": "2026-03-24T14:11:00Z",
    "line": "1"
  }
]
```

**Beispiel-Request mit serviceDate (Fingerprint-Auflösung):**
```
GET /api/trip?tripId=2|%23VN%231%23ZI%23125364%23TA%2346%23...&serviceDate=2026-04-22
```

**Beispiel-Response mit serviceDate:**
```json
{
  "stops": [
    {
      "sequence": 1,
      "stopId": "de:15003:3900",
      "stop": "Magdeburg, Westerhüsen",
      "departurePlanned": "2026-04-22T14:10:00Z",
      "departureActual": null,
      "line": "1"
    }
  ],
  "tripId": 38,
  "activeCourseNumber": "07",
  "pathFingerprint": "a3f9...64-stelliger Hex-String...",
  "scheduleFingerprint": "c12b...64-stelliger Hex-String..."
}
```

`tripId` und `activeCourseNumber` sind `null`, wenn die Fahrt noch nicht in der DB erfasst wurde.

---

### GET `/api/calendar`

Wochentagstyp für ein konkretes Datum.

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `date` | string | ja | Datum im Format `YYYY-MM-DD` |

**Beispiel-Request:**
```
GET /api/calendar?date=2026-12-25
```

**Beispiel-Response:**
```json
{
  "date": "2026-12-25",
  "dayType": "SO",
  "name": "1. Weihnachtstag"
}
```

---

## 3. Erfassungs-Endpunkte

> **Wartung:** Solange eine Wartung läuft (siehe `GET /api/notices.maintenance`),
> antworten alle schreibenden Endpunkte (POST/PUT/DELETE auf `/api/recordings*`)
> mit HTTP `503` und `{"error":"Wartung läuft – <Hinweistext>"}`. GET-Endpunkte
> bleiben unverändert verfügbar.

### POST `/api/recordings`

Neue Kursnummer-Erfassung speichern. Legt bei Bedarf automatisch eine
logische Fahrt in `trips` an (INSERT IGNORE) und speichert den
vollständigen Laufweg.

**Request-Body:**
```json
{
  "hafasTripId":      "1|12345|0|80|24032026",
  "serviceNr":        "41058",
  "line":             "6",
  "direction":        "Lübecker Str.",
  "stopId":           "de:15003:4000",
  "serviceDate":      "2026-03-24",
  "departurePlanned": "2026-03-24T14:32:00Z",
  "departureActual":  "2026-03-24T14:33:00Z",
  "courseNumber":     "07"
}
```

**`serviceDate`-Auflösung:** Der vom Client gelieferte `serviceDate` wird
serverseitig autoritativ aus dem Trip-Start abgeleitet
(`derive_service_date()`), damit mitternachts­überschreitende Fahrten
zuverlässig dem richtigen Betriebstag zugeordnet werden – eine Sonntag-
Nachtfahrt 23:45 → Mo 01:37 zählt vollständig zum Sonntag (`SO`), auch
wenn der Erfasser an einem Halt nach Mitternacht aussteigt. Die
Frontend-Eingabe gilt nur als Plausibilitäts­hinweis und wird, falls sie
abweicht, im Log vermerkt und durch den abgeleiteten Wert ersetzt.

**Erfolg-Response (201):**
```json
{
  "recordingId": 142,
  "tripId": 38,
  "periodId": 2,
  "dayType": "MO-FR",
  "replacedRecordingIds": [141]
}
```

**Korrektur-Erkennung:** Erfasst derselbe Nutzer (Match per `X-User-Token`)
am gleichen Betriebstag (`serviceDate`), an derselben Haltestelle (`stopId`)
und für dieselbe Plan-Abfahrt (`departurePlanned`) erneut, werden seine
älteren aktiven Erfassungen für genau diese Kombination automatisch
soft-gelöscht (`deleted_at` gesetzt) – das fängt typische Erfassungsfehler
ab, etwa eine korrigierte Kursnummer. Die `course_number` ist bewusst
**kein** Match-Kriterium, sonst würde das Korrigieren gerade nicht greifen.
Anonyme Erfassungen ohne `X-User-Token` werden nicht ersetzt. Die IDs der
ersetzten Erfassungen erscheinen im Feld `replacedRecordingIds`; das Feld
fehlt, wenn keine Ersetzung stattfand.

**Fehler-Beispiele:**
- `400` – Kursnummer nicht im Format `01`–`99`
- `400` – Pflichtfeld fehlt
- `500` – HAFAS-Tripabfrage fehlgeschlagen

---

### GET `/api/recordings`

Erfassungen abrufen, optional gefiltert und paginiert (Infinite Scroll im Verlauf).

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `period_id` | int | nein | Standard: aktive Periode |
| `line` | string | nein | Filter auf Linie |
| `day_type` | string | nein | Filter: MO-FR / SA / SO / SF (FT nur in Altdaten) |
| `date_from` | string | nein | Datumsfilter von (YYYY-MM-DD) |
| `date_to` | string | nein | Datumsfilter bis (YYYY-MM-DD) |
| `limit` | int | nein | 1..200, Default 50 |
| `offset` | int | nein | >= 0, Default 0 |

**Beispiel-Request:**
```
GET /api/recordings?line=6&day_type=MO-FR&limit=50&offset=0
```

Der optionale Header `X-User-Token` (User-Token aus localStorage) aktiviert das `isOwn`-Feld —
der Token selbst erscheint nie in der Antwort.

Soft-gelöschte Erfassungen (`deleted_at` gesetzt; siehe `DELETE /api/recordings/{id}`)
werden in dieser Liste und in allen aggregierten Kursnummer-Berechnungen
(`/api/trips`, `/api/trip`, `/api/departures`, Admin-Endpunkte) ausgeblendet.
Endgültig entfernt werden sie via Lazy-Cleanup im POST-Endpunkt nach 30 Tagen.

Die Sortierung ist `recorded_at DESC, id DESC` (stabil — wichtig für die Pagination,
sonst können Einträge bei gleicher `recorded_at` zwischen zwei Seiten doppelt
oder gar nicht erscheinen).

**Beispiel-Response:**
```json
{
  "items": [
    {
      "id": 142,
      "recordedAt": "2026-03-24T14:33:45Z",
      "line": "6",
      "direction": "Lübecker Str.",
      "serviceNr": "41058",
      "dayType": "MO-FR",
      "serviceDate": "2026-03-24",
      "stopId": "de:15003:4000",
      "stop": "Magdeburg, Hauptbahnhof",
      "departurePlanned": "2026-03-24T14:32:00Z",
      "departureActual":  "2026-03-24T14:33:00Z",
      "courseNumber": "07",
      "activeCourseNumber": "07",
      "manualCourseNumber": null,
      "comment": null,
      "isOwn": false
    }
  ],
  "total":   1234,
  "limit":   50,
  "offset":  0,
  "hasMore": true
}
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `items` | array | Datensätze der aktuellen Seite |
| `total` | int | Gesamtzahl der Treffer (alle Seiten) |
| `limit` | int | Tatsächlich angewendetes Limit (geclampt) |
| `offset` | int | Echo des Request-Offsets |
| `hasMore` | bool | `(offset + items.length) < total` |
| `items[].comment` | string\|null | Kommentar zur Erfassung |
| `items[].isOwn` | bool | `true` wenn `X-User-Token` mit dem erfassenden User übereinstimmt |

---

### PUT `/api/recordings/{id}`

Eigene Erfassung nachträglich bearbeiten (Kursnummer und/oder Kommentar).
Nur für Erfassungen der aktiven Periode. Erfordert Header `X-User-Token`.

**Request-Header:** `X-User-Token: <token>`

**Request-Body** (mindestens ein Feld):
```json
{
  "courseNumber": "07",
  "comment": "Fahrzeug war ein Tatra T4D."
}
```

`comment: null` löscht den vorhandenen Kommentar.

**Erfolg (200):**
```json
{ "ok": true }
```

**Fehler-Beispiele:**
- `401` – `X-User-Token`-Header fehlt oder ungültig
- `403` – Erfassung gehört einem anderen User
- `403` – Erfassung liegt in einer abgeschlossenen Periode
- `404` – Erfassung nicht gefunden
- `409` – Erfassung ist soft-gelöscht (erst Restore aufrufen)

---

### DELETE `/api/recordings/{id}`

Eigene Erfassung soft-löschen. Setzt `deleted_at = UTC_TIMESTAMP()`; der Datensatz
verschwindet aus allen Lese-Endpunkten, kann aber via `POST /api/recordings/{id}/restore`
wiederhergestellt werden (Undo aus der Snackbar im Verlauf-View). Datenbank-Bereinigung
erfolgt via Lazy-Cleanup im POST-Endpunkt nach 30 Tagen.

Identische Berechtigungsregeln wie `PUT`: nur eigene Erfassung in der aktiven Periode.

**Request-Header:** `X-User-Token: <token>`

**Erfolg (200):**
```json
{ "ok": true }
```

Bei bereits soft-gelöschtem Datensatz idempotent:
```json
{ "ok": true, "alreadyDeleted": true }
```

**Fehler-Beispiele:**
- `401` – `X-User-Token`-Header fehlt oder ungültig
- `403` – Erfassung gehört einem anderen User
- `403` – Erfassung liegt in einer abgeschlossenen Periode
- `404` – Erfassung nicht gefunden

---

### POST `/api/recordings/{id}/restore`

Soft-Delete rückgängig machen (Undo). Wird vom Frontend aus dem
„Rückgängig"-Button der Snackbar aufgerufen, kann aber auch ein
versehentlich gelöschtes Recording manuell wiederherstellen.

Identische Berechtigungsregeln wie `DELETE`.

**Request-Header:** `X-User-Token: <token>`

**Erfolg (200):**
```json
{ "ok": true }
```

Auf einen bereits aktiven Datensatz idempotent:
```json
{ "ok": true, "wasActive": true }
```

**Fehler-Beispiele:**
- `401` – `X-User-Token`-Header fehlt oder ungültig
- `403` – Erfassung gehört einem anderen User
- `403` – Erfassung liegt in einer abgeschlossenen Periode
- `404` – Erfassung nicht gefunden

---

### GET `/api/recordings/{id}/route`

Laufweg einer gespeicherten Erfassung (aus `route_stops`-Tabelle).
Gibt 404 zurück wenn kein Laufweg gespeichert ist (ältere Erfassungen
ohne HAFAS-Abfrage) oder die Erfassung nicht existiert.

**Beispiel-Response:**
```json
[
  { "sequence": 1,  "stopId": "de:15003:1000", "name": "Magdeburg, Alte Neustadt",    "departurePlanned": "2026-03-24T14:10:00Z", "isRecordingStop": false },
  { "sequence": 2,  "stopId": "de:15003:4000", "name": "Magdeburg, Hauptbahnhof",     "departurePlanned": "2026-03-24T14:32:00Z", "isRecordingStop": true,  "line": "1" },
  { "sequence": 3,  "stopId": "de:15003:5000", "name": "Magdeburg, Universitätsplatz","departurePlanned": "2026-03-24T14:38:00Z", "isRecordingStop": false, "line": "5" }
]
```

`departurePlanned` ist `null` beim letzten Halt (nur Ankunft).
`line` ist `null` wenn HAFAS keine Linieninformation je Halt geliefert hat (best-effort).

---

### POST `/api/trips/touch`

Verlinkt eine HAFAS-Fahrt per `schedule_fingerprint` mit einem bestehenden
Trip-Datensatz. Aktualisiert dort `last_hafas_trip_id` und `service_nr`,
ohne eine Erfassung anzulegen. Wird vom Frontend beim Öffnen der
Capture-View aufgerufen, damit die Abfahrtstafel die zugeordnete
Kursnummer auch dann anzeigt, wenn HAFAS für dieselbe Fahrt eine neue
`tripId`/`serviceNr` ausgibt. Legt **keinen** neuen Trip an. Bei fehlendem
Fingerprint-Match (`reason: "no_trip"`) wird zusätzlich ein heuristischer
Lookup über `route_stops` versucht; bei eindeutigem Treffer enthält die
Antwort `heuristicCourseNumber` als Vorschlag für die Capture-View.

**Request-Body:**
```json
{
  "hafasTripId":      "1|12345|0|80|24032026",
  "serviceNr":        "41058",
  "line":             "6",
  "stopId":           "de:15003:4000",
  "departurePlanned": "2026-03-24T14:32:00Z"
}
```

`stopId` und `departurePlanned` werden für den heuristischen Fallback
benötigt. `departurePlanned` muss ISO-8601-UTC sein.

**Erfolg-Response (200) – Match per Fingerprint:**
```json
{
  "matched": true,
  "tripId": 38,
  "updated": true,
  "activeCourseNumber": "07",
  "courseSource": "recorded"
}
```

`courseSource`: `manual` (Trip hat Override), `recorded` (Mehrheit aus
Erfassungen) oder `null` (Trip existiert, aber keine Recordings).

**Antwort bei matched=false mit Heuristik-Treffer:**
```json
{
  "matched": false,
  "reason": "no_trip",
  "heuristicCourseNumber": "07",
  "heuristicCourseSource": "heuristic",
  "heuristicTripId": 42
}
```

`heuristicCourseSource`: `heuristic` oder `manual` (wenn der heuristisch
gefundene Trip einen Override hat).

Bei `matched: false` enthält die Antwort einen `reason`:
- `no_trip` – kein Trip mit passendem Fingerprint vorhanden
  (heuristische Felder dann ggf. zusätzlich gesetzt)
- `no_fingerprint` – Fahrt hat zu wenige Halte mit Zeitangabe
- `no_service_date` – Betriebsdatum nicht ableitbar
- `hafas_error` – HAFAS-Anfrage fehlgeschlagen (best-effort, kein 5xx)

**Fehler:**
- `400` – Pflichtfeld fehlt oder Feldlänge überschritten
- `400` – `departurePlanned` nicht im Format `YYYY-MM-DDTHH:MM:SSZ`

---

### GET `/api/trips`

Logische Fahrten einer Periode mit berechneter aktiver Kursnummer.

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `period_id` | int | nein | Standard: aktive Periode |

**Beispiel-Response:**
```json
[
  {
    "id": 38,
    "periodId": 2,
    "serviceNr": "41058",
    "line": "6",
    "dayType": "MO-FR",
    "direction": "Lübecker Str.",
    "activeCourseNumber": "07",
    "manualCourseNumber": null,
    "recordingCount": 5
  }
]
```

---

### GET `/api/periods`

Alle Fahrplanperioden auflisten.

**Beispiel-Response:**
```json
[
  {
    "id": 1,
    "name": "Fahrplan (initial)",
    "startDate": "2026-01-01",
    "createdAt": "2026-01-01T08:00:00Z",
    "recordingCount": 312,
    "active": false
  },
  {
    "id": 2,
    "name": "Fahrplan 2026/2027",
    "startDate": "2026-12-14",
    "createdAt": "2026-12-14T06:00:00Z",
    "recordingCount": 47,
    "active": true
  }
]
```

---

## 4. User-Endpunkte

Alle User-Endpunkte erfordern den Header `X-User-Token` mit einem 32-stelligen Hex-Token
(UUID v4 ohne Bindestriche, generiert im Frontend via `crypto.randomUUID()`).

---

### POST `/api/user`

Token registrieren bzw. `last_seen_at` aktualisieren. Legt den User beim ersten Aufruf an.

**Request-Header:** `X-User-Token: <token>`

**Erfolg-Response bei Neuanlage (201):**
```json
{ "displayId": "A3K7F", "isNew": true }
```

**Erfolg-Response bei bestehendem User (200):**
```json
{ "displayId": "A3K7F", "isNew": false }
```

**Fehler:** `401` – Token fehlt oder ungültig

---

### GET `/api/user`

Eigenes Profil laden.

**Request-Header:** `X-User-Token: <token>`

**Erfolg (200):**
```json
{
  "displayId": "A3K7F",
  "name": "Testnutzer",
  "createdAt": "2026-04-01T10:00:00Z"
}
```

`name` ist `null` wenn noch kein Profilname gesetzt wurde.

**Fehler:** `401` – Token ungültig | `404` – Token nicht in DB

---

### PUT `/api/user`

Profilname setzen oder löschen.

**Request-Header:** `X-User-Token: <token>`

**Request-Body:**
```json
{ "name": "Testnutzer" }
```

Leerer String (`""`) löscht den Profilnamen (setzt auf `null`).
Maximallänge: 100 Zeichen.

**Erfolg (200):** `{ "ok": true }`

**Fehler:** `400` – Name zu lang | `401` – Token ungültig | `404` – Token nicht in DB

---

### GET `/api/user/favorites`

Favoriten-Haltestellen laden.

**Request-Header:** `X-User-Token: <token>`

**Erfolg (200):**
```json
[
  {
    "stopId": "de:15003:4000",
    "stopName": "Magdeburg, Hauptbahnhof",
    "createdAt": "2026-04-01T10:05:00Z"
  }
]
```

---

### POST `/api/user/favorites`

Favorit hinzufügen.

**Request-Header:** `X-User-Token: <token>`

**Request-Body:**
```json
{ "stopId": "de:15003:4000", "stopName": "Magdeburg, Hauptbahnhof" }
```

**Erfolg (201):** `{ "ok": true }` (auch bei bereits vorhandenem Favorit – idempotent)

**Fehler:** `400` – Pflichtfeld fehlt / `stopId` > 20 Zeichen | `401` – Token ungültig | `404` – Token nicht in DB

---

### DELETE `/api/user/favorites/{stop_id}`

Favorit entfernen. URL-Encoded stop_id im Pfad.

**Request-Header:** `X-User-Token: <token>`

**Beispiel-Request:**
```
DELETE /api/user/favorites/de%3A15003%3A4000
```

**Erfolg (200):** `{ "ok": true }` (auch wenn Favorit nicht vorhanden – idempotent)

**Fehler:** `400` – stop_id fehlt | `401` – Token ungültig

---

## 5. Admin-Endpunkte

Alle Admin-Endpunkte erfordern eine aktive PHP-Session (Login).
Ohne gültige Session: HTTP `401 Unauthorized`.

---

### POST `/admin/login`

**Request-Body:**
```json
{ "password": "geheimesPasswort" }
```

**Erfolg (200):**
```json
{ "ok": true }
```

**Fehler (401):**
```json
{ "error": "Ungültiges Passwort" }
```

---

### POST `/admin/logout`

**Response (200):**
```json
{ "ok": true }
```

---

### GET `/admin/school-holidays`

```json
[
  {
    "id": 1,
    "name": "Sommerferien 2026",
    "dateFrom": "2026-06-25",
    "dateTo": "2026-08-07"
  }
]
```

---

### POST `/admin/school-holidays`

**Request-Body:**
```json
{
  "name": "Herbstferien 2026",
  "dateFrom": "2026-10-10",
  "dateTo": "2026-10-24"
}
```

**Erfolg (201):**
```json
{ "id": 2 }
```

---

### PUT `/admin/school-holidays/:id`

**Request-Body:** wie POST. **Erfolg:** `200 { "ok": true }`.

---

### DELETE `/admin/school-holidays/:id`

**Erfolg:** `200 { "ok": true }`.

---

### PUT `/admin/trips/:id/override`

Manuelle Kursnummer setzen.

**Request-Body:**
```json
{ "courseNumber": "12" }
```

**Erfolg:** `200 { "ok": true }`.

---

### DELETE `/admin/trips/:id/override`

Manuelle Übersteuerung zurücksetzen (zurück zur Mehrheitsregel).

**Erfolg:** `200 { "ok": true }`.

---

### POST `/admin/periods`

Fahrplanschnitt: neue Periode anlegen.

**Request-Body:**
```json
{
  "name": "Fahrplan 2027/2028",
  "startDate": "2027-12-12"
}
```

**Erfolg (201):**
```json
{
  "id": 3,
  "name": "Fahrplan 2027/2028",
  "startDate": "2027-12-12",
  "createdAt": "2027-12-12T05:55:00Z"
}
```

---

### PUT `/admin/periods/:id`

Bezeichnung oder Startdatum einer Periode nachträglich korrigieren
(primär für die initiale Periode gedacht).

**Request-Body:**
```json
{
  "name": "Fahrplan 2025/2026",
  "startDate": "2025-12-15"
}
```

**Erfolg:** `200 { "ok": true }`.

---

### GET `/admin-api/trips/:id/recordings`

Alle Einzelerfassungen einer logischen Fahrt laden (für das Admin-Accordion).

**Erfolg (200):**
```json
[
  {
    "id": 142,
    "recordedAt": "2026-04-01T14:33:45Z",
    "courseNumber": "07",
    "stopId": "de:15003:4000",
    "stopName": "Magdeburg, Hauptbahnhof",
    "comment": "Tatra T4D",
    "userName": "Testnutzer",
    "userDisplayId": "A3K7F",
    "userDevice": "Chrome 124 / Android 14"
  }
]
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `userName` | string\|null | Profilname des Users; `null` wenn kein Name gesetzt |
| `userDisplayId` | string\|null | 5-stellige Base36-ID; `null` für Altdaten ohne Token |
| `userDevice` | string\|null | Gerätekurzname (Browser + OS); `null` für Altdaten |
| `comment` | string\|null | Nutzerkommentar zur Erfassung |

Sortierung: `recorded_at DESC`. Leeres Array wenn keine Erfassungen vorhanden.

**Fehler:** `401` – keine Admin-Session | `403` – keine Berechtigung

---

### GET `/admin-api/trip-group-detail`

Laufweg und Abfahrtszeiten für eine Gruppe logischer Fahrten (für den Accordion im Fahrten-Tab).
Grundlage ist je Trip die neuste Erfassung mit gespeicherten `route_stops`.

**Parameter:**

| Parameter | Pflicht | Beschreibung |
|---|---|---|
| `trip_ids` | ja | Kommagetrennte Trip-IDs (positive Ganzzahlen, max. 50) |

**Beispiel:**
```
GET /admin-api/trip-group-detail?trip_ids=1,2,3
```

**Erfolg (200):**
```json
{
  "stops": [
    { "stopId": "de:15003:4000", "stopName": "Magdeburg, Hauptbahnhof", "sequence": 1 },
    { "stopId": "de:15003:4001", "stopName": "Magdeburg, Marktplatz",   "sequence": 2 }
  ],
  "trips": [
    {
      "id": 1,
      "serviceNr": "125364_46",
      "activeCourseNumber": "12",
      "manualCourseNumber": null,
      "departures": {
        "de:15003:4000": "07:15",
        "de:15003:4001": "07:18"
      }
    }
  ]
}
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `stops` | array | Kanonische Haltestellenliste (längster Laufweg aller Trips) |
| `stops[].stopId` | string | HAFAS-ID der Haltestelle |
| `stops[].stopName` | string | Anzeigename der Haltestelle |
| `stops[].sequence` | int | Position im Laufweg (1-basiert) |
| `trips` | array | Trips, sortiert nach TA-Teil des `serviceNr` (chronologisch) |
| `trips[].activeCourseNumber` | string\|null | Aktive Kursnummer (Override oder Mehrheitsregel) |
| `trips[].manualCourseNumber` | string\|null | Manuelle Übersteuerung; `null` wenn keine |
| `trips[].departures` | object | Map stop_id → Abfahrtszeit `HH:MM`; fehlende Halte fehlen im Objekt |

Kanonische Stop-Liste ist leer, wenn kein Trip gespeicherte `route_stops` hat.

**Fehler:** `400` – `trip_ids` fehlt oder ungültig | `401` – keine Admin-Session

---

### GET `/admin-api/announcements`

Liste aller Nachrichten (für die Admin-UI; abgelaufene werden mitgeliefert,
das Filtern auf die aktuelle übernimmt `GET /api/notices`).

**Erfolg (200):** Array von Objekten, sortiert `created_at DESC`:
```json
[
  {
    "id": 7,
    "body": "Heute Abend kein Spätbetrieb",
    "expiresAt": "2026-05-20T22:00:00Z",
    "createdAt": "2026-05-12T18:00:00Z"
  }
]
```

**Fehler:** `401` – keine Admin-Session

---

### POST `/admin-api/announcements`

Neue Nachricht anlegen.

**Request-Body:**
```json
{
  "body":      "Heute Abend kein Spätbetrieb",
  "expiresAt": "2026-05-20T22:00:00Z"
}
```

`expiresAt` muss ein gültiges ISO-8601-Datum sein (UTC; das Frontend
konvertiert die lokale Eingabe).

**Erfolg (201):** `{ "id": 7 }`

**Fehler:** `400` – Pflichtfeld fehlt / ungültiges Datum | `401` – keine Admin-Session

---

### PUT `/admin-api/announcements/:id`

Nachricht aktualisieren.

**Request-Body:** wie POST. Beide Felder sind Pflicht.

**Erfolg (200):** `{ "ok": true }`

**Fehler:** `400` – Pflichtfeld fehlt | `401` – keine Admin-Session | `404` – Nachricht nicht gefunden

---

### DELETE `/admin-api/announcements/:id`

Nachricht löschen.

**Erfolg (200):** `{ "ok": true }`

**Fehler:** `401` – keine Admin-Session | `404` – Nachricht nicht gefunden

---

### GET `/admin-api/maintenance`

Aktuellen Wartungsstatus + Historie der letzten 20 Wartungsfenster abrufen.

**Erfolg (200):**
```json
{
  "active": {
    "id": 3,
    "message": "Wartungsarbeiten – Erfassen, Bearbeiten und Löschen sind gerade nicht möglich.",
    "startedAt": "2026-05-12T18:30:00Z"
  },
  "history": [
    {
      "id": 3,
      "message": "Wartungsarbeiten – ...",
      "startedAt": "2026-05-12T18:30:00Z",
      "endedAt": null
    },
    {
      "id": 2,
      "message": "Datenbank-Update",
      "startedAt": "2026-05-05T20:00:00Z",
      "endedAt": "2026-05-05T20:42:00Z"
    }
  ]
}
```

`active` ist `null`, wenn gerade keine Wartung läuft.

**Fehler:** `401` – keine Admin-Session

---

### POST `/admin-api/maintenance`

Wartung starten. Setzt eine Zeile mit `started_at = UTC_TIMESTAMP()` und
`ended_at = NULL`. Solange diese Zeile existiert, blockiert das Backend
schreibende `/api/recordings*`-Aufrufe mit HTTP `503`.

**Request-Body:**
```json
{ "message": "Datenbank-Upgrade läuft, ca. 30 Minuten" }
```

`message`: 1–500 Zeichen, Pflichtfeld. Wird im Wartungs-Banner der App
angezeigt.

**Erfolg (201):**
```json
{
  "id": 4,
  "active": {
    "id": 4,
    "message": "Datenbank-Upgrade läuft, ca. 30 Minuten",
    "startedAt": "2026-05-12T18:30:12Z"
  }
}
```

**Fehler:** `400` – Pflichtfeld fehlt / zu lang | `401` – keine Admin-Session |
`409` – Es läuft bereits eine Wartung (Response enthält `active`-Payload der
laufenden)

---

### POST `/admin-api/maintenance/end`

Aktive Wartung beenden. Setzt `ended_at = UTC_TIMESTAMP()` für die offene Zeile.

**Erfolg (200):** `{ "ok": true }`

**Fehler:** `401` – keine Admin-Session | `404` – keine aktive Wartung vorhanden
