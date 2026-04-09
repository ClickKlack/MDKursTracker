<?php
// GET /api/nearby – Tramhaltestellen in der Nähe eines GPS-Punkts.
// Parameter: lat (float, Pflicht), lon (float, Pflicht), results (int, optional, Standard 10)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$lat     = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lon     = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
$results = max(1, min(50, (int) ($_GET['results'] ?? 10)));

if ($lat === false || $lat === null || $lon === false || $lon === null) {
    json_error('Parameter lat und lon sind erforderlich');
}

try {
    $stops = hafas_nearby($lat, $lon, $results);
    json_response($stops);
} catch (RuntimeException $e) {
    get_logger()->error('nearby: HAFAS-Fehler', ['exception' => $e->getMessage()]);
    json_error('HAFAS nicht verfügbar: ' . $e->getMessage(), 500);
}
