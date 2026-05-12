<?php
// GET /api/notices – Liefert die aktuell anzuzeigende Nachricht und ggf. den
// Wartungsstatus. Öffentlich (kein Token nötig), wird vom Frontend periodisch
// gepollt und bei jedem App-Start abgerufen.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/announcements.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$pdo = get_db();
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

json_response([
    'announcements' => get_active_announcements($pdo, $now),
    'maintenance'   => get_active_maintenance($pdo),
]);
