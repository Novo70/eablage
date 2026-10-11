-- Nur für die automatische Initialisierung einer neuen Docker-Datenbank.
-- Nach schema.sql und seed.sql ausführen; keine Bestandsdatenbank damit initialisieren.
UPDATE parameter SET param_value = '/data/inbox/'  WHERE param_key = 'pfadAblageNeu_linux';
UPDATE parameter SET param_value = '/data/archiv/' WHERE param_key = 'pfadAblage_linux';
UPDATE parameter SET param_value = '/data/meta/'   WHERE param_key = 'pfadMetadaten_linux';
UPDATE parameter SET param_value = '/data/export/' WHERE param_key = '1_expVerzeichnis_linux';
UPDATE parameter SET param_value = '/data/backup/' WHERE param_key = 'pfadDatensicherung_linux';
