<?php
// GET /admin-api/diagnostics[?period_id=N]
//
// Diagnose der Kursnummer-Heuristik für eine Fahrplanperiode:
//   routeChanges     – Laufweg einer konkreten Fahrt hat sich geändert
//   scheduleDrift    – Fahrplanwechsel innerhalb der laufenden Periode
//   courseConflicts  – Route-Schlüssel, die die Heuristik nicht auflösen kann
//   heuristicMisses  – im Betrieb protokollierte Aussetzer (mit Trefferzähler)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/diagnostics.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

require_admin();

$pdo      = get_db();
$periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : get_active_period_id($pdo);

$periodStmt = $pdo->prepare('SELECT id, name, start_date FROM ' . tbl('schedule_periods') . ' WHERE id = ?');
$periodStmt->execute([$periodId]);
$period = $periodStmt->fetch(PDO::FETCH_ASSOC);

if (!$period) {
    json_error('Fahrplanperiode nicht gefunden', 404);
}

$routeChg  = detect_trip_route_changes($pdo, $periodId);
$drift     = detect_schedule_drift($pdo, $periodId);
$conflicts = detect_course_conflicts($pdo, $periodId);
$misses    = load_heuristic_misses($pdo, $periodId);

json_response([
    'period' => [
        'id'        => (int) $period['id'],
        'name'      => $period['name'],
        'startDate' => $period['start_date'],
    ],
    'summary' => [
        'routeChanges'    => count($routeChg),
        'scheduleDrift'   => count($drift),
        'courseConflicts' => count($conflicts),
        'heuristicMisses' => count($misses),
        'missHits'        => array_sum(array_column($misses, 'hitCount')),
    ],
    'routeChanges'    => $routeChg,
    'scheduleDrift'   => $drift,
    'courseConflicts' => $conflicts,
    'heuristicMisses' => $misses,
]);
