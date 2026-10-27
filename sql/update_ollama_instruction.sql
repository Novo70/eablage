-- Editable local AI guidance (MariaDB). Back up DB and pause writers first.
-- Repeatable; existing parameter values and custom guidance are preserved.
ALTER TABLE `parameter` MODIFY COLUMN `param_value` text DEFAULT NULL;
INSERT INTO `parameter` (`param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`, `anzeige`) VALUES
('ollamaAnweisung', 'Ordne Kategorien und Dokumententypen nach dem Inhalt und Zweck des Dokuments zu. Nutze ihre Namen und Erläuterungen als fachliche Definitionen und Abgrenzungen. Ausdrückliche Ausschlüsse in den Erläuterungen haben Vorrang vor thematischer Ähnlichkeit oder einzelnen Stichwörtern. Wähle keine Kategorie, deren Erläuterung diese Dokumentart ausschließt. Wenn keine zulässige Zuordnung erkennbar ist, lasse sie leer.
Bereits ausgefüllte Formularwerte sind vorläufige Vorschläge und können falsch sein. Prüfe die Zuordnung unabhängig davon; übernimm sie nicht ungeprüft.
Partner ist der Absender. Formuliere einen kurzen, sachlichen Betreff.
Verwende das Dokumentdatum, kein Geburts-, Fälligkeits- oder Leistungsdatum. Nummer ist die Dokumentnummer.
Tags sind höchstens fünf konkrete deutsche Stichworte, durch Komma getrennt. Sie beschreiben zusätzliche Inhalte, die weder in deinen übrigen Vorschlägen noch in den aktuellen Formularangaben enthalten sind. Vermeide Wiederholungen, sinngleiche Begriffe und allgemeine Wörter wie Dokument oder Schreiben. Ohne zusätzliche Inhalte bleiben Tags leer.', 'Fachliche KI-Anweisung (bis 4000 Zeichen); leer verwendet Standardvorgaben', 'Lokale KI', '40', 'ja')
ON DUPLICATE KEY UPDATE param_key = VALUES(param_key);
