-- =============================================================================
-- DATABASE.sql – marego Kursnummer-Erfassungs-App
-- MariaDB-Schema, Version 1.1, Stand: 2026-03-24
--
-- Reihenfolge beachten: Tabellen ohne FK zuerst.
-- Zeichensatz: utf8mb4 (voller Unicode inkl. Emoji)
--
-- Tabellen-Prefix:
--   Die Tabellennamen enthalten den Platzhalter %%PREFIX%%.
--   Vor der Ausführung mit setup_db.sh ersetzen:
--     ./local_scripts/setup_db.sh          (liest Prefix aus config.php, führt SQL aus)
--     ./local_scripts/setup_db.sh --print  (gibt fertiges SQL auf stdout aus)
--
--   Für manuelles Ersetzen ohne setup_db.sh:
--     sed 's/%%PREFIX%%/meinprefix_/g' DATABASE.sql | mysql -h HOST -u USER -p DB
--   Kein Prefix gewünscht: %%PREFIX%% bleibt leer, Tabellen heißen wie bisher.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%stops
-- Periodenübergreifender Namens-Cache für HAFAS-Haltestellen-IDs.
-- Wird beim ersten Auftreten einer neuen hafas_id befüllt (INSERT IGNORE).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%stops` (
    `hafas_id` VARCHAR(20)  NOT NULL,
    `name`     VARCHAR(100) NOT NULL,
    PRIMARY KEY (`hafas_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%school_holidays
-- Periodenübergreifend. Wird über das Admin-Frontend gepflegt.
-- Wochentagstyp SF gilt für Mo–Fr innerhalb dieser Intervalle
-- (sofern kein gesetzlicher Feiertag).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%school_holidays` (
    `id`        INT          NOT NULL AUTO_INCREMENT,
    `name`      VARCHAR(100) NOT NULL COMMENT 'z.B. Sommerferien 2025',
    `date_from` DATE         NOT NULL COMMENT 'Beginn inklusiv',
    `date_to`   DATE         NOT NULL COMMENT 'Ende inklusiv',
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_%%PREFIX%%school_holidays_dates` CHECK (`date_to` >= `date_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%schedule_periods
-- Jede Zeile repräsentiert einen abgegrenzten Fahrplanzeitraum.
-- Die aktive Periode ist immer die mit dem höchsten id-Wert.
-- Beim ersten API-Aufruf wird automatisch eine initiale Periode angelegt
-- (name "Fahrplan (initial)", start_date = aktuelles Datum).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%schedule_periods` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL COMMENT 'z.B. Fahrplan 2025/2026',
    `start_date` DATE         NOT NULL COMMENT 'Erster Gültigkeitstag',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%trips
-- Logische, fahrplanstabile Fahrten. Eine Fahrt ist eindeutig durch
-- (period_id, service_nr, line, day_type).
-- Wird beim ersten Erfassen einer Abfahrt automatisch angelegt (INSERT IGNORE).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%trips` (
    `id`                   INT          NOT NULL AUTO_INCREMENT,
    `period_id`            INT          NOT NULL,
    `service_nr`           VARCHAR(20)  NOT NULL COMMENT 'HAFAS fahrtNr',
    `line`                 VARCHAR(10)  NOT NULL COMMENT 'z.B. 6',
    `day_type`             ENUM('MO-FR','SA','SO','FT','SF') NOT NULL,
    `direction`            VARCHAR(100) NOT NULL COMMENT 'Zielhaltestellenname',
    `manual_course_number` CHAR(2)      NULL     COMMENT 'Admin-Übersteuerung; NULL = keine',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_%%PREFIX%%trip_period` (`period_id`, `service_nr`, `line`, `day_type`),
    CONSTRAINT `fk_%%PREFIX%%trips_period`
        FOREIGN KEY (`period_id`) REFERENCES `%%PREFIX%%schedule_periods` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%recordings
-- Einzelne Beobachtungen einer Abfahrt durch einen Erfasser.
-- Mehrere Erfassungen pro logischer Fahrt sind ausdrücklich gewünscht.
-- Die aktive Kursnummer wird zur Laufzeit per Mehrheitsregel berechnet,
-- sofern manual_course_number in %%PREFIX%%trips NULL ist.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%recordings` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `trip_id`           INT          NOT NULL,
    `recorded_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     COMMENT 'UTC-Zeitstempel',
    `hafas_trip_id`     VARCHAR(512) NOT NULL COMMENT 'HAFAS tripId (tagesgebunden, neues Format bis ~300 Zeichen)',
    `service_date`      DATE         NOT NULL COMMENT 'Betriebsdatum',
    `stop_id`           VARCHAR(20)  NOT NULL COMMENT 'Beobachtete Haltestelle',
    `departure_planned` DATETIME     NOT NULL,
    `departure_actual`  DATETIME     NULL     COMMENT 'NULL wenn keine Echtzeit verfügbar',
    `course_number`     CHAR(2)      NOT NULL COMMENT '01-99, immer zweistellig',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%recordings_trip`        (`trip_id`),
    KEY `idx_%%PREFIX%%recordings_service_date` (`service_date`),
    CONSTRAINT `fk_%%PREFIX%%recordings_trip`
        FOREIGN KEY (`trip_id`) REFERENCES `%%PREFIX%%trips` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_%%PREFIX%%recordings_stop`
        FOREIGN KEY (`stop_id`) REFERENCES `%%PREFIX%%stops` (`hafas_id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_%%PREFIX%%course_number`
        CHECK (`course_number` REGEXP '^[0-9]{2}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%route_stops
-- Normalisierter Laufweg je Erfassung. Wird beim Speichern einer Erfassung
-- automatisch über den HAFAS Trip-Endpunkt befüllt.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%route_stops` (
    `id`                INT         NOT NULL AUTO_INCREMENT,
    `recording_id`      INT         NOT NULL,
    `sequence`          TINYINT     NOT NULL COMMENT 'Position im Laufweg, beginnend bei 1',
    `stop_id`           VARCHAR(20) NOT NULL,
    `departure_planned` DATETIME    NULL     COMMENT 'NULL bei letztem Halt (nur Ankunft)',
    `line`              VARCHAR(10) NULL     COMMENT 'Linie an diesem Halt (aus HAFAS prodL); NULL wenn nicht verfügbar',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%route_stops_recording` (`recording_id`),
    CONSTRAINT `fk_%%PREFIX%%route_stops_recording`
        FOREIGN KEY (`recording_id`) REFERENCES `%%PREFIX%%recordings` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_%%PREFIX%%route_stops_stop`
        FOREIGN KEY (`stop_id`) REFERENCES `%%PREFIX%%stops` (`hafas_id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%hafas_log
-- Protokolliert echte HAFAS-API-Aufrufe (keine Cache-Hits).
-- Aktivierung: 'hafas_logging' => true in config.php
-- Bereinigung: rollierende 7 Tage, lazy mit 2 % Wahrscheinlichkeit.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%hafas_log` (
    `id`          BIGINT      NOT NULL AUTO_INCREMENT,
    `logged_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    `endpoint`    ENUM('nearby','departures','trip') NOT NULL,
    `http_status` SMALLINT    NOT NULL,
    `duration_ms` SMALLINT    UNSIGNED NOT NULL,
    `cache_hit`   TINYINT(1)  NOT NULL DEFAULT 0,
    `retry_after` SMALLINT    UNSIGNED NULL COMMENT 'Sekunden aus Retry-After-Header, falls vorhanden',
    `params`      JSON                 NULL COMMENT 'Fachliche Anfrageparameter (lat/lon, stopId, tripId)',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%hafas_log_logged_at` (`logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Nützliche Abfragen (Kommentare, kein ausführbarer Code)
-- =============================================================================
--
-- Aktive Periode ermitteln:
--   SELECT * FROM %%PREFIX%%schedule_periods ORDER BY id DESC LIMIT 1;
--
-- Aktive Kursnummer je Fahrt berechnen (Mehrheitsregel, Gleichstand: ältester):
--   SELECT
--       t.id,
--       t.line,
--       t.service_nr,
--       t.day_type,
--       COALESCE(
--           t.manual_course_number,
--           (
--               SELECT r.course_number
--               FROM %%PREFIX%%recordings r
--               WHERE r.trip_id = t.id
--               GROUP BY r.course_number
--               ORDER BY COUNT(*) DESC, MIN(r.recorded_at) ASC
--               LIMIT 1
--           )
--       ) AS active_course_number
--   FROM %%PREFIX%%trips t
--   WHERE t.period_id = ?;
--
-- Wochentagstyp wird serverseitig in PHP berechnet (Feiertage + Schulferien).
-- =============================================================================
