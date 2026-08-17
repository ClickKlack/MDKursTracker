-- =============================================================================
-- DATABASE.sql – marego Kursnummer-Erfassungs-App
-- MariaDB-Schema, Version 3.0, Stand: 2026-04-22
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
-- Tabelle: %%PREFIX%%users
-- Anonyme Nutzer-Identitäten. Kein Login, nur Token-basiert.
-- Token wird clientseitig generiert (UUID v4) und in localStorage gespeichert.
-- display_id: 5-stellige Base36-ID (nur im Admin und im eigenen Profil sichtbar).
-- last_device: Gerätekurzname, aus User-Agent geparst.
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
-- Die aktive Periode ist die neueste, deren start_date bereits erreicht ist
-- (heute in deutscher Zeit); Perioden mit zukünftigem start_date werden erst
-- ab ihrem Gültigkeitstag aktiv. Fallback: älteste Periode.
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
-- Logische, fahrplanstabile Fahrten. Primär identifiziert durch
-- (period_id, schedule_fingerprint, day_type).
-- service_nr bleibt als nicht-uniquer Hilfswert (zuletzt bekannte HAFAS fahrtNr).
-- last_hafas_trip_id wird beim Auflösen einer Fahrt im Detail-View lazy nachgeführt.
-- Wird beim ersten Erfassen einer Abfahrt automatisch angelegt.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%trips` (
    `id`                   INT          NOT NULL AUTO_INCREMENT,
    `period_id`            INT          NOT NULL,
    `service_nr`           VARCHAR(20)  NOT NULL COMMENT 'Zuletzt bekannte HAFAS fahrtNr (mutable)',
    `line`                 VARCHAR(10)  NOT NULL COMMENT 'z.B. 6',
    `day_type`             ENUM('MO-FR','SA','SO','FT','SF') NOT NULL,
    `direction`            VARCHAR(100) NOT NULL COMMENT 'Zielhaltestellenname',
    `path_fingerprint`     CHAR(64)     NULL     COMMENT 'SHA-256 über geordnete Stop-IDs (auf Haltestellen-Ebene normalisiert) des Laufwegs',
    `schedule_fingerprint` CHAR(64)     NULL     COMMENT 'SHA-256 über normalisierte Stop-ID+HH:MM-Paare (UTC); identifiziert Fahrtinstanz',
    `last_hafas_trip_id`   VARCHAR(512) NULL     COMMENT 'Zuletzt bekannte HAFAS Journey-ID; wird lazy nachgeführt',
    `manual_course_number` CHAR(2)      NULL     COMMENT 'Admin-Übersteuerung; NULL = keine',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_%%PREFIX%%trips_fingerprint` (`period_id`, `schedule_fingerprint`, `day_type`),
    KEY `idx_%%PREFIX%%trips_service_nr`  (`period_id`, `service_nr`, `line`, `day_type`),
    KEY `idx_%%PREFIX%%trips_last_hafas`  (`last_hafas_trip_id`(191)),
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
    `user_token`        VARCHAR(64)  NULL     COMMENT 'FK zu users.token; NULL für Altdaten',
    `comment`           VARCHAR(500) NULL     COMMENT 'Optionaler Nutzerkommentar (nur bei eigenen Erfassungen editierbar)',
    `deleted_at`        TIMESTAMP    NULL     DEFAULT NULL
                                     COMMENT 'Soft-Delete-Zeitpunkt (UTC); NULL = aktiv',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%recordings_trip`        (`trip_id`),
    KEY `idx_%%PREFIX%%recordings_service_date` (`service_date`),
    KEY `idx_%%PREFIX%%recordings_user_token`   (`user_token`),
    KEY `idx_%%PREFIX%%recordings_deleted_at`   (`deleted_at`),
    CONSTRAINT `fk_%%PREFIX%%recordings_trip`
        FOREIGN KEY (`trip_id`) REFERENCES `%%PREFIX%%trips` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_%%PREFIX%%recordings_stop`
        FOREIGN KEY (`stop_id`) REFERENCES `%%PREFIX%%stops` (`hafas_id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_%%PREFIX%%recordings_user`
        FOREIGN KEY (`user_token`) REFERENCES `%%PREFIX%%users` (`token`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_%%PREFIX%%course_number`
        CHECK (`course_number` REGEXP '^[0-9]{2}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%route_stops
-- Normalisierter Laufweg je Fahrt (Trip). Wird beim ersten Erfassen einer Fahrt
-- über den HAFAS Trip-Endpunkt befüllt und danach wiederverwendet.
-- Pro (trip_id, sequence) existiert genau ein Eintrag.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%route_stops` (
    `id`                INT         NOT NULL AUTO_INCREMENT,
    `trip_id`           INT         NOT NULL,
    `sequence`          TINYINT     NOT NULL COMMENT 'Position im Laufweg, beginnend bei 1',
    `stop_id`           VARCHAR(20) NOT NULL,
    `departure_planned` DATETIME    NULL     COMMENT 'Abfahrtszeit; letzter Halt: Ankunftszeit (HAFAS aTimeS-Fallback)',
    `line`              VARCHAR(10) NULL     COMMENT 'Linie an diesem Halt (aus HAFAS prodL); NULL wenn nicht verfügbar',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_%%PREFIX%%route_stops_seq` (`trip_id`, `sequence`),
    KEY `idx_%%PREFIX%%route_stops_lookup` (`stop_id`, `departure_planned`, `line`),
    CONSTRAINT `fk_%%PREFIX%%route_stops_trip`
        FOREIGN KEY (`trip_id`) REFERENCES `%%PREFIX%%trips` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_%%PREFIX%%route_stops_stop`
        FOREIGN KEY (`stop_id`) REFERENCES `%%PREFIX%%stops` (`hafas_id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%user_favorites
-- Gespeicherte Lieblingshaltestellen je Nutzer.
-- Werden in der Nähe-Ansicht immer ganz oben angezeigt.
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
-- Tabelle: %%PREFIX%%hafas_log
-- Protokolliert echte HAFAS-API-Aufrufe (keine Cache-Hits).
-- Aktivierung: 'hafas_logging' => true in config.php
-- Bereinigung: rollierende 7 Tage, lazy mit 2 % Wahrscheinlichkeit.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%hafas_log` (
    `id`          BIGINT      NOT NULL AUTO_INCREMENT,
    `logged_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    `endpoint`    ENUM('nearby','stopfinder','departures','trip') NOT NULL,
    `http_status` SMALLINT    NOT NULL,
    `duration_ms` SMALLINT    UNSIGNED NOT NULL,
    `cache_hit`   TINYINT(1)  NOT NULL DEFAULT 0,
    `retry_after` SMALLINT    UNSIGNED NULL COMMENT 'Sekunden aus Retry-After-Header, falls vorhanden',
    `params`      JSON                 NULL COMMENT 'Fachliche Anfrageparameter (lat/lon, stopId, tripId)',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%hafas_log_logged_at` (`logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%announcements
-- Vom Admin gepflegte Nachrichten mit Ablaufdatum. Die jüngste, noch nicht
-- abgelaufene Zeile wird per GET /api/notices an die App ausgeliefert und dort
-- unterhalb des Headers angezeigt. Nutzer kann sie pro id lokal wegklicken
-- (localStorage); serverseitig keine User-spezifische Sicht.
-- -----------------------------------------------------------------------------
-- Hinweis Datentyp: DATETIME statt TIMESTAMP, sonst belegt MariaDB die erste
-- NOT-NULL-Spalte implizit mit ON UPDATE CURRENT_TIMESTAMP.
CREATE TABLE IF NOT EXISTS `%%PREFIX%%announcements` (
    `id`         INT      NOT NULL AUTO_INCREMENT,
    `body`       TEXT     NOT NULL                    COMMENT 'Nachrichtentext, wird unterhalb des App-Headers angezeigt',
    `expires_at` DATETIME NOT NULL                    COMMENT 'UTC; ab diesem Zeitpunkt wird die Nachricht nicht mehr ausgeliefert',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%announcements_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%maintenance_windows
-- Wartungsmodus inkl. Historie. Während ended_at IS NULL gilt: schreibende
-- API-Aufrufe (/api/recordings POST/PUT/DELETE) liefern HTTP 503, und das
-- Frontend zeigt einen nicht-dismissbaren Warn-Banner.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%maintenance_windows` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `message`    VARCHAR(500) NOT NULL                    COMMENT 'Hinweistext für den Wartungs-Banner',
    `started_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, Realzeit',
    `ended_at`   DATETIME     NULL     DEFAULT NULL       COMMENT 'UTC; NULL = Wartung läuft noch',
    PRIMARY KEY (`id`),
    KEY `idx_%%PREFIX%%maintenance_windows_ended_at` (`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tabelle: %%PREFIX%%heuristic_misses
-- Diagnose der Kursnummer-Heuristik: Route-Schlüssel, an denen eine echte
-- Abfahrtsanfrage ohne Kursnummer blieb, obwohl widersprüchliche Kandidaten
-- vorlagen. Wiederholungen zählen hit_count hoch statt neue Zeilen anzulegen –
-- so zeigt die Tabelle, wie oft Nutzer den Aussetzer tatsächlich sehen.
-- Ausgewertet im Admin-Bereich unter "Diagnose" und im täglichen Cron-Report
-- (cron/diagnostics_report.php).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `%%PREFIX%%heuristic_misses` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `period_id`  INT          NOT NULL                    COMMENT 'Fahrplanperiode, in der der Aussetzer auftrat',
    `route_key`  VARCHAR(120) NOT NULL                    COMMENT 'stopId|line|dayType|HH:MM',
    `direction`  VARCHAR(100) NOT NULL DEFAULT ''         COMMENT 'Richtungstext der Abfahrt; leer = nicht mitgeliefert',
    `stop_id`    VARCHAR(20)  NOT NULL,
    `line`       VARCHAR(10)  NOT NULL,
    `day_type`   ENUM('MO-FR','SA','SO','FT','SF') NOT NULL,
    `hhmm`       CHAR(5)      NOT NULL                    COMMENT 'Soll-Abfahrtszeit UTC',
    `courses`    VARCHAR(100) NOT NULL DEFAULT ''         COMMENT 'Widersprüchliche Kursnummern, z.B. "01,05"',
    `trip_ids`   VARCHAR(255) NOT NULL DEFAULT ''         COMMENT 'Beteiligte Trip-IDs, kommasepariert',
    `hit_count`  INT          NOT NULL DEFAULT 1          COMMENT 'Wie oft Nutzer diese Abfahrt ohne Kurs gesehen haben',
    `first_seen` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    `last_seen`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_%%PREFIX%%heuristic_misses_key` (`period_id`, `route_key`, `direction`),
    KEY `idx_%%PREFIX%%heuristic_misses_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Nützliche Abfragen (Kommentare, kein ausführbarer Code)
-- =============================================================================
--
-- Aktive Periode ermitteln (neueste, deren start_date bereits erreicht ist):
--   SELECT * FROM %%PREFIX%%schedule_periods
--   WHERE start_date <= CURDATE() ORDER BY start_date DESC, id DESC LIMIT 1;
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
