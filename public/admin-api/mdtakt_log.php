<?php
// GET /admin-api/mdtakt-log      – Aufruf-Protokoll der MD-Takt-API (gefiltert + aggregiert)
// GET /admin-api/mdtakt-log/:id  – Einzeleintrag mit Request- und Response-Body
// Zugriff nur mit gültiger Admin-Session.

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$pdo = get_db();
$tbl = tbl('mdtakt_log');

// ── Einzeleintrag ────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    $stmt = $pdo->prepare(
        "SELECT id, logged_at, method, endpoint, context, http_status, duration_ms, cache_hit,
                items_sent, items_ok, stats, error, request_body, response_body
           FROM $tbl WHERE id = ?"
    );
    $stmt->execute([$resourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error('Eintrag nicht gefunden (evtl. bereits bereinigt)', 404);
    }

    // Bodies als JSON zurückgeben; alles andere (Query-String eines GET,
    // HTML-Fehlerseite) bleibt als String erhalten.
    $decode = static function (?string $raw): mixed {
        if ($raw === null) {
            return null;
        }
        $d = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $d : $raw;
    };

    json_response(mdtakt_log_entry($row) + [
        'request'  => $decode($row['request_body']),
        'response' => $decode($row['response_body']),
    ]);
}

// ── Parameter ────────────────────────────────────────────────────────────────

$dateFrom   = $_GET['date_from'] ?? date('Y-m-d');           // Standard: heute
$dateTo     = $_GET['date_to']   ?? $dateFrom;
$errorsOnly = ($_GET['errors_only'] ?? '') === '1';
$endpoint   = $_GET['endpoint'] ?? '';

foreach ([$dateFrom, $dateTo] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        json_error('Ungültiges Datumsformat (YYYY-MM-DD erwartet)');
    }
}

if ($endpoint !== '' && !preg_match('#^[a-z0-9/_-]{1,50}$#', $endpoint)) {
    json_error('Ungültiger endpoint-Filter');
}

// Fehler = kein 2xx (inkl. 0 = Netzwerkfehler)
$isError = '(http_status < 200 OR http_status >= 300)';

$where     = ['DATE(logged_at) >= :date_from', 'DATE(logged_at) <= :date_to'];
$sqlParams = [':date_from' => $dateFrom, ':date_to' => $dateTo];
if ($endpoint !== '') {
    $where[]                = 'endpoint = :endpoint';
    $sqlParams[':endpoint'] = $endpoint;
}
if ($errorsOnly) {
    $where[] = $isError;
}
$whereClause = implode(' AND ', $where);

// ── Einzeleinträge (neueste zuerst, max. 500, ohne Bodies) ───────────────────

$stmt = $pdo->prepare(
    "SELECT id, logged_at, method, endpoint, context, http_status, duration_ms, cache_hit,
            items_sent, items_ok, stats, error
       FROM $tbl
      WHERE $whereClause
      ORDER BY logged_at DESC, id DESC
      LIMIT 500"
);
$stmt->execute($sqlParams);
$entries = array_map('mdtakt_log_entry', $stmt->fetchAll(PDO::FETCH_ASSOC));

// ── Übersicht pro Tag und Endpunkt (ohne errors_only) ────────────────────────
// Die endpunktspezifischen stats-Zähler werden in PHP summiert – so braucht
// die Übersicht keine Kenntnis der einzelnen Endpunkte.

$dayWhere  = 'DATE(logged_at) >= :date_from AND DATE(logged_at) <= :date_to';
$dayParams = [':date_from' => $dateFrom, ':date_to' => $dateTo];
if ($endpoint !== '') {
    $dayWhere              .= ' AND endpoint = :endpoint';
    $dayParams[':endpoint'] = $endpoint;
}

$stmt = $pdo->prepare(
    "SELECT DATE(logged_at) AS day, endpoint, http_status, duration_ms, cache_hit,
            items_sent, items_ok, stats
       FROM $tbl
      WHERE $dayWhere
      ORDER BY day DESC, endpoint"
);
$stmt->execute($dayParams);

$groups = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = $r['day'] . '|' . $r['endpoint'];
    $g   = &$groups[$key];
    $g ??= [
        'day' => $r['day'], 'endpoint' => $r['endpoint'], 'calls' => 0, 'errors' => 0,
        'cacheHits' => 0, 'itemsSent' => 0, 'itemsOk' => 0, 'stats' => [], 'durationSum' => 0,
    ];
    $g['calls']++;
    $status = (int) $r['http_status'];
    if ($status < 200 || $status >= 300) {
        $g['errors']++;
    }
    $g['cacheHits']   += (int) $r['cache_hit'];
    $g['itemsSent']   += (int) $r['items_sent'];
    $g['itemsOk']     += (int) $r['items_ok'];
    $g['durationSum'] += (int) $r['duration_ms'];
    foreach (json_decode((string) $r['stats'], true) ?: [] as $k => $v) {
        if (is_int($v)) {
            $g['stats'][$k] = ($g['stats'][$k] ?? 0) + $v;
        }
    }
    unset($g);
}

$byDay = [];
foreach ($groups as $g) {
    $g['avgDurationMs'] = (int) round($g['durationSum'] / $g['calls']);
    unset($g['durationSum']);
    $g['stats'] = (object) $g['stats'];
    $byDay[] = $g;
}

json_response([
    'filters' => [
        'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
        'endpoint' => $endpoint, 'errorsOnly' => $errorsOnly,
    ],
    'entries' => $entries,
    'byDay'   => $byDay,
]);

// ---------------------------------------------------------------------------

/**
 * Gemeinsame Felder eines Protokolleintrags (Liste und Detail).
 */
function mdtakt_log_entry(array $r): array
{
    return [
        'id'         => (int) $r['id'],
        'loggedAt'   => mysql_to_iso($r['logged_at']),
        'method'     => $r['method'],
        'endpoint'   => $r['endpoint'],
        'context'    => $r['context'],
        'httpStatus' => (int) $r['http_status'],
        'durationMs' => (int) $r['duration_ms'],
        'cacheHit'   => (bool) $r['cache_hit'],
        'itemsSent'  => (int) $r['items_sent'],
        'itemsOk'    => $r['items_ok'] !== null ? (int) $r['items_ok'] : null,
        'stats'      => $r['stats'] !== null ? (object) (json_decode($r['stats'], true) ?: []) : null,
        'error'      => $r['error'],
    ];
}
