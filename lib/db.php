<?php
// PDO-Verbindung zur MariaDB.
// Beim ersten Aufruf wird geprüft, ob schedule_periods leer ist –
// falls ja, wird automatisch eine initiale Periode angelegt.

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/response.php';

/**
 * Gibt den Tabellennamen mit konfiguriertem Prefix zurück.
 * Beispiel: tbl('trips') → 'trammd_trips' bei db_prefix = 'trammd_'
 */
function tbl(string $name): string
{
    static $prefix = null;
    if ($prefix === null) {
        $config = require dirname(__DIR__) . '/config.php';
        $prefix = $config['db_prefix'] ?? '';
    }
    return $prefix . $name;
}

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
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . tbl('schedule_periods'))->fetchColumn();

    if ($count === 0) {
        $pdo->prepare(
            'INSERT INTO ' . tbl('schedule_periods') . ' (name, start_date) VALUES (?, CURDATE())'
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
        'SELECT id FROM ' . tbl('schedule_periods') . ' ORDER BY id DESC LIMIT 1'
    )->fetch();

    return (int) $row['id'];
}

/**
 * Konvertiert einen MySQL-DATETIME-String (UTC, "YYYY-MM-DD HH:MM:SS")
 * in ISO-8601-UTC ("YYYY-MM-DDTHH:MM:SSZ") – oder null bei null.
 */
function mysql_to_iso(?string $dt): ?string
{
    if ($dt === null) {
        return null;
    }
    return (new DateTimeImmutable($dt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Konvertiert einen ISO-8601-String in einen MySQL-DATETIME-String (UTC).
 * Beispiel: "2026-03-24T14:32:00Z" → "2026-03-24 14:32:00"
 */
function iso_to_mysql(string $iso): string
{
    return (new DateTimeImmutable($iso))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}
