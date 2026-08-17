<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/course_lookup.php – pick_course_for_departure() und
 * narrow_course_candidates().
 *
 * Die Funktionen sind die reine Auswahl-Logik: aus drei vorberechneten Maps
 * wird die Kursnummer mit der höchsten Priorität ausgewählt; die dritte Map
 * hält Kandidatenlisten, die stufenweise eingeengt werden.
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

    /** Ein Kandidat im byRouteStop-Eintrag. */
    private static function cand(array $overrides = []): array
    {
        return array_merge([
            'course'       => '07',
            'manual'       => false,
            'tripId'       => 1,
            'direction'    => 'Buckau',
            'journeyStart' => '13:47',
            'journeyEnd'   => '14:03',
        ], $overrides);
    }

    public function test_byJourney_wins_over_other_maps(): void
    {
        $maps = [
            'byJourney'   => ['TRIP123' => ['number' => '07', 'source' => 'recorded']],
            'byServiceNr' => ['41058|6|MO-FR' => ['number' => '99', 'source' => 'recorded']],
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [self::cand(['course' => '88'])]],
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
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [self::cand(['course' => '88'])]],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame(['number' => '12', 'source' => 'recorded'], $result);
    }

    public function test_falls_back_to_byRouteStop_with_heuristic_source(): void
    {
        $maps = [
            'byJourney'   => [],
            'byServiceNr' => [],
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [self::cand()]],
        ];
        $result = pick_course_for_departure($maps, self::spec());
        $this->assertSame(['number' => '07', 'source' => 'heuristic'], $result);
    }

    public function test_byRouteStop_can_carry_manual_source(): void
    {
        // Heuristisch gefundener Trip mit Override: Source 'manual' überschreibt
        // 'heuristic' – agree_course_from_trips() wertet das manual-Flag aus.
        $maps = [
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [self::cand(['manual' => true])]],
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
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [self::cand()]],
        ];
        $result = pick_course_for_departure($maps, self::spec(['hhmm' => null]));
        $this->assertSame(['number' => null, 'source' => null], $result);
    }

    public function test_byRouteStop_skipped_when_stopId_missing(): void
    {
        $maps = [
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [self::cand()]],
        ];
        $result = pick_course_for_departure($maps, self::spec(['stopId' => '']));
        $this->assertSame(['number' => null, 'source' => null], $result);
    }

    public function test_byRouteStop_conflict_resolved_by_direction_via_pick(): void
    {
        // Der reale Fall: Hasselbachplatz 17:56, Linie 2, Schulferien – die alte
        // Westerhüsen-Fahrt (Kurs 01) und die eingekürzte Buckau-Fahrt (Kurs 05)
        // teilen sich den Route-Schlüssel. Die Richtung entscheidet.
        $maps = [
            'byRouteStop' => ['de:15003:4000|6|MO-FR|14:32' => [
                self::cand(['course' => '01', 'tripId' => 1628, 'direction' => 'Westerhüsen',
                            'journeyStart' => '13:47', 'journeyEnd' => '14:19']),
                self::cand(['course' => '05', 'tripId' => 1751, 'direction' => 'Buckau',
                            'journeyStart' => '13:47', 'journeyEnd' => '14:03']),
            ]],
        ];
        $result = pick_course_for_departure($maps, self::spec(['direction' => 'Buckau']));
        $this->assertSame(['number' => '05', 'source' => 'heuristic'], $result);
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

    // -------------------------------------------------------------------------
    // narrow_course_candidates() – stufenweises Einengen bei Uneindeutigkeit.
    // Stufe 1 = alle Kandidaten, Stufe 2 = Richtung, Stufe 3 = Laufwegzeiten.
    // -------------------------------------------------------------------------

    /** Alte Westerhüsen-Fahrt (Kurs 01) vs. eingekürzte Buckau-Fahrt (Kurs 05). */
    private static function conflictingPair(): array
    {
        return [
            self::cand(['course' => '01', 'tripId' => 1628, 'direction' => 'Westerhüsen',
                        'journeyStart' => '13:47', 'journeyEnd' => '14:19']),
            self::cand(['course' => '05', 'tripId' => 1751, 'direction' => 'Buckau',
                        'journeyStart' => '13:47', 'journeyEnd' => '14:03']),
        ];
    }

    public function test_narrow_returns_null_for_empty_list(): void
    {
        $this->assertNull(narrow_course_candidates([], ['direction' => 'Buckau']));
    }

    public function test_narrow_stage1_unchanged_when_already_unique(): void
    {
        // Eindeutige Liste – die Stichentscheide dürfen nichts verändern.
        $result = narrow_course_candidates([self::cand()], []);
        $this->assertSame(['number' => '07', 'source' => 'heuristic', 'tripId' => 1], $result);
    }

    public function test_narrow_stage1_wins_even_if_direction_mismatches(): void
    {
        // Kernversprechen der Umsetzung: Was Stufe 1 eindeutig löst, bleibt
        // erhalten – auch wenn der Richtungstext nicht passt. Damit kann keine
        // Abfahrt eine bisher angezeigte Kursnummer verlieren.
        $result = narrow_course_candidates(
            [self::cand(['direction' => 'Westerhüsen'])],
            ['direction' => 'Buckau']
        );
        $this->assertSame('07', $result['number']);
    }

    public function test_narrow_resolves_conflict_by_direction(): void
    {
        $result = narrow_course_candidates(self::conflictingPair(), ['direction' => 'Buckau']);
        $this->assertSame(['number' => '05', 'source' => 'heuristic', 'tripId' => 1751], $result);
    }

    public function test_narrow_resolves_conflict_by_journey_times(): void
    {
        // Beide Fahrten enden am selben Ort – nur der Laufweg unterscheidet sie
        // (Trips 1651/1777: Start 07:30 vs. 07:44, beide Richtung Hauptbahnhof).
        $candidates = [
            self::cand(['course' => '05', 'tripId' => 1651, 'direction' => 'Hauptbahnhof',
                        'journeyStart' => '05:30', 'journeyEnd' => '06:02']),
            self::cand(['course' => '03', 'tripId' => 1777, 'direction' => 'Hauptbahnhof',
                        'journeyStart' => '05:44', 'journeyEnd' => '06:02']),
        ];
        $result = narrow_course_candidates($candidates, [
            'direction'    => 'Hauptbahnhof',
            'journeyStart' => '05:44',
            'journeyEnd'   => '06:02',
        ]);
        $this->assertSame(['number' => '03', 'source' => 'heuristic', 'tripId' => 1777], $result);
    }

    public function test_narrow_journey_stage_works_when_direction_text_drifted(): void
    {
        // Stufe 3 filtert aus der Vollliste, nicht aus Stufe 2. Driftet der
        // HAFAS-Richtungstext, bleiben die Laufwegzeiten wirksam.
        $result = narrow_course_candidates(self::conflictingPair(), [
            'direction'    => 'Buckau (Wasserwerk)', // passt zu keinem Kandidaten
            'journeyStart' => '13:47',
            'journeyEnd'   => '14:03',
        ]);
        $this->assertSame(['number' => '05', 'source' => 'heuristic', 'tripId' => 1751], $result);
    }

    public function test_narrow_returns_null_when_no_stage_resolves(): void
    {
        // Weder Richtung noch Zeiten gegeben → bleibt uneindeutig wie bisher.
        $this->assertNull(narrow_course_candidates(self::conflictingPair(), []));
    }

    public function test_narrow_returns_null_when_spec_matches_nothing(): void
    {
        $result = narrow_course_candidates(self::conflictingPair(), [
            'direction'    => 'Reform',
            'journeyStart' => '09:00',
            'journeyEnd'   => '09:30',
        ]);
        $this->assertNull($result);
    }

    public function test_narrow_keeps_manual_source_after_narrowing(): void
    {
        $candidates = [
            self::cand(['course' => '01', 'tripId' => 1628, 'direction' => 'Westerhüsen']),
            self::cand(['course' => '05', 'tripId' => 1751, 'direction' => 'Buckau', 'manual' => true]),
        ];
        $result = narrow_course_candidates($candidates, ['direction' => 'Buckau']);
        $this->assertSame('manual', $result['source']);
    }

    public function test_narrow_direction_stage_still_ambiguous(): void
    {
        // Zwei echte Widersprüche in derselben Richtung und mit denselben
        // Laufwegzeiten – hier bleibt es korrekterweise bei null.
        $candidates = [
            self::cand(['course' => '01', 'tripId' => 10]),
            self::cand(['course' => '02', 'tripId' => 11]),
        ];
        $result = narrow_course_candidates($candidates, [
            'direction'    => 'Buckau',
            'journeyStart' => '13:47',
            'journeyEnd'   => '14:03',
        ]);
        $this->assertNull($result);
    }

    public function test_narrow_ignores_candidates_without_course(): void
    {
        $candidates = [
            self::cand(['course' => null, 'tripId' => 10, 'direction' => 'Westerhüsen']),
            self::cand(['course' => '05', 'tripId' => 11, 'direction' => 'Buckau']),
        ];
        // Bereits Stufe 1 ist eindeutig, weil unbekannte Kurse nicht blockieren.
        $result = narrow_course_candidates($candidates, []);
        $this->assertSame(['number' => '05', 'source' => 'heuristic', 'tripId' => 11], $result);
    }
}
