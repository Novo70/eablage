# eAblage Dokumentation

Diese Dokumentation beschreibt eAblage 4.3.0. Web, Backend und Worker gehören zu derselben Version. Für Installation und Sicherung den verwendeten Tag oder Commit festhalten.

Pfade, Konten, Datenbanknamen und Passwörter in den Anleitungen sind neutrale Beispiele.

## Einstieg

| Ziel | Anleitung |
| --- | --- |
| Erste Schritte und tägliche Ablage | [Benutzerhandbuch](BENUTZERHANDBUCH.md) |
| Neue eigenständige Instanz mit eigener MariaDB aufsetzen | [Isolierte Neuinstallation](INSTALL_FRESH.md) |
| Anwendung mit separater MariaDB auf NAS installieren | [Standard-Installation](INSTALL_STANDARD.md) |
| Vorhandene Instanz aktualisieren | [Aktualisierung und Deployment](UPDATE.md) |
| Datenbank anlegen oder Schema prüfen | [Datenbank](DATENBANK.md) |

## Bedienung und Einrichtung

| Thema | Zuständige Beschreibung |
| --- | --- |
| Eingang, Klassifizierung, Archivierung, Suche und Bearbeitung | [Benutzerhandbuch](BENUTZERHANDBUCH.md) |
| Text aus gescannten oder gemischten PDFs erkennen | [Lokale OCR](OCR.md) |
| Inbox automatisch vorbereiten und manuell prüfen | [Stapelverarbeitung](STAPELVERARBEITUNG.md) |
| Ereignisprotokoll und Dokumenthistorie | [Dokumenthistorie](DOKUMENTHISTORIE.md) |
| Dokumente verwerfen, Papierkorb und Wiederherstellen | [Papierkorb](PAPIERKORB.md) |
| Ergänzungsregeln, Klassifikationsregeln und Extraktion | [AutoKat-Regeln](AUTOKAT.md) |
| Kategorien und Dokumententypen | [Stammdaten](STAMMDATEN.md) |
| Virtuelle Ordner, Filter und PDF-Auswahl | [Ablage-Explorer](EXPLORER.md) |
| Einzel- und Gesamtexport, Dateinamen und Verzeichnisebenen | [Export](EXPORT.md) |
| Einstellungen, Containerpfade und Verbindungsdaten | [Konfiguration](KONFIGURATION.md) |

## Betrieb und Wartung

| Thema | Anleitung |
| --- | --- |
| Zusammengehörige Sicherung und Rücksicherung in eine leere Installation | [Sicherung und Wiederherstellung](SICHERUNG.md) |
| PDFs und Begleitdateien prüfen, JSON reparieren | [Archivprüfung](ARCHIVPRUEFUNG.md) |
| Unterstützte Bestandsformate und ältere Metadaten | [Bestandskompatibilität](BESTANDSKOMPATIBILITAET.md) |
| Fehler eingrenzen und beheben | [Fehlerbehebung](FEHLERBEHEBUNG.md) |

## Technische Referenz

| Thema | Referenz |
| --- | --- |
| Dokumentfelder und vollständiger JSON-Formatvertrag | [Dokumentmodell und JSON](METADATEN_JSON.md) |
| Komponenten, Schreibhoheit, API und wichtige Dateien | [Technische Referenz](TECHNIK.md) |
| Entwicklung, Abhängigkeiten und wiederholbare Prüfungen | [Entwicklung und Tests](ENTWICKLUNG.md) |
| Architekturentscheidungen | [Architekturleitlinien](ARCHITEKTUR.md) |

Für Änderungen zwischen Projektständen dienen das [gemeinsame Changelog](../changelog.txt) und das [Backend-Changelog](../backend/changelog.txt). Diese Historien ersetzen keine aktuelle Bedienungsanleitung.

## Grundbegriffe

| Begriff | Bedeutung |
| --- | --- |
| Eingang / Inbox | Noch nicht archivierte PDFs |
| Dokumentdatum | Fachliches Datum des Dokuments |
| Archivierungsdatum | Zeitpunkt der Aufnahme; kann im Bestand unbekannt oder ausdrücklich rekonstruiert sein |
| Änderungsdatum | Zeitpunkt der letzten tatsächlichen Metadatenänderung |
| Löschzeitpunkt | Zeitpunkt, seit dem ein Dokument im Papierkorb liegt |
| DokID | Stabile fachliche Dokument-ID; auch Grundlage der Archivdateinamen |
| Metadaten | Angaben wie Kategorie, Partner, Betreff und Dokumenttext |
| PDF-Archiv | Original-PDFs mit technischen Dateinamen |
| Export | Kopien mit lesbaren Namen und optionaler Ordnerstruktur |
| Ablage-Explorer | Virtuelle Darstellung des Bestands nach dem Gesamtexportprofil |

Die Datenbank und das PDF-Archiv bilden den maßgeblichen Bestand. JSON-Dateien sind unabhängig lesbare Begleitdateien. Ein Export ersetzt weder die Datenbank noch deren Sicherung.

## Lizenz und Drittkomponenten

- [Lizenz und Weitergabe](LIZENZ.md)
- [Drittkomponenten und Lizenzen](DRITTKOMPONENTEN.md)
