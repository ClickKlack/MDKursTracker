<?php
// GET /api/periods – Alle Fahrplanperioden mit Erfassungsanzahl und active-Flag.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$pdo = get_db();

// Aktive Periode = höchste ID
$activeId = get_active_period_id($pdo);

$stmt = $pdo->query(
    'SELECT
         p.id,
         p.name,
         p.start_date,
         p.created_at,
         COUNT(r.id) AS recording_count
     FROM schedule_periods p
     LEFT JOIN trips t ON t.period_id = p.id
     LEFT JOIN recordings r ON r.trip_id = t.id
     GROUP BY p.id
     ORDER BY p.id DESC'
);

$rows   = $stmt->fetchAll();
$result = [];
foreach ($rows as $row) {
    $result[] = [
        'id'             => (int) $row['id'],
        'name'           => $row['name'],
        'startDate'      => $row['start_date'],
        'createdAt'      => mysql_to_iso($row['created_at']),
        'recordingCount' => (int) $row['recording_count'],
        'active'         => (int) $row['id'] === $activeId,
    ];
}

json_response($result);
