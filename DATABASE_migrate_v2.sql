-- =============================================================================
-- DATABASE_migrate_v2.sql – Migration auf Schema-Version 2
-- Für bestehende Installationen, bei denen DATABASE.sql bereits ausgeführt wurde.
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v2
--   (oder manuell mit: sed 's/%%PREFIX%%/IHR_PREFIX_/g' DATABASE_migrate_v2.sql | mysql ...)
--
-- Sicher wiederholbar: alle Statements verwenden IF NOT EXISTS / IF NOT EXISTS COLUMN.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Neue Tabelle: %%PREFIX%%users
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%users` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `token`           VARCHAR(64)  NOT NULL COMMENT 'UUID v4 ohne Bindestriche, clientseitig generiert',
    `display_id`      CHAR(5)      NOT NULL COMMENT 'Base36 aus SHA-256 des Tokens, für Admin-Anzeige',
    `name`            VARCHAR(100) NULL     COMMENT 'Optionaler Nutzername, selbst eingegeben',
    `last_user_agent` VARCHAR(512) NULL     COMMENT 'Browser-User-Agent beim letzten API-Call',
    `last_device`     VARCHAR(100) NULL     COMMENT 'Gerätekurzname, aus UA geparst, z.B. "Chrome 124 / Android 14"',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_%%PREFIX%%users_token`      (`token`),
    UNIQUE KEY `uq_%%PREFIX%%users_display_id` (`display_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Neue Tabelle: %%PREFIX%%user_favorites
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%user_favorites` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `user_token` VARCHAR(64)  NOT NULL,
    `stop_id`    VARCHAR(20)  NOT NULL COMMENT 'HAFAS-Haltestellen-ID',
    `stop_name`  VARCHAR(100) NOT NULL COMMENT 'Anzeigename der Haltestelle',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_%%PREFIX%%favorites` (`user_token`, `stop_id`),
    CONSTRAINT `fk_%%PREFIX%%favorites_user`
        FOREIGN KEY (`user_token`) REFERENCES `%%PREFIX%%users` (`token`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Neue Spalten in %%PREFIX%%recordings
-- (MariaDB: IF NOT EXISTS für Spalten ab 10.0.2 unterstützt)
-- -----------------------------------------------------------------------------
ALTER TABLE `%%PREFIX%%recordings`
    ADD COLUMN IF NOT EXISTS `user_token` VARCHAR(64)  NULL
        COMMENT 'FK zu users.token; NULL für Altdaten'
        AFTER `course_number`,
    ADD COLUMN IF NOT EXISTS `comment`    VARCHAR(500) NULL
        COMMENT 'Optionaler Nutzerkommentar'
        AFTER `user_token`;

-- Index nur hinzufügen wenn noch nicht vorhanden
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT(REPLACE('%%PREFIX%%', '', ''), 'recordings')
      AND index_name   = 'idx_%%PREFIX%%recordings_user_token'
);
-- Pragmatisch: Fehler bei doppeltem Index wird ignoriert
ALTER IGNORE TABLE `%%PREFIX%%recordings`
    ADD KEY `idx_%%PREFIX%%recordings_user_token` (`user_token`);

-- Foreign Key – nur setzen wenn noch nicht vorhanden
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = CONCAT('%%PREFIX%%', 'recordings')
      AND CONSTRAINT_NAME = CONCAT('fk_%%PREFIX%%', 'recordings_user')
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `%%PREFIX%%recordings` ADD CONSTRAINT `fk_%%PREFIX%%recordings_user` FOREIGN KEY (`user_token`) REFERENCES `%%PREFIX%%users` (`token`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1 /* FK already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
