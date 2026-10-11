-- Remove redundant Access path after verifying canonical archive basenames.
-- Back up DB/PDF/metadata together and stop all writers first.
-- Does not rename files. Check archive inventory before running this migration.
DELIMITER //
DROP PROCEDURE IF EXISTS eablage_remove_document_path//
CREATE PROCEDURE eablage_remove_document_path()
BEGIN
    DECLARE incompatible BIGINT DEFAULT 0;
    IF EXISTS (SELECT 1 FROM dokumente WHERE DokID IS NULL OR DokID < 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ungueltige DokID: Bestand vor Dateipfad-Entfernung klaeren.';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumente' AND COLUMN_NAME = 'Dateipfad') THEN
        -- Dynamic SQL also allows a repeated run after the column was removed.
        SET @eablage_path_check = 'SELECT COUNT(*) INTO @eablage_path_mismatch FROM dokumente WHERE Dateipfad IS NOT NULL AND OCTET_LENGTH(Dateipfad) > 0 AND BINARY SUBSTRING_INDEX(REPLACE(Dateipfad, CHAR(92), ''/''), ''/'', -1) <> BINARY CONCAT(DokID, ''dok.pdf'')';
        PREPARE path_check FROM @eablage_path_check;
        EXECUTE path_check;
        DEALLOCATE PREPARE path_check;
        SET incompatible = @eablage_path_mismatch;
        IF incompatible > 0 THEN
            SET @eablage_path_report = 'SELECT DokID, Dateipfad, CONCAT(DokID, ''dok.pdf'') AS ErwarteterDateiname FROM dokumente WHERE Dateipfad IS NOT NULL AND OCTET_LENGTH(Dateipfad) > 0 AND BINARY SUBSTRING_INDEX(REPLACE(Dateipfad, CHAR(92), ''/''), ''/'', -1) <> BINARY CONCAT(DokID, ''dok.pdf'') ORDER BY DokID';
            PREPARE path_report FROM @eablage_path_report;
            EXECUTE path_report;
            DEALLOCATE PREPARE path_report;
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Abweichende Archivdateinamen: Dateien und Metadaten vor Migration klaeren.';
        END IF;
        ALTER TABLE dokumente DROP COLUMN Dateipfad;
    END IF;
END//
CALL eablage_remove_document_path()//
DROP PROCEDURE eablage_remove_document_path//
DELIMITER ;
