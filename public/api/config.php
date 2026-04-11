<?php
// GET /api/config – Öffentliche Frontend-Konfiguration.
// Gibt nur Werte zurück, die das Frontend benötigt und die keine Geheimnisse sind.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$cfg = require dirname(__DIR__, 2) . '/config.php';

json_response([
    // Präfix, der aus Haltestellennamen in der Anzeige entfernt wird.
    // Leer wenn nicht konfiguriert.
    'stopNamePrefix' => $cfg['stop_name_prefix'] ?? '',
]);
