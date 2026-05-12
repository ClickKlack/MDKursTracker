<?php
/**
 * announcements.php – Nachrichten-Banner für die App.
 *
 * Der Endpunkt liefert alle aktiven Nachrichten (expires_at > now), sortiert
 * id DESC. Das Frontend rendert davon die jüngste, die der User noch nicht
 * weggeklickt hat – so rückt nach Dismissal die nächst-jüngere nach, statt
 * den User mit dem Banner-Slot leer zu lassen.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Liefert alle aktiven Nachrichten als Liste (id DESC).
 *
 * @return list<array{id:int,body:string,expiresAt:string}>
 */
function get_active_announcements(PDO $pdo, DateTimeImmutable $now): array
{
    $stmt = $pdo->prepare(
        'SELECT id, body, expires_at
           FROM ' . tbl('announcements') . '
          WHERE expires_at > :now
          ORDER BY id DESC'
    );
    $stmt->execute([':now' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $payload = format_announcement_row($row);
        if ($payload !== null) {
            $result[] = $payload;
        }
    }
    return $result;
}

/**
 * Reine Logik-Funktion: Row → API-Payload. Ausgelagert für PHPUnit.
 *
 * @param array<string,mixed>|null $row Erwartet: id, body, expires_at (MySQL-DATETIME UTC)
 * @return array{id:int,body:string,expiresAt:string}|null
 */
function format_announcement_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    return [
        'id'        => (int) $row['id'],
        'body'      => (string) $row['body'],
        'expiresAt' => mysql_to_iso($row['expires_at']),
    ];
}

/**
 * Wählt aus einer Liste vor-gefilterter Kandidaten (alle mit expires_at > now)
 * den jüngsten Eintrag. Ausgelagert für PHPUnit (kein DB-Zugriff).
 *
 * @param list<array{id:int,expires_at:string,body?:string}> $rows
 * @return array{id:int,expires_at:string,body?:string}|null
 */
function pick_latest_announcement(array $rows): ?array
{
    if (count($rows) === 0) {
        return null;
    }
    usort($rows, static fn($a, $b) => $b['id'] <=> $a['id']);
    return $rows[0];
}
