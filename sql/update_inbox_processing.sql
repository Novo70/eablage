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
