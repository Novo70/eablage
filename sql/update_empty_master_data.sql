-- Leere ID-0-Platzhalter in Kategorie und Dokumententyp entfernen.
-- Vorher die Datenbank sichern. In der gewünschten eAblage-Datenbank ausführen.
-- Wiederholbar; keine Änderungen an Dokumenten, Regeln oder Zuordnungswerten.
-- 0 bleibt die Kennzeichnung für "keine Zuordnung" und benötigt keinen Stammdatensatz.
-- Andere IDs und unerwartet benannte ID-0-Zeilen bleiben erhalten.
SET NAMES utf8mb4;
START TRANSACTION;
DELETE FROM kategorie WHERE id = '0' AND TRIM(COALESCE(katBez, '')) = '';
DELETE FROM doktyp WHERE id = '0' AND TRIM(COALESCE(doktypBez, '')) = '';
COMMIT;
