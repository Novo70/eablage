-- Bereinigung bestehender Installationen nach Version 3.3.0.
-- Zuerst DB_TABELLE_ARCHIV in der lokalen konfiguration.php fest auf
-- 'dokumente' setzen; siehe readme.md. In der jeweiligen Zieldatenbank ausführen.
-- Entfernt nur den veralteten Parameter, keine Dokumente. Wiederholbar.
DELETE FROM `parameter` WHERE `param_key` = 'dbTabelleArchiv';
