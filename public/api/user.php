<?php
// /api/user  – Anonymes User-System (Token-basiert, kein Login)
//
// Routen:
//   POST   /api/user                   – Token registrieren / last_seen_at aktualisieren
//   GET    /api/user                   – Eigenes Profil laden
//   PUT    /api/user                   – Profilname setzen
//   GET    /api/user/favorites         – Favoriten-Haltestellen laden
//   POST   /api/user/favorites         – Favorit hinzufügen
//   DELETE /api/user/favorites/{id}    – Favorit entfernen (stop_id als letztes URL-Segment)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/user_helpers.php';

// --- Sub-Pfad und Methode bestimmen -----------------------------------------

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Pfad nach /api/user/  extrahieren: '' | 'favorites' | 'favorites/de:15003:4000'
$after = preg_replace('#^.*?/api/user/?#', '', $uri);
$after = trim($after, '/');

// Routing
if ($after === '' || $after === false) {
    // /api/user
    match ($method) {
        'POST'  => handle_user_init(),
        'GET'   => handle_user_get(),
        'PUT'   => handle_user_put(),
        default => json_error('Methode nicht erlaubt', 405),
    };
} elseif ($after === 'favorites') {
    // /api/user/favorites
    match ($method) {
        'GET'  => handle_favorites_get(),
        'POST' => handle_favorites_post(),
        default => json_error('Methode nicht erlaubt', 405),
    };
} elseif (str_starts_with($after, 'favorites/')) {
    // /api/user/favorites/{encoded_stop_id}
    if ($method !== 'DELETE') {
        json_error('Methode nicht erlaubt', 405);
    }
    $stopId = urldecode(substr($after, strlen('favorites/')));
    handle_favorites_delete($stopId);
} else {
    json_error('Endpunkt nicht gefunden', 404);
}

// --- Token aus Header lesen und validieren -----------------------------------

/**
 * Liest X-User-Token aus dem Request-Header.
 * Gibt den Token zurück oder beendet mit 401 wenn fehlt/ungültig.
 */
function get_user_token(): string
{
    // Apache: HTTP_X_USER_TOKEN; nginx: HTTP_X_USER_TOKEN (nach RFC normalisiert)
    $token = $_SERVER['HTTP_X_USER_TOKEN'] ?? '';

    // UUID v4 ohne Bindestriche (32 Hex-Zeichen) oder mit (36 Zeichen)
    if (!preg_match('/^[0-9a-f]{32,36}$/i', $token)) {
        json_error('Kein gültiger User-Token im Header X-User-Token', 401);
    }

    // Bindestriche entfernen (falls UUID mit Bindestrichen übermittelt)
    return strtolower(str_replace('-', '', $token));
}

// --- Handler: POST /api/user ------------------------------------------------

function handle_user_init(): never
{
    $token = get_user_token();
    $pdo   = get_db();
    $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
    $dev   = parse_device($ua);

    // Prüfen ob User bereits existiert
    $stmt = $pdo->prepare('SELECT id, display_id FROM ' . tbl('users') . ' WHERE token = ?');
    $stmt->execute([$token]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Bestehenden User: last_seen_at + Gerät aktualisieren
        $pdo->prepare(
            'UPDATE ' . tbl('users') .
            ' SET last_seen_at = UTC_TIMESTAMP(), last_user_agent = ?, last_device = ? WHERE token = ?'
        )->execute([$ua ?: null, $dev ?: null, $token]);

        json_response(['displayId' => $existing['display_id'], 'isNew' => false]);
    }

    // Neuen User anlegen – Display-ID ableiten, bei Kollision retry
    $displayId = null;
    for ($i = 0; $i < 5; $i++) {
        $candidate = derive_display_id($token, $i);
        try {
            $pdo->prepare(
                'INSERT INTO ' . tbl('users') .
                ' (token, display_id, last_user_agent, last_device) VALUES (?, ?, ?, ?)'
            )->execute([$token, $candidate, $ua ?: null, $dev ?: null]);
            $displayId = $candidate;
            break;
        } catch (PDOException $e) {
            // 23000 = Integrity constraint violation (UNIQUE)
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Kollision auf display_id oder token
            // Token-Kollision ist ein schwerwiegender Fehler (extrem unwahrscheinlich)
            $dup = $pdo->prepare('SELECT id FROM ' . tbl('users') . ' WHERE token = ?');
            $dup->execute([$token]);
            if ($dup->fetchColumn()) {
                // Token-Kollision: doch schon vorhanden (Race Condition)
                $sel = $pdo->prepare('SELECT display_id FROM ' . tbl('users') . ' WHERE token = ?');
                $sel->execute([$token]);
                $displayId = $sel->fetchColumn();
                break;
            }
            // Display-ID-Kollision: nächsten Offset versuchen
        }
    }

    if ($displayId === null) {
        get_logger()->error('user_init: Konnte keine Display-ID vergeben', ['token_prefix' => substr($token, 0, 8)]);
        json_error('Interner Fehler bei User-Anlage', 500);
    }

    json_response(['displayId' => $displayId, 'isNew' => true], 201);
}

// --- Handler: GET /api/user -------------------------------------------------

function handle_user_get(): never
{
    $token = get_user_token();
    $pdo   = get_db();

    $stmt = $pdo->prepare(
        'SELECT display_id, name, created_at FROM ' . tbl('users') . ' WHERE token = ?'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_error('Unbekannter User-Token', 404);
    }

    json_response([
        'displayId' => $user['display_id'],
        'name'      => $user['name'],
        'createdAt' => $user['created_at'],
    ]);
}

// --- Handler: PUT /api/user -------------------------------------------------

function handle_user_put(): never
{
    $token = get_user_token();
    $body  = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    // name darf leer sein (dann auf NULL setzen → Profil zurücksetzen)
    $name = isset($body['name']) ? trim((string) $body['name']) : null;
    if ($name !== null && mb_strlen($name) > 100) {
        json_error('Name darf maximal 100 Zeichen lang sein');
    }
    if ($name === '') {
        $name = null;
    }

    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
    $dev = parse_device($ua);

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'UPDATE ' . tbl('users') .
        ' SET name = ?, last_seen_at = UTC_TIMESTAMP(), last_user_agent = ?, last_device = ?
          WHERE token = ?'
    );
    $stmt->execute([$name, $ua ?: null, $dev ?: null, $token]);

    if ($stmt->rowCount() === 0) {
        json_error('Unbekannter User-Token', 404);
    }

    json_response(['ok' => true]);
}

// --- Handler: GET /api/user/favorites ---------------------------------------

function handle_favorites_get(): never
{
    $token = get_user_token();
    $pdo   = get_db();

    $stmt = $pdo->prepare(
        'SELECT stop_id, stop_name, created_at
           FROM ' . tbl('user_favorites') . '
          WHERE user_token = ?
          ORDER BY created_at ASC'
    );
    $stmt->execute([$token]);

    $rows = array_map(static fn($r) => [
        'stopId'    => $r['stop_id'],
        'stopName'  => $r['stop_name'],
        'createdAt' => $r['created_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    json_response($rows);
}

// --- Handler: POST /api/user/favorites --------------------------------------

function handle_favorites_post(): never
{
    $token = get_user_token();
    $body  = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    $stopId   = trim((string) ($body['stopId']   ?? ''));
    $stopName = trim((string) ($body['stopName'] ?? ''));

    if ($stopId === '' || $stopName === '') {
        json_error('stopId und stopName sind erforderlich');
    }
    if (strlen($stopId) > 20) {
        json_error('stopId zu lang (max. 20 Zeichen)');
    }

    $pdo = get_db();

    // Sicherstellen, dass der User existiert
    $check = $pdo->prepare('SELECT id FROM ' . tbl('users') . ' WHERE token = ?');
    $check->execute([$token]);
    if (!$check->fetchColumn()) {
        json_error('Unbekannter User-Token', 404);
    }

    try {
        $pdo->prepare(
            'INSERT IGNORE INTO ' . tbl('user_favorites') .
            ' (user_token, stop_id, stop_name) VALUES (?, ?, ?)'
        )->execute([$token, $stopId, mb_substr($stopName, 0, 100)]);
    } catch (PDOException $e) {
        get_logger()->error('favorites_post: DB-Fehler', ['exception' => $e->getMessage()]);
        json_error('Datenbankfehler', 500);
    }

    json_response(['ok' => true], 201);
}

// --- Handler: DELETE /api/user/favorites/{stop_id} --------------------------

function handle_favorites_delete(string $stopId): never
{
    $token = get_user_token();

    if ($stopId === '') {
        json_error('stop_id fehlt in der URL');
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'DELETE FROM ' . tbl('user_favorites') . ' WHERE user_token = ? AND stop_id = ?'
    );
    $stmt->execute([$token, $stopId]);

    // 404 wenn Favorit nicht gefunden – tolerant (Idempotenz)
    json_response(['ok' => true]);
}
