<?php
// GET /admin-api/hafas-log – HAFAS-Zugriffslog (gefiltert + aggregiert)
// Zugriff nur mit gültiger Admin-Session.

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';

require_admin();

$pdo = get_db();

// ── Parameter ────────────────────────────────────────────────────────────────

$dateFrom = $_GET['date_from'] ?? date('Y-m-d');           // Standard: heute
$dateTo   = $_GET['date_to']   ?? $dateFrom;
$endpoint = $_GET['endpoint']  ?? '';
$status   = isset($_GET['status']) ? (int) $_GET['status'] : null;

// Einfache Datums-Validierung
foreach ([$dateFrom, $dateTo] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        json_error('Ungültiges Datumsformat (YYYY-MM-DD erwartet)');
    }
}

$allowedEndpoints = ['', 'nearby', 'stopfinder', 'departures', 'trip'];
if (!in_array($endpoint, $allowedEndpoints, true)) {
    json_error('Ungültiger endpoint-Filter');
}

// ── WHERE-Klausel aufbauen ───────────────────────────────────────────────────

$where  = ['DATE(logged_at) >= :date_from', 'DATE(logged_at) <= :date_to'];
$sqlParams = [':date_from' => $dateFrom, ':date_to' => $dateTo];

if ($endpoint !== '') {
    $where[]          = 'endpoint = :endpoint';
    $sqlParams[':endpoint'] = $endpoint;
}
if ($status !== null) {
    $where[]           = 'http_status = :status';
    $sqlParams[':status'] = $status;
}

$whereClause = implode(' AND ', $where);
$tbl         = tbl('hafas_log');

// ── Einzeleinträge (neueste zuerst, max. 500) ────────────────────────────────

$rowsStmt = $pdo->prepare(
    "SELECT id, logged_at, endpoint, http_status, duration_ms, cache_hit, retry_after, params
       FROM $tbl
      WHERE $whereClause
      ORDER BY logged_at DESC
      LIMIT 500"
);
$rowsStmt->execute($sqlParams);
$rows = $rowsStmt->fetchAll();

$entries = [];
foreach ($rows as $r) {
    $entryParams = $r['params'] !== null ? json_decode($r['params'], true) : null;

    $cacheKey = match($r['endpoint']) {
        'trip'   => isset($entryParams['tripId'])
                        ? hafas_cache_key('trip', $entryParams['tripId'])
                        : null,
        'nearby' => isset($entryParams['lat'], $entryParams['lon'], $entryParams['results'])
                        ? hafas_cache_key('nearby', $entryParams['lat'], $entryParams['lon'], $entryParams['results'])
                        : null,
        'stopfinder' => isset($entryParams['name'], $entryParams['results'])
                        ? hafas_cache_key('stopfinder', $entryParams['name'], $entryParams['results'])
                        : null,
        'departures' => isset($entryParams['stopId'], $entryParams['results'], $entryParams['maxMinutes'])
                        ? hafas_cache_key('departures', $entryParams['stopId'], $entryParams['results'],
                              $entryParams['maxMinutes'],
                              max(0, (int) ((hafas_config())['departures_lookback_minutes'] ?? 5)))
                        : null,
        default  => null,
    };
    $cacheAvailable = $cacheKey !== null && file_exists(hafas_cache_path($cacheKey));

    $entries[] = [
        'id'             => (int) $r['id'],
        'loggedAt'       => mysql_to_iso($r['logged_at']),
        'endpoint'       => $r['endpoint'],
        'httpStatus'     => (int) $r['http_status'],
        'durationMs'     => (int) $r['duration_ms'],
        'cacheHit'       => (bool) $r['cache_hit'],
        'retryAfter'     => $r['retry_after'] !== null ? (int) $r['retry_after'] : null,
        'params'         => $entryParams,
        'cacheAvailable' => $cacheAvailable,
    ];
}

// ── Aggregation: pro Tag ─────────────────────────────────────────────────────

$aggStmt = $pdo->prepare(
    "SELECT
         DATE(logged_at)                                    AS day,
         COUNT(*)                                           AS total,
         SUM(cache_hit = 0)                                 AS real_requests,
         SUM(cache_hit = 1)                                 AS cache_hits,
         ROUND(100.0 * SUM(cache_hit) / COUNT(*), 1)       AS cache_hit_pct,
         ROUND(AVG(CASE WHEN cache_hit = 0 THEN duration_ms END), 0) AS avg_duration_ms,
         SUM(http_status >= 400)                            AS errors,
         SUM(http_status = 429)                             AS rate_limits,
         MAX(retry_after)                                   AS max_retry_after
       FROM $tbl
      WHERE DATE(logged_at) >= :date_from AND DATE(logged_at) <= :date_to
      GROUP BY DATE(logged_at)
      ORDER BY day DESC"
);
$aggStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$aggRows = $aggStmt->fetchAll();

$byDay = [];
foreach ($aggRows as $r) {
    $byDay[] = [
        'day'           => $r['day'],
        'total'         => (int) $r['total'],
        'realRequests'  => (int) $r['real_requests'],
        'cacheHits'     => (int) $r['cache_hits'],
        'cacheHitPct'   => (float) $r['cache_hit_pct'],
        'avgDurationMs' => $r['avg_duration_ms'] !== null ? (int) $r['avg_duration_ms'] : null,
        'errors'        => (int) $r['errors'],
        'rateLimits'    => (int) $r['rate_limits'],
        'maxRetryAfter' => $r['max_retry_after'] !== null ? (int) $r['max_retry_after'] : null,
    ];
}

// ── Aggregation: pro Tag & Status ─────────────────────────────────────────────

$statusStmt = $pdo->prepare(
    "SELECT
         DATE(logged_at) AS day,
         http_status,
         COUNT(*)        AS count
       FROM $tbl
      WHERE DATE(logged_at) >= :date_from AND DATE(logged_at) <= :date_to
      GROUP BY DATE(logged_at), http_status
      ORDER BY day DESC, http_status ASC"
);
$statusStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$byDayStatus = [];
foreach ($statusStmt->fetchAll() as $r) {
    $byDayStatus[] = [
        'day'        => $r['day'],
        'httpStatus' => (int) $r['http_status'],
        'count'      => (int) $r['count'],
    ];
}

// ── Aggregation: pro Endpoint ────────────────────────────────────────────────

$epStmt = $pdo->prepare(
    "SELECT
         endpoint,
         COUNT(*)                                           AS total,
         SUM(cache_hit = 0)                                 AS real_requests,
         ROUND(100.0 * SUM(cache_hit) / COUNT(*), 1)       AS cache_hit_pct,
         ROUND(AVG(CASE WHEN cache_hit = 0 THEN duration_ms END), 0) AS avg_duration_ms
       FROM $tbl
      WHERE $whereClause
      GROUP BY endpoint
      ORDER BY total DESC"
);
$epStmt->execute($sqlParams);
$byEndpoint = [];
foreach ($epStmt->fetchAll() as $r) {
    $byEndpoint[] = [
        'endpoint'      => $r['endpoint'],
        'total'         => (int) $r['total'],
        'realRequests'  => (int) $r['real_requests'],
        'cacheHitPct'   => (float) $r['cache_hit_pct'],
        'avgDurationMs' => $r['avg_duration_ms'] !== null ? (int) $r['avg_duration_ms'] : null,
    ];
}

json_response([
    'filters'    => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'endpoint' => $endpoint, 'status' => $status],
    'entries'    => $entries,
    'byDay'      => $byDay,
    'byDayStatus'=> $byDayStatus,
    'byEndpoint' => $byEndpoint,
]);
