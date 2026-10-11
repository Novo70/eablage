-- e_ablage database schema
-- Target: eAblage 4.3.0 (gemeinsame Web-/Backend-/Worker-Version)
-- MariaDB

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE TABLE `autokat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `RegelID` varchar(20) NOT NULL,
  `Version` int(11) NOT NULL DEFAULT 1,
  `Rang` int(11) DEFAULT 0,
  `Suchbegriffe` text DEFAULT NULL,
  `Ausschlussbegriffe` text DEFAULT NULL,
  `Regeltyp` char(1) NOT NULL,
  `Aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `Bezeichnung` varchar(255) DEFAULT NULL,
  `Kommentar` varchar(500) DEFAULT NULL,
  `Treffer` int(11) NOT NULL DEFAULT 0,
  `LetzterTreffer` datetime DEFAULT NULL,
  `ErstelltAm` datetime NOT NULL DEFAULT current_timestamp(),
  `kat` varchar(3) DEFAULT NULL,
  `kat2` varchar(3) DEFAULT NULL,
  `dok` varchar(3) DEFAULT NULL,
  `partner` varchar(255) DEFAULT NULL,
  `betreff` varchar(255) DEFAULT NULL,
  `nr` varchar(255) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `prDatum` varchar(255) DEFAULT NULL,
  `prBetreff` varchar(255) DEFAULT NULL,
  `prBetreffAnz` varchar(255) DEFAULT NULL,
  `BetreffLänge` int(11) DEFAULT 0,
  `BetreffEnde` varchar(255) DEFAULT NULL,
  `prNum` varchar(50) DEFAULT NULL,
  `prNumAnz` varchar(50) DEFAULT NULL,
  `NumLänge` int(11) DEFAULT 0,
  `NumEnde` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_autokat_regelid` (`RegelID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doktyp` (
  `id` varchar(20) NOT NULL DEFAULT '',
  `doktypBez` varchar(255) DEFAULT NULL,
  `Aktiv` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dokumente` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `DokID` int(11) DEFAULT NULL,
  `Originaldateiname` varchar(255) DEFAULT NULL,
  `DateiSHA256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `Aenderungsdatum` datetime DEFAULT NULL,
  `GeloeschtAm` datetime DEFAULT NULL,
  `Datum` date DEFAULT NULL,
  `kategorie_id` varchar(15) DEFAULT NULL,
  `kategorie2_id` varchar(15) DEFAULT NULL,
  `doktyp_id` varchar(15) DEFAULT NULL,
  `Partner` varchar(255) DEFAULT NULL,
  `Betreff` varchar(255) DEFAULT NULL,
  `Nummer` varchar(50) DEFAULT NULL,
  `Tags` varchar(255) DEFAULT NULL,
  `Doktext` mediumtext DEFAULT NULL,
  `Aufbewahrungsdauer` int(11) DEFAULT NULL,
  `Archivierungsdatum` datetime DEFAULT NULL,
  `Info` text DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_dokumente_dokid` (`DokID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kategorie` (
  `id` varchar(20) NOT NULL DEFAULT '',
  `katBez` varchar(255) DEFAULT NULL,
  `Aktiv` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parameter` (
  `param_key` varchar(255) NOT NULL,
  `param_value` varchar(255) DEFAULT NULL,
  `beschreibung` varchar(255) DEFAULT NULL,
  `gruppierung` varchar(255) DEFAULT NULL,
  `rang` varchar(255) DEFAULT NULL,
  `anzeige` varchar(4) NOT NULL DEFAULT 'ja',
  PRIMARY KEY (`param_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;


-- Durable, reproducible inbox proposals. No archive IDs are reserved here.
CREATE TABLE IF NOT EXISTS verarbeitungsauftraege (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dateischluessel CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dateiname VARCHAR(255) NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'wartend',
    ergebnis LONGTEXT NULL,
    fehler TEXT NULL,
    versuche INT UNSIGNED NOT NULL DEFAULT 0,
    dok_id BIGINT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_verarbeitung_datei (dateischluessel),
    KEY ix_verarbeitung_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vor dem Start der aktualisierten Webanwendung ausführen, wiederholbar.
-- Keine Altdaten werden verändert oder als vermeintlich neue Ereignisse importiert.
CREATE TABLE IF NOT EXISTS `ereignisprotokoll` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `DokID` int NOT NULL,
  `Zeitpunkt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Ereignistyp` varchar(40) NOT NULL,
  `Quelle` varchar(40) NOT NULL,
  `Details` JSON NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `ix_protokoll_dokument` (`DokID`, `Zeitpunkt`, `ID`),
  KEY `ix_protokoll_ereignis` (`Ereignistyp`, `Zeitpunkt`, `ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Bewusst kein löschender Fremdschlüssel: Historie bleibt nach endgültigem Löschen erhalten.
