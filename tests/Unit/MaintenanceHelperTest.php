<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/maintenance.php – reine Logik-Funktionen.
 *
 * format_maintenance_payload() formt eine DB-Row ins API-Payload-Format um.
 * Die DB-gebundenen Pfade (get_active_maintenance, require_no_maintenance)
 * werden über Bruno-Integrationstests abgedeckt.
 */
class MaintenanceHelperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('format_maintenance_payload')) {
            require_once dirname(__DIR__, 2) . '/lib/maintenance.php';
        }
    }

    public function test_format_returns_null_for_null_row(): void
    {
        $this->assertNull(format_maintenance_payload(null));
    }

    public function test_format_maps_columns_to_camelcase(): void
    {
        $row = [
            'id'         => '7',
            'message'    => 'Datenbank-Upgrade läuft',
            'started_at' => '2026-05-12 18:30:00',
        ];
        $payload = format_maintenance_payload($row);

        $this->assertNotNull($payload);
        $this->assertSame(7, $payload['id']);
        $this->assertSame('Datenbank-Upgrade läuft', $payload['message']);
        $this->assertSame('2026-05-12T18:30:00Z', $payload['startedAt']);
    }

    public function test_format_keeps_message_string_unmodified(): void
    {
        // Umlaute, Sonderzeichen, lange Texte → 1:1 durchreichen
        $row = [
            'id'         => 1,
            'message'    => 'Über 1 Stunde "Wartung" – bis ca. 20:00 Uhr',
            'started_at' => '2026-05-12 00:00:00',
        ];
        $this->assertSame(
            'Über 1 Stunde "Wartung" – bis ca. 20:00 Uhr',
            format_maintenance_payload($row)['message']
        );
    }
}
