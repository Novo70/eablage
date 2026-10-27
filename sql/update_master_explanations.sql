-- Optional explanations for categories and document types (MariaDB).
-- Back up the database and pause application writes before running.
-- Repeatable: existing explanations and all other data are preserved.
ALTER TABLE `kategorie` ADD COLUMN IF NOT EXISTS `Erlaeuterung` varchar(1000) DEFAULT NULL;
ALTER TABLE `doktyp` ADD COLUMN IF NOT EXISTS `Erlaeuterung` varchar(1000) DEFAULT NULL;
