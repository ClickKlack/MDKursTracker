<?php
// Admin-API-Router: leitet Requests an die zuständigen Handler-Dateien weiter.
// Wird von der .htaccess-Rewrite-Regel für alle /admin-api/*-Requests aufgerufen.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';

// Pfad aus der URL extrahieren: /admin-api/school-holidays/5 → ["school-holidays", "5"]
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path     = preg_replace('#^.*?/admin-api/?#', '', $uri);
$path     = trim($path, '/');
$segments = $path !== '' ? explode('/', $path) : [];

$resource   = $segments[0] ?? '';
$resourceId = null;

// Muster: /trips/:id/recordings → trip_recordings.php
if ($resource === 'trips'
    && isset($segments[1]) && ctype_digit($segments[1])
    && isset($segments[2]) && $segments[2] === 'recordings') {
    $resourceId = (int) $segments[1];
    require __DIR__ . '/trip_recordings.php';
    return;
}

// Muster: /trips/:id/override → override.php
if ($resource === 'trips'
    && isset($segments[1]) && ctype_digit($segments[1])
    && isset($segments[2]) && $segments[2] === 'override') {
    $resourceId = (int) $segments[1];
    require __DIR__ . '/override.php';
    return;
}

// Muster: /maintenance/end → maintenance.php (Aktion ohne :id)
$action = null;
if ($resource === 'maintenance' && isset($segments[1]) && $segments[1] === 'end') {
    $action = 'end';
    require __DIR__ . '/maintenance.php';
    return;
}

// Muster: /:resource/:id  (z.B. /school-holidays/5, /periods/3)
if (isset($segments[1]) && ctype_digit($segments[1])) {
    $resourceId = (int) $segments[1];
}

// Erlaubte Endpunkte und ihre Handler-Dateien
$routes = [
    'login'              => 'login.php',
    'logout'             => 'logout.php',
    'school-holidays'    => 'school_holidays.php',
    'periods'            => 'periods.php',
    'hafas-log'          => 'hafas_log.php',
    'hafas-cache'        => 'hafas_cache.php',
    'trip-group-detail'  => 'trip_group_detail.php',
    'announcements'      => 'announcements.php',
    'maintenance'        => 'maintenance.php',
    'diagnostics'        => 'diagnostics.php',
];

if ($resource !== '' && isset($routes[$resource])) {
    require __DIR__ . '/' . $routes[$resource];
} else {
    json_error('Endpunkt nicht gefunden', 404);
}
