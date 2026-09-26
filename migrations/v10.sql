-- =============================================================================
-- v10.sql – Migration auf Schema-Version 10
-- Eine neue Tabelle:
--   %%PREFIX%%mdtakt_log – Protokoll aller Aufrufe der MD-Takt-API
--                          (Admin-Tab "MD-Takt-Log")
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v10
--   ./local_scripts/setup_db.sh --migrate-v10-remote
--
-- Sicher wiederholbar: CREATE TABLE IF NOT EXISTS ist idempotent.
--
-- Die Tabelle deckt beide Datenflüsse ab, ohne je Endpunkt eigene Spalten:
--   Fluss 1  POST collector/sightings      (cron/mdtakt_sync.php)
--   Fluss 2  GET|POST collector/course-lookup (Abfahrtstafel)
-- Gemeinsame Zähler stehen in items_sent/items_ok, endpunktspezifische in
-- stats (JSON). Die Bodies sind optional – bei häufigen Kursauskünften
-- lassen sie sich weglassen, ohne das Schema zu ändern.
--
-- Geschrieben wird je HTTP-Aufruf in lib/mdtakt.php, gelöscht nach
-- mdtakt_log_days (Standard 30) am Ende jedes Cron-Laufs. Der Token steht
-- nur im Header und wird nie protokolliert.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%mdtakt_log` (
    `id`            BIGINT            NOT NULL AUTO_INCREMENT,
    `logged_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    `method`        VARCHAR(6)        NOT NULL COMMENT 'GET | POST',
    `endpoint`      VARCHAR(50)       NOT NULL COMMENT 'Pfad unter /api/v1/, z.B. collector/sightings',
    `context`       VARCHAR(100)      NULL     COMMENT 'Aufrufer: Skriptname (CLI) bzw. Request-Pfad (Web)',
    `http_status`   SMALLINT          NOT NULL COMMENT '0 = Netzwerkfehler/Timeout',
    `duration_ms`   INT      UNSIGNED NOT NULL,
    `cache_hit`     TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = aus eigenem Cache beantwortet, kein HTTP-Aufruf',
    `items_sent`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sichtungen bzw. angefragte Abfahrten',
    `items_ok`      SMALLINT UNSIGNED NULL     COMMENT 'angenommen bzw. gefunden; NULL ohne 2xx',
    `stats`         JSON              NULL     COMMENT 'Endpunktspezifische Zähler',
    `error`         VARCHAR(500)      NULL     COMMENT 'Fehlercode/-text bei Nicht-2xx',
    `request_body`  MEDIUMTEXT        NULL     COMMENT 'JSON bzw. Query-String; NULL = nicht gespeichert',
    `response_body` MEDIUMTEXT        NULL,
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%mdtakt_log_logged_at` (`logged_at`),
    KEY `idx_%%PREFIX%%mdtakt_log_endpoint`  (`endpoint`, `logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
