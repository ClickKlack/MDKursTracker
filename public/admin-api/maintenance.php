<?php
// GET  /admin-api/maintenance        – Status: aktive Wartung (oder null) + History (letzte 20)
// POST /admin-api/maintenance        – Wartung starten (Body: {message}). 409 bei laufender.
// POST /admin-api/maintenance/end    – Aktive Wartung beenden. 404 wenn keine aktiv.

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance.php';

require_admin();

// Der Router (admin-api/index.php) setzt $action='end', wenn POST /maintenance/end
// adressiert wurde. Sonst null.
$action = $action ?? null;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $action === null) {
    handle_get_maintenance();
} elseif ($method === 'POST' && $action === null) {
    handle_start_maintenance();
} elseif ($method === 'POST' && $action === 'end') {
    handle_end_maintenance();
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// GET /admin-api/maintenance
// ---------------------------------------------------------------------------

function handle_get_maintenance(): never
{
    $pdo = get_db();

    $rows = $pdo->query(
        'SELECT id, message, started_at, ended_at
           FROM ' . tbl('maintenance_windows') . '
          ORDER BY id DESC
          LIMIT 20'
    )->fetchAll();

    $history = [];
    foreach ($rows as $row) {
        $history[] = [
            'id'        => (int) $row['id'],
            'message'   => $row['message'],
            'startedAt' => mysql_to_iso($row['started_at']),
            'endedAt'   => $row['ended_at'] ? mysql_to_iso($row['ended_at']) : null,
        ];
    }

    json_response([
        'active'  => get_active_maintenance($pdo),
        'history' => $history,
    ]);
}

// ---------------------------------------------------------------------------
// POST /admin-api/maintenance
// ---------------------------------------------------------------------------

function handle_start_maintenance(): never
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        json_error('Pflichtfeld fehlt: message');
    }
    if (mb_strlen($message) > 500) {
        json_error('message darf maximal 500 Zeichen lang sein');
    }

    $pdo = get_db();

    // Schon eine offen? Dann 409 + Payload der laufenden zurückgeben.
    $existing = get_active_maintenance($pdo);
    if ($existing !== null) {
        json_response([
            'error'  => 'Es läuft bereits eine Wartung',
            'active' => $existing,
        ], 409);
    }

    $pdo->prepare(
        'INSERT INTO ' . tbl('maintenance_windows') . ' (message, started_at)
                                              VALUES (?, UTC_TIMESTAMP())'
    )->execute([$message]);

    $id = (int) $pdo->lastInsertId();
    get_logger()->warning('Wartung gestartet', ['id' => $id, 'message' => $message]);

    json_response(['id' => $id, 'active' => get_active_maintenance($pdo)], 201);
}

// ---------------------------------------------------------------------------
// POST /admin-api/maintenance/end
// ---------------------------------------------------------------------------

function handle_end_maintenance(): never
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'UPDATE ' . tbl('maintenance_windows') . '
            SET ended_at = UTC_TIMESTAMP()
          WHERE ended_at IS NULL'
    );
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        json_error('Keine aktive Wartung vorhanden', 404);
    }

    get_logger()->warning('Wartung beendet', ['rows' => $stmt->rowCount()]);
    json_response(['ok' => true]);
}
