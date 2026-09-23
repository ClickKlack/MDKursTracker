<?php
// Auflösung einer HAFAS-Fahrt auf einen Trip-Datensatz, wenn der
// schedule_fingerprint nicht exakt trifft.
//
// Hintergrund: Bei einer Umleitung verschiebt HAFAS nicht nur den Laufweg
// (Zusatzhalte, entfallende Halte – siehe scheduled_stops_only() in
// lib/fingerprint.php), sondern auch einzelne *Planzeiten*. Am Verzweigungs-
// halt trägt der Halt dann den Hinweis
// "text.realtime.stop.scheduled.dep.arr.time.changed" und eine um ein, zwei
// Minuten abweichende Sollzeit.
//
// Real beobachtet am 23.09.2026, Linie 10 Richtung Alte Neustadt: Von 27
// Halten wich genau einer ab – S-Bahnhof Neustadt lag bei +22 statt +23
// Minuten nach Fahrtbeginn. Da der schedule_fingerprint über alle
// Halt-Zeit-Paare geht, genügte diese eine Minute, um die Fahrt als neue
// Fahrt zu behandeln und einen zweiten Trip-Datensatz anzulegen.
//
// Der Fingerprint selbst bleibt deshalb unverändert – er ist der exakte,
// primäre Schlüssel. Greift er nicht, sucht dieser Fallback eine Fahrt mit
// demselben Laufweg (path_fingerprint) und nahezu identischen Planzeiten.
//
// Sicherheit gegen Fehlzuordnung:
//   - Der Laufweg muss exakt übereinstimmen (path_fingerprint).
//   - Die Linie muss übereinstimmen. Ohne diesen Filter träfe die Toleranz
//     tatsächlich daneben: In Periode 1 fahren Linie 3 und Linie 4 abends
//     denselben Weg zum Betriebshof Nord, zwei Minuten versetzt, mit
//     verschiedenen Kursnummern (Trips 401 und 403).
//   - Jeder einzelne Halt darf um höchstens TRIP_SCHEDULE_TOLERANCE_MINUTES
//     abweichen. Aufeinanderfolgende Fahrten derselben Linie liegen im
//     dichtesten Takt fünf Minuten auseinander – deutlich über der Toleranz.
//   - Bleiben mehrere Kandidaten übrig, wird *nicht* zugeordnet. Lieber ein
//     zusätzlicher Trip als eine Erfassung an der falschen Fahrt.
//
// Über alle 2392 gespeicherten Fahrten geprüft: Von 24.056 Paaren mit
// gleichem Laufweg, Wochentagstyp und Periode liegen nur die beiden echten
// Umleitungs-Duplikate innerhalb der Toleranz.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fingerprint.php';

/** Zulässige Abweichung je Halt in Minuten. */
const TRIP_SCHEDULE_TOLERANCE_MINUTES = 2;

/**
 * Vergleicht zwei geordnete Listen von "HH:MM"-Zeiten auf Quasi-Gleichheit.
 *
 * Beide Listen müssen gleich lang sein. Jede Position darf um höchstens
 * $toleranceMinutes abweichen; eine einzige größere Abweichung genügt zur
 * Ablehnung.
 *
 * Tageswechsel: Die Differenz wird über die Tagesgrenze hinweg gerechnet,
 * damit 23:59 und 00:00 eine Minute auseinanderliegen und nicht 1439.
 *
 * @param string[] $a
 * @param string[] $b
 */
function schedule_times_match(array $a, array $b, int $toleranceMinutes = TRIP_SCHEDULE_TOLERANCE_MINUTES): bool
{
    if ($a === [] || count($a) !== count($b)) {
        return false;
    }

    $toMinutes = static function (string $hhmm): ?int {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    };

    foreach ($a as $i => $timeA) {
        $x = $toMinutes((string) $timeA);
        $y = $toMinutes((string) $b[$i]);
        if ($x === null || $y === null) {
            return false;
        }
        $diff = abs($x - $y);
        $diff = min($diff, 1440 - $diff); // über Mitternacht
        if ($diff > $toleranceMinutes) {
            return false;
        }
    }

    return true;
}

/**
 * Extrahiert die "HH:MM"-Zeiten (UTC) eines Laufwegs in Halt-Reihenfolge.
 *
 * Zusatzhalte bleiben außen vor, damit die Liste zum gespeicherten
 * Planlaufweg in route_stops passt. Halte ohne Zeit werden übersprungen –
 * genau wie in compute_schedule_fingerprint().
 *
 * @param array $stops Ausgabe von hafas_trip()
 * @return string[]
 */
function schedule_times_from_stops(array $stops): array
{
    $times = [];
    foreach (scheduled_stops_only($stops) as $s) {
        $time = extract_utc_hhmm($s['departurePlanned'] ?? null);
        if ($time !== null) {
            $times[] = $time;
        }
    }
    return $times;
}

/**
 * Ermittelt die vorherrschende Linie eines Laufwegs.
 *
 * GET /api/trip kennt die Linie nicht aus dem Request – sie steckt nur in den
 * Halten. Bei durchgebundenen Fahrten wechselt sie unterwegs; genommen wird
 * die häufigste, weil trips.line den überwiegenden Teil der Fahrt beschreibt.
 *
 * @param array $stops Ausgabe von hafas_trip()
 */
function dominant_line_from_stops(array $stops): ?string
{
    $counts = [];
    foreach ($stops as $s) {
        $line = $s['line'] ?? null;
        if ($line === null || $line === '') {
            continue;
        }
        $counts[$line] = ($counts[$line] ?? 0) + 1;
    }

    if ($counts === []) {
        return null;
    }

    arsort($counts);
    return (string) array_key_first($counts);
}

/**
 * Sucht eine Fahrt mit gleichem Laufweg und nahezu gleichen Planzeiten.
 *
 * Wird nur aufgerufen, wenn der exakte schedule_fingerprint-Lookup leer
 * ausging. Legt selbst nichts an und ändert nichts.
 *
 * @param array       $stops Ausgabe von hafas_trip() (ungefiltert)
 * @param string|null $line  Linienbezeichnung; ohne sie findet keine Suche
 *                           statt, da die Linie der entscheidende Filter ist
 * @return int|null Trip-ID bei genau einem Treffer, sonst null
 */
function find_trip_by_near_schedule(
    PDO $pdo,
    int $periodId,
    string $dayType,
    string $pathFingerprint,
    array $stops,
    ?string $line
): ?int {
    if ($line === null || $line === '') {
        return null;
    }

    $times = schedule_times_from_stops($stops);
    if (count($times) < 2) {
        return null;
    }

    // Kandidaten: gleiche Linie, gleicher Laufweg, gleiche Periode,
    // gleicher Wochentagstyp.
    $stmt = $pdo->prepare(
        'SELECT t.id,
                GROUP_CONCAT(
                    DATE_FORMAT(rs.departure_planned, \'%H:%i\')
                    ORDER BY rs.sequence ASC SEPARATOR \',\'
                ) AS times
           FROM ' . tbl('trips') . ' t
           JOIN ' . tbl('route_stops') . ' rs ON rs.trip_id = t.id
          WHERE t.period_id        = ?
            AND t.day_type         = ?
            AND t.line             = ?
            AND t.path_fingerprint = ?
            AND rs.departure_planned IS NOT NULL
          GROUP BY t.id'
    );
    $stmt->execute([$periodId, $dayType, $line, $pathFingerprint]);

    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        $candidate = explode(',', (string) $row['times']);
        if (schedule_times_match($times, $candidate)) {
            $matches[] = (int) $row['id'];
        }
    }

    // Mehrdeutigkeit ist kein Treffer.
    return count($matches) === 1 ? $matches[0] : null;
}
