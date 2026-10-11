-- eAblage 4.1.0: Nur die drei nicht mehr ausgewerteten Access-Spalten entfernen.
-- Vorher sichern und Web/Backend-Schreibvorgänge stoppen.
-- Wiederholbar; Suchbegriffe und Ausschlussbegriffe bleiben unverändert.
ALTER TABLE autokat
  DROP COLUMN IF EXISTS Suchbegriff1,
  DROP COLUMN IF EXISTS Suchbegriff2,
  DROP COLUMN IF EXISTS sucheBeenden;
