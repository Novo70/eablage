# eAblage

eAblage klassifiziert, archiviert und recherchiert PDF-Dokumente im Browser. Apache/PHP und FastAPI laufen in getrennten Containern und nutzen dieselbe MariaDB. Datenbank und PDF-Archiv sind der maßgebliche Bestand; JSON-Begleitdateien machen die Metadaten unabhängig lesbar.

Diese Dokumentation beschreibt eAblage in der Version 4.3.0. Web, Backend und Worker gehören zu derselben Version.

## Funktionen

- PDF-Eingang mit Einzelupload und Drag & Drop; lokale OCR, Datumserkennung, AutoKat und Textvergleich.
- Automatische Vorbereitung der Inbox im Hintergrund; manuelles Klassifizieren und Prüfen vor der Archivierung.
- Archivierung mit JSON Schema 3, Originaldateiname und SHA-256-Referenz; Recherche und Metadatenbearbeitung.
- Virtueller Ablage-Explorer mit Metadatenfilter und Original-PDF-Zugriff.
- Einzel-/Gesamtexport mit generierten oder bekannten Originalnamen und bis zu fünf Verzeichnisebenen.
- Ereignisprotokoll mit Dokumenthistorie, aufklappbaren Details und nachvollziehbaren Löschungen.
- Regel- und Stammdatenpflege sowie Anwendungseinstellungen.
- SQL-Sicherung und Rücksicherung in eine Installation mit leerer Dokumenttabelle.
- Archivprüfung, JSON-Reparatur/-Erzeugung.

## Einstieg

| Aufgabe | Dokumentation |
| --- | --- |
| Vollständige Übersicht | [Dokumentation](docs/README.md) |
| Tägliche Bedienung | [Benutzerhandbuch](docs/BENUTZERHANDBUCH.md) |
| Lokale Texterkennung und Eingangsverarbeitung | [OCR](docs/OCR.md), [Stapelverarbeitung](docs/STAPELVERARBEITUNG.md) |
| Eigenständige Instanz mit eigener MariaDB | [Isolierte Neuinstallation](docs/INSTALL_FRESH.md) |
| UGREEN mit separater MariaDB | [UGREEN-Installation](docs/INSTALL_STANDARD.md) |
| Bestehende Instanz aktualisieren | [Aktualisierung](docs/UPDATE.md) |
| Datenbank und Dateien sichern/wiederherstellen | [Sicherung](docs/SICHERUNG.md) |

Die Anwendung ist für lokalen Einbenutzerbetrieb vorgesehen und besitzt keine eigene Anmeldung. Webzugriff sollte daher auf vertrauenswürdige Clients begrenzt werden. Produktions- und Testinstanzen benötigen getrennte Datenbanken, Datenpfade und Ports.

Beschreibungen der Installation, Bedienung, Betrieb und technische Referenz sind über die Übersicht erreichbar.

## Geschichte

Die Anwendung eAblage habe ich ursprünglich in 2017 als Microsoft Access-Anwendung erstellt. Über entsprechende VBA-Module konnten PDF-Dokumente in eine Eingangsverzeichnis ausgelesen und über ein Regelwerk klassifiziert werden. Die Anwendung war bewusst so ausgelegt, dass keine Abhängkgkeit von einem bestimmten Datenbanksystem entsteht. Zu jeder PDF-Datei wird zusätzlich eine Meta-Datei mit den erkannten und vergebenen Metadaten (incl. Originaldateiname) im definierten Datenverzeichnis erzeugt. So sind alle Informationen in lesbarer Form unabhängig von der Datenbank vorhanden und können mit vertretbarem Aufwand in ein anderes System migriert werden.

Die Datenhaltung wurde aus Access-Tabellen später in eine MariaDB ausgelagert und ab 2019 über ein kleines Web-Frontend der flexible und mobile Zugriff ermöglicht. Trotz der Verlagerung der Daten aus dem proprietären Access-Format habe ich die Erzeugung der Meta-Dateien beibehalten.

Da die Klassifzierung und Archivierung über Access sehr unflexibel war, habe ich ab ca. 2024 mit Hilfe künstlicher Intelligenz nach einer Lösung gesucht, weitere Teile der Anwendung von Access zu lösen. Dies hat so gut funktioniert, dass es auf diese Weise gelungen ist, Schritt für Schritt eine eigenständige Webanwendung zu erstellen und diese auch noch um weitere Funktionalitäten zu erweitern (z. B. OCR-Erkennung).

Obwohl die Anwendung bei mir sauber läuft und ich damit mein kleines privates Dokumentenarchiv nach wie vor verwalte, stelle ich den Quellcode hier ausdrücklich zum Experimentieren ohne Gewähr zur Verfügung. Der Code sowie die Dokumentation ist zu großen Teilen mit KI-Unterstützung entstanden, aber trotz umfangreicher Tests kann ich für die korrekte Funktionalität keine Gewährleistung übernehmen.

## Lizenz

Copyright (C) 2026 Novo70

Der eigene eAblage-Quellcode steht unter der [GNU Affero General Public License Version 3 (AGPL-3.0-only)](LICENSE). Hinweise zu Nutzung, Weitergabe und Beiträgen enthält die [Lizenzdokumentation](docs/LIZENZ.md). Lizenzen externer Komponenten sind unter [Drittkomponenten](docs/DRITTKOMPONENTEN.md) beschrieben.
