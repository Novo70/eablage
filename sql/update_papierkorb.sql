-- Einmalig vor dem ersten Einsatz der Papierkorb-Funktion ausführen.
ALTER TABLE dokumente ADD COLUMN GeloeschtAm DATETIME NULL DEFAULT NULL AFTER Aenderungsdatum;
