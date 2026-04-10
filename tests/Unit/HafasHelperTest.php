<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für die reinen Hilfsfunktionen in lib/hafas.php.
 * Externe HAFAS-API-Aufrufe werden hier nicht getestet.
 */
class HafasHelperTest extends TestCase
{
    // -----------------------------------------------------------------------
    // hafas_iso – HAFAS-Datum/Zeit → ISO-8601-UTC
    // -----------------------------------------------------------------------

    public static function isoProvider(): array
    {
        return [
            // Winterzeit (UTC+1): 14:32 Berlin = 13:32 UTC
            ['20260324', '143200', '2026-03-24T13:32:00Z'],
            // Sommerzeit (UTC+2): 14:32 Berlin = 12:32 UTC
            ['20260701', '143200', '2026-07-01T12:32:00Z'],
            // Zeit nach Mitternacht: 25:00 = +1 Tag, 01:00 Uhr
            ['20260324', '250000', '2026-03-25T00:00:00Z'],
            // Leere Eingaben → null
            ['', '', null],
            ['20260324', '', null],
        ];
    }

    #[DataProvider('isoProvider')]
    public function test_hafas_iso(string $date, string $time, ?string $expected): void
    {
        $this->assertSame($expected, hafas_iso($date, $time));
    }

    // -----------------------------------------------------------------------
    // hafas_line_name – Präfixbereinigung
    // -----------------------------------------------------------------------

    public static function lineNameProvider(): array
    {
        return [
            ['STR  6', '6'],
            ['STR 10', '10'],
            ['Bus 56', '56'],
            ['Tram 4', '4'],
            ['6', '6'],
            ['N3', 'N3'],        // Nachttram – kein bekannter Präfix
            ['S-Bahn 1', '1'],
        ];
    }

    #[DataProvider('lineNameProvider')]
    public function test_hafas_line_name(string $input, string $expected): void
    {
        $this->assertSame($expected, hafas_line_name($input));
    }

    // -----------------------------------------------------------------------
    // hafas_service_nr – Fahrtennummer-Extraktion
    // -----------------------------------------------------------------------

    public static function serviceNrProvider(): array
    {
        return [
            // Neues Format: ZI+TA aus jid extrahieren
            ['2|#VN#1#ST#…#ZI#125364#TA#46#DA#100426#',  [],          '125364_46'],
            ['2|#VN#1#ST#…#ZI#99#TA#3#DA#100426#',       [],          '99_3'],
            // Altes Format: number aus prodL
            ['1|12345|0|80|24032026', ['number' => '41058'],           '41058'],
            ['1|12345|0|80|24032026', ['num'    => '41058'],           '41058'],
            ['1|12345|0|80|24032026', ['prodCtx' => ['num' => '42']], '42'],
            // Altes Format: führende Nullen entfernen
            ['1|12345|0|80|24032026', ['number' => '00041'],           '41'],
            // Fallback: jid ohne Datumssegment
            ['1|12345|0|80|24032026', [],                              '1|12345|0|80'],
            // Kein erkennbares Format → jid unverändert
            ['singlepart',            [],                              'singlepart'],
        ];
    }

    #[DataProvider('serviceNrProvider')]
    public function test_hafas_service_nr(string $jid, array $prod, string $expected): void
    {
        $this->assertSame($expected, hafas_service_nr($prod, $jid));
    }

    // -----------------------------------------------------------------------
    // haversine_distance – Entfernungsberechnung
    // -----------------------------------------------------------------------

    public function test_haversine_same_point_is_zero(): void
    {
        $this->assertSame(0, haversine_distance(52.1205, 11.6276, 52.1205, 11.6276));
    }

    public function test_haversine_magdeburg_hbf_to_hasselbachplatz(): void
    {
        // Magdeburg Hbf → Hasselbachplatz, Luftlinie ~600 m
        $dist = haversine_distance(52.1300, 11.6265, 52.1207, 11.6277);
        $this->assertGreaterThan(500, $dist);
        $this->assertLessThan(1500, $dist);
    }

    public function test_haversine_returns_int(): void
    {
        $dist = haversine_distance(52.0, 11.0, 52.1, 11.1);
        $this->assertIsInt($dist);
    }
}
