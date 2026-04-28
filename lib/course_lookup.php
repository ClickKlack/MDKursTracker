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
 * Baut die Heuristik-Lookup-Map für alle route_stops einer Periode auf.
 *
 * Liefert nur Tupel mit genau einem matchenden Trip (HAVING trip_count = 1).
 * Bei mehreren matchenden Trips ist die Heuristik nicht sicher – kein Eintrag.
 *
 * @return array  Map["stopId|line|dayType|HH:MM" => ['number','source']]
 */
function build_route_stop_course_map(PDO $pdo, int $periodId): array
{
    $sql = '
        SELECT
            rs.stop_id,
            COALESCE(rs.line, t.line) AS line,
            t.day_type,
            DATE_FORMAT(rs.departure_planned, \'%H:%i\') AS hhmm,
            COUNT(DISTINCT t.id) AS trip_count,
            MIN(t.id) AS trip_id,
            (
                SELECT r.course_number
                FROM ' . tbl('recordings') . ' r
                WHERE r.trip_id = MIN(t.id)
                GROUP BY r.course_number
                ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
                LIMIT 1
            ) AS majority_course_number,
            MIN(t.manual_course_number) AS manual_course_number
        FROM ' . tbl('route_stops') . ' rs
        JOIN ' . tbl('trips') . ' t ON t.id = rs.trip_id
        WHERE t.period_id = ?
          AND rs.departure_planned IS NOT NULL
        GROUP BY rs.stop_id, COALESCE(rs.line, t.line), t.day_type, hhmm
        HAVING trip_count = 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$periodId]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $number = $row['manual_course_number'] ?? $row['majority_course_number'];
        if ($number === null) {
            continue;
        }
        $source = $row['manual_course_number'] !== null ? 'manual' : 'heuristic';
        $key    = $row['stop_id'] . '|' . ($row['line'] ?? '') . '|' . $row['day_type'] . '|' . $row['hhmm'];
        $map[$key] = ['number' => $number, 'source' => $source];
    }

    return $map;
}

/**
 * Heuristik-Lookup für eine einzelne Fahrt (Touch-Endpoint).
 *
 * Liefert eine Kursnummer nur, wenn (stopId, line, dayType, HH:MM) genau
 * einen Trip in der Periode matcht.
 *
 * @return array|null ['number','source','tripId'] oder null bei 0/>1 Treffern.
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
                WHERE r.trip_id = t.id
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
        LIMIT 2
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$periodId, $stopId, $line, $dayType, $hhmm]);
    $rows = $stmt->fetchAll();

    if (count($rows) !== 1) {
        return null; // 0 oder >1 Treffer – nicht eindeutig
    }
    $row    = $rows[0];
    $number = $row['manual_course_number'] ?? $row['majority_course_number'];
    if ($number === null) {
        return null;
    }
    $source = $row['manual_course_number'] !== null ? 'manual' : 'heuristic';

    return [
        'number' => $number,
        'source' => $source,
        'tripId' => (int) $row['trip_id'],
    ];
}
