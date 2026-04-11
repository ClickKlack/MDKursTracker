<?php
// POST /admin-api/periods       – Fahrplanschnitt: neue Periode anlegen
// PUT  /admin-api/periods/:id   – Bezeichnung / Startdatum einer Periode korrigieren

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    handle_post_period();
} elseif ($method === 'PUT') {
    handle_put_period($resourceId);
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// POST /admin-api/periods
// ---------------------------------------------------------------------------

function handle_post_period(): never
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    foreach (['name', 'startDate'] as $field) {
        if (empty($body[$field])) {
            json_error("Pflichtfeld fehlt: $field");
        }
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['startDate'])) {
        json_error('startDate muss im Format YYYY-MM-DD angegeben werden');
    }

    $pdo = get_db();
    $pdo->prepare(
        'INSERT INTO ' . tbl('schedule_periods') . ' (name, start_date) VALUES (?, ?)'
    )->execute([$body['name'], $body['startDate']]);

    $id     = (int) $pdo->lastInsertId();
    $stmt   = $pdo->prepare('SELECT id, name, start_date, created_at FROM ' . tbl('schedule_periods') . ' WHERE id = ?');
    $stmt->execute([$id]);
    $period = $stmt->fetch();

    get_logger()->info('Fahrplanschnitt: neue Periode angelegt', [
        'id'   => $id,
        'name' => $period['name'],
    ]);

    json_response([
        'id'        => (int) $period['id'],
        'name'      => $period['name'],
        'startDate' => $period['start_date'],
        'createdAt' => mysql_to_iso($period['created_at']),
    ], 201);
}

// ---------------------------------------------------------------------------
// PUT /admin-api/periods/:id
// ---------------------------------------------------------------------------

function handle_put_period(?int $id): never
{
    if ($id === null) {
        json_error('ID fehlt in der URL', 400);
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    $updates = [];
    $params  = [];

    if (!empty($body['name'])) {
        $updates[] = 'name = ?';
        $params[]  = $body['name'];
    }

    if (!empty($body['startDate'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['startDate'])) {
            json_error('startDate muss im Format YYYY-MM-DD angegeben werden');
        }
        $updates[] = 'start_date = ?';
        $params[]  = $body['startDate'];
    }

    if (empty($updates)) {
        json_error('Mindestens eines der Felder name oder startDate muss angegeben werden');
    }

    $params[] = $id;
    $pdo      = get_db();
    $stmt     = $pdo->prepare(
        'UPDATE ' . tbl('schedule_periods') . ' SET ' . implode(', ', $updates) . ' WHERE id = ?'
    );
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        // Prüfen ob die Periode existiert (rowCount = 0 auch bei unverändertem Wert)
        $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . tbl('schedule_periods') . ' WHERE id = ?');
        $exists->execute([$id]);
        if ((int) $exists->fetchColumn() === 0) {
            json_error('Fahrplanperiode nicht gefunden', 404);
        }
    }

    get_logger()->info('Fahrplanperiode aktualisiert', ['id' => $id]);
    json_response(['ok' => true]);
}
