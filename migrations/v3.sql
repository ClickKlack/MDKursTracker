-- =============================================================================
-- DATABASE_migrate_v3.sql – Migration auf Schema-Version 3
-- Für bestehende Installationen, bei denen DATABASE.sql bereits ausgeführt wurde.
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v3
--   (oder manuell mit: sed 's/%%PREFIX%%/IHR_PREFIX_/g' DATABASE_migrate_v3.sql | mysql ...)
--
-- Anschließend Fingerprints berechnen und route_stops deduplizieren:
--   php local_scripts/migrate_v3_fingerprints.php
--
-- Sicher wiederholbar: Spalten und Keys werden nur hinzugefügt, wenn noch nicht vorhanden.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Schritt 1: Neue Spalten in %%PREFIX%%trips
-- -----------------------------------------------------------------------------
ALTER TABLE `%%PREFIX%%trips`
    ADD COLUMN IF NOT EXISTS `path_fingerprint`     CHAR(64)     NULL
        COMMENT 'SHA-256 über geordnete Stop-IDs des Laufwegs'
        AFTER `direction`,
    ADD COLUMN IF NOT EXISTS `schedule_fingerprint` CHAR(64)     NULL
        COMMENT 'SHA-256 über Stop-ID+HH:MM-Paare (UTC); identifiziert Fahrtinstanz'
        AFTER `path_fingerprint`,
    ADD COLUMN IF NOT EXISTS `last_hafas_trip_id`   VARCHAR(512) NULL
        COMMENT 'Zuletzt bekannte HAFAS Journey-ID; wird lazy nachgeführt'
        AFTER `schedule_fingerprint`;

-- -----------------------------------------------------------------------------
-- Schritt 2: Neuer UNIQUE-Key auf schedule_fingerprint (Primärschlüssel neu)
-- MariaDB erlaubt mehrere NULL-Zeilen in einem UNIQUE-Key.
-- -----------------------------------------------------------------------------
ALTER IGNORE TABLE `%%PREFIX%%trips`
    ADD UNIQUE KEY `uq_%%PREFIX%%trips_fingerprint` (`period_id`, `schedule_fingerprint`, `day_type`);

-- -----------------------------------------------------------------------------
-- Schritt 3: Alten UNIQUE-Key auf service_nr entfernen, Index stattdessen
-- service_nr bleibt als mutable "zuletzt gesehene service_nr" erhalten.
-- -----------------------------------------------------------------------------
SET @uq_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'trips')
      AND index_name   = CONCAT('uq_%%PREFIX%%', 'trip_period')
      AND NON_UNIQUE   = 0
);
SET @sql = IF(@uq_exists > 0,
    CONCAT('ALTER TABLE `%%PREFIX%%trips` DROP INDEX `uq_%%PREFIX%%trip_period`'),
    'SELECT 1 /* unique key already removed */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Nicht-uniquer Index für Fallback-Lookup in departures (dauerhaft)
ALTER IGNORE TABLE `%%PREFIX%%trips`
    ADD KEY `idx_%%PREFIX%%trips_service_nr` (`period_id`, `service_nr`, `line`, `day_type`);

-- Index für schnellen Lookup per journey-ID
ALTER IGNORE TABLE `%%PREFIX%%trips`
    ADD KEY `idx_%%PREFIX%%trips_last_hafas` (`last_hafas_trip_id`(191));

-- -----------------------------------------------------------------------------
-- Schritt 4: route_stops von recordings auf trips umhängen
--
-- Neue Spalte trip_id hinzufügen, dann Daten migrieren, dann recording_id entfernen.
-- Der UNIQUE-Key (trip_id, sequence) wird erst nach der Datenmigration gesetzt
-- (via PHP-Skript, das Duplikate zuerst bereinigt).
-- -----------------------------------------------------------------------------
ALTER TABLE `%%PREFIX%%route_stops`
    ADD COLUMN IF NOT EXISTS `trip_id` INT NULL
        COMMENT 'FK zu trips.id; ersetzt recording_id'
        AFTER `id`;

-- trip_id aus recordings befüllen
UPDATE `%%PREFIX%%route_stops` rs
JOIN `%%PREFIX%%recordings` r ON r.id = rs.recording_id
SET rs.trip_id = r.trip_id
WHERE rs.trip_id IS NULL;

-- FK für trip_id anlegen (nur wenn noch nicht vorhanden)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = CONCAT('%%PREFIX%%', 'route_stops')
      AND CONSTRAINT_NAME = CONCAT('fk_%%PREFIX%%', 'route_stops_trip')
);
SET @sql = IF(@fk_exists = 0,
    CONCAT('ALTER TABLE `%%PREFIX%%route_stops`',
           ' ADD CONSTRAINT `fk_%%PREFIX%%route_stops_trip`',
           ' FOREIGN KEY (`trip_id`) REFERENCES `%%PREFIX%%trips` (`id`)',
           ' ON DELETE CASCADE ON UPDATE CASCADE'),
    'SELECT 1 /* FK already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Hinweis: recording_id und der alte FK/Index werden NACH der PHP-Migration entfernt
-- (migrate_v3_fingerprints.php bereinigt Duplikate und entfernt recording_id).

-- -----------------------------------------------------------------------------
-- Schritt 5: hafas_log.endpoint um 'stopfinder' erweitern
-- -----------------------------------------------------------------------------
ALTER TABLE `%%PREFIX%%hafas_log`
    MODIFY COLUMN `endpoint` ENUM('nearby','stopfinder','departures','trip') NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;
