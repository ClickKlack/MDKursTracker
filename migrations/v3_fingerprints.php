#!/usr/bin/env php
<?php
// Migrations-Skript für Schema-Version 3.
//
// Aufgaben:
//   1. route_stops-Duplikate bereinigen (gleiche trip_id + sequence → ältesten behalten)
//   2. recording_id-Spalte aus route_stops entfernen (inkl. FK + Index)
//   3. UNIQUE-Key (trip_id, sequence) auf route_stops setzen
//   4. Fingerprints berechnen; Trips mit identischem Fingerprint zusammenführen
//   5. last_hafas_trip_id aus der neuesten Erfassung je Trip befüllen
//
// Voraussetzung: migrations/v3.sql wurde bereits ausgeführt.
//
// Ausführen:
//   php migrations/v3_fingerprints.php
//   php migrations/v3_fingerprints.php --dry-run   (kein Schreiben)
//
// Remote via setup_db.sh:
//   ./local_scripts/setup_db.sh --migrate-v3-remote

declare(strict_types=1);

// Projektverzeichnis: optionales erstes Argument (für Remote-Ausführung aus /tmp heraus),
// Fallback: ein Verzeichnis über dem Skript-Verzeichnis (lokaler Standardaufruf).
$projectRoot = isset($argv[1]) ? rtrim($argv[1], '/') : dirname(__DIR__);
chdir($projectRoot);
require_once 'vendor/autoload.php';
require_once 'lib/db.php';
require_once 'lib/fingerprint.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

if ($dryRun) {
    echo "[DRY-RUN] Keine Änderungen werden geschrieben.\n";
}

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// Schritt 1: Duplikate in route_stops bereinigen
// Pro (trip_id, sequence) nur den Eintrag mit der kleinsten id behalten.
// ---------------------------------------------------------------------------
echo "Schritt 1: route_stops-Duplikate bereinigen...\n";

$dupes = $pdo->query(
    'SELECT trip_id, sequence, MIN(id) AS keep_id, COUNT(*) AS cnt
     FROM ' . tbl('route_stops') . '
     WHERE trip_id IS NOT NULL
     GROUP BY trip_id, sequence
     HAVING cnt > 1'
)->fetchAll();

$deletedDupes = 0;
foreach ($dupes as $row) {
    if (!$dryRun) {
        $pdo->prepare(
            'DELETE FROM ' . tbl('route_stops') . '
             WHERE trip_id = ? AND sequence = ? AND id != ?'
        )->execute([$row['trip_id'], $row['sequence'], $row['keep_id']]);
    }
    $deletedDupes += $row['cnt'] - 1;
}
echo "  {$deletedDupes} doppelte Einträge " . ($dryRun ? 'würden gelöscht' : 'gelöscht') . ".\n";

// ---------------------------------------------------------------------------
// Schritt 2: UNIQUE-Key (trip_id, sequence) setzen (falls nicht vorhanden)
// ---------------------------------------------------------------------------
echo "Schritt 2: UNIQUE-Key auf route_stops setzen...\n";

$uqExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = '" . tbl('route_stops') . "'
       AND index_name   = '" . tbl('') . "route_stops_seq'
       AND NON_UNIQUE   = 0"
)->fetchColumn();

if ($uqExists === 0) {
    if (!$dryRun) {
        $pdo->exec(
            'ALTER TABLE ' . tbl('route_stops') .
            ' ADD UNIQUE KEY `uq_' . tbl('') . 'route_stops_seq` (`trip_id`, `sequence`)'
        );
    }
    echo "  UNIQUE-Key " . ($dryRun ? 'würde gesetzt' : 'gesetzt') . ".\n";
} else {
    echo "  UNIQUE-Key bereits vorhanden, übersprungen.\n";
}

// ---------------------------------------------------------------------------
// Schritt 3: recording_id-Spalte entfernen (FK + Index + Spalte)
// ---------------------------------------------------------------------------
echo "Schritt 3: recording_id aus route_stops entfernen...\n";

$recColExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = '" . tbl('route_stops') . "'
       AND column_name  = 'recording_id'"
)->fetchColumn();

if ($recColExists > 0) {
    if (!$dryRun) {
        // FK entfernen (ignoriere Fehler falls schon weg)
        try {
            $pdo->exec(
                'ALTER TABLE ' . tbl('route_stops') .
                ' DROP FOREIGN KEY `fk_' . tbl('') . 'route_stops_recording`'
            );
        } catch (PDOException $e) {
            // FK bereits entfernt
        }
        // Index entfernen
        try {
            $pdo->exec(
                'ALTER TABLE ' . tbl('route_stops') .
                ' DROP INDEX `idx_' . tbl('') . 'route_stops_recording`'
            );
        } catch (PDOException $e) {
            // Index bereits entfernt
        }
        // Spalte entfernen
        $pdo->exec('ALTER TABLE ' . tbl('route_stops') . ' DROP COLUMN `recording_id`');
    }
    echo "  recording_id-Spalte " . ($dryRun ? 'würde entfernt' : 'entfernt') . ".\n";
} else {
    echo "  recording_id bereits entfernt, übersprungen.\n";
}

// ---------------------------------------------------------------------------
// Schritt 4: Fingerprints für alle Trips berechnen
// ---------------------------------------------------------------------------
echo "Schritt 4: Fingerprints berechnen...\n";

$trips = $pdo->query(
    'SELECT id, period_id, day_type FROM ' . tbl('trips') . ' WHERE schedule_fingerprint IS NULL'
)->fetchAll();

echo "  " . count($trips) . " Trips ohne Fingerprint gefunden.\n";

$updated      = 0;
$noRouteStops = 0;
$merged       = 0;

$routeStmt = $pdo->prepare(
    'SELECT stop_id AS stopId, departure_planned AS departurePlanned
     FROM ' . tbl('route_stops') . '
     WHERE trip_id = ?
     ORDER BY sequence ASC'
);

$updateStmt = $pdo->prepare(
    'UPDATE ' . tbl('trips') . '
     SET path_fingerprint = ?, schedule_fingerprint = ?
     WHERE id = ?'
);

// Fingerprints vorberechnen, Duplikate erkennen bevor etwas geschrieben wird.
// Struktur: 'period_id|schedule_fp|day_type' => winner_trip_id
$fpIndex = [];

// Trips mit route_stops zuerst erfassen (Trips ohne bleiben NULL)
$tripData = [];
foreach ($trips as $trip) {
    $routeStmt->execute([$trip['id']]);
    $stops = $routeStmt->fetchAll();

    if (empty($stops)) {
        $noRouteStops++;
        $tripData[$trip['id']] = null; // kein Fingerprint möglich
        continue;
    }

    $stopsForFp = array_map(function (array $s): array {
        return [
            'stopId'           => $s['stopId'],
            'departurePlanned' => $s['departurePlanned'] !== null
                ? str_replace(' ', 'T', $s['departurePlanned']) . 'Z'
                : null,
        ];
    }, $stops);

    $tripData[$trip['id']] = [
        'fp'        => compute_fingerprints($stopsForFp),
        'period_id' => $trip['period_id'],
        'day_type'  => $trip['day_type'],
    ];
}

$rebaseStmt = $pdo->prepare(
    'UPDATE ' . tbl('recordings') . ' SET trip_id = ? WHERE trip_id = ?'
);

$deleteStmt = $pdo->prepare(
    'DELETE FROM ' . tbl('trips') . ' WHERE id = ?'
);

foreach ($tripData as $tripId => $data) {
    if ($data === null) {
        continue; // kein Fingerprint möglich → überspringen
    }

    $fp       = $data['fp'];
    $indexKey = $data['period_id'] . '|' . ($fp['schedule'] ?? '') . '|' . $data['day_type'];

    if ($fp['schedule'] !== null && isset($fpIndex[$indexKey])) {
        // Duplikat: gleicher Fingerprint, andere service_nr → in Gewinner-Trip zusammenführen
        $winnerId = $fpIndex[$indexKey];
        echo "  Merge: Trip #{$tripId} → #{$winnerId}\n";

        if (!$dryRun) {
            $rebaseStmt->execute([$winnerId, $tripId]);
            // route_stops des Duplikats werden via CASCADE mitgelöscht
            $deleteStmt->execute([$tripId]);
        }
        $merged++;
        continue;
    }

    // Erster Treffer für diesen Fingerprint → als Gewinner registrieren
    if ($fp['schedule'] !== null) {
        $fpIndex[$indexKey] = $tripId;
    }

    if (!$dryRun) {
        $updateStmt->execute([$fp['path'], $fp['schedule'], $tripId]);
    }
    $updated++;
}

echo "  {$updated} Trips " . ($dryRun ? 'würden aktualisiert' : 'aktualisiert') . ".\n";
echo "  {$merged} Duplikat-Trips " . ($dryRun ? 'würden zusammengeführt' : 'zusammengeführt') . " (gleicher Fingerprint, unterschiedliche service_nr).\n";
echo "  {$noRouteStops} Trips ohne route_stops (Fingerprint bleibt NULL, service_nr-Fallback greift).\n";

// ---------------------------------------------------------------------------
// Schritt 5: last_hafas_trip_id aus neuester Erfassung je Trip befüllen
// ---------------------------------------------------------------------------
echo "Schritt 5: last_hafas_trip_id befüllen...\n";

if (!$dryRun) {
    $affected = $pdo->exec(
        'UPDATE ' . tbl('trips') . ' t
         JOIN (
             SELECT trip_id, hafas_trip_id
             FROM ' . tbl('recordings') . '
             WHERE (trip_id, recorded_at) IN (
                 SELECT trip_id, MAX(recorded_at)
                 FROM ' . tbl('recordings') . '
                 GROUP BY trip_id
             )
         ) latest ON latest.trip_id = t.id
         SET t.last_hafas_trip_id = latest.hafas_trip_id
         WHERE t.last_hafas_trip_id IS NULL'
    );
    echo "  {$affected} Trips mit last_hafas_trip_id befüllt.\n";
} else {
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM ' . tbl('trips') . ' WHERE last_hafas_trip_id IS NULL'
    )->fetchColumn();
    echo "  {$count} Trips würden mit last_hafas_trip_id befüllt.\n";
}

echo "\nMigration v3 abgeschlossen" . ($dryRun ? ' (DRY-RUN)' : '') . ".\n";
