-- eAblage 4.0.0: in der gezielt ausgewaehlten Datenbank ausfuehren.
-- Vorher sichern und Schreibzugriffe stoppen. DDL ist nicht rollbackfaehig.
-- Wiederholbar: keine vorhandenen Dokumentwerte oder Parameter ueberschreiben.
-- Datum bleibt hier unveraendert; getrennte, bewachte Migration siehe Anleitung.
SET NAMES utf8mb4;

ALTER TABLE `dokumente`
    ADD COLUMN IF NOT EXISTS `Originaldateiname` varchar(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `DateiSHA256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `Aenderungsdatum` datetime DEFAULT NULL;

INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`)
SELECT '1_expDateinameModus', 'generiert',
       'Dateiname: generiert oder original (fehlender Originalname: generiert)', 'Export', NULL, 'ja'
WHERE NOT EXISTS (SELECT 1 FROM `parameter` WHERE `param_key` = '1_expDateinameModus');

INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`)
SELECT '2_expDateinameModus', 'generiert',
       'Dateiname: generiert oder original (fehlender Originalname: generiert)', 'Export', NULL, 'ja'
WHERE NOT EXISTS (SELECT 1 FROM `parameter` WHERE `param_key` = '2_expDateinameModus');

SHOW COLUMNS FROM `dokumente`;
