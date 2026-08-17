<?php
// Diagnose der Kursnummer-Heuristik.
//
// Zwei Befundarten, die zusammengehören wie Ursache und Symptom:
//
//   detect_schedule_drift()   – Ursache: Innerhalb einer Fahrplanperiode hat
//                               sich der Laufweg einer Linie geändert. Alte und
//                               neue Fahrten leben dann nebeneinander in
//                               derselben Periode und fahren zur selben Minute
//                               vom selben Steig ab.
//   detect_course_conflicts() – Symptom: Route-Schlüssel, an denen sich Trips
//                               auf dieselbe Kursnummer nicht einigen und die
//                               auch die Stichentscheide aus
//                               narrow_course_candidates() nicht trennen. Diese
//                               Abfahrten zeigen Nutzern dauerhaft "??".
//
// Beide Funktionen sind lesend und ohne Seiteneffekte – sie werden sowohl vom
// Admin-Endpunkt als auch vom täglichen Cron-Report genutzt.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/course_lookup.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/fingerprint.php';

/**
 * Findet Route-Schlüssel, an denen die Heuristik keine Kursnummer liefern kann.
 *
 * Ein Schlüssel ist unauflösbar, sobald zwei Kandidaten mit verschiedenen
 * bekannten Kursnummern in denselben Stichentscheid-Topf fallen, also in
 * Richtung *und* Start-/Endzeit der Gesamtfahrt übereinstimmen. Dann greift
 * keine der Stufen in narrow_course_candidates() mehr.
 *
 * Kandidaten, die sich über Richtung oder Laufwegzeiten trennen lassen, gelten
 * nicht als Befund – die liefert die Abfahrtstafel korrekt aus.
 *
 * @return array Liste aus ['routeKey','stopId','stopName','line','dayType',
 *               'hhmm','direction','journeyStart','journeyEnd','courses',
 *               'tripIds']
 */
function detect_course_conflicts(PDO $pdo, int $periodId): array
{
    $map       = build_route_stop_candidate_map($pdo, $periodId);
    $stopNames = load_stop_names($pdo);
    $findings  = [];

    foreach ($map as $routeKey => $candidates) {
        if (count($candidates) < 2) {
            continue; // ein Kandidat kann sich nicht widersprechen
        }

        // Nach dem Stichentscheid-Topf gruppieren: Richtung + Laufwegzeiten.
        $buckets = [];
        foreach ($candidates as $c) {
            if ($c['course'] === null) {
                continue; // unbekannte Kursnummer blockiert nicht
            }
            $bucketKey = ($c['direction'] ?? '') . '|' . ($c['journeyStart'] ?? '') . '|' . ($c['journeyEnd'] ?? '');
            $buckets[$bucketKey][] = $c;
        }

        foreach ($buckets as $bucket) {
            $courses = array_values(array_unique(array_column($bucket, 'course')));
            if (count($courses) < 2) {
                continue; // einig – kein Befund
            }

            [$stopId, $line, $dayType, $hhmm] = array_pad(explode('|', $routeKey), 4, '');
            sort($courses);

            $findings[] = [
                'routeKey'     => $routeKey,
                'stopId'       => $stopId,
                'stopName'     => $stopNames[$stopId] ?? $stopId,
                'line'         => $line,
                'dayType'      => $dayType,
                'hhmm'         => $hhmm,
                'direction'    => $bucket[0]['direction'],
                'journeyStart' => $bucket[0]['journeyStart'],
                'journeyEnd'   => $bucket[0]['journeyEnd'],
                'courses'      => $courses,
                'tripIds'      => array_column($bucket, 'tripId'),
            ];
        }
    }

    // Stabile Sortierung: Linie, Tagestyp, Uhrzeit
    usort($findings, static function (array $a, array $b): int {
        return [$a['line'], $a['dayType'], $a['hhmm']] <=> [$b['line'], $b['dayType'], $b['hhmm']];
    });

    return $findings;
}

/**
 * Erkennt Fahrplanwechsel innerhalb einer laufenden Periode.
 *
 * Der Detektor hängt bewusst an dem, was tatsächlich schadet, statt an
 * allgemeiner Laufweg-Statistik: Gesucht sind Route-Schlüssel, an denen zwei
 * Trips mit *verschiedenen* Kursnummern zusammentreffen, deren
 * Erfassungszeiträume sich aber **nicht überlappen**. Der eine hört auf, wo der
 * andere anfängt – das ist die Signatur eines Fahrplanwechsels und zugleich
 * genau die Konstellation, aus der uneindeutige Kursnummern entstehen.
 *
 * Dauerhaft nebeneinander bestehende Varianten (Verstärker- und Einrückfahrten)
 * überlappen zeitlich und bleiben deshalb unauffällig. Ebenso Trips ohne
 * Kursnummer – ohne Kurs kein Schaden.
 *
 * Der Befund greift auch dann, wenn narrow_course_candidates() den Konflikt
 * inzwischen über Richtung oder Laufwegzeiten auflöst: Als Frühwarnung ist er
 * gerade dann wertvoll, wenn Nutzer noch nichts merken.
 *
 * Die Einzelfunde werden je (Linie, Tagestyp, alter Endhalt → neuer Endhalt)
 * zusammengefasst, damit ein Wechsel eine Meldung ergibt und nicht hunderte.
 *
 * @return array Liste aus ['line','dayType','changedOn','affectedKeys',
 *               'examples','from'=>[...],'to'=>[...]]
 */
function detect_schedule_drift(PDO $pdo, int $periodId): array
{
    $meta      = load_trip_drift_meta($pdo, $periodId);
    $map       = build_route_stop_candidate_map($pdo, $periodId);
    $stopNames = load_stop_names($pdo);

    $events = [];

    foreach ($map as $routeKey => $candidates) {
        if (count($candidates) < 2) {
            continue;
        }

        foreach ($candidates as $a) {
            foreach ($candidates as $b) {
                // Nur Paare mit zwei bekannten, verschiedenen Kursnummern.
                if ($a['course'] === null || $b['course'] === null || $a['course'] === $b['course']) {
                    continue;
                }
                $mA = $meta[$a['tripId']] ?? null;
                $mB = $meta[$b['tripId']] ?? null;
                if ($mA === null || $mB === null) {
                    continue;
                }
                // Nur die Richtung "alt → neu" betrachten, sonst zählt jedes
                // Paar doppelt. Überlappen die Fenster, ist es kein Wechsel.
                if ($mA['lastSeen'] >= $mB['firstSeen']) {
                    continue;
                }

                [, $line, $dayType] = array_pad(explode('|', $routeKey), 4, '');
                $eventKey = $line . '|' . $dayType . '|' . $mA['endStopId'] . '|' . $mB['endStopId'];

                if (!isset($events[$eventKey])) {
                    $events[$eventKey] = [
                        'line'         => $line,
                        'dayType'      => $dayType,
                        'changedOn'    => $mB['firstSeen'],
                        'affectedKeys' => [],
                        'examples'     => [],
                        'from'         => [
                            'endStopId'   => $mA['endStopId'],
                            'endStopName' => $stopNames[$mA['endStopId']] ?? $mA['endStopId'],
                            'stopCount'   => $mA['stopCount'],
                            'lastSeen'    => $mA['lastSeen'],
                            'tripIds'     => [],
                        ],
                        'to'           => [
                            'endStopId'   => $mB['endStopId'],
                            'endStopName' => $stopNames[$mB['endStopId']] ?? $mB['endStopId'],
                            'stopCount'   => $mB['stopCount'],
                            'firstSeen'   => $mB['firstSeen'],
                            'tripIds'     => [],
                        ],
                    ];
                }

                $ev = &$events[$eventKey];
                $ev['affectedKeys'][$routeKey]      = true;
                $ev['from']['tripIds'][$a['tripId']] = true;
                $ev['to']['tripIds'][$b['tripId']]   = true;
                // Frühester Wechseltermin gewinnt – das ist der Tag, an dem
                // geschnitten werden müsste.
                $ev['changedOn']        = min($ev['changedOn'], $mB['firstSeen']);
                $ev['to']['firstSeen']  = min($ev['to']['firstSeen'], $mB['firstSeen']);
                $ev['from']['lastSeen'] = max($ev['from']['lastSeen'], $mA['lastSeen']);
                if (count($ev['examples']) < 5) {
                    $ev['examples'][] = [
                        'routeKey' => $routeKey,
                        'stopName' => $stopNames[explode('|', $routeKey)[0]] ?? '',
                        'hhmm'     => explode('|', $routeKey)[3] ?? '',
                        'oldCourse' => $a['course'],
                        'newCourse' => $b['course'],
                    ];
                }
                unset($ev);
            }
        }
    }

    $findings = [];
    foreach ($events as $ev) {
        $ev['affectedKeys']   = count($ev['affectedKeys']);
        $ev['from']['tripIds'] = array_keys($ev['from']['tripIds']);
        $ev['to']['tripIds']   = array_keys($ev['to']['tripIds']);
        $ev['from']['tripCount'] = count($ev['from']['tripIds']);
        $ev['to']['tripCount']   = count($ev['to']['tripIds']);
        $findings[] = $ev;
    }

    // Nach Tragweite sortieren: Ein Wechsel, der zwei Dutzend Route-Schlüssel
    // betrifft, ist der handlungsrelevante – ein Einzelfund ist meist nur eine
    // Verstärkerfahrt, die zufällig aus dem Zeitfenster fiel.
    usort($findings, static function (array $a, array $b): int {
        return [$b['affectedKeys'], $b['changedOn']] <=> [$a['affectedKeys'], $a['changedOn']];
    });

    return $findings;
}

/**
 * Erkennt Laufweg-Änderungen an einer konkreten Fahrt.
 *
 * "Konkrete Fahrt" ist hier der Slot aus Linie, Tagestyp, Starthaltestelle und
 * Soll-Abfahrtszeit – also das, was man umgangssprachlich "die Fahrt um 21 Uhr"
 * nennt. Innerhalb eines Slots darf sich der Laufweg nicht ändern: Wenn die
 * 21-Uhr-Fahrt bisher bis zur Endstation lief und plötzlich vorher endet, ist
 * entweder der Fahrplan gewechselt oder die Erfassung hat etwas Falsches
 * eingefangen.
 *
 * Bewusst *kein* Befund sind Linien, deren Kurse abwechselnd weiterfahren
 * (Linie 10): Die Kurzläufer haben eigene Soll-Zeiten und damit eigene Slots,
 * treffen also nie im selben Slot aufeinander. Ebenso wenig gemeldet werden
 * Varianten, deren Beobachtungszeiträume sich überlappen – die bestehen
 * nebeneinander und sind planmäßig.
 *
 * Anders als detect_schedule_drift() verlangt dieser Detektor *keine*
 * widersprüchlichen Kursnummern. Er schlägt also auch an, wenn die Fahrt ihre
 * Kursnummer behält und sich nur das Ziel ändert.
 *
 * Der Slot wird zweimal gebildet – einmal am Fahrtanfang verankert, einmal am
 * Fahrtende. Nur so werden beide Einkürzungsrichtungen erfasst: Wird hinten
 * gekürzt, bleibt der Start gleich; wird vorn gekürzt, bleibt das Ziel gleich
 * und die Startzeit wandert. Befunde, die beide Läufe finden, werden über das
 * beteiligte Trip-Paar entdoppelt.
 *
 * @param int $minObservedDays Mindestzahl unterschiedlicher Erfassungstage der
 *                             alten Variante. Standard 1: In der Praxis wird
 *                             eine Fahrt oft nur an einem Tag erfasst, eine
 *                             höhere Schwelle würde echte Wechsel verschlucken.
 *                             Die Aussagekraft entsteht stattdessen daraus, dass
 *                             mehrere Slots derselben Linie am selben Tag
 *                             wechseln.
 * @return array Liste aus ['line','dayType','anchor','slotStopName','slotHhmm',
 *               'changedOn','shortened','from'=>[...],'to'=>[...]]
 */
function detect_trip_route_changes(PDO $pdo, int $periodId, int $minObservedDays = 1): array
{
    $meta      = load_trip_drift_meta($pdo, $periodId);
    $stopNames = load_stop_names($pdo);

    $findings = [];
    $seenPairs = [];

    foreach (['start', 'end'] as $anchor) {
        foreach (find_slot_route_changes($meta, $anchor, $minObservedDays) as $f) {
            // Entdoppeln: dasselbe Trip-Paar kann über beide Verankerungen
            // gefunden werden, wenn sich Start *und* Ziel geändert haben.
            $pairKey = min($f['from']['tripIds']) . '-' . min($f['to']['tripIds']);
            if (isset($seenPairs[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;

            $f['slotStopName']         = $stopNames[$f['slotStopId']] ?? $f['slotStopId'];
            $f['from']['movedStopName'] = $stopNames[$f['from']['movedStopId']] ?? $f['from']['movedStopId'];
            $f['to']['movedStopName']   = $stopNames[$f['to']['movedStopId']] ?? $f['to']['movedStopId'];
            // Verkürzung = das neue Fahrtende liegt auf dem bisherigen Laufweg.
            $f['shortened'] = is_stop_on_route($pdo, $f['from']['tripIds'][0], $f['to']['movedStopId']);

            $findings[] = $f;
        }
    }

    // Jüngste Änderung zuerst – die ist die handlungsrelevante.
    usort($findings, static function (array $a, array $b): int {
        return [$b['changedOn'], $b['line'], $b['slotHhmm']] <=> [$a['changedOn'], $a['line'], $a['slotHhmm']];
    });

    return $findings;
}

/**
 * Sucht Laufweg-Wechsel für eine Slot-Verankerung.
 *
 * @param string $anchor 'start' verankert an Starthalt und Abfahrtszeit,
 *                       'end' an Endhalt und Ankunftszeit.
 * @return array Rohbefunde ohne Haltestellennamen und Verkürzungs-Kennzeichen
 */
function find_slot_route_changes(array $meta, string $anchor, int $minObservedDays): array
{
    $anchorStop = $anchor === 'start' ? 'startStopId' : 'endStopId';
    $anchorTime = $anchor === 'start' ? 'startHhmm'   : 'endHhmm';

    // Nach Slot gruppieren, darin nach Laufweg-Variante. Die Variante wird
    // immer über das *jeweils andere* Ende gebildet – das ist die Seite, die
    // sich bei einer Einkürzung bewegt.
    $slots = [];
    foreach ($meta as $tripId => $m) {
        if (($m[$anchorStop] ?? null) === null || ($m[$anchorTime] ?? null) === null) {
            continue;
        }
        $slotKey    = $m['line'] . '|' . $m['dayType'] . '|' . $m[$anchorStop] . '|' . $m[$anchorTime];
        $variantKey = ($anchor === 'start' ? $m['endStopId'] : $m['startStopId']) . '|' . $m['stopCount'];

        if (!isset($slots[$slotKey][$variantKey])) {
            $slots[$slotKey][$variantKey] = [
                'movedStopId'  => $anchor === 'start' ? $m['endStopId'] : $m['startStopId'],
                'stopCount'    => $m['stopCount'],
                'firstSeen'    => $m['firstSeen'],
                'lastSeen'     => $m['lastSeen'],
                'observedDays' => 0,
                'tripIds'      => [],
                'courses'      => [],
            ];
        }
        $v = &$slots[$slotKey][$variantKey];
        $v['firstSeen']    = min($v['firstSeen'], $m['firstSeen']);
        $v['lastSeen']     = max($v['lastSeen'], $m['lastSeen']);
        $v['observedDays'] += $m['observedDays'];
        $v['tripIds'][]    = $tripId;
        if ($m['course'] !== null) {
            $v['courses'][$m['course']] = true;
        }
        unset($v);
    }

    $findings = [];

    foreach ($slots as $slotKey => $variants) {
        if (count($variants) < 2) {
            continue;
        }
        [$line, $dayType, $slotStopId, $slotHhmm] = explode('|', $slotKey);

        // Jüngste Variante gegen alle älteren prüfen.
        uasort($variants, static fn(array $a, array $b): int => $a['firstSeen'] <=> $b['firstSeen']);
        $ordered = array_values($variants);
        $newest  = array_pop($ordered);

        // Überlappt die neue Variante mit einer älteren, bestehen sie
        // nebeneinander – planmäßige Alternation, kein Befund.
        $previous = null;
        foreach ($ordered as $old) {
            if ($old['lastSeen'] >= $newest['firstSeen']) {
                $previous = null;
                break;
            }
            if ($previous === null || $old['lastSeen'] > $previous['lastSeen']) {
                $previous = $old;
            }
        }

        if ($previous === null || $previous['observedDays'] < $minObservedDays) {
            continue;
        }

        $findings[] = [
            'line'       => $line,
            'dayType'    => $dayType,
            'anchor'     => $anchor,
            'changedSide' => $anchor === 'start' ? 'end' : 'start',
            'slotStopId' => $slotStopId,
            'slotHhmm'   => $slotHhmm,
            'changedOn'  => $newest['firstSeen'],
            'from'       => [
                'movedStopId'  => $previous['movedStopId'],
                'stopCount'    => $previous['stopCount'],
                'lastSeen'     => $previous['lastSeen'],
                'observedDays' => $previous['observedDays'],
                'courses'      => array_keys($previous['courses']),
                'tripIds'      => $previous['tripIds'],
            ],
            'to'         => [
                'movedStopId'  => $newest['movedStopId'],
                'stopCount'    => $newest['stopCount'],
                'firstSeen'    => $newest['firstSeen'],
                'observedDays' => $newest['observedDays'],
                'courses'      => array_keys($newest['courses']),
                'tripIds'      => $newest['tripIds'],
            ],
        ];
    }

    return $findings;
}

/**
 * Liegt eine Haltestelle auf dem Laufweg eines Trips?
 *
 * Unterscheidet eine Verkürzung (das neue Fahrtende liegt auf der bisherigen
 * Strecke) von einem echten Zielwechsel (es liegt woanders).
 *
 * Verglichen wird auf Haltestellen-, nicht auf Steig-Ebene: HAFAS liefert für
 * dieselbe Haltestelle je nach Fahrt abweichende Steig-IDs – Trip 1651 passiert
 * Buckau als 300741401, Trip 1777 startet dort als 300741402. Ohne
 * normalize_stop_id() würde jede Verkürzung als Zielwechsel durchgehen.
 */
function is_stop_on_route(PDO $pdo, int $tripId, ?string $stopId): bool
{
    if ($stopId === null) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT stop_id FROM ' . tbl('route_stops') . ' WHERE trip_id = ?'
    );
    $stmt->execute([$tripId]);

    $needle = normalize_stop_id($stopId);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
        if (normalize_stop_id((string) $candidate) === $needle) {
            return true;
        }
    }

    return false;
}

/**
 * Lädt je Trip Endhaltestelle, Haltzahl und das Zeitfenster, in dem er
 * beobachtet wurde.
 *
 * 'firstSeen' stammt aus dem in route_stops mitgespeicherten Plandatum (dem
 * Tag der ersten Erfassung), 'lastSeen' aus der jüngsten Erfassung.
 *
 * @return array<int, array{endStopId:string, stopCount:int, firstSeen:string, lastSeen:string}>
 */
function load_trip_drift_meta(PDO $pdo, int $periodId): array
{
    $sql = '
        SELECT
            t.id AS trip_id,
            t.line,
            t.day_type,
            t.manual_course_number,
            (
                SELECT r2.course_number
                FROM ' . tbl('recordings') . ' r2
                WHERE r2.trip_id = t.id AND r2.deleted_at IS NULL
                GROUP BY r2.course_number
                ORDER BY COUNT(*) DESC, MIN(r2.recorded_at) ASC
                LIMIT 1
            ) AS majority_course_number,
            (
                SELECT rs_s.stop_id
                FROM ' . tbl('route_stops') . ' rs_s
                WHERE rs_s.trip_id = t.id
                ORDER BY rs_s.sequence ASC
                LIMIT 1
            ) AS start_stop_id,
            (
                SELECT DATE_FORMAT(rs_t.departure_planned, \'%H:%i\')
                FROM ' . tbl('route_stops') . ' rs_t
                WHERE rs_t.trip_id = t.id AND rs_t.departure_planned IS NOT NULL
                ORDER BY rs_t.sequence ASC
                LIMIT 1
            ) AS start_hhmm,
            (
                SELECT rs_e.stop_id
                FROM ' . tbl('route_stops') . ' rs_e
                WHERE rs_e.trip_id = t.id
                ORDER BY rs_e.sequence DESC
                LIMIT 1
            ) AS end_stop_id,
            (
                SELECT DATE_FORMAT(rs_x.departure_planned, \'%H:%i\')
                FROM ' . tbl('route_stops') . ' rs_x
                WHERE rs_x.trip_id = t.id AND rs_x.departure_planned IS NOT NULL
                ORDER BY rs_x.sequence DESC
                LIMIT 1
            ) AS end_hhmm,
            (
                SELECT COUNT(*)
                FROM ' . tbl('route_stops') . ' rs_c
                WHERE rs_c.trip_id = t.id
            ) AS stop_count,
            (
                SELECT DATE(MIN(rs_f.departure_planned))
                FROM ' . tbl('route_stops') . ' rs_f
                WHERE rs_f.trip_id = t.id
            ) AS plan_date,
            (
                SELECT DATE(MAX(r.recorded_at))
                FROM ' . tbl('recordings') . ' r
                WHERE r.trip_id = t.id AND r.deleted_at IS NULL
            ) AS last_recorded,
            (
                SELECT COUNT(DISTINCT DATE(r3.recorded_at))
                FROM ' . tbl('recordings') . ' r3
                WHERE r3.trip_id = t.id AND r3.deleted_at IS NULL
            ) AS observed_days
        FROM ' . tbl('trips') . ' t
        WHERE t.period_id = ?';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$periodId]);

    $meta = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['end_stop_id'] === null || $row['plan_date'] === null) {
            continue;
        }
        $meta[(int) $row['trip_id']] = [
            'line'         => $row['line'],
            'dayType'      => $row['day_type'],
            'course'       => $row['manual_course_number'] ?? $row['majority_course_number'],
            'startStopId'  => $row['start_stop_id'],
            'startHhmm'    => $row['start_hhmm'],
            'endStopId'    => $row['end_stop_id'],
            'endHhmm'      => $row['end_hhmm'],
            'stopCount'    => (int) $row['stop_count'],
            'firstSeen'    => $row['plan_date'],
            'lastSeen'     => $row['last_recorded'] ?? $row['plan_date'],
            'observedDays' => (int) $row['observed_days'],
        ];
    }

    return $meta;
}

/**
 * Hält einen Heuristik-Aussetzer fest, den eine echte Abfahrtsanfrage ausgelöst
 * hat: Zum Route-Schlüssel lagen Kandidaten vor, sie widersprachen sich aber
 * und ließen sich auch nicht über Richtung oder Laufwegzeiten trennen.
 *
 * Zählt bei Wiederholung nur hoch, statt Zeilen zu vervielfachen – so entsteht
 * ein Bild davon, wie oft Nutzer diese Abfahrt tatsächlich ohne Kursnummer
 * sehen. Fehler werden geschluckt: Die Diagnose darf die Abfahrtstafel nie
 * stören.
 *
 * @param array $misses Liste aus ['routeKey','courses','tripIds','direction']
 */
function record_heuristic_misses(PDO $pdo, int $periodId, array $misses): void
{
    if (!$misses) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . tbl('heuristic_misses') . '
                 (period_id, route_key, direction, stop_id, line, day_type, hhmm,
                  courses, trip_ids, hit_count, first_seen, last_seen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 hit_count = hit_count + 1,
                 last_seen = UTC_TIMESTAMP(),
                 courses   = VALUES(courses),
                 trip_ids  = VALUES(trip_ids)'
        );

        foreach ($misses as $miss) {
            [$stopId, $line, $dayType, $hhmm] = array_pad(explode('|', $miss['routeKey']), 4, '');
            if ($dayType === '' || $hhmm === '') {
                continue; // unvollständiger Schlüssel – nichts zu protokollieren
            }
            $stmt->execute([
                $periodId,
                $miss['routeKey'],
                mb_substr((string) ($miss['direction'] ?? ''), 0, 100),
                $stopId,
                $line,
                $dayType,
                $hhmm,
                mb_substr(implode(',', $miss['courses']), 0, 100),
                mb_substr(implode(',', $miss['tripIds']), 0, 255),
            ]);
        }
    } catch (Throwable $e) {
        get_logger()->warning('diagnostics: Heuristik-Aussetzer nicht gespeichert', [
            'periodId'  => $periodId,
            'exception' => $e->getMessage(),
        ]);
    }
}

/**
 * Lädt die protokollierten Heuristik-Aussetzer einer Periode, häufigste zuerst.
 *
 * @return array Liste aus ['routeKey','stopId','stopName','line','dayType',
 *               'hhmm','direction','courses','tripIds','hitCount','firstSeen','lastSeen']
 */
function load_heuristic_misses(PDO $pdo, int $periodId, int $limit = 100): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT route_key, direction, stop_id, line, day_type, hhmm,
                    courses, trip_ids, hit_count, first_seen, last_seen
             FROM ' . tbl('heuristic_misses') . '
             WHERE period_id = ?
             ORDER BY hit_count DESC, last_seen DESC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$periodId]);
    } catch (Throwable $e) {
        // Fehlt die Tabelle (Code ausgerollt, Migration v8 noch nicht gelaufen),
        // soll die Diagnose die übrigen Befunde trotzdem liefern.
        get_logger()->warning('diagnostics: Aussetzer-Tabelle nicht lesbar', [
            'exception' => $e->getMessage(),
        ]);
        return [];
    }

    $stopNames = load_stop_names($pdo);
    $rows      = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'routeKey'  => $row['route_key'],
            'stopId'    => $row['stop_id'],
            'stopName'  => $stopNames[$row['stop_id']] ?? $row['stop_id'],
            'line'      => $row['line'],
            'dayType'   => $row['day_type'],
            'hhmm'      => $row['hhmm'],
            'direction' => $row['direction'] !== '' ? $row['direction'] : null,
            'courses'   => $row['courses'] !== '' ? explode(',', $row['courses']) : [],
            'tripIds'   => $row['trip_ids'] !== '' ? array_map('intval', explode(',', $row['trip_ids'])) : [],
            'hitCount'  => (int) $row['hit_count'],
            'firstSeen' => $row['first_seen'],
            'lastSeen'  => $row['last_seen'],
        ];
    }

    return $rows;
}

/**
 * Haltestellennamen als Map hafas_id → Name (einmal je Aufruf geladen).
 *
 * @return array<string, string>
 */
function load_stop_names(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach ($pdo->query('SELECT hafas_id, name FROM ' . tbl('stops')) as $row) {
        $cache[$row['hafas_id']] = $row['name'];
    }

    return $cache;
}

/**
 * Baut die Report-Nachricht aus den Befunden.
 *
 * Zielformat ist Telegrams HTML-Teilmenge (<b>, <i>); für die Konsolenausgabe
 * wird sie per strip_tags() entschärft. Lange Listen werden nach fünf Einträgen
 * gekappt – die Nachricht soll auf einen Blick lesbar bleiben.
 */
function format_diagnostics_report(
    int $periodId,
    string $periodName,
    array $drift,
    array $conflicts,
    array $misses,
    array $routeChanges = []
): string {
    $e     = 'telegram_escape';
    $lines = [];

    $lines[] = '<b>MDKursTracker – Diagnose</b>';
    $lines[] = 'Periode #' . $periodId . ' · ' . $e($periodName);

    if (!$drift && !$conflicts && !$misses && !$routeChanges) {
        $lines[] = '';
        $lines[] = 'Keine Befunde.';
        return implode("\n", $lines);
    }

    if ($routeChanges) {
        $lines[] = '';
        $lines[] = '<b>⚠ Laufweg einzelner Fahrten geändert (' . count($routeChanges) . ')</b>';
        $lines[] = '<i>Dieselbe Fahrt fährt plötzlich woandershin. Fahrplanwechsel prüfen.</i>';
        foreach (array_slice($routeChanges, 0, 5) as $c) {
            $lines[] = sprintf(
                '• Linie %s (%s) %s ab %s: %s %s → %s, ab <b>%s</b>%s',
                $e($c['line']),
                $e($c['dayType']),
                $e($c['slotHhmm']),
                $e($c['slotStopName']),
                $c['changedSide'] === 'end' ? 'Ziel' : 'Start',
                $e($c['from']['movedStopName']),
                $e($c['to']['movedStopName']),
                $e($c['changedOn']),
                $c['shortened'] ? ' (Verkürzung)' : ''
            );
        }
        if (count($routeChanges) > 5) {
            $lines[] = '• … und ' . (count($routeChanges) - 5) . ' weitere';
        }
    }

    if ($drift) {
        $lines[] = '';
        $lines[] = '<b>⚠ Fahrplanwechsel in laufender Periode (' . count($drift) . ')</b>';
        $lines[] = '<i>Empfehlung: neue Fahrplanperiode zum genannten Datum anlegen.</i>';
        foreach (array_slice($drift, 0, 5) as $d) {
            $lines[] = sprintf(
                '• Linie %s (%s) ab <b>%s</b>: %s → %s, %d Route-Schlüssel betroffen',
                $e($d['line']),
                $e($d['dayType']),
                $e($d['changedOn']),
                $e($d['from']['endStopName']),
                $e($d['to']['endStopName']),
                $d['affectedKeys']
            );
        }
        if (count($drift) > 5) {
            $lines[] = '• … und ' . (count($drift) - 5) . ' weitere';
        }
    }

    if ($conflicts) {
        $lines[] = '';
        $lines[] = '<b>✖ Unauflösbare Kurskonflikte (' . count($conflicts) . ')</b>';
        $lines[] = '<i>Diese Abfahrten bleiben ohne Kursnummer – Override setzen.</i>';
        foreach (array_slice($conflicts, 0, 5) as $c) {
            $lines[] = sprintf(
                '• Linie %s (%s) %s %s – Kurse %s',
                $e($c['line']),
                $e($c['dayType']),
                $e($c['hhmm']),
                $e($c['stopName']),
                $e(implode('/', $c['courses']))
            );
        }
        if (count($conflicts) > 5) {
            $lines[] = '• … und ' . (count($conflicts) - 5) . ' weitere';
        }
    }

    if ($misses) {
        $hits    = array_sum(array_column($misses, 'hitCount'));
        $lines[] = '';
        $lines[] = '<b>Aussetzer im Betrieb (' . count($misses) . ', ' . $hits . ' Treffer)</b>';
        foreach (array_slice($misses, 0, 3) as $m) {
            $lines[] = sprintf(
                '• %d× Linie %s (%s) %s %s',
                $m['hitCount'],
                $e($m['line']),
                $e($m['dayType']),
                $e($m['hhmm']),
                $e($m['stopName'])
            );
        }
    }

    $lines[] = '';
    $lines[] = 'Details im Admin-Bereich unter „Diagnose".';

    return implode("\n", $lines);
}
