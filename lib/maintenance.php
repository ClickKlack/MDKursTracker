<?php
/**
 * maintenance.php – Wartungsmodus.
 *
 * Aktive Wartung = einzige Zeile in %%PREFIX%%maintenance_windows mit
 * ended_at IS NULL. Schreibende Endpunkte rufen require_no_maintenance()
 * direkt nach get_db() auf und werden bei aktiver Wartung mit HTTP 503
 * abgebrochen. Lesen bleibt erlaubt.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

/**
 * Liest die aktive Wartung aus der Datenbank.
 * Liefert ['id','message','startedAt'] (ISO-UTC) oder null.
 *
 * @return array{id:int,message:string,startedAt:string}|null
 */
function get_active_maintenance(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        'SELECT id, message, started_at
           FROM ' . tbl('maintenance_windows') . '
          WHERE ended_at IS NULL
          ORDER BY id DESC
          LIMIT 1'
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return format_maintenance_payload($row ?: null);
}

/**
 * Reine Logik-Funktion: Row → API-Payload. Ausgelagert für PHPUnit.
 *
 * @param array<string,mixed>|null $row Erwartet: id, message, started_at (MySQL-DATETIME UTC)
 * @return array{id:int,message:string,startedAt:string}|null
 */
function format_maintenance_payload(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    return [
        'id'        => (int) $row['id'],
        'message'   => (string) $row['message'],
        'startedAt' => mysql_to_iso($row['started_at']),
    ];
}

/**
 * Bricht den Request mit HTTP 503 ab, wenn gerade eine Wartung aktiv ist.
 * In allen schreibenden /api/recordings-Handlern direkt nach get_db() aufrufen.
 */
function require_no_maintenance(PDO $pdo): void
{
    $active = get_active_maintenance($pdo);
    if ($active !== null) {
        json_error('Wartung läuft – ' . $active['message'], 503);
    }
}
