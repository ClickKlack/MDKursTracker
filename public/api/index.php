<?php
// API-Router: leitet Requests an die zuständigen Handler-Dateien weiter.
// Wird von der .htaccess-Rewrite-Regel für alle /api/*-Requests aufgerufen.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';

// Pfad aus der URL extrahieren: /api/nearby → nearby
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^.*?/api/#', '', $uri);
$path = strtok(trim($path, '/'), '/'); // erstes Segment, Subpfade ignorieren

// Erlaubte Endpunkte
$routes = ['nearby', 'departures', 'trip', 'calendar', 'recordings', 'trips', 'periods', 'config', 'user'];

if ($path !== false && in_array($path, $routes, true)) {
    require __DIR__ . '/' . $path . '.php';
} else {
    json_error('Endpunkt nicht gefunden', 404);
}
