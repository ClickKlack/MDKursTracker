<?php
// JSON-Antwort-Helfer: setzt Header, kodiert Daten und beendet den Request.

/**
 * Sendet eine erfolgreiche JSON-Antwort und beendet den Request.
 */
function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Sendet eine Fehler-JSON-Antwort und beendet den Request.
 */
function json_error(string $message, int $status = 400): never
{
    json_response(['error' => $message], $status);
}
