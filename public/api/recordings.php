<?php
// GET /api/recordings – Erfassungen abrufen (gefiltert)
// POST /api/recordings – Neue Kursnummer-Erfassung speichern

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handle_get_recordings();
} elseif ($method === 'POST') {
    handle_post_recording();
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// GET /api/recordings
// ---------------------------------------------------------------------------

function handle_get_recordings(): never
{
    $pdo      = get_db();
    $periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : get_active_period_id($pdo);

    // Optionale Filter aufbauen
    $where  = ['t.period_id = :period_id'];
    $params = [':period_id' => $periodId];

    if (!empty($_GET['line'])) {
        $where[]         = 't.line = :line';
        $params[':line'] = $_GET['line'];
    }

    if (!empty($_GET['day_type'])) {
        $allowed = ['MO-FR', 'SA', 'SO', 'FT', 'SF'];
        if (!in_array($_GET['day_type'], $allowed, true)) {
            json_error('Ungültiger day_type');
        }
        $where[]           = 't.day_type = :day_type';
        $params[':day_type'] = $_GET['day_type'];
    }

    if (!empty($_GET['date_from'])) {
        $where[]              = 'r.service_date >= :date_from';
        $params[':date_from'] = $_GET['date_from'];
    }

    if (!empty($_GET['date_to'])) {
        $where[]            = 'r.service_date <= :date_to';
        $params[':date_to'] = $_GET['date_to'];
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT
             r.id,
             r.recorded_at,
             t.line,
             t.direction,
             t.service_nr,
             t.day_type,
             r.service_date,
             s.name         AS stop,
             r.departure_planned,
             r.departure_actual,
             r.course_number,
             t.manual_course_number,
             COALESCE(
                 t.manual_course_number,
                 (
                     SELECT r2.course_number
                     FROM recordings r2
                     WHERE r2.trip_id = t.id
                     GROUP BY r2.course_number
                     ORDER BY COUNT(*) DESC, MIN(r2.recorded_at) ASC
                     LIMIT 1
                 )
             ) AS active_course_number
         FROM recordings r
         JOIN trips t ON r.trip_id = t.id
         JOIN stops s ON r.stop_id = s.hafas_id
         WHERE $whereClause
         ORDER BY r.recorded_at DESC"
    );
    $stmt->execute($params);

    $rows   = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id'                 => (int) $row['id'],
            'recordedAt'         => mysql_to_iso($row['recorded_at']),
            'line'               => $row['line'],
            'direction'          => $row['direction'],
            'serviceNr'          => $row['service_nr'],
            'dayType'            => $row['day_type'],
            'serviceDate'        => $row['service_date'],
            'stop'               => $row['stop'],
            'departurePlanned'   => mysql_to_iso($row['departure_planned']),
            'departureActual'    => mysql_to_iso($row['departure_actual']),
            'courseNumber'       => $row['course_number'],
            'activeCourseNumber' => $row['active_course_number'],
            'manualCourseNumber' => $row['manual_course_number'],
        ];
    }

    json_response($result);
}

// ---------------------------------------------------------------------------
// POST /api/recordings
// ---------------------------------------------------------------------------

function handle_post_recording(): never
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    // Pflichtfelder prüfen
    $required = ['hafasTripId', 'serviceNr', 'line', 'direction', 'stopId',
                 'serviceDate', 'departurePlanned', 'courseNumber'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || (string) $body[$field] === '') {
            json_error("Pflichtfeld fehlt: $field");
        }
    }

    // Kursnummer validieren: zweistellig, 01–99
    if (!preg_match('/^(0[1-9]|[1-9][0-9])$/', $body['courseNumber'])) {
        json_error('Kursnummer muss zweistellig im Format 01–99 sein');
    }

    $pdo      = get_db();
    $periodId = get_active_period_id($pdo);

    // Wochentagstyp aus Betriebsdatum berechnen
    $serviceDate = DateTimeImmutable::createFromFormat('Y-m-d', $body['serviceDate']);
    if ($serviceDate === false) {
        json_error('Ungültiges serviceDate-Format (erwartet YYYY-MM-DD)');
    }
    $dayType = getDayType($serviceDate, $pdo);

    // Logische Fahrt anlegen (IGNORE = kein Fehler bei Duplikat)
    $pdo->prepare(
        'INSERT IGNORE INTO trips (period_id, service_nr, line, day_type, direction)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$periodId, $body['serviceNr'], $body['line'], $dayType, $body['direction']]);

    // Trip-ID für FK ermitteln
    $tripStmt = $pdo->prepare(
        'SELECT id FROM trips
         WHERE period_id = ? AND service_nr = ? AND line = ? AND day_type = ?'
    );
    $tripStmt->execute([$periodId, $body['serviceNr'], $body['line'], $dayType]);
    $tripId = (int) $tripStmt->fetchColumn();

    // Vollständigen Laufweg über HAFAS laden
    try {
        $tripStops = hafas_trip($body['hafasTripId']);
    } catch (RuntimeException $e) {
        get_logger()->error('recordings POST: HAFAS-Trip-Fehler', [
            'hafasTripId' => $body['hafasTripId'],
            'exception'   => $e->getMessage(),
        ]);
        json_error('HAFAS-Tripabfrage fehlgeschlagen: ' . $e->getMessage(), 500);
    }

    // Haltestellenname und kanonische HAFAS-ID des Erfassungs-Stops ermitteln.
    // nearby/departures liefern Kurz-IDs (z.B. "7543"), JourneyDetails Lang-IDs ("300754302").
    // Daher zuerst Zeitabgleich als Fallback wenn ID-Match scheitert.
    $recordingStopId   = $body['stopId']; // Fallback: übermittelte ID
    $recordingStopName = $body['stopId']; // Fallback: ID als Name

    foreach ($tripStops as $ts) {
        if ($ts['stopId'] === $body['stopId']) {
            $recordingStopId   = $ts['stopId'];
            $recordingStopName = $ts['stop'];
            break;
        }
    }

    // Kein ID-Match: Abgleich über departurePlanned-Zeit (HH:MM in UTC)
    if ($recordingStopName === $body['stopId'] && $body['departurePlanned'] !== '') {
        $bodyTime = substr($body['departurePlanned'], 11, 5); // "HH:MM"
        foreach ($tripStops as $ts) {
            if ($ts['departurePlanned'] !== null) {
                $tsTime = substr($ts['departurePlanned'], 11, 5);
                if ($tsTime === $bodyTime) {
                    $recordingStopId   = $ts['stopId'];
                    $recordingStopName = $ts['stop'];
                    break;
                }
            }
        }
    }

    if ($recordingStopName === $body['stopId']) {
        get_logger()->warning('recordings POST: Haltestelle nicht im Laufweg gefunden', [
            'stopId'           => $body['stopId'],
            'departurePlanned' => $body['departurePlanned'],
        ]);
    }

    // Alles in einer Transaktion speichern
    $pdo->beginTransaction();
    try {
        // Erfassungs-Haltestelle sicherstellen (mit kanonischer Lang-ID)
        $pdo->prepare('INSERT IGNORE INTO stops (hafas_id, name) VALUES (?, ?)')
            ->execute([$recordingStopId, $recordingStopName]);

        // Erfassung anlegen
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $recStmt = $pdo->prepare(
            'INSERT INTO recordings
                 (trip_id, recorded_at, hafas_trip_id, service_date, stop_id,
                  departure_planned, departure_actual, course_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $recStmt->execute([
            $tripId,
            $now,
            $body['hafasTripId'],
            $body['serviceDate'],
            $recordingStopId,
            iso_to_mysql($body['departurePlanned']),
            isset($body['departureActual']) && $body['departureActual'] !== null
                ? iso_to_mysql($body['departureActual'])
                : null,
            $body['courseNumber'],
        ]);
        $recordingId = (int) $pdo->lastInsertId();

        // Alle Laufweg-Haltestellen anlegen und route_stops befüllen
        if (!empty($tripStops)) {
            $stopInsert  = $pdo->prepare('INSERT IGNORE INTO stops (hafas_id, name) VALUES (?, ?)');
            $routeInsert = $pdo->prepare(
                'INSERT INTO route_stops (recording_id, sequence, stop_id, departure_planned)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($tripStops as $ts) {
                $stopInsert->execute([$ts['stopId'], $ts['stop']]);
                $routeInsert->execute([
                    $recordingId,
                    $ts['sequence'],
                    $ts['stopId'],
                    $ts['departurePlanned'] !== null ? iso_to_mysql($ts['departurePlanned']) : null,
                ]);
            }
        } else {
            get_logger()->warning('recordings POST: HAFAS lieferte leere Halteliste', [
                'hafasTripId' => $body['hafasTripId'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        get_logger()->error('recordings POST: Transaktion fehlgeschlagen', [
            'exception' => $e->getMessage(),
        ]);
        json_error('Speichern fehlgeschlagen', 500);
    }

    get_logger()->info('Erfassung gespeichert', [
        'recording_id' => $recordingId,
        'trip_id'      => $tripId,
        'course'       => $body['courseNumber'],
    ]);

    json_response([
        'recordingId' => $recordingId,
        'tripId'      => $tripId,
        'periodId'    => $periodId,
        'dayType'     => $dayType,
    ], 201);
}
