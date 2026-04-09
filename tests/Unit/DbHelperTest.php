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
}
