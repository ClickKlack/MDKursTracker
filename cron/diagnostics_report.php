<?php
// Täglicher Diagnose-Report der Kursnummer-Heuristik.
//
// Aufruf (CLI, z.B. per Cron einmal täglich):
//   php /pfad/zum/tracker/cron/diagnostics_report.php
//
// Optionen:
//   --force   Auch melden, wenn sich seit dem letzten Lauf nichts geändert hat
//   --dry-run Nur ausgeben, nichts senden und keinen Zustand schreiben
//
// Verhalten: Der Report meldet nur, wenn sich die Befundlage seit dem letzten
// Lauf geändert hat. Dafür wird ein Fingerabdruck der Befunde in
// cache/diagnostics_state.json abgelegt. Ohne Änderung bleibt der Lauf still –
// so wird der Kanal nicht täglich mit derselben Meldung geflutet.
//
// Ohne konfigurierten Telegram-Kanal (telegram_bot_token + telegram_chat_id in
// der config.php) läuft alles normal durch, die Nachricht wird nur nicht
// versendet, sondern auf stdout ausgegeben.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/diagnostics.php';
require_once dirname(__DIR__) . '/lib/telegram.php';

$force  = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$stateFile = dirname(__DIR__) . '/cache/diagnostics_state.json';

try {
    $pdo      = get_db();
    $periodId = get_active_period_id($pdo);

    $periodStmt = $pdo->prepare('SELECT name FROM ' . tbl('schedule_periods') . ' WHERE id = ?');
    $periodStmt->execute([$periodId]);
    $periodName = (string) ($periodStmt->fetchColumn() ?: ('#' . $periodId));

    $routeChg  = detect_trip_route_changes($pdo, $periodId);
    $drift     = detect_schedule_drift($pdo, $periodId);
    $conflicts = detect_course_conflicts($pdo, $periodId);
    $misses    = load_heuristic_misses($pdo, $periodId, 20);
} catch (Throwable $e) {
    fwrite(STDERR, 'Diagnose fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
    get_logger()->error('diagnostics_report: Lauf fehlgeschlagen', ['exception' => $e->getMessage()]);
    exit(1);
}

// Fingerabdruck der Befundlage. Bewusst ohne Trefferzähler und Zeitstempel –
// sonst gälte jeder zusätzliche Aufruf einer bekannten Abfahrt als Änderung.
$fingerprint = hash('sha256', json_encode([
    'period'    => $periodId,
    'routeChg'  => array_map(
        static fn(array $c): array => [$c['line'], $c['dayType'], $c['slotHhmm'], $c['changedOn'], $c['to']['movedStopId']],
        $routeChg
    ),
    'drift'     => array_map(
        static fn(array $d): array => [$d['line'], $d['dayType'], $d['changedOn'], $d['from']['endStopId'], $d['to']['endStopId']],
        $drift
    ),
    'conflicts' => array_column($conflicts, 'routeKey'),
    'misses'    => array_column($misses, 'routeKey'),
]));

$previous = is_file($stateFile)
    ? (json_decode((string) file_get_contents($stateFile), true) ?: [])
    : [];
$unchanged = ($previous['fingerprint'] ?? null) === $fingerprint;
$nothingFound = !$routeChg && !$drift && !$conflicts && !$misses;

if ($nothingFound && !$force) {
    // Keine Befunde: Zustand fortschreiben, aber nichts melden.
    write_state($stateFile, $fingerprint, $dryRun);
    echo "Keine Befunde in Periode {$periodId} ({$periodName}).\n";
    exit(0);
}

if ($unchanged && !$force) {
    echo "Befundlage unverändert seit dem letzten Lauf – keine Meldung.\n";
    exit(0);
}

$message = format_diagnostics_report($periodId, $periodName, $drift, $conflicts, $misses, $routeChg);

if ($dryRun) {
    echo "--- dry-run, es wird nichts gesendet ---\n";
    echo strip_tags($message) . "\n";
    exit(0);
}

if (telegram_configured()) {
    if (telegram_send($message)) {
        echo "Telegram-Meldung gesendet.\n";
    } else {
        fwrite(STDERR, "Telegram-Meldung fehlgeschlagen – siehe logs/app.log\n");
    }
} else {
    echo "Kein Telegram-Kanal konfiguriert – Meldung nur hier:\n";
    echo strip_tags($message) . "\n";
}

write_state($stateFile, $fingerprint, false);
exit(0);

// ---------------------------------------------------------------------------

function write_state(string $file, string $fingerprint, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    @mkdir(dirname($file), 0775, true);
    @file_put_contents($file, json_encode([
        'fingerprint' => $fingerprint,
        'updatedAt'   => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_PRETTY_PRINT));
}
