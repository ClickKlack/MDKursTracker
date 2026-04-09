<?php
// GET    /admin-api/school-holidays       – Alle Schulferien auflisten
// POST   /admin-api/school-holidays       – Neuen Eintrag anlegen
// PUT    /admin-api/school-holidays/:id   – Eintrag aktualisieren
// DELETE /admin-api/school-holidays/:id   – Eintrag löschen

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handle_get_school_holidays();
} elseif ($method === 'POST') {
    handle_post_school_holiday();
} elseif ($method === 'PUT') {
    handle_put_school_holiday($resourceId);
} elseif ($method === 'DELETE') {
    handle_delete_school_holiday($resourceId);
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// GET /admin-api/school-holidays
// ---------------------------------------------------------------------------

function handle_get_school_holidays(): never
{
    $pdo  = get_db();
    $rows = $pdo->query(
        'SELECT id, name, date_from, date_to FROM school_holidays ORDER BY date_from ASC'
    )->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id'       => (int) $row['id'],
            'name'     => $row['name'],
            'dateFrom' => $row['date_from'],
            'dateTo'   => $row['date_to'],
        ];
    }

    json_response($result);
}

// ---------------------------------------------------------------------------
// POST /admin-api/school-holidays
// ---------------------------------------------------------------------------

function handle_post_school_holiday(): never
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    foreach (['name', 'dateFrom', 'dateTo'] as $field) {
        if (empty($body[$field])) {
            json_error("Pflichtfeld fehlt: $field");
        }
    }

    // Datumsformat und Reihenfolge prüfen
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['dateFrom'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['dateTo'])) {
        json_error('dateFrom und dateTo müssen im Format YYYY-MM-DD angegeben werden');
    }

    if ($body['dateTo'] < $body['dateFrom']) {
        json_error('dateTo darf nicht vor dateFrom liegen');
    }

    $pdo = get_db();
    $pdo->prepare(
        'INSERT INTO school_holidays (name, date_from, date_to) VALUES (?, ?, ?)'
    )->execute([$body['name'], $body['dateFrom'], $body['dateTo']]);

    $id = (int) $pdo->lastInsertId();
    get_logger()->info('Schulferien angelegt', ['id' => $id, 'name' => $body['name']]);

    json_response(['id' => $id], 201);
}

// ---------------------------------------------------------------------------
// PUT /admin-api/school-holidays/:id
// ---------------------------------------------------------------------------

function handle_put_school_holiday(?int $id): never
{
    if ($id === null) {
        json_error('ID fehlt in der URL', 400);
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    foreach (['name', 'dateFrom', 'dateTo'] as $field) {
        if (empty($body[$field])) {
            json_error("Pflichtfeld fehlt: $field");
        }
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['dateFrom'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['dateTo'])) {
        json_error('dateFrom und dateTo müssen im Format YYYY-MM-DD angegeben werden');
    }

    if ($body['dateTo'] < $body['dateFrom']) {
        json_error('dateTo darf nicht vor dateFrom liegen');
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'UPDATE school_holidays SET name = ?, date_from = ?, date_to = ? WHERE id = ?'
    );
    $stmt->execute([$body['name'], $body['dateFrom'], $body['dateTo'], $id]);

    if ($stmt->rowCount() === 0) {
        // Prüfen ob der Datensatz überhaupt existiert
        $exists = $pdo->prepare('SELECT COUNT(*) FROM school_holidays WHERE id = ?');
        $exists->execute([$id]);
        if ((int) $exists->fetchColumn() === 0) {
            json_error('Schulferien-Eintrag nicht gefunden', 404);
        }
    }

    get_logger()->info('Schulferien aktualisiert', ['id' => $id]);
    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// DELETE /admin-api/school-holidays/:id
// ---------------------------------------------------------------------------

function handle_delete_school_holiday(?int $id): never
{
    if ($id === null) {
        json_error('ID fehlt in der URL', 400);
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare('DELETE FROM school_holidays WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        json_error('Schulferien-Eintrag nicht gefunden', 404);
    }

    get_logger()->info('Schulferien gelöscht', ['id' => $id]);
    json_response(['ok' => true]);
}
