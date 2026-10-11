-- e_ablage update to 3.3.0
-- Adds configuration for SQL database backups and parameter visibility in web settings.

ALTER TABLE `parameter`
    ADD COLUMN `anzeige` varchar(4) NOT NULL DEFAULT 'ja' AFTER `rang`;

INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`)
VALUES (
    'pfadDatensicherung_linux',
    '(bitte konfigurieren)',
    'Zielverzeichnis für SQL-Datensicherungen der Webanwendung',
    'Pfad',
    '40',
    'ja'
)
ON DUPLICATE KEY UPDATE
    `beschreibung` = VALUES(`beschreibung`),
    `gruppierung` = VALUES(`gruppierung`),
    `rang` = VALUES(`rang`),
    `anzeige` = VALUES(`anzeige`);
