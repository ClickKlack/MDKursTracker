<?php
// GET    /admin-api/announcements       – Alle Nachrichten auflisten (DESC nach created_at)
// POST   /admin-api/announcements       – Neue Nachricht anlegen
// PUT    /admin-api/announcements/:id   – Nachricht aktualisieren
// DELETE /admin-api/announcements/:id   – Nachricht löschen

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handle_get_announcements();
} elseif ($method === 'POST') {
    handle_post_announcement();
} elseif ($method === 'PUT') {
    handle_put_announcement($resourceId);
} elseif ($method === 'DELETE') {
    handle_delete_announcement($resourceId);
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// GET /admin-api/announcements
// ---------------------------------------------------------------------------

function handle_get_announcements(): never
{
    $pdo  = get_db();
    $rows = $pdo->query(
        'SELECT id, body, expires_at, created_at
           FROM ' . tbl('announcements') . '
          ORDER BY created_at DESC, id DESC'
    )->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id'        => (int) $row['id'],
            'body'      => $row['body'],
            'expiresAt' => mysql_to_iso($row['expires_at']),
            'createdAt' => mysql_to_iso($row['created_at']),
        ];
    }

    json_response($result);
}

// ---------------------------------------------------------------------------
// POST /admin-api/announcements
// ---------------------------------------------------------------------------

function handle_post_announcement(): never
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    $text     = trim((string) ($body['body'] ?? ''));
    $expires  = (string) ($body['expiresAt'] ?? '');

    if ($text === '') {
        json_error('Pflichtfeld fehlt: body');
    }
    if ($expires === '') {
        json_error('Pflichtfeld fehlt: expiresAt');
    }

    $expiresUtc = parse_iso_utc_or_fail($expires, 'expiresAt');

    $pdo = get_db();
    $pdo->prepare(
        'INSERT INTO ' . tbl('announcements') . ' (body, expires_at) VALUES (?, ?)'
    )->execute([$text, $expiresUtc]);

    $id = (int) $pdo->lastInsertId();
    get_logger()->info('Nachricht angelegt', ['id' => $id, 'expires_at' => $expiresUtc]);

    json_response(['id' => $id], 201);
}

// ---------------------------------------------------------------------------
// PUT /admin-api/announcements/:id
// ---------------------------------------------------------------------------

function handle_put_announcement(?int $id): never
{
    if ($id === null) {
        json_error('ID fehlt in der URL', 400);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    $text    = trim((string) ($body['body'] ?? ''));
    $expires = (string) ($body['expiresAt'] ?? '');

    if ($text === '') {
        json_error('Pflichtfeld fehlt: body');
    }
    if ($expires === '') {
        json_error('Pflichtfeld fehlt: expiresAt');
    }

    $expiresUtc = parse_iso_utc_or_fail($expires, 'expiresAt');

    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'UPDATE ' . tbl('announcements') . ' SET body = ?, expires_at = ? WHERE id = ?'
    );
    $stmt->execute([$text, $expiresUtc, $id]);

    if ($stmt->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . tbl('announcements') . ' WHERE id = ?');
        $exists->execute([$id]);
        if ((int) $exists->fetchColumn() === 0) {
            json_error('Nachricht nicht gefunden', 404);
        }
    }

    get_logger()->info('Nachricht aktualisiert', ['id' => $id]);
    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// DELETE /admin-api/announcements/:id
// ---------------------------------------------------------------------------

function handle_delete_announcement(?int $id): never
{
    if ($id === null) {
        json_error('ID fehlt in der URL', 400);
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare('DELETE FROM ' . tbl('announcements') . ' WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        json_error('Nachricht nicht gefunden', 404);
    }

    get_logger()->info('Nachricht gelöscht', ['id' => $id]);
    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// Hilfsfunktion
// ---------------------------------------------------------------------------

/**
 * Parst einen ISO-8601-String und liefert MySQL-DATETIME (UTC) zurück.
 * Bricht bei ungültigem Format mit json_error() ab.
 */
function parse_iso_utc_or_fail(string $iso, string $field): string
{
    try {
        return iso_to_mysql($iso);
    } catch (Exception $e) {
        json_error("$field muss ein gültiges ISO-8601-Datum sein");
    }
}
