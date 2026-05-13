<?php
// GET    /api/recordings                  – Erfassungen abrufen (gefiltert)
// POST   /api/recordings                  – Neue Kursnummer-Erfassung speichern
// PUT    /api/recordings/{id}             – Eigene Erfassung bearbeiten (Kursnummer + Kommentar)
// DELETE /api/recordings/{id}             – Eigene Erfassung soft-löschen (deleted_at setzen)
// POST   /api/recordings/{id}/restore     – Soft-Delete rückgängig machen (Undo aus Snackbar)
// GET    /api/recordings/{id}/route       – Laufweg einer Erfassung

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';
require_once dirname(__DIR__, 2) . '/lib/user_helpers.php';
require_once dirname(__DIR__, 2) . '/lib/fingerprint.php';
require_once dirname(__DIR__, 2) . '/lib/recording_helpers.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance.php';

$method = $_SERVER['REQUEST_METHOD'];

// Sub-Pfad erkennen: /api/recordings/{id}/route, .../restore oder /api/recordings/{id}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/api/recordings/(\d+)/route$#', $uri, $m)) {
    if ($method !== 'GET') {
        json_error('Methode nicht erlaubt', 405);
    }
    handle_get_route((int) $m[1]);
}

if (preg_match('#/api/recordings/(\d+)/restore$#', $uri, $m)) {
    if ($method !== 'POST') {
        json_error('Methode nicht erlaubt', 405);
    }
    handle_restore_recording((int) $m[1]);
}

if (preg_match('#/api/recordings/(\d+)$#', $uri, $m)) {
    if ($method === 'PUT') {
        handle_put_recording((int) $m[1]);
    }
    if ($method === 'DELETE') {
        handle_delete_recording((int) $m[1]);
    }
    json_error('Methode nicht erlaubt', 405);
}

if ($method === 'GET') {
    handle_get_recordings();
} elseif ($method === 'POST') {
    handle_post_recording();
} else {
    json_error('Methode nicht erlaubt', 405);
}

// ---------------------------------------------------------------------------
// Hilfsfunktion: X-User-Token aus Header lesen (optional, kein Fehler wenn fehlt)
// ---------------------------------------------------------------------------

function get_request_token(): ?string
{
    $token = $_SERVER['HTTP_X_USER_TOKEN'] ?? '';
    if ($token === '') {
        return null;
    }
    // UUID v4 ohne Bindestriche (32 Hex-Zeichen) oder mit (36 Zeichen)
    if (!preg_match('/^[0-9a-f]{32,36}$/i', $token)) {
        return null;
    }
    return strtolower(str_replace('-', '', $token));
}

// ---------------------------------------------------------------------------
// GET /api/recordings
// ---------------------------------------------------------------------------

function handle_get_recordings(): never
{
    $pdo      = get_db();
    $periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : get_active_period_id($pdo);

    // Optionale Filter aufbauen – soft-deleted Erfassungen niemals mitliefern
    $where  = ['t.period_id = :period_id', 'r.deleted_at IS NULL'];
    $params = [':period_id' => $periodId];

    if (!empty($_GET['line'])) {
        $where[]         = 't.line = :line';
        $params[':line'] = $_GET['line'];
    }

    if (!empty($_GET['day_type'])) {
        $allowed = ['MO-FR', 'SA', 'SO', 'SF'];
        if (!in_array($_GET['day_type'], $allowed, true)) {
            json_error('Ungültiger day_type');
        }
        $where[]             = 't.day_type = :day_type';
        $params[':day_type'] = $_GET['day_type'];
    }

    if (!empty($_GET['date_from'])) {
        $where[]              = 'r.service_date >= :date_from';
        $params[':date_from'] = $_GET['date_from'];
    }

    if (!empty($_GET['date_to'])) {
        $where[]            = 'r.service_date <= :date_to';
        $params[':date_to'] = $_GET['date_to'];
    }

    $whereClause = implode(' AND ', $where);

    // Pagination: limit (default 50, max 200) + offset; clamping in Helper
    $page = parse_pagination_params($_GET);

    // Total-Count für hasMore/Anzeige – ohne JOIN auf stops, das bremst nur.
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM ' . tbl('recordings') . ' r
           JOIN ' . tbl('trips') . ' t ON r.trip_id = t.id
          WHERE ' . $whereClause
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Page-SELECT mit stabilem Sort: r.id DESC als Tiebreaker bei gleicher recorded_at.
    $pageParams = $params;
    $pageParams[':limit']  = $page['limit'];
    $pageParams[':offset'] = $page['offset'];

    $stmt = $pdo->prepare(
        'SELECT
             r.id,
             r.recorded_at,
             t.line,
             t.direction,
             t.service_nr,
             t.day_type,
             r.service_date,
             r.stop_id,
             s.name         AS stop,
             r.departure_planned,
             r.departure_actual,
             r.course_number,
             r.user_token,
             r.comment,
             t.manual_course_number,
             COALESCE(
                 t.manual_course_number,
                 (
                     SELECT r2.course_number
                     FROM ' . tbl('recordings') . ' r2
                     WHERE r2.trip_id = t.id AND r2.deleted_at IS NULL
                     GROUP BY r2.course_number
                     ORDER BY COUNT(*) DESC, MIN(r2.recorded_at) ASC
                     LIMIT 1
                 )
             ) AS active_course_number
         FROM ' . tbl('recordings') . ' r
         JOIN ' . tbl('trips') . ' t ON r.trip_id = t.id
         JOIN ' . tbl('stops') . ' s ON r.stop_id = s.hafas_id
         WHERE ' . $whereClause . '
         ORDER BY r.recorded_at DESC, r.id DESC
         LIMIT :limit OFFSET :offset'
    );
    // limit/offset müssen als Integer gebunden werden (sonst quoted PDO sie als String)
    foreach ($pageParams as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();

    // Eigenen Token für isOwn-Vergleich – Token nie im JSON ausgeben
    $ownToken = get_request_token();

    $rows  = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id'                 => (int) $row['id'],
            'recordedAt'         => mysql_to_iso($row['recorded_at']),
            'line'               => $row['line'],
            'direction'          => $row['direction'],
            'serviceNr'          => $row['service_nr'],
            'dayType'            => $row['day_type'],
            'serviceDate'        => $row['service_date'],
            'stopId'             => $row['stop_id'],
            'stop'               => $row['stop'],
            'departurePlanned'   => mysql_to_iso($row['departure_planned']),
            'departureActual'    => mysql_to_iso($row['departure_actual']),
            'courseNumber'       => $row['course_number'],
            'activeCourseNumber' => $row['active_course_number'],
            'manualCourseNumber' => $row['manual_course_number'],
            'comment'            => $row['comment'],
            // isOwn: true nur wenn Token übermittelt und zur Erfassung passt
            'isOwn'              => $ownToken !== null && $row['user_token'] === $ownToken,
        ];
    }

    json_response([
        'items'   => $items,
        'total'   => $total,
        'limit'   => $page['limit'],
        'offset'  => $page['offset'],
        'hasMore' => ($page['offset'] + count($items)) < $total,
    ]);
}

// ---------------------------------------------------------------------------
// POST /api/recordings
// ---------------------------------------------------------------------------

function handle_post_recording(): never
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    // Pflichtfelder prüfen
    $required = ['hafasTripId', 'serviceNr', 'line', 'direction', 'stopId',
                 'serviceDate', 'departurePlanned', 'courseNumber'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || (string) $body[$field] === '') {
            json_error("Pflichtfeld fehlt: $field");
        }
    }

    // Feldlängen gegen DB-Schema prüfen (verhindert stille Trunkierungen)
    $maxLengths = [
        'hafasTripId' => 512,
        'serviceNr'   => 20,
        'line'        => 10,
        'direction'   => 100,
        'stopId'      => 20,
    ];
    foreach ($maxLengths as $field => $max) {
        if (mb_strlen((string) $body[$field]) > $max) {
            json_error("$field darf maximal $max Zeichen lang sein");
        }
    }

    // Kursnummer validieren: zweistellig, 00–99
    if (!preg_match('/^[0-9]{2}$/', $body['courseNumber'])) {
        json_error('Kursnummer muss zweistellig im Format 00–99 sein');
    }

    // serviceDate-Format validieren: YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['serviceDate'])) {
        json_error('serviceDate muss das Format YYYY-MM-DD haben');
    }

    // departurePlanned: muss ISO-8601-String sein
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['departurePlanned'])) {
        json_error('departurePlanned muss ISO-8601-UTC sein (YYYY-MM-DDTHH:MM:SSZ)');
    }

    // departureActual: optional, aber wenn gesetzt, muss es valides Format haben
    if (isset($body['departureActual']) && $body['departureActual'] !== null
        && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['departureActual'])) {
        json_error('departureActual muss ISO-8601-UTC sein (YYYY-MM-DDTHH:MM:SSZ)');
    }

    $pdo      = get_db();
    require_no_maintenance($pdo);
    $periodId = get_active_period_id($pdo);

    // Vollständigen Laufweg über HAFAS laden (vor Trip-Anlage, da Fingerprint daraus berechnet wird)
    try {
        $tripStops = hafas_trip($body['hafasTripId']);
    } catch (RuntimeException $e) {
        get_logger()->error('recordings POST: HAFAS-Trip-Fehler', [
            'hafasTripId' => $body['hafasTripId'],
            'exception'   => $e->getMessage(),
        ]);
        json_error('HAFAS-Tripabfrage fehlgeschlagen: ' . $e->getMessage(), 500);
    }

    // Betriebsdatum autoritativ aus dem Trip-Start ableiten – das Frontend liefert
    // serviceDate auf Basis des Erfasser-Halts, was bei Mitternachts-Übergängen
    // den falschen Tag ergibt (Sonntag-Nachtfahrt mit Erfassung 00:15 wäre sonst
    // Montag/MO-FR statt Sonntag/SO). Frontend-Wert bleibt als Plausibilitäts-Hinweis.
    $derivedDate = derive_service_date($tripStops);
    if ($derivedDate !== null && $derivedDate !== $body['serviceDate']) {
        get_logger()->info('recordings POST: serviceDate aus Trip-Start korrigiert', [
            'frontend_value' => $body['serviceDate'],
            'derived_value'  => $derivedDate,
            'hafasTripId'    => $body['hafasTripId'],
            'stopId'         => $body['stopId'],
        ]);
        $body['serviceDate'] = $derivedDate;
    }

    // Wochentagstyp aus dem (ggf. korrigierten) Betriebsdatum berechnen
    $serviceDate = DateTimeImmutable::createFromFormat('Y-m-d', $body['serviceDate']);
    $dayType = getDayType($serviceDate, $pdo);

    // Fingerprints berechnen
    $fp = compute_fingerprints($tripStops);

    // Trip per schedule_fingerprint suchen (primär) oder per service_nr (Fallback)
    $tripId = null;

    if ($fp['schedule'] !== null) {
        // Fingerprint-basierter Lookup
        $tripStmt = $pdo->prepare(
            'SELECT id, last_hafas_trip_id, service_nr FROM ' . tbl('trips') . '
             WHERE period_id = ? AND schedule_fingerprint = ? AND day_type = ?'
        );
        $tripStmt->execute([$periodId, $fp['schedule'], $dayType]);
        $existingTrip = $tripStmt->fetch();

        if ($existingTrip) {
            $tripId = (int) $existingTrip['id'];
            // last_hafas_trip_id und service_nr nachführen wenn abgewichen
            if ($existingTrip['last_hafas_trip_id'] !== $body['hafasTripId']
                || $existingTrip['service_nr'] !== $body['serviceNr']) {
                $pdo->prepare(
                    'UPDATE ' . tbl('trips') . '
                     SET last_hafas_trip_id = ?, service_nr = ?
                     WHERE id = ?'
                )->execute([$body['hafasTripId'], $body['serviceNr'], $tripId]);
            }
        } else {
            // Neuen Trip mit Fingerprints anlegen
            $pdo->prepare(
                'INSERT INTO ' . tbl('trips') . '
                     (period_id, service_nr, line, day_type, direction,
                      path_fingerprint, schedule_fingerprint, last_hafas_trip_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $periodId, $body['serviceNr'], $body['line'], $dayType, $body['direction'],
                $fp['path'], $fp['schedule'], $body['hafasTripId'],
            ]);
            $tripId = (int) $pdo->lastInsertId();
        }
    } else {
        // Kein schedule_fingerprint (z.B. alle Zeiten fehlen) → service_nr-Fallback
        $pdo->prepare(
            'INSERT IGNORE INTO ' . tbl('trips') . '
                 (period_id, service_nr, line, day_type, direction, last_hafas_trip_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$periodId, $body['serviceNr'], $body['line'], $dayType, $body['direction'],
                    $body['hafasTripId']]);
        $fbStmt = $pdo->prepare(
            'SELECT id FROM ' . tbl('trips') . '
             WHERE period_id = ? AND service_nr = ? AND line = ? AND day_type = ?'
        );
        $fbStmt->execute([$periodId, $body['serviceNr'], $body['line'], $dayType]);
        $tripId = (int) $fbStmt->fetchColumn();
    }

    // Haltestellenname und kanonische HAFAS-ID des Erfassungs-Stops ermitteln.
    $recordingStopId   = $body['stopId'];
    $recordingStopName = $body['stopId'];

    foreach ($tripStops as $ts) {
        if ($ts['stopId'] === $body['stopId']) {
            $recordingStopId   = $ts['stopId'];
            $recordingStopName = $ts['stop'];
            break;
        }
    }

    // Kein ID-Match: Abgleich über departurePlanned-Zeit
    if ($recordingStopName === $body['stopId'] && $body['departurePlanned'] !== '') {
        $bodyTime = substr($body['departurePlanned'], 11, 5);
        foreach ($tripStops as $ts) {
            if ($ts['departurePlanned'] !== null) {
                $tsTime = substr($ts['departurePlanned'], 11, 5);
                if ($tsTime === $bodyTime) {
                    $recordingStopId   = $ts['stopId'];
                    $recordingStopName = $ts['stop'];
                    break;
                }
            }
        }
    }

    if ($recordingStopName === $body['stopId']) {
        get_logger()->warning('recordings POST: Haltestelle nicht im Laufweg gefunden', [
            'stopId'           => $body['stopId'],
            'departurePlanned' => $body['departurePlanned'],
        ]);
    }

    // User-Token aus Header (optional)
    $userToken = get_request_token();

    // Alles in einer Transaktion speichern
    $pdo->beginTransaction();
    try {
        // Erfassungs-Haltestelle sicherstellen
        $pdo->prepare('INSERT IGNORE INTO ' . tbl('stops') . ' (hafas_id, name) VALUES (?, ?)')
            ->execute([$recordingStopId, $recordingStopName]);

        // Korrektur-Erkennung: Erfasst derselbe Nutzer am selben Betriebstag,
        // an derselben Haltestelle und für dieselbe Plan-Abfahrt derselben
        // Fahrt erneut, ist das fast immer eine Korrektur einer falsch
        // erfassten Kursnummer (Match bewusst ohne course_number). Die ältere
        // Erfassung wird soft-gelöscht; Lazy-Cleanup entfernt sie nach 30
        // Tagen final.
        // trip_id ist Pflicht im Match: an Umsteigeknoten (z.B. Hasselbach-
        // platz) teilen sich bis zu vier Linien dieselbe Plattform, und zwei
        // verschiedene Fahrten können zur selben Minute abfahren. Ohne trip_id
        // würden sie sich gegenseitig als "Korrektur" überschreiben.
        // Anonyme Erfassungen (kein User-Token) bleiben außen vor – sonst
        // würden sich fremde Erfassungen gegenseitig löschen.
        // Spiegelt das Predikat recording_dedup_match() in lib/recording_helpers.php.
        $departurePlannedMysql = iso_to_mysql($body['departurePlanned']);
        $replacedIds = [];
        if ($userToken !== null) {
            $dupStmt = $pdo->prepare(
                'SELECT id FROM ' . tbl('recordings') . '
                  WHERE user_token = ?
                    AND trip_id = ?
                    AND service_date = ?
                    AND stop_id = ?
                    AND departure_planned = ?
                    AND deleted_at IS NULL'
            );
            $dupStmt->execute([
                $userToken,
                $tripId,
                $body['serviceDate'],
                $recordingStopId,
                $departurePlannedMysql,
            ]);
            $replacedIds = array_map('intval', $dupStmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($replacedIds)) {
                $placeholders = implode(',', array_fill(0, count($replacedIds), '?'));
                $pdo->prepare(
                    'UPDATE ' . tbl('recordings') .
                    ' SET deleted_at = UTC_TIMESTAMP() WHERE id IN (' . $placeholders . ')'
                )->execute($replacedIds);

                get_logger()->info('Erfassung ersetzt', [
                    'replaced_ids' => $replacedIds,
                    'stop_id'      => $recordingStopId,
                    'service_date' => $body['serviceDate'],
                ]);
            }
        }

        // Erfassung anlegen
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $recStmt = $pdo->prepare(
            'INSERT INTO ' . tbl('recordings') . '
                 (trip_id, recorded_at, hafas_trip_id, service_date, stop_id,
                  departure_planned, departure_actual, course_number, user_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $recStmt->execute([
            $tripId,
            $now,
            $body['hafasTripId'],
            $body['serviceDate'],
            $recordingStopId,
            $departurePlannedMysql,
            isset($body['departureActual']) && $body['departureActual'] !== null
                ? iso_to_mysql($body['departureActual'])
                : null,
            $body['courseNumber'],
            $userToken,
        ]);
        $recordingId = (int) $pdo->lastInsertId();

        // Laufweg-Haltestellen anlegen und route_stops befüllen – nur einmal pro Trip.
        // INSERT IGNORE auf (trip_id, sequence) verhindert Duplikate bei parallelen Erfassungen.
        if (!empty($tripStops)) {
            $stopInsert  = $pdo->prepare('INSERT IGNORE INTO ' . tbl('stops') . ' (hafas_id, name) VALUES (?, ?)');
            $routeInsert = $pdo->prepare(
                'INSERT IGNORE INTO ' . tbl('route_stops') . '
                     (trip_id, sequence, stop_id, departure_planned, line)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($tripStops as $ts) {
                $stopInsert->execute([$ts['stopId'], $ts['stop']]);
                $routeInsert->execute([
                    $tripId,
                    $ts['sequence'],
                    $ts['stopId'],
                    $ts['departurePlanned'] !== null ? iso_to_mysql($ts['departurePlanned']) : null,
                    $ts['line'] ?? null,
                ]);
            }
        } else {
            get_logger()->warning('recordings POST: HAFAS lieferte leere Halteliste', [
                'hafasTripId' => $body['hafasTripId'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        get_logger()->error('recordings POST: Transaktion fehlgeschlagen', [
            'exception' => $e->getMessage(),
        ]);
        json_error('Speichern fehlgeschlagen', 500);
    }

    // User last_seen_at aktualisieren (non-blocking, Fehler werden nur geloggt)
    if ($userToken !== null) {
        try {
            $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
            $dev = parse_device($ua);
            $pdo->prepare(
                'UPDATE ' . tbl('users') .
                ' SET last_seen_at = UTC_TIMESTAMP(), last_user_agent = ?, last_device = ? WHERE token = ?'
            )->execute([$ua ?: null, $dev ?: null, $userToken]);
        } catch (Throwable $e) {
            get_logger()->warning('recordings POST: User-Update fehlgeschlagen', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    get_logger()->info('Erfassung gespeichert', [
        'recording_id' => $recordingId,
        'trip_id'      => $tripId,
        'course'       => $body['courseNumber'],
    ]);

    // Lazy-Cleanup: mit ~1 % Wahrscheinlichkeit soft-deleted Erfassungen
    // > 30 Tage final entfernen. Fehler nur loggen (Cleanup ist best-effort).
    try {
        if (random_int(1, 100) === 1) {
            $deleted = $pdo->exec(
                'DELETE FROM ' . tbl('recordings') . '
                  WHERE deleted_at IS NOT NULL
                    AND deleted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY'
            );
            if ($deleted > 0) {
                get_logger()->info('Soft-Delete-Cleanup', ['removed' => $deleted]);
            }
        }
    } catch (Throwable $e) {
        get_logger()->warning('Soft-Delete-Cleanup fehlgeschlagen', [
            'exception' => $e->getMessage(),
        ]);
    }

    $resp = [
        'recordingId' => $recordingId,
        'tripId'      => $tripId,
        'periodId'    => $periodId,
        'dayType'     => $dayType,
    ];
    if (!empty($replacedIds)) {
        $resp['replacedRecordingIds'] = $replacedIds;
    }
    json_response($resp, 201);
}

// ---------------------------------------------------------------------------
// PUT /api/recordings/{id}
// ---------------------------------------------------------------------------

function handle_put_recording(int $recordingId): never
{
    $token = get_request_token();
    if ($token === null) {
        json_error('X-User-Token-Header fehlt oder ist ungültig', 401);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_error('Ungültiger JSON-Body');
    }

    // Mindestens ein Feld muss angegeben sein
    $hasCourse  = isset($body['courseNumber']);
    $hasComment = array_key_exists('comment', $body);
    if (!$hasCourse && !$hasComment) {
        json_error('courseNumber oder comment muss angegeben sein');
    }

    // Kursnummer validieren wenn angegeben
    if ($hasCourse && !preg_match('/^[0-9]{2}$/', (string) $body['courseNumber'])) {
        json_error('Kursnummer muss zweistellig im Format 00–99 sein');
    }

    // Kommentar validieren wenn angegeben
    $comment = null;
    if ($hasComment) {
        $comment = $body['comment'] === null ? null : trim((string) $body['comment']);
        if ($comment !== null && mb_strlen($comment) > 500) {
            json_error('Kommentar darf maximal 500 Zeichen lang sein');
        }
        if ($comment === '') {
            $comment = null;
        }
    }

    $pdo = get_db();
    require_no_maintenance($pdo);

    // Eigentümer/aktive-Periode-Check via Helper (gemeinsam mit DELETE/RESTORE)
    $rec = assert_recording_modifiable($pdo, $recordingId, $token);

    // Bereits soft-gelöschte Erfassung kann nicht bearbeitet werden – erst Undo
    if ($rec['deleted_at'] !== null) {
        json_error('Erfassung ist gelöscht und kann nicht bearbeitet werden', 409);
    }

    // UPDATE zusammenstellen
    $sets   = [];
    $params = [];
    if ($hasCourse) {
        $sets[]   = 'course_number = ?';
        $params[] = $body['courseNumber'];
    }
    if ($hasComment) {
        $sets[]   = 'comment = ?';
        $params[] = $comment;
    }
    $params[] = $recordingId;

    $pdo->prepare(
        'UPDATE ' . tbl('recordings') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?'
    )->execute($params);

    get_logger()->info('Erfassung bearbeitet', [
        'recording_id' => $recordingId,
        'course'       => $body['courseNumber'] ?? null,
        'has_comment'  => $hasComment,
    ]);

    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// DELETE /api/recordings/{id}
// ---------------------------------------------------------------------------

function handle_delete_recording(int $recordingId): never
{
    $token = get_request_token();
    $pdo   = get_db();
    require_no_maintenance($pdo);

    $rec = assert_recording_modifiable($pdo, $recordingId, $token);

    // Bereits soft-gelöscht: idempotent als ok melden, damit der Client
    // bei Doppelklicks oder Reconnects keinen Fehler sieht.
    if ($rec['deleted_at'] !== null) {
        json_response(['ok' => true, 'alreadyDeleted' => true]);
    }

    $pdo->prepare(
        'UPDATE ' . tbl('recordings') . ' SET deleted_at = UTC_TIMESTAMP() WHERE id = ?'
    )->execute([$recordingId]);

    get_logger()->info('Erfassung soft-gelöscht', [
        'recording_id' => $recordingId,
    ]);

    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// POST /api/recordings/{id}/restore  – Undo zur Snackbar
// ---------------------------------------------------------------------------

function handle_restore_recording(int $recordingId): never
{
    $token = get_request_token();
    $pdo   = get_db();
    require_no_maintenance($pdo);

    $rec = assert_recording_modifiable($pdo, $recordingId, $token);

    if ($rec['deleted_at'] === null) {
        // Restore auf einen aktiven Datensatz: keine Änderung nötig
        json_response(['ok' => true, 'wasActive' => true]);
    }

    $pdo->prepare(
        'UPDATE ' . tbl('recordings') . ' SET deleted_at = NULL WHERE id = ?'
    )->execute([$recordingId]);

    get_logger()->info('Erfassung wiederhergestellt', [
        'recording_id' => $recordingId,
    ]);

    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// GET /api/recordings/{id}/route
// ---------------------------------------------------------------------------

function handle_get_route(int $recordingId): never
{
    $pdo = get_db();

    $recStmt = $pdo->prepare(
        'SELECT r.stop_id, r.departure_planned, r.trip_id
         FROM ' . tbl('recordings') . ' r
         WHERE r.id = ?'
    );
    $recStmt->execute([$recordingId]);
    $rec = $recStmt->fetch();

    if (!$rec) {
        json_error('Erfassung nicht gefunden', 404);
    }

    $recordingStopId           = $rec['stop_id'];
    $recordingDeparturePlanned = $rec['departure_planned'];

    $stmt = $pdo->prepare(
        'SELECT
             rs.sequence,
             rs.stop_id,
             st.name              AS stop_name,
             rs.departure_planned,
             rs.line
         FROM ' . tbl('route_stops') . ' rs
         JOIN ' . tbl('stops') . ' st ON rs.stop_id = st.hafas_id
         WHERE rs.trip_id = ?
         ORDER BY rs.sequence ASC'
    );
    $stmt->execute([$rec['trip_id']]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        json_error('Kein Laufweg gespeichert', 404);
    }

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'sequence'         => (int) $row['sequence'],
            'stopId'           => $row['stop_id'],
            'name'             => $row['stop_name'],
            'departurePlanned' => mysql_to_iso($row['departure_planned']),
            'isRecordingStop'  => $row['stop_id'] === $recordingStopId
                && $row['departure_planned'] === $recordingDeparturePlanned,
            'line'             => $row['line'],
        ];
    }

    json_response($result);
}

