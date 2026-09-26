<?php
// Übertragung der Erfassungen an MD-Takt (Fluss 1, "Ingest").
//
// MD-Takt rekonstruiert Fahrzeugumläufe aus GTFS-Daten und unseren
// Sichtungen. Wir senden jede Erfassung einmal per
//   POST {mdtakt_api_url}/api/v1/collector/sightings
// zusammen mit dem Laufweg ihrer Fahrt. Aufgerufen wird das ausschließlich
// vom Cron cron/mdtakt_sync.php – bewusst kein Sofort-Push beim Speichern:
//
//   - Karenzzeit: Eine Erfassung geht erst raus, wenn sie mindestens
//     mdtakt_grace_minutes alt ist. Bis dahin lässt sie sich folgenlos
//     löschen. Löschungen selbst werden nicht übertragen – spätere
//     Fehlerfassungen müssen in MD-Takt auffallen.
//   - Offene Erfassungen erkennt der Cron an recordings.mdtakt_synced_at IS NULL.
//     PUT mit neuer Kursnummer setzt die Spalte zurück; die Erfassung geht
//     dann mit derselben mdkt_recording_id erneut raus (MD-Takt: "updated").
//   - Seed-Erfassungen der Kursübernahme sind keine echten Sichtungen und
//     bleiben außen vor.
//
// Konfiguration in config.php (ohne Token passiert nichts):
//   'mdtakt_api_url'       => 'https://api.strassenbahn-magdeburg.de',
//   'mdtakt_api_token'     => '…',
//   'mdtakt_grace_minutes' => 30,
//   'mdtakt_log_days'      => 30,   // Aufbewahrung im Admin-Log (mdtakt_log)
//
// Jeder HTTP-Aufruf wird in %%PREFIX%%mdtakt_log protokolliert (Admin-Tab
// "MD-Takt-Log"). Der Token wird nie geloggt.

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';

// Grenzen der MD-Takt-Schnittstelle je Request
const MDTAKT_MAX_SIGHTINGS = 500;
const MDTAKT_MAX_TRIPS     = 200;

// Kennzeichen der Seed-Erfassungen aus data_migrations/clone_unchanged_lines.php
// (Kommentar "Kurs aus Fahrplan „…" übernommen (…, automatische Startbelegung).")
const MDTAKT_SEED_COMMENT_LIKE = 'Kurs aus Fahrplan %automatische Startbelegung%';

// Outcomes, nach denen eine Sichtung als übertragen gilt.
// unknown_fingerprint bleibt offen: Der Laufweg fehlte MD-Takt.
const MDTAKT_ACCEPTED_OUTCOMES = ['created', 'updated', 'unchanged'];

// Pfad unterhalb von {mdtakt_api_url}/api/v1/, zugleich Kennung im Protokoll
const MDTAKT_ENDPOINT_SIGHTINGS = 'collector/sightings';

/**
 * Ist die Übertragung an MD-Takt konfiguriert?
 */
function mdtakt_configured(): bool
{
    return mdtakt_config()['token'] !== '';
}

/**
 * Liest die MD-Takt-Einstellungen aus der config.php (bzw. config.test.php).
 *
 * @return array{url: string, token: string, graceMinutes: int, logDays: int}
 */
function mdtakt_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $file = getenv('APP_ENV') === 'test'
        ? dirname(__DIR__) . '/config.test.php'
        : dirname(__DIR__) . '/config.php';
    $raw  = is_file($file) ? require $file : [];

    $cfg = [
        'url'          => rtrim((string) ($raw['mdtakt_api_url'] ?? 'https://api.strassenbahn-magdeburg.de'), '/'),
        'token'        => (string) ($raw['mdtakt_api_token'] ?? ''),
        'graceMinutes' => max(0, (int) ($raw['mdtakt_grace_minutes'] ?? 30)),
        'logDays'      => max(1, (int) ($raw['mdtakt_log_days'] ?? 30)),
    ];

    return $cfg;
}

// ---------------------------------------------------------------------------
// Payload-Aufbau (reine Funktionen, getestet in tests/Unit/MdTaktTest.php)
// ---------------------------------------------------------------------------

/**
 * Bildet eine Zeile aus mdtakt_load_pending() auf eine Sichtung ab.
 * Alle Zeiten als ISO-8601 UTC mit "Z" – MD-Takt weist andere Formate ab.
 */
function mdtakt_sighting(array $row): array
{
    return [
        'mdkt_recording_id'    => (int) $row['id'],
        'schedule_fingerprint' => $row['schedule_fingerprint'],
        'hafas_stop_id'        => $row['stop_id'],
        'line'                 => $row['line'],
        // Ohne führende Null ("03" → "3"), wie MD-Takt Kurse führt
        'course_number'        => (string) (int) $row['course_number'],
        'service_date'         => $row['service_date'],
        'observed_at'          => mysql_to_iso($row['recorded_at']),
        'departure_planned'    => mysql_to_iso($row['departure_planned']),
        'departure_actual'     => mysql_to_iso($row['departure_actual'] ?? null),
    ];
}

/**
 * Tagestyp in MD-Takts Wertebereich (MO-FR | SA | SO).
 * Der Wert ist dort nur informativ – MD-Takt bestimmt den Fahrplantyp selbst.
 * Schulferientage (SF) sind Werktage, Feiertage (FT) fahren nach SO.
 */
function mdtakt_day_type(string $dayType): string
{
    return match ($dayType) {
        'SF'    => 'MO-FR',
        'FT'    => 'SO',
        default => $dayType,
    };
}

/**
 * Bildet einen Trip samt Laufweg auf MD-Takts Routendefinition ab.
 *
 * Null-Zeiten werden weggelassen statt als null gesendet. Die Zeiten in
 * route_stops tragen das Datum des ersten HAFAS-Abrufs – verlässlich ist
 * nur die Uhrzeit.
 *
 * @param array $trip  Zeile aus trips
 * @param array $stops Zeilen aus route_stops ⋈ stops, nach sequence sortiert
 */
function mdtakt_trip(array $trip, array $stops): array
{
    $outStops = [];
    foreach ($stops as $s) {
        $stop = [
            'seq'           => (int) $s['sequence'],
            'hafas_stop_id' => $s['stop_id'],
            'stop_name'     => $s['stop_name'] ?? '',
            'line'          => $s['line'] ?? $trip['line'],
        ];
        if ($s['departure_planned'] !== null) {
            $stop['departure_planned'] = mysql_to_iso($s['departure_planned']);
        }
        if (($s['arrival_planned'] ?? null) !== null) {
            $stop['arrival_planned'] = mysql_to_iso($s['arrival_planned']);
        }
        $outStops[] = $stop;
    }

    $out = [
        'mdkt_trip_id'         => (int) $trip['id'],
        'schedule_fingerprint' => $trip['schedule_fingerprint'],
        'line'                 => $trip['line'],
        'direction'            => $trip['direction'],
        'day_type'             => mdtakt_day_type($trip['day_type']),
    ];
    // Geklonte Trips haben bis zur ersten Erfassung keine service_nr
    if (($trip['service_nr'] ?? '') !== '') {
        $out['service_nr'] = $trip['service_nr'];
    }
    $out['stops'] = $outStops;

    return $out;
}

/**
 * Teilt offene Erfassungen in Blöcke, die MD-Takts Grenzen einhalten:
 * höchstens MDTAKT_MAX_SIGHTINGS Sichtungen und MDTAKT_MAX_TRIPS
 * verschiedene Fingerprints (= mitgesendete Laufwege) je Block.
 *
 * @param array $rows Zeilen mit mindestens 'schedule_fingerprint'
 * @return array<int, array> Liste von Blöcken, Reihenfolge bleibt erhalten
 */
function mdtakt_chunks(
    array $rows,
    int $maxSightings = MDTAKT_MAX_SIGHTINGS,
    int $maxTrips = MDTAKT_MAX_TRIPS
): array {
    $blocks = [];
    $cur    = [];
    $fps    = [];

    foreach ($rows as $row) {
        $fp    = $row['schedule_fingerprint'];
        $newFp = !isset($fps[$fp]);
        if ($cur !== [] && (count($cur) >= $maxSightings || ($newFp && count($fps) >= $maxTrips))) {
            $blocks[] = $cur;
            $cur      = [];
            $fps      = [];
        }
        $cur[]    = $row;
        $fps[$fp] = true;
    }
    if ($cur !== []) {
        $blocks[] = $cur;
    }

    return $blocks;
}

/**
 * Baut den Request-Body für einen Block.
 *
 * @param array $rows      Sichtungs-Zeilen eines Blocks
 * @param array $tripsById [trip_id => ['trip' => row, 'stops' => rows]]
 */
function mdtakt_build_body(array $rows, array $tripsById, string $generatedAt): array
{
    $trips     = [];
    $sightings = [];
    $since     = null;

    foreach ($rows as $row) {
        $fp = $row['schedule_fingerprint'];
        // Je Fingerprint genau ein Laufweg – derselbe Fingerprint kann in
        // mehreren Perioden (= mehreren Trip-IDs) vorkommen.
        if (!isset($trips[$fp]) && isset($tripsById[$row['trip_id']])) {
            $t          = $tripsById[$row['trip_id']];
            $trips[$fp] = mdtakt_trip($t['trip'], $t['stops']);
        }
        $sightings[] = mdtakt_sighting($row);
        if ($since === null || $row['recorded_at'] < $since) {
            $since = $row['recorded_at'];
        }
    }

    return [
        'sync'      => ['since' => mysql_to_iso($since), 'generated_at' => $generatedAt],
        'trips'     => array_values($trips),
        'sightings' => $sightings,
    ];
}

/**
 * Welche Sichtungen hat MD-Takt angenommen? Liefert die recording-IDs mit
 * einem Outcome aus MDTAKT_ACCEPTED_OUTCOMES.
 *
 * @param array $data "data"-Teil der MD-Takt-Antwort
 * @return int[]
 */
function mdtakt_accepted_ids(array $data): array
{
    $ids = [];
    foreach ($data['results'] ?? [] as $r) {
        if (in_array($r['outcome'] ?? null, MDTAKT_ACCEPTED_OUTCOMES, true)) {
            $ids[] = (int) $r['mdkt_recording_id'];
        }
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------------

/**
 * Lädt offene Erfassungen, deren Karenzzeit abgelaufen ist, nach id sortiert.
 *
 * Ausgeschlossen: gelöschte, bereits übertragene, Seed-Erfassungen, Trips ohne
 * Fingerprint oder ohne gespeicherten Laufweg. Die Linie am Halt kommt wie im
 * GET /api/recordings aus route_stops (Match über Stop und Uhrzeit), sonst
 * aus trips.line.
 */
function mdtakt_load_pending(PDO $pdo, int $graceMinutes, int $limit): array
{
    $sql = 'SELECT
                r.id,
                r.trip_id,
                t.schedule_fingerprint,
                r.stop_id,
                COALESCE(
                    (SELECT rs.line
                       FROM ' . tbl('route_stops') . ' rs
                      WHERE rs.trip_id = r.trip_id
                        AND rs.stop_id = r.stop_id
                        AND TIME(rs.departure_planned) = TIME(r.departure_planned)
                        AND rs.line IS NOT NULL
                      ORDER BY rs.sequence
                      LIMIT 1),
                    t.line
                ) AS line,
                r.course_number,
                r.service_date,
                r.recorded_at,
                r.departure_planned,
                r.departure_actual
            FROM ' . tbl('recordings') . ' r
            JOIN ' . tbl('trips') . ' t ON t.id = r.trip_id
           WHERE r.mdtakt_synced_at IS NULL
             AND r.deleted_at IS NULL
             AND r.recorded_at < UTC_TIMESTAMP() - INTERVAL ' . max(0, $graceMinutes) . ' MINUTE
             AND t.schedule_fingerprint IS NOT NULL
             AND NOT (r.user_token IS NULL AND COALESCE(r.comment, \'\') LIKE ?)
             AND EXISTS (SELECT 1 FROM ' . tbl('route_stops') . ' rx WHERE rx.trip_id = r.trip_id)
           ORDER BY r.id
           LIMIT ' . max(1, $limit);

    $stmt = $pdo->prepare($sql);
    $stmt->execute([MDTAKT_SEED_COMMENT_LIKE]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Lädt Trips samt Laufweg.
 *
 * @param int[] $tripIds
 * @return array [trip_id => ['trip' => row, 'stops' => rows]]
 */
function mdtakt_load_trips(PDO $pdo, array $tripIds): array
{
    $tripIds = array_values(array_unique(array_map('intval', $tripIds)));
    if ($tripIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($tripIds), '?'));

    $stmt = $pdo->prepare(
        'SELECT id, schedule_fingerprint, line, direction, day_type, service_nr
           FROM ' . tbl('trips') . ' WHERE id IN (' . $in . ')'
    );
    $stmt->execute($tripIds);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $out[(int) $t['id']] = ['trip' => $t, 'stops' => []];
    }

    $stmt = $pdo->prepare(
        'SELECT rs.trip_id, rs.sequence, rs.stop_id, s.name AS stop_name,
                rs.line, rs.departure_planned, rs.arrival_planned
           FROM ' . tbl('route_stops') . ' rs
           LEFT JOIN ' . tbl('stops') . ' s ON s.hafas_id = rs.stop_id
          WHERE rs.trip_id IN (' . $in . ')
          ORDER BY rs.trip_id, rs.sequence'
    );
    $stmt->execute($tripIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $tid = (int) $s['trip_id'];
        if (isset($out[$tid])) {
            $out[$tid]['stops'][] = $s;
        }
    }

    return $out;
}

/**
 * Markiert übertragene Erfassungen.
 *
 * Nur wenn die Kursnummer noch der gesendeten entspricht: Ändert ein PUT sie
 * während des Laufs, bleibt die Erfassung offen und geht beim nächsten Mal
 * mit der neuen Nummer raus.
 *
 * @param array<int, string> $sent [recording_id => gesendete course_number]
 * @return int Anzahl markierter Zeilen
 */
function mdtakt_mark_synced(PDO $pdo, array $sent): int
{
    $stmt = $pdo->prepare(
        'UPDATE ' . tbl('recordings') . '
            SET mdtakt_synced_at = UTC_TIMESTAMP()
          WHERE id = ? AND mdtakt_synced_at IS NULL AND course_number = ?'
    );
    $n = 0;
    foreach ($sent as $id => $course) {
        $stmt->execute([$id, $course]);
        $n += $stmt->rowCount();
    }
    return $n;
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * Sendet einen Block an MD-Takt und protokolliert den Aufruf. Wirft nie.
 *
 * @return array{status: int, data: ?array, error: ?string}
 *         status 0 = Netzwerkfehler; data nur bei 2xx
 */
function mdtakt_post(array $body, int $timeout = 30): array
{
    $json  = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $start = hrtime(true);
    [$res, $raw] = mdtakt_http_post($json, $timeout);
    $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

    $sum = mdtakt_sightings_summary($body, $res['data']);
    mdtakt_log_write([
        'method'     => 'POST',
        'endpoint'   => MDTAKT_ENDPOINT_SIGHTINGS,
        'status'     => $res['status'],
        'durationMs' => $durationMs,
        'itemsSent'  => $sum['itemsSent'],
        'itemsOk'    => $sum['itemsOk'],
        'stats'      => $sum['stats'],
        'error'      => $res['error'],
        'request'    => $json,
        'response'   => $raw,
    ]);

    return $res;
}

/**
 * Eigentlicher HTTP-Aufruf (gzip-komprimiert).
 *
 * @return array{0: array{status: int, data: ?array, error: ?string}, 1: ?string}
 *         Ergebnis und rohe Antwort (null bei Netzwerkfehler)
 */
function mdtakt_http_post(string $json, int $timeout): array
{
    $cfg = mdtakt_config();
    $gz  = gzencode($json);

    $ch = curl_init($cfg['url'] . '/api/v1/' . MDTAKT_ENDPOINT_SIGHTINGS);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $gz,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['token'],
            'Content-Type: application/json',
            'Content-Encoding: gzip',
            'Accept: application/json',
        ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        return [['status' => 0, 'data' => null, 'error' => 'curl: ' . $err], null];
    }

    $decoded = json_decode((string) $raw, true);
    if ($code >= 200 && $code < 300) {
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        return [['status' => $code, 'data' => $data, 'error' => null], (string) $raw];
    }

    $error = is_array($decoded['error'] ?? null)
        ? (($decoded['error']['code'] ?? '?') . ': ' . ($decoded['error']['message'] ?? ''))
        : mb_substr((string) $raw, 0, 300);

    return [['status' => $code, 'data' => null, 'error' => $error], (string) $raw];
}

// ---------------------------------------------------------------------------
// Aufruf-Protokoll (Admin-Tab "MD-Takt-Log")
// ---------------------------------------------------------------------------

/**
 * Kennzahlen eines Sichtungs-Aufrufs (Fluss 1) für Protokoll und Statistik.
 *
 * itemsOk und die Ergebnis-Zähler in stats sind null, wenn keine
 * 2xx-Antwort vorliegt.
 *
 * @param array  $body Gesendeter Request-Body
 * @param ?array $data "data"-Teil der Antwort (nur bei 2xx)
 * @return array{itemsSent:int, itemsOk:?int,
 *               stats: array{trips:int, waiting:?int, unknownFingerprint:?int}}
 */
function mdtakt_sightings_summary(array $body, ?array $data): array
{
    $sum = [
        'itemsSent' => count($body['sightings'] ?? []),
        'itemsOk'   => null,
        'stats'     => [
            'trips'              => count($body['trips'] ?? []),
            'waiting'            => null,
            'unknownFingerprint' => null,
        ],
    ];
    if ($data === null) {
        return $sum;
    }

    $sum['itemsOk']                     = count(mdtakt_accepted_ids($data));
    $sum['stats']['waiting']            = 0;
    $sum['stats']['unknownFingerprint'] = 0;
    foreach ($data['results'] ?? [] as $r) {
        if (($r['match'] ?? null) === 'waiting') {
            $sum['stats']['waiting']++;
        }
        if (($r['outcome'] ?? null) === 'unknown_fingerprint') {
            $sum['stats']['unknownFingerprint']++;
        }
    }
    return $sum;
}

/**
 * Aufrufer für die Spalte context: Skriptname im CLI (z.B. mdtakt_sync.php),
 * sonst der Request-Pfad (z.B. /api/departures).
 */
function mdtakt_log_context(): ?string
{
    if (PHP_SAPI === 'cli') {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?: null;
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return is_string($path) && $path !== '' ? mb_substr($path, 0, 100) : null;
}

/**
 * Schreibt einen Aufruf ins Protokoll – für alle MD-Takt-Endpunkte.
 * Fehler werden nur geloggt; das Protokoll darf keinen Aufruf abbrechen.
 *
 * @param array{method:string, endpoint:string, status:int, durationMs:int,
 *              itemsSent?:int, itemsOk?:?int, stats?:?array, error?:?string,
 *              request?:?string, response?:?string, cacheHit?:bool,
 *              context?:?string} $e
 *        request/response null = Body nicht speichern
 */
function mdtakt_log_write(array $e): void
{
    try {
        get_db()->prepare(
            'INSERT INTO ' . tbl('mdtakt_log') . '
                 (logged_at, method, endpoint, context, http_status, duration_ms, cache_hit,
                  items_sent, items_ok, stats, error, request_body, response_body)
             VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $e['method'],
            $e['endpoint'],
            array_key_exists('context', $e) ? $e['context'] : mdtakt_log_context(),
            $e['status'],
            max(0, $e['durationMs']),
            !empty($e['cacheHit']) ? 1 : 0,
            $e['itemsSent'] ?? 0,
            $e['itemsOk'] ?? null,
            isset($e['stats']) ? json_encode($e['stats']) : null,
            isset($e['error']) ? mb_substr($e['error'], 0, 500) : null,
            $e['request'] ?? null,
            $e['response'] ?? null,
        ]);
    } catch (Throwable $ex) {
        get_logger()->warning('mdtakt_log_write fehlgeschlagen', ['exception' => $ex->getMessage()]);
    }
}

/**
 * Löscht Protokolleinträge, die älter als $days Tage sind.
 *
 * @return int Anzahl gelöschter Einträge
 */
function mdtakt_log_cleanup(PDO $pdo, int $days): int
{
    return (int) $pdo->exec(
        'DELETE FROM ' . tbl('mdtakt_log') . '
          WHERE logged_at < UTC_TIMESTAMP() - INTERVAL ' . max(1, $days) . ' DAY'
    );
}

// ---------------------------------------------------------------------------
// Ablauf
// ---------------------------------------------------------------------------

/**
 * Überträgt alle offenen Erfassungen in Blöcken.
 *
 * Fehlerverhalten:
 *   - 422 (Validierung): Der Block wird halbiert und erneut gesendet, bis die
 *     beanstandete Sichtung allein steht. Sie wird geloggt und bleibt offen;
 *     der Rest geht durch. So blockiert eine fehlerhafte Zeile nicht alle
 *     folgenden.
 *   - 401, 429, 5xx, Netzwerk: Abbruch des Laufs. Nichts wird markiert, der
 *     nächste Lauf wiederholt (idempotent dank mdkt_recording_id).
 *
 * @param callable|null $send Ersatz für mdtakt_post() (Dry-Run)
 * @return array{blocks:int, sent:int, accepted:int, waiting:int,
 *               unknownFingerprint:int, rejected:int, failed:bool}
 */
function mdtakt_sync(PDO $pdo, int $maxBlocks, ?callable $send = null): array
{
    $send  = $send ?? 'mdtakt_post';
    $cfg   = mdtakt_config();
    $stats = [
        'blocks' => 0, 'sent' => 0, 'accepted' => 0, 'waiting' => 0,
        'unknownFingerprint' => 0, 'rejected' => 0, 'failed' => false,
    ];

    $rows = mdtakt_load_pending($pdo, $cfg['graceMinutes'], $maxBlocks * MDTAKT_MAX_SIGHTINGS);
    if ($rows === []) {
        return $stats;
    }

    $trips  = mdtakt_load_trips($pdo, array_column($rows, 'trip_id'));
    $blocks = array_slice(mdtakt_chunks($rows), 0, $maxBlocks);

    foreach ($blocks as $block) {
        if (!mdtakt_send_block($pdo, $block, $trips, $send, $stats)) {
            $stats['failed'] = true;
            break;
        }
    }

    get_logger()->info('mdtakt: Sync abgeschlossen', $stats);

    return $stats;
}

/**
 * Sendet einen Block; bei 422 rekursiv halbiert.
 *
 * @return bool false = Lauf abbrechen
 */
function mdtakt_send_block(PDO $pdo, array $rows, array $trips, callable $send, array &$stats): bool
{
    $body = mdtakt_build_body($rows, $trips, gmdate('Y-m-d\TH:i:s\Z'));
    $res  = $send($body);
    $stats['blocks']++;

    if ($res['data'] !== null) {
        $stats['sent'] += count($rows);

        $courseById = array_column($rows, 'course_number', 'id');
        $accepted   = [];
        foreach (mdtakt_accepted_ids($res['data']) as $id) {
            if (isset($courseById[$id])) {
                $accepted[$id] = $courseById[$id];
            }
        }
        mdtakt_mark_synced($pdo, $accepted);
        $stats['accepted'] += count($accepted);

        $sum = mdtakt_sightings_summary($body, $res['data']);
        $stats['waiting']            += $sum['stats']['waiting'];
        $stats['unknownFingerprint'] += $sum['stats']['unknownFingerprint'];
        // Fahrten, die MD-Takt (noch) keiner Fahrt seines Fahrplans zuordnen
        // kann (match: waiting) – laut Konzept kein Fehler, MD-Takt ordnet
        // nach dem nächsten Fahrplan-Import selbst neu zu.
        if (!empty($res['data']['unmatched_fingerprints'])) {
            get_logger()->info('mdtakt: Fahrten noch ohne Fahrplan-Zuordnung', [
                'fingerprints' => $res['data']['unmatched_fingerprints'],
            ]);
        }
        return true;
    }

    if ($res['status'] === 422) {
        if (count($rows) === 1) {
            $stats['rejected']++;
            get_logger()->warning('mdtakt: Sichtung abgewiesen', [
                'recording_id' => (int) $rows[0]['id'],
                'error'        => $res['error'],
            ]);
            return true;
        }
        $half = intdiv(count($rows), 2);
        return mdtakt_send_block($pdo, array_slice($rows, 0, $half), $trips, $send, $stats)
            && mdtakt_send_block($pdo, array_slice($rows, $half), $trips, $send, $stats);
    }

    get_logger()->warning('mdtakt: Übertragung fehlgeschlagen', [
        'httpCode' => $res['status'],
        'error'    => $res['error'],
        'rows'     => count($rows),
    ]);
    return false;
}
