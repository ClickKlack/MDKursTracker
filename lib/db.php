<?php
// PDO-Verbindung zur MariaDB.
// Beim ersten Aufruf wird geprüft, ob schedule_periods leer ist –
// falls ja, wird automatisch eine initiale Periode angelegt.

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/response.php';

/**
 * Gibt die gemeinsame PDO-Instanz zurück (Singleton pro Request).
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config.php';

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        get_logger()->error('Datenbankverbindung fehlgeschlagen', [
            'exception' => $e->getMessage(),
        ]);
        json_error('Datenbankverbindung fehlgeschlagen', 500);
    }

    // Initiale Periode anlegen, wenn noch keine vorhanden ist
    init_period($pdo);

    return $pdo;
}

/**
 * Legt eine initiale Fahrplanperiode an, falls die Tabelle leer ist.
 */
function init_period(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM schedule_periods')->fetchColumn();

    if ($count === 0) {
        $pdo->prepare(
            'INSERT INTO schedule_periods (name, start_date) VALUES (?, CURDATE())'
        )->execute(['Fahrplan (initial)']);

        get_logger()->info('Initiale Fahrplanperiode automatisch angelegt');
    }
}

/**
 * Gibt die ID der aktiven (neuesten) Fahrplanperiode zurück.
 */
function get_active_period_id(PDO $pdo): int
{
    $row = $pdo->query(
        'SELECT id FROM schedule_periods ORDER BY id DESC LIMIT 1'
    )->fetch();

    return (int) $row['id'];
}
