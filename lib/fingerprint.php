<?php
// Berechnet stabile Fingerprints für HAFAS-Fahrten aus dem Laufweg-Array.
// Eingabe: Array aus hafas_trip() – jedes Element: [stopId, departurePlanned (ISO-8601 UTC), ...]
//
// path_fingerprint:     SHA-256 über geordnete Stop-IDs → beschreibt den Linienverlauf
// schedule_fingerprint: SHA-256 über Stop-ID+HH:MM-Paare → beschreibt die konkrete Fahrtinstanz
//
// Stops ohne departurePlanned werden in schedule_fingerprint übersprungen.
// Letzter Halt: HAFAS liefert dort die Ankunftszeit als departurePlanned (aTimeS-Fallback).
//
// Zusatzhalte (additional) fließen NICHT ein – siehe scheduled_stops_only().

/**
 * Berechnet path_fingerprint und schedule_fingerprint aus einem Laufweg-Array.
 *
 * @param array $stops  Ausgabe von hafas_trip(): [{stopId, departurePlanned, ...}, ...]
 * @return array        ['path' => string, 'schedule' => string|null]
 *                      schedule ist null, wenn weniger als 2 Halte Zeitangaben haben.
 */
function compute_fingerprints(array $stops): array
{
    return [
        'path'     => compute_path_fingerprint($stops),
        'schedule' => compute_schedule_fingerprint($stops),
    ];
}

/**
 * Reduziert einen Laufweg auf die fahrplanmäßigen Halte, d. h. entfernt
 * Zusatzhalte (HAFAS `isAdd`).
 *
 * Hintergrund: Bei einer Umleitung – etwa der Sperrung eines Streckenastes –
 * liefert HAFAS denselben Laufweg mit zusätzlichen Halten der Umleitungs-
 * strecke, während die entfallenden Planhalte als `cancelled` bestehen
 * bleiben und ihre Planzeiten behalten. Hashte der Fingerprint auch die
 * Zusatzhalte, bekäme die Fahrt an jedem Störungstag eine neue Identität:
 * `POST /api/recordings` legte einen zweiten Trip-Datensatz an, die Erfassung
 * hinge an einer Fahrt, die es nur an diesem einen Tag gab, und die
 * Heuristik in lib/course_lookup.php bekäme Route-Schlüssel für Halte, die
 * die Linie planmäßig gar nicht bedient.
 *
 * Ohne die Zusatzhalte ergibt der Umleitungslauf wieder exakt den Fingerprint
 * des Regelbetriebs – die Erfassung landet an der richtigen Fahrt.
 *
 * An störungsfreien Tagen enthält der Laufweg keine Zusatzhalte; die
 * Fingerprints bleiben damit unverändert zu allen bereits gespeicherten.
 *
 * Defensiv: Bestünde ein Laufweg ausschließlich aus Zusatzhalten (bisher nie
 * beobachtet), bliebe er unverändert – ein leerer Fingerprint wäre schlechter
 * als ein abweichender.
 *
 * @param array $stops  Ausgabe von hafas_trip()
 * @return array        Laufweg ohne Zusatzhalte
 */
function scheduled_stops_only(array $stops): array
{
    $filtered = array_values(array_filter(
        $stops,
        static fn(array $s): bool => empty($s['additional'])
    ));

    return $filtered !== [] ? $filtered : $stops;
}

/**
 * Normalisiert eine HAFAS-Stop-ID auf Haltestellen-Ebene, indem die letzten
 * zwei Ziffern (der Bahnsteig/Steig) entfernt werden.
 *
 * Hintergrund: HAFAS liefert für dieselbe reale Fahrt am selben Halt mal den
 * einen, mal den anderen Steig (z. B. Olvenstedter Platz 300738901 vs.
 * 300738903). Hashte der Fingerprint die rohen Stop-IDs, entstand pro Variante
 * ein eigener Trip-Datensatz ("graues Problem"). Die Haltestellen-ID ist über
 * die Steige hinweg stabil.
 *
 * Konvention identisch zum Abfahrts-Filter in lib/hafas.php (substr(extId,0,-2));
 * sie gilt netzweit für die 9-stelligen INSA-Stop-IDs. Nur rein numerische IDs
 * mit ausreichender Länge werden gekürzt – unerwartete Formate bleiben unverändert.
 */
function normalize_stop_id(string $stopId): string
{
    if (ctype_digit($stopId) && strlen($stopId) > 2) {
        return substr($stopId, 0, -2);
    }
    return $stopId;
}

/**
 * Hash über die geordnete Folge der (auf Haltestellen-Ebene normalisierten)
 * Stop-IDs. Identifiziert den Linienverlauf unabhängig von Abfahrtszeiten.
 */
function compute_path_fingerprint(array $stops): string
{
    $stops = scheduled_stops_only($stops);
    $ids   = array_map(fn($s) => normalize_stop_id((string) $s['stopId']), $stops);
    return hash('sha256', implode('|', $ids));
}

/**
 * Hash über normalisierte Stop-ID + UTC-HH:MM-Paare aller Halte mit Zeitangabe.
 * Identifiziert eine konkrete Fahrtinstanz (Plan-Fahrplan-Muster).
 * Gibt null zurück, wenn weniger als 2 Halte Zeitangaben haben.
 */
function compute_schedule_fingerprint(array $stops): ?string
{
    $parts = [];
    foreach (scheduled_stops_only($stops) as $s) {
        $time = extract_utc_hhmm($s['departurePlanned'] ?? null);
        if ($time === null) {
            continue;
        }
        $parts[] = normalize_stop_id((string) $s['stopId']) . '_' . $time;
    }

    if (count($parts) < 2) {
        return null;
    }

    return hash('sha256', implode('|', $parts));
}

/**
 * Extrahiert HH:MM aus einem ISO-8601-UTC-String (YYYY-MM-DDTHH:MM:SSZ).
 * Gibt null zurück bei ungültigem oder fehlendem Wert.
 */
function extract_utc_hhmm(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    // Format: YYYY-MM-DDTHH:MM:SSZ  →  Position 11..15
    if (preg_match('/T(\d{2}:\d{2}):\d{2}Z$/', $iso, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Bestimmt das Betriebsdatum (YYYY-MM-DD, Europe/Berlin) eines Trips aus
 * dessen Laufweg.
 *
 * Konvention: Der Betriebstag richtet sich nach dem Start der Fahrt – eine
 * Sonntag-Nachtfahrt 23:45 → Mo 01:37 gehört vollständig zum Sonntag-
 * Betriebstag, auch wenn Halte nach Mitternacht kalendarisch im Montag liegen.
 *
 * Genommen wird der erste Halt mit Plan-Zeit (departurePlanned). Das ist
 * robust gegen fehlende Zeiten am ersten Halt (selten, aber möglich).
 *
 * @param array $stops Ausgabe von hafas_trip(): [{stopId, departurePlanned, ...}, ...]
 * @return string|null YYYY-MM-DD oder null wenn kein Halt eine Plan-Zeit hat
 */
function derive_service_date(array $stops): ?string
{
    // Zusatzhalte überspringen: Startet eine umgeleitete Fahrt auf der
    // Umleitungsstrecke, darf ihr Betriebstag trotzdem vom Planbeginn kommen.
    foreach (scheduled_stops_only($stops) as $s) {
        $iso = $s['departurePlanned'] ?? null;
        if ($iso === null || $iso === '') {
            continue;
        }
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone('Europe/Berlin'))
                ->format('Y-m-d');
        } catch (Exception) {
            continue;
        }
    }
    return null;
}
