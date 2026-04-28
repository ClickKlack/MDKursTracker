<?php
// Hilfsfunktionen für POST /api/trips/touch.
//
// Der Touch-Endpunkt verlinkt eine HAFAS-Fahrt per schedule_fingerprint mit
// einem bestehenden Trip-Datensatz und führt dabei `last_hafas_trip_id`
// und `service_nr` nach. Anders als POST /api/recordings legt er keinen
// neuen Trip an – wenn kein Match existiert, passiert nichts.

/**
 * Validiert den JSON-Body eines /api/trips/touch-Requests.
 *
 * @param mixed $body  Dekodierter JSON-Body (idealerweise Array).
 * @return array       ['ok' => true, 'body' => array]  bei Erfolg
 *                     ['ok' => false, 'error' => string] bei Fehler
 */
function validate_trip_touch_body(mixed $body): array
{
    if (!is_array($body)) {
        return ['ok' => false, 'error' => 'Ungültiger JSON-Body'];
    }

    // Pflichtfelder. stopId und departurePlanned werden für den heuristischen
    // route_stops-Lookup gebraucht, wenn der Fingerprint-Match scheitert.
    $required = ['hafasTripId', 'serviceNr', 'line', 'stopId', 'departurePlanned'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || (string) $body[$field] === '') {
            return ['ok' => false, 'error' => "Pflichtfeld fehlt: $field"];
        }
    }

    // Feldlängen analog zu POST /api/recordings
    $maxLengths = [
        'hafasTripId' => 512,
        'serviceNr'   => 20,
        'line'        => 10,
        'stopId'      => 20,
    ];
    foreach ($maxLengths as $field => $max) {
        if (mb_strlen((string) $body[$field]) > $max) {
            return ['ok' => false, 'error' => "$field darf maximal $max Zeichen lang sein"];
        }
    }

    // departurePlanned als ISO-8601-UTC validieren
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['departurePlanned'])) {
        return ['ok' => false, 'error' => 'departurePlanned muss ISO-8601-UTC sein (YYYY-MM-DDTHH:MM:SSZ)'];
    }

    return ['ok' => true, 'body' => $body];
}
