-- Optional local AI thinking mode (MariaDB). Back up DB and pause writers first.
-- Repeatable; existing parameter values are preserved.
INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`) VALUES
('ollamaDenkmodus', 'nein', 'Denkmodus für die lokale KI (ja/nein); kann die Zuordnung verbessern und länger dauern', 'Lokale KI', '50', 'ja')
ON DUPLICATE KEY UPDATE param_key = VALUES(param_key);
