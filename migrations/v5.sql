-- =============================================================================
-- v5.sql – Migration auf Schema-Version 5
-- Composite-Index auf route_stops für den heuristischen Kursnummer-Lookup
-- in /api/departures (build_route_stop_course_map) und /api/trips/touch
-- (lookup_route_stop_course_single).
--
-- Ausführen:
--   ./local_scripts/setup_db.sh --migrate-v5
--   ./local_scripts/setup_db.sh --migrate-v5-remote
--
-- Sicher wiederholbar: vor dem ALTER prüft das Skript via information_schema,
-- ob der Index bereits existiert (kompatibel mit MariaDB 10.4+).
-- =============================================================================

SET NAMES utf8mb4;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = CONCAT('%%PREFIX%%', 'route_stops')
      AND index_name   = CONCAT('idx_%%PREFIX%%', 'route_stops_lookup')
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `%%PREFIX%%route_stops`
         ADD KEY `idx_%%PREFIX%%route_stops_lookup`
             (`stop_id`, `departure_planned`, `line`)',
    'SELECT 1 /* index already exists */'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
