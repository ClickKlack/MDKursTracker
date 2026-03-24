<?php
// Session-basierte Authentifizierung für Admin-Endpunkte.

require_once __DIR__ . '/response.php';

/**
 * Prüft, ob eine gültige Admin-Session vorhanden ist.
 * Bricht den Request mit HTTP 401 ab, wenn nicht authentifiziert.
 */
function require_admin(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['admin'])) {
        json_error('Nicht authentifiziert', 401);
    }
}

/**
 * Prüft das übergebene Passwort gegen den konfigurierten bcrypt-Hash.
 * Gibt true zurück, wenn das Passwort korrekt ist.
 */
function verify_admin_password(string $password): bool
{
    $config = require dirname(__DIR__) . '/config.php';
    return password_verify($password, $config['admin_hash']);
}

/**
 * Startet eine neue Admin-Session nach erfolgreichem Login.
 */
function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Session-ID nach Login regenerieren (verhindert Session-Fixation)
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
}

/**
 * Beendet die Admin-Session.
 */
function destroy_admin_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];
    session_destroy();
}
