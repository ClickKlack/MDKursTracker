<?php
// HAFAS-curl-Proxy für die INSA/NASA-Reiseauskunft.
// Alle Funktionen geben normalisierte PHP-Arrays zurück.
// HAFAS-Fehler werden als RuntimeException weitergegeben.

require_once __DIR__ . '/hafas_cache.php';

// INSA HAFAS: Straßenbahn-Bitmask – gilt für cls (Produktliste) und pCls (Haltestellen)
const HAFAS_TRAM_MASK = 32;

/**
 * Gibt die HAFAS-Konfiguration aus config.php zurück (gecacht pro Request).
 */
function hafas_config(): array
{
    static $config = null;
    if ($config === null) {
        $file   = getenv('APP_ENV') === 'test'
            ? dirname(__DIR__) . '/config.test.php'
            : dirname(__DIR__) . '/config.php';
        $config = require $file;
    }
    return $config;
}

/**
 * Haltestellen in der Nähe eines GPS-Punkts, gefiltert auf Straßenbahn.
 *
 * @return array<array{id: string, name: string, distance: int}>
 */
function hafas_nearby(float $lat, float $lon, int $results = 10): array
{
    // Cache-Schlüssel: Koordinaten auf 3 Dezimalstellen gerundet (≈ 111 m Raster).
    // Innerhalb dieses Rasters sind die nächsten Tramhaltestellen identisch.
    $cacheKey = hafas_cache_key('nearby', round($lat, 3), round($lon, 3), $results);
    $cached   = hafas_cache_get($cacheKey);
    $logParams = ['lat' => round($lat, 3), 'lon' => round($lon, 3), 'results' => $results];
    if ($cached !== null) {
        hafas_log_write([['meth' => 'LocGeoPos']], 200, 0, [], true, $logParams);
        return $cached;
    }

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
    ], $logParams);

    $stops = $res[0]['res']['common']['locL'] ?? [];

    $result = [];
    foreach ($stops as $stop) {
        // Nur Haltestellen mit Tram-Betrieb: Bit 5 (32) im pCls-Feld
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

    hafas_cache_set($cacheKey, $result, HAFAS_CACHE_TTL_NEARBY);

    return $result;
}

/**
 * Haltestellen per Name suchen, gefiltert auf Straßenbahn.
 * Nutzt HAFAS LocMatch (Freitextsuche).
 *
 * @param  string $name    Suchbegriff (ggf. bereits mit Stadt-Präfix)
 * @param  int    $results Maximale Trefferanzahl
 * @return array<array{id: string, name: string}>
 */
function hafas_find_stops(string $name, int $results = 10): array
{
    $cacheKey  = hafas_cache_key('stopfinder', $name, $results);
    $cached    = hafas_cache_get($cacheKey);
    $logParams = ['name' => $name, 'results' => $results];
    if ($cached !== null) {
        hafas_log_write([['meth' => 'LocMatch']], 200, 0, [], true, $logParams);
        return $cached;
    }

    $res = hafas_request([
        [
            'meth' => 'LocMatch',
            'req'  => [
                'input' => [
                    'field' => 'S',
                    'loc'   => [
                        'name' => $name,
                        'type' => 'S',
                    ],
                    'maxLoc' => $results * 3, // mehr anfordern, da wir auf Trams filtern
                ],
            ],
        ],
    ], $logParams);

    $stops  = $res[0]['res']['match']['locL'] ?? [];
    $result = hafas_parse_stop_matches($stops, $results);

    hafas_cache_set($cacheKey, $result, HAFAS_CACHE_TTL_STOPFINDER);

    return $result;
}

/**
 * Filtert und mappt eine rohe HAFAS-locL-Liste auf [{id, name}].
 * Nur Einträge mit gesetztem Tram-Bit (HAFAS_TRAM_MASK) in pCls werden übernommen.
 *
 * @internal Ausgelagert für Unit-Tests ohne echten HAFAS-API-Call.
 * @param  array<array{extId: string, name: string, pCls?: int}> $locL
 * @return array<array{id: string, name: string}>
 */
function hafas_parse_stop_matches(array $locL, int $results): array
{
    $result = [];
    foreach ($locL as $stop) {
        // Nur Haltestellen mit Tram-Betrieb: Bit 5 (32) im pCls-Feld
        if (!(($stop['pCls'] ?? 0) & HAFAS_TRAM_MASK)) {
            continue;
        }
        $result[] = [
            'id'   => $stop['extId'],
            'name' => $stop['name'],
        ];
        if (count($result) >= $results) {
            break;
        }
    }
    return $result;
}

/**
 * Nächste Straßenbahn-Abfahrten an einer Haltestelle.
 *
 * @param  int $maxMinutes Zeitfenster in Minuten (Standard 59); 0 = kein Limit
 * @return array<array{
 *   hafasTripId: string, serviceNr: string, line: string,
 *   direction: string, departurePlanned: string, departureActual: string|null
 * }>
 */
function hafas_departures(string $stopId, int $results = 20, int $maxMinutes = 59): array
{
    // Rückblick-Fenster aus Config (Abfahrten die bereits vom System als abgefahren gelten,
    // aber noch sichtbar an der Haltestelle sein können)
    $cfg          = hafas_config();
    $lookback     = max(0, (int) ($cfg['departures_lookback_minutes'] ?? 5));

    // Kurzer Cache (30 s): Echtzeit-Verspätungen sollen zügig aktualisiert werden,
    // aber identische Anfragen im selben Intervall treffen HAFAS nur einmal.
    $cacheKey = hafas_cache_key('departures', $stopId, $results, $maxMinutes, $lookback);
    $cached   = hafas_cache_get($cacheKey);
    $logParams = ['stopId' => $stopId, 'results' => $results, 'maxMinutes' => $maxMinutes];
    if ($cached !== null) {
        hafas_log_write([['meth' => 'StationBoard']], 200, 0, [], true, $logParams);
        return $cached;
    }

    // Startzeit = jetzt minus Rückblick-Fenster (Berliner Zeit)
    $now   = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $start = $lookback > 0 ? $now->modify("-{$lookback} minutes") : $now;

    // Mehr Fahrten anfordern als benötigt, da wir in PHP auf Trams filtern.
    // dur = Gesamtfenster in Minuten ab Startzeit (Rückblick + Vorausschau).
    $req = [
        'type'   => 'DEP',
        'date'   => $start->format('Ymd'),
        'time'   => $start->format('His'),
        'stbLoc' => ['type' => 'S', 'extId' => $stopId],
        'maxJny' => $results * 5,
    ];
    if ($maxMinutes > 0) {
        $req['dur'] = $maxMinutes + $lookback;
    }

    $res = hafas_request([['meth' => 'StationBoard', 'req' => $req]], $logParams);

    $common   = $res[0]['res']['common'] ?? [];
    $prodList = $common['prodL'] ?? [];
    $journeys = $res[0]['res']['jnyL'] ?? [];

    // Lookup-Map extId → Name für Starth.-Auflösung (locL ist oft klein, daher vertretbar)
    $locByExtId = [];
    foreach ($common['locL'] as $l) {
        if (isset($l['extId'], $l['name'])) {
            $locByExtId[$l['extId']] = $l['name'];
        }
    }

    // Duplikat-Filter mit Längen-Präferenz:
    // Schlüssel: "line|dirTxt|dTimeS|locX" → ['endTime' => int, 'entry' => array]
    // Bei Kurspaaren (gleiche Linie, Richtung, Zeit, Halt) gewinnt die Fahrt
    // mit der späteren Endzeit (LT# aus der jid) – also immer die Langversion.
    $best = [];

    foreach ($journeys as $jny) {
        $prod    = $prodList[$jny['prodX']] ?? [];
        $stbStop = $jny['stbStop'] ?? [];
        $loc     = $common['locL'][$stbStop['locX']] ?? [];
        $foundId = $loc['extId'] ?? '';

        // Filter-Logik: Prüfen, ob die Kurz-ID in der langen extId vorkommt.
        // Annahme, die letzten beiden Ziffern sind der Bahnsteig, der Rest ist die Haltestelle – z.B. "8000003" für "800000301" und "800000302".
        if (!str_ends_with(substr($foundId, 0, -2), $stopId)) {
            continue;
        }

        // Nur Straßenbahnen: cls-Bitmask (32 = Tram in INSA HAFAS)
        if (!(($prod['cls'] ?? 0) & HAFAS_TRAM_MASK)) {
            continue;
        }

        // Linienname aus prodX – dieser beschreibt das Produkt ab diesem Halt.
        // Bei durchgebundenen Fahrten (Linienübergang mid-route) weicht ZB# in der jid
        // davon ab (zeigt die Linie beim Startpunkt der Fahrt) → prodX ist maßgeblich.
        $lineName = hafas_line_name($prod['name'] ?? '');

        // Ursprüngliche Linienbezeichnung aus ZB# (Linie beim Startpunkt der Gesamtfahrt).
        // Weicht bei Linienübergängen von $lineName ab → als Hinweis für die UI.
        $jidLine      = hafas_line_from_jid($jny['jid']);
        $originalLine = ($jidLine !== '' && $jidLine !== $lineName) ? $jidLine : null;

        $plannedDate  = $stbStop['dDateS'] ?? ($jny['date'] ?? '');
        $plannedTime  = $stbStop['dTimeS'] ?? '';
        $realtimeDate = $stbStop['dDateR'] ?? '';
        $realtimeTime = $stbStop['dTimeR'] ?? '';
        $dirTxt       = $jny['dirTxt'] ?? '';
        $jnyDate      = $jny['date'] ?? '';

        // Start- und Endzeit früh extrahieren – werden für Deduplizierungs-Vergleich benötigt
        preg_match('/#1S#([^#]+)#1T#(\d{4,6})#/', $jny['jid'], $startM);
        $startLocName  = isset($startM[1]) ? ($locByExtId[$startM[1]] ?? null) : null;
        $startTimeRaw  = isset($startM[2]) ? hafas_jid_time_pad($startM[2]) : '';
        preg_match('/#LT#(\d{4,6})#/', $jny['jid'], $endM);
        $endTimeRaw = isset($endM[1]) ? hafas_jid_time_pad($endM[1]) : '';

        // ISO-Zeitpunkte einmalig berechnen – werden für Dedup, Duration und Output benutzt.
        $plannedIso     = hafas_iso($plannedDate, $plannedTime);
        $startIso       = ($startTimeRaw !== '' && $jnyDate !== '') ? hafas_iso($jnyDate, $startTimeRaw) : null;
        $endIso         = ($endTimeRaw   !== '' && $jnyDate !== '') ? hafas_iso($jnyDate, $endTimeRaw)   : null;

        // Gesamtdauer als Präferenzmetrik: Langversion hat frühere Startzeit oder spätere
        // Endzeit – oder beides. ISO-Differenz (Sekunden) ist Tageswechsel-sicher,
        // die alte Integer-Subtraktion auf rohen jid-Strings hätte bei mitternachts­
        // überschreitenden Fahrten ein negatives Ergebnis geliefert.
        $duration = ($startIso !== null && $endIso !== null)
            ? (strtotime($endIso) - strtotime($startIso))
            : 0;

        // Typ-B-Deduplizierung: bei gleicher Linie, Richtung, Soll-Zeit und Halt
        // die Fahrt mit der längsten Gesamtdauer bevorzugen. Schlüssel auf ISO-Zeit
        // (statt rohem dTimeS), damit '001800' und '01001800' (gleiche Wallclock,
        // verschiedenes HAFAS-Encoding) als Dublette erkannt werden.
        $dedupeKey = $lineName . '|' . $dirTxt . '|' . ($plannedIso ?? '') . '|' . ($stbStop['locX'] ?? '');
        if (isset($best[$dedupeKey]) && $duration <= $best[$dedupeKey]['duration']) {
            continue;
        }

        // Zielhalt.: Name aus prodL[0].tLocX (zeigt zuverlässig auf Endhalt in locL), Zeit aus LT#
        $jnyProdEntry = $jny['prodL'][0] ?? [];
        $endLoc       = $common['locL'][$jnyProdEntry['tLocX'] ?? -1] ?? [];
        $endLocName   = ($endLoc['name'] ?? '') !== '' ? $endLoc['name'] : null;

        // Ausfall: gesamte Fahrt (isCncl) oder dieser Halt (dCncl) ist ausgefallen
        $cancelled = !empty($jny['isCncl']) || !empty($stbStop['dCncl']);

        $best[$dedupeKey] = [
            'duration' => $duration,
            'entry'    => [
                'hafasTripId'      => $jny['jid'],
                'serviceNr'        => hafas_service_nr($prod, $jny['jid']),
                'line'             => $lineName,
                'originalLine'     => $originalLine,
                'direction'        => $dirTxt,
                'cancelled'        => $cancelled,
                // Lang-ID des Bahnsteigs an dem diese Abfahrt erfolgt
                // (extId aus locL). Wird vom Frontend an die Capture-View und
                // die Heuristik-Lookups in /api/departures + /api/trips/touch
                // weitergereicht – route_stops.stop_id ist ebenfalls Lang-ID.
                'stopId'           => $foundId,
                'departurePlanned' => $plannedIso,
                'departureActual'  => ($realtimeTime !== '')
                    ? hafas_iso($realtimeDate ?: $plannedDate, $realtimeTime)
                    : null,
                'journeyStart'     => $startLocName,
                'journeyStartTime' => $startIso,
                'journeyEnd'       => $endLocName,
                'journeyEndTime'   => $endIso,
            ],
        ];
    }

    $result = array_column(array_values($best), 'entry');
    $result = hafas_sort_departures($result); // nach Live-Zeit sortieren (Fallback Soll-Zeit)

    hafas_cache_set($cacheKey, $result, HAFAS_CACHE_TTL_DEPARTURES);

    return $result;
}

/**
 * Vollständiger Laufweg einer Fahrt (alle planmäßigen Halte).
 *
 * @return array<array{sequence: int, stopId: string, stop: string, departurePlanned: string|null}>
 */
function hafas_trip(string $tripId): array
{
    // Laufweg ist fahrplanstabil → 1 Tag cachen.
    // Der tripId enthält das Datum, daher ist der Schlüssel tagesgebunden.
    $cacheKey = hafas_cache_key('trip', $tripId);
    $cached   = hafas_cache_get($cacheKey);
    $logParams = ['tripId' => $tripId];
    if ($cached !== null) {
        hafas_log_write([['meth' => 'JourneyDetails']], 200, 0, [], true, $logParams);
        return $cached;
    }

    // Altes Format "1|...|DDMMYYYY": Datum aus letztem Segment extrahieren.
    // Neues Format "2|#VN#...": Datum steckt in der ID selbst – kein date-Parameter nötig.
    $req = [
        'jid'         => $tripId,
        'getPolyline' => false,
        'getPasslist' => true,
    ];

    if (!str_starts_with($tripId, '2|')) {
        $parts   = explode('|', $tripId);
        $rawDate = end($parts); // z.B. "24032026"
        if (strlen($rawDate) === 8) {
            $req['date'] = substr($rawDate, 4)
                         . substr($rawDate, 2, 2)
                         . substr($rawDate, 0, 2); // → YYYYMMDD
        }
    }

    $res = hafas_request([['meth' => 'JourneyDetails', 'req' => $req]], $logParams);

    $common  = $res[0]['res']['common'] ?? [];
    $locList = $common['locL'] ?? [];
    $journey = $res[0]['res']['journey'] ?? [];

    // Basisdatum der Fahrt als Fallback – neuere HAFAS-Versionen lassen dDateS
    // auf Stop-Ebene weg, wenn es mit dem Fahrtdatum übereinstimmt.
    $jnyDate  = $journey['date'] ?? date('Ymd');
    $stops    = $journey['stopL'] ?? [];
    $prodList = $common['prodL'] ?? [];

    // Produkt-Segmente des Laufwegs für Linienübergang-Erkennung.
    // HAFAS JourneyDetails enthält in journey.prodL ggf. mehrere Einträge mit fIdx/tIdx,
    // die angeben, welches Produkt (Linie) für welchen Halt-Index gilt.
    // Falls nicht vorhanden, bleibt $lineForIdx() immer null → keine Änderung im Verhalten.
    $jnyProdSegs = $journey['prodL'] ?? [];

    $lineForIdx = static function (int $stopIdx, array $stopData) use ($jnyProdSegs, $prodList): ?string {
        // Direktes dProdX am Halt bevorzugen (nicht immer vorhanden)
        $prodX = $stopData['dProdX'] ?? null;
        if ($prodX === null) {
            // Fallback: Produkt-Segment über fIdx/tIdx ermitteln
            foreach ($jnyProdSegs as $seg) {
                if ($stopIdx >= ($seg['fIdx'] ?? 0) && $stopIdx <= ($seg['tIdx'] ?? PHP_INT_MAX)) {
                    $prodX = $seg['prodX'] ?? null;
                    break;
                }
            }
        }
        if ($prodX === null) {
            return null;
        }
        $prod = $prodList[$prodX] ?? null;
        return $prod !== null ? hafas_line_name($prod['name'] ?? '') : null;
    };

    $result = [];
    foreach ($stops as $i => $stop) {
        $loc = $locList[$stop['locX']] ?? [];

        // Planzeit bevorzugen; letzter Halt hat nur Ankunft.
        // Datum und Zeit werden immer als Paar aus derselben Quelle geholt.
        if (isset($stop['dTimeS'])) {
            $dDate = $stop['dDateS'] ?? $jnyDate;
            $dTime = $stop['dTimeS'];
        } elseif (isset($stop['aTimeS'])) {
            $dDate = $stop['aDateS'] ?? $jnyDate;
            $dTime = $stop['aTimeS'];
        } else {
            $dDate = $jnyDate;
            $dTime = '';
        }

        // Echtzeit-Abfahrtszeit (dTimeR) bzw. Echtzeit-Ankunft (aTimeR) am letzten Halt
        if (isset($stop['dTimeR'])) {
            $rDate = $stop['dDateR'] ?? $jnyDate;
            $rTime = $stop['dTimeR'];
        } elseif (isset($stop['aTimeR'])) {
            $rDate = $stop['aDateR'] ?? $jnyDate;
            $rTime = $stop['aTimeR'];
        } else {
            $rDate = $jnyDate;
            $rTime = '';
        }

        $result[] = [
            'sequence'         => $i + 1,
            'stopId'           => $loc['extId'] ?? '',
            'stop'             => $loc['name'] ?? '',
            'departurePlanned' => ($dTime !== '') ? hafas_iso($dDate, $dTime) : null,
            'departureActual'  => ($rTime !== '') ? hafas_iso($rDate, $rTime) : null,
            'line'             => $lineForIdx($i, $stop),
        ];
    }

    hafas_cache_set($cacheKey, $result, HAFAS_CACHE_TTL_TRIP);

    return $result;
}

// ---------------------------------------------------------------------------
// Interne Hilfsfunktionen
// ---------------------------------------------------------------------------

/**
 * Sendet einen HAFAS-mgate-Request und gibt die normalisierten Ergebnisse zurück.
 *
 * @param  array<mixed> $services  Array von svcReqL-Einträgen
 * @param  array        $params    Fachliche Parameter für das Log (lat/lon, stopId, tripId)
 * @return array<mixed>            Array von svcResL-Einträgen
 * @throws RuntimeException        Bei HTTP- oder HAFAS-Fehler
 */
function hafas_request(array $services, array $params = []): array
{
    $cfg  = hafas_config();
    $body = json_encode([
        'ver'     => $cfg['hafas_ver'],
        'lang'    => 'de',
        'auth'    => ['type' => 'AID', 'aid' => $cfg['hafas_aid']],
        'client'  => $cfg['hafas_client'],
        'svcReqL' => $services,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($cfg['hafas_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERAGENT      => 'MDKursTracker/1.0 (https://codeberg.org/ClickKlack/JSKursTracker)',
    ]);

    $responseHeaders = [];
    if ($cfg['hafas_logging'] ?? false) {
        // Response-Header für Retry-After sammeln
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $responseHeaders[] = rtrim($header);
            return strlen($header);
        });
    }

    $tStart      = hrtime(true);
    $raw         = curl_exec($ch);
    $durationMs  = (int) round((hrtime(true) - $tStart) / 1_000_000);
    $httpStatus  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno       = curl_errno($ch);
    $error       = curl_error($ch);

    if ($errno !== 0) {
        get_logger()->error('HAFAS curl-Fehler', ['errno' => $errno, 'error' => $error]);
        hafas_log_write($services, 0, $durationMs, [], false, $params);
        throw new RuntimeException("HAFAS nicht erreichbar: $error");
    }

    $data = json_decode($raw, true);

    if (($data['err'] ?? 'OK') !== 'OK') {
        get_logger()->warning('HAFAS API-Fehler', ['err' => $data['err'], 'txt' => $data['errTxt'] ?? '']);
        hafas_log_write($services, $httpStatus, $durationMs, $responseHeaders, false, $params);
        throw new RuntimeException('HAFAS API-Fehler: ' . ($data['errTxt'] ?? $data['err']));
    }

    // Einzelne Service-Antworten auf Fehler prüfen
    foreach ($data['svcResL'] ?? [] as $svcRes) {
        if (($svcRes['err'] ?? 'OK') !== 'OK') {
            get_logger()->warning('HAFAS Service-Fehler', ['err' => $svcRes['err']]);
            hafas_log_write($services, $httpStatus, $durationMs, $responseHeaders, false, $params);
            throw new RuntimeException('HAFAS Service-Fehler: ' . $svcRes['err']);
        }
    }

    hafas_log_write($services, $httpStatus, $durationMs, $responseHeaders, false, $params);

    return $data['svcResL'] ?? [];
}

/**
 * Schreibt einen HAFAS-Log-Eintrag in die DB (nur wenn hafas_logging aktiv).
 * Lazy-Cleanup: mit 2 % Wahrscheinlichkeit werden Einträge älter als 7 Tage gelöscht.
 *
 * @param array  $services        svcReqL (zum Endpoint-Typ ermitteln)
 * @param int    $httpStatus      HTTP-Statuscode (0 = curl-Fehler)
 * @param int    $durationMs      Antwortzeit in Millisekunden
 * @param array  $responseHeaders Response-Header (für Retry-After)
 * @param bool   $cacheHit        true wenn Ergebnis aus Cache kam (wird von Callee gesetzt)
 * @param array  $params          Fachliche Anfrageparameter (lat/lon, stopId, tripId)
 */
function hafas_log_write(
    array $services,
    int   $httpStatus,
    int   $durationMs,
    array $responseHeaders,
    bool  $cacheHit,
    array $params = []
): void {
    $cfg = hafas_config();
    if (!($cfg['hafas_logging'] ?? false)) {
        return;
    }

    // Endpoint-Typ aus der ersten Service-Methode ableiten
    $meth     = $services[0]['meth'] ?? '';
    $endpoint = match ($meth) {
        'LocGeoPos'      => 'nearby',
        'LocMatch'       => 'stopfinder',
        'StationBoard'   => 'departures',
        'JourneyDetails' => 'trip',
        default          => 'nearby',
    };

    // Retry-After-Header auslesen (falls Rate-Limit signalisiert)
    $retryAfter = null;
    foreach ($responseHeaders as $h) {
        if (stripos($h, 'retry-after:') === 0) {
            $val = trim(substr($h, strlen('retry-after:')));
            if (is_numeric($val)) {
                $retryAfter = (int) $val;
            }
        }
    }

    try {
        $pdo = get_db();

        $paramsJson = !empty($params) ? json_encode($params, JSON_UNESCAPED_UNICODE) : null;

        $pdo->prepare(
            'INSERT INTO ' . tbl('hafas_log') .
            ' (logged_at, endpoint, http_status, duration_ms, cache_hit, retry_after, params)
              VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)'
        )->execute([$endpoint, $httpStatus, min($durationMs, 32767), $cacheHit ? 1 : 0, $retryAfter, $paramsJson]);

        // Lazy-Cleanup mit 2 % Wahrscheinlichkeit
        if (mt_rand(1, 100) <= 2) {
            $pdo->exec(
                'DELETE FROM ' . tbl('hafas_log') .
                " WHERE logged_at < UTC_TIMESTAMP() - INTERVAL 7 DAY"
            );
        }
    } catch (Throwable $e) {
        // Logging-Fehler dürfen nie den normalen Betrieb stören
        get_logger()->warning('hafas_log_write fehlgeschlagen', ['exception' => $e->getMessage()]);
    }
}

/**
 * Normalisiert eine aus der HAFAS-jid extrahierte Zeitangabe (1T#, LT#) auf
 * ein von hafas_iso() konsumierbares Format. HAFAS verwendet im jid keine
 * Sekunden, lässt führende Nullen weg und kodiert Halte am Folgetag mit einem
 * vorangestellten Tages-Counter:
 *   1–4 Stellen → "HHMM" (z. B. "15" = 0:15, "137" = 1:37, "2345" = 23:45)
 *                 → links auf 4 padden, "00" als Sekunden anhängen → 6 Stellen
 *   ≥5 Stellen  → "[Tagesoffset][HHMM]" (z. B. "10037" = +1 Tag, 0:37;
 *                 "12345" = +1 Tag, 23:45) → Präfix erhalten, HHMM links
 *                 padden, "00" anhängen → ≥7 Stellen, hafas_iso erkennt das
 *                 als NNHHMMSS-Form.
 *
 * Belegt durch capture-hafas.php: Trip mit jid LT#10037 endet tatsächlich um
 * +1 Tag 00:37 lokal, nicht um 1:00:37 — die alte HMMSS-Annahme war falsch.
 */
function hafas_jid_time_pad(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    if (strlen($raw) <= 4) {
        return str_pad($raw, 4, '0', STR_PAD_LEFT) . '00';
    }
    $prefix = substr($raw, 0, -4);
    $hhmm   = substr($raw, -4);
    return $prefix . $hhmm . '00';
}

/**
 * Konvertiert HAFAS-Datum (YYYYMMDD) + Zeit in ISO-8601-UTC.
 *
 * HAFAS verwendet zwei Zeit-Formate:
 *   - 6-stellig HHMMSS (Standardfall, kein Tageswechsel)
 *   - >6-stellig NNHHMMSS, mit NN = Tagesoffset für Halte nach Mitternacht
 *     (z.B. '01000300' = +1 Tag, 00:03:00). dDateS bleibt dabei meist leer.
 * Zusätzlich kann HHMMSS auch Stunden ≥ 24 enthalten (alte Form für
 *   Mitternachts-Übergang); beide Formen werden zu $extraDays addiert.
 */
function hafas_iso(string $date, string $time): ?string
{
    if ($date === '' || $time === '') return null;

    // Tagesoffset-Präfix bei mitternachts­überschreitenden Halten extrahieren
    $extraDays = 0;
    if (strlen($time) > 6) {
        $extraDays = (int) substr($time, 0, -6);
        $time      = substr($time, -6);
    }

    $hours      = (int) substr($time, 0, 2);
    $minutes    = (int) substr($time, 2, 2);
    $seconds    = (int) substr($time, 4, 2);
    $extraDays += intdiv($hours, 24);
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
 * Ermittelt die service_nr (Fahrtennummer) für einen HAFAS-Eintrag.
 *
 * INSA HAFAS liefert im StationBoard manchmal kein journey-spezifisches
 * `number`-Feld (oder denselben Wert für alle Fahrten einer Linie).
 * Fallback-Kaskade:
 *   1. $prod['number'] / $prod['num']  – fahrtNr aus Produktliste
 *   2. $prod['prodCtx']['num']         – alternative HAFAS-Variante
 *   3. Stabiler Teil der jid (ohne Datums-Segment) – eindeutig pro Fahrt,
 *      konsistent für denselben Umlauf an verschiedenen Tagen
 *
 * @param array  $prod  Produkt-Eintrag aus prodL
 * @param string $jid   Journey-ID (z.B. "1|12345|0|80|24032026")
 */
function hafas_service_nr(array $prod, string $jid): string
{
    // Neues HAFAS-Format (2|#VN#...):
    // Die Fahrtennummer steckt nicht im prodL-Feld 'number' (das enthält nur
    // die Linienbezeichnung, z.B. "6"), sondern in der jid selbst:
    //   ZI#125364  = Umlaufnummer (welches Fahrzeug / welcher Kurs)
    //   TA#46      = Fahrtabschnitt (n-te Fahrt dieses Umlaufs im Fahrplan)
    // Kombination ZI+TA identifiziert eine wiederkehrende Fahrt eindeutig.
    if (str_starts_with($jid, '2|')
        && preg_match('/#ZI#(\d+)#TA#(\d+)#/', $jid, $m)) {
        return $m[1] . '_' . $m[2]; // z.B. "125364_46"
    }

    // Altes HAFAS-Format (1|...): fahrtNr aus Produktliste
    $nr = ltrim(
        (string) ($prod['number'] ?? $prod['num'] ?? $prod['prodCtx']['num'] ?? ''),
        '0'
    );
    if ($nr !== '') {
        return $nr;
    }

    // Letzter Fallback: stabiler Teil der jid ohne tagesgebundenes Datumssegment
    $parts = explode('|', $jid);
    if (count($parts) >= 2) {
        array_pop($parts);
        return implode('|', $parts);
    }

    return $jid;
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
 * Extrahiert die Linienbezeichnung aus dem ZB#-Feld einer HAFAS-jid (neues Format 2|#VN#...).
 * Gibt '' zurück wenn das Feld fehlt (z.B. altes jid-Format 1|...).
 *
 * Hinweis: Bei durchgebundenen Fahrten (Linienübergang mid-route) zeigt ZB# die Linie
 * beim Startpunkt der Gesamtfahrt, nicht die ab dem abgefragten Halt aktive Linie.
 * Für die Abfahrtstafel ist prodX zuverlässiger; diese Funktion eignet sich für Kontexte,
 * in denen die ursprüngliche Fahrtbezeichnung benötigt wird.
 * Beispiel: "...#ZB#Str   13#..." → "13"
 */
function hafas_line_from_jid(string $jid): string
{
    if (preg_match('/#ZB#([^#]+)#/', $jid, $m)) {
        return hafas_line_name($m[1]);
    }
    return '';
}

/**
 * Wählt aus einer Liste normalisierter Abfahrts-Einträge die bevorzugte Version
 * pro Deduplizierungsschlüssel (gleiche Linie, Richtung, Soll-Zeit, Haltestelle).
 * Bevorzugt wird immer die Fahrt mit der längsten Gesamtdauer (LT# − 1T#).
 * Damit gewinnt die Langversion in beiden Richtungen:
 *   gleiche Endzeit   → frühere Startzeit  → größere Dauer
 *   gleiche Startzeit → spätere Endzeit    → größere Dauer
 *
 * @param  array<array{line: string, direction: string, departurePlanned: string,
 *                     _dedupeLocX: string, _duration: int}> $entries
 * @return array Gefilterte Liste, eine Fahrt pro Schlüssel (Langversion bevorzugt)
 * @internal Ausgelagert für Unit-Tests ohne HAFAS-HTTP-Aufruf.
 */
function hafas_deduplicate_departures(array $entries): array
{
    $best = [];
    foreach ($entries as $entry) {
        $key = ($entry['line'] ?? '')
             . '|' . ($entry['direction'] ?? '')
             . '|' . ($entry['departurePlanned'] ?? '')
             . '|' . ($entry['_dedupeLocX'] ?? '');
        $d = $entry['_duration'] ?? 0;
        if (!isset($best[$key]) || $d > $best[$key][0]) {
            $best[$key] = [$d, $entry];
        }
    }
    return array_column(array_values($best), 1);
}

/**
 * Sortiert Abfahrts-Einträge aufsteigend nach der effektiven Abfahrtszeit:
 * Echtzeit (`departureActual`), falls vorhanden, sonst Soll-Zeit
 * (`departurePlanned`). So spiegelt die Reihenfolge die real erwartete
 * Abfahrt wider – eine verspätete Fahrt rutscht hinter eine planmäßig
 * spätere, aber pünktliche Fahrt.
 *
 * Verglichen werden die ISO-8601-UTC-Strings direkt (Format
 * "YYYY-MM-DDTHH:MM:SSZ" ist lexikografisch == chronologisch). Einträge ganz
 * ohne Zeit landen am Ende. usort ist seit PHP 8.0 stabil, daher bleibt bei
 * gleicher effektiver Zeit die bisherige Reihenfolge erhalten.
 *
 * @param  array<array{departurePlanned: ?string, departureActual: ?string}> $entries
 * @return array Nach effektiver Abfahrtszeit aufsteigend sortierte Liste
 * @internal Ausgelagert für Unit-Tests ohne HAFAS-HTTP-Aufruf.
 */
function hafas_sort_departures(array $entries): array
{
    // Einträge ohne jede Zeit ans Ende stellen (\xFF sortiert nach jedem ISO-String)
    $effective = static fn(array $e): string =>
        $e['departureActual'] ?? $e['departurePlanned'] ?? "\xFF";

    usort($entries, static fn(array $a, array $b): int =>
        strcmp($effective($a), $effective($b)));

    return $entries;
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
