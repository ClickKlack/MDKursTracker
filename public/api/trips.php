<?php
// GET /api/trips – Logische Fahrten einer Periode mit berechneter aktiver Kursnummer.
// Parameter: period_id (int, optional – Standard: aktive Periode)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

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
