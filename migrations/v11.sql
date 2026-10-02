-- =============================================================================
-- v11.sql – Migration auf Schema-Version 11
-- Buslinien:
--   trips.product – Verkehrsmittel der Fahrt ('tram' | 'bus'), serverseitig
--                   aus dem ZB#-Feld der HAFAS-jid bestimmt
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v11
--   ./local_scripts/setup_db.sh --migrate-v11-remote
--
-- Sicher wiederholbar: vor ALTER prüft das Skript via information_schema,
-- ob die Spalte bereits existiert (kompatibel mit MariaDB 10.4+).
--
-- Bestehende Fahrten sind ausnahmslos Straßenbahnen – der Default 'tram'
-- deckt sie ab, eine Datenmigration ist nicht nötig.
-- =============================================================================

SET NAMES utf8mb4;

-- ── Spalte trips.product ──────────────────────────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'trips')
      AND column_name  = 'product'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `%%PREFIX%%trips`
         ADD COLUMN `product` ENUM(''tram'',''bus'') NOT NULL DEFAULT ''tram''
             COMMENT ''Verkehrsmittel der Fahrt''
             AFTER `line`',
    'SELECT 1 /* column already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
