/**
 * api.js – fetch()-Wrapper für alle Backend-Aufrufe
 *
 * Alle Funktionen geben normalisierte JS-Objekte zurück.
 * Bei HTTP-Fehlern wird ein Error mit der Backend-Fehlermeldung geworfen.
 */

// Basis-URL: leer = gleiche Origin (kein CORS-Problem bei lokalem Deployment)
const BASE_URL = '';

/**
 * Interner Helfer: fetch mit einheitlicher Fehlerbehandlung.
 * Fügt automatisch den X-User-Token-Header hinzu, wenn ein Token in
 * localStorage vorhanden ist.
 * @param {string} url
 * @param {RequestInit} [options]
 * @returns {Promise<any>}
 */
async function apiFetch(url, options = {}) {
    const token   = localStorage.getItem('user_token') ?? '';
    const headers = { 'Content-Type': 'application/json' };
    if (token) {
        headers['X-User-Token'] = token;
    }
    const defaults = { headers };

    // Netzwerkfehler (kein Server erreichbar) sauber abfangen
    let response;
    try {
        response = await fetch(BASE_URL + url, { ...defaults, ...options });
    } catch (networkErr) {
        throw new Error(`Netzwerkfehler: ${networkErr.message}`);
    }

    // Leere Antwort (z.B. bei 204) direkt zurückgeben
    if (response.status === 204) {
        return null;
    }

    // Antwort zuerst als Text lesen – so kann bei einem Parse-Fehler die
    // rohe Antwort (PHP-Fehlermeldung, HTML-Fehlerseite o.Ä.) geloggt werden.
    const text = await response.text();

    let data;
    try {
        data = JSON.parse(text);
    } catch {
        // Rohe Antwort in der Konsole ausgeben – hilft beim Debuggen
        console.error(`[API] Keine JSON-Antwort für ${url} (HTTP ${response.status}):`,
            text.slice(0, 800));
        const err = new Error(
            response.ok
                ? `Ungültige Serverantwort (kein JSON) – Details in der Konsole`
                : `Server-Fehler ${response.status} – Details in der Konsole`
        );
        err.status = response.status;
        throw err;
    }

    if (!response.ok) {
        // Backend sendet immer { "error": "..." }
        const message = data?.error ?? `HTTP ${response.status}`;
        const err = new Error(message);
        err.status = response.status;
        throw err;
    }

    return data;
}

// =============================================================================
// HAFAS-Proxy-Endpunkte
// =============================================================================

/**
 * Haltestellen in der Nähe eines GPS-Punkts abrufen (nur Tram).
 * @param {number} lat
 * @param {number} lon
 * @param {number} [results=10]
 * @returns {Promise<Array<{id:string, name:string, distance:number}>>}
 */
export async function getNearby(lat, lon, results = 10) {
    const params = new URLSearchParams({ lat, lon, results });
    return apiFetch(`/api/nearby?${params}`);
}

/**
 * Haltestellen per Name suchen (nur Tram).
 * Stadtpräfix wird serverseitig automatisch vorangestellt.
 * @param {string} name  Suchbegriff (z.B. "Hauptbahnhof")
 * @param {number} [results=10]
 * @returns {Promise<Array<{id:string, name:string}>>}
 */
export async function getNearbyByName(name, results = 10) {
    const params = new URLSearchParams({ name, results });
    return apiFetch(`/api/nearby?${params}`);
}

/**
 * Nächste Straßenbahn-Abfahrten an einer Haltestelle.
 * @param {string} stopId
 * @param {number} [results=20]
 * @returns {Promise<Array>}
 */
export async function getDepartures(stopId, results = 20) {
    const params = new URLSearchParams({ stopId, results });
    return apiFetch(`/api/departures?${params}`);
}

/**
 * Vollständigen Laufweg eines Kurses abrufen.
 * @param {string} tripId  HAFAS tripId
 * @returns {Promise<Array>}
 */
export async function getTrip(tripId) {
    const params = new URLSearchParams({ tripId });
    return apiFetch(`/api/trip?${params}`);
}

/**
 * Wochentagstyp für ein konkretes Datum ermitteln.
 * @param {string} date  Format YYYY-MM-DD
 * @returns {Promise<{date:string, dayType:string, name:string|null}>}
 */
export async function getCalendar(date) {
    const params = new URLSearchParams({ date });
    return apiFetch(`/api/calendar?${params}`);
}

// =============================================================================
// Erfassungs-Endpunkte
// =============================================================================

/**
 * Neue Kursnummer-Erfassung speichern.
 * @param {{
 *   hafasTripId:      string,
 *   serviceNr:        string,
 *   line:             string,
 *   direction:        string,
 *   stopId:           string,
 *   serviceDate:      string,
 *   departurePlanned: string,
 *   departureActual:  string|null,
 *   courseNumber:     string
 * }} data
 * @returns {Promise<{recordingId:number, tripId:number, periodId:number, dayType:string, replacedRecordingIds?:number[]}>}
 */
export async function postRecording(data) {
    return apiFetch('/api/recordings', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

/**
 * Laufweg einer gespeicherten Erfassung abrufen (aus route_stops).
 * @param {number} recordingId
 * @returns {Promise<Array<{sequence:number, stopId:string, name:string, departurePlanned:string|null, isRecordingStop:boolean}>>}
 */
export async function getRecordingRoute(recordingId) {
    return apiFetch(`/api/recordings/${recordingId}/route`);
}

/**
 * Eigene Erfassung bearbeiten (Kursnummer und/oder Kommentar).
 * Nur in der aktiven Periode möglich.
 * @param {number} recordingId
 * @param {{courseNumber?: string, comment?: string|null}} data
 * @returns {Promise<{ok:boolean}>}
 */
export async function putRecording(recordingId, data) {
    return apiFetch(`/api/recordings/${recordingId}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

/**
 * Eigene Erfassung soft-löschen. Wiederherstellung über restoreRecording().
 * Nur in der aktiven Periode möglich.
 * @param {number} recordingId
 * @returns {Promise<{ok:boolean, alreadyDeleted?:boolean}>}
 */
export async function deleteRecording(recordingId) {
    return apiFetch(`/api/recordings/${recordingId}`, { method: 'DELETE' });
}

/**
 * Soft-Delete einer eigenen Erfassung rückgängig machen (Undo aus Snackbar).
 * @param {number} recordingId
 * @returns {Promise<{ok:boolean, wasActive?:boolean}>}
 */
export async function restoreRecording(recordingId) {
    return apiFetch(`/api/recordings/${recordingId}/restore`, { method: 'POST' });
}

/**
 * Erfassungen abrufen, optional gefiltert und paginiert.
 *
 * Antwort: `{ items, total, limit, offset, hasMore }`. `items` sind die
 * Datensätze der angefragten Seite (sortiert: neueste zuerst, stabil per id).
 *
 * @param {{
 *   period_id?: number,
 *   line?:      string,
 *   day_type?:  string,
 *   date_from?: string,
 *   date_to?:   string,
 *   limit?:     number,
 *   offset?:    number
 * }} [filters={}]
 * @returns {Promise<{items:Array, total:number, limit:number, offset:number, hasMore:boolean}>}
 */
export async function getRecordings(filters = {}) {
    const params = new URLSearchParams(
        // Leere Werte weglassen
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null))
    );
    const query = params.toString() ? `?${params}` : '';
    return apiFetch(`/api/recordings${query}`);
}

/**
 * HAFAS-Fahrt per Fingerprint mit bestehendem Trip verlinken.
 * Aktualisiert last_hafas_trip_id und service_nr am gefundenen Trip;
 * legt nichts neu an. Bei Miss zusätzlich heuristischer route_stops-Lookup.
 * Best-effort – Fehler werden vom Caller ignoriert.
 * @param {{
 *   hafasTripId:string, serviceNr:string, line:string,
 *   stopId:string, departurePlanned:string
 * }} data
 * @returns {Promise<{
 *   matched:boolean, tripId?:number, updated?:boolean,
 *   activeCourseNumber?:string|null, courseSource?:string|null,
 *   heuristicCourseNumber?:string, heuristicCourseSource?:string, heuristicTripId?:number,
 *   reason?:string
 * }>}
 */
export async function postTouchTrip(data) {
    return apiFetch('/api/trips/touch', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

/**
 * Logische Fahrten einer Periode mit berechneter aktiver Kursnummer.
 * @param {number} [periodId]  Standard: aktive Periode
 * @returns {Promise<Array>}
 */
export async function getTrips(periodId) {
    const params = periodId != null ? new URLSearchParams({ period_id: periodId }) : null;
    const query = params ? `?${params}` : '';
    return apiFetch(`/api/trips${query}`);
}

/**
 * Alle Fahrplanperioden auflisten.
 * @returns {Promise<Array<{id:number, name:string, startDate:string, createdAt:string, recordingCount:number, active:boolean}>>}
 */
export async function getPeriods() {
    return apiFetch('/api/periods');
}

/**
 * Öffentliche Frontend-Konfiguration vom Backend laden.
 * @returns {Promise<{stopNamePrefix:string}>}
 */
export async function getConfig() {
    return apiFetch('/api/config');
}

// =============================================================================
// User-Endpunkte
// =============================================================================

/**
 * User-Token registrieren oder last_seen_at aktualisieren.
 * Wird beim App-Start aufgerufen (silent – kein Fehler bei Offline).
 * @returns {Promise<{displayId:string, isNew:boolean}>}
 */
export async function postUserInit() {
    return apiFetch('/api/user', { method: 'POST' });
}

/**
 * Eigenes Profil laden.
 * @returns {Promise<{displayId:string, name:string|null, createdAt:string}>}
 */
export async function getUserProfile() {
    return apiFetch('/api/user');
}

/**
 * Profilname setzen oder löschen.
 * @param {string|null} name  null oder '' zum Löschen
 * @returns {Promise<{ok:boolean}>}
 */
export async function putUserProfile(name) {
    return apiFetch('/api/user', {
        method: 'PUT',
        body: JSON.stringify({ name }),
    });
}

/**
 * Favoriten-Haltestellen laden.
 * @returns {Promise<Array<{stopId:string, stopName:string, createdAt:string}>>}
 */
export async function getUserFavorites() {
    return apiFetch('/api/user/favorites');
}

/**
 * Haltestelle als Favorit speichern.
 * @param {string} stopId
 * @param {string} stopName
 * @returns {Promise<{ok:boolean}>}
 */
export async function postUserFavorite(stopId, stopName) {
    return apiFetch('/api/user/favorites', {
        method: 'POST',
        body: JSON.stringify({ stopId, stopName }),
    });
}

/**
 * Haltestelle aus Favoriten entfernen.
 * @param {string} stopId
 * @returns {Promise<{ok:boolean}>}
 */
export async function deleteUserFavorite(stopId) {
    return apiFetch(`/api/user/favorites/${encodeURIComponent(stopId)}`, { method: 'DELETE' });
}

// =============================================================================
// Admin-Endpunkte
// =============================================================================

/**
 * Admin-Login.
 * @param {string} password
 * @returns {Promise<{ok:boolean}>}
 */
export async function adminLogin(password) {
    return apiFetch('/admin-api/login', {
        method: 'POST',
        body: JSON.stringify({ password }),
    });
}

/**
 * Admin-Logout.
 * @returns {Promise<{ok:boolean}>}
 */
export async function adminLogout() {
    return apiFetch('/admin-api/logout', { method: 'POST' });
}

/**
 * Schulferien auflisten.
 * @returns {Promise<Array>}
 */
export async function getSchoolHolidays() {
    return apiFetch('/admin-api/school-holidays');
}

/**
 * Schulferien anlegen.
 * @param {{name:string, dateFrom:string, dateTo:string}} data
 * @returns {Promise<{id:number}>}
 */
export async function createSchoolHoliday(data) {
    return apiFetch('/admin-api/school-holidays', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

/**
 * Schulferien aktualisieren.
 * @param {number} id
 * @param {{name:string, dateFrom:string, dateTo:string}} data
 * @returns {Promise<{ok:boolean}>}
 */
export async function updateSchoolHoliday(id, data) {
    return apiFetch(`/admin-api/school-holidays/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

/**
 * Schulferien löschen.
 * @param {number} id
 * @returns {Promise<{ok:boolean}>}
 */
export async function deleteSchoolHoliday(id) {
    return apiFetch(`/admin-api/school-holidays/${id}`, { method: 'DELETE' });
}

/**
 * Manuelle Kursnummer-Übersteuerung setzen.
 * @param {number} tripId
 * @param {string} courseNumber  zweistellig, z.B. "07"
 * @returns {Promise<{ok:boolean}>}
 */
export async function setTripOverride(tripId, courseNumber) {
    return apiFetch(`/admin-api/trips/${tripId}/override`, {
        method: 'PUT',
        body: JSON.stringify({ courseNumber }),
    });
}

/**
 * Manuelle Kursnummer-Übersteuerung zurücksetzen.
 * @param {number} tripId
 * @returns {Promise<{ok:boolean}>}
 */
export async function deleteTripOverride(tripId) {
    return apiFetch(`/admin-api/trips/${tripId}/override`, { method: 'DELETE' });
}

/**
 * Fahrplanschnitt: neue Periode anlegen.
 * @param {{name:string, startDate:string}} data
 * @returns {Promise<{id:number, name:string, startDate:string, createdAt:string}>}
 */
export async function createPeriod(data) {
    return apiFetch('/admin-api/periods', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

/**
 * Periode umbenennen oder Startdatum korrigieren.
 * @param {number} id
 * @param {{name?:string, startDate?:string}} data
 * @returns {Promise<{ok:boolean}>}
 */
export async function updatePeriod(id, data) {
    return apiFetch(`/admin-api/periods/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

/**
 * Einzelerfassungen einer Fahrt laden (Admin).
 * @param {number} tripId
 * @returns {Promise<Array<{id,recordedAt,courseNumber,stopId,stopName,comment,userName,userDisplayId,userDevice}>>}
 */
export async function getAdminTripRecordings(tripId) {
    return apiFetch(`/admin-api/trips/${tripId}/recordings`);
}
