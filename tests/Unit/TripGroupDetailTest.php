<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für die Eingabe-Validierungslogik des trip-group-detail-Endpunkts.
 *
 * Getestet wird die Trip-ID-Parsing-Logik ohne Datenbankzugriff.
 * Entspricht der Validierung in public/admin-api/trip_group_detail.php.
 */
class TripGroupDetailTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Hilfsfunktion: spiegelt die Validierungslogik des Endpunkts wider
    // -----------------------------------------------------------------------

    /**
     * Parst einen raw trip_ids-String in ein Array valider positiver Integers.
     *
     * @return int[]
     * @throws InvalidArgumentException Bei ungültigem Input
     */
    private function parseTripIds(string $raw): array
    {
        if ($raw === '') {
            throw new InvalidArgumentException('Parameter trip_ids fehlt');
        }

        $parts   = explode(',', $raw);
        $tripIds = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!ctype_digit($part) || (int) $part <= 0) {
                throw new InvalidArgumentException(
                    'Ungültige trip_ids – nur positive Ganzzahlen erlaubt'
                );
            }
            $tripIds[] = (int) $part;
        }
        $tripIds = array_unique($tripIds);

        if (count($tripIds) > 50) {
            throw new InvalidArgumentException('Maximal 50 Fahrten pro Anfrage');
        }

        return $tripIds;
    }

    // -----------------------------------------------------------------------
    // Valide Inputs
    // -----------------------------------------------------------------------

    public function test_single_id_is_parsed(): void
    {
        $result = $this->parseTripIds('42');
        $this->assertSame([42], $result);
    }

    public function test_multiple_ids_are_parsed(): void
    {
        $result = $this->parseTripIds('1,2,3');
        $this->assertSame([1, 2, 3], $result);
    }

    public function test_whitespace_around_ids_is_trimmed(): void
    {
        $result = $this->parseTripIds('1, 2 , 3');
        $this->assertSame([1, 2, 3], $result);
    }

    public function test_duplicate_ids_are_deduplicated(): void
    {
        $result = $this->parseTripIds('5,5,5');
        $this->assertSame([5], $result);
    }

    public function test_exactly_50_ids_are_accepted(): void
    {
        $raw    = implode(',', range(1, 50));
        $result = $this->parseTripIds($raw);
        $this->assertCount(50, $result);
    }

    // -----------------------------------------------------------------------
    // Invalide Inputs
    // -----------------------------------------------------------------------

    public function test_empty_string_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter trip_ids fehlt');
        $this->parseTripIds('');
    }

    public function test_non_integer_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parseTripIds('1,abc,3');
    }

    public function test_float_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parseTripIds('1.5');
    }

    public function test_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parseTripIds('0');
    }

    public function test_negative_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parseTripIds('-1');
    }

    public function test_more_than_50_unique_ids_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximal 50 Fahrten pro Anfrage');
        $raw = implode(',', range(1, 51));
        $this->parseTripIds($raw);
    }

    public function test_semicolon_separator_is_invalid(): void
    {
        // Semikolon ist kein Trenner – wird als ein ungültiger Token geparst
        $this->expectException(InvalidArgumentException::class);
        $this->parseTripIds('1;2;3');
    }

    // -----------------------------------------------------------------------
    // Sortierung und TA-Extraktion (serviceNr-Stammlogik)
    // -----------------------------------------------------------------------

    /**
     * Der ZI-Stamm einer serviceNr ist der Teil vor dem Unterstrich.
     */
    #[DataProvider('serviceNrProvider')]
    public function test_zi_stem_extraction(string $serviceNr, string $expectedStem): void
    {
        $stem = explode('_', $serviceNr)[0];
        $this->assertSame($expectedStem, $stem);
    }

    public static function serviceNrProvider(): array
    {
        return [
            ['125364_46', '125364'],
            ['125364_47', '125364'],
            ['9876_1',    '9876'],
            ['42_0',      '42'],
        ];
    }

    /**
     * Trips innerhalb einer Gruppe werden nach dem TA-Wert (Teil nach dem Unterstrich)
     * sortiert – das entspricht der chronologischen Fahrtfolge.
     */
    public function test_trips_sorted_by_ta_value(): void
    {
        $serviceNrs = ['125364_48', '125364_46', '125364_47'];
        usort($serviceNrs, static function (string $a, string $b): int {
            $taA = (int) explode('_', $a)[1];
            $taB = (int) explode('_', $b)[1];
            return $taA <=> $taB;
        });

        $this->assertSame(['125364_46', '125364_47', '125364_48'], $serviceNrs);
    }
}
