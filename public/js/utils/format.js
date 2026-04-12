/**
 * format.js – Datum/Zeit-Formatierung und Kursnummer-Darstellung
 *
 * Das Backend speichert alle Zeitstempel in UTC (ISO 8601).
 * Die Anzeige erfolgt immer in der Zeitzone Europe/Berlin (inkl. Sommerzeit).
 */

const BERLIN = { timeZone: 'Europe/Berlin' };

/**
 * ISO-UTC-Zeitstempel → Uhrzeit in Berliner Zeit (HH:MM).
 * @param {string|null} isoString
 * @returns {string}
 */
export function formatTime(isoString) {
    if (!isoString) return '–';
    return new Date(isoString).toLocaleTimeString('de-DE', {
        ...BERLIN,
        hour:   '2-digit',
        minute: '2-digit',
    });
}

/**
 * ISO-UTC-Zeitstempel → Kurzdatum in Berliner Zeit (z.B. "Mi., 24.03.").
 * @param {string|null} isoString
 * @returns {string}
 */
export function formatDate(isoString) {
    if (!isoString) return '–';
    return new Date(isoString).toLocaleDateString('de-DE', {
        ...BERLIN,
        weekday: 'short',
        day:     '2-digit',
        month:   '2-digit',
    });
}

/**
 * Verspätung in Minuten berechnen.
 * @param {string} planned   ISO UTC (planmäßige Abfahrt)
 * @param {string|null} actual  ISO UTC (Echtzeit-Abfahrt)
 * @returns {number|null}  Positive Zahl = Verspätung, negative Zahl = zu früh; null = pünktlich oder kein Istwert
 */
export function calcDelay(planned, actual) {
    if (!actual || !planned) return null;
    const diff = Math.round((new Date(actual) - new Date(planned)) / 60_000);
    return diff !== 0 ? diff : null;
}

/**
 * Betriebsdatum (YYYY-MM-DD in Berliner Zeit) aus UTC-Zeitstempel ableiten.
 * Wichtig für Nachtfahrten, die kalendarisch noch dem Vortag angehören.
 * @param {string} isoString
 * @returns {string}  Format YYYY-MM-DD
 */
export function getServiceDate(isoString) {
    // 'sv' (Schwedisch) liefert YYYY-MM-DD ohne weitere Umformung nötig
    return new Date(isoString).toLocaleDateString('sv', BERLIN);
}

/**
 * Kursnummer zweistellig darstellen ("7" → "07", 1 → "01").
 * @param {string|number} n
 * @returns {string}
 */
export function padCourseNumber(n) {
    return String(n).padStart(2, '0');
}
