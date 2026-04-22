<?php
// GET /admin-api/hafas-cache?id={log_entry_id}
//
// Gibt den noch vorhandenen Cache-Inhalt für einen HAFAS-Log-Eintrag zurück.
// Unterstützte Endpoints: trip, nearby.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    json_error('Parameter id fehlt oder ungültig', 400);
}

$pdo  = get_db();
$stmt = $pdo->prepare('SELECT endpoint, params FROM ' . tbl('hafas_log') . ' WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_error('Eintrag nicht gefunden', 404);
}

$params = $row['params'] !== null ? json_decode($row['params'], true) : null;

$key = match($row['endpoint']) {
    'trip'   => isset($params['tripId'])
                    ? hafas_cache_key('trip', $params['tripId'])
                    : null,
    'nearby' => isset($params['lat'], $params['lon'], $params['results'])
                    ? hafas_cache_key('nearby', $params['lat'], $params['lon'], $params['results'])
                    : null,
    'stopfinder' => isset($params['name'], $params['results'])
                    ? hafas_cache_key('stopfinder', $params['name'], $params['results'])
                    : null,
    'departures' => isset($params['stopId'], $params['results'], $params['maxMinutes'])
                    ? hafas_cache_key('departures', $params['stopId'], $params['results'],
                          $params['maxMinutes'],
                          max(0, (int) ((hafas_config())['departures_lookback_minutes'] ?? 5)))
                    : null,
    default  => null,
};

if ($key === null) {
    json_error('Kein Cache für diesen Endpoint', 404);
}

$data = hafas_cache_get($key);
if ($data === null) {
    json_error('Cache nicht vorhanden oder abgelaufen', 404);
}

json_response($data);
