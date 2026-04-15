<?php
// Filesystem-basierter Cache für HAFAS-API-Antworten.
//
// Strategie:
//   nearby     → 1 Tag  (Haltestellen ändern sich kaum)
//   departures → 30 s   (Echtzeit-Daten; kurz genug für schnelle Refreshs)
//   trip       → 1 Tag  (Laufweg ist fahrplanstabil)
//
// Cache-Verzeichnis: ../cache/hafas/ (außerhalb Webroot, nicht per HTTP erreichbar)
// Konfigurierbar über config.php: 'cache_dir' => '/pfad/zum/cache'
//
// Das Caching reduziert die HAFAS-Last automatisch auf weit unter 100 req/min
// (dokumentiertes Limit der INSA-API) – kein separates Rate-Limiting nötig.

const HAFAS_CACHE_TTL_NEARBY      = 86400; // 1 Tag in Sekunden
const HAFAS_CACHE_TTL_DEPARTURES  = 30;    // 30 Sekunden
const HAFAS_CACHE_TTL_TRIP        = 86400; // 1 Tag in Sekunden
const HAFAS_CACHE_TTL_STOPFINDER  = 86400; // 1 Tag in Sekunden

/**
 * Cache-Eintrag lesen. Gibt null zurück wenn nicht vorhanden oder abgelaufen.
 *
 * @param string $key  Cache-Schlüssel (wird intern gehasht)
 * @return mixed|null  Gecachte Daten oder null
 */
function hafas_cache_get(string $key): mixed
{
    $file = hafas_cache_path($key);
    if (!file_exists($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }

    $entry = @unserialize($raw);
    if (!is_array($entry) || !array_key_exists('expires', $entry) || !array_key_exists('data', $entry)) {
        return null;
    }

    if (time() > $entry['expires']) {
        @unlink($file); // Abgelaufenen Eintrag bereinigen
        return null;
    }

    return $entry['data'];
}

/**
 * Cache-Eintrag schreiben (atomares Schreiben via tmp → rename).
 * Mit 2 % Wahrscheinlichkeit werden dabei alle abgelaufenen Einträge bereinigt.
 *
 * @param string $key  Cache-Schlüssel
 * @param mixed  $data Zu cachende Daten (muss serialisierbar sein)
 * @param int    $ttl  Gültigkeitsdauer in Sekunden
 */
function hafas_cache_set(string $key, mixed $data, int $ttl): void
{
    $dir = hafas_cache_dir();

    // Verzeichnis anlegen falls nicht vorhanden
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        // Wenn Verzeichnis nicht angelegt werden kann → Cache-Fehler stumm ignorieren
        return;
    }

    $entry = serialize(['expires' => time() + $ttl, 'data' => $data]);
    $file  = hafas_cache_path($key);

    // Atomar schreiben (verhindert halb-geschriebene Dateien bei parallelen Requests)
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $entry, LOCK_EX) !== false) {
        @rename($tmp, $file);
    }

    // Probabilistische Bereinigung: bei ~2 % aller Schreibvorgänge alle
    // abgelaufenen Cache-Dateien löschen (analog zu PHP-Session-GC).
    if (random_int(1, 50) === 1) {
        hafas_cache_gc($dir);
    }
}

/**
 * Löscht alle abgelaufenen Cache-Dateien im angegebenen Verzeichnis.
 * Wird intern probabilistisch aufgerufen; kann auch direkt genutzt werden.
 */
function hafas_cache_gc(string $dir): void
{
    $files = @glob($dir . '/*.cache');
    if ($files === false) {
        return;
    }

    $now = time();
    foreach ($files as $file) {
        $raw   = @file_get_contents($file);
        $entry = $raw !== false ? @unserialize($raw) : false;

        if ($entry === false
            || !is_array($entry)
            || !array_key_exists('expires', $entry)
            || $now > $entry['expires']
        ) {
            @unlink($file);
        }
    }
}

/**
 * Gibt den Dateipfad für einen Cache-Schlüssel zurück.
 * Der Schlüssel wird als SHA-1-Hash gespeichert (sicherer Dateiname).
 */
function hafas_cache_path(string $key): string
{
    return hafas_cache_dir() . '/' . sha1($key) . '.cache';
}

/**
 * Gibt das konfigurierte Cache-Verzeichnis zurück (gecacht pro Request).
 */
function hafas_cache_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $cfg = hafas_config();
        $dir = $cfg['cache_dir'] ?? dirname(__DIR__) . '/cache/hafas';
    }
    return $dir;
}

/**
 * Cache-Schlüssel aus mehreren Teilen zusammenbauen.
 * Verwendet einen Trennzeichner der nicht in normalen Werten vorkommt.
 *
 * @param string ...$parts  Schlüsselteile (z.B. 'nearby', $lat, $lon)
 * @return string
 */
function hafas_cache_key(string ...$parts): string
{
    return implode('|', $parts);
}
