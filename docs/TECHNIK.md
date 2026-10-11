# Technische Referenz

eAblage ist eine lokal betriebene Einbenutzer-Anwendung aus Apache/PHP, FastAPI und MariaDB. Komponenten bleiben nach den [Architekturleitlinien](ARCHITEKTUR.md) bestehen, solange eine Änderung keinen konkreten Vorteil bringt.

## Komponenten und Verantwortlichkeiten

| Komponente | Aufgaben und Schreibhoheit |
| --- | --- |
| PHP im Webcontainer | Oberfläche, Upload, Archivierung/Bearbeitung, Regeln/Statistik, Parameter, PDF-Ausgabe, Exportkopien, SQL-Sicherung und Initialrücksicherung |
| FastAPI im Backendcontainer | PDF-Textextraktion/OCR, Auftragsverwaltung, Datum/AutoKat/Textvergleich, Exportplanung, Stammdatenänderungen, JSON-Validierung/-Vorbereitung, Archivprüfung/-reparatur |
| Python-Worker | Inbox prüfen und ungeprüfte Ergebnisse über denselben Verarbeitungscode erzeugen |
| MariaDB | Dokumente, Aufträge, Regeln, Stammdaten und Parameter; InnoDB-Transaktionen und Sperren |
| Poppler und Tesseract im Backend/Worker | PDF-Textextraktion, seitenweises Rendern und lokale OCR für Klassifizierung/Suche |
| Dateisystem | Eingang, Archiv, Metadaten, Export und Sicherungen |

Bei regulärer Archivierung/Bearbeitung fordert PHP validiertes JSON vom Backend an und schreibt anschließend Datenbank und Dateien. Die JSON-Vorbereitung selbst schreibt nichts. Python besitzt die fachliche Schreibhoheit für Erkennungsergebnisse sowie die eigenständigen Stammdaten- und Wartungsvorgänge. PHP schließt den Auftrag als Teil der Archivierungstransaktion ab oder markiert ihn beim Verwerfen als verworfen. Es gibt keine zwei voneinander unabhängigen Schreibwege für denselben Vorgang.

Die Datenbank ist maßgeblich für JSON-Reparatur und Exportdarstellung. Begleitdateien sichern Lesbarkeit außerhalb der Anwendung, ersetzen aber keine SQL-Sicherung. Export und Explorer sind Darstellungen des Bestands.

## Container und Netze

`web/Dockerfile` verwendet PHP 8.3 mit Apache auf Bookworm und installiert die deutsche Locale sowie MySQL-Erweiterungen. `backend/Dockerfile` verwendet Python 3.11 auf Bookworm, installiert Poppler/Tesseract und startet Uvicorn auf Port 8000. Der Worker verwendet denselben Build mit `python worker.py` und öffnet keinen Port. Die Dockerfiles kopieren den jeweiligen Code beim Build ins Image.

`compose.yaml` erstellt ein projektspezifisches App-Netz und nutzt das externe Netzwerk `database` für eine separate MariaDB. Der Backendalias `backend` gehört zum jeweiligen App-Netz. `compose.fresh.yaml` besitzt zusätzlich eine eigene MariaDB mit eigenem Volume und projektspezifischem Datenbanknetz; die Datei setzt derzeit `mariadb:11.4` ein.

PHP erhält den Datenmount lesend/schreibend. Das Backend erhält die Datenwurzel lesend und nur den Metadatenordner zusätzlich schreibend. Der Worker erhält ausschließlich die Datenwurzel lesend. Die reguläre lokale Konfiguration beziehungsweise `konfiguration_docker.php` wird einzeln schreibgeschützt eingebunden. Pfade und Rechte: [Konfiguration](KONFIGURATION.md).

## API-Endpunkte

Die Schnittstellen werden intern von PHP verwendet. Für Wartungs- und Stammdatenaufrufe wird die PHP-Datenbankkennung mitgegeben; das Backend prüft die Übereinstimmung mit seiner Datenbank. Die API besitzt keine allgemeine Benutzeranmeldung und soll nicht als ungeschützter externer Dienst veröffentlicht werden.

Schreibende Webformulare senden den sitzungsgebundenen `aktion_token`. `web/csrf.php` erzeugt und prüft ihn vor Änderungen, auch bei paketweisen Wartungsanfragen. Der Token schützt gegen gefälschte browserseitige Anfragen, ersetzt aber weder eine Zugangssicherung noch die Trennung des internen Backends vom öffentlichen Netz. Nach einem Versionswechsel geöffnete alte Formulare neu laden.

| Methode und Pfad | Zweck |
| --- | --- |
| `POST /inbox/process` | Auftrag unmittelbar verarbeiten oder gespeichertes Ergebnis liefern; Datenbank, Dateiname, optional `retry` und `ocr_requested` |
| `POST /inbox/jobs` | Status ohne Dokumenttexte; optional `filenames` mit höchstens 200 Namen, ohne Liste nur vorhandene Inbox-PDFs |
| `POST /documents/text` | PDF aus Inbox oder Archiv auslesen; `ocr_requested=true` erkennt alle Seiten ausdrücklich neu |
| `POST /analyze/classify` | Gemeinsame Reihenfolge AutoKat und Textvergleich |
| `GET /health` | Status, Dienstname und Backendversion |
| `POST /analyze/date` | Allgemeine oder verankerte Datumserkennung |
| `POST /analyze/autokat` | Aktive Regeln auswerten, Werte und Herkunft liefern |
| `POST /analyze/textcompare` | Vergleichsvorlage suchen, optional eigene DokID ausschließen |
| `GET /export/document/{dok_id}` | Einzelexportname aus Profil 2 |
| `GET /export/plan` | Vollständiger Gesamtexport-/Explorerplan aus Profil 1 |
| `POST /metadata/prepare` | JSON aus vollständigem Dokumentstand validieren und vorbereiten |
| `POST /archive/audit` | Bestand prüfen |
| `POST /archive/repair` | Ausgewählte JSON-Dateien aus DB reparieren, höchstens 100 je Aufruf |
| `POST /master-data/{kind}` | Kategorien oder Dokumententypen lesen/ändern |

Bei `POST /inbox/process` erzwingt `ocr_requested=true` eine neue Verarbeitung einschließlich OCR aller Seiten, auch ohne `retry=true` und bei einem bereits vorbereiteten oder fehlgeschlagenen Ergebnis. Neue Klassifikationsvorschläge ersetzen das bisherige Auftragsergebnis. Ohne OCR-Anforderung bleibt die Verwendung fertiger Ergebnisse unverändert. Dateisperren gelten weiterhin; bei laufender Verarbeitung wird keine parallele OCR gestartet. Bereits archivierte Aufträge werden nicht erneut geöffnet. Details zur Bedienung: [OCR](OCR.md).

### Analyse-Anfragen

- Datum: `{"text":"...","search_text":"..."}`; Antwort enthält `date` und `found`.
- AutoKat: `{"text":"..."}`; Antwort enthält Metadaten, E-/K-Treffer und Feldherkunft.
- Textvergleich: `{"text":"...","exclude_document_id":10000}`; die optionale ID verhindert die eigene Vorlage. Antwort enthält Trefferstatus, Ähnlichkeit, Wortzahlen, Quell-DokID und Vorschläge.

Der Exportplan enthält Profil, Bezeichnung, Strukturflag, Anzahl und `items`. Jedes Item besitzt `dok_id`, `filename`, `directories` und `display` mit Datum, Kategorie, Typ, Partner, Betreff und Nummer. Der reine Planbuilder greift weder auf Dateien noch auf DB zu; `prepare_full_export()` lädt vorher den DB-Stand. Kollisionen werden über den vollständigen Bestand aufgelöst, bevor der Explorer filtert.

### Wartungs-Anfragen

Prüfung/Vorschau: `{"database":"eablage"}`. Schreibaufrufe ergänzen `"selections":[{"dok_id":10000,"token":"..."}]`. Tokens stammen aus der jeweiligen Vorschau und sind 30 Minuten gültig; Backend-Neustart oder Änderung des zugrunde liegenden Stands machen sie unbrauchbar. Je Dokument schützt eine Zeilensperre den gelesenen Stand. Der Browser steuert die Sammelverarbeitung über aufeinanderfolgende Aufrufe; für Archiv-Reparaturen existiert kein Hintergrundjob, der nach geschlossenem Browser garantiert fortsetzt. Der Inbox-Worker ist davon unabhängig.

Stammdaten: `kind` ist `categories` oder `document_types`. Der Body enthält `database`, `action` und bei Änderungen `entry_id` beziehungsweise `name`. Aktionen sind `list`, `create`, `rename`, `activate`, `deactivate`, `delete`.

Die vollständige JSON-Vorbereitungsanfrage und der Formatvertrag stehen unter [Dokumentmodell und JSON](METADATEN_JSON.md). Die serverseitige Fehlerantwort und der HTTP-Status sind auszuwerten; ein HTTP-Erfolg allein ersetzt nicht die Prüfung des erwarteten Antwortformats.

## Speicher- und Konsistenzgrenzen

Archivierung schützt die DokID-Vergabe mit einer Archivierungssperre und erstellt Archiv-/Metadateien exklusiv. Ein vorhandenes Ziel wird nicht überschrieben. Bearbeitung sperrt den Datensatz und prüft ihn gegen die Prüfsumme des beim Öffnen des Formulars geladenen Stands. Nur tatsächliche Änderungen setzen `Aenderungsdatum`.

Python repariert JSON über temporäre Dateien im selben Ordner und veröffentlicht neue Ziele exklusiv. Symlinks oder nicht sicher lesbare Metadateien werden nicht automatisch überschrieben. Die DB wird vor dem Schreiben erneut gelesen. Stammdatenänderung und neue Zuordnung verwenden Sperren, damit parallele Vorgänge keine neuen inaktiven oder verwaisten Zuordnungen erzeugen.

Dateisystem und MariaDB besitzen keine gemeinsame atomare Transaktion. Abbrüche können eine Prüfung erforderlich machen; ein teilweise abgeschlossener Wartungsbatch wird nicht als Gesamtpaket zurückgenommen. Die Initialrücksicherung verwendet DDL und kann einen teilbefüllten Bestand hinterlassen. Fachliche Abläufe und Fehlerbehandlung: [Archivprüfung](ARCHIVPRUEFUNG.md), [Rücksicherung](SICHERUNG.md).


## Wichtige Dateien

| Datei / Bereich | Aufgabe |
| --- | --- |
| `web/eingang.php`, `upload_dropzone.js` | Eingang, Einzelupload und Drag & Drop |
| `web/verarbeiten.php`, `classification_draft.js` | Analyseansicht, temporäre Rücksprungdaten |
| `web/speichern.php` | Archivierung und Statistik |
| `web/dokumente.php`, `bearbeiten.php` | Suche und Dokumentbearbeitung |
| `web/autokat_regeln.php`, `autokat_regel_bearbeiten.php` | Regelübersicht, Test und Bearbeitung |
| `web/stammdaten.php`, `stammdaten_funktionen.php` | Stammdatenoberfläche und Zuordnungskontrollen |
| `web/status_toggle.js`, `status_toggle.php` | Zeilenaktionen für Aktivstatus |
| `web/csrf.php` | Gemeinsamer Sitzungstoken für schreibende Web-Aktionen |
| `web/einstellungen.php` | Parameterpflege |
| `web/backend_client.php` | Gemeinsamer JSON-HTTP-Client, Status-/Fehlerauswertung, keine automatischen Wiederholungen oder Weiterleitungen |
| `web/funktionen.php`, `metadaten_funktionen.php` | Fachliche Analyseaufrufe und strikte JSON-Vorbereitung |
| `backend/worker.py`, `app/services/inbox.py` | Dateibeobachtung, Aufträge und Verarbeitung |
| `web/archiv_pdf.php`, `archiv_pdf_funktionen.php` | Original-PDF für Explorer anhand der DokID |
| `web/explorer.php`, `explorer.js`, `explorer.css` | Virtuelle Ablage und Auswahl |
| `web/export.php`, `gesamtexport.php` | Einzelexport und zentrale Administrationsseite |
| `web/datenbank_sicherung.php`, `datenbank_wiederherstellung.php` | SQL-Dump und geprüfter Initialimport |
| `web/archiv_wartung.php`, `archiv_wartung_ansicht.php`, `archiv_wartung.js` | Wartungsanfragen, Ergebnisse und Batchsteuerung |
| `backend/main.py`, `app/metadata_api.py` | API-Verträge und Fehlerantworten |
| `backend/app/services/` | Fachliche Analyse-, Export-, Stammdaten- und Wartungsdienste |
| `backend/app/db/database.py` | Verbindung über `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` |
| `sql/schema.sql`, `seed.sql`, `init_docker_paths.sql` | Leere Erstinstallation |
| `compose.yaml`, `compose.fresh.yaml` | Gemeinsamer Containerbetrieb in zwei Varianten |

`web/konfiguration.php` ist eine lokale, nicht versionierte Datei. Die vorhandenen Beispiel-/Docker-Konfigurationen stehen unter `web/`; für neue Containerinstallationen gelten ausschließlich die aktuellen Installationsanleitungen.


PDF-Textextraktion, OCR und die Zusammenführung der Klassifizierung laufen gemeinsam in Python. FastAPI und Worker verwenden dieselben Funktionen. Die Archivierung bleibt ein vollständiger PHP-Vorgang. Grenzen und Konfiguration: [OCR](OCR.md).

## Inbox-Aufträge

Ablauf, Zustände, Datenbanksperren und Wiederanlauf: [Stapelverarbeitung](STAPELVERARBEITUNG.md). Fertige Ergebnisse bleiben bis zur expliziten Neuerkennung erhalten; Regeländerungen lösen keine automatische Überschreibung aus.
