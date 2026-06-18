<?php
// Heuristische Kursnummer-Zuordnung über route_stops.
//
// Wird sowohl in /api/departures (für viele Abfahrten in einem Aufruf) als
// auch in /api/trips/touch (für genau eine Fahrt) genutzt.
//
// Lookup-Reihenfolge in pick_course_for_departure():
//   1. byJourney[hafasTripId]                     – stabilster Treffer
//   2. byServiceNr["serviceNr|line|dayType"]      – persistent über tripId-Wechsel
//   3. byRouteStop["stopId|line|dayType|HH:MM"]   – heuristisch (route_stops)
//
// Quelle pro Treffer: 'manual' (Override am Trip), 'recorded' (Mehrheit aus
// recordings) oder 'heuristic' (eindeutiger route_stops-Fund ohne Override).

require_once __DIR__ . '/db.php';

/**
 * Wählt aus drei Lookup-Maps die best passende Kursnummer für eine Abfahrt.
 *
 * @param array $maps  ['byJourney'=>[], 'byServiceNr'=>[], 'byRouteStop'=>[]]
 *                     Jeder Eintrag: ['number' => string, 'source' => string]
 * @param array $spec  ['hafasTripId','serviceNr','line','dayType','stopId','hhmm']
 *                     'hhmm' ist ein "HH:MM"-String oder null.
 * @return array       ['number' => string|null, 'source' => string|null]
 */
function pick_course_for_departure(array $maps, array $spec): array
{
    $byJourney   = $maps['byJourney']   ?? [];
    $byServiceNr = $maps['byServiceNr'] ?? [];
    $byRouteStop = $maps['byRouteStop'] ?? [];

    if (isset($spec['hafasTripId']) && isset($byJourney[$spec['hafasTripId']])) {
        return $byJourney[$spec['hafasTripId']];
    }

    $serviceKey = ($spec['serviceNr'] ?? '') . '|' . ($spec['line'] ?? '') . '|' . ($spec['dayType'] ?? '');
    if (isset($byServiceNr[$serviceKey])) {
        return $byServiceNr[$serviceKey];
    }

    if (!empty($spec['stopId']) && !empty($spec['hhmm'])) {
        $routeKey = $spec['stopId'] . '|' . ($spec['line'] ?? '') . '|' . ($spec['dayType'] ?? '') . '|' . $spec['hhmm'];
        if (isset($byRouteStop[$routeKey])) {
            return $byRouteStop[$routeKey];
        }
    }

    return ['number' => null, 'source' => null];
}

/**
 * Wählt aus mehreren Trips am selben Route-Schlüssel die Kursnummer, sofern
 * sich alle einig sind.
 *
 * Hintergrund: HAFAS liefert für dieselbe reale Fahrt gelegentlich abweichende
 * Steig-IDs an einem Zwischenhalt (z. B. Olvenstedter Platz …901 vs. …903).
 * Da der schedule_fingerprint die rohen Stop-IDs hasht, entstehen dadurch zwei
 * Trip-Datensätze für eine Fahrt. Beide tragen aber dieselbe Kursnummer.
 *
 * Regel: Es zählen nur Trips mit bekannter Kursnummer. Tragen alle davon
 * dieselbe Nummer, wird sie zurückgegeben. Widersprechen sich zwei bekannte
 * Nummern, bleibt es uneindeutig (null). Trips ganz ohne Kursnummer blockieren
 * nicht – häufig ist nur eine der Duplikat-Varianten erfasst.
 *
 * @param array $trips Liste aus ['course' => string|null, 'manual' => bool].
 *                     'course' ist die effektive Kursnummer (Override oder
 *                     Mehrheit) oder null.
 * @return array{number: string, source: string}|null
 */
function agree_course_from_trips(array $trips): ?array
{
    $number    = null;
    $hasManual = false;

    foreach ($trips as $t) {
        $course = $t['course'] ?? null;
        if ($course === null) {
            continue; // unbekannte Kursnummer blockiert nicht
        }
        if ($number === null) {
            $number = $course;
        } elseif ($number !== $course) {
            return null; // zwei verschiedene bekannte Nummern – uneindeutig
        }
        if (!empty($t['manual'])) {
            $hasManual = true; // Override gewinnt bei der Quellen-Kennzeichnung
        }
    }

    if ($number === null) {
        return null; // keine einzige bekannte Kursnummer
    }

    return ['number' => $number, 'source' => $hasManual ? 'manual' : 'heuristic'];
}

/**
 * Baut die Heuristik-Lookup-Map für alle route_stops einer Periode auf.
 *
 * Matchen mehrere Trips denselben Schlüssel (Duplikate derselben Fahrt durch
 * instabile Steig-IDs), wird die Kursnummer geliefert, wenn sich alle einig
 * sind – siehe agree_course_from_trips(). Widersprechen sie sich, kein Eintrag.
 *
 * @return array  Map["stopId|line|dayType|HH:MM" => ['number','source']]
 */
function build_route_stop_course_map(PDO $pdo, int $periodId): array
{
    // Eine Zeile je (Route-Schlüssel, Trip) mit dessen effektiver Kursnummer;
    // die Einigung über mehrere Trips erfolgt anschließend in PHP.
    $sql = '
        SELECT
            rs.stop_id,
            COALESCE(rs.line, t.line) AS line,
            t.day_type,
            DATE_FORMAT(rs.departure_planned, \'%H:%i\') AS hhmm,
            t.id AS trip_id,
            t.manual_course_number,
            (
                SELECT r.course_number
                FROM ' . tbl('recordings') . ' r
                WHERE r.trip_id = t.id AND r.deleted_at IS NULL
                GROUP BY r.course_number
                ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
                LIMIT 1
            ) AS majority_course_number
        FROM ' . tbl('route_stops') . ' rs
        JOIN ' . tbl('trips') . ' t ON t.id = rs.trip_id
        WHERE t.period_id = ?
          AND rs.departure_planned IS NOT NULL
        GROUP BY rs.stop_id, COALESCE(rs.line, t.line), t.day_type, hhmm, t.id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$periodId]);

    // Trips je Route-Schlüssel sammeln …
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['stop_id'] . '|' . ($row['line'] ?? '') . '|' . $row['day_type'] . '|' . $row['hhmm'];
        $grouped[$key][] = [
            'course' => $row['manual_course_number'] ?? $row['majority_course_number'],
            'manual' => $row['manual_course_number'] !== null,
        ];
    }

    // … und nur eintragen, wenn sich die Trips auf eine Kursnummer einigen.
    $map = [];
    foreach ($grouped as $key => $trips) {
        $pick = agree_course_from_trips($trips);
        if ($pick !== null) {
            $map[$key] = $pick;
        }
    }

    return $map;
}

/**
 * Heuristik-Lookup für eine einzelne Fahrt (Touch-Endpoint).
 *
 * Matchen mehrere Trips (stopId, line, dayType, HH:MM) – Duplikate derselben
 * Fahrt durch instabile Steig-IDs – wird die Kursnummer geliefert, sofern sich
 * alle einig sind (siehe agree_course_from_trips). Sonst null.
 *
 * @return array|null ['number','source','tripId'] oder null bei 0/uneindeutig.
 */
function lookup_route_stop_course_single(
    PDO $pdo,
    int $periodId,
    string $stopId,
    string $line,
    string $dayType,
    string $hhmm
): ?array {
    $sql = '
        SELECT
            t.id AS trip_id,
            t.manual_course_number,
            (
                SELECT r.course_number
                FROM ' . tbl('recordings') . ' r
                WHERE r.trip_id = t.id AND r.deleted_at IS NULL
                GROUP BY r.course_number
                ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
                LIMIT 1
            ) AS majority_course_number
        FROM ' . tbl('route_stops') . ' rs
        JOIN ' . tbl('trips') . ' t ON t.id = rs.trip_id
        WHERE t.period_id   = ?
          AND rs.stop_id    = ?
          AND COALESCE(rs.line, t.line) = ?
          AND t.day_type    = ?
          AND DATE_FORMAT(rs.departure_planned, \'%H:%i\') = ?
        GROUP BY t.id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$periodId, $stopId, $line, $dayType, $hhmm]);

    $trips = [];
    foreach ($stmt->fetchAll() as $row) {
        $trips[] = [
            'course' => $row['manual_course_number'] ?? $row['majority_course_number'],
            'manual' => $row['manual_course_number'] !== null,
            'tripId' => (int) $row['trip_id'],
        ];
    }

    $pick = agree_course_from_trips($trips);
    if ($pick === null) {
        return null; // 0 Treffer oder widersprüchliche Kursnummern
    }

    // Repräsentativen Trip mit der gewählten Nummer für die Antwort wählen.
    $tripId = null;
    foreach ($trips as $t) {
        if ($t['course'] === $pick['number']) {
            $tripId = $t['tripId'];
            break;
        }
    }

    return [
        'number' => $pick['number'],
        'source' => $pick['source'],
        'tripId' => $tripId,
    ];
}
