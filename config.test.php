<?php
// config.test.php – Konfiguration für PHPUnit-Tests
// Wird verwendet wenn APP_ENV=test gesetzt ist (tests/bootstrap.php).
// Enthält sichere Defaults: kein DB-Prefix, Logging deaktiviert, Dummy-Zugangsdaten.

return [
    // Dummy-DB-Zugangsdaten (Unit-Tests verwenden PDO-Mocks, keine echte Verbindung)
    'db_host' => '127.0.0.1',
    'db_name' => 'test',
    'db_user' => 'test',
    'db_pass' => 'test',

    // Kein Tabellen-Prefix in Tests → tbl('trips') = 'trips'
    'db_prefix' => '',

    // Logging deaktiviert → hafas_log_write() läuft ohne DB-Zugriff durch
    'hafas_logging' => false,

    // HAFAS-Dummy-Werte (werden in Unit-Tests nicht aufgerufen)
    'hafas_url'    => 'http://localhost',
    'hafas_aid'    => 'test',
    'hafas_client' => ['type' => 'TEST', 'id' => 'TEST', 'v' => '0', 'name' => 'test'],
    'hafas_ver'    => '1.44',

    'admin_hash'       => '$2y$12$invalid',
    'stop_name_prefix' => '',
];
