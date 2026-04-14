<?php
// Reine Hilfsfunktionen für das User-System.
// Keine DB-Abhängigkeiten – direkt testbar.

/**
 * Leitet eine 5-stellige Base36-ID aus dem SHA-256 des Tokens ab.
 * Werteraum: 36^5 ≈ 60 Mio. Eindeutig genug für kleine Nutzerzahlen.
 *
 * @param string $token  Normalisierter Token (lowercase, ohne Bindestriche)
 * @param int    $offset Offset in den SHA-256-Hex-Zeichen (je +6 für Kollisions-Retry)
 */
function derive_display_id(string $token, int $offset = 0): string
{
    $hash = hash('sha256', $token);
    // 6 Hex-Zeichen ab Offset = 24 Bit → max. Wert 16.777.215
    $hex  = substr($hash, $offset * 6, 6);
    $dec  = hexdec($hex);
    $b36  = strtoupper(base_convert((string) $dec, 10, 36));
    return str_pad($b36, 5, '0', STR_PAD_LEFT);
}

/**
 * Erzeugt einen kurzen Gerätenamen aus dem User-Agent-String.
 * Beispiele:
 *   "Chrome 124 / Android 14"
 *   "Safari / iPhone iOS 17"
 *   "Firefox 125 / Windows 10/11"
 */
function parse_device(string $ua): string
{
    if ($ua === '') {
        return '';
    }

    // Browser-Familie und Version
    $browser = 'Unbekannt';
    if (preg_match('/Edg(?:e)?\/(\d+)/i', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/OPR\/(\d+)/i', $ua, $m)) {
        $browser = 'Opera ' . $m[1];
    } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $m)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Version\/[\d.]+.*Safari/i', $ua)) {
        $browser = 'Safari';
    }

    // Betriebssystem / Gerät
    $os = 'Unbekannt';
    if (preg_match('/Android (\d+)/i', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/iPhone.*CPU.*OS (\d+)/i', $ua, $m)) {
        $os = 'iPhone iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/iPad.*CPU.*OS (\d+)/i', $ua, $m)) {
        $os = 'iPad iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Windows NT (\d+\.\d+)/i', $ua, $m)) {
        $ntMap = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
        $os = 'Windows ' . ($ntMap[$m[1]] ?? $m[1]);
    } elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    return substr($browser . ' / ' . $os, 0, 100);
}
