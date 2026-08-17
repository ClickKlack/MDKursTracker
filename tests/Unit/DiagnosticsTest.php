<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/diagnostics.php und lib/telegram.php.
 *
 * Getestet wird die reine Formatier- und Auswertungslogik. Die SQL-gestützten
 * Detektoren (detect_schedule_drift, detect_course_conflicts) brauchen eine
 * Datenbank und werden hier nicht abgedeckt – sie sind gegen die
 * Produktivdaten verifiziert.
 */
class DiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('format_diagnostics_report')) {
            require_once dirname(__DIR__, 2) . '/lib/diagnostics.php';
        }
    }

    private static function drift(array $overrides = []): array
    {
        return array_merge([
            'line'         => '2',
            'dayType'      => 'SF',
            'changedOn'    => '2026-07-29',
            'affectedKeys' => 24,
            'examples'     => [['routeKey' => 'x', 'stopName' => 'Hbf', 'hhmm' => '15:47',
                                'oldCourse' => '01', 'newCourse' => '05']],
            'from'         => ['endStopId' => '300754003', 'endStopName' => 'Westerhüsen',
                               'stopCount' => 24, 'lastSeen' => '2026-07-15', 'tripIds' => [1628], 'tripCount' => 1],
            'to'           => ['endStopId' => '300741403', 'endStopName' => 'Buckau',
                               'stopCount' => 12, 'firstSeen' => '2026-07-29', 'tripIds' => [1751], 'tripCount' => 1],
        ], $overrides);
    }

    private static function conflict(array $overrides = []): array
    {
        return array_merge([
            'routeKey' => '300733003|2|SF|15:56',
            'stopId'   => '300733003',
            'stopName' => 'Hasselbachplatz',
            'line'     => '2',
            'dayType'  => 'SF',
            'hhmm'     => '15:56',
            'courses'  => ['01', '05'],
            'tripIds'  => [1628, 1751],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // format_diagnostics_report()
    // -------------------------------------------------------------------------

    public function test_report_without_findings_says_so(): void
    {
        $msg = format_diagnostics_report(4, 'Netz 17.8.', [], [], []);
        $this->assertStringContainsString('Keine Befunde', $msg);
        $this->assertStringContainsString('Periode #4', $msg);
        $this->assertStringContainsString('Netz 17.8.', $msg);
    }

    public function test_report_lists_schedule_drift_with_date(): void
    {
        $msg = format_diagnostics_report(3, 'Baunetz', [self::drift()], [], []);
        $this->assertStringContainsString('Fahrplanwechsel', $msg);
        $this->assertStringContainsString('2026-07-29', $msg);
        $this->assertStringContainsString('Westerhüsen', $msg);
        $this->assertStringContainsString('Buckau', $msg);
        $this->assertStringContainsString('24 Route-Schlüssel', $msg);
    }

    public function test_report_lists_course_conflicts(): void
    {
        $msg = format_diagnostics_report(3, 'Baunetz', [], [self::conflict()], []);
        $this->assertStringContainsString('Kurskonflikte', $msg);
        $this->assertStringContainsString('Hasselbachplatz', $msg);
        $this->assertStringContainsString('01/05', $msg);
    }

    public function test_report_lists_runtime_misses_with_hit_sum(): void
    {
        $misses = [
            ['line' => '2', 'dayType' => 'SF', 'hhmm' => '15:56', 'stopName' => 'Hasselbachplatz', 'hitCount' => 7],
            ['line' => '9', 'dayType' => 'MO-FR', 'hhmm' => '08:12', 'stopName' => 'Reform', 'hitCount' => 3],
        ];
        $msg = format_diagnostics_report(3, 'Baunetz', [], [], $misses);
        $this->assertStringContainsString('10 Treffer', $msg);
        $this->assertStringContainsString('7× Linie 2', $msg);
    }

    public function test_report_truncates_long_drift_lists(): void
    {
        $many = array_fill(0, 8, self::drift());
        $msg  = format_diagnostics_report(3, 'Baunetz', $many, [], []);
        $this->assertStringContainsString('und 3 weitere', $msg);
    }

    public function test_report_escapes_html_in_names(): void
    {
        $msg = format_diagnostics_report(3, 'Netz <b>x</b>', [], [], []);
        $this->assertStringContainsString('Netz &lt;b&gt;x&lt;/b&gt;', $msg);
        $this->assertStringNotContainsString('Netz <b>x</b>', $msg);
    }

    // -------------------------------------------------------------------------
    // find_slot_route_changes() – Laufweg-Wechsel an einer konkreten Fahrt.
    // Reine Auswertung über der Trip-Metaliste, ohne Datenbank.
    // -------------------------------------------------------------------------

    private static function trip(array $overrides = []): array
    {
        return array_merge([
            'line'         => '2',
            'dayType'      => 'SF',
            'course'       => '01',
            'startStopId'  => '300739302',
            'startHhmm'    => '15:47',
            'endStopId'    => '300754003',
            'endHhmm'      => '16:19',
            'stopCount'    => 24,
            'firstSeen'    => '2026-07-15',
            'lastSeen'     => '2026-07-15',
            'observedDays' => 1,
        ], $overrides);
    }

    public function test_slot_change_detected_when_destination_moves(): void
    {
        // Die 15:47-Fahrt ab Hbf endet ab dem 30.07. in Buckau statt Westerhüsen.
        $meta = [
            1628 => self::trip(),
            1751 => self::trip([
                'course' => '05', 'endStopId' => '300741403', 'endHhmm' => '16:03',
                'stopCount' => 12, 'firstSeen' => '2026-07-30', 'lastSeen' => '2026-07-30',
            ]),
        ];
        $found = find_slot_route_changes($meta, 'start', 1);
        $this->assertCount(1, $found);
        $this->assertSame('2026-07-30', $found[0]['changedOn']);
        $this->assertSame('end', $found[0]['changedSide']);
        $this->assertSame('300754003', $found[0]['from']['movedStopId']);
        $this->assertSame('300741403', $found[0]['to']['movedStopId']);
    }

    public function test_slot_change_detected_when_start_moves(): void
    {
        // Vorn eingekürzt: Ziel und Ankunftszeit bleiben, der Start wandert.
        $meta = [
            1651 => self::trip([
                'startStopId' => '300754001', 'startHhmm' => '05:30',
                'endStopId' => '300739302', 'endHhmm' => '06:02', 'stopCount' => 25,
            ]),
            1777 => self::trip([
                'course' => '03', 'startStopId' => '300741402', 'startHhmm' => '05:44',
                'endStopId' => '300739302', 'endHhmm' => '06:02', 'stopCount' => 13,
                'firstSeen' => '2026-08-06', 'lastSeen' => '2026-08-06',
            ]),
        ];
        // Über den Fahrtanfang verankert findet man nichts – die Slots sind verschieden.
        $this->assertSame([], find_slot_route_changes($meta, 'start', 1));
        // Über das Fahrtende verankert schon.
        $found = find_slot_route_changes($meta, 'end', 1);
        $this->assertCount(1, $found);
        $this->assertSame('start', $found[0]['changedSide']);
        $this->assertSame('2026-08-06', $found[0]['changedOn']);
    }

    public function test_no_finding_when_variants_overlap_in_time(): void
    {
        // Der Linie-10-Fall in seiner schärfsten Form: zwei Varianten im selben
        // Slot, die sich zeitlich überlappen → planmäßige Alternation.
        $meta = [
            1 => self::trip(['firstSeen' => '2026-07-01', 'lastSeen' => '2026-08-10']),
            2 => self::trip([
                'course' => '05', 'endStopId' => '300741403', 'stopCount' => 12,
                'firstSeen' => '2026-07-20', 'lastSeen' => '2026-08-12',
            ]),
        ];
        $this->assertSame([], find_slot_route_changes($meta, 'start', 1));
    }

    public function test_no_finding_for_different_slots(): void
    {
        // Abwechselnd weiterfahrende Kurse haben eigene Soll-Zeiten.
        $meta = [
            1 => self::trip(['startHhmm' => '15:47']),
            2 => self::trip([
                'startHhmm' => '16:02', 'endStopId' => '300741403', 'stopCount' => 12,
                'firstSeen' => '2026-07-30', 'lastSeen' => '2026-07-30',
            ]),
        ];
        $this->assertSame([], find_slot_route_changes($meta, 'start', 1));
    }

    public function test_no_finding_for_single_variant(): void
    {
        $meta = [1 => self::trip(), 2 => self::trip(['firstSeen' => '2026-08-01', 'lastSeen' => '2026-08-01'])];
        $this->assertSame([], find_slot_route_changes($meta, 'start', 1));
    }

    public function test_observed_days_threshold_suppresses_thin_evidence(): void
    {
        $meta = [
            1628 => self::trip(['observedDays' => 1]),
            1751 => self::trip([
                'course' => '05', 'endStopId' => '300741403', 'stopCount' => 12,
                'firstSeen' => '2026-07-30', 'lastSeen' => '2026-07-30',
            ]),
        ];
        $this->assertCount(1, find_slot_route_changes($meta, 'start', 1));
        $this->assertSame([], find_slot_route_changes($meta, 'start', 2));
    }

    public function test_slot_change_keeps_course_numbers_even_when_identical(): void
    {
        // Kernfall: gleiche Kursnummer, nur anderes Ziel. Der Kurskonflikt-
        // Detektor kann das nicht sehen – dieser hier schon.
        $meta = [
            1 => self::trip(['course' => '03']),
            2 => self::trip([
                'course' => '03', 'endStopId' => '300741403', 'stopCount' => 12,
                'firstSeen' => '2026-07-22', 'lastSeen' => '2026-07-22',
            ]),
        ];
        $found = find_slot_route_changes($meta, 'start', 1);
        $this->assertCount(1, $found);
        $this->assertSame(['03'], $found[0]['from']['courses']);
        $this->assertSame(['03'], $found[0]['to']['courses']);
    }

    // -------------------------------------------------------------------------
    // Report-Abschnitt für Laufweg-Änderungen
    // -------------------------------------------------------------------------

    public function test_report_lists_route_changes(): void
    {
        $changes = [[
            'line' => '2', 'dayType' => 'SF', 'slotHhmm' => '15:47',
            'slotStopName' => 'Hauptbahnhof', 'changedSide' => 'end',
            'changedOn' => '2026-07-30', 'shortened' => true,
            'from' => ['movedStopName' => 'Westerhüsen'],
            'to'   => ['movedStopName' => 'Buckau'],
        ]];
        $msg = format_diagnostics_report(3, 'Baunetz', [], [], [], $changes);
        $this->assertStringContainsString('Laufweg einzelner Fahrten geändert', $msg);
        $this->assertStringContainsString('15:47', $msg);
        $this->assertStringContainsString('Westerhüsen', $msg);
        $this->assertStringContainsString('(Verkürzung)', $msg);
    }

    public function test_report_without_findings_ignores_empty_route_changes(): void
    {
        $this->assertStringContainsString(
            'Keine Befunde',
            format_diagnostics_report(4, 'Netz', [], [], [], [])
        );
    }

    // -------------------------------------------------------------------------
    // telegram_escape()
    // -------------------------------------------------------------------------

    public function test_telegram_escape_handles_html_specials(): void
    {
        $this->assertSame('a &amp; b', telegram_escape('a & b'));
        $this->assertSame('&lt;i&gt;', telegram_escape('<i>'));
        // Reihenfolge: & muss zuerst ersetzt werden, sonst doppelt maskiert
        $this->assertSame('&amp;lt;', telegram_escape('&lt;'));
    }

    public function test_telegram_escape_leaves_plain_text_alone(): void
    {
        $this->assertSame('Hasselbachplatz (Tram/Bus)', telegram_escape('Hasselbachplatz (Tram/Bus)'));
    }
}
