-- Configurable AI text selection. Back up DB and pause writers first.
-- Repeatable on MariaDB/MySQL; preserves all existing settings and proposals.
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
SET @ea_text_selection_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ki_vorschlaege' AND COLUMN_NAME='textfenster')=0,
    'ALTER TABLE ki_vorschlaege ADD COLUMN textfenster VARCHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'DO 0');
PREPARE ea_text_selection_stmt FROM @ea_text_selection_ddl;
EXECUTE ea_text_selection_stmt;
DEALLOCATE PREPARE ea_text_selection_stmt;
-- Legacy code always used 9000/3000. Preserve proposals and their attempt timestamps.
UPDATE ki_vorschlaege SET textfenster='9000:3000', geaendert_am=geaendert_am WHERE textfenster IS NULL;
INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`) VALUES
('ollamaTextAnfang', '9000', 'Zeichen vom Dokumentanfang; mindestens 1, zusammen mit Ende höchstens 12000', 'Lokale KI', '70', 'ja'),
('ollamaTextEnde', '3000', 'Zeichen vom Dokumentende; 0 lässt das Ende weg, zusammen mit Anfang höchstens 12000', 'Lokale KI', '80', 'ja')
ON DUPLICATE KEY UPDATE param_key = VALUES(param_key);

