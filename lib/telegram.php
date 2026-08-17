<?php
// Telegram-Benachrichtigung für den täglichen Diagnose-Report.
//
// Konfiguration in config.php (beide Werte nötig, sonst passiert nichts):
//   'telegram_bot_token' => '123456:ABC-DEF…',
//   'telegram_chat_id'   => '-1001234567890',
//
// Ohne Konfiguration bleibt telegram_send() still und meldet false zurück –
// so lässt sich der Report gefahrlos betreiben, bevor der Kanal steht.

require_once __DIR__ . '/logger.php';

/**
 * Ist ein Telegram-Kanal konfiguriert?
 */
function telegram_configured(): bool
{
    $cfg = telegram_config();
    return ($cfg['token'] ?? '') !== '' && ($cfg['chatId'] ?? '') !== '';
}

/**
 * Liest Bot-Token und Chat-ID aus der config.php.
 *
 * @return array{token: string, chatId: string}
 */
function telegram_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $file = dirname(__DIR__) . '/config.php';
    $raw  = is_file($file) ? require $file : [];

    $cfg = [
        'token'  => (string) ($raw['telegram_bot_token'] ?? ''),
        'chatId' => (string) ($raw['telegram_chat_id'] ?? ''),
    ];

    return $cfg;
}

/**
 * Sendet eine Nachricht in den konfigurierten Kanal.
 *
 * Nutzt MarkdownV2 nicht, sondern HTML – dort müssen nur <, > und &
 * maskiert werden, was telegram_escape() erledigt. Fehler werden geloggt und
 * als false gemeldet, nie geworfen: Eine fehlgeschlagene Meldung darf einen
 * Cron-Lauf nicht abbrechen.
 *
 * @param string $html Nachrichtentext; erlaubt Telegrams HTML-Teilmenge
 *                     (<b>, <i>, <code>, <pre>, <a href>)
 */
function telegram_send(string $html): bool
{
    if (!telegram_configured()) {
        return false;
    }

    $cfg = telegram_config();
    $url = 'https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage';

    // Telegram begrenzt auf 4096 Zeichen.
    if (mb_strlen($html) > 4000) {
        $html = mb_substr($html, 0, 3980) . "\n…";
    }

    $payload = http_build_query([
        'chat_id'                  => $cfg['chatId'],
        'text'                     => $html,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        get_logger()->warning('telegram: Senden fehlgeschlagen', [
            'httpCode' => $code,
            'curlError' => $err,
            'response'  => is_string($body) ? mb_substr($body, 0, 300) : null,
        ]);
        return false;
    }

    return true;
}

/**
 * Maskiert Text für Telegrams HTML-Modus.
 */
function telegram_escape(string $text): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
}
