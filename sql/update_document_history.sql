-- Web/Worker stoppen und Datenbank sichern; dann in der Ziel-Datenbank ausführen.
-- Eine bestehende dokument_protokoll wird verlustfrei umbenannt (inkl. IDs/JSON).
-- Existieren beide Namen, schlägt RENAME fehl: erst die Bestände prüfen,
-- niemals eine der Tabellen ungeprüft löschen oder den Fehler ignorieren.
SET @eablage_history_migration = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokument_protokoll'),
  'RENAME TABLE `dokument_protokoll` TO `ereignisprotokoll`',
  'DO 0'
);
PREPARE eablage_history_migration FROM @eablage_history_migration;
EXECUTE eablage_history_migration;
DEALLOCATE PREPARE eablage_history_migration;

-- Vor dem Start der aktualisierten Webanwendung ausführen, wiederholbar.
-- Keine Altdaten werden verändert oder als vermeintlich neue Ereignisse importiert.
CREATE TABLE IF NOT EXISTS `ereignisprotokoll` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `DokID` int NOT NULL,
  `Zeitpunkt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Ereignistyp` varchar(40) NOT NULL,
  `Quelle` varchar(40) NOT NULL,
  `Details` JSON NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `ix_protokoll_dokument` (`DokID`, `Zeitpunkt`, `ID`),
  KEY `ix_protokoll_ereignis` (`Ereignistyp`, `Zeitpunkt`, `ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Bewusst kein löschender Fremdschlüssel: Historie bleibt nach endgültigem Löschen erhalten.

-- Standard erhält das bisherige Verhalten. Vorhandene Einstellung nie überschreiben.
INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`)
VALUES ('protokollLoeschdatenBehalten', 'ja',
  'Dokumentdaten bei endgültiger Löschung im Protokoll erhalten. Nein entfernt auch alle Details früherer Ereignisse des Dokuments; DokID, Zeitpunkt, Ereignistyp und Quelle bleiben erhalten.',
  'Archiv', '20', 'ja')
ON DUPLICATE KEY UPDATE `param_key` = VALUES(`param_key`);
