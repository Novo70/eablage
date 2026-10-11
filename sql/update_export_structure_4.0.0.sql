-- Vorbereitung 4.0.0: fünfte Gesamtexport-Ebene, standardmäßig deaktiviert.
-- In der gewünschten Datenbank ausführen; wiederholbar ohne Wertänderungen.
-- Keine neue Spalte und keine Änderung an Dokumenten oder eingestellten Werten.
-- Eine unveränderte Standardbeschreibung der fünften Ebene wird aktualisiert.
SET NAMES utf8mb4;

INSERT INTO `parameter`
    (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`)
SELECT '1_expVerzeichnisEbene5', '(keine)',
       'Fünfte Ebene: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr, Monat oder (keine)',
       'Export', NULL, 'ja'
WHERE NOT EXISTS (
    SELECT 1 FROM `parameter` WHERE `param_key` = '1_expVerzeichnisEbene5'
);

-- Die Beschreibung bestehender Installationen nur bei bisherigem Standardtext aktualisieren.
UPDATE `parameter`
SET `beschreibung` = 'Fünfte Ebene: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr, Monat oder (keine)'
WHERE `param_key` = '1_expVerzeichnisEbene5'
  AND `beschreibung` = 'Fünfte Ebene: Kategorie, Kategorie2, Dokumententyp, Partner, Jahr, Monat oder (keine)';
