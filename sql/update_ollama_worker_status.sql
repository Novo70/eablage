-- Reproducible worker status, not archive data. Back up DB and pause writers first.
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
