-- eAblage 4.0.0: veraltete Access-/Kompatibilitätsparameter entfernen.
-- Vorher Datenbank sichern und BACKEND_URL im Webcontainer prüfen.
-- Nur Parameterzeilen; keine Tabellen oder Spalten werden verändert.
-- Wiederholbar: Fehlende Schlüssel bleiben unberührt.

START TRANSACTION;

DELETE FROM parameter WHERE param_key IN (
    '1_expTrennzeichen',
    '1_expVerknuepfungAnlegen',
    '1_expVerzeichnis',
    '1_expVerzeichnisIndiv',
    '2_expTrennzeichen',
    '2_expVerknuepfungAnlegen',
    '2_expVerzeichnis',
    '2_expVerzeichnisEbene1',
    '2_expVerzeichnisEbene2',
    '2_expVerzeichnisEbene3',
    '2_expVerzeichnisEbene4',
    '2_expVerzeichnisIndiv',
    '2_expVerzeichnisstruktur',
    'backupSpeichern',
    'dokTextEinlesen',
    'PDFToTextLayout',
    'suffixDok',
    'suffixMeta',
    'tagsInDateiname',
    'AnalysisApiUrl',
    'dbTabelleArchiv'
);

COMMIT;
