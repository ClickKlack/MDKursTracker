<?php
// PHPUnit-Bootstrap: Test-Modus aktivieren, bevor vendor/autoload.php die lib-Dateien lädt.
// lib/db.php (tbl) und lib/hafas.php (hafas_config) prüfen APP_ENV und laden
// config.test.php statt config.php, wenn APP_ENV=test gesetzt ist.

putenv('APP_ENV=test');

require __DIR__ . '/../vendor/autoload.php';
