<?php
// GET /admin-api/trip-group-detail?trip_ids=1,2,3
//
// Gibt für eine Gruppe logischer Fahrten den gemeinsamen Laufweg sowie
// die Abfahrtszeiten jeder Fahrt an jedem Halt zurück.
// Grundlage: je Trip die neuste Erfassung mit gespeicherten route_stops.
//
// Nur für authentifizierte Admins zugänglich.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

require_admin(); // 401 wenn nicht eingeloggt

// --- trip_ids parsen und validieren -----------------------------------------

$raw = trim($_GET['trip_ids'] ?? '');
if ($raw === '') {
    json_error('Parameter trip_ids fehlt', 400);
}

$parts  = explode(',', $raw);
$tripIds = [];
foreach ($parts as $part) {
    $part = trim($part);
    if (!ctype_digit($part) || (int) $part <= 0) {
        json_error('Ungültige trip_ids – nur positive Ganzzahlen erlaubt', 400);
    }
    $tripIds[] = (int) $part;
}
$tripIds = array_unique($tripIds);

if (count($tripIds) > 50) {
    json_error('Maximal 50 Fahrten pro Anfrage', 400);
}

$pdo = get_db();

// --- Pro Trip: aktive Kursnummer und neuste Erfassung ermitteln -------------

$placeholders = implode(',', array_fill(0, count($tripIds), '?'));

// Fahrten-Metadaten laden (inkl. active_course_number per Mehrheitsregel)
$stmtTrips = $pdo->prepare(
    'SELECT
         t.id,
         t.service_nr,
         t.manual_course_number,
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
     WHERE t.id IN (' . $placeholders . ')
     ORDER BY
         CAST(SUBSTRING_INDEX(t.service_nr, \'_\', -1) AS UNSIGNED)'
);
$stmtTrips->execute($tripIds);
$tripRows = $stmtTrips->fetchAll(PDO::FETCH_ASSOC);

// Nur tatsächlich in der DB gefundene IDs weiterverarbeiten
$foundIds = array_column($tripRows, 'id');

if (empty($foundIds)) {
    json_response(['stops' => [], 'trips' => []]);
}

// Pro Trip: neuste Erfassung mit route_stops ermitteln
$ph2 = implode(',', array_fill(0, count($foundIds), '?'));
$stmtRec = $pdo->prepare(
    'SELECT r.trip_id, r.id AS recording_id
     FROM ' . tbl('recordings') . ' r
     WHERE r.trip_id IN (' . $ph2 . ')
       AND EXISTS (
           SELECT 1 FROM ' . tbl('route_stops') . ' rs WHERE rs.recording_id = r.id
       )
     ORDER BY r.trip_id, r.recorded_at DESC'
);
$stmtRec->execute($foundIds);

// Neueste Erfassung je Trip (erste Zeile pro trip_id in DESC-Reihenfolge)
$recordingByTrip = [];
foreach ($stmtRec->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tid = (int) $row['trip_id'];
    if (!isset($recordingByTrip[$tid])) {
        $recordingByTrip[$tid] = (int) $row['recording_id'];
    }
}

// --- Route-Stops aller relevanten Erfassungen laden -------------------------

$allRecordingIds = array_values($recordingByTrip);
if (empty($allRecordingIds)) {
    // Fahrten vorhanden, aber ohne gespeicherte route_stops
    $tripsOut = [];
    foreach ($tripRows as $tr) {
        $tripsOut[] = [
            'id'                 => (int) $tr['id'],
            'serviceNr'          => $tr['service_nr'],
            'activeCourseNumber' => $tr['active_course_number'],
            'manualCourseNumber' => $tr['manual_course_number'],
            'departures'         => (object) [],
        ];
    }
    json_response(['stops' => [], 'trips' => $tripsOut]);
}

$ph3 = implode(',', array_fill(0, count($allRecordingIds), '?'));
$stmtStops = $pdo->prepare(
    'SELECT
         rs.recording_id,
         rs.sequence,
         rs.stop_id,
         rs.departure_planned,
         st.name AS stop_name
     FROM ' . tbl('route_stops') . ' rs
     JOIN ' . tbl('stops') . ' st ON st.hafas_id = rs.stop_id
     WHERE rs.recording_id IN (' . $ph3 . ')
     ORDER BY rs.recording_id, rs.sequence'
);
$stmtStops->execute($allRecordingIds);

// route_stops je recording_id indizieren
$stopsByRecording = [];
foreach ($stmtStops->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rid = (int) $row['recording_id'];
    if (!isset($stopsByRecording[$rid])) {
        $stopsByRecording[$rid] = [];
    }
    $stopsByRecording[$rid][] = $row;
}

// --- Kanonische Stop-Liste: längste Haltestellen-Sequenz -------------------
// Gleiche Linie → gleicher Laufweg; wir nehmen den mit den meisten Halten.

$canonicalStops = [];
foreach ($allRecordingIds as $rid) {
    $stops = $stopsByRecording[$rid] ?? [];
    if (count($stops) > count($canonicalStops)) {
        $canonicalStops = $stops;
    }
}

$stopsOut = [];
foreach ($canonicalStops as $s) {
    $stopsOut[] = [
        'stopId'   => $s['stop_id'],
        'stopName' => $s['stop_name'],
        'sequence' => (int) $s['sequence'],
    ];
}

// --- Trips mit Abfahrtszeiten zusammenstellen -------------------------------

$tripsOut = [];
foreach ($tripRows as $tr) {
    $tid = (int) $tr['id'];
    $rid = $recordingByTrip[$tid] ?? null;

    $departures = [];
    if ($rid !== null) {
        foreach ($stopsByRecording[$rid] ?? [] as $s) {
            if ($s['departure_planned'] !== null) {
                // Nur HH:MM ausgeben
                $departures[$s['stop_id']] = (new DateTime($s['departure_planned']))->format('H:i');
            }
        }
    }

    $tripsOut[] = [
        'id'                 => $tid,
        'serviceNr'          => $tr['service_nr'],
        'activeCourseNumber' => $tr['active_course_number'],
        'manualCourseNumber' => $tr['manual_course_number'],
        'departures'         => $departures ?: (object) [],
    ];
}

json_response([
    'stops' => $stopsOut,
    'trips' => $tripsOut,
]);
