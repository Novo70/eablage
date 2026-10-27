-- Optional local AI. Idempotent; existing values are retained.
INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`) VALUES
('ollamaAktiv', 'nein', 'Lokale KI-Vorschläge in der Klassifizierung aktivieren (ja/nein)', 'Lokale KI', '10', 'ja'),
('ollamaURL', '', 'Ollama-Adresse mit privater LAN-IP, z. B. http://192.168.1.100:11434', 'Lokale KI', '20', 'ja'),
('ollamaModell', 'qwen3.5:9b', 'Exakter lokaler Modellname aus ollama list; keine Cloud-Modelle', 'Lokale KI', '30', 'ja')
ON DUPLICATE KEY UPDATE param_key = VALUES(param_key);
