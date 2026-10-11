-- In der jeweiligen Zieldatenbank vor dem Deployment ausführen.
-- Wiederholbar; bestehende IDs/Bezeichnungen und Aktiv-Zustände bleiben erhalten.
ALTER TABLE kategorie ADD COLUMN IF NOT EXISTS Aktiv TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE doktyp ADD COLUMN IF NOT EXISTS Aktiv TINYINT(1) NOT NULL DEFAULT 1;
