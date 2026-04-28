<?php
// GET  /api/trips         – Logische Fahrten einer Periode mit aktiver Kursnummer.
// POST /api/trips/touch   – HAFAS-Fahrt per Fingerprint mit bestehendem Trip
//                           verlinken; aktualisiert last_hafas_trip_id und
//                           service_nr ohne neue Erfassung anzulegen.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/api/trips/touch$#', $uri)) {
    if ($method !== 'POST') {
        json_error('Methode nicht erlaubt', 405);
    }
    require_once dirname(__DIR__, 2) . '/lib/logger.php';
    require_once dirname(__DIR__, 2) . '/lib/calendar.php';
    require_once dirname(__DIR__, 2) . '/lib/hafas.php';
    require_once dirname(__DIR__, 2) . '/lib/fingerprint.php';
    require_once dirname(__DIR__, 2) . '/lib/trip_touch.php';
    require_once dirname(__DIR__, 2) . '/lib/course_lookup.php';
    handle_post_trip_touch();
}

if ($method !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

handle_get_trips();

// ---------------------------------------------------------------------------
// GET /api/trips
// ---------------------------------------------------------------------------

function handle_get_trips(): never
{
    $pdo      = get_db();
    $periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : get_active_period_id($pdo);

    // Fahrten mit aktivem Kursnummer laden.
    // Mehrheitsregel: häufigste course_number gewinnt; bei Gleichstand ältester Eintrag.
    $stmt = $pdo->prepare(
        'SELECT
             t.id,
             t.period_id,
             t.service_nr,
             t.line,
             t.day_type,
             t.direction,
             t.path_fingerprint,
             t.schedule_fingerprint,
             t.manual_course_number,
             COUNT(r.id)               AS recording_count,
             MIN(rs1.departure_planned) AS start_departure,
             MIN(st1.name)              AS start_stop_name,
             (
                 SELECT rs_e.departure_planned
                 FROM ' . tbl('route_stops') . ' rs_e
                 WHERE rs_e.trip_id = t.id
                 ORDER BY rs_e.sequence DESC LIMIT 1
             ) AS end_departure,
             (
                 SELECT se.name
                 FROM ' . tbl('route_stops') . ' rs_e
                 JOIN ' . tbl('stops') . ' se ON se.hafas_id = rs_e.stop_id
                 WHERE rs_e.trip_id = t.id
                 ORDER BY rs_e.sequence DESC LIMIT 1
             ) AS end_stop_name,
             COALESCE(
                 t.manual_course_number,
                 (
                     SELECT r2.course_number
                     FROM ' . tbl('recordings') . ' r2
                     WHERE r2.trip_id = t.id
                     GROUP BY r2.course_number
                     ORDER BY COUNT(*) DESC, MIN(r2.recorded_at) ASC
                     LIMIT 1
                 )
             ) AS active_course_number
         FROM ' . tbl('trips') . ' t
         LEFT JOIN ' . tbl('recordings') . '  r   ON r.trip_id = t.id
         LEFT JOIN ' . tbl('route_stops') . ' rs1 ON rs1.trip_id = t.id AND rs1.sequence = 1
         LEFT JOIN ' . tbl('stops') . '        st1 ON st1.hafas_id = rs1.stop_id
         WHERE t.period_id = ?
         GROUP BY t.id
         ORDER BY t.line, t.day_type,
                  CAST(SUBSTRING_INDEX(t.service_nr, \'_\', 1)  AS UNSIGNED),
                  CAST(SUBSTRING_INDEX(t.service_nr, \'_\', -1) AS UNSIGNED)'
    );
    $stmt->execute([$periodId]);

    $rows   = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id'                  => (int) $row['id'],
            'periodId'            => (int) $row['period_id'],
            'serviceNr'           => $row['service_nr'],
            'line'                => $row['line'],
            'dayType'             => $row['day_type'],
            'direction'           => $row['direction'],
            'pathFingerprint'     => $row['path_fingerprint'],
            'scheduleFingerprint' => $row['schedule_fingerprint'],
            'activeCourseNumber'  => $row['active_course_number'],
            'manualCourseNumber'  => $row['manual_course_number'],
            'recordingCount'      => (int) $row['recording_count'],
            'startDeparture'      => $row['start_departure']
                ? (new DateTime($row['start_departure'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i')
                : null,
            'startStopName'       => $row['start_stop_name'],
            'endDeparture'        => $row['end_departure']
                ? (new DateTime($row['end_departure'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i')
                : null,
            'endStopName'         => $row['end_stop_name'] ?? $row['direction'],
        ];
    }

    json_response($result);
}

// ---------------------------------------------------------------------------
// POST /api/trips/touch
//
// Wird beim Öffnen der Capture-View aufgerufen, sobald die HAFAS-Daten der
// Fahrt vorliegen. Sucht einen Trip mit gleichem schedule_fingerprint und
// aktualisiert dort die instabile last_hafas_trip_id sowie service_nr,
// damit die Abfahrtstafel die zugeordnete Kursnummer auch dann anzeigt,
// wenn HAFAS für dieselbe Fahrt eine neue tripId/serviceNr ausgibt.
// ---------------------------------------------------------------------------

function handle_post_trip_touch(): never
{
    $body  = json_decode(file_get_contents('php://input'), true);
    $check = validate_trip_touch_body($body);

    if (!$check['ok']) {
        json_error($check['error']);
    }
    $body = $check['body'];

    $pdo      = get_db();
    $periodId = get_active_period_id($pdo);

    // Vollständigen Laufweg über HAFAS laden (bereits gecached durch nachfolgenden
    // /api/trip-Aufruf der Capture-View – kein zusätzlicher Roundtrip).
    try {
        $tripStops = hafas_trip($body['hafasTripId']);
    } catch (RuntimeException $e) {
        get_logger()->warning('trips/touch: HAFAS-Trip-Fehler', [
            'hafasTripId' => $body['hafasTripId'],
            'exception'   => $e->getMessage(),
        ]);
        // Schweigend "kein Match" – der Endpunkt ist best-effort, ein
        // HAFAS-Fehler darf das Öffnen der Erfassung nicht stören.
        json_response(['matched' => false, 'reason' => 'hafas_error']);
    }

    // Betriebsdatum autoritativ aus dem Trip-Start ableiten (analog zu
    // POST /api/recordings, da Mitternachts-Übergänge sonst falschen
    // day_type liefern).
    $serviceDate = derive_service_date($tripStops);
    if ($serviceDate === null) {
        json_response(['matched' => false, 'reason' => 'no_service_date']);
    }

    $dayType = getDayType(new DateTimeImmutable($serviceDate), $pdo);

    $fp = compute_fingerprints($tripStops);
    if ($fp['schedule'] === null) {
        json_response(['matched' => false, 'reason' => 'no_fingerprint']);
    }

    // Trip per schedule_fingerprint suchen (kein Insert bei Miss!)
    $tripStmt = $pdo->prepare(
        'SELECT id, last_hafas_trip_id, service_nr, manual_course_number
         FROM ' . tbl('trips') . '
         WHERE period_id = ? AND schedule_fingerprint = ? AND day_type = ?'
    );
    $tripStmt->execute([$periodId, $fp['schedule'], $dayType]);
    $existing = $tripStmt->fetch();

    if (!$existing) {
        // Fingerprint-Match fehlgeschlagen: heuristischen Lookup über
        // route_stops versuchen, damit die Capture-View trotzdem einen
        // Vorschlag bekommen kann.
        $hhmm  = substr($body['departurePlanned'], 11, 5); // "HH:MM" UTC
        $heur  = lookup_route_stop_course_single(
            $pdo, $periodId,
            (string) $body['stopId'],
            (string) $body['line'],
            $dayType,
            $hhmm
        );

        $response = ['matched' => false, 'reason' => 'no_trip'];
        if ($heur !== null) {
            $response['heuristicCourseNumber'] = $heur['number'];
            $response['heuristicCourseSource'] = $heur['source']; // 'manual' oder 'heuristic'
            $response['heuristicTripId']       = $heur['tripId'];
        }
        json_response($response);
    }

    $tripId  = (int) $existing['id'];
    $updated = false;

    if ($existing['last_hafas_trip_id'] !== $body['hafasTripId']
        || $existing['service_nr'] !== $body['serviceNr']) {
        $pdo->prepare(
            'UPDATE ' . tbl('trips') . '
             SET last_hafas_trip_id = ?, service_nr = ?
             WHERE id = ?'
        )->execute([$body['hafasTripId'], $body['serviceNr'], $tripId]);

        $updated = true;

        get_logger()->info('trips/touch: Trip-Mapping aktualisiert', [
            'tripId'         => $tripId,
            'oldHafasTripId' => $existing['last_hafas_trip_id'],
            'newHafasTripId' => $body['hafasTripId'],
            'oldServiceNr'   => $existing['service_nr'],
            'newServiceNr'   => $body['serviceNr'],
        ]);
    }

    // Aktive Kursnummer für die Antwort ermitteln (Override > Mehrheit).
    $courseStmt = $pdo->prepare(
        'SELECT r.course_number
         FROM ' . tbl('recordings') . ' r
         WHERE r.trip_id = ?
         GROUP BY r.course_number
         ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
         LIMIT 1'
    );
    $courseStmt->execute([$tripId]);
    $majority = $courseStmt->fetchColumn();
    $active   = $existing['manual_course_number'] ?? ($majority !== false ? $majority : null);
    $source   = $existing['manual_course_number'] !== null
        ? 'manual'
        : ($majority !== false ? 'recorded' : null);

    json_response([
        'matched'            => true,
        'tripId'             => $tripId,
        'updated'            => $updated,
        'activeCourseNumber' => $active,
        'courseSource'       => $source,
    ]);
}
