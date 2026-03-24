<?php
// HAFAS-curl-Proxy für die INSA/NASA-Reiseauskunft.
// Alle Funktionen geben normalisierte PHP-Arrays zurück.
// HAFAS-Fehler werden als RuntimeException weitergegeben.

// API-Konstanten (aus hafas-client, Phase-0-verifiziert)
const HAFAS_URL    = 'https://reiseauskunft.insa.de/bin/mgate.exe';
const HAFAS_AID    = 'nasa-apps';
const HAFAS_CLIENT = ['type' => 'IPH', 'id' => 'NASA', 'v' => '4000200', 'name' => 'nasaPROD'];
const HAFAS_VER    = '1.44';

// Produkt-Bitmask: 64 = Straßenbahn (Tram)
const HAFAS_TRAM_MASK = 64;

/**
 * Haltestellen in der Nähe eines GPS-Punkts, gefiltert auf Straßenbahn.
 *
 * @return array<array{id: string, name: string, distance: int}>
 */
function hafas_nearby(float $lat, float $lon, int $results = 10): array
{
    // HAFAS erwartet Koordinaten als Ganzzahl (Grad × 1.000.000)
    $x = (int) round($lon * 1_000_000);
    $y = (int) round($lat * 1_000_000);

    $res = hafas_request([
        [
            'meth' => 'LocGeoPos',
            'req'  => [
                'ring'     => ['cCrd' => ['x' => $x, 'y' => $y], 'maxDist' => 1000, 'minDist' => 0],
                'getPOIs'  => false,
                'getStops' => true,
                'maxLoc'   => $results * 3, // mehr anfordern, da wir filtern
            ],
        ],
    ]);

    $stops = $res[0]['res']['common']['locL'] ?? [];

    $result = [];
    foreach ($stops as $stop) {
        // Nur Haltestellen mit Tram-Betrieb (Bit-6 im pCls-Feld)
        if (!(($stop['pCls'] ?? 0) & HAFAS_TRAM_MASK)) {
            continue;
        }

        $stopLat = ($stop['crd']['y'] ?? 0) / 1_000_000;
        $stopLon = ($stop['crd']['x'] ?? 0) / 1_000_000;

        $result[] = [
            'id'       => $stop['extId'],
            'name'     => $stop['name'],
            'distance' => haversine_distance($lat, $lon, $stopLat, $stopLon),
        ];

        if (count($result) >= $results) {
            break;
        }
    }

    // Nach Entfernung sortieren
    usort($result, fn($a, $b) => $a['distance'] <=> $b['distance']);

    return $result;
}

/**
 * Nächste Straßenbahn-Abfahrten an einer Haltestelle.
 *
 * @return array<array{
 *   hafasTripId: string, serviceNr: string, line: string,
 *   direction: string, departurePlanned: string, departureActual: string|null
 * }>
 */
function hafas_departures(string $stopId, int $results = 20): array
{
    $res = hafas_request([
        [
            'meth' => 'StationBoard',
            'req'  => [
                'type'      => 'DEP',
                'stbLoc'    => ['type' => 'S', 'extId' => $stopId],
                'maxJny'    => $results,
                // Nur Tram-Produkte einschließen
                'jnyFltrL'  => [['type' => 'PROD', 'mode' => 'INC', 'value' => (string) HAFAS_TRAM_MASK]],
            ],
        ],
    ]);

    $common   = $res[0]['res']['common'] ?? [];
    $prodList = $common['prodL'] ?? [];
    $journeys = $res[0]['res']['jnyL'] ?? [];

    $result = [];
    foreach ($journeys as $jny) {
        $prod    = $prodList[$jny['prodX']] ?? [];
        $stbStop = $jny['stbStop'] ?? [];

        // Nur Tram-Fahrten (zur Sicherheit nochmals filtern)
        if (!(($prod['cls'] ?? 0) & HAFAS_TRAM_MASK)) {
            continue;
        }

        $plannedDate   = $stbStop['dDateS'] ?? ($jny['date'] ?? '');
        $plannedTime   = $stbStop['dTimeS'] ?? '';
        $realtimeDate  = $stbStop['dDateR'] ?? '';
        $realtimeTime  = $stbStop['dTimeR'] ?? '';

        $result[] = [
            'hafasTripId'      => $jny['jid'],
            'serviceNr'        => ltrim($prod['number'] ?? $prod['num'] ?? '', '0') ?: '0',
            'line'             => hafas_line_name($prod['name'] ?? ''),
            'direction'        => $jny['dirTxt'] ?? '',
            'departurePlanned' => hafas_iso($plannedDate, $plannedTime),
            'departureActual'  => ($realtimeTime !== '')
                ? hafas_iso($realtimeDate ?: $plannedDate, $realtimeTime)
                : null,
        ];
    }

    return $result;
}

/**
 * Vollständiger Laufweg einer Fahrt (alle planmäßigen Halte).
 *
 * @return array<array{sequence: int, stopId: string, stop: string, departurePlanned: string|null}>
 */
function hafas_trip(string $tripId): array
{
    // Datum aus der tripId extrahieren (letztes Segment: DDMMYYYY)
    $parts = explode('|', $tripId);
    $rawDate = end($parts); // z.B. "24032026"
    $date = strlen($rawDate) === 8
        ? substr($rawDate, 4) . substr($rawDate, 2, 2) . substr($rawDate, 0, 2) // → YYYYMMDD
        : date('Ymd');

    $res = hafas_request([
        [
            'meth' => 'JourneyDetails',
            'req'  => [
                'jid'        => $tripId,
                'date'       => $date,
                'getPolyline' => false,
                'getPasslist' => true,
            ],
        ],
    ]);

    $common  = $res[0]['res']['common'] ?? [];
    $locList = $common['locL'] ?? [];
    $stops   = $res[0]['res']['journey']['stopL'] ?? [];

    $result = [];
    foreach ($stops as $i => $stop) {
        $loc = $locList[$stop['locX']] ?? [];

        // Abfahrtszeit bevorzugen; letzter Halt hat keine Abfahrt
        $dDate = $stop['dDateS'] ?? ($stop['aDateS'] ?? '');
        $dTime = $stop['dTimeS'] ?? ($stop['aTimeS'] ?? '');

        $result[] = [
            'sequence'         => $i + 1,
            'stopId'           => $loc['extId'] ?? '',
            'stop'             => $loc['name'] ?? '',
            'departurePlanned' => ($dTime !== '') ? hafas_iso($dDate, $dTime) : null,
        ];
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Interne Hilfsfunktionen
// ---------------------------------------------------------------------------

/**
 * Sendet einen HAFAS-mgate-Request und gibt die normalisierten Ergebnisse zurück.
 *
 * @param  array<mixed> $services  Array von svcReqL-Einträgen
 * @return array<mixed>            Array von svcResL-Einträgen
 * @throws RuntimeException        Bei HTTP- oder HAFAS-Fehler
 */
function hafas_request(array $services): array
{
    $body = json_encode([
        'ver'      => HAFAS_VER,
        'lang'     => 'de',
        'auth'     => ['type' => 'AID', 'aid' => HAFAS_AID],
        'client'   => HAFAS_CLIENT,
        'svcReqL'  => $services,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(HAFAS_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    if ($errno !== 0) {
        get_logger()->error('HAFAS curl-Fehler', ['errno' => $errno, 'error' => $error]);
        throw new RuntimeException("HAFAS nicht erreichbar: $error");
    }

    $data = json_decode($raw, true);

    if (($data['err'] ?? 'OK') !== 'OK') {
        get_logger()->warning('HAFAS API-Fehler', ['err' => $data['err'], 'txt' => $data['errTxt'] ?? '']);
        throw new RuntimeException('HAFAS API-Fehler: ' . ($data['errTxt'] ?? $data['err']));
    }

    // Einzelne Service-Antworten auf Fehler prüfen
    foreach ($data['svcResL'] ?? [] as $svcRes) {
        if (($svcRes['err'] ?? 'OK') !== 'OK') {
            get_logger()->warning('HAFAS Service-Fehler', ['err' => $svcRes['err']]);
            throw new RuntimeException('HAFAS Service-Fehler: ' . $svcRes['err']);
        }
    }

    return $data['svcResL'] ?? [];
}

/**
 * Konvertiert HAFAS-Datum (YYYYMMDD) + Zeit (HHMMSS, ggf. > 235959) in ISO-8601-UTC.
 */
function hafas_iso(string $date, string $time): ?string
{
    if ($date === '' || $time === '') return null;

    $hours      = (int) substr($time, 0, 2);
    $minutes    = (int) substr($time, 2, 2);
    $seconds    = (int) substr($time, 4, 2);
    $extraDays  = intdiv($hours, 24);
    $hours      = $hours % 24;

    $tz = new DateTimeZone('Europe/Berlin');
    $dt = DateTimeImmutable::createFromFormat(
        'Ymd His',
        $date . ' ' . sprintf('%02d%02d%02d', $hours, $minutes, $seconds),
        $tz
    );

    if ($dt === false) return null;

    if ($extraDays > 0) {
        $dt = $dt->modify("+{$extraDays} days");
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Extrahiert die kurze Linienbezeichnung aus dem HAFAS-Produktnamen.
 * Beispiele: "STR  6" → "6", "Bus 56" → "56", "6" → "6"
 */
function hafas_line_name(string $name): string
{
    // Präfixe wie "STR", "Bus", "S-Bahn" entfernen
    $clean = preg_replace('/^(STR|Bus|Tram|S-Bahn|U)\s*/i', '', trim($name));
    return $clean !== '' ? $clean : $name;
}

/**
 * Berechnet die Entfernung zwischen zwei GPS-Punkten in Metern (Haversine).
 */
function haversine_distance(float $lat1, float $lon1, float $lat2, float $lon2): int
{
    $r    = 6_371_000; // Erdradius in Metern
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return (int) round($r * 2 * asin(sqrt($a)));
}
