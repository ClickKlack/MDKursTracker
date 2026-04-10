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
 * @param {string} url
 * @param {RequestInit} [options]
 * @returns {Promise<any>}
 */
async function apiFetch(url, options = {}) {
    const defaults = {
        headers: { 'Content-Type': 'application/json' },
    };

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
 * @returns {Promise<{recordingId:number, tripId:number, periodId:number, dayType:string}>}
 */
export async function postRecording(data) {
    return apiFetch('/api/recordings', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

/**
 * Erfassungen abrufen, optional gefiltert.
 * @param {{
 *   period_id?: number,
 *   line?:      string,
 *   day_type?:  string,
 *   date_from?: string,
 *   date_to?:   string
 * }} [filters={}]
 * @returns {Promise<Array>}
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
