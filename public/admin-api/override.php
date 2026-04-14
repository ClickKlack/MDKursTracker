<?php
// PUT    /admin-api/trips/:id/override – Manuelle Kursnummer setzen
// DELETE /admin-api/trips/:id/override – Manuelle Übersteuerung zurücksetzen

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') {
    handle_put_override($resourceId);
} elseif ($method === 'DELETE') {
    handle_delete_override($resourceId);
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// PUT /admin-api/trips/:id/override
// ---------------------------------------------------------------------------

function handle_put_override(?int $tripId): never
{
    if ($tripId === null) {
        json_error('Trip-ID fehlt in der URL', 400);
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body) || !isset($body['courseNumber'])) {
        json_error('Pflichtfeld fehlt: courseNumber');
    }

    // Kursnummer validieren: zweistellig, 00–99
    if (!preg_match('/^[0-9]{2}$/', $body['courseNumber'])) {
        json_error('Kursnummer muss zweistellig im Format 00–99 sein');
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare('UPDATE ' . tbl('trips') . ' SET manual_course_number = ? WHERE id = ?');
    $stmt->execute([$body['courseNumber'], $tripId]);

    if ($stmt->rowCount() === 0) {
        // Prüfen ob die Fahrt existiert (rowCount = 0 auch bei unverändertem Wert)
        $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . tbl('trips') . ' WHERE id = ?');
        $exists->execute([$tripId]);
        if ((int) $exists->fetchColumn() === 0) {
            json_error('Fahrt nicht gefunden', 404);
        }
    }

    get_logger()->info('Manuelle Kursnummer gesetzt', [
        'trip_id' => $tripId,
        'course'  => $body['courseNumber'],
    ]);

    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// DELETE /admin-api/trips/:id/override
// ---------------------------------------------------------------------------

function handle_delete_override(?int $tripId): never
{
    if ($tripId === null) {
        json_error('Trip-ID fehlt in der URL', 400);
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare('UPDATE ' . tbl('trips') . ' SET manual_course_number = NULL WHERE id = ?');
    $stmt->execute([$tripId]);

    if ($stmt->rowCount() === 0) {
        // Prüfen ob die Fahrt existiert (NULL setzen auf bereits-NULL zählt rowCount = 0)
        $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . tbl('trips') . ' WHERE id = ?');
        $exists->execute([$tripId]);
        if ((int) $exists->fetchColumn() === 0) {
            json_error('Fahrt nicht gefunden', 404);
        }
        // Fahrt existiert, override war bereits NULL – idempotent ok
    }

    get_logger()->info('Manuelle Kursnummer zurückgesetzt', ['trip_id' => $tripId]);
    json_response(['ok' => true]);
}
