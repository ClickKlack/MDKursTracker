-- =============================================================================
-- v8.sql – Migration auf Schema-Version 8
-- Eine neue Tabelle:
--   %%PREFIX%%heuristic_misses – Route-Schlüssel, an denen die Kursnummer-
--                                Heuristik im laufenden Betrieb aufgegeben hat
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v8
--   ./local_scripts/setup_db.sh --migrate-v8-remote
--
-- Sicher wiederholbar: CREATE TABLE IF NOT EXISTS ist idempotent.
--
-- Hintergrund: lib/diagnostics.php kann Konflikte auch rein analytisch finden.
-- Diese Tabelle beantwortet die andere Frage – welche davon Nutzer wirklich
-- treffen und wie oft. Geschrieben wird nur, wenn eine echte Abfahrtsanfrage
-- ohne Kursnummer bleibt, obwohl Kandidaten vorlagen; im Normalfall entsteht
-- also gar kein Schreibzugriff.
--
-- Hinweis Datentyp: DATETIME statt TIMESTAMP, damit MariaDB nicht implizit
-- ON UPDATE CURRENT_TIMESTAMP auf first_seen legt.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%heuristic_misses` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `period_id`    INT          NOT NULL                    COMMENT 'Fahrplanperiode, in der der Aussetzer auftrat',
    `route_key`    VARCHAR(120) NOT NULL                    COMMENT 'stopId|line|dayType|HH:MM',
    `direction`    VARCHAR(100) NOT NULL DEFAULT ''         COMMENT 'Richtungstext der Abfahrt; leer = nicht mitgeliefert',
    `stop_id`      VARCHAR(20)  NOT NULL,
    `line`         VARCHAR(10)  NOT NULL,
    `day_type`     ENUM('MO-FR','SA','SO','FT','SF') NOT NULL,
    `hhmm`         CHAR(5)      NOT NULL                    COMMENT 'Soll-Abfahrtszeit UTC',
    `courses`      VARCHAR(100) NOT NULL DEFAULT ''         COMMENT 'Widersprüchliche Kursnummern, z.B. "01,05"',
    `trip_ids`     VARCHAR(255) NOT NULL DEFAULT ''         COMMENT 'Beteiligte Trip-IDs, kommasepariert',
    `hit_count`    INT          NOT NULL DEFAULT 1          COMMENT 'Wie oft Nutzer diese Abfahrt ohne Kurs gesehen haben',
    `first_seen`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    `last_seen`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_%%PREFIX%%heuristic_misses_key` (`period_id`, `route_key`, `direction`),
    KEY `idx_%%PREFIX%%heuristic_misses_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
