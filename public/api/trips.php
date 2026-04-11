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
         t.manual_course_number,
         COUNT(r.id) AS recording_count,
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
     LEFT JOIN ' . tbl('recordings') . ' r ON r.trip_id = t.id
     WHERE t.period_id = ?
     GROUP BY t.id
     ORDER BY t.line, t.day_type, t.service_nr'
);
$stmt->execute([$periodId]);

$rows   = $stmt->fetchAll();
$result = [];
foreach ($rows as $row) {
    $result[] = [
        'id'                 => (int) $row['id'],
        'periodId'           => (int) $row['period_id'],
        'serviceNr'          => $row['service_nr'],
        'line'               => $row['line'],
        'dayType'            => $row['day_type'],
        'direction'          => $row['direction'],
        'activeCourseNumber' => $row['active_course_number'],
        'manualCourseNumber' => $row['manual_course_number'],
        'recordingCount'     => (int) $row['recording_count'],
    ];
}

json_response($result);
