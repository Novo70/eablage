# SQL-Dateien für eAblage

Die maßgebliche Anleitung zu Schema, Rechten, Neuinstallation und Aktualisierung steht unter [Datenbank](../docs/DATENBANK.md). Einrichtung der Container: [UGREEN](../docs/INSTALL_STANDARD.md) oder [isolierte Installation](../docs/INSTALL_FRESH.md).

`schema.sql`, `seed.sql` und bei der standardmäßigen Containerstruktur `init_docker_paths.sql` sind für eine **leere Neuinstallation** bestimmt. Sie dürfen nicht über einen bestehenden Bestand importiert werden. Die übrigen Skripte werden nur nach Prüfung des Ausgangsschemas, Sicherung und Schreibpause entsprechend der Datenbankanleitung angewendet.

Eine kompatible Sicherung in eine vorbereitete leere Installation zurückspielen: [Sicherung und Wiederherstellung](../docs/SICHERUNG.md). Begleitdateien und PDFs müssen zum Sicherungsstand passen; sie sind nicht im SQL-Dump enthalten.


- `update_document_path.sql`: nach Sicherung und Schreibpause den redundanten Dokumentpfad entfernen; Prüfung und Vorgehen siehe [Datenbankanleitung](../docs/DATENBANK.md#redundanten-dokumentpfad-entfernen).
