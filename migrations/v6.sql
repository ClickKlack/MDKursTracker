-- =============================================================================
-- v6.sql – Migration auf Schema-Version 6
-- Soft-Delete für Erfassungen: Spalte `deleted_at` + Filter-Index.
-- Damit kann eine eigene Erfassung im Verlauf gelöscht und über die
-- Undo-Snackbar wiederhergestellt werden, ohne id/recorded_at zu verlieren.
-- Lazy-Cleanup im POST-Endpunkt entfernt Datensätze > 30 Tage soft-deleted.
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v6
--   ./local_scripts/setup_db.sh --migrate-v6-remote
--
-- Sicher wiederholbar: vor ALTER prüft das Skript via information_schema,
-- ob Spalte und Index bereits existieren (kompatibel mit MariaDB 10.4+).
-- =============================================================================

SET NAMES utf8mb4;

-- ── Spalte deleted_at ergänzen ────────────────────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'recordings')
      AND column_name  = 'deleted_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `%%PREFIX%%recordings`
         ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL
             COMMENT ''Soft-Delete-Zeitpunkt (UTC); NULL = aktiv''
             AFTER `comment`',
    'SELECT 1 /* column already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Index für schnellen Filter ────────────────────────────────────────────────
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'recordings')
      AND index_name   = CONCAT('idx_%%PREFIX%%', 'recordings_deleted_at')
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `%%PREFIX%%recordings`
         ADD KEY `idx_%%PREFIX%%recordings_deleted_at` (`deleted_at`)',
    'SELECT 1 /* index already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
