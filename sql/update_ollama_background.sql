-- Optional background AI. Back up DB and pause writers before applying.
-- Repeatable; existing settings are preserved. Cache contains no archive records.
CREATE TABLE IF NOT EXISTS ki_vorschlaege (
    auftrag_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kontext CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(20) NOT NULL,
    ergebnis LONGTEXT NULL,
    fehler TEXT NULL,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`) VALUES
('ollamaHintergrund', 'aus', 'KI-Vorschläge im Worker vorbereiten: aus, fehlende oder alle', 'Lokale KI', '60', 'ja')
ON DUPLICATE KEY UPDATE param_key = VALUES(param_key);
