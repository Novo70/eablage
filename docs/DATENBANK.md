# Datenbank und Schema

eAblage nutzt MariaDB mit InnoDB und `utf8mb4`. Die Archivtabelle heißt fest `dokumente`; verschiedene Instanzen werden über getrennte Datenbanken und Konfigurationen unterschieden. Diese Referenz beschreibt die aktuelle Struktur und die weiterhin nötigen Wege, vorhandene Bestände darauf zu bringen.

## Tabellen

| Tabelle | Inhalt |
| --- | --- |
| `dokumente` | DokID, Metadaten, Text, Originaldateiname, Zeitstempel, Papierkorbstatus und Referenzprüfsumme |
| `kategorie` | Kategorie-ID, Bezeichnung, optionale Erläuterung, Aktivstatus |
| `doktyp` | Dokumententyp-ID, Bezeichnung, optionale Erläuterung, Aktivstatus |
| `ereignisprotokoll` | Dauerhafte Dokumentereignisse mit Zeitpunkt, Quelle und strukturierten Details |
| `autokat` | Regelidentität, Version, Rang, Such-/Ergebnisfelder und Statistik |
| `parameter` | Schlüssel/Wert, Beschreibung, Gruppe, Rang und Sichtbarkeit |
| `ki_worker_status` | Laufzeitrückmeldung des KI-Workers, aktueller Auftrag und Fehlerpause; kein Bestandteil des Archivbackups |
| `ki_vorschlaege` | Reproduzierbare Hintergrund-KI-Vorschläge mit PDF-Prüfsumme, Revision und Kontext; kein Bestandteil des Archivbackups |
| `verarbeitungsauftraege` | Ungeprüfte Inbox-Ergebnisse, Dateiprüfsumme, Status und Fehler; kein Bestandteil des Archivbackups |

`dokumente.ID` ist die technische Auto-Increment-ID, `DokID` die fachliche ID mit eindeutigem Index. Stammdaten-IDs bleiben Zeichenketten; führende Nullen werden im JSON-Format erhalten. Die höchste vergebene DokID wird beim endgültigen Löschen zusätzlich in `parameter.eablageLetzteDokID` festgehalten, damit IDs nicht wiederverwendet werden. Die Regelzuordnung ist gegenwärtig auf den numerischen Bereich bis 999 begrenzt.

Der Archivdateiname wird ausschließlich aus der positiven `DokID` gebildet: `<DokID>dok.pdf`. Ein PDF-Pfad wird nicht in `dokumente` gespeichert; das Archivverzeichnis kommt aus der Instanzkonfiguration. `autokat` verwendet `Suchbegriffe` und `Ausschlussbegriffe`; die früheren Access-Spalten `Suchbegriff1`, `Suchbegriff2` und `sucheBeenden` gehören nicht mehr zum aktuellen Schema.

Neu angelegte Datenbanken verwenden `Datum DATE`. Aktualisierte Bestände können gültige ISO-Datumswerte in einer Textspalte behalten. `Originaldateiname`, `DateiSHA256`, `Aenderungsdatum` und `GeloeschtAm` sind nullable; unbekannte Bestandswerte werden nicht geraten. Weitere Feldabbildungen: [Dokumentmodell](METADATEN_JSON.md).

## Neuinstallation

Nur in eine leere Datenbank importieren:

1. [schema.sql](../sql/schema.sql): Tabellen und Indizes.
2. [seed.sql](../sql/seed.sql): Standardparameter sowie Beispielkategorien und -dokumenttypen; Dokumente und AutoKat-Regeln bleiben leer.
3. [init_docker_paths.sql](../sql/init_docker_paths.sql): setzt die fünf Anwendungspfade auf `/data/…/`, wenn diese standardmäßige Containerstruktur verwendet wird.

`compose.fresh.yaml` importiert diese Dateien beim ersten Start eines leeren MariaDB-Volumes automatisch. Die [Installation mit separater Datenbank](INSTALL_STANDARD.md) importiert Schema/Seed und setzt die Pfade im beschriebenen Einrichtungsschritt. Containerneustarts führen keine erneute Initialisierung vorhandener Volumes aus.

Schema/Seed niemals über vorhandene Daten importieren. Vorhandene Bestände gezielt aktualisieren oder eine leere Installation aus einer kompatiblen [Sicherung](SICHERUNG.md) befüllen.

## Bestehenden Bestand an das aktuelle Schema anpassen

Vor jedem Schemaeingriff eine zusammengehörige Sicherung erstellen, Zielinstanz prüfen und alle Schreiber stoppen. Den Schema-Ausgangsstand mit `SHOW COLUMNS` kontrollieren. Die folgenden Schritte gelten nur, soweit das jeweilige Merkmal noch fehlt; sie beschreiben keine Bedienung einer älteren Anwendung.

| Fehlendes Merkmal / Ausgangssituation | Aktuell benötigter Schritt |
| --- | --- |
| `parameter.anzeige` fehlt | [update_3.3.0.sql](../sql/update_3.3.0.sql) einmalig; das darin enthaltene ALTER ist nicht wiederholbar |
| `kategorie.Aktiv` oder `doktyp.Aktiv` fehlt | [update_stammdaten.sql](../sql/update_stammdaten.sql); erhält vorhandene Aktivzustände |
| `Originaldateiname`, `DateiSHA256`, `Aenderungsdatum` oder Dateinamensmodi fehlen | [update_document_metadata_4.0.0.sql](../sql/update_document_metadata_4.0.0.sql) |
| Papierkorbspalte `dokumente.GeloeschtAm` fehlt | [update_papierkorb.sql](../sql/update_papierkorb.sql) einmalig auf den 4.0.0-Bestand anwenden |
| Tabelle `ereignisprotokoll` fehlt oder heißt noch `dokument_protokoll` | [update_document_history.sql](../sql/update_document_history.sql); wiederholbar, vor Start der aktualisierten Anwendung |
| Tabelle `verarbeitungsauftraege` fehlt | [update_inbox_processing.sql](../sql/update_inbox_processing.sql); wiederholbar |
| Redundante Spalte `dokumente.Dateipfad` vorhanden | [update_document_path.sql](../sql/update_document_path.sql) gemäß der folgenden Anleitung |
| Alte AutoKat-Spalten vorhanden | [update_autokat_4.1.0.sql](../sql/update_autokat_4.1.0.sql) nach Sicherung und Schreibpause anwenden; wiederholbar |
| Fünfte Gesamtexport-Ebene fehlt | [update_export_structure_4.0.0.sql](../sql/update_export_structure_4.0.0.sql) |
| Nicht mehr verwendete Parameter vorhanden | [update_access_parameters_4.0.0.sql](../sql/update_access_parameters_4.0.0.sql); entfernt nur exakt benannte Parameterzeilen |
| Veralteter Parameter für den Archivtabellennamen vorhanden | [remove_dbTabelleArchiv.sql](../sql/remove_dbTabelleArchiv.sql); lokale Konstante muss `dokumente` lauten |

Bei einem bereits vorbereiteten 3.4.0-Bestand werden die drei `4.0.0`-Skripte für Metadaten, Exportstruktur und Parameterbereinigung in dieser Reihenfolge angewendet. Für 4.1.0 benötigt auch ein freigegebener 4.0.0-Bestand zusätzlich `GeloeschtAm` und die AutoKat-Bereinigung. Für den Schritt von 4.1.0 auf 4.2.0 kommen die Auftragstabelle und die geprüfte Entfernung von `Dateipfad` hinzu. Für den Schritt von 4.2.0 auf 4.3.0 wird `ereignisprotokoll` ergänzt beziehungsweise eine bestehende `dokument_protokoll` umbenannt. Alle benötigten Schema-Updates vor dem Start von Web, Backend und Worker ausführen.

Die wiederholbaren Skripte erhalten bereits vorhandene Werte. SQL-Fehler dennoch nicht ignorieren. Die Kennzeichnung `Version` ist kein Schema-Beweis. Für wesentlich ältere oder abweichende Bestände ist eine individuelle Schema-Prüfung nötig; diese Skripte konvertieren insbesondere kein fremdes Regelmodell automatisch.

Nach Änderung Spalten, Parameter und InnoDB kontrollieren; anschließend Web, Backend und Worker gemeinsam aktualisieren und [testen](ENTWICKLUNG.md). Die AutoKat-Bereinigung entfernt ausschließlich die drei benannten Altspalten; andere unbekannte Spalten erfordern eine gesonderte Prüfung.

## Redundanten Dokumentpfad entfernen

Nach zusammengehöriger Sicherung von Datenbank, PDFs und Metadaten und bei gestoppten Schreibern [update_document_path.sql](../sql/update_document_path.sql) ausführen. Benötigt werden ALTER, CREATE/ALTER ROUTINE und EXECUTE. Das Skript prüft positive DokIDs und vergleicht vorhandene, nicht leere Pfadangaben anhand ihres Windows-/Linux-Basisnamens exakt mit `<DokID>dok.pdf` – auch für Papierkorbdokumente. Abweichungen werden mit DokID ausgegeben; die Spalte bleibt dann erhalten. Dateien werden nicht umbenannt. Abweichende Bestände einschließlich zugehöriger JSON-Dateien zuerst gezielt klären.

Vorher die [Archivprüfung](ARCHIVPRUEFUNG.md) verwenden und sicherstellen, dass die PDFs unter den erwarteten Namen im Archiv liegen. Leere/NULL-Pfadangaben verhindern die Migration nicht, bestätigen aber auch keine vorhandene PDF. Nach Erfolg entfallen Spalte und zugehöriger Index. Wiederholtes Ausführen ist möglich. Anschließend Web, Backend und Worker gemeinsam aktualisieren und Anzeige, Bearbeiten, Archivieren, Export und Papierkorb testen.

Bei Rücksicherung eines unterstützten Dumps, der die alte Spalte enthält, diesen Schritt erneut anwenden: Der Import übernimmt das Schema der Sicherung. Die Anwendung verwendet die zusätzliche Spalte nicht.

## Datum optional auf DATE umstellen

Dieser Schritt ist von der sonstigen Aktualisierung unabhängig. Gültige ISO-Werte in einer vorhandenen Textspalte werden unterstützt. Die Konvertierung verändert keine fachlichen Datumswerte und erfindet keine fehlenden Angaben.

1. Datenbank sichern und alle Schreiber stoppen; die Schreibpause bis zum Ende beibehalten.
2. [update_document_date_4.0.0.sql](../sql/update_document_date_4.0.0.sql) ausführen. Es erzeugt die Hilfsprozedur und startet nur die Prüfung.
3. Gemeldete Werte mit DokID fachlich klären. Leerstrings, Leerzeichen, Nulldaten, falsche Formate und unmögliche Tage führen zum Abbruch mit SQLSTATE 45000. `NULL` ist erlaubt; vorgesehen sind Jahre 1000 bis 9999.
4. Nur nach erfolgreicher Prüfung bei weiter bestehender Schreibpause ausführen:

   ```sql
   CALL eablage_400_document_date(TRUE);
   SHOW COLUMNS FROM dokumente LIKE 'Datum';
   ```

5. Nach Erfolg die Hilfsprozedur entfernen und Dienste starten:

   ```sql
   DROP PROCEDURE eablage_400_document_date;
   ```

Benötigt werden zusätzlich zu ALTER unter anderem CREATE/ALTER ROUTINE, CREATE TEMPORARY TABLES und EXECUTE. DDL ist nicht per ROLLBACK rückgängig zu machen. Kein `--force` oder ungeprüftes ALTER als Ersatz für die Bestandsprüfung. JSON muss bei inhaltlich unverändertem Datum nicht neu erzeugt werden.

## Rechte

PHP, Backend und Worker müssen auf dieselbe Datenbank zeigen. Die mit den Installationsbeispielen angelegten Anwendungsbenutzer erhalten Rechte auf ihre eigene Datenbank, keine globalen Rechte.

Bei einer gezielten Rechtevergabe müssen die tatsächlich verwendeten Vorgänge abgedeckt sein:

- PHP: SELECT auf allen neun Tabellen; Schreiben von Dokumenten, Regeln, Parametern und Trefferstatistik; UPDATE/DELETE auf `verarbeitungsauftraege` für Abschluss, Verwerfen und Rücksicherung sowie DELETE auf `ki_vorschlaege` und `ki_worker_status` bei Rücksicherung. Tabellenstrukturen für Sicherung lesen. Für die Initialrücksicherung zusätzlich CREATE und DROP sowie INSERT auf den wiederhergestellten Tabellen.
- Backend: SELECT auf allen neun Tabellen; INSERT/UPDATE/DELETE auf Kategorien und Dokumenttypen; INSERT/UPDATE auf `verarbeitungsauftraege` sowie SELECT/INSERT/UPDATE/DELETE auf `ki_vorschlaege`; Sperr- und Transaktionszugriffe für die zugehörigen Vorgänge.
- Worker: SELECT auf Dokumenten, Regeln, Parametern und Stammdaten sowie SELECT/INSERT/UPDATE auf `verarbeitungsauftraege` und SELECT/INSERT/UPDATE/DELETE auf `ki_vorschlaege` sowie SELECT/INSERT/UPDATE auf `ki_worker_status`.
- Schemaadministration: ALTER und je Skript die oben genannten zusätzlichen Rechte.

Nicht passende Rechte führen zu Fehlern; sie werden nicht durch Dateirechte oder eine andere Backend-URL behoben. Die Skripte enthalten keine Benutzeranlage für bereits vorhandene externe Datenbankdienste. Rechte und Zugangsdaten dort administrativ passend einrichten.


## Inbox-Aufträge

`verarbeitungsauftraege` speichert ungeprüfte Erkennungsergebnisse getrennt vom Archiv. Auf bestehenden Installationen [update_inbox_processing.sql](../sql/update_inbox_processing.sql) ausführen; Neuinstallationen erhalten die Tabelle mit dem Schema. Die Tabelle wird vom integrierten Archivbackup ausgenommen. [Verhalten und Wiederanlauf](STAPELVERARBEITUNG.md).

## Dokumenthistorie

`ereignisprotokoll` wird beim Archivieren, Bearbeiten und bei Papierkorbaktionen innerhalb derselben InnoDB-Transaktion ergänzt. PHP benötigt dafür INSERT und SELECT; Rücksicherung benötigt zusätzlich DELETE bzw. CREATE/DROP. Es gibt keinen löschenden Fremdschlüssel auf `dokumente`, damit Ereignisse nach endgültiger Löschung erhalten bleiben. Details und Auswertungsbeispiele: [Dokumenthistorie](DOKUMENTHISTORIE.md). `dokumente.Info` wird nur noch als Altbestand gelesen, nicht neu befüllt.

## Optionale Ollama-Parameter

`sql/update_ollama.sql` ergänzt `ollamaAktiv` (Vorgabe `nein`), `ollamaURL` (leer) und `ollamaModell` (`qwen3.5:9b`). Bestehende Werte bleiben erhalten; es werden keine Tabellen oder Dokumentdaten geändert. `seed.sql` enthält dieselben Vorgaben. Nach Rücksicherung eines älteren Backups fehlende Parameter erneut ergänzen. Siehe [Ollama](OLLAMA.md).

## Optionale Stammdaten-Erläuterungen

`kategorie.Erlaeuterung` und `doktyp.Erlaeuterung` sind optionale `VARCHAR(1000)`-Felder mit Vorgabe `NULL`. Die Oberfläche erlaubt mehrzeiligen Text und stellt `NULL` wie eine leere Erläuterung dar. Die Felder dienen der Dokumentation und der lokalen KI; bestehende Dokumentzuordnungen und AutoKat bleiben unverändert.

Nach Datenbanksicherung und bei pausierten Schreibern [update_master_explanations.sql](../sql/update_master_explanations.sql) in der richtigen Anwendungsdatenbank ausführen. Das Skript benötigt ALTER und kann erneut ausgeführt werden; vorhandene Erläuterungen und andere Daten bleiben erhalten. Neuinstallationen enthalten die Felder in `schema.sql`. Die integrierte Rücksicherung ergänzt sie bereits in den CREATE-TABLE-Anweisungen älterer unterstützter Sicherungen; vorhandene Erläuterungen neuer Sicherungen werden übernommen. Der SQL-Dump enthält Struktur und Werte vollständig.

## Bearbeitbare Ollama-Anweisung

`parameter.param_value` verwendet ab dieser unveröffentlichten Erweiterung `TEXT DEFAULT NULL`, damit mehrzeilige Anweisungen länger als 255 Zeichen gespeichert werden können. `sql/update_ollama_instruction.sql` erweitert die Spalte und ergänzt `ollamaAnweisung` mit den Standardvorgaben (Oberflächenlimit 4000 Zeichen). Das Skript ist nach Sicherung und Schreibpause wiederholbar; bestehende Parameterwerte und bereits angepasste Anweisungen bleiben erhalten. Neuinstallationen erhalten Spaltentyp und Parameter aus `schema.sql`/`seed.sql`.

Die integrierte Rücksicherung erweitert VARCHAR-basierte Parameterdefinitionen älterer Sicherungen bereits beim Wiederanlegen der Tabelle. Fehlt `ollamaAnweisung` in einer älteren Sicherung, verwendet das Backend Standards; zum Bearbeiten in den Einstellungen danach das SQL-Update erneut ausführen. Die DDL-Erweiterung benötigt ALTER, die Parametereinrichtung INSERT. Alle vorhandenen Parameter werden weiterhin unverändert verwendet.

## Reproduzierbarer Hintergrund-KI-Cache

`ki_vorschlaege` ist ein aus Eingangsaufträgen abgeleiteter Cache, kein Archivbestand. Der Schlüssel `auftrag_id` verweist logisch auf `verarbeitungsauftraege.id`; PDF-Prüfsumme, Revision und Kontexthash verhindern die Verwendung veralteter Ergebnisse. Modell, validierte Vorschläge und Fehlerstatus werden getrennt vom normalen OCR-/Klassifizierungsergebnis gespeichert. Die Tabelle wird mit `sql/update_ollama_background.sql` wiederholbar angelegt (CREATE-/INSERT-Rechte für das Update); laufender Worker und Backend benötigen SELECT, INSERT, UPDATE und DELETE. Schema/Seed enthalten Tabelle und standardmäßig deaktivierten Parameter. Die Sicherung schließt beide Auftragstabellen aus; Rücksicherung leert vorhandene Caches und blockiert bei laufender KI-Generierung. [Ablauf](OLLAMA.md#ki-im-hintergrund).

`sql/update_ollama_worker_status.sql` ergänzt wiederholbar die separate Laufzeittabelle `ki_worker_status` (CREATE für das Update). Der Worker benötigt SELECT/INSERT/UPDATE, Backend und PHP SELECT; bei Rücksicherung benötigt PHP zusätzlich DELETE. Die Singleton-Zeile wird beim Start angelegt und mit einer Startkennung gegen verspätete Schreibvorgänge des vorherigen Prozesses geschützt. Reine Rückmeldungen verändern weder den Beginn einer Berechnung noch das Ende einer Fehlerpause. Zeitdifferenzen werden mit der Datenbankuhr berechnet. Nach Rücksicherung den Worker neu starten. [Anzeige](OLLAMA.md#hintergrundstatus-erkennen).

`sql/update_ollama_text_selection.sql` ergänzt `ollamaTextAnfang` und `ollamaTextEnde` sowie `ki_vorschlaege.textfenster` (ASCII-Kennzeichnung der Auswahl, z. B. `9000:3000`). Die wiederholbare Migration benötigt CREATE, ALTER und INSERT/UPDATE und erhält existierende Parameter, Vorschläge und Versuchzeitpunkte. Eine Spaltenprüfung über `information_schema` und ein vorbereitetes DDL-Statement vermeiden eine Abhängigkeit von `ADD COLUMN IF NOT EXISTS`. Fehlt die Cachetabelle, wird sie ebenfalls angelegt. Die laufende Anwendung benötigt keine DDL-Rechte. [Bedienung](OLLAMA.md#textauswahl-einstellen).


## Mehrere Nummern pro Dokument (4.4.0)

Die Tabellen `nummernart`, `dokument_nummer` und `autokat_nummer` ergänzen das Datenmodell. Pro Dokument/Regel und Nummernart gilt eine eindeutige Zuordnung. Details zur fachlichen Schreibhoheit, zur Kompatibilitätsspalte `dokumente.Nummer`, zum Suchwert und zur einmaligen Migration stehen in [Nummern](NUMMERN.md#datenhaltung-und-migration). Neue Metadaten verwenden JSON-Schema 4; die Formate 1 bis 3 bleiben lesbar.

Der vollständige Updatepfad von 4.3.0 auf 4.4.0 einschließlich KI-Parametern, Hintergrundtabellen und Nummernmigration steht unter [Aktualisierung](UPDATE.md#aktualisierung-von-430-auf-440).
