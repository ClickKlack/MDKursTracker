<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/trip_touch.php (Validierungs-Helfer für POST /api/trips/touch).
 */
class TripTouchTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('validate_trip_touch_body')) {
            require_once dirname(__DIR__, 2) . '/lib/trip_touch.php';
        }
    }

    private static function validBody(): array
    {
        return [
            'hafasTripId'      => '1|12345|0|80|24032026',
            'serviceNr'        => '41058',
            'line'             => '6',
            'stopId'           => 'de:15003:4000',
            'departurePlanned' => '2026-03-24T14:32:00Z',
        ];
    }

    public function test_valid_body_passes(): void
    {
        $result = validate_trip_touch_body(self::validBody());
        $this->assertTrue($result['ok']);
        $this->assertSame(self::validBody(), $result['body']);
    }

    public function test_extra_fields_are_preserved(): void
    {
        $body              = self::validBody();
        $body['direction'] = 'Lübecker Str.';
        $result = validate_trip_touch_body($body);
        $this->assertTrue($result['ok']);
        $this->assertSame('Lübecker Str.', $result['body']['direction']);
    }

    public function test_non_array_body_is_rejected(): void
    {
        foreach ([null, 'foo', 42, false] as $bad) {
            $result = validate_trip_touch_body($bad);
            $this->assertFalse($result['ok']);
            $this->assertSame('Ungültiger JSON-Body', $result['error']);
        }
    }

    public function test_missing_required_fields(): void
    {
        foreach (['hafasTripId', 'serviceNr', 'line', 'stopId', 'departurePlanned'] as $field) {
            $body = self::validBody();
            unset($body[$field]);
            $result = validate_trip_touch_body($body);
            $this->assertFalse($result['ok'], "Feld $field als fehlend erkannt");
            $this->assertSame("Pflichtfeld fehlt: $field", $result['error']);
        }
    }

    public function test_invalid_departure_planned_format(): void
    {
        $body                     = self::validBody();
        $body['departurePlanned'] = '2026-03-24 14:32:00';
        $result = validate_trip_touch_body($body);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ISO-8601-UTC', $result['error']);
    }

    public function test_empty_string_field_is_rejected(): void
    {
        $body              = self::validBody();
        $body['serviceNr'] = '';
        $result = validate_trip_touch_body($body);
        $this->assertFalse($result['ok']);
        $this->assertSame('Pflichtfeld fehlt: serviceNr', $result['error']);
    }

    public function test_field_length_limits(): void
    {
        $cases = [
            'hafasTripId' => 513,
            'serviceNr'   => 21,
            'line'        => 11,
        ];
        foreach ($cases as $field => $tooLong) {
            $body          = self::validBody();
            $body[$field]  = str_repeat('a', $tooLong);
            $result        = validate_trip_touch_body($body);
            $this->assertFalse($result['ok'], "$field zu lang erkannt");
            $this->assertStringContainsString("$field darf maximal", $result['error']);
        }
    }

    public function test_field_length_at_boundary_is_accepted(): void
    {
        $body                = self::validBody();
        $body['hafasTripId'] = str_repeat('a', 512);
        $body['serviceNr']   = str_repeat('a', 20);
        $body['line']        = str_repeat('a', 10);
        $result              = validate_trip_touch_body($body);
        $this->assertTrue($result['ok']);
    }

    // -------------------------------------------------------------------------
    // Optionale Stichentscheid-Felder (direction, journeyStartTime,
    // journeyEndTime) für den heuristischen route_stops-Lookup.
    // -------------------------------------------------------------------------

    public function test_optional_tiebreak_fields_are_accepted(): void
    {
        $body = self::validBody() + [
            'direction'        => 'Buckau',
            'journeyStartTime' => '2026-03-24T14:20:00Z',
            'journeyEndTime'   => '2026-03-24T14:48:00Z',
        ];
        $result = validate_trip_touch_body($body);
        $this->assertTrue($result['ok']);
        $this->assertSame('Buckau', $result['body']['direction']);
    }

    public function test_optional_tiebreak_fields_may_be_absent(): void
    {
        // Ältere Clients mit gecachtem Service Worker senden sie nicht – der
        // Request muss trotzdem durchgehen.
        $result = validate_trip_touch_body(self::validBody());
        $this->assertTrue($result['ok']);
    }

    public function test_optional_tiebreak_fields_may_be_null(): void
    {
        $body = self::validBody() + [
            'direction'        => null,
            'journeyStartTime' => null,
            'journeyEndTime'   => null,
        ];
        $result = validate_trip_touch_body($body);
        $this->assertTrue($result['ok']);
    }

    public function test_optional_tiebreak_fields_may_be_empty_strings(): void
    {
        // HAFAS liefert die Laufwegzeiten nicht für jede Fahrt; Clients reichen
        // dann '' durch. Das darf keinen 400er auslösen.
        $body = self::validBody() + [
            'direction'        => '',
            'journeyStartTime' => '',
            'journeyEndTime'   => '',
        ];
        $result = validate_trip_touch_body($body);
        $this->assertTrue($result['ok']);
    }

    public function test_invalid_journey_time_format_is_rejected(): void
    {
        foreach (['journeyStartTime', 'journeyEndTime'] as $field) {
            $body         = self::validBody();
            $body[$field] = '2026-03-24 14:20:00';
            $result       = validate_trip_touch_body($body);
            $this->assertFalse($result['ok'], "$field-Format geprüft");
            $this->assertStringContainsString('ISO-8601-UTC', $result['error']);
        }
    }

    public function test_direction_length_limit(): void
    {
        $body              = self::validBody();
        $body['direction'] = str_repeat('a', 101);
        $result            = validate_trip_touch_body($body);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('direction darf maximal', $result['error']);

        $body['direction'] = str_repeat('a', 100);
        $this->assertTrue(validate_trip_touch_body($body)['ok']);
    }
}
