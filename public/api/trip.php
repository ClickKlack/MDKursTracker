<?php
// GET /api/trip – Vollständiger Laufweg eines HAFAS-Kurses (alle planmäßigen Halte).
//
// Parameter:
//   tripId      (string, Pflicht)    HAFAS Journey-ID
//   serviceDate (string, optional)   Betriebsdatum YYYY-MM-DD; wenn angegeben, wird die
//                                    Fahrt per schedule_fingerprint in der DB aufgelöst
//                                    und activeCourseNumber + tripId zurückgegeben.
//                                    last_hafas_trip_id und service_nr werden lazy nachgeführt.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';
require_once dirname(__DIR__, 2) . '/lib/fingerprint.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$tripId      = trim($_GET['tripId']      ?? '');
$serviceDate = trim($_GET['serviceDate'] ?? '');

if ($tripId === '') {
    json_error('Parameter tripId ist erforderlich');
}

if ($serviceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)) {
    json_error('serviceDate muss das Format YYYY-MM-DD haben');
}

try {
    $stops = hafas_trip($tripId);
} catch (RuntimeException $e) {
    get_logger()->error('trip: HAFAS-Fehler', ['tripId' => $tripId, 'exception' => $e->getMessage()]);
    json_error('HAFAS nicht verfügbar: ' . $e->getMessage(), 500);
}

// Ohne serviceDate: nur Laufweg zurückgeben (bisheriges Verhalten)
if ($serviceDate === '') {
    json_response($stops);
}

// Mit serviceDate: Fingerprints berechnen, Trip in DB suchen, Kurs zurückgeben
$fp = compute_fingerprints($stops);

$pdo      = get_db();
$periodId = get_active_period_id($pdo);
$dayType  = getDayType(new DateTimeImmutable($serviceDate), $pdo);

$resolvedTripId          = null;
$activeCourseNumber      = null;

if ($fp['schedule'] !== null) {
    $tripRow = $pdo->prepare(
        'SELECT
             t.id,
             t.last_hafas_trip_id,
             t.service_nr,
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
         WHERE t.period_id = ? AND t.schedule_fingerprint = ? AND t.day_type = ?'
    );
    $tripRow->execute([$periodId, $fp['schedule'], $dayType]);
    $trip = $tripRow->fetch();

    if ($trip) {
        $resolvedTripId     = (int) $trip['id'];
        $activeCourseNumber = $trip['manual_course_number'] ?? $trip['majority_course_number'];

        // last_hafas_trip_id lazy nachführen, wenn die Journey-ID sich geändert hat
        // (service_nr wird erst beim Erfassen über POST /api/recordings aktualisiert,
        //  da hafas_service_nr() das Produkt-Array aus dem StationBoard benötigt)
        if ($trip['last_hafas_trip_id'] !== $tripId) {
            try {
                $pdo->prepare(
                    'UPDATE ' . tbl('trips') . '
                     SET last_hafas_trip_id = ?
                     WHERE id = ?'
                )->execute([$tripId, $resolvedTripId]);
            } catch (Throwable $e) {
                get_logger()->warning('trip: Update last_hafas_trip_id fehlgeschlagen', [
                    'tripId'    => $tripId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}

json_response([
    'stops'             => $stops,
    'tripId'            => $resolvedTripId,
    'activeCourseNumber'=> $activeCourseNumber,
    'pathFingerprint'   => $fp['path'],
    'scheduleFingerprint' => $fp['schedule'],
]);
