# Entwicklung und Tests

Diese Seite beschreibt aktuelle Prüfverfahren, keine früheren Freigabeprotokolle. Automatisierte Tests ersetzen nicht die Prüfung der NAS-Mounts, Rechte, PHP-/MariaDB-Verbindung und Browserbedienung.

## Arbeitsmodell

`develop` enthält die Weiterentwicklung, `main` den freigegebenen Stand. Web, Backend und Worker gemeinsam ändern, versionieren und prüfen. Für schreibende Vorgänge die eindeutige fachliche Verantwortung gemäß [Architektur](ARCHITEKTUR.md) erhalten. Zugangsdaten und Dokumentdaten nicht ins Repository aufnehmen.

Markdown ist die maßgebliche Dokumentation. Verhalten in der zuständigen Fachseite ändern und den Einstieg unter [docs/README.md](README.md) aktuell halten. Andere Seiten sollen auf die ausführliche Erklärung verweisen. Historische Ergebnisse gehören nicht als vermeintlich aktuelle Freigabe in die Anleitungen.

## Laufzeit und Abhängigkeiten

Die Dockerfiles definieren PHP 8.3 und Python 3.11. `backend/requirements.in` nennt direkte Laufzeitabhängigkeiten; `requirements.txt` enthält fixierte direkte/transitive Versionen und Hashes. Der Build installiert mit `--require-hashes`. Für Tests kommen unter anderem `httpx2`, `linkedom` und Playwright hinzu; sie sind keine zusätzlichen Produktionsdienste.

Abhängigkeiten bewusst aktualisieren und danach prüfen, beispielsweise mit installiertem `uv`:

```bash
uv pip compile --python-version 3.11 --generate-hashes backend/requirements.in -o backend/requirements.txt
```

Docker-Basisimages und Betriebssystempakete sind trotz fixierter Python-Pakete beweglich. Für einen exakt reproduzierbaren Wiederherstellungsstand die tatsächlich getesteten Images zusätzlich sichern.

## Automatisierte Prüfungen

Befehle aus dem Repository-Hauptverzeichnis ausführen, sofern nicht anders angegeben. Eine getrennte Entwicklungsumgebung mit den erforderlichen Abhängigkeiten verwenden.

| Prüfung | Befehl | Voraussetzung |
| --- | --- | --- |
| Backend-Unit-/API-Tests | `PYTHONPATH=backend python -m unittest discover -s backend/tests -v` | Backend-Abhängigkeiten und `httpx2` |
| Deployment-Zielprüfung | `powershell -NoProfile -File tests/deployment_checks.ps1` | Windows PowerShell 5.1 oder PowerShell 7 (`pwsh` statt `powershell`) |
| Größenlimits (verbindlich vor Deployment) | `PHP_BIN=php python tests/deployment_limits.py -v` | PHP 8.3 CLI |
| Gemeinsamer HTTP-Client | `PHP_BIN=php python tests/backend_client_integration.py -v` | PHP CLI |
| Inbox-Statusabfragen | `node --test tests/inbox_status.test.cjs` | Node |
| Explorerlogik | `node --test tests/explorer.test.cjs` | Node |
| Status und Rücksprung aus Klassifizierung/Bearbeitung | `node --test tests/status_toggle.test.cjs tests/classification_draft.test.cjs` | Node |
| Sammelauswahl und Batchsteuerung | `node --test tests/archive_maintenance_dom.test.cjs` | Node und `linkedom` |
| Explorer im Browser | `node --test tests/explorer_browser.test.cjs` | Playwright und Chromium |
| PHP-Helfer | `php tests/php_helpers.php` | PHP CLI |
| Archiv-PDF-Pfadbegrenzung | `php tests/archiv_pdf_helpers.php` | PHP CLI |
| Dokumentprotokoll und Rollback | `php tests/document_history.php` | PHP CLI |
| Restore-Vorprüfung | `php tests/restore_preflight.php` | PHP CLI |
| PHP-/JSON-API-Verbindung | `python tests/metadata_php_integration.py -v` | Backend-Abhängigkeiten, `httpx2`, PHP CLI |
| Inbox und Worker | `PHP_BIN=php EABLAGE_TEST_DB_PORT=13316 python tests/inbox_integration.py -v` | Wegwerf-MariaDB, PHP mit PDO-MySQL, Poppler, reportlab und Backend-Testabhängigkeiten |
| OCR | `python tests/ocr_integration.py -v` | Poppler, Tesseract mit deu+eng, Pillow, reportlab und Backend-Abhängigkeiten |
| SQL-Schema und DATE-Konvertierung | `python tests/document_schema_integration.py -v` | Wegwerf-MariaDB auf `127.0.0.1`, `EABLAGE_TEST_DB_PORT` |
| Echte Archivierung, Bearbeitung und Rollback | `python tests/archive_integration.py` | Wegwerf-MariaDB auf `127.0.0.1`, PHP mit PDO-MySQL, Backend-Abhängigkeiten, `httpx2` |

Gezielte Backendtests lassen sich aus `backend/` mit `python -m unittest discover -s tests -p 'test_metadata*.py' -v` beziehungsweise dem Namen der gewünschten Testdatei ausführen. Sie decken unter anderem Format/API, Dateiprüfung, JSON-Reparatur, Stammdaten und Export ab.

Die Deploymenttests führen beide Skripte mit temporären Quell-/Zielverzeichnissen und simuliertem Git/Robocopy aus. Sie prüfen gültige und fehlende/falsche/doppelte Instanzkennungen, Abbruch vor dem Kopieren, frei wählbare Datenpfade und den Erhalt lokaler Konfigurationen. Sie ersetzen keinen echten Windows-/NAS-Kopiertest.

Für DOM-/Browsertests in einer getrennten Testumgebung `linkedom` und `playwright` installieren. `CHROMIUM_EXECUTABLE_PATH` kann auf ein vorhandenes Chromium zeigen. `node --test tests/*.test.cjs` setzt alle Testabhängigkeiten voraus und verwendet keinen produktiven NAS-Dokumentbestand.

Die MariaDB-Integrationstests erstellen zufällig benannte lokale Testdatenbanken und entfernen diese anschließend. Nur einen bewusst bereitgestellten entbehrlichen Dienst verwenden, niemals den Produktionsdienst. `EABLAGE_TEST_DB_PORT` aktiviert den Test; optional ist `EABLAGE_TEST_DB_PASSWORD`. Der Archivintegrationstest akzeptiert zusätzlich `PHP_BIN`. Beispiel bei einem eigens bereitgestellten lokalen Dienst:

```bash
EABLAGE_TEST_DB_PORT=13316 python tests/document_schema_integration.py -v
EABLAGE_TEST_DB_PORT=13316 python tests/archive_integration.py
```

Ein wegen fehlender Voraussetzungen übersprungener Test ist keine erfolgreiche Funktionsprüfung. Ausgeführte, übersprungene und fehlgeschlagene Prüfungen mit Commit und Umgebung getrennt dokumentieren.

## Funktionsprüfung in einer getrennten Instanz

Mit gesicherten, entbehrlichen Testdaten prüfen:

1. **Installation und Trennung:** PHP/Backend/DB erreichbar, getrennte Ports und Datenpfade, keine Änderung im Produktivbestand.
2. **Eingang:** Einzelupload und Drag & Drop; Originalname erhalten, gleichnamiges Ziel geschützt; Bild-PDF und PDF mit Textschicht unterscheiden.
3. **Klassifizierung:** Datum, E-/K-Reihenfolge, mehrere K-Treffer, Ausschlussbegriffe und Textvergleich. Feldherkunft und leeres Datum prüfen.
4. **Rücksprung:** Regel-/Vergleichs-Badge öffnen und zurückkehren; ungespeicherte Werte, zweite Kategorie, Ansicht und Scrollposition bleiben erhalten.
5. **Archivierung:** Genau ein Datensatz, Archiv-PDF und JSON Schema 3; Eingang erst nach Erfolg entfernt. Originalname, Hash, Archivierungsdatum und zunächst leeres Änderungsdatum vergleichen.
6. **Fehlerfälle:** Vorhandenes Archiv-/Metaziel, unbeschreibbarer Metaordner und nicht erreichbares Backend kontrolliert nachstellen. Keine verlorene Eingangskopie oder Überschreibung fremder Dateien. Zwei parallele Archivierungen dürfen keine gemeinsame DokID erhalten.
7. **Bearbeitung:** Echte Änderung aktualisiert DB/JSON und Änderungsdatum; PDF, Originalname, Hash und Archivierungsdatum bleiben gleich. Das Info-Feld bleibt auch bei Änderungen unveränderter Altbestand. Unverändertes Speichern erzeugt weder Protokolleintrag noch neuen Zeitstempel. Text neu einlesen erhält andere Formulareingaben; Neuklassifizierung schließt sich selbst als Vorlage aus.
8. **Recherche und Explorer:** Kombinierte Such-/Datums-/Kategorie-/Typfilter, zweite Kategorie, Volltext und Seitensteuerung. Explorerordner, Metadatenfilter einschließlich Unterordnern, Datumssortierung, Rückkehr nach geänderter Zuordnung, Zurück/Vorwärts und mehr als 100 Einträge prüfen. Mobile PDFs separat öffnen.
9. **Regeln und Stammdaten:** Anlegen, fachliche Version, Test ohne Statistikänderung, Status und mobile Übersicht. Verwendung, Deaktivierungs-/Löschsperren, inaktive bestehende Zuordnung und Reaktivierung prüfen.
10. **Export:** Beide Namensmodi, fehlender Originalname, gleiche Namen, Umlaute/lange Namen und fünf Ebenen mit Nummer/Jahr/Monat. Gegen ein leeres Exportziel vergleichen; Archiv unverändert.
11. **JSON und Archivprüfung:** Fehlende/abweichende JSON-Datei selektiv reparieren, unbekannte Version schützen, verwaiste Datei nur melden. Schema 1/2 lesen und bei echter Bearbeitung Schema 3 ohne Info-Feld schreiben. TXT unverändert erhalten.
12. **Prüfsumme:** Neue PDF entspricht Referenz; Bestands-PDF ohne Referenz wird separat gezählt. Nur an gesicherter Test-PDF Bytes ändern und Hashabweichung prüfen; danach Datei aus Sicherung zurückholen, Referenz nicht anpassen.
13. **Sicherung/Rücksicherung:** Kompatiblen Dump erzeugen, leere Installation mit zugehörigen PDFs/Metadaten befüllen und Archivprüfung ausführen. Parameterübernahme, Ablehnung älterer Dumps und Sperre bei gefüllter Dokumenttabelle prüfen. DDL-Fehlerfall nur im entbehrlichen Bestand nachstellen.
14. **4.1.0-Migration und Webaktionen:** In einem 4.0.0-Testbestand erst `GeloeschtAm` ergänzen und dann die drei alten AutoKat-Spalten entfernen; aktuelle Regeln bleiben erhalten. Schreiben/Sichern mit gültigem Formular, abgewiesene Archivierung oder Rücksicherung ohne `aktion_token` und Neuladen alter Formulare prüfen.
15. **Inbox:** Worker-Vorbereitung und sofortige manuelle Klassifizierung prüfen. Unveränderte fertige PDFs werden im laufenden Worker nicht neu gelesen; eine geleerte Auftragstabelle und geänderte PDFs werden erkannt. Lesefehler, Wiederholung und inzwischen deaktivierte Vorschlagswerte prüfen.
16. **Formularkonflikte:** Dokument in zwei Fenstern öffnen. Nach Speicherung im ersten darf das zweite dessen Änderungen weder beim Speichern noch über Hilfsaktionen überschreiben.
17. **Regelvorbereitung:** Ohne Regeltreffer und bei reinen E-Regeln „Speichern und Regel vorbereiten“ im Drei-Punkte-Menü prüfen; manuell vervollständigte Werte müssen im inaktiven K-Regelentwurf stehen. Bei einer erkannten K-Regel bleibt die Aktion ausgeblendet.
18. **Versionsstand und Rücksicherung:** Oberfläche und `/health` melden 4.3.0. Neue SQL-Sicherungen tragen 4.3.0; gültige 4.1.0-, 4.2.0- und 4.3.0-Dumps sind importierbar, ungültige Schemata und nicht unterstützte Kennungen werden vor Änderungen abgewiesen.
19. **Dokumenthistorie:** [Funktionsprüfung](DOKUMENTHISTORIE.md#funktionsprüfung) einschließlich Ereignis-Rollback und neuer/alter Rücksicherungen durchführen. PHP-Prüfungen: `php tests/document_history.php` und `php tests/restore_preflight.php`; MariaDB-Ende-zu-Ende-Prüfung mit dem vorhandenen `tests/archive_integration.py` in einer Wegwerfinstanz.
20. **Historienrücksprung:** Aus der Bearbeitung die Historie öffnen, filtern und blättern; **Zurück** erhält ungespeicherte Metadaten, neu eingelesenen Text und das ursprüngliche Rücksprungziel. Der Ausgangsstand wird nicht stillschweigend gespeichert.
21. **Ausdrückliche OCR:** In Klassifizierung und Bearbeitung eine PDF mit fehlerhafter vorhandener Textschicht vollständig neu erkennen. In der Klassifizierung Bestätigung, Ersetzung ungespeicherter Eingaben, neue Vorschläge und erneuerten Inbox-Cache prüfen; ein normaler Folgeaufruf muss das neue Ergebnis verwenden. Bei belegter Dateisperre nach Abschluss ausdrücklich erneut starten. In der Bearbeitung bleiben andere Formularwerte erhalten und neuer Text wird erst beim Speichern übernommen. Originalbytes und Prüfsumme bleiben unverändert.
22. **Reiter und Protokolldarstellung:** Stammdatenlisten über beide Reiter wechseln und den aktiven Bereich auch ohne JavaScript erkennen. Ereigniszeilen mit und ohne Dokumentlink sind linksbündig; Details sind zunächst eingeklappt und lassen sich per Maus und Tastatur öffnen.


Während einer Sicherung oder eines Wartungslaufs keine externen Dateiänderungen, Wiederherstellungen oder unkoordinierten DB-Schreibzugriffe durchführen. Nach Abbruch zuerst den tatsächlichen Stand prüfen.


Für die Entfernung von `Dateipfad` zusätzlich das Bestandsupdate mit kanonischen Windows-/Linux-Pfaden, leeren Pfaden, Papierkorbdokumenten und abweichenden Dateinamen testen. Nach Migration und Wiederholung darf die Spalte nicht mehr vorhanden sein. Die Schema- und Archiv-Integrationstests decken diese Fälle sowie Anzeige, Suche, Bearbeitung und endgültiges Löschen ohne die Spalte ab. Die Archivtests verwenden gültige CSRF-Tokens und behalten HTTP-Sitzungen bei.

Die OCR-Integration prüft gemischte Seiten, die vollständige Neuerkennung trotz vorhandener fehlerhafter Textschicht, unveränderte Originalbytes, Deaktivierung, fehlende Sprachpakete, Seiten- und Prozesszeitlimits. `backend/tests/test_inbox_ocr.py` prüft API-Parameter, Cache-Erneuerung sowie gesperrte, archivierte und fehlgeschlagene Aufträge ohne echte Datenbank. Die Inbox-Integration prüft zusätzlich die OCR-Erneuerung vorbereiteter Ergebnisse mit anschließender Cache-Nutzung, echte Sperren, Wiederanlauf, Dateiaustausch, PHP-Klassifizierung, manuelle Archivierung nach Lesefehlern, Statusfilterung und das Überspringen unveränderter Dateien.
