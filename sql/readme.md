# SQL-Dateien für eAblage

Die maßgebliche Anleitung zu Schema, Rechten, Neuinstallation und Aktualisierung steht unter [Datenbank](../docs/DATENBANK.md). Einrichtung der Container: [Installation mit separater Datenbank](../docs/INSTALL_STANDARD.md) oder [Installation mit integrierter Datenbank](../docs/INSTALL_FRESH.md).

`schema.sql`, `seed.sql` und bei der standardmäßigen Containerstruktur `init_docker_paths.sql` sind für eine **leere Neuinstallation** bestimmt. Sie dürfen nicht über einen bestehenden Bestand importiert werden. Die übrigen Skripte werden nur nach Prüfung des Ausgangsschemas, Sicherung und Schreibpause entsprechend der Datenbankanleitung angewendet.

Eine kompatible Sicherung in eine vorbereitete leere Installation zurückspielen: [Sicherung und Wiederherstellung](../docs/SICHERUNG.md). Begleitdateien und PDFs müssen zum Sicherungsstand passen; sie sind nicht im SQL-Dump enthalten.


- `update_document_path.sql`: nach Sicherung und Schreibpause den redundanten Dokumentpfad entfernen; Prüfung und Vorgehen siehe [Datenbankanleitung](../docs/DATENBANK.md#redundanten-dokumentpfad-entfernen).

- `update_ollama.sql`: optionale lokale KI-Parameter ergänzen; bestehende Werte erhalten. [Einrichtung](../docs/OLLAMA.md).

- `update_master_explanations.sql`: optionale Erläuterungen für Kategorien und Dokumententypen ergänzen; wiederholbar, bestehende Werte erhalten. [Details](../docs/DATENBANK.md#optionale-stammdaten-erläuterungen).

- `update_ollama_instruction.sql`: Parameterwerte auf TEXT erweitern und die bearbeitbare fachliche KI-Anweisung ergänzen; bestehende Werte erhalten. [Details](../docs/OLLAMA.md#fachliche-ki-anweisung-bearbeiten).

- `update_ollama_thinking.sql`: optionalen Denkmodus ergänzen; wiederholbar, bestehende Werte erhalten, Vorgabe `nein`. [Details](../docs/OLLAMA.md#umfang-und-grenzen).

- `update_ollama_diagnostics.sql`: optionalen Anzeigeschalter für die KI-Diagnose unter Entwicklerwerkzeuge ergänzen; wiederholbar, bestehende Werte erhalten, Vorgabe `nein`. [Details](../docs/OLLAMA.md#tatsächliche-ki-anfrage-anzeigen).

- `update_ollama_background.sql`: reproduzierbaren KI-Cache und Hintergrundmodus ergänzen; wiederholbar, vorhandene Werte bleiben erhalten. Vorgabe `aus`. [Details](../docs/OLLAMA.md#ki-im-hintergrund).

- `update_ollama_worker_status.sql`: separate Laufzeittabelle für Worker-Rückmeldung, laufenden Auftrag und Fehlerpause ergänzen; wiederholbar, keine Änderungen an Einstellungen oder Vorschlägen. [Details](../docs/OLLAMA.md#hintergrundstatus-erkennen).

- `update_ollama_text_selection.sql`: Textauswahlparameter und Cache-Kennzeichnung ergänzen; wiederholbar, bestehende Einstellungen und Vorschläge erhalten. [Details](../docs/OLLAMA.md#textauswahl-einstellen).


Für den zusammenhängenden Updatepfad von **4.3.0 auf 4.4.0** alle in [UPDATE.md](../docs/UPDATE.md#aktualisierung-von-430-auf-440) aufgeführten Skripte in der angegebenen Reihenfolge anwenden. Das umfasst KI-Parameter und Laufzeittabellen, Stammdaten-Erläuterungen und `update_document_numbers.sql`. Schema und Seed bleiben ausschließlich der Neuinstallation vorbehalten.
