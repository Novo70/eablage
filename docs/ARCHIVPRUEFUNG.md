# Archivprüfung und Metadatenreparatur

Unter **Export & Datensicherung → Archivprüfung & Metadatenreparatur** kontrollieren, ob Datenbank, PDFs und Begleitdateien zueinander passen. **Archiv prüfen** verändert nichts. PHP zeigt die Ergebnisse; das Backend prüft und repariert ausgewählte Metadateien. Die Datenbank ist für deren Inhalt maßgeblich.

Voraussetzungen sind dieselbe Datenbank für PHP und Backend, erreichbare Archiv- und Metadatenpfade sowie passende Lese-/Schreibrechte gemäß [Konfiguration](KONFIGURATION.md).

## Prüfumfang

- Datenbankeintrag ohne PDF, ungültiger Dateiname oder nicht lesbare PDF.
- PDF ohne zugehörigen Datenbankeintrag.
- Fehlende, ungültige oder inhaltlich abweichende Metadatei.
- Metadatei ohne zugehörigen Datenbankeintrag.

Der Bestand ist flach: Dateiname `<DokID>dok.pdf` aus der positiven DokID im konfigurierten
Archivordner, Metadateien als `<DokID>meta.json` im Metadatenordner.
Unterverzeichnisse werden nicht rekursiv durchsucht. Die Prüfung kontrolliert Zuordnung, Metadaten und vorhandene SHA-256-Referenzprüfsummen der PDFs. Dokumente ohne Referenz werden separat gezählt; für sie wird keine ursprüngliche Dateiintegrität bestätigt. Die interne PDF-Struktur wird nicht validiert.
Nicht erreichbare Verzeichnisse sind ein Fehler und gelten nicht als leerer Bestand.

JSON wird vorrangig geprüft: Version, Struktur, Typen und alle Felder einschließlich
Bezeichnungen, Archivierungsdatum und reservierter Aufbewahrungsdauer. Das in Schema 1/2 vorhandene Info-Feld wird strikt eingelesen, aber nicht mehr fachlich mit der Datenbank verglichen; neue Metadateien verwenden Schema 3 ohne `info`.
Einrückung/Schlüsselreihenfolge spielen keine Rolle; Zeilenumbrüche innerhalb
von JSON-Textwerten gehören zum Inhalt. BOM wird akzeptiert. Unbekannte
Formatversionen werden ohne Reparaturauswahl gemeldet.

Fehlendes JSON wird auch bei vorhandenen alten TXT-Dateien als fehlend gemeldet. TXT-Dateien werden nicht gelesen und sind keine gültigen Metadaten. Verbliebene Dateien werden nur als nicht zugeordnet gemeldet; sie werden nicht automatisch entfernt.

## Reparatur

Nach einer Prüfung reparierbare Metadateien auswählen und die Reparatur
bestätigen. Es stehen mit JavaScript **Alle auswählen**,
**Auswahl aufheben** und ein Auswahlzähler zur Verfügung. Große Auswahlen werden
nacheinander in Paketen bis 100 Dateien verarbeitet; die Fortschrittsanzeige
nennt die bearbeitete und erfolgreiche Anzahl. Ohne JavaScript bleibt das Limit
von 100 Dateien pro Start bestehen. Es gibt keine automatische Auswahl oder Reparatur beim Prüfen.
Reparaturen schreiben ausschließlich JSON; vorhandene TXT-Dateien bleiben erhalten.
Das Backend liest die Datenbankwerte vor jedem Schreiben erneut. Signierte
Prüfnachweise verfallen nach 30 Minuten, bei Backend-Neustart oder Änderungen
an den betreffenden Daten/Dateien. In diesen Fällen erneut prüfen.

Pro Dokument hält das Backend während der Reparatur eine Datenbank-Zeilensperre
(InnoDB); die Datenbank selbst wird nicht geändert. Metadateien werden über eine
Temporärdatei im selben Ordner atomar ersetzt. Neue Dateien erhalten Modus 0664.
Neue JSON-Dateien werden exklusiv per Hardlink veröffentlicht, damit zwischenzeitlich
angelegte Dateien nicht überschrieben werden.
Symlinks, nicht reguläre oder nicht sicher lesbare Metadateien werden nur gemeldet.
Bei Archivprüfung und Metadatenreparatur werden PDFs, nicht zugeordnete Dateien
und Datenbankeinträge nie verändert/gelöscht.
Externe Dateioperationen und NAS-Wiederherstellungen während einer Reparatur vermeiden.

Die Sammelverarbeitung benötigt einen geöffneten Browser. Beim ersten fehlerhaften
Paket oder Verbindungsfehler werden keine weiteren Pakete gestartet. Bereits
bearbeitete Dateien bleiben erhalten. Nach Abschluss oder Abbruch **Archiv prüfen**
erneut wählen; der angezeigte Ausgangsreport ist nicht mehr aktuell.
Ohne JavaScript zeigt die Oberfläche Einzelergebnisse und prüft danach erneut. Nach einem
Verbindungsabbruch kann ein Teil der Dateien bereits repariert sein; erneut prüfen,
bevor ein weiterer Reparaturversuch gestartet wird. Schreibvorgänge werden mit
Datenbank und DokID im Backend-Log protokolliert, ohne Dokumentinhalt.
Die Implementierung verwendet synchrone Aufrufe (PHP-Backend-Zeitlimit 120 Sekunden) und hält den Prüfreport im Speicher. Laufzeit und Speicherbedarf wachsen mit dem Bestand; eine Fortsetzung als Hintergrundauftrag ist nicht implementiert.

PDF-Probleme und nicht zugeordnete Dateien zuerst inhaltlich prüfen; die Reparatur stellt keine PDFs wieder her.
