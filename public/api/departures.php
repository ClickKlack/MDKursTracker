<?php
// GET /api/departures – Nächste Tramabfahrten an einer Haltestelle,
// angereichert mit activeCourseNumber aus der eigenen DB.
// Parameter: stopId (string, Pflicht), results (int, optional, Standard 20)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$stopId  = trim($_GET['stopId'] ?? '');
$results = max(1, min(100, (int) ($_GET['results'] ?? 20)));

if ($stopId === '') {
    json_error('Parameter stopId ist erforderlich');
}

// HAFAS-Abfahrten laden
try {
    $departures = hafas_departures($stopId, $results);
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
         t.service_nr,
         t.line,
         t.day_type,
         t.manual_course_number,
         (
             SELECT r.course_number
             FROM recordings r
             WHERE r.trip_id = t.id
             GROUP BY r.course_number
             ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
             LIMIT 1
         ) AS majority_course_number
     FROM trips t
     WHERE t.period_id = ?'
);
$stmt->execute([$periodId]);

// Lookup-Map: "service_nr|line|day_type" → activeCourseNumber
$tripMap = [];
foreach ($stmt->fetchAll() as $row) {
    $key           = $row['service_nr'] . '|' . $row['line'] . '|' . $row['day_type'];
    // manuelle Übersteuerung hat Vorrang vor Mehrheitsregel
    $tripMap[$key] = $row['manual_course_number'] ?? $row['majority_course_number'];
}

// Abfahrten mit activeCourseNumber anreichern
$result = [];
foreach ($departures as $dep) {
    $dateStr = $dep['departurePlanned'] !== null
        ? substr($dep['departurePlanned'], 0, 10)
        : date('Y-m-d');
    $dayType = $dayTypeCache[$dateStr] ?? 'MO-FR';
    $key     = $dep['serviceNr'] . '|' . $dep['line'] . '|' . $dayType;

    $result[] = array_merge($dep, [
        'activeCourseNumber' => $tripMap[$key] ?? null,
    ]);
}

json_response($result);
