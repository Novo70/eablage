-- OPTIONALE getrennte Umstellung Datum VARCHAR -> DATE (MariaDB).
-- Datenbank sichern, Web/Backend sowie alle anderen Schreiber vorher stoppen.
-- Ohne Schreibpause besteht zwischen Pruefung und ALTER ein Rennen!
-- Benutzt eine temporaere Hilfsprozedur, benoetigt CREATE/ALTER ROUTINE und ALTER.
-- Bei Abbruch Daten unveraendert lassen und die gemeldeten DokIDs fachlich klaeren.
-- Niemals --force verwenden. Ein Fehler im CALL kann kein ALTER ausloesen.
SET NAMES utf8mb4;
DELIMITER //
CREATE OR REPLACE PROCEDURE eablage_400_document_date(IN do_convert BOOLEAN)
SQL SECURITY INVOKER
BEGIN
    DECLARE bad_count INT DEFAULT 0;
    -- Keine Datumsumwandlung vor der Validierung: auch unmoegliche Tage,
    -- Schaltjahre, Leerstrings, Nulldaten und Leerzeichen werden erkannt.
    CREATE TEMPORARY TABLE eablage_400_invalid_dates AS
    SELECT DokID, Datum FROM dokumente
    WHERE CASE
        WHEN Datum IS NULL THEN FALSE
        WHEN CHAR_LENGTH(Datum) <> 10 OR Datum NOT REGEXP '^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$' THEN TRUE
        WHEN CAST(SUBSTRING(Datum, 6, 2) AS UNSIGNED) NOT BETWEEN 1 AND 12 THEN TRUE
        ELSE CAST(SUBSTRING(Datum, 9, 2) AS UNSIGNED) NOT BETWEEN 1 AND
            CASE CAST(SUBSTRING(Datum, 6, 2) AS UNSIGNED)
                WHEN 2 THEN 28 + (MOD(CAST(LEFT(Datum, 4) AS UNSIGNED), 400) = 0 OR
                    (MOD(CAST(LEFT(Datum, 4) AS UNSIGNED), 4) = 0 AND
                     MOD(CAST(LEFT(Datum, 4) AS UNSIGNED), 100) <> 0))
                WHEN 4 THEN 30 WHEN 6 THEN 30 WHEN 9 THEN 30 WHEN 11 THEN 30
                ELSE 31
            END
        END;
    SELECT COUNT(*) INTO bad_count FROM eablage_400_invalid_dates;
    SELECT * FROM eablage_400_invalid_dates ORDER BY DokID;
    DROP TEMPORARY TABLE eablage_400_invalid_dates;
    IF bad_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Datum nicht umgestellt: ungueltige Bestandswerte, siehe DokIDs.';
    END IF;
    IF do_convert THEN
        ALTER TABLE dokumente MODIFY COLUMN Datum DATE DEFAULT NULL;
    END IF;
END//
DELIMITER ;

-- Nur Bestandspruefung, keine Konvertierung:
CALL eablage_400_document_date(FALSE);
-- Erst nach erfolgreicher Pruefung und mit Sicherung/Schreibpause separat ausfuehren:
-- CALL eablage_400_document_date(TRUE);
-- Anschliessend Hilfsprozedur entfernen:
-- DROP PROCEDURE eablage_400_document_date;
