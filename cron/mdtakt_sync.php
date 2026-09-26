<?php
// Überträgt Erfassungen an MD-Takt (siehe lib/mdtakt.php).
//
// Aufruf (CLI, per Cron alle 4 Stunden):
//   php /pfad/zum/tracker/cron/mdtakt_sync.php
//
// Optionen:
//   --dry-run  Payload des ersten Blocks ausgeben, nichts senden, nichts markieren
//
// Übertragen werden nur Erfassungen, die älter als die Karenzzeit
// (mdtakt_grace_minutes, Standard 30) und noch nicht übertragen sind.
// Beim ersten Lauf nach Migration v9 kommt so der Altbestand mit; bei großen
// Beständen verteilt er sich über mehrere Läufe (höchstens MAX_BLOCKS Blöcke
// à 500 Erfassungen je Lauf).
//
// Am Ende jedes Laufs werden Einträge im Aufruf-Protokoll (mdtakt_log), die
// älter als mdtakt_log_days (Standard 30) sind, gelöscht.
//
// Ohne konfigurierten Token (mdtakt_api_token) beendet sich der Lauf still.
// Exit-Code 1, wenn ein Block nicht übertragen werden konnte – der nächste
// Lauf wiederholt ihn.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/mdtakt.php';

const MAX_BLOCKS = 50;

$dryRun = in_array('--dry-run', $argv, true);

if (!mdtakt_configured() && !$dryRun) {
    echo "MD-Takt nicht konfiguriert (mdtakt_api_token) – nichts zu tun.\n";
    exit(0);
}

// Überlappende Läufe verhindern (z.B. wenn ein Altbestand-Lauf lange dauert)
$lockFile = dirname(__DIR__) . '/cache/mdtakt_sync.lock';
@mkdir(dirname($lockFile), 0775, true);
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Ein anderer Lauf ist noch aktiv – übersprungen.\n";
    exit(0);
}

// Dry-Run: ersten Block ausgeben und wie eine leere 2xx-Antwort behandeln –
// ohne akzeptierte IDs wird nichts markiert.
$send = null;
if ($dryRun) {
    $send = static function (array $body): array {
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return ['status' => 200, 'data' => [], 'error' => null];
    };
}

try {
    $stats = mdtakt_sync(get_db(), $dryRun ? 1 : MAX_BLOCKS, $send);
} catch (Throwable $e) {
    fwrite(STDERR, 'MD-Takt-Sync fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
    get_logger()->error('mdtakt_sync: Lauf fehlgeschlagen', ['exception' => $e->getMessage()]);
    exit(1);
}

printf(
    "%sBlöcke: %d, gesendet: %d, angenommen: %d, waiting: %d, unbekannter Laufweg: %d, abgewiesen: %d\n",
    $dryRun ? '[dry-run] ' : '',
    $stats['blocks'],
    $stats['sent'],
    $stats['accepted'],
    $stats['waiting'],
    $stats['unknownFingerprint'],
    $stats['rejected']
);

// Protokoll bereinigen (auch nach Fehlern – das Protokoll soll nicht wachsen)
if (!$dryRun) {
    try {
        $removed = mdtakt_log_cleanup(get_db(), mdtakt_config()['logDays']);
        if ($removed > 0) {
            get_logger()->info('mdtakt_log: alte Einträge entfernt', ['removed' => $removed]);
        }
    } catch (Throwable $e) {
        get_logger()->warning('mdtakt_log: Bereinigung fehlgeschlagen', ['exception' => $e->getMessage()]);
    }
}

if ($stats['failed']) {
    fwrite(STDERR, "Übertragung abgebrochen – siehe logs/app.log\n");
    exit(1);
}
exit(0);
