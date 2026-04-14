<?php
// GET /api/nearby – Tramhaltestellen in der Nähe eines GPS-Punkts oder per Namenssuche.
//
// Parameter (GPS-Modus):
//   lat     (float, Pflicht)  – Breitengrad
//   lon     (float, Pflicht)  – Längengrad
//   results (int, optional, Standard 10)
//
// Parameter (Name-Modus):
//   name    (string, Pflicht) – Suchbegriff (Stadtpräfix wird serverseitig ergänzt)
//   results (int, optional, Standard 10)
//
// Genau einer der Modi muss angegeben sein.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$results = max(1, min(50, (int) ($_GET['results'] ?? 10)));
$name    = isset($_GET['name']) ? trim($_GET['name']) : null;
$lat     = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lon     = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);

if ($name !== null && $name !== '') {
    // Name-Modus: Suche per HAFAS LocMatch
    // Stadtpräfix aus Config automatisch voranstellen
    $cfg    = require dirname(__DIR__, 2) . '/config.php';
    $prefix = $cfg['stop_name_prefix'] ?? '';
    $query  = $prefix . $name;

    try {
        $stops = hafas_find_stops($query, $results);
        json_response($stops);
    } catch (RuntimeException $e) {
        get_logger()->error('nearby/name: HAFAS-Fehler', ['exception' => $e->getMessage()]);
        json_error('HAFAS nicht verfügbar: ' . $e->getMessage(), 500);
    }
} elseif ($lat !== false && $lat !== null && $lon !== false && $lon !== null) {
    // GPS-Modus: Suche per Koordinaten
    try {
        $stops = hafas_nearby($lat, $lon, $results);
        json_response($stops);
    } catch (RuntimeException $e) {
        get_logger()->error('nearby: HAFAS-Fehler', ['exception' => $e->getMessage()]);
        json_error('HAFAS nicht verfügbar: ' . $e->getMessage(), 500);
    }
} else {
    json_error('Entweder name oder lat+lon sind erforderlich');
}
