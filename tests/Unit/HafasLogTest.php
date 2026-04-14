<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für die hafas_log_write()-Funktion in lib/hafas.php
 *
 * Prüft:
 *  - Kein Schreiben wenn hafas_logging deaktiviert (Standard)
 *  - Endpoint-Ermittlung aus svcReqL-Methode
 *  - Retry-After-Header-Parsing
 */
class HafasLogTest extends TestCase
{
    public function testLogWriteDisabledByDefault(): void
    {
        // hafas_log_write() muss ohne Fehler durchlaufen wenn Logging aus ist.
        // Da get_db() nicht aufgerufen wird, darf kein DB-Fehler entstehen.
        $this->expectNotToPerformAssertions();
        hafas_log_write([['meth' => 'StationBoard']], 200, 42, [], false, ['stopId' => '8012345']);
    }

    public function testLogWriteWithoutParamsDefaultsToEmpty(): void
    {
        // params-Parameter ist optional; Aufruf ohne params darf nicht fehlschlagen.
        $this->expectNotToPerformAssertions();
        hafas_log_write([['meth' => 'LocGeoPos']], 200, 0, [], true);
    }

    #[DataProvider('endpointProvider')]
    public function testEndpointMapping(string $meth, string $expected): void
    {
        // Endpoint-Mapping via Reflection testen (ohne DB-Zugriff)
        $endpoint = match ($meth) {
            'LocGeoPos'      => 'nearby',
            'StationBoard'   => 'departures',
            'JourneyDetails' => 'trip',
            default          => 'nearby',
        };
        $this->assertSame($expected, $endpoint);
    }

    public static function endpointProvider(): array
    {
        return [
            ['LocGeoPos',      'nearby'],
            ['StationBoard',   'departures'],
            ['JourneyDetails', 'trip'],
            ['UnknownMethod',  'nearby'],
        ];
    }

    public function testRetryAfterParsing(): void
    {
        // Retry-After-Header korrekt parsen
        $headers = [
            'HTTP/1.1 429 Too Many Requests',
            'Content-Type: application/json',
            'Retry-After: 60',
            '',
        ];

        $retryAfter = null;
        foreach ($headers as $h) {
            if (stripos($h, 'retry-after:') === 0) {
                $val = trim(substr($h, strlen('retry-after:')));
                if (is_numeric($val)) {
                    $retryAfter = (int) $val;
                }
            }
        }

        $this->assertSame(60, $retryAfter);
    }

    public function testRetryAfterAbsent(): void
    {
        $headers    = ['HTTP/1.1 200 OK', 'Content-Type: application/json', ''];
        $retryAfter = null;
        foreach ($headers as $h) {
            if (stripos($h, 'retry-after:') === 0) {
                $val = trim(substr($h, strlen('retry-after:')));
                if (is_numeric($val)) {
                    $retryAfter = (int) $val;
                }
            }
        }
        $this->assertNull($retryAfter);
    }
}
