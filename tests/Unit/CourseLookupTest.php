<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/course_lookup.php – pick_course_for_departure().
 *
 * Die Funktion ist die reine Auswahl-Logik: aus drei vorberechneten Maps
 * wird die Kursnummer mit der höchsten Priorität ausgewählt.
 * SQL-basierte Map-Builder werden hier nicht getestet (kein DB-Zugriff im
 * Unit-Test); die Auswahl-Reihenfolge ist die kritische Logik.
 */
class CourseLookupTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pick_course_for_departure')) {
            require_once dirname(__DIR__, 2) . '/lib/course_lookup.php';
        }
    }

    private static function spec(array $overrides = []): array
    {
        return array_merge([
            'hafasTripId' => 'TRIP123',
            'serviceNr'   => '41058',
            'line'        => '6',
            'dayType'     => 'MO-FR',
            'stopId'      => 'de:15003:4000',
            'hhmm'        => '14:32',
        ], $overrides);
    }

    public function test_byJourney_wins_over_other_maps(): void
    {
        $maps = [
            'byJourney'   => ['TRIP123' => ['number' => '07', 'source' => 'recorded']],
            'byServiceNr' => ['41058|6|MO-FR' => ['number' => '99', 'source' => 'recorded']],
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => ['number' => '88', 'source' => 'heuristic']],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame(['number' => '07', 'source' => 'recorded'], $result);
    }

    public function test_byJourney_keeps_manual_source(): void
    {
        $maps = [
            'byJourney' => ['TRIP123' => ['number' => '07', 'source' => 'manual']],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame('manual', $result['source']);
    }

    public function test_falls_back_to_byServiceNr(): void
    {
        $maps = [
            'byJourney'   => [],
            'byServiceNr' => ['41058|6|MO-FR' => ['number' => '12', 'source' => 'recorded']],
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => ['number' => '88', 'source' => 'heuristic']],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame(['number' => '12', 'source' => 'recorded'], $result);
    }

    public function test_falls_back_to_byRouteStop_with_heuristic_source(): void
    {
        $maps = [
            'byJourney'   => [],
            'byServiceNr' => [],
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => ['number' => '07', 'source' => 'heuristic']],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame(['number' => '07', 'source' => 'heuristic'], $result);
    }

    public function test_byRouteStop_can_carry_manual_source(): void
    {
        // Heuristisch gefundener Trip mit Override: Source 'manual' überschreibt
        // 'heuristic' – wird vom Map-Builder so eingetragen.
        $maps = [
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => ['number' => '07', 'source' => 'manual']],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame('manual', $result['source']);
    }

    public function test_returns_nulls_when_no_map_matches(): void
    {
        $result = pick_course_for_departure([], self::spec());
        $this->assertSame(['number' => null, 'source' => null], $result);
    }

    public function test_byRouteStop_skipped_when_hhmm_missing(): void
    {
        $maps = [
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => ['number' => '07', 'source' => 'heuristic']],
        ];
        $result = pick_course_for_departure($maps, self::spec(['hhmm' => null]));
        $this->assertSame(['number' => null, 'source' => null], $result);
    }

    public function test_byRouteStop_skipped_when_stopId_missing(): void
    {
        $maps = [
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => ['number' => '07', 'source' => 'heuristic']],
        ];
        $result = pick_course_for_departure($maps, self::spec(['stopId' => '']));
        $this->assertSame(['number' => null, 'source' => null], $result);
    }

    public function test_serviceNr_key_assembly(): void
    {
        // Stellt sicher, dass die Reihenfolge serviceNr|line|dayType eingehalten wird
        $maps = [
            'byServiceNr' => ['ABC|9|SO' => ['number' => '42', 'source' => 'recorded']],
        ];
        $spec = self::spec(['serviceNr' => 'ABC', 'line' => '9', 'dayType' => 'SO']);
        $this->assertSame('42', pick_course_for_departure($maps, $spec)['number']);
    }

    // -------------------------------------------------------------------------
    // agree_course_from_trips() – Einigung mehrerer Trips am selben Schlüssel.
    // Deckt das "graue Problem" ab: Duplikat-Trips derselben Fahrt (instabile
    // Steig-IDs) sollen ihre gemeinsame Kursnummer trotzdem liefern.
    // -------------------------------------------------------------------------

    public function test_agree_returns_null_for_no_trips(): void
    {
        $this->assertNull(agree_course_from_trips([]));
    }

    public function test_agree_single_trip_recorded(): void
    {
        $result = agree_course_from_trips([
            ['course' => '02', 'manual' => false],
        ]);
        $this->assertSame(['number' => '02', 'source' => 'heuristic'], $result);
    }

    public function test_agree_single_trip_manual_keeps_manual_source(): void
    {
        $result = agree_course_from_trips([
            ['course' => '02', 'manual' => true],
        ]);
        $this->assertSame(['number' => '02', 'source' => 'manual'], $result);
    }

    public function test_agree_two_duplicates_with_same_course(): void
    {
        // Kernfall: zwei Duplikat-Trips, beide Kurs 02 → 02 statt "??".
        $result = agree_course_from_trips([
            ['course' => '02', 'manual' => false],
            ['course' => '02', 'manual' => false],
        ]);
        $this->assertSame(['number' => '02', 'source' => 'heuristic'], $result);
    }

    public function test_agree_manual_override_wins_source_when_values_match(): void
    {
        $result = agree_course_from_trips([
            ['course' => '07', 'manual' => false],
            ['course' => '07', 'manual' => true],
        ]);
        $this->assertSame(['number' => '07', 'source' => 'manual'], $result);
    }

    public function test_agree_returns_null_on_conflict(): void
    {
        $result = agree_course_from_trips([
            ['course' => '02', 'manual' => false],
            ['course' => '05', 'manual' => false],
        ]);
        $this->assertNull($result);
    }

    public function test_agree_ignores_trips_without_course(): void
    {
        // Nur eine Variante des Duplikats ist erfasst – die andere blockiert nicht.
        $result = agree_course_from_trips([
            ['course' => null, 'manual' => false],
            ['course' => '02', 'manual' => false],
        ]);
        $this->assertSame(['number' => '02', 'source' => 'heuristic'], $result);
    }

    public function test_agree_returns_null_when_all_courses_unknown(): void
    {
        $result = agree_course_from_trips([
            ['course' => null, 'manual' => false],
            ['course' => null, 'manual' => false],
        ]);
        $this->assertNull($result);
    }
}
