<?php
// Berechnet stabile Fingerprints für HAFAS-Fahrten aus dem Laufweg-Array.
// Eingabe: Array aus hafas_trip() – jedes Element: [stopId, departurePlanned (ISO-8601 UTC), ...]
//
// path_fingerprint:     SHA-256 über geordnete Stop-IDs → beschreibt den Linienverlauf
// schedule_fingerprint: SHA-256 über Stop-ID+HH:MM-Paare → beschreibt die konkrete Fahrtinstanz
//
// Stops ohne departurePlanned werden in schedule_fingerprint übersprungen.
// Letzter Halt: HAFAS liefert dort die Ankunftszeit als departurePlanned (aTimeS-Fallback).

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
 * Hash über die geordnete Folge der Stop-IDs.
 * Identifiziert den Linienverlauf unabhängig von Abfahrtszeiten.
 */
function compute_path_fingerprint(array $stops): string
{
    $ids = array_map(fn($s) => (string) $s['stopId'], $stops);
    return hash('sha256', implode('|', $ids));
}

/**
 * Hash über Stop-ID + UTC-HH:MM-Paare aller Halte mit Zeitangabe.
 * Identifiziert eine konkrete Fahrtinstanz (Plan-Fahrplan-Muster).
 * Gibt null zurück, wenn weniger als 2 Halte Zeitangaben haben.
 */
function compute_schedule_fingerprint(array $stops): ?string
{
    $parts = [];
    foreach ($stops as $s) {
        $time = extract_utc_hhmm($s['departurePlanned'] ?? null);
        if ($time === null) {
            continue;
        }
        $parts[] = $s['stopId'] . '_' . $time;
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
    foreach ($stops as $s) {
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
