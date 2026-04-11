/**
 * geolocation.js – GPS-Wrapper mit sprechender Fehlerbehandlung
 */

/**
 * Aktuelle GPS-Position des Geräts ermitteln.
 *
 * @param {PositionOptions} [options]
 * @returns {Promise<GeolocationPosition>}
 * @throws {Error}  Mit deutschsprachiger Fehlermeldung bei Ablehnung/Timeout
 */
const GPS_TIMEOUT_MS  = 10_000;
const GPS_WATCHDOG_MS = 12_000; // Watchdog: greift wenn der Browser-Timeout nicht feuert

export function getCurrentPosition(options = {}) {
    if (!('geolocation' in navigator)) {
        return Promise.reject(new Error(
            'Geolocation wird von diesem Browser nicht unterstützt.'
        ));
    }

    const defaults = {
        enableHighAccuracy: true,
        timeout:            GPS_TIMEOUT_MS,
        maximumAge:         60_000, // Gecachte Position bis zu 1 Minute akzeptieren
    };

    const geoPromise = new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(
            resolve,
            (err) => {
                switch (err.code) {
                    case err.PERMISSION_DENIED:
                        reject(new Error(
                            'GPS-Zugriff verweigert. Bitte in den Browser-Einstellungen für ' +
                            'diese Seite erlauben und erneut versuchen.'
                        ));
                        break;
                    case err.POSITION_UNAVAILABLE:
                        reject(new Error(
                            'GPS-Position momentan nicht verfügbar. ' +
                            'Bitte ins Freie gehen oder WLAN aktivieren.'
                        ));
                        break;
                    case err.TIMEOUT:
                        reject(new Error(
                            'GPS-Abfrage hat zu lange gedauert. Bitte erneut versuchen.'
                        ));
                        break;
                    default:
                        reject(new Error(`GPS-Fehler: ${err.message}`));
                }
            },
            { ...defaults, ...options }
        );
    });

    // Watchdog: manche Browser ignorieren das timeout-Flag bei enableHighAccuracy.
    // Nach GPS_WATCHDOG_MS wird das Promise zwangsweise rejected.
    const watchdog = new Promise((_, reject) =>
        setTimeout(
            () => reject(new Error('GPS-Abfrage hat zu lange gedauert. Bitte erneut versuchen.')),
            GPS_WATCHDOG_MS
        )
    );

    return Promise.race([geoPromise, watchdog]);
}
