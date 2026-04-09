<?php
// GET /api/trip – Vollständiger Laufweg eines HAFAS-Kurses (alle planmäßigen Halte).
// Parameter: tripId (string, Pflicht)

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/hafas.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$tripId = trim($_GET['tripId'] ?? '');

if ($tripId === '') {
    json_error('Parameter tripId ist erforderlich');
}

try {
    $stops = hafas_trip($tripId);
    json_response($stops);
} catch (RuntimeException $e) {
    get_logger()->error('trip: HAFAS-Fehler', ['tripId' => $tripId, 'exception' => $e->getMessage()]);
    json_error('HAFAS nicht verfügbar: ' . $e->getMessage(), 500);
}
