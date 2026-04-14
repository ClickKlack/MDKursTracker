<?php
// Session-basierte Authentifizierung für Admin-Endpunkte.

require_once __DIR__ . '/response.php';

/** Session-Gültigkeit: 30 Tage in Sekunden */
const ADMIN_SESSION_LIFETIME = 30 * 24 * 60 * 60;

/**
 * Setzt Session-Parameter (Lifetime, Cookie) – muss vor session_start() aufgerufen werden.
 */
function configure_session(): void
{
    ini_set('session.gc_maxlifetime', (string) ADMIN_SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => ADMIN_SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => true,   // nur über HTTPS
        'httponly' => true,   // kein JS-Zugriff
        'samesite' => 'Lax',
    ]);
}

/**
 * Prüft, ob eine gültige Admin-Session vorhanden ist.
 * Bricht den Request mit HTTP 401 ab, wenn nicht authentifiziert.
 */
function require_admin(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        configure_session();
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
        configure_session();
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
