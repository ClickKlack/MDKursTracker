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
}
