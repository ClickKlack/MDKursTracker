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
}
