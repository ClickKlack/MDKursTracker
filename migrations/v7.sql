-- =============================================================================
-- v7.sql – Migration auf Schema-Version 7
-- Zwei neue Tabellen:
--   %%PREFIX%%announcements      – Admin-pflegbare Nachrichten mit Ablaufdatum
--   %%PREFIX%%maintenance_windows – Wartungs-Protokoll (aktiv + Historie)
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v7
--   ./local_scripts/setup_db.sh --migrate-v7-remote
--
-- Sicher wiederholbar: CREATE TABLE IF NOT EXISTS sind idempotent.
--
-- Hinweis Datentyp: DATETIME statt TIMESTAMP, sonst belegt MariaDB die
-- erste NOT-NULL-Spalte implizit mit ON UPDATE CURRENT_TIMESTAMP – das würde
-- expires_at bei jedem PUT auf "jetzt" überschreiben.
-- =============================================================================

SET NAMES utf8mb4;

-- ── Tabelle %%PREFIX%%announcements ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `%%PREFIX%%announcements` (
    `id`         INT      NOT NULL AUTO_INCREMENT,
    `body`       TEXT     NOT NULL                    COMMENT 'Nachrichtentext, wird unterhalb des App-Headers angezeigt',
    `expires_at` DATETIME NOT NULL                    COMMENT 'UTC; ab diesem Zeitpunkt wird die Nachricht nicht mehr ausgeliefert',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%announcements_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tabelle %%PREFIX%%maintenance_windows ────────────────────────────────────
-- Aktive Wartung = einzige Zeile mit ended_at IS NULL.
-- Historie wird beibehalten (Protokoll der realen Start-/Endzeiten).
CREATE TABLE IF NOT EXISTS `%%PREFIX%%maintenance_windows` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `message`    VARCHAR(500) NOT NULL                    COMMENT 'Hinweistext für den Wartungs-Banner',
    `started_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, Realzeit',
    `ended_at`   DATETIME     NULL     DEFAULT NULL       COMMENT 'UTC; NULL = Wartung läuft noch',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%maintenance_windows_ended_at` (`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
