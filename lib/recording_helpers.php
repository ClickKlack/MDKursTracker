<?php
/**
 * recording_helpers.php – gemeinsame Validierung für Erfassungs-Mutationen
 * (PUT / DELETE / RESTORE in public/api/recordings.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calendar.php';
require_once __DIR__ . '/response.php';

/**
 * Reine Logik-Funktion: prüft, ob eine Erfassung durch den Token-Inhaber
 * mutiert werden darf. Liefert NULL bei "ok" oder ein Tupel
 * ['status' => int, 'message' => string] bei Verletzung.
 *
 * Ausgelagert für PHPUnit (kein DB-Zugriff nötig). Erwartetes $row:
 *   ['user_token' => ?string, 'period_id' => int|string]
 *
 * @param array<string,mixed>|null $row             Recording-Row aus dem JOIN, oder null wenn nicht vorhanden
 * @param string|null              $token           Übergebener X-User-Token (lower-case, ohne Bindestriche)
 * @param int                      $activePeriodId  Aktuell aktive Periode
 *
 * @return array{status:int,message:string}|null
 */
function recording_modifiable_reason(?array $row, ?string $token, int $activePeriodId): ?array
{
    if ($token === null) {
        return ['status' => 401, 'message' => 'X-User-Token-Header fehlt oder ist ungültig'];
    }
    if ($row === null) {
        return ['status' => 404, 'message' => 'Erfassung nicht gefunden'];
    }
    if (($row['user_token'] ?? null) !== $token) {
        return ['status' => 403, 'message' => 'Keine Berechtigung für diese Erfassung'];
    }
    if ((int) ($row['period_id'] ?? 0) !== $activePeriodId) {
        return ['status' => 403, 'message' => 'Erfassungen älterer Perioden können nicht bearbeitet werden'];
    }
    return null;
}

/**
 * Reine Logik-Funktion: ermittelt limit/offset für die paginierte
 * Erfassungs-Liste. Clamped negative Werte und übersteuert das Limit
 * gegen einen konfigurierbaren Maximalwert. Ungültige Eingaben (nicht-numerisch)
 * fallen auf die Defaults zurück.
 *
 * Ausgelagert für PHPUnit (kein DB-Zugriff nötig).
 *
 * @param array<string,mixed> $query    Roher $_GET-ähnlicher Array
 * @param int                 $default  Default für `limit`, wenn nicht gesetzt
 * @param int                 $max      Obergrenze für `limit`
 *
 * @return array{limit:int, offset:int}
 */
/**
 * Predikat: Gilt eine neu eingehende Erfassung als Korrektur einer bestehenden?
 *
 * Match-Schlüssel: (user_token, trip_id, service_date, stop_id, departure_planned).
 * Bewusst OHNE course_number – sonst würde gerade die Korrektur einer falsch
 * erfassten Kursnummer (häufigster Fehlerfall) nicht greifen.
 *
 * trip_id ist Pflicht im Match, sonst würden an Stops mit mehreren Linien zur
 * selben Minute (Umsteigeknoten wie Hasselbachplatz) zwei verschiedene Fahrten
 * sich gegenseitig als "Korrektur" überschreiben.
 *
 * Anonyme Erfassungen (user_token === null) werden nie verglichen – sonst
 * würden sich fremde Erfassungen gegenseitig löschen.
 *
 * Spiegelt die WHERE-Klausel in handle_post_recording wider; Unit-Test sichert
 * die Regel gegen Regression ab.
 *
 * @param array<string,mixed> $existing  Bestehende Erfassung aus DB
 * @param array<string,mixed> $candidate Eingehende Erfassung
 */
function recording_dedup_match(array $existing, array $candidate): bool
{
    $userToken = $candidate['user_token'] ?? null;
    if ($userToken === null || $userToken === '') {
        return false;
    }
    $keys = ['user_token', 'trip_id', 'service_date', 'stop_id', 'departure_planned'];
    foreach ($keys as $key) {
        if (($existing[$key] ?? null) !== ($candidate[$key] ?? null)) {
            return false;
        }
    }
    return true;
}

function parse_pagination_params(array $query, int $default = 50, int $max = 200): array
{
    $limit = $default;
    if (isset($query['limit']) && is_numeric($query['limit'])) {
        $limit = (int) $query['limit'];
        if ($limit < 1)    $limit = 1;
        if ($limit > $max) $limit = $max;
    }

    $offset = 0;
    if (isset($query['offset']) && is_numeric($query['offset'])) {
        $offset = (int) $query['offset'];
        if ($offset < 0) $offset = 0;
    }

    return ['limit' => $limit, 'offset' => $offset];
}

/**
 * Lädt die Erfassungs-Stammdaten und ruft bei Verstoß json_error() auf.
 * Wird von PUT, DELETE und POST .../restore verwendet.
 *
 * Liefert die Row inkl. zusätzlich `deleted_at` zur weiteren Verwendung.
 *
 * @return array<string,mixed>  ['id', 'user_token', 'period_id', 'deleted_at']
 */
function assert_recording_modifiable(PDO $pdo, int $recordingId, ?string $token): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_token, r.deleted_at, t.period_id
           FROM ' . tbl('recordings') . ' r
           JOIN ' . tbl('trips') . ' t ON r.trip_id = t.id
          WHERE r.id = ?'
    );
    $stmt->execute([$recordingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $activePeriodId = get_active_period_id($pdo);
    $reason = recording_modifiable_reason($row ?: null, $token, $activePeriodId);
    if ($reason !== null) {
        json_error($reason['message'], $reason['status']);
    }

    return $row;
}
