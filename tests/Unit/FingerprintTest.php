<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für lib/fingerprint.php.
 */
class FingerprintTest extends TestCase
{
    // Minimal-Laufweg: 3 Halte mit Zeiten
    private static function stopsBasic(): array
    {
        return [
            ['stopId' => 'de:15003:1', 'departurePlanned' => '2026-04-22T12:00:00Z'],
            ['stopId' => 'de:15003:2', 'departurePlanned' => '2026-04-22T12:05:00Z'],
            ['stopId' => 'de:15003:3', 'departurePlanned' => '2026-04-22T12:10:00Z'],
        ];
    }

    // -----------------------------------------------------------------------
    // compute_path_fingerprint
    // -----------------------------------------------------------------------

    public function test_path_fingerprint_is_sha256_of_stop_ids(): void
    {
        $stops = self::stopsBasic();
        $expected = hash('sha256', 'de:15003:1|de:15003:2|de:15003:3');
        $this->assertSame($expected, compute_path_fingerprint($stops));
    }

    public function test_path_fingerprint_ignores_times(): void
    {
        $a = self::stopsBasic();
        $b = self::stopsBasic();
        $b[0]['departurePlanned'] = '2026-04-22T99:00:00Z'; // Zeit abweichend
        $this->assertSame(compute_path_fingerprint($a), compute_path_fingerprint($b));
    }

    public function test_path_fingerprint_changes_on_different_stops(): void
    {
        $stops = self::stopsBasic();
        $other = $stops;
        $other[1]['stopId'] = 'de:15003:99';
        $this->assertNotSame(compute_path_fingerprint($stops), compute_path_fingerprint($other));
    }

    // -----------------------------------------------------------------------
    // compute_schedule_fingerprint
    // -----------------------------------------------------------------------

    public function test_schedule_fingerprint_is_sha256_of_stop_id_time_pairs(): void
    {
        $stops = self::stopsBasic();
        $expected = hash('sha256', 'de:15003:1_12:00|de:15003:2_12:05|de:15003:3_12:10');
        $this->assertSame($expected, compute_schedule_fingerprint($stops));
    }

    public function test_schedule_fingerprint_changes_on_time_change(): void
    {
        $a = self::stopsBasic();
        $b = self::stopsBasic();
        $b[0]['departurePlanned'] = '2026-04-22T12:01:00Z';
        $this->assertNotSame(compute_schedule_fingerprint($a), compute_schedule_fingerprint($b));
    }

    public function test_schedule_fingerprint_same_across_service_dates(): void
    {
        // Selbes Fahrplanmuster, anderes Betriebsdatum → gleicher Fingerprint
        $a = self::stopsBasic();
        $b = array_map(fn($s) => array_merge($s, [
            'departurePlanned' => str_replace('2026-04-22', '2026-04-23', $s['departurePlanned']),
        ]), self::stopsBasic());
        $this->assertSame(compute_schedule_fingerprint($a), compute_schedule_fingerprint($b));
    }

    public function test_schedule_fingerprint_null_when_fewer_than_2_times(): void
    {
        $stops = [
            ['stopId' => 'de:15003:1', 'departurePlanned' => null],
            ['stopId' => 'de:15003:2', 'departurePlanned' => null],
            ['stopId' => 'de:15003:3', 'departurePlanned' => '2026-04-22T12:10:00Z'],
        ];
        $this->assertNull(compute_schedule_fingerprint($stops));
    }

    public function test_schedule_fingerprint_skips_stops_without_time(): void
    {
        $fullStops = self::stopsBasic();
        $stopsWithGap = [
            ['stopId' => 'de:15003:1', 'departurePlanned' => '2026-04-22T12:00:00Z'],
            ['stopId' => 'de:15003:X', 'departurePlanned' => null],   // ohne Zeit: übersprungen
            ['stopId' => 'de:15003:2', 'departurePlanned' => '2026-04-22T12:05:00Z'],
            ['stopId' => 'de:15003:3', 'departurePlanned' => '2026-04-22T12:10:00Z'],
        ];
        // Fingerprint unterscheidet sich, weil stopId:X fehlt, aber Stop 1-3 identisch
        $fpGap  = compute_schedule_fingerprint($stopsWithGap);
        $fpFull = compute_schedule_fingerprint($fullStops);
        // Stop:X wird übersprungen, daher sollten beide identisch sein
        $this->assertSame($fpFull, $fpGap);
    }

    // -----------------------------------------------------------------------
    // compute_fingerprints (Wrapper)
    // -----------------------------------------------------------------------

    public function test_compute_fingerprints_returns_both_keys(): void
    {
        $result = compute_fingerprints(self::stopsBasic());
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('schedule', $result);
        $this->assertIsString($result['path']);
        $this->assertIsString($result['schedule']);
    }

    public function test_compute_fingerprints_schedule_null_without_times(): void
    {
        $stops = [
            ['stopId' => 'de:15003:1', 'departurePlanned' => null],
            ['stopId' => 'de:15003:2', 'departurePlanned' => null],
        ];
        $result = compute_fingerprints($stops);
        $this->assertNull($result['schedule']);
        $this->assertIsString($result['path']);
    }

    // -----------------------------------------------------------------------
    // normalize_stop_id – Steig → Haltestelle (letzte 2 Ziffern entfernen)
    // -----------------------------------------------------------------------

    public function test_normalize_strips_last_two_digits_of_numeric_id(): void
    {
        // Olvenstedter Platz: zwei Steige derselben Haltestelle
        $this->assertSame('3007389', normalize_stop_id('300738901'));
        $this->assertSame('3007389', normalize_stop_id('300738903'));
    }

    public function test_normalize_leaves_non_numeric_ids_unchanged(): void
    {
        $this->assertSame('de:15003:4000', normalize_stop_id('de:15003:4000'));
    }

    public function test_normalize_leaves_very_short_ids_unchanged(): void
    {
        $this->assertSame('12', normalize_stop_id('12'));
        $this->assertSame('7', normalize_stop_id('7'));
    }

    public function test_platform_variants_yield_same_schedule_fingerprint(): void
    {
        // Kernfall des "grauen Problems": dieselbe Fahrt, an einem Zwischenhalt
        // ein abweichender Steig (…901 vs. …903) → muss denselben Fingerprint
        // ergeben, damit kein Duplikat-Trip entsteht.
        $a = [
            ['stopId' => '300730901', 'departurePlanned' => '2026-05-11T13:52:00Z'],
            ['stopId' => '300738901', 'departurePlanned' => '2026-05-11T14:02:00Z'],
            ['stopId' => '300449305', 'departurePlanned' => '2026-05-11T14:11:00Z'],
        ];
        $b = $a;
        $b[1]['stopId'] = '300738903'; // anderer Steig am selben Halt
        $this->assertSame(
            compute_schedule_fingerprint($a),
            compute_schedule_fingerprint($b)
        );
        $this->assertSame(
            compute_path_fingerprint($a),
            compute_path_fingerprint($b)
        );
    }

    public function test_different_station_still_changes_fingerprint(): void
    {
        // Abweichung in den Stations-Ziffern (nicht nur im Steig) bleibt relevant.
        $a = [
            ['stopId' => '300730901', 'departurePlanned' => '2026-05-11T13:52:00Z'],
            ['stopId' => '300738901', 'departurePlanned' => '2026-05-11T14:02:00Z'],
        ];
        $b = $a;
        $b[1]['stopId'] = '300739001'; // andere Haltestelle (3007390 ≠ 3007389)
        $this->assertNotSame(
            compute_schedule_fingerprint($a),
            compute_schedule_fingerprint($b)
        );
    }

    // -----------------------------------------------------------------------
    // extract_utc_hhmm
    // -----------------------------------------------------------------------

    public static function hhmmProvider(): array
    {
        return [
            ['2026-04-22T12:05:00Z', '12:05'],
            ['2026-04-22T00:00:00Z', '00:00'],
            ['2026-04-22T23:59:00Z', '23:59'],
            [null,                   null],
            ['',                     null],
            ['invalid',              null],
        ];
    }

    #[DataProvider('hhmmProvider')]
    public function test_extract_utc_hhmm(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, extract_utc_hhmm($input));
    }

    // -----------------------------------------------------------------------
    // derive_service_date – Betriebsdatum aus Trip-Start
    // -----------------------------------------------------------------------

    public function test_derive_service_date_uses_first_stop_local_date(): void
    {
        $stops = [
            // 12:00 UTC = 14:00 Berlin (Sommerzeit) – derselbe Kalendertag
            ['stopId' => 'a', 'departurePlanned' => '2026-04-22T12:00:00Z'],
            ['stopId' => 'b', 'departurePlanned' => '2026-04-22T12:05:00Z'],
        ];
        $this->assertSame('2026-04-22', derive_service_date($stops));
    }

    public function test_derive_service_date_keeps_start_day_for_overnight_trip(): void
    {
        // Sonntag-Nachtfahrt 23:45 lokal → Halte nach Mitternacht liegen kalendarisch
        // im Montag, der Betriebstag bleibt aber der Sonntag (Trip-Start).
        $stops = [
            // 21:45 UTC = So 23:45 Berlin
            ['stopId' => 'start', 'departurePlanned' => '2026-04-26T21:45:00Z'],
            // 22:15 UTC = Mo 00:15 Berlin
            ['stopId' => 'late',  'departurePlanned' => '2026-04-26T22:15:00Z'],
        ];
        $this->assertSame('2026-04-26', derive_service_date($stops));
    }

    public function test_derive_service_date_skips_stops_without_time(): void
    {
        $stops = [
            ['stopId' => 'a', 'departurePlanned' => null],
            ['stopId' => 'b', 'departurePlanned' => ''],
            ['stopId' => 'c', 'departurePlanned' => '2026-04-22T22:00:00Z'],
        ];
        $this->assertSame('2026-04-23', derive_service_date($stops));
    }

    public function test_derive_service_date_returns_null_when_no_times(): void
    {
        $stops = [
            ['stopId' => 'a', 'departurePlanned' => null],
            ['stopId' => 'b'],
        ];
        $this->assertNull(derive_service_date($stops));
    }

    public function test_derive_service_date_handles_winter_time(): void
    {
        // 23:30 UTC = 00:30 Berlin nächster Tag (Winterzeit, UTC+1)
        $stops = [
            ['stopId' => 'a', 'departurePlanned' => '2026-01-15T23:30:00Z'],
        ];
        $this->assertSame('2026-01-16', derive_service_date($stops));
    }

    // -----------------------------------------------------------------------
    // scheduled_stops_only – Zusatzhalte bei Umleitungen
    // -----------------------------------------------------------------------

    /**
     * Regelbetrieb der Fahrt: drei planmäßige Halte.
     */
    private static function stopsRegular(): array
    {
        return [
            ['stopId' => 'de:15003:1', 'departurePlanned' => '2026-09-22T17:29:00Z'],
            ['stopId' => 'de:15003:2', 'departurePlanned' => '2026-09-22T17:31:00Z'],
            ['stopId' => 'de:15003:3', 'departurePlanned' => '2026-09-22T17:37:00Z'],
        ];
    }

    /**
     * Dieselbe Fahrt an einem Störungstag: die Planhalte bleiben mit ihren
     * Planzeiten erhalten und sind als ausgefallen markiert, dazwischen
     * stehen Zusatzhalte der Umleitungsstrecke.
     */
    private static function stopsDiverted(): array
    {
        return [
            ['stopId' => 'de:15003:1',  'departurePlanned' => '2026-09-22T17:29:00Z'],
            ['stopId' => 'de:15003:90', 'departurePlanned' => '2026-09-22T17:30:00Z', 'additional' => true],
            ['stopId' => 'de:15003:2',  'departurePlanned' => '2026-09-22T17:31:00Z', 'cancelled' => true],
            ['stopId' => 'de:15003:91', 'departurePlanned' => '2026-09-22T17:32:00Z', 'additional' => true],
            ['stopId' => 'de:15003:3',  'departurePlanned' => '2026-09-22T17:37:00Z', 'cancelled' => true],
        ];
    }

    public function test_scheduled_stops_only_removes_additional_stops(): void
    {
        $filtered = scheduled_stops_only(self::stopsDiverted());

        $this->assertCount(3, $filtered);
        $this->assertSame(
            ['de:15003:1', 'de:15003:2', 'de:15003:3'],
            array_column($filtered, 'stopId')
        );
    }

    public function test_scheduled_stops_only_keeps_cancelled_stops(): void
    {
        $filtered = scheduled_stops_only(self::stopsDiverted());

        // Entfallende Halte behalten ihre Planzeiten und beschreiben weiter
        // den regulären Fahrplan – sie dürfen nicht herausfallen.
        $this->assertTrue($filtered[1]['cancelled']);
    }

    public function test_scheduled_stops_only_is_noop_without_flags(): void
    {
        $stops = self::stopsBasic();
        $this->assertSame($stops, scheduled_stops_only($stops));
    }

    public function test_scheduled_stops_only_keeps_list_if_all_additional(): void
    {
        // Defensiv: ein leerer Laufweg wäre schlechter als ein abweichender.
        $stops = [
            ['stopId' => 'a', 'departurePlanned' => '2026-09-22T17:29:00Z', 'additional' => true],
            ['stopId' => 'b', 'departurePlanned' => '2026-09-22T17:31:00Z', 'additional' => true],
        ];
        $this->assertSame($stops, scheduled_stops_only($stops));
    }

    /**
     * Kern der Störungsbehandlung: Die umgeleitete Fahrt muss denselben
     * Fingerprint ergeben wie im Regelbetrieb – sonst legt
     * POST /api/recordings pro Störungstag eine eigene Fahrt an.
     */
    public function test_diverted_trip_keeps_schedule_fingerprint_of_regular_trip(): void
    {
        $this->assertSame(
            compute_schedule_fingerprint(self::stopsRegular()),
            compute_schedule_fingerprint(self::stopsDiverted())
        );
    }

    public function test_diverted_trip_keeps_path_fingerprint_of_regular_trip(): void
    {
        $this->assertSame(
            compute_path_fingerprint(self::stopsRegular()),
            compute_path_fingerprint(self::stopsDiverted())
        );
    }

    /**
     * Gegenprobe: Eine echte Fahrplanänderung – ein anderer Planhalt – muss
     * den Fingerprint weiterhin verändern.
     */
    public function test_changed_scheduled_stop_still_changes_fingerprint(): void
    {
        $changed = self::stopsDiverted();
        $changed[2]['stopId'] = 'de:15003:99';

        $this->assertNotSame(
            compute_schedule_fingerprint(self::stopsRegular()),
            compute_schedule_fingerprint($changed)
        );
    }

    public function test_derive_service_date_ignores_additional_start_stop(): void
    {
        // Umgeleitete Fahrt, die auf der Umleitungsstrecke beginnt: der
        // Betriebstag kommt trotzdem vom ersten Planhalt.
        $stops = [
            ['stopId' => 'extra', 'departurePlanned' => '2026-04-26T22:10:00Z', 'additional' => true],
            ['stopId' => 'plan',  'departurePlanned' => '2026-04-26T21:45:00Z'],
        ];
        $this->assertSame('2026-04-26', derive_service_date($stops));
    }

    // ── route_arrival_updates ───────────────────────────────────────────────

    /**
     * Linienwechsel 5 → 1 an City Carré: an 18:36, ab 18:38 (Berlin, MESZ).
     * Der gespeicherte Laufweg stammt vom 15.04. (MESZ), der frische Abruf vom
     * 26.09. – die Ankunft muss das Datum der gespeicherten Abfahrt tragen.
     */
    public function test_route_arrival_updates_uses_dwell_and_stored_date(): void
    {
        $route = [
            ['sequence' => 2, 'departure_planned' => '2026-04-15 16:38:00'],
            ['sequence' => 3, 'departure_planned' => '2026-04-15 16:45:00'],
        ];
        $fresh = [
            ['departurePlanned' => '2026-09-26T16:30:00Z', 'arrivalPlanned' => null],
            ['departurePlanned' => '2026-09-26T16:38:00Z', 'arrivalPlanned' => '2026-09-26T16:36:00Z'],
            // Endhalt: Ankunft steht auch als Abfahrt → Standzeit 0
            ['departurePlanned' => '2026-09-26T16:45:00Z', 'arrivalPlanned' => '2026-09-26T16:45:00Z'],
        ];

        $this->assertSame([
            2 => '2026-04-15 16:36:00',
            3 => '2026-04-15 16:45:00',
        ], route_arrival_updates($route, $fresh));
    }

    public function test_route_arrival_updates_skips_other_trip_and_missing_arrival(): void
    {
        $route = [
            ['sequence' => 2, 'departure_planned' => '2026-04-15 16:38:00'],
            ['sequence' => 3, 'departure_planned' => '2026-04-15 16:45:00'],
            ['sequence' => 4, 'departure_planned' => null],
            ['sequence' => 9, 'departure_planned' => '2026-04-15 17:00:00'],
        ];
        $fresh = [
            ['departurePlanned' => '2026-09-26T16:30:00Z', 'arrivalPlanned' => null],
            // Abfahrtsminute weicht ab → andere Fahrt, nicht übernehmen
            ['departurePlanned' => '2026-09-26T16:39:00Z', 'arrivalPlanned' => '2026-09-26T16:36:00Z'],
            // Keine Ankunft geliefert
            ['departurePlanned' => '2026-09-26T16:45:00Z', 'arrivalPlanned' => null],
            ['departurePlanned' => '2026-09-26T16:50:00Z', 'arrivalPlanned' => '2026-09-26T16:49:00Z'],
        ];

        $this->assertSame([], route_arrival_updates($route, $fresh));
    }

    public function test_route_arrival_updates_across_utc_midnight(): void
    {
        // an 23:58, ab 00:02 UTC – die Ankunft liegt am Vortag der Abfahrt
        $route = [['sequence' => 2, 'departure_planned' => '2026-04-16 00:02:00']];
        $fresh = [
            ['departurePlanned' => '2026-09-26T23:50:00Z', 'arrivalPlanned' => null],
            ['departurePlanned' => '2026-09-27T00:02:00Z', 'arrivalPlanned' => '2026-09-26T23:58:00Z'],
        ];

        $this->assertSame([2 => '2026-04-15 23:58:00'], route_arrival_updates($route, $fresh));
    }
}
