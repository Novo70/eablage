# Entwicklung und Tests

Diese Seite beschreibt aktuelle Prüfverfahren, keine früheren Freigabeprotokolle. Automatisierte Tests ersetzen nicht die Prüfung der NAS-Mounts, Rechte, PHP-/MariaDB-Verbindung und Browserbedienung.

## Arbeitsmodell

`develop` enthält die Weiterentwicklung, `main` den freigegebenen Stand. Web, Backend und Worker gemeinsam ändern, versionieren und prüfen. Für schreibende Vorgänge die eindeutige fachliche Verantwortung gemäß [Architektur](ARCHITEKTUR.md) erhalten. Zugangsdaten und Dokumentdaten nicht ins Repository aufnehmen.

Markdown ist die maßgebliche Dokumentation. Verhalten in der zuständigen Fachseite ändern und den Einstieg unter [docs/README.md](README.md) aktuell halten. Andere Seiten sollen auf die ausführliche Erklärung verweisen. Historische Ergebnisse gehören nicht als vermeintlich aktuelle Freigabe in die Anleitungen.

## Laufzeit und Abhängigkeiten

Die Dockerfiles definieren PHP 8.3 und Python 3.11. `backend/requirements.in` nennt direkte Laufzeitabhängigkeiten; `requirements.txt` enthält fixierte direkte/transitive Versionen und Hashes. Der Build installiert mit `--require-hashes`. Für Tests kommen unter anderem `httpx2`, `jsdom`, `linkedom` und Playwright hinzu; sie sind keine zusätzlichen Produktionsdienste.

Abhängigkeiten bewusst aktualisieren und danach prüfen, beispielsweise mit installiertem `uv`:

```bash
uv pip compile --python-version 3.11 --generate-hashes backend/requirements.in -o backend/requirements.txt
```

Docker-Basisimages und Betriebssystempakete sind trotz fixierter Python-Pakete beweglich. Für einen exakt reproduzierbaren Wiederherstellungsstand die tatsächlich getesteten Images zusätzlich sichern.

## Automatisierte Prüfungen

Befehle aus dem Repository-Hauptverzeichnis ausführen, sofern nicht anders angegeben. Eine getrennte Entwicklungsumgebung mit den erforderlichen Abhängigkeiten verwenden.

| Prüfung | Befehl | Voraussetzung |
| --- | --- | --- |
| Backend-Unit-/API-Tests | `PYTHONPATH=backend python -m pytest backend/tests -q` | Backend-Abhängigkeiten, `pytest` und `httpx2` |
| Deployment-Zielprüfung | `powershell -NoProfile -File tests/deployment_checks.ps1` | Windows PowerShell 5.1 oder PowerShell 7 (`pwsh` statt `powershell`) |
| Größenlimits (verbindlich vor Deployment) | `PHP_BIN=php python tests/deployment_limits.py -v` | PHP 8.3 CLI |
| Gemeinsamer HTTP-Client | `PHP_BIN=php python tests/backend_client_integration.py -v` | PHP CLI |
| Inbox-Statusabfragen | `node --test tests/inbox_status.test.cjs` | Node |
| Explorerlogik | `node --test tests/explorer.test.cjs` | Node |
| Status und Rücksprung aus Klassifizierung/Bearbeitung | `node --test tests/status_toggle.test.cjs tests/classification_draft.test.cjs` | Node |
| Sammelauswahl und Batchsteuerung | `node --test tests/archive_maintenance_dom.test.cjs` | Node und `linkedom` |
| Explorer im Browser | `node --test tests/explorer_browser.test.cjs` | Playwright und Chromium |
| Nummern- und Regel-Editor | `node --test tests/document_numbers.test.cjs tests/number_rules.test.cjs` | Node und `jsdom` |
| Nummern-/Stammdaten-/KI-Oberfläche | `node --test tests/document_numbers_browser.test.cjs tests/master_data_browser.test.cjs tests/ollama_browser.test.cjs` | PHP CLI, Playwright und Chromium |
| Nummernmigration | `EABLAGE_TEST_DB_PORT=13316 python -m unittest discover -s tests -p document_numbers_migration.py -v` | Wegwerf-MariaDB |
| Nummern speichern und rücksichern | `EABLAGE_TEST_DB_PORT=13316 python tests/document_numbers_integration.py` | Wegwerf-MariaDB, PHP mit PDO-MySQL |
| PHP-Helfer | `php tests/php_helpers.php` | PHP CLI |
| Archiv-PDF-Pfadbegrenzung | `php tests/archiv_pdf_helpers.php` | PHP CLI |
| Dokumentprotokoll und Rollback | `php tests/document_history.php` | PHP CLI |
| Restore-Vorprüfung | `php tests/restore_preflight.php` | PHP CLI |
| PHP-/JSON-API-Verbindung | `python tests/metadata_php_integration.py -v` | Backend-Abhängigkeiten, `httpx2`, PHP CLI |
| Inbox und Worker | `PHP_BIN=php EABLAGE_TEST_DB_PORT=13316 python tests/inbox_integration.py -v` | Wegwerf-MariaDB, PHP mit PDO-MySQL, Poppler, reportlab und Backend-Testabhängigkeiten |
| OCR | `python tests/ocr_integration.py -v` | Poppler, Tesseract mit deu+eng, Pillow, reportlab und Backend-Abhängigkeiten |
| SQL-Schema und DATE-Konvertierung | `python tests/document_schema_integration.py -v` | Wegwerf-MariaDB auf `127.0.0.1`, `EABLAGE_TEST_DB_PORT` |
| Echte Archivierung, Bearbeitung und Rollback | `python tests/archive_integration.py` | Wegwerf-MariaDB auf `127.0.0.1`, PHP mit PDO-MySQL, Backend-Abhängigkeiten, `httpx` |

Gezielte Backendtests lassen sich aus `backend/` mit `python -m unittest discover -s tests -p 'test_metadata*.py' -v` beziehungsweise dem Namen der gewünschten Testdatei ausführen. Sie decken unter anderem Format/API, Dateiprüfung, JSON-Reparatur, Stammdaten und Export ab.

Die Deploymenttests führen beide Skripte mit temporären Quell-/Zielverzeichnissen und simuliertem Git/Robocopy aus. Sie prüfen gültige und fehlende/falsche/doppelte Instanzkennungen, Abbruch vor dem Kopieren, frei wählbare Datenpfade und den Erhalt lokaler Konfigurationen. Sie ersetzen keinen echten Windows-/NAS-Kopiertest.

Für DOM-/Browsertests in einer getrennten Testumgebung `jsdom`, `linkedom` und `playwright` installieren. `CHROMIUM_EXECUTABLE_PATH` (Explorer) und `PLAYWRIGHT_EXECUTABLE_PATH` (übrige Browserprüfungen) können auf ein vorhandenes Chromium zeigen. `node --test tests/*.test.cjs` setzt alle Testabhängigkeiten voraus und verwendet keinen produktiven NAS-Dokumentbestand.

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
5. **Archivierung:** Genau ein Datensatz, Archiv-PDF und JSON Schema 4; Eingang erst nach Erfolg entfernt. Originalname, Hash, Archivierungsdatum und zunächst leeres Änderungsdatum vergleichen.
6. **Fehlerfälle:** Vorhandenes Archiv-/Metaziel, unbeschreibbarer Metaordner und nicht erreichbares Backend kontrolliert nachstellen. Keine verlorene Eingangskopie oder Überschreibung fremder Dateien. Zwei parallele Archivierungen dürfen keine gemeinsame DokID erhalten.
7. **Bearbeitung:** Echte Änderung aktualisiert DB/JSON und Änderungsdatum; PDF, Originalname, Hash und Archivierungsdatum bleiben gleich. Das Info-Feld bleibt auch bei Änderungen unveränderter Altbestand. Unverändertes Speichern erzeugt weder Protokolleintrag noch neuen Zeitstempel. Text neu einlesen erhält andere Formulareingaben; Neuklassifizierung schließt sich selbst als Vorlage aus.
8. **Recherche und Explorer:** Kombinierte Such-/Datums-/Kategorie-/Typfilter, zweite Kategorie, Volltext und Seitensteuerung. Explorerordner, Metadatenfilter einschließlich Unterordnern, Datumssortierung, Rückkehr nach geänderter Zuordnung, Zurück/Vorwärts und mehr als 100 Einträge prüfen. Mobile PDFs separat öffnen.
9. **Regeln und Stammdaten:** Anlegen, fachliche Version, Test ohne Statistikänderung, Status und mobile Übersicht. Verwendung, Deaktivierungs-/Löschsperren, inaktive bestehende Zuordnung und Reaktivierung prüfen.
10. **Export:** Beide Namensmodi, fehlender Originalname, gleiche Namen, Umlaute/lange Namen und fünf Ebenen mit Nummer/Jahr/Monat. Gegen ein leeres Exportziel vergleichen; Archiv unverändert.
11. **JSON und Archivprüfung:** Fehlende/abweichende JSON-Datei selektiv reparieren, unbekannte Version schützen, verwaiste Datei nur melden. Schema 1/2/3 lesen und bei echter Bearbeitung Schema 4 ohne Info-Feld schreiben. TXT unverändert erhalten.
12. **Prüfsumme:** Neue PDF entspricht Referenz; Bestands-PDF ohne Referenz wird separat gezählt. Nur an gesicherter Test-PDF Bytes ändern und Hashabweichung prüfen; danach Datei aus Sicherung zurückholen, Referenz nicht anpassen.
13. **Sicherung/Rücksicherung:** Kompatiblen Dump erzeugen, leere Installation mit zugehörigen PDFs/Metadaten befüllen und Archivprüfung ausführen. Parameterübernahme, Ablehnung älterer Dumps und Sperre bei gefüllter Dokumenttabelle prüfen. DDL-Fehlerfall nur im entbehrlichen Bestand nachstellen.
14. **4.1.0-Migration und Webaktionen:** In einem 4.0.0-Testbestand erst `GeloeschtAm` ergänzen und dann die drei alten AutoKat-Spalten entfernen; aktuelle Regeln bleiben erhalten. Schreiben/Sichern mit gültigem Formular, abgewiesene Archivierung oder Rücksicherung ohne `aktion_token` und Neuladen alter Formulare prüfen.
15. **Inbox:** Worker-Vorbereitung und sofortige manuelle Klassifizierung prüfen. Unveränderte fertige PDFs werden im laufenden Worker nicht neu gelesen; eine geleerte Auftragstabelle und geänderte PDFs werden erkannt. Lesefehler, Wiederholung und inzwischen deaktivierte Vorschlagswerte prüfen.
16. **Formularkonflikte:** Dokument in zwei Fenstern öffnen. Nach Speicherung im ersten darf das zweite dessen Änderungen weder beim Speichern noch über Hilfsaktionen überschreiben.
17. **Regelvorbereitung:** Ohne Regeltreffer und bei reinen E-Regeln „Speichern und Regel vorbereiten“ im Drei-Punkte-Menü prüfen; manuell vervollständigte Werte müssen im inaktiven K-Regelentwurf stehen. Bei einer erkannten K-Regel bleibt die Aktion ausgeblendet.
18. **Versionsstand und Rücksicherung:** Oberfläche und `/health` melden 4.4.0. Neue SQL-Sicherungen tragen 4.4.0; gültige 4.1.0-, 4.2.0-, 4.3.0- und 4.4.0-Dumps sind importierbar, ungültige Schemata und nicht unterstützte Kennungen werden vor Änderungen abgewiesen.
19. **Dokumenthistorie:** [Funktionsprüfung](DOKUMENTHISTORIE.md#funktionsprüfung) einschließlich Ereignis-Rollback und neuer/alter Rücksicherungen durchführen. PHP-Prüfungen: `php tests/document_history.php` und `php tests/restore_preflight.php`; MariaDB-Ende-zu-Ende-Prüfung mit dem vorhandenen `tests/archive_integration.py` in einer Wegwerfinstanz.
20. **Historienrücksprung:** Aus der Bearbeitung die Historie öffnen, filtern und blättern; **Zurück** erhält ungespeicherte Metadaten, neu eingelesenen Text und das ursprüngliche Rücksprungziel. Der Ausgangsstand wird nicht stillschweigend gespeichert.
21. **Ausdrückliche OCR:** In Klassifizierung und Bearbeitung eine PDF mit fehlerhafter vorhandener Textschicht vollständig neu erkennen. In der Klassifizierung Bestätigung, Ersetzung ungespeicherter Eingaben, neue Vorschläge und erneuerten Inbox-Cache prüfen; ein normaler Folgeaufruf muss das neue Ergebnis verwenden. Bei belegter Dateisperre nach Abschluss ausdrücklich erneut starten. In der Bearbeitung bleiben andere Formularwerte erhalten und neuer Text wird erst beim Speichern übernommen. Originalbytes und Prüfsumme bleiben unverändert.
22. **Reiter und Protokolldarstellung:** Stammdatenlisten über beide Reiter wechseln und den aktiven Bereich auch ohne JavaScript erkennen. Ereigniszeilen mit und ohne Dokumentlink sind linksbündig; Details sind zunächst eingeklappt und lassen sich per Maus und Tastatur öffnen.


Während einer Sicherung oder eines Wartungslaufs keine externen Dateiänderungen, Wiederherstellungen oder unkoordinierten DB-Schreibzugriffe durchführen. Nach Abbruch zuerst den tatsächlichen Stand prüfen.


Für die Entfernung von `Dateipfad` zusätzlich das Bestandsupdate mit kanonischen Windows-/Linux-Pfaden, leeren Pfaden, Papierkorbdokumenten und abweichenden Dateinamen testen. Nach Migration und Wiederholung darf die Spalte nicht mehr vorhanden sein. Die Schema- und Archiv-Integrationstests decken diese Fälle sowie Anzeige, Suche, Bearbeitung und endgültiges Löschen ohne die Spalte ab. Die Archivtests verwenden gültige CSRF-Tokens und behalten HTTP-Sitzungen bei.

Die OCR-Integration prüft gemischte Seiten, die vollständige Neuerkennung trotz vorhandener fehlerhafter Textschicht, unveränderte Originalbytes, Deaktivierung, fehlende Sprachpakete, Seiten- und Prozesszeitlimits. `backend/tests/test_inbox_ocr.py` prüft API-Parameter, Cache-Erneuerung sowie gesperrte, archivierte und fehlgeschlagene Aufträge ohne echte Datenbank. Die Inbox-Integration prüft zusätzlich die OCR-Erneuerung vorbereiteter Ergebnisse mit anschließender Cache-Nutzung, echte Sperren, Wiederanlauf, Dateiaustausch, PHP-Klassifizierung, manuelle Archivierung nach Lesefehlern, Statusfilterung und das Überspringen unveränderter Dateien.

## Ollama-Prüfungen

Die automatisierten Prüfungen und der ergänzende NAS-/PC-Praxistest stehen unter [Ollama](OLLAMA.md#technische-prüfung). Es werden keine zusätzlichen Python-Laufzeitabhängigkeiten benötigt; der HTTP-Transport verwendet die Standardbibliothek.

## Stammdaten-Erläuterungen prüfen

Backend-Tests prüfen optionale und mehrzeilige Werte, Längenbegrenzung, gebundene Schreibwerte, Löschen der Erläuterung und Erhalt bei älteren Umbenennungsaufrufen. Ollama-Tests prüfen die Übergabe von Name/Erläuterung, leere Texte, aktive Einträge, veränderte Stammdaten und den weiterhin fehlenden Kategorie2-Vorschlag. `tests/master_data_browser.test.cjs` rendert die tatsächliche PHP-Seite mit simuliertem Backend und prüft beide Stammdatenbereiche, sichere Textdarstellung und gemeinsames Speichern/Löschen der Erläuterung. `tests/restore_preflight.php` prüft die Ergänzung älterer Tabellenstrukturen, ohne aktuelle Erläuterungen zu verändern.

```bash
node --test tests/master_data_browser.test.cjs
php tests/restore_preflight.php
```

Die tatsächliche MariaDB-Migration kann mit der bestehenden lokalen Wegwerf-Testdatenbank über `tests/document_schema_integration.py` geprüft werden. `EABLAGE_TEST_DB_PORT` aktiviert diesen Test ausschließlich gegen localhost; er legt eine zufällig benannte Testdatenbank an und entfernt sie anschließend. Er prüft die wiederholte Migration und den Erhalt bestehender Stammdaten und Erläuterungen. Ohne lokale MariaDB wird dieser Integrationstest übersprungen. Vor Freigabe in der Testinstallation außerdem Sicherung und Rücksicherung sowie das reale Ollama-Modell mit eigenen Beispielen prüfen.

## Bearbeitbare KI-Anweisung prüfen

Backend-Tests prüfen Standard-/Leerwertverhalten, erneutes Lesen aus der Datenbank, unveränderte technische Vorgaben, Kontextübergabe, Längenbegrenzung und die Ablehnung einer während der Anfrage geänderten Anweisung. `tests/ollama_settings_integration.py` führt die tatsächliche PHP-Einstellungsseite mit einer simulierten Datenbank aus und prüft sichere mehrzeilige Darstellung, Unicode-Texte über 255 Zeichen, Leerwerte und das Zurückweisen ungültiger Eingaben ohne Speicherung. `tests/restore_preflight.php` prüft die Erweiterung älterer Parameterdefinitionen ohne Änderung gespeicherter Texte. Der lokale MariaDB-Test in `tests/document_schema_integration.py` prüft Migration und Erhalt bereits angepasster Anweisungen; ohne Testdatenbank wird er übersprungen. Der fachliche Erfolg der Anweisung muss mit dem realen Ollama-Modell geprüft werden.

```bash
python tests/ollama_settings_integration.py
```

## Diagnose der KI-Anfrage prüfen

Backend-Tests vergleichen die Diagnose mit dem tatsächlich an den HTTP-Transport übergebenen Request und dessen JSON-Serialisierung. Fehler nach Erfassung erhalten dieselbe Aufnahme. Browserprüfungen verwenden den tatsächlichen PHP-Markupbereich und prüfen Anzeige ohne neue Netzwerkanfrage, unveränderte Formularwerte, ursprüngliche Werte nach späteren Änderungen, sichere Textdarstellung, Zwischenablage-Fallback und Diagnose nach Modellfehlern. Die PHP-Brücke erhält Fehlertext und Diagnose gemeinsam. Reale Modellvergleiche stehen unter [Ollama](OLLAMA.md#tatsächliche-ki-anfrage-anzeigen).

## Hintergrund-KI prüfen

`backend/tests/test_ollama_background.py` prüft die drei Betriebsarten, Klassifikations-/Ergänzungsregeln, Textvergleich, Kontextveraltung, gemeinsame Generierungssperre, begrenzten Cacheinhalt und fortlaufende Inbox-Prüfung während blockierter KI. Die Browserprüfungen testen Nachladen ohne Generierung, Erhalt bearbeiteter/geleerter Felder, Ausblenden und Vorrang eines manuellen Abrufs. Die PHP-Brücke bindet Hintergrundabrufe an die Anwendungsdatenbank und geprüfte Dokumentidentität. `tests/ollama_background_integration.py` prüft mit `EABLAGE_TEST_DB_PORT` in einer lokalen Wegwerf-MariaDB wiederholbare Migration, tatsächliche Sperren, Wiederaufnahme und revisionsgebundene Schreiboperationen nach Archivierung/OCR-Wechsel; ohne lokale MariaDB wird dieser Test übersprungen. Vor Freigabe Worker, reales Ollama, Erreichbarkeitsfehler und Sicherung/Rücksicherung in der Testinstallation prüfen.

Die Ollama-Diagnoseprüfungen decken außerdem numerische Laufzeit-/Tokenmetadaten bei Erfolg, unvollständiger und ungültiger Antwort ab. Nichtnumerische, negative oder übergroße Werte werden nicht weitergegeben. Browserprüfungen prüfen Nanosekundenumrechnung, Tokens pro Sekunde, Null-/fehlende Werte und sichere Darstellung. Messwerte ändern weder Anfrage-JSON noch Generierungsparameter; Denk- und Antworttext werden dafür nicht gespeichert.

KI-Textauswahl: Backendprüfungen decken Default-/konfigurierte Grenzen, Anfang ohne Ende, kurze Dokumente, Grenzlängen ohne Überlappung, Unicode, ungültige Werte, Änderung während Generierung, Cache-Kennzeichnung und Diagnose ab. Die tatsächliche PHP-Einstellungsseite prüft kombinierte Grenzen und teilweise Änderungen einschließlich Rollback. Der MariaDB/MySQL-Integrationstest prüft wiederholbare Migration, Erhalt bestehender Vorschläge und Versuchzeitpunkte sowie automatische Neueinplanung nach Parameteränderung; ohne lokale Testdatenbank wird er übersprungen. Die Browserprüfung zeigt die tatsächlich verwendete Auswahl in der Diagnose.


## Rückfall ohne Denkmodus prüfen

`backend/tests/test_ollama_fallback.py` prüft einen einzelnen sequenziellen Rückfall nach Tokenlimit, das Ausbleiben eines dritten Versuchs, unveränderten Prompt/Schema/Formularkontext, veraltete Dokumente, erhaltene Diagnose je Versuch und Verbindungsfehler. Ein lokaler HTTP-Test simuliert die Nutzzeitüberschreitung mit bestätigtem Ende innerhalb des Nachlaufs. Ein harter Timeout ohne Endbestätigung darf keinen zweiten Auftrag erzeugen. Hintergrundtests prüfen dauerhafte Fehlerkennzeichnung, Erhalt vorübergehender Wiederholungen und erneute Verarbeitung nach Kontextänderungen. Status- und Browsertests prüfen Warteschlangenpositionen, Rückfallhinweise, Diagnose und Ende des Pollings bei endgültigen Fehlern. Die MariaDB-Integrationssuite enthält eine Prüfung der gespeicherten Endfehler über mehrere Workeraufrufe.

Vor Freigabe mit realem Ollama prüfen: Denkversuch am Tokenlimit, erfolgreicher/gescheiterter Rückfall, Zeitüberschreitung, Nichterreichbarkeit und manuelle Korrektur während der Wartezeit. Der Branch basiert auf `develop` und enthält keine Änderungen der fachlichen Prompts aus der unabhängigen Klassifizierungsvariante. Webimage einschließlich Apache-Konfiguration neu bauen; Backend und Worker gemeinsam aktualisieren. Kein SQL-Update erforderlich.

## Gespeicherte KI-Durchlaufhistorie prüfen

`ollama_history.py` begrenzt die gespeicherten Versuche auf zwei und übernimmt ausschließlich boolesche Angaben, feste Status-/Abschlusswerte und begrenzte nichtnegative Ganzzahlen. `test_ollama_history.py` prüft Altbestand ohne Historie, leere Vorabfehler, Erstversuch-/Fallbackfehler, Erfolg und die Entfernung beliebiger Text-/Requestdaten. Fallback- und Cachetests prüfen unterschiedliche Timeoutmeldungen sowie Erhalt der Historie bei Erfolg und Fehler ohne weitere Modellanfrage beim Lesen. Status-/Browsertests prüfen Verlauf in Eingang und Klassifizierung, sichere Textdarstellung, erhaltenen Aufklappzustand beim Polling und Altbestand ohne erfundene Angaben.

Der neue Commit ergänzt den bestehenden Fallback-Branch. Kein SQL-Update; Historie im vorhandenen Cache-JSON. Manuelle Request-Diagnosen bleiben flüchtig. Bestehende Fehler ohne Metadaten lassen sich nicht nachträglich rekonstruieren und werden durch das Update nicht automatisch erneut verarbeitet. Vor Freigabe Erstversuch-Timeout, Fallback-Timeout und erfolgreichen Rückfall am realen Ollama-PC vergleichen.

## KI-Analyse unter Erkennungsdetails

Browsertests prüfen, dass Eingangseinträge nur kurze KI-Statuslabels anzeigen und keine Verlauf-/Fehlerdetails enthalten. In der Klassifizierung müssen Verlauf, Warnungen und ausführliche Fehler unter `classification-recognition-details` liegen und bei geschlossenem Bereich unsichtbar bleiben, auch bei deaktivierter Wartungsdiagnose. Fehler am manuellen Aufruf bleiben kurz; der Verweis öffnet die Erkennungsdetails ohne neue Anfrage oder Änderung der Eingaben. Der Wartungsaufruf öffnet ebenfalls diesen Bereich; Kopierfunktion und sichere Textdarstellung bleiben erhalten. Die Datenhaltung, Worker-Wiederholungsregeln und Modellanfragen werden durch diese Anzeigeänderung nicht angepasst.

