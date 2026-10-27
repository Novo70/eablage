-- Optional AI diagnostic UI (MariaDB). Back up DB and pause writers first.
-- Repeatable; existing parameter values are preserved.
INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`) VALUES
('ollamaDiagnoseAnzeigen', 'nein', 'KI-Anfrage zur Fehlersuche im Klassifizierungsmenü anzeigen (ja/nein)', 'Entwicklerwerkzeuge', '10', 'ja')
ON DUPLICATE KEY UPDATE param_key = VALUES(param_key);
