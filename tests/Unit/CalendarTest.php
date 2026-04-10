<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für lib/calendar.php
 *
 * Prüft Osterberechnung, gesetzliche Feiertage Sachsen-Anhalt
 * und die Wochentagstyp-Ermittlung.
 */
class CalendarTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Osterberechnung
    // -----------------------------------------------------------------------

    public static function easterProvider(): array
    {
        return [
            [2023, '2023-04-09'],
            [2024, '2024-03-31'],
            [2025, '2025-04-20'],
            [2026, '2026-04-05'],
            [2027, '2027-03-28'],
        ];
    }

    #[DataProvider('easterProvider')]
    public function test_get_easter(int $year, string $expected): void
    {
        $this->assertSame($expected, get_easter($year)->format('Y-m-d'));
    }

    // -----------------------------------------------------------------------
    // Gesetzliche Feiertage Sachsen-Anhalt
    // -----------------------------------------------------------------------

    public static function fixedHolidayProvider(): array
    {
        return [
            ['2026-01-01', 'Neujahr'],
            ['2026-01-06', 'Heilige Drei Könige'],
            ['2026-05-01', 'Tag der Arbeit'],
            ['2026-05-08', 'Weltfriedenstag'],
            ['2026-10-03', 'Tag der deutschen Einheit'],
            ['2026-12-25', '1. Weihnachtstag'],
            ['2026-12-26', '2. Weihnachtstag'],
        ];
    }

    #[DataProvider('fixedHolidayProvider')]
    public function test_fixed_holidays_are_in_list(string $date, string $label): void
    {
        $year     = (int) substr($date, 0, 4);
        $holidays = get_public_holidays($year);
        $this->assertContains($date, $holidays, "$label ($date) fehlt in der Feiertagsliste");
    }

    public static function movableHolidayProvider(): array
    {
        return [
            // Ostern 2026 = 05.04.
            ['2026-04-03', 'Karfreitag 2026'],
            ['2026-04-06', 'Ostermontag 2026'],
            ['2026-05-14', 'Christi Himmelfahrt 2026'],
            ['2026-05-25', 'Pfingstmontag 2026'],
            // Ostern 2025 = 20.04.
            ['2025-04-18', 'Karfreitag 2025'],
            ['2025-04-21', 'Ostermontag 2025'],
            ['2025-05-29', 'Christi Himmelfahrt 2025'],
            ['2025-06-09', 'Pfingstmontag 2025'],
        ];
    }

    #[DataProvider('movableHolidayProvider')]
    public function test_movable_holidays_are_in_list(string $date, string $label): void
    {
        $year     = (int) substr($date, 0, 4);
        $holidays = get_public_holidays($year);
        $this->assertContains($date, $holidays, "$label ($date) fehlt in der Feiertagsliste");
    }

    // -----------------------------------------------------------------------
    // Wochentagstypen (getDayType)
    // -----------------------------------------------------------------------

    /** Erstellt ein PDO-Mock, das keine Schulferien zurückgibt. */
    private function mockPdoNoHolidays(): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('0');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return $pdo;
    }

    /** Erstellt ein PDO-Mock, das Schulferien zurückgibt. */
    private function mockPdoWithSchoolHoliday(): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('1');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return $pdo;
    }

    public function test_saturday_returns_sa(): void
    {
        // 28.03.2026 ist Samstag
        $date = new DateTimeImmutable('2026-03-28');
        $this->assertSame('SA', getDayType($date, $this->mockPdoNoHolidays()));
    }

    public function test_sunday_returns_so(): void
    {
        // 29.03.2026 ist Sonntag
        $date = new DateTimeImmutable('2026-03-29');
        $this->assertSame('SO', getDayType($date, $this->mockPdoNoHolidays()));
    }

    public function test_weekday_without_holiday_returns_mofr(): void
    {
        // 24.03.2026 ist Dienstag, kein Feiertag
        $date = new DateTimeImmutable('2026-03-24');
        $this->assertSame('MO-FR', getDayType($date, $this->mockPdoNoHolidays()));
    }

    public static function holidayOnWeekdayProvider(): array
    {
        return [
            ['2026-01-01', 'Neujahr (Do)'],
            ['2026-01-06', 'Heilige Drei Könige (Di)'],
            ['2026-04-03', 'Karfreitag (Fr)'],
            ['2026-04-06', 'Ostermontag (Mo)'],
            ['2026-05-01', 'Tag der Arbeit (Fr)'],
            ['2026-05-08', 'Weltfriedenstag (Fr)'],
            ['2026-05-14', 'Christi Himmelfahrt (Do)'],
            ['2026-05-25', 'Pfingstmontag (Mo)'],
            ['2026-12-25', '1. Weihnachtstag (Fr)'],
            ['2025-10-31', 'Reformationstag 2025 (Fr)'],
        ];
    }

    #[DataProvider('holidayOnWeekdayProvider')]
    public function test_public_holiday_on_weekday_returns_so(string $date, string $label): void
    {
        // Feiertage fahren nach Sonntagsfahrplan → 'SO'
        $dt = new DateTimeImmutable($date);
        $this->assertSame('SO', getDayType($dt, $this->mockPdoNoHolidays()), $label);
    }

    public function test_holiday_on_saturday_returns_sa(): void
    {
        // Reformationstag 2026 fällt auf Samstag → SA hat Vorrang
        $date = new DateTimeImmutable('2026-10-31');
        $this->assertSame('SA', getDayType($date, $this->mockPdoNoHolidays()));
    }

    public function test_school_holiday_weekday_returns_sf(): void
    {
        // Ein beliebiger Werktag ohne gesetzlichen Feiertag, aber mit Schulferien
        // Datum muss einmalig sein (wegen statischem Cache in is_school_holiday)
        $date = new DateTimeImmutable('2026-08-03'); // Montag, kein Feiertag
        $this->assertSame('SF', getDayType($date, $this->mockPdoWithSchoolHoliday()));
    }

    public function test_school_holiday_has_no_effect_on_saturday(): void
    {
        // Schulferien ändern Samstag nicht → SA bleibt SA
        $date = new DateTimeImmutable('2026-08-01'); // Samstag
        $this->assertSame('SA', getDayType($date, $this->mockPdoWithSchoolHoliday()));
    }

    public function test_school_holiday_has_no_effect_on_public_holiday(): void
    {
        // Schulferien ändern Feiertag nicht → SO (Sonntagsfahrplan) hat Vorrang
        // 25.12.2026 ist Freitag + Feiertag, Schulferien würden SF ergeben,
        // aber Sonntagsfahrplan hat Vorrang
        $date = new DateTimeImmutable('2026-12-25');
        $this->assertSame('SO', getDayType($date, $this->mockPdoWithSchoolHoliday()));
    }

    // -----------------------------------------------------------------------
    // get_public_holiday_name
    // -----------------------------------------------------------------------

    public static function holidayNameProvider(): array
    {
        return [
            ['2026-01-01', 'Neujahr'],
            ['2026-01-06', 'Heilige Drei Könige'],
            ['2026-05-01', 'Tag der Arbeit'],
            ['2026-05-08', 'Weltfriedenstag'],
            ['2026-10-03', 'Tag der deutschen Einheit'],
            ['2026-10-31', 'Reformationstag'],
            ['2026-12-25', '1. Weihnachtstag'],
            ['2026-12-26', '2. Weihnachtstag'],
            // Bewegliche Feiertage 2026 (Ostern = 05.04.)
            ['2026-04-03', 'Karfreitag'],
            ['2026-04-06', 'Ostermontag'],
            ['2026-05-14', 'Christi Himmelfahrt'],
            ['2026-05-25', 'Pfingstmontag'],
        ];
    }

    #[DataProvider('holidayNameProvider')]
    public function test_get_public_holiday_name_returns_correct_name(string $date, string $expectedName): void
    {
        $dt = new DateTimeImmutable($date);
        $this->assertSame($expectedName, get_public_holiday_name($dt));
    }

    public function test_get_public_holiday_name_returns_null_for_non_holiday(): void
    {
        // 24.03.2026 ist Dienstag, kein Feiertag
        $dt = new DateTimeImmutable('2026-03-24');
        $this->assertNull(get_public_holiday_name($dt));
    }

    public function test_get_public_holiday_name_returns_null_for_saturday(): void
    {
        // 28.03.2026 ist Samstag, kein Feiertag
        $dt = new DateTimeImmutable('2026-03-28');
        $this->assertNull(get_public_holiday_name($dt));
    }
}
