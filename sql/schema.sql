-- e_ablage database schema
-- Target: eAblage 4.4.0 (gemeinsame Web-/Backend-/Worker-Version)
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
  `Erlaeuterung` varchar(1000) DEFAULT NULL,
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
  `Erlaeuterung` varchar(1000) DEFAULT NULL,
  `Aktiv` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parameter` (
  `param_key` varchar(255) NOT NULL,
  `param_value` text DEFAULT NULL,
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

-- Reproducible AI proposals; excluded from archive backups.
CREATE TABLE IF NOT EXISTS ki_vorschlaege (
    auftrag_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    textfenster VARCHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kontext CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(20) NOT NULL,
    ergebnis LONGTEXT NULL,
    fehler TEXT NULL,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Runtime worker heartbeat; excluded from archive backups.
CREATE TABLE IF NOT EXISTS ki_worker_status (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    owner CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    heartbeat DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL,
    auftrag_id BIGINT UNSIGNED NULL,
    gestartet_am DATETIME NULL,
    versuch_ab DATETIME NULL,
    fehler VARCHAR(255) NULL
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


-- Nummernverwaltung: gemeinsamer Initialisierungs- und Migrationsabschnitt.
-- Bei der leeren Neuinstallation entstehen hier Tabellen, die Art Allgemein und
-- die Migrationsmarkierung; die Datenübernahmen finden keine Bestandszeilen vor.
-- Der folgende Sicherungs-/Stopphinweis gilt bei separater Ausführung des Updates
-- auf einem bestehenden Bestand (sql/update_document_numbers.sql).
-- Vorher vollständige Datenbanksicherung erstellen; Web/Worker während Migration stoppen.
-- Wiederholbar. Altspalten bleiben ausschließlich für die Übergangskompatibilität erhalten.
SET NAMES utf8mb4;
-- Ältere Bestände besitzen teilweise nur den Primärschlüssel auf ID.
-- Der Fremdschlüssel benötigt einen Index auf DokID. Die fachliche DokID ist eindeutig.
-- Bei doppelten DokIDs bricht dieser Schritt ab: keine automatische Datenbereinigung.
ALTER TABLE dokumente ADD UNIQUE INDEX IF NOT EXISTS uq_dokumente_dokid (DokID);
CREATE TABLE IF NOT EXISTS nummernart (
 id VARCHAR(20) NOT NULL PRIMARY KEY,
 Bezeichnung VARCHAR(255) NOT NULL,
 Erlaeuterung VARCHAR(1000) DEFAULT NULL,
 Sortierung INT NOT NULL DEFAULT 10,
 Aktiv TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_nummernart_bezeichnung (Bezeichnung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO nummernart (id,Bezeichnung) VALUES ('1','Allgemein');
CREATE TABLE IF NOT EXISTS dokument_nummer (
 ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 DokumentID INT NOT NULL,
 NummernartID VARCHAR(20) NOT NULL,
 Wert VARCHAR(255) NOT NULL,
 Suchwert VARCHAR(255) NOT NULL,
 Sortierung INT NOT NULL DEFAULT 10,
 UNIQUE KEY uq_dokument_nummernart (DokumentID,NummernartID),
 KEY ix_nummer_suchwert (Suchwert),
 CONSTRAINT fk_nummer_dokument FOREIGN KEY (DokumentID) REFERENCES dokumente(DokID) ON DELETE CASCADE,
 CONSTRAINT fk_nummer_art FOREIGN KEY (NummernartID) REFERENCES nummernart(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS autokat_nummer (
 ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 AutoKatID INT NOT NULL,
 NummernartID VARCHAR(20) NOT NULL,
 Startanker VARCHAR(255) NOT NULL DEFAULT '',
 Endmodus VARCHAR(20) NOT NULL DEFAULT 'zeile',
 Endanker VARCHAR(255) NOT NULL DEFAULT '',
 Festwert VARCHAR(255) NOT NULL DEFAULT '',
 Praefix VARCHAR(255) NOT NULL DEFAULT '',
 MaxLaenge INT NOT NULL DEFAULT 0,
 Sortierung INT NOT NULL DEFAULT 10,
 UNIQUE KEY uq_autokat_nummernart (AutoKatID,NummernartID),
 CONSTRAINT fk_nummer_regel FOREIGN KEY (AutoKatID) REFERENCES autokat(id) ON DELETE CASCADE,
 CONSTRAINT fk_regel_nummer_art FOREIGN KEY (NummernartID) REFERENCES nummernart(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE dokumente MODIFY COLUMN Nummer VARCHAR(255) DEFAULT NULL;
START TRANSACTION;
INSERT IGNORE INTO dokument_nummer (DokumentID,NummernartID,Wert,Suchwert)
 SELECT DokID,'1',TRIM(Nummer),UPPER(REGEXP_REPLACE(TRIM(Nummer),'[^a-zA-Z0-9]',''))
 FROM dokumente WHERE DokID IS NOT NULL AND TRIM(COALESCE(Nummer,''))<>''
 AND NOT EXISTS (SELECT 1 FROM parameter WHERE param_key='nummernMigration' AND param_value='1');
INSERT IGNORE INTO autokat_nummer (AutoKatID,NummernartID,Startanker,Endmodus,Endanker,Festwert,Praefix,MaxLaenge)
 SELECT id,'1',COALESCE(prNum,''),'legacy',COALESCE(NumEnde,''),COALESCE(nr,''),COALESCE(prNumAnz,''),COALESCE(NumLänge,0)
 FROM autokat WHERE (TRIM(COALESCE(prNum,''))<>'' OR TRIM(COALESCE(nr,''))<>'')
 AND NOT EXISTS (SELECT 1 FROM parameter WHERE param_key='nummernMigration' AND param_value='1');
INSERT INTO parameter (param_key,param_value,anzeige) VALUES ('nummernMigration','1','nein') ON DUPLICATE KEY UPDATE param_value='1';

COMMIT;
