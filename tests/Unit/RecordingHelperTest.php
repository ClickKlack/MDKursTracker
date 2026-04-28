<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/recording_helpers.php – Zugriffslogik für PUT/DELETE/RESTORE.
 *
 * recording_modifiable_reason() ist eine reine Funktion ohne DB-Zugriff,
 * mit der wir die Vorbedingungen validieren (Token, Owner, aktive Periode).
 * Die DB-gebundene Variante assert_recording_modifiable() wird in den
 * Bruno-Integrationstests abgedeckt.
 */
class RecordingHelperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('recording_modifiable_reason')) {
            require_once dirname(__DIR__, 2) . '/lib/recording_helpers.php';
        }
    }

    public function test_token_missing_returns_401(): void
    {
        $row = ['user_token' => 'abc', 'period_id' => 5];
        $r   = recording_modifiable_reason($row, null, 5);
        $this->assertNotNull($r);
        $this->assertSame(401, $r['status']);
    }

    public function test_unknown_recording_returns_404(): void
    {
        $r = recording_modifiable_reason(null, 'tok', 5);
        $this->assertNotNull($r);
        $this->assertSame(404, $r['status']);
    }

    public function test_foreign_owner_returns_403(): void
    {
        $row = ['user_token' => 'other-token', 'period_id' => 5];
        $r   = recording_modifiable_reason($row, 'my-token', 5);
        $this->assertNotNull($r);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('Berechtigung', $r['message']);
    }

    public function test_inactive_period_returns_403(): void
    {
        $row = ['user_token' => 'tok', 'period_id' => 4];
        $r   = recording_modifiable_reason($row, 'tok', 5);
        $this->assertNotNull($r);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('Periode', $r['message']);
    }

    public function test_active_owner_returns_null(): void
    {
        $row = ['user_token' => 'tok', 'period_id' => 5];
        $r   = recording_modifiable_reason($row, 'tok', 5);
        $this->assertNull($r);
    }

    public function test_period_id_string_is_compared_numerically(): void
    {
        // PDO liefert period_id u.U. als String – muss trotzdem matchen.
        $row = ['user_token' => 'tok', 'period_id' => '5'];
        $this->assertNull(recording_modifiable_reason($row, 'tok', 5));
    }
}
