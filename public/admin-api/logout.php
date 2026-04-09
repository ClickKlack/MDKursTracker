<?php
// POST /admin-api/logout – Admin-Session beenden

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Methode nicht erlaubt', 405);
}

destroy_admin_session();
get_logger()->info('Admin-Logout');
json_response(['ok' => true]);
