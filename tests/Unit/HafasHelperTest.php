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

    // -----------------------------------------------------------------------
    // hafas_line_from_jid – Linienname aus jid-ZB#-Feld
    // -----------------------------------------------------------------------

    public static function lineFromJidProvider(): array
    {
        return [
            // Neues Format: ZB# vorhanden → Linie korrekt extrahieren
            ['2|#VN#1#ST#…#ZI#141135#TA#10#DA#120426#1S#300730801#1T#1424#LS#300754003#LT#1513#PU#80#RT#1#CA#StN#ZE#13#ZB#Str   13#PC#5#', '13'],
            ['2|#VN#1#ST#…#ZI#120882#TA#10#DA#120426#1S#300384601#1T#1444#LS#300754003#LT#1513#PU#80#RT#1#CA#StN#ZE#2#ZB#Str    2#PC#5#',  '2'],
            ['2|#VN#1#ST#…#ZI#141061#TA#10#DA#120426#1S#300730801#1T#1418#LS#300730902#LT#1513#PU#80#RT#1#CA#StN#ZE#1#ZB#Str    1#PC#5#',  '1'],
            ['2|#VN#1#ST#…#ZI#121157#TA#11#DA#120426#1S#300366601#1T#1430#LS#300734901#LT#1526#PU#80#RT#1#CA#StN#ZE#9#ZB#Str    9#PC#5#',  '9'],
            // Nachtnetz
            ['2|#VN#1#ST#…#ZE#N1#ZB#Str N1#PC#5#', 'N1'],
            // Altes Format ohne ZB#: '' zurückgeben
            ['1|12345|0|80|24032026', ''],
            // Leerer String
            ['', ''],
        ];
    }

    #[DataProvider('lineFromJidProvider')]
    public function test_hafas_line_from_jid(string $jid, string $expected): void
    {
        $this->assertSame($expected, hafas_line_from_jid($jid));
    }
}
