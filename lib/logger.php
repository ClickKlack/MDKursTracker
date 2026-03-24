<?php
// Monolog-Logger: einmalig pro Request initialisiert, 14-Tage-Rotation.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * Gibt den gemeinsamen Logger zurück (Singleton pro Request).
 */
function get_logger(): Logger
{
    static $logger = null;

    if ($logger !== null) {
        return $logger;
    }

    $logger = new Logger('mdkurstracker');
    $handler = new RotatingFileHandler(
        dirname(__DIR__) . '/logs/app.log',
        maxFiles: 14,
        level: Level::Debug
    );
    $logger->pushHandler($handler);

    return $logger;
}
