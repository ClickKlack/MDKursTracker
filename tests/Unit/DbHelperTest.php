<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für die Datetime-Hilfsfunktionen in lib/db.php:
 * mysql_to_iso() und iso_to_mysql()
 */
class DbHelperTest extends TestCase
{
    // -----------------------------------------------------------------------
    // mysql_to_iso
    // -----------------------------------------------------------------------

    public static function mysqlToIsoProvider(): array
    {
        return [
            ['2026-03-24 14:32:00', '2026-03-24T14:32:00Z'],
            ['2026-01-01 00:00:00', '2026-01-01T00:00:00Z'],
            ['2026-12-31 23:59:59', '2026-12-31T23:59:59Z'],
        ];
    }

    #[DataProvider('mysqlToIsoProvider')]
    public function test_mysql_to_iso_converts_correctly(string $mysql, string $expected): void
    {
        $this->assertSame($expected, mysql_to_iso($mysql));
    }

    public function test_mysql_to_iso_returns_null_for_null(): void
    {
        $this->assertNull(mysql_to_iso(null));
    }

    // -----------------------------------------------------------------------
    // iso_to_mysql
    // -----------------------------------------------------------------------

    public static function isoToMysqlProvider(): array
    {
        return [
            ['2026-03-24T14:32:00Z', '2026-03-24 14:32:00'],
            ['2026-01-01T00:00:00Z', '2026-01-01 00:00:00'],
            ['2026-12-31T23:59:59Z', '2026-12-31 23:59:59'],
            // Offset-Zeitzone wird korrekt nach UTC umgerechnet
            ['2026-03-24T15:32:00+01:00', '2026-03-24 14:32:00'],
        ];
    }

    #[DataProvider('isoToMysqlProvider')]
    public function test_iso_to_mysql_converts_correctly(string $iso, string $expected): void
    {
        $this->assertSame($expected, iso_to_mysql($iso));
    }

    // -----------------------------------------------------------------------
    // Roundtrip
    // -----------------------------------------------------------------------

    public function test_roundtrip_mysql_iso_mysql(): void
    {
        $original = '2026-06-15 09:45:30';
        $this->assertSame($original, iso_to_mysql(mysql_to_iso($original)));
    }

    // -----------------------------------------------------------------------
    // select_active_period_id
    // -----------------------------------------------------------------------

    /**
     * Perioden absteigend nach start_date, id sortiert – wie von der Query geliefert.
     */
    private static function periods(): array
    {
        return [
            ['id' => 3, 'start_date' => '2026-07-09'], // Zukunft (heute = 07.07.)
            ['id' => 2, 'start_date' => '2026-06-01'],
            ['id' => 1, 'start_date' => '2026-01-01'],
        ];
    }

    public function test_future_period_is_not_active_yet(): void
    {
        // Kern des Bugs: Periode #3 gilt ab 09.07., heute ist der 07.07.
        $this->assertSame(2, select_active_period_id(self::periods(), '2026-07-07'));
    }

    public function test_future_period_becomes_active_on_start_date(): void
    {
        $this->assertSame(3, select_active_period_id(self::periods(), '2026-07-09'));
    }

    public function test_future_period_active_after_start_date(): void
    {
        $this->assertSame(3, select_active_period_id(self::periods(), '2026-08-01'));
    }

    public function test_falls_back_to_oldest_when_all_periods_are_future(): void
    {
        $this->assertSame(1, select_active_period_id(self::periods(), '2025-12-31'));
    }

    public function test_returns_zero_for_empty_list(): void
    {
        $this->assertSame(0, select_active_period_id([], '2026-07-07'));
    }
}
