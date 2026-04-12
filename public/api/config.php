<?php
// GET /api/config – Öffentliche Frontend-Konfiguration.
// Gibt nur Werte zurück, die das Frontend benötigt und die keine Geheimnisse sind.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$cfg         = require dirname(__DIR__, 2) . '/config.php';
$versionFile = dirname(__DIR__, 2) . '/version.json';

// version.json wird vom deploy.sh generiert; Fallback für lokale Entwicklung
$versionData = [];
if (is_readable($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true) ?? [];
}

json_response([
    // Präfix, der aus Haltestellennamen in der Anzeige entfernt wird.
    'stopNamePrefix' => $cfg['stop_name_prefix'] ?? '',
    // Versionsinfo aus version.json (vom deploy.sh generiert)
    'version'        => $versionData['version']        ?? null,
    'swCacheVersion' => $versionData['swCacheVersion'] ?? null,
    'deployedAt'     => $versionData['deployedAt']     ?? null,
]);
