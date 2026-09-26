<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für hafas_parse_trip_stops() in lib/hafas.php.
 *
 * Die Fixture bildet den realen Fall vom 22.09.2026 nach: Linie 10 wird wegen
 * einer Streckensperrung umgeleitet. HAFAS liefert im selben Laufweg die
 * entfallenden Planhalte *und* die Zusatzhalte der Umleitungsstrecke –
 * "Am Nordpark" steht dadurch zweimal in der Liste.
 */
class HafasTripParseTest extends TestCase
{
    /**
     * JourneyDetails-Antwort (res-Sektion) mit allen relevanten Halt-Typen.
     */
    private static function divertedTrip(): array
    {
        return [
            'common' => [
                'locL' => [
                    ['extId' => '300736802', 'name' => 'Magdeburg, S-Bahnhof Neustadt'],
                    ['extId' => '300748002', 'name' => 'Magdeburg, Am Nordpark'],
                    ['extId' => '300735102', 'name' => 'Magdeburg, AOK'],
                    ['extId' => '300748001', 'name' => 'Magdeburg, Am Nordpark'],
                    ['extId' => '300736801', 'name' => 'Magdeburg, S-Bahnhof Neustadt'],
                ],
                'prodL' => [
                    ['name' => 'Str  10'],
                ],
            ],
            'journey' => [
                'date'        => '20260922',
                'isPartCncl'  => true,
                'prodL'       => [['prodX' => 0, 'fIdx' => 0, 'tIdx' => 4]],
                'stopL'       => [
                    // Planhalt, fährt regulär
                    ['locX' => 0, 'dTimeS' => '192900', 'dTimeR' => '193100'],
                    // Zusatzhalt der Umleitung
                    ['locX' => 1, 'dTimeS' => '193000', 'dTimeR' => '193200', 'isAdd' => true],
                    // Entfallender Planhalt
                    ['locX' => 2, 'dTimeS' => '193100', 'dCncl' => true, 'aCncl' => true],
                    // Entfallender Planhalt – dieselbe Haltestelle wie Index 1
                    ['locX' => 3, 'dTimeS' => '193700', 'dCncl' => true, 'aCncl' => true],
                    // Vorzeitiger Endhalt der Umleitung: Ankunft findet statt
                    // (inkl. Echtzeit), aber es gibt keine Weiterfahrt – HAFAS
                    // setzt dafür dCncl, ohne dass der Halt entfiele.
                    ['locX' => 4, 'aTimeS' => '193600', 'aTimeR' => '193800',
                     'isAdd' => true, 'dCncl' => true, 'dInS' => false, 'dInR' => false],
                ],
            ],
        ];
    }

    public function test_parses_all_stops_in_order(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $this->assertCount(5, $stops);
        $this->assertSame([1, 2, 3, 4, 5], array_column($stops, 'sequence'));
        $this->assertSame('Magdeburg, S-Bahnhof Neustadt', $stops[0]['stop']);
    }

    public function test_marks_cancelled_stops(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $this->assertFalse($stops[0]['cancelled']);
        $this->assertTrue($stops[2]['cancelled']);
        $this->assertTrue($stops[3]['cancelled']);
    }

    public function test_marks_additional_stops(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $this->assertFalse($stops[0]['additional']);
        $this->assertTrue($stops[1]['additional']);
        $this->assertFalse($stops[2]['additional']);
    }

    public function test_marks_boarding_restriction(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $this->assertTrue($stops[0]['boarding']);
        // dInS/dInR = false → "Hält nur zum Aussteigen"
        $this->assertFalse($stops[4]['boarding']);
    }

    /**
     * Kern des Problems: Dieselbe Haltestelle taucht als Zusatzhalt und als
     * entfallender Planhalt auf. Nur die Flags unterscheiden die beiden.
     */
    public function test_same_stop_appears_twice_with_different_flags(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $nordpark = array_values(array_filter(
            $stops,
            static fn(array $s): bool => $s['stop'] === 'Magdeburg, Am Nordpark'
        ));

        $this->assertCount(2, $nordpark);
        $this->assertTrue($nordpark[0]['additional']);
        $this->assertFalse($nordpark[0]['cancelled']);
        $this->assertFalse($nordpark[1]['additional']);
        $this->assertTrue($nordpark[1]['cancelled']);
    }

    public function test_undisturbed_stops_default_to_scheduled(): void
    {
        $res = [
            'common'  => [
                'locL'  => [['extId' => '300736802', 'name' => 'Magdeburg, S-Bahnhof Neustadt']],
                'prodL' => [['name' => 'Str  10']],
            ],
            'journey' => [
                'date'  => '20260922',
                'stopL' => [['locX' => 0, 'dTimeS' => '192900']],
            ],
        ];

        $stops = hafas_parse_trip_stops($res);

        $this->assertFalse($stops[0]['cancelled']);
        $this->assertFalse($stops[0]['additional']);
        $this->assertTrue($stops[0]['boarding']);
    }

    public function test_empty_response_yields_empty_list(): void
    {
        $this->assertSame([], hafas_parse_trip_stops([]));
    }

    /**
     * Regression: Der vorzeitige Endhalt einer umgeleiteten Fahrt trug
     * gleichzeitig "entfällt" und "Zusatzhalt". HAFAS setzt dort dCncl, weil
     * keine Weiterfahrt existiert – bedient wird der Halt trotzdem.
     */
    public function test_terminating_stop_with_dcncl_is_not_cancelled(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $this->assertFalse($stops[4]['cancelled']);
        $this->assertTrue($stops[4]['additional']);
        $this->assertFalse($stops[4]['boarding']);
    }

    /**
     * Gegenprobe: Der planmäßige Endhalt einer vorzeitig endenden Fahrt hat
     * nur eine Ankunft – dort genügt aCncl allein.
     */
    public function test_final_stop_with_acncl_only_is_cancelled(): void
    {
        $res = self::divertedTrip();
        $res['journey']['stopL'][] = [
            'locX' => 2, 'aTimeS' => '194200', 'aCncl' => true,
        ];

        $stops = hafas_parse_trip_stops($res);

        $this->assertTrue($stops[5]['cancelled']);
    }

    /**
     * Gegenprobe: Der Starthalt einer Fahrt hat nur eine Abfahrt – dort
     * genügt dCncl allein.
     */
    public function test_first_stop_with_dcncl_only_is_cancelled(): void
    {
        $res = [
            'common'  => [
                'locL'  => [['extId' => '300752803', 'name' => 'Magdeburg, Barleber See']],
                'prodL' => [['name' => 'Str  10']],
            ],
            'journey' => [
                'date'  => '20260922',
                'stopL' => [['locX' => 0, 'dTimeS' => '190700', 'dCncl' => true]],
            ],
        ];

        $this->assertTrue(hafas_parse_trip_stops($res)[0]['cancelled']);
    }

    /**
     * Ein übersprungener Zwischenhalt trägt beide Flags.
     */
    public function test_skipped_intermediate_stop_needs_both_flags(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        // Index 2 und 3: aCncl + dCncl
        $this->assertTrue($stops[2]['cancelled']);
        $this->assertTrue($stops[3]['cancelled']);
    }

    /**
     * arrivalPlanned kommt nur aus aTimeS – ohne Ankunft bleibt es null,
     * auch wenn departurePlanned am Endhalt auf die Ankunft zurückfällt.
     */
    public function test_arrival_planned_only_from_arrival_time(): void
    {
        $stops = hafas_parse_trip_stops(self::divertedTrip());

        $this->assertNull($stops[0]['arrivalPlanned']);
        // 19:36 Berlin (MESZ) = 17:36 UTC
        $this->assertSame('2026-09-22T17:36:00Z', $stops[4]['arrivalPlanned']);
        $this->assertSame($stops[4]['arrivalPlanned'], $stops[4]['departurePlanned']);
    }

    public function test_arrival_planned_uses_own_date(): void
    {
        $res = [
            'common'  => ['locL' => [['extId' => '1', 'name' => 'A']]],
            'journey' => [
                'date'  => '20260922',
                'stopL' => [[
                    'locX'   => 0,
                    'aTimeS' => '235900', 'aDateS' => '20260922',
                    'dTimeS' => '000100', 'dDateS' => '20260923',
                ]],
            ],
        ];

        $stop = hafas_parse_trip_stops($res)[0];
        $this->assertSame('2026-09-22T21:59:00Z', $stop['arrivalPlanned']);
        $this->assertSame('2026-09-22T22:01:00Z', $stop['departurePlanned']);
    }
}
