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
export function getCurrentPosition(options = {}) {
    return new Promise((resolve, reject) => {
        if (!('geolocation' in navigator)) {
            reject(new Error('Geolocation wird von diesem Browser nicht unterstützt.'));
            return;
        }

        const defaults = {
            enableHighAccuracy: true,
            timeout:            10_000,
            maximumAge:         60_000, // Gecachte Position bis zu 1 Minute akzeptieren
        };

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
}
