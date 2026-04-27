#!/usr/bin/env php
<?php
// Migrations-Skript für den HAFAS-Mitternachts-Bug.
//
// Hintergrund: Bis zum Fix in lib/hafas.php hat hafas_iso() für Halte nach
// Mitternacht das 8-stellige HAFAS-Format ('NNHHMMSS') nicht erkannt und nur
// die ersten 6 Stellen ausgewertet. Dadurch landeten in route_stops für solche
// Halte UTC-Zeitstempel des Musters HH:00:SS (Soll-Minuten in den Sekunden);
// schedule_fingerprint und day_type wurden auf Basis dieser falschen Werte
// gebildet.
//
// Dieses Skript:
//   1. identifiziert betroffene Trips (route_stops mit SECOND(...) > 0)
//   2. lädt für jeden den Laufweg frisch von HAFAS (mit jetzt korrektem Parser)
//   3. berechnet neuen path_/schedule_fingerprint und Betriebstag
//   4. führt Trip in vorhandenen passenden Trip zusammen (Recordings umhängen)
//      ODER aktualisiert Fingerprint, day_type und route_stops in-place
//
// Voraussetzung: lib/hafas.php enthält den 'NNHHMMSS'-Fix (sonst wird re-import
// dieselben falschen Werte zurückliefern).
//
// Aufrufe:
//   php migrations/v4_fix_overnight_trips.php
//   php migrations/v4_fix_overnight_trips.php --dry-run
//
// Idempotent: erneuter Lauf nach erfolgreicher Migration ist ein No-Op
// (keine route_stops mit Sekunden > 0 mehr vorhanden).

declare(strict_types=1);

// Projektverzeichnis: optionales erstes nicht-Schalter-Argument
$projectRoot = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '' && $a[0] !== '-') {
        $projectRoot = rtrim($a, '/');
        break;
    }
}
$projectRoot ??= dirname(__DIR__);
chdir($projectRoot);

require_once 'vendor/autoload.php';
require_once 'lib/db.php';
require_once 'lib/hafas.php';
require_once 'lib/fingerprint.php';
require_once 'lib/calendar.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
if ($dryRun) {
    echo "[DRY-RUN] Keine Änderungen werden geschrieben.\n";
}

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// Schritt 1: Betroffene Trips identifizieren
// ---------------------------------------------------------------------------
echo "Schritt 1: Bug-Signatur in route_stops suchen...\n";

$affectedTrips = $pdo->query(
    'SELECT DISTINCT t.id, t.period_id, t.day_type, t.last_hafas_trip_id,
                     t.service_nr, t.line, t.direction
     FROM ' . tbl('trips') . ' t
     JOIN ' . tbl('route_stops') . ' rs ON rs.trip_id = t.id
     WHERE rs.departure_planned IS NOT NULL
       AND SECOND(rs.departure_planned) > 0'
)->fetchAll();

echo '  ' . count($affectedTrips) . " betroffene Trips gefunden.\n";

if (count($affectedTrips) === 0) {
    echo "Nichts zu tun. Migration beendet.\n";
    exit(0);
}

$noJid     = 0;
$merged    = 0;
$updated   = 0;
$failed    = 0;
$unchanged = 0;

// Vorbereiteter Selektor: gibt es bereits einen Trip mit dem neuen Fingerprint?
$lookupStmt = $pdo->prepare(
    'SELECT id FROM ' . tbl('trips') . '
     WHERE period_id = ? AND schedule_fingerprint = ? AND day_type = ?
       AND id != ?'
);

$updateTripStmt = $pdo->prepare(
    'UPDATE ' . tbl('trips') . '
     SET path_fingerprint     = ?,
         schedule_fingerprint = ?,
         day_type             = ?,
         direction            = COALESCE(NULLIF(?, \'\'), direction)
     WHERE id = ?'
);

$deleteRouteStmt = $pdo->prepare(
    'DELETE FROM ' . tbl('route_stops') . ' WHERE trip_id = ?'
);

$insertStopStmt = $pdo->prepare(
    'INSERT IGNORE INTO ' . tbl('stops') . ' (hafas_id, name) VALUES (?, ?)'
);

$insertRouteStmt = $pdo->prepare(
    'INSERT INTO ' . tbl('route_stops') . '
        (trip_id, sequence, stop_id, departure_planned, line)
     VALUES (?, ?, ?, ?, ?)'
);

$rebaseStmt = $pdo->prepare(
    'UPDATE ' . tbl('recordings') . ' SET trip_id = ? WHERE trip_id = ?'
);

$deleteTripStmt = $pdo->prepare(
    'DELETE FROM ' . tbl('trips') . ' WHERE id = ?'
);

// ---------------------------------------------------------------------------
// Schritt 2: Pro Trip frisch von HAFAS laden, Fingerprint/day_type neu setzen
// ---------------------------------------------------------------------------
foreach ($affectedTrips as $trip) {
    $jid = $trip['last_hafas_trip_id'];
    if ($jid === null || $jid === '') {
        $noJid++;
        echo "  Trip #{$trip['id']}: keine last_hafas_trip_id → übersprungen.\n";
        continue;
    }

    // Cache-Eintrag invalidieren, damit garantiert frisch von HAFAS geladen wird
    $cacheFile = hafas_cache_dir() . '/' . sha1('trip|' . $jid) . '.cache';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    try {
        $stops = hafas_trip($jid);
    } catch (Throwable $e) {
        $failed++;
        echo "  Trip #{$trip['id']}: HAFAS-Fehler: " . $e->getMessage() . "\n";
        continue;
    }

    if (empty($stops)) {
        $failed++;
        echo "  Trip #{$trip['id']}: leerer Laufweg von HAFAS.\n";
        continue;
    }

    $fp           = compute_fingerprints($stops);
    $serviceDate  = derive_service_date($stops);
    $newDayType   = $serviceDate !== null
        ? getDayType(new DateTimeImmutable($serviceDate), $pdo)
        : $trip['day_type'];

    // Lookup: existiert schon ein anderer Trip mit dem neuen Fingerprint+day_type?
    $lookupStmt->execute([
        $trip['period_id'],
        $fp['schedule'],
        $newDayType,
        $trip['id'],
    ]);
    $winnerId = $lookupStmt->fetchColumn();

    if ($winnerId !== false) {
        // Merge: Recordings auf Gewinner umhängen, alten Trip + route_stops löschen
        echo "  Trip #{$trip['id']} → merge in #{$winnerId} (day_type {$trip['day_type']} → {$newDayType})\n";
        if (!$dryRun) {
            $pdo->beginTransaction();
            try {
                $rebaseStmt->execute([$winnerId, $trip['id']]);
                $deleteRouteStmt->execute([$trip['id']]);
                $deleteTripStmt->execute([$trip['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo "    FEHLER beim Merge: " . $e->getMessage() . "\n";
                $failed++;
                continue;
            }
        }
        $merged++;
        continue;
    }

    // In-place Update: Trip behalten, Fingerprint/day_type/route_stops erneuern
    $direction = '';
    foreach (array_reverse($stops) as $s) {
        if (!empty($s['stop'])) { $direction = (string) $s['stop']; break; }
    }

    if ($trip['day_type'] === $newDayType
        && $fp['schedule'] !== null
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . tbl('route_stops') . '
             WHERE trip_id = ' . (int) $trip['id'] . '
               AND SECOND(departure_planned) > 0'
        )->fetchColumn() === 0
    ) {
        // Hat sich offenbar bereits selbst korrigiert (Race condition / paralleler Lauf)
        $unchanged++;
        continue;
    }

    echo "  Trip #{$trip['id']}: in-place update"
       . ($trip['day_type'] !== $newDayType ? " (day_type {$trip['day_type']} → {$newDayType})" : "")
       . "\n";

    if (!$dryRun) {
        $pdo->beginTransaction();
        try {
            $updateTripStmt->execute([
                $fp['path'], $fp['schedule'], $newDayType, $direction, $trip['id'],
            ]);
            $deleteRouteStmt->execute([$trip['id']]);
            foreach ($stops as $s) {
                $insertStopStmt->execute([$s['stopId'], $s['stop'] ?? '']);
                $insertRouteStmt->execute([
                    $trip['id'],
                    $s['sequence'],
                    $s['stopId'],
                    $s['departurePlanned'] !== null
                        ? (new DateTimeImmutable($s['departurePlanned']))
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->format('Y-m-d H:i:s')
                        : null,
                    $s['line'] ?? null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "    FEHLER beim Update: " . $e->getMessage() . "\n";
            $failed++;
            continue;
        }
    }
    $updated++;
}

// ---------------------------------------------------------------------------
// Zusammenfassung
// ---------------------------------------------------------------------------
echo "\n";
echo "Zusammenfassung:\n";
echo "  Aktualisiert (in-place):    {$updated}\n";
echo "  Zusammengeführt (merge):    {$merged}\n";
echo "  Bereits sauber:             {$unchanged}\n";
echo "  Übersprungen (keine jid):   {$noJid}\n";
echo "  Fehlgeschlagen:             {$failed}\n";

exit($failed > 0 ? 1 : 0);
