<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/announcements.php – reine Logik-Funktionen.
 *
 * format_announcement_row() formt eine DB-Row ins API-Payload-Format um.
 * pick_latest_announcement() wählt aus einer vor-gefilterten Liste den jüngsten.
 * Der DB-gebundene Pfad get_current_announcement() wird über Bruno-Tests
 * abgedeckt.
 */
class AnnouncementHelperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('format_announcement_row')) {
            require_once dirname(__DIR__, 2) . '/lib/announcements.php';
        }
    }

    public function test_format_returns_null_for_null_row(): void
    {
        $this->assertNull(format_announcement_row(null));
    }

    public function test_format_maps_columns_to_camelcase(): void
    {
        $row = [
            'id'         => '3',
            'body'       => 'Heute Abend kein Spätbetrieb',
            'expires_at' => '2026-05-13 22:00:00',
        ];
        $payload = format_announcement_row($row);

        $this->assertNotNull($payload);
        $this->assertSame(3, $payload['id']);
        $this->assertSame('Heute Abend kein Spätbetrieb', $payload['body']);
        $this->assertSame('2026-05-13T22:00:00Z', $payload['expiresAt']);
    }

    // -----------------------------------------------------------------------
    // pick_latest_announcement – jüngste id wird gewählt
    // -----------------------------------------------------------------------

    public function test_pick_latest_returns_null_when_empty(): void
    {
        $this->assertNull(pick_latest_announcement([]));
    }

    public function test_pick_latest_returns_only_row(): void
    {
        $rows   = [['id' => 5, 'expires_at' => '2026-06-01 00:00:00']];
        $result = pick_latest_announcement($rows);
        $this->assertSame(5, $result['id']);
    }

    public function test_pick_latest_uses_highest_id(): void
    {
        $rows = [
            ['id' => 2, 'expires_at' => '2099-01-01 00:00:00'],
            ['id' => 7, 'expires_at' => '2026-06-01 00:00:00'],
            ['id' => 4, 'expires_at' => '2027-01-01 00:00:00'],
        ];
        $result = pick_latest_announcement($rows);
        // Auswahl rein per id (DESC) – nicht per expires_at, sonst sähe man
        // die "längste Laufzeit" als jüngste, nicht den neuesten Eintrag.
        $this->assertSame(7, $result['id']);
    }
}
