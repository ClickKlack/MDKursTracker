<?php
// GET /admin-api/trips/:id/recordings – Einzelerfassungen einer Fahrt
//
// Gibt alle Erfassungen der angegebenen Fahrt zurück, inkl. Nutzerinformationen.
// Nur für authentifizierte Admins zugänglich.
//
// $resourceId (int) wird vom index.php-Router gesetzt.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

require_admin(); // 401 wenn nicht eingeloggt

if (!isset($resourceId) || $resourceId <= 0) {
    json_error('Ungültige trip_id', 400);
}

$pdo = get_db();

// Prüfen ob Fahrt existiert
$tripCheck = $pdo->prepare('SELECT id FROM ' . tbl('trips') . ' WHERE id = ?');
$tripCheck->execute([$resourceId]);
if (!$tripCheck->fetchColumn()) {
    json_error('Fahrt nicht gefunden', 404);
}

// Alle Erfassungen dieser Fahrt laden, mit User- und Haltestelleninfos
$stmt = $pdo->prepare(
    'SELECT
         r.id,
         r.recorded_at,
         r.course_number,
         r.stop_id,
         st.name         AS stop_name,
         r.comment,
         u.name          AS user_name,
         u.display_id    AS user_display_id,
         u.last_device   AS user_device
     FROM ' . tbl('recordings') . ' r
     JOIN ' . tbl('stops') . ' st ON r.stop_id = st.hafas_id
     LEFT JOIN ' . tbl('users') . ' u ON r.user_token = u.token
     WHERE r.trip_id = ? AND r.deleted_at IS NULL
     ORDER BY r.recorded_at DESC'
);
$stmt->execute([$resourceId]);

$rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
$result = [];
foreach ($rows as $row) {
    $result[] = [
        'id'            => (int) $row['id'],
        'recordedAt'    => mysql_to_iso($row['recorded_at']),
        'courseNumber'  => $row['course_number'],
        'stopId'        => $row['stop_id'],
        'stopName'      => $row['stop_name'],
        'comment'       => $row['comment'],
        'userName'      => $row['user_name'],
        'userDisplayId' => $row['user_display_id'],
        'userDevice'    => $row['user_device'],
    ];
}

json_response($result);
