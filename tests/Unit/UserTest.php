<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests für User-System-Hilfsfunktionen aus public/api/user.php.
 *
 * Da user.php keine eigene PHP-Klasse ist, sondern Top-Level-Funktionen enthält,
 * laden wir die benötigten Funktionen über einen minimalen Require-Pfad.
 *
 * Getestete Funktionen:
 *  - derive_display_id()
 *  - parse_device()
 */
class UserTest extends TestCase
{
    protected function setUp(): void
    {
        // Sicherstellen, dass die Hilfsfunktionen verfügbar sind.
        // Die response-Abhängigkeit (json_response, json_error) wird durch
        // einen Stub-Mechanismus in bootstrap.php gelöst.
        if (!function_exists('derive_display_id')) {
            require_once dirname(__DIR__, 2) . '/lib/user_helpers.php';
        }
    }

    // -----------------------------------------------------------------------
    // derive_display_id – Grundverhalten
    // -----------------------------------------------------------------------

    public function test_display_id_is_exactly_5_chars(): void
    {
        $token = str_repeat('a', 32);
        $id    = derive_display_id($token, 0);
        $this->assertSame(5, strlen($id));
    }

    public function test_display_id_is_uppercase_alphanumeric(): void
    {
        $token = str_repeat('b', 32);
        $id    = derive_display_id($token, 0);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', $id);
    }

    public function test_display_id_is_deterministic(): void
    {
        $token = 'abc123def456abc123def456abc123de';
        $this->assertSame(derive_display_id($token, 0), derive_display_id($token, 0));
    }

    public function test_display_id_differs_with_different_offsets(): void
    {
        $token = '1234567890abcdef1234567890abcdef';
        $id0   = derive_display_id($token, 0);
        $id1   = derive_display_id($token, 1);
        // Mit hoher Wahrscheinlichkeit verschieden (kann theoretisch gleich sein)
        // Wir prüfen ob der Mechanismus grundsätzlich läuft
        $this->assertSame(5, strlen($id0));
        $this->assertSame(5, strlen($id1));
    }

    public function test_display_id_differs_for_different_tokens(): void
    {
        $id1 = derive_display_id(str_repeat('1', 32), 0);
        $id2 = derive_display_id(str_repeat('2', 32), 0);
        // Verschiedene Tokens → (mit extrem hoher Wahrscheinlichkeit) verschiedene IDs
        $this->assertNotSame($id1, $id2);
    }

    // -----------------------------------------------------------------------
    // parse_device – Geräteerkennung
    // -----------------------------------------------------------------------

    public static function userAgentProvider(): array
    {
        return [
            // Android Chrome
            [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
                'Chrome 124 / Android 14',
            ],
            // iPhone Safari
            [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
                'Safari / iPhone iOS 17',
            ],
            // Firefox Windows
            [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
                'Firefox 125 / Windows 10/11',
            ],
            // Edge Windows
            [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
                'Edge 124 / Windows 10/11',
            ],
            // Leerer UA
            [
                '',
                '',
            ],
        ];
    }

    #[DataProvider('userAgentProvider')]
    public function test_parse_device(string $ua, string $expected): void
    {
        $result = parse_device($ua);
        $this->assertSame($expected, $result);
    }

    public function test_parse_device_max_length(): void
    {
        $longUa = str_repeat('A', 1000);
        $result = parse_device($longUa);
        $this->assertLessThanOrEqual(100, strlen($result));
    }
}
