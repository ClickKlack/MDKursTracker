<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/trip_resolve.php – die Toleranzsuche, die greift, wenn der
 * schedule_fingerprint wegen einer Umleitung nicht exakt trifft.
 *
 * Bezugsfall: Linie 10 Richtung Alte Neustadt am 23.09.2026. Von 27 Halten
 * wich genau einer um eine Minute ab (S-Bahnhof Neustadt, +22 statt +23
 * Minuten nach Fahrtbeginn), weil HAFAS bei der Umleitung die Planzeit am
 * Verzweigungshalt verschiebt.
 */
class TripResolveTest extends TestCase
{
    /** Planzeiten der regulären Fahrt (Ausschnitt, UTC). */
    private static function regular(): array
    {
        return ['04:39', '04:40', '04:57', '04:58', '05:02', '05:03', '05:14'];
    }

    /** Dieselbe Fahrt als Umleitung: ein Halt eine Minute früher. */
    private static function diverted(): array
    {
        return ['04:39', '04:40', '04:57', '04:58', '05:01', '05:03', '05:14'];
    }

    public function test_identical_schedules_match(): void
    {
        $this->assertTrue(schedule_times_match(self::regular(), self::regular()));
    }

    public function test_single_minute_shift_matches(): void
    {
        $this->assertTrue(schedule_times_match(self::regular(), self::diverted()));
    }

    public function test_shift_within_tolerance_matches(): void
    {
        $shifted = self::regular();
        $shifted[4] = '05:04'; // +2 Minuten
        $this->assertTrue(schedule_times_match(self::regular(), $shifted));
    }

    public function test_shift_beyond_tolerance_does_not_match(): void
    {
        $shifted = self::regular();
        $shifted[4] = '05:05'; // +3 Minuten
        $this->assertFalse(schedule_times_match(self::regular(), $shifted));
    }

    /**
     * Die eigentliche Schutzfunktion: Die Nachbarfahrt derselben Linie liegt
     * im Takt zehn Minuten später und darf nie als dieselbe Fahrt gelten.
     */
    public function test_next_journey_in_headway_does_not_match(): void
    {
        $next = array_map(
            static fn(string $t): string => sprintf('%02d:%02d',
                intdiv(((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2) + 10, 60) % 24,
                (((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2) + 10) % 60
            ),
            self::regular()
        );
        $this->assertFalse(schedule_times_match(self::regular(), $next));
    }

    public function test_different_length_does_not_match(): void
    {
        $short = array_slice(self::regular(), 0, 5);
        $this->assertFalse(schedule_times_match(self::regular(), $short));
    }

    public function test_empty_list_does_not_match(): void
    {
        $this->assertFalse(schedule_times_match([], []));
    }

    public function test_malformed_time_does_not_match(): void
    {
        $broken = self::regular();
        $broken[2] = '';
        $this->assertFalse(schedule_times_match(self::regular(), $broken));
    }

    /**
     * Über Mitternacht darf 23:59 → 00:00 nicht als 1439 Minuten gelten.
     */
    public function test_midnight_wrap_counts_as_one_minute(): void
    {
        $this->assertTrue(schedule_times_match(['23:58', '23:59'], ['23:58', '00:00']));
        $this->assertFalse(schedule_times_match(['23:58', '23:55'], ['23:58', '00:00']));
    }

    public function test_tolerance_is_configurable(): void
    {
        $shifted = self::regular();
        $shifted[4] = '05:07'; // +5 Minuten
        $this->assertFalse(schedule_times_match(self::regular(), $shifted));
        $this->assertTrue(schedule_times_match(self::regular(), $shifted, 5));
    }

    // -----------------------------------------------------------------------
    // schedule_times_from_stops
    // -----------------------------------------------------------------------

    public function test_times_from_stops_skips_additional_and_timeless(): void
    {
        $stops = [
            ['stopId' => 'a', 'departurePlanned' => '2026-09-23T04:39:00Z'],
            ['stopId' => 'x', 'departurePlanned' => '2026-09-23T04:44:00Z', 'additional' => true],
            ['stopId' => 'b', 'departurePlanned' => null],
            ['stopId' => 'c', 'departurePlanned' => '2026-09-23T05:14:00Z', 'cancelled' => true],
        ];

        // Zusatzhalt raus, Halt ohne Zeit raus, entfallender Planhalt bleibt
        $this->assertSame(['04:39', '05:14'], schedule_times_from_stops($stops));
    }

    // -----------------------------------------------------------------------
    // dominant_line_from_stops – Linienfilter für GET /api/trip
    // -----------------------------------------------------------------------

    public function test_dominant_line_returns_the_only_line(): void
    {
        $stops = [
            ['stopId' => 'a', 'line' => '10'],
            ['stopId' => 'b', 'line' => '10'],
        ];
        $this->assertSame('10', dominant_line_from_stops($stops));
    }

    public function test_dominant_line_wins_on_through_service(): void
    {
        // Durchgebundene Fahrt: Linienwechsel unterwegs, die längere Strecke gewinnt
        $stops = [
            ['stopId' => 'a', 'line' => '10'],
            ['stopId' => 'b', 'line' => '10'],
            ['stopId' => 'c', 'line' => '10'],
            ['stopId' => 'd', 'line' => '2'],
        ];
        $this->assertSame('10', dominant_line_from_stops($stops));
    }

    public function test_dominant_line_ignores_missing_values(): void
    {
        $stops = [
            ['stopId' => 'a'],
            ['stopId' => 'b', 'line' => null],
            ['stopId' => 'c', 'line' => ''],
            ['stopId' => 'd', 'line' => '6'],
        ];
        $this->assertSame('6', dominant_line_from_stops($stops));
    }

    public function test_dominant_line_null_without_any_line(): void
    {
        $this->assertNull(dominant_line_from_stops([['stopId' => 'a'], ['stopId' => 'b']]));
    }

    /**
     * Dokumentiert, warum der Linienfilter in der Kandidatensuche nötig ist:
     * In Periode 1 fahren Linie 3 und Linie 4 abends denselben Weg zum
     * Betriebshof Nord, zwei Minuten versetzt – mit verschiedenen
     * Kursnummern (08 und 16). Über die Zeiten allein wären sie nicht zu
     * trennen; erst t.line macht sie eindeutig.
     */
    public function test_two_lines_on_same_route_are_within_tolerance(): void
    {
        $line4 = ['18:35', '18:36', '18:38', '18:39', '18:40'];
        $line3 = ['18:37', '18:38', '18:40', '18:41', '18:42'];

        $this->assertTrue(
            schedule_times_match($line4, $line3),
            'Zeiten allein trennen die beiden Linien nicht – daher der Linienfilter in der Query'
        );
    }
}
