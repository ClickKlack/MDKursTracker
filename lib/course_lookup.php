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
// Stufe 3 hält je Schlüssel eine Kandidatenliste statt einer fertigen Nummer.
// Fallen mehrere Trips auf denselben Schlüssel, engt narrow_course_candidates()
// sie stufenweise ein – siehe dort.
//
// Quelle pro Treffer: 'manual' (Override am Trip), 'recorded' (Mehrheit aus
// recordings) oder 'heuristic' (eindeutiger route_stops-Fund ohne Override).

require_once __DIR__ . '/db.php';

/**
 * Wählt aus drei Lookup-Maps die best passende Kursnummer für eine Abfahrt.
 *
 * @param array $maps  ['byJourney'=>[], 'byServiceNr'=>[], 'byRouteStop'=>[]]
 *                     byJourney/byServiceNr: Eintrag ['number','source'].
 *                     byRouteStop: Eintrag ist eine Kandidatenliste
 *                     (siehe build_route_stop_candidate_map()).
 * @param array $spec  ['hafasTripId','serviceNr','line','dayType','stopId','hhmm',
 *                      'direction','journeyStart','journeyEnd']
 *                     'hhmm', 'journeyStart' und 'journeyEnd' sind "HH:MM"-Strings
 *                     (UTC) oder null.
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
        $pick     = narrow_course_candidates($byRouteStop[$routeKey] ?? [], $spec);
        if ($pick !== null) {
            return ['number' => $pick['number'], 'source' => $pick['source']];
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
 * Engt eine Kandidatenliste stufenweise ein, bis sie eine eindeutige
 * Kursnummer liefert.
 *
 * Der Route-Schlüssel (stopId|line|dayType|HH:MM) ist bewusst grob und fasst
 * gelegentlich zwei reale Fahrten zusammen – etwa wenn sich innerhalb einer
 * Fahrplanperiode der Laufweg einer Linie ändert (Linie 2 ab 29.07.2026 von
 * Westerhüsen auf Buckau eingekürzt). Dann tragen die Trips verschiedene
 * Kursnummern und agree_course_from_trips() verweigert die Auskunft.
 *
 * Stufen:
 *   1. alle Kandidaten           – identisch zum Verhalten ohne Stichentscheid
 *   2. nur passende Richtung     – trennt Westerhüsen von Buckau
 *   3. nur passende Start-/Endzeit der Gesamtfahrt – trennt Fahrten mit
 *      gleichem Ziel, aber unterschiedlich langem Laufweg
 *
 * Jede Stufe filtert aus der Vollliste, nicht aus dem Ergebnis der
 * vorhergehenden: Ändert HAFAS den Richtungstext, bleibt Stufe 3 wirksam.
 * Da Stufe 1 die ungefilterte Liste ist, kann keine Abfahrt eine Kursnummer
 * verlieren, die sie vorher hatte – die späteren Stufen greifen nur dort, wo
 * bisher gar nichts angezeigt wurde.
 *
 * @param array $candidates Liste aus ['course','manual','tripId','direction',
 *                          'journeyStart','journeyEnd'].
 * @param array $spec       Erwartet 'direction', 'journeyStart', 'journeyEnd'
 *                          (je optional). Fehlt ein Wert, entfällt die Stufe.
 * @return array{number: string, source: string, tripId: int|null}|null
 */
function narrow_course_candidates(array $candidates, array $spec): ?array
{
    if (!$candidates) {
        return null;
    }

    $stages = [$candidates];

    if (($spec['direction'] ?? '') !== '') {
        $stages[] = array_filter(
            $candidates,
            static fn(array $c): bool => ($c['direction'] ?? null) === $spec['direction']
        );
    }

    if (($spec['journeyStart'] ?? '') !== '' && ($spec['journeyEnd'] ?? '') !== '') {
        $stages[] = array_filter(
            $candidates,
            static fn(array $c): bool => ($c['journeyStart'] ?? null) === $spec['journeyStart']
                                      && ($c['journeyEnd'] ?? null) === $spec['journeyEnd']
        );
    }

    foreach ($stages as $stage) {
        // Leere Stufen liefern über agree_course_from_trips() ohnehin null.
        $pick = agree_course_from_trips($stage);
        if ($pick === null) {
            continue;
        }

        // Repräsentativen Trip mitgeben – der Touch-Endpunkt gibt ihn zurück.
        $tripId = null;
        foreach ($stage as $c) {
            if (($c['course'] ?? null) === $pick['number']) {
                $tripId = $c['tripId'] ?? null;
                break;
            }
        }

        return $pick + ['tripId' => $tripId];
    }

    return null;
}

/**
 * Beschreibt, warum eine Abfahrt ohne Kursnummer blieb – sofern der Grund
 * widersprüchliche Kandidaten waren.
 *
 * Wird nur aufgerufen, wenn pick_course_for_departure() nichts geliefert hat,
 * und unterscheidet die beiden Fälle:
 *   - gar keine Daten zum Route-Schlüssel  → null (normal, nichts zu melden)
 *   - Daten vorhanden, aber widersprüchlich → Befund
 *
 * Nur der zweite Fall ist ein echter Aussetzer der Heuristik und wird über
 * record_heuristic_miss() festgehalten.
 *
 * @return array{routeKey:string, courses:string[], tripIds:int[]}|null
 */
function describe_unresolved_conflict(array $maps, array $spec): ?array
{
    if (empty($spec['stopId']) || empty($spec['hhmm'])) {
        return null;
    }

    $routeKey = $spec['stopId'] . '|' . ($spec['line'] ?? '') . '|' . ($spec['dayType'] ?? '') . '|' . $spec['hhmm'];
    $candidates = ($maps['byRouteStop'] ?? [])[$routeKey] ?? [];

    $courses = [];
    $tripIds = [];
    foreach ($candidates as $c) {
        if (($c['course'] ?? null) === null) {
            continue;
        }
        $courses[$c['course']] = true;
        $tripIds[] = $c['tripId'] ?? null;
    }

    if (count($courses) < 2) {
        return null; // keine Daten oder einig – kein Widerspruch
    }

    $courses = array_keys($courses);
    sort($courses);

    return [
        'routeKey' => $routeKey,
        'courses'  => $courses,
        'tripIds'  => array_values(array_filter($tripIds, static fn($id) => $id !== null)),
    ];
}

/**
 * Baut die Heuristik-Kandidatenmap für alle route_stops einer Periode auf.
 *
 * Anders als ein fertiger Schlüssel→Nummer-Index behält die Map je Schlüssel
 * alle passenden Trips. Die Auswahl trifft erst narrow_course_candidates() zum
 * Abfragezeitpunkt – nur dort sind Richtung und Laufwegzeiten der konkreten
 * Abfahrt bekannt.
 *
 * @return array  Map["stopId|line|dayType|HH:MM" => Kandidatenliste]
 */
function build_route_stop_candidate_map(PDO $pdo, int $periodId): array
{
    // Trip-Ebene separat laden: die Mehrheits-Subquery läuft so einmal je Trip
    // (einige hundert) statt einmal je route_stops-Zeile (einige zehntausend).
    $tripStmt = $pdo->prepare(
        'SELECT
             t.id,
             t.day_type,
             t.direction,
             t.manual_course_number,
             (
                 SELECT r.course_number
                 FROM ' . tbl('recordings') . ' r
                 WHERE r.trip_id = t.id AND r.deleted_at IS NULL
                 GROUP BY r.course_number
                 ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
                 LIMIT 1
             ) AS majority_course_number
         FROM ' . tbl('trips') . ' t
         WHERE t.period_id = ?'
    );
    $tripStmt->execute([$periodId]);

    $trips = [];
    foreach ($tripStmt->fetchAll() as $row) {
        $trips[(int) $row['id']] = [
            'course'    => $row['manual_course_number'] ?? $row['majority_course_number'],
            'manual'    => $row['manual_course_number'] !== null,
            'tripId'    => (int) $row['id'],
            'direction' => $row['direction'],
            'dayType'   => $row['day_type'],
        ];
    }

    // Start- und Endzeit der Gesamtfahrt je Trip. MIN/MAX auf dem vollen
    // Datetime statt Sortierung nach sequence: Die Halte einer Fahrt sind
    // chronologisch, und das mitgespeicherte Datum macht die Grenzen auch bei
    // Fahrten über Mitternacht eindeutig.
    $boundStmt = $pdo->prepare(
        'SELECT
             rs.trip_id,
             DATE_FORMAT(MIN(rs.departure_planned), \'%H:%i\') AS journey_start,
             DATE_FORMAT(MAX(rs.departure_planned), \'%H:%i\') AS journey_end
         FROM ' . tbl('route_stops') . ' rs
         JOIN ' . tbl('trips') . ' t ON t.id = rs.trip_id
         WHERE t.period_id = ?
           AND rs.departure_planned IS NOT NULL
         GROUP BY rs.trip_id'
    );
    $boundStmt->execute([$periodId]);

    $bounds = [];
    foreach ($boundStmt->fetchAll() as $row) {
        $bounds[(int) $row['trip_id']] = [$row['journey_start'], $row['journey_end']];
    }

    // Laufwege zeilenweise streamen – die Rohzeilen werden nicht gesammelt,
    // sonst läge der Spitzenverbrauch bei einer Periode mit einigen zehntausend
    // route_stops deutlich über dem Speicherlimit des Hostings.
    $stopStmt = $pdo->prepare(
        'SELECT
             rs.trip_id,
             rs.stop_id,
             COALESCE(rs.line, t.line) AS line,
             DATE_FORMAT(rs.departure_planned, \'%H:%i\') AS hhmm
         FROM ' . tbl('route_stops') . ' rs
         JOIN ' . tbl('trips') . ' t ON t.id = rs.trip_id
         WHERE t.period_id = ?
           AND rs.departure_planned IS NOT NULL'
    );
    $stopStmt->execute([$periodId]);

    // Je Schlüssel höchstens ein Kandidat pro Trip – ein Trip, der denselben
    // Halt zur selben Minute doppelt führt, soll nicht doppelt zählen.
    $grouped = [];
    while ($row = $stopStmt->fetch()) {
        $tripId = (int) $row['trip_id'];
        if (!isset($trips[$tripId])) {
            continue;
        }
        $trip = $trips[$tripId];
        $key  = $row['stop_id'] . '|' . ($row['line'] ?? '') . '|' . $trip['dayType'] . '|' . $row['hhmm'];

        $grouped[$key][$tripId] = [
            'course'       => $trip['course'],
            'manual'       => $trip['manual'],
            'tripId'       => $trip['tripId'],
            'direction'    => $trip['direction'],
            'journeyStart' => $bounds[$tripId][0] ?? null,
            'journeyEnd'   => $bounds[$tripId][1] ?? null,
        ];
    }

    $map = [];
    foreach ($grouped as $key => $byTrip) {
        $map[$key] = array_values($byTrip);
    }

    return $map;
}

/**
 * Heuristik-Lookup für eine einzelne Fahrt (Touch-Endpoint).
 *
 * Gleiche Stufenlogik wie in der Abfahrtstafel – siehe
 * narrow_course_candidates(). $direction, $journeyStart und $journeyEnd sind
 * optional; fehlen sie, bleibt es beim groben Schlüssel.
 *
 * @param string|null $journeyStart "HH:MM" (UTC) des ersten Halts der Gesamtfahrt
 * @param string|null $journeyEnd   "HH:MM" (UTC) des letzten Halts der Gesamtfahrt
 * @return array|null ['number','source','tripId'] oder null bei 0/uneindeutig.
 */
function lookup_route_stop_course_single(
    PDO $pdo,
    int $periodId,
    string $stopId,
    string $line,
    string $dayType,
    string $hhmm,
    ?string $direction = null,
    ?string $journeyStart = null,
    ?string $journeyEnd = null
): ?array {
    // Nur ein Schlüssel, entsprechend wenige Trips – die Laufwegzeiten dürfen
    // hier als korrelierte Subqueries kommen.
    $sql = '
        SELECT
            t.id AS trip_id,
            t.direction,
            t.manual_course_number,
            (
                SELECT DATE_FORMAT(rs_s.departure_planned, \'%H:%i\')
                FROM ' . tbl('route_stops') . ' rs_s
                WHERE rs_s.trip_id = t.id AND rs_s.departure_planned IS NOT NULL
                ORDER BY rs_s.sequence ASC
                LIMIT 1
            ) AS journey_start,
            (
                SELECT DATE_FORMAT(rs_e.departure_planned, \'%H:%i\')
                FROM ' . tbl('route_stops') . ' rs_e
                WHERE rs_e.trip_id = t.id AND rs_e.departure_planned IS NOT NULL
                ORDER BY rs_e.sequence DESC
                LIMIT 1
            ) AS journey_end,
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

    $candidates = [];
    foreach ($stmt->fetchAll() as $row) {
        $candidates[] = [
            'course'       => $row['manual_course_number'] ?? $row['majority_course_number'],
            'manual'       => $row['manual_course_number'] !== null,
            'tripId'       => (int) $row['trip_id'],
            'direction'    => $row['direction'],
            'journeyStart' => $row['journey_start'],
            'journeyEnd'   => $row['journey_end'],
        ];
    }

    return narrow_course_candidates($candidates, [
        'direction'    => $direction,
        'journeyStart' => $journeyStart,
        'journeyEnd'   => $journeyEnd,
    ]);
}
