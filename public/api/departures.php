<?php
// GET /api/departures – Nächste Tramabfahrten an einer Haltestelle,
// angereichert mit activeCourseNumber + courseSource aus der eigenen DB.
// Parameter: stopId (string, Pflicht), results (int, optional, Standard 20)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';
require_once dirname(__DIR__, 2) . '/lib/course_lookup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$stopId     = trim($_GET['stopId'] ?? '');
$results    = max(1, min(100, (int) ($_GET['results'] ?? 20)));
$maxMinutes = max(1, min(120, (int) ($_GET['maxMinutes'] ?? 59)));

if ($stopId === '') {
    json_error('Parameter stopId ist erforderlich');
}

// HAFAS-Abfahrten laden (nur Abfahrten innerhalb des Zeitfensters)
try {
    $departures = hafas_departures($stopId, $results, $maxMinutes);
} catch (RuntimeException $e) {
    get_logger()->error('departures: HAFAS-Fehler', ['stopId' => $stopId, 'exception' => $e->getMessage()]);
    json_error('HAFAS nicht verfügbar: ' . $e->getMessage(), 500);
}

// DB-Verbindung und aktive Periode ermitteln
$pdo      = get_db();
$periodId = get_active_period_id($pdo);

// Alle vorkommenden Service-Daten aus den Abfahrtszeiten sammeln
// und Wochentagstypen vorberechnen (in der Regel nur 1–2 Daten)
$dayTypeCache = [];
foreach ($departures as $dep) {
    if ($dep['departurePlanned'] !== null) {
        $dateStr = substr($dep['departurePlanned'], 0, 10); // YYYY-MM-DD
        if (!isset($dayTypeCache[$dateStr])) {
            $dayTypeCache[$dateStr] = getDayType(new DateTimeImmutable($dateStr), $pdo);
        }
    }
}

// Alle Trips der aktiven Periode einmalig laden, inkl. aktiver Kursnummer.
// Mehrheitsregel: häufigste course_number gewinnt; bei Gleichstand ältester Eintrag.
$stmt = $pdo->prepare(
    'SELECT
         t.last_hafas_trip_id,
         t.service_nr,
         t.line,
         t.day_type,
         t.manual_course_number,
         (
             SELECT r.course_number
             FROM ' . tbl('recordings') . ' r
             WHERE r.trip_id = t.id
             GROUP BY r.course_number
             ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
             LIMIT 1
         ) AS majority_course_number
     FROM ' . tbl('trips') . ' t
     WHERE t.period_id = ?'
);
$stmt->execute([$periodId]);

// Drei Lookup-Maps aufbauen, je mit Quellangabe pro Treffer:
//   1. byJourney   – last_hafas_trip_id → ['number','source']  (primär, stabil)
//   2. byServiceNr – serviceNr|line|dayType → [...]            (Fallback)
//   3. byRouteStop – stopId|line|dayType|HH:MM → [...]         (heuristisch)
$byJourney   = [];
$byServiceNr = [];
foreach ($stmt->fetchAll() as $row) {
    $number = $row['manual_course_number'] ?? $row['majority_course_number'];
    if ($number === null) {
        continue;
    }
    $entry = [
        'number' => $number,
        'source' => $row['manual_course_number'] !== null ? 'manual' : 'recorded',
    ];

    if ($row['last_hafas_trip_id'] !== null) {
        $byJourney[$row['last_hafas_trip_id']] = $entry;
    }

    // Fallback-Key – bei Kollision gewinnt der zuerst geladene Eintrag
    // (in der Praxis eindeutig, da service_nr per Trip nur einmal vorkommt)
    $fbKey = $row['service_nr'] . '|' . $row['line'] . '|' . $row['day_type'];
    if (!isset($byServiceNr[$fbKey])) {
        $byServiceNr[$fbKey] = $entry;
    }
}

$byRouteStop = build_route_stop_course_map($pdo, $periodId);

// Abfahrten mit activeCourseNumber + courseSource anreichern
$maps   = ['byJourney' => $byJourney, 'byServiceNr' => $byServiceNr, 'byRouteStop' => $byRouteStop];
$result = [];
foreach ($departures as $dep) {
    $dateStr = $dep['departurePlanned'] !== null
        ? substr($dep['departurePlanned'], 0, 10)
        : date('Y-m-d');
    $dayType = $dayTypeCache[$dateStr] ?? 'MO-FR';
    // HH:MM aus ISO-8601-UTC-String "YYYY-MM-DDTHH:MM:SSZ" → Position 11..15
    $hhmm = $dep['departurePlanned'] !== null ? substr($dep['departurePlanned'], 11, 5) : null;

    $pick = pick_course_for_departure($maps, [
        'hafasTripId' => $dep['hafasTripId'],
        'serviceNr'   => $dep['serviceNr'],
        'line'        => $dep['line'],
        'dayType'     => $dayType,
        'stopId'      => $dep['stopId'] ?? $stopId,
        'hhmm'        => $hhmm,
    ]);

    $result[] = array_merge($dep, [
        'activeCourseNumber' => $pick['number'],
        'courseSource'       => $pick['source'],
    ]);
}

json_response($result);
