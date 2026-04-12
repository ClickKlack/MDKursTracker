# API.md – marego Kursnummer-Erfassungs-App

> Version: 1.1 | Stand: 2026-03-24

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

## 2. HAFAS-Proxy-Endpunkte

### GET `/api/nearby`

Haltestellen in der Nähe eines GPS-Punkts, gefiltert auf Straßenbahnen.

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `lat` | float | ja | Breitengrad |
| `lon` | float | ja | Längengrad |
| `results` | int | nein | Max. Anzahl Ergebnisse (Standard: 10) |

**Beispiel-Request:**
```
GET /api/nearby?lat=52.1205&lon=11.6276&results=5
```

**Beispiel-Response:**
```json
[
  {
    "id": "de:15003:4000",
    "name": "Magdeburg, Hauptbahnhof",
    "distance": 142
  },
  {
    "id": "de:15003:4001",
    "name": "Magdeburg, Breiter Weg",
    "distance": 310
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
    "departurePlanned": "2026-04-12T12:48:00Z",
    "departureActual":  "2026-04-12T12:48:00Z",
    "journeyStart":     "Magdeburg, City Carré",
    "journeyStartTime": "2026-04-12T12:44:00Z",
    "journeyEnd":       "Magdeburg, Westerhüsen (Betriebshof)",
    "journeyEndTime":   "2026-04-12T13:13:00Z",
    "activeCourseNumber": "07"
  }
]
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `hafasTripId` | string | HAFAS-Journey-ID |
| `serviceNr` | string | Fahrtennummer (ZI_TA im neuen Format) |
| `line` | string | Linienbezeichnung (aus jid-ZB#, zuverlässiger als prodL) |
| `direction` | string | Richtungstext (Endhaltestellenname, Marketing-Name) |
| `departurePlanned` | string\|null | Geplante Abfahrtszeit (ISO 8601 UTC) |
| `departureActual` | string\|null | Echtzeit-Abfahrtszeit; `null` = keine Echtzeit |
| `journeyStart` | string\|null | Name der Starthaltestelle; `null` = nicht im Response verfügbar |
| `journeyStartTime` | string\|null | Abfahrtszeit an der Starthaltestelle (ISO 8601 UTC) |
| `journeyEnd` | string\|null | Name der Endhaltestelle |
| `journeyEndTime` | string\|null | Planmäßige Ankunftszeit an der Endhaltestelle |
| `activeCourseNumber` | string\|null | Kursnummer aus eigener DB; `null` = noch nicht erfasst |
| `originalLine` | string\|null | Linie zu Fahrtbeginn, wenn unterschiedlich zur aktuellen Linie (durchgebundene Fahrt); `null` = kein Linienwechsel |

---

### GET `/api/trip`

Vollständiger Laufweg eines Kurses (alle planmäßigen Halte).

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `tripId` | string | ja | HAFAS tripId |

**Beispiel-Request:**
```
GET /api/trip?tripId=1|12345|0|80|24032026
```

**Beispiel-Response:**
```json
[
  {
    "sequence": 1,
    "stopId": "de:15003:3900",
    "stop": "Magdeburg, Westerhüsen",
    "departurePlanned": "2026-03-24T14:10:00Z"
  },
  {
    "sequence": 2,
    "stopId": "de:15003:3901",
    "stop": "Magdeburg, Salbker Chaussee",
    "departurePlanned": "2026-03-24T14:12:00Z"
  },
  {
    "sequence": 14,
    "stopId": "de:15003:4005",
    "stop": "Magdeburg, Lübecker Str.",
    "departurePlanned": null
  }
]
```

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

**Erfolg-Response (201):**
```json
{
  "recordingId": 142,
  "tripId": 38,
  "periodId": 2,
  "dayType": "MO-FR"
}
```

**Fehler-Beispiele:**
- `400` – Kursnummer nicht im Format `01`–`99`
- `400` – Pflichtfeld fehlt
- `500` – HAFAS-Tripabfrage fehlgeschlagen

---

### GET `/api/recordings`

Alle Erfassungen abrufen, optional gefiltert.

**Parameter:**

| Name | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `period_id` | int | nein | Standard: aktive Periode |
| `line` | string | nein | Filter auf Linie |
| `day_type` | string | nein | Filter: MO-FR / SA / SO / SF (FT nur in Altdaten) |
| `date_from` | string | nein | Datumsfilter von (YYYY-MM-DD) |
| `date_to` | string | nein | Datumsfilter bis (YYYY-MM-DD) |

**Beispiel-Request:**
```
GET /api/recordings?line=6&day_type=MO-FR
```

**Beispiel-Response:**
```json
[
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
    "manualCourseNumber": null
  }
]
```

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

## 4. Admin-Endpunkte

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
