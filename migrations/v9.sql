-- =============================================================================
-- v9.sql – Migration auf Schema-Version 9
-- Übertragung an MD-Takt (Fluss 1):
--   recordings.mdtakt_synced_at  – Zeitpunkt der erfolgreichen Übertragung
--                                  (UTC); NULL = noch offen
--   route_stops.arrival_planned  – Soll-Ankunft je Halt (UTC)
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v9
--   ./local_scripts/setup_db.sh --migrate-v9-remote
--
-- Sicher wiederholbar: vor ALTER prüft das Skript via information_schema,
-- ob Spalten und Index bereits existieren (kompatibel mit MariaDB 10.4+).
--
-- Bestehende Erfassungen bleiben bewusst auf NULL – der Cron
-- cron/mdtakt_sync.php spielt so beim ersten Lauf den Altbestand ein.
-- arrival_planned lässt sich nicht nachfüllen und bleibt für bestehende
-- Laufwege NULL.
-- =============================================================================

SET NAMES utf8mb4;

-- ── Spalte recordings.mdtakt_synced_at ────────────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'recordings')
      AND column_name  = 'mdtakt_synced_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `%%PREFIX%%recordings`
         ADD COLUMN `mdtakt_synced_at` DATETIME NULL DEFAULT NULL
             COMMENT ''An MD-Takt übertragen (UTC); NULL = offen''
             AFTER `deleted_at`',
    'SELECT 1 /* column already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Index für die Auswahl offener Erfassungen ─────────────────────────────────
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'recordings')
      AND index_name   = CONCAT('idx_%%PREFIX%%', 'recordings_mdtakt_sync')
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `%%PREFIX%%recordings`
         ADD KEY `idx_%%PREFIX%%recordings_mdtakt_sync` (`mdtakt_synced_at`, `id`)',
    'SELECT 1 /* index already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Spalte route_stops.arrival_planned ────────────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'route_stops')
      AND column_name  = 'arrival_planned'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `%%PREFIX%%route_stops`
         ADD COLUMN `arrival_planned` DATETIME NULL DEFAULT NULL
             COMMENT ''Soll-Ankunft (UTC); erster Halt NULL''
             AFTER `departure_planned`',
    'SELECT 1 /* column already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
