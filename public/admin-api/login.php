<?php
// POST /admin-api/login – Admin-Login mit PHP-Session

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Methode nicht erlaubt', 405);
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body) || !isset($body['password']) || $body['password'] === '') {
    json_error('Pflichtfeld fehlt: password');
}

if (!verify_admin_password((string) $body['password'])) {
    get_logger()->warning('Admin-Login fehlgeschlagen (falsches Passwort)');
    json_error('Ungültiges Passwort', 401);
}

start_admin_session();
get_logger()->info('Admin-Login erfolgreich');
json_response(['ok' => true]);
