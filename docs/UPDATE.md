# Aktualisierung und Deployment

Web, Backend und Worker werden zusammen aus einem vollständigen Projektstand aktualisiert. Vorhandene lokale Konfigurationen und Dokumentdaten müssen dabei erhalten bleiben.

## Aktualisierung von 4.3.0 auf 4.4.0

1. Zusammengehörige Sicherung von Datenbank, PDFs, Metadaten und Konfiguration mit dem bisherigen Stand erstellen. Offene Dokumententwürfe schließen. Web, Backend und Worker stoppen; Datenbank und Datenbankwerkzeug bleiben für die Migration erreichbar.
2. In der **richtigen Anwendungsdatenbank** die folgenden Skripte in dieser Reihenfolge vollständig ausführen. Schema und Seed nicht in einen vorhandenen Bestand importieren. Die Skripte sind wiederholbar und erhalten vorhandene Einstellungen.

   1. [update_ollama.sql](../sql/update_ollama.sql) – standardmäßig deaktivierte lokale KI.
   2. [update_master_explanations.sql](../sql/update_master_explanations.sql) – optionale Stammdaten-Erläuterungen.
   3. [update_ollama_instruction.sql](../sql/update_ollama_instruction.sql) – Parametertexte und fachliche KI-Anweisung.
   4. [update_ollama_thinking.sql](../sql/update_ollama_thinking.sql) – optionaler Denkmodus.
   5. [update_ollama_diagnostics.sql](../sql/update_ollama_diagnostics.sql) – Schalter für die Diagnose.
   6. [update_ollama_background.sql](../sql/update_ollama_background.sql) – Hintergrundcache und Verarbeitungsmodus.
   7. [update_ollama_worker_status.sql](../sql/update_ollama_worker_status.sql) – Worker-Rückmeldung.
   8. [update_ollama_text_selection.sql](../sql/update_ollama_text_selection.sql) – Textauswahl und Cache-Kontext.
   9. [update_document_numbers.sql](../sql/update_document_numbers.sql) – Nummernarten, Nummern und Regelerkennungen.

3. Vorläufige Eingangs-/KI-Ergebnisse des alten Softwarestands leeren: `DELETE FROM ki_vorschlaege;` und `DELETE FROM verarbeitungsauftraege;`. Die archivierten Dokumente und Original-PDFs im Eingang bleiben erhalten; der Worker bereitet die Ergebnisse neu vor. Das gilt auch beim Wechsel von einem zuvor getesteten Entwicklungsstand.
4. Web, Backend und Worker aus demselben 4.4.0-Stand gemeinsam **neu bauen und erstellen**, siehe unten. Ein Containerneustart allein übernimmt keine Codeänderungen.
5. Oberfläche und `/health` müssen 4.4.0 melden. Nummernarten, eine migrierte Nummer, mehrere Nummern im Dokument, AutoKat-Anker einschließlich maximaler Länge und Leerzeichen, Textvergleich, Schreibvariantensuche und Archivprüfung testen. Bestehende Regeldefinitionen verwenden zunächst **Bisherige Erkennung**. Unter Stammdaten können neue Arten und Erläuterungen ergänzt werden.
6. Die lokale KI bleibt ohne bisherige Aktivierung ausgeschaltet. Bei Nutzung Adresse und Modell einrichten, Verbindung testen und manuelle sowie gegebenenfalls Hintergrundvorschläge prüfen. Einrichtung, Zeitgrenzen, Denkmodus und Diagnose: [Ollama](OLLAMA.md).
7. Eine neue SQL-Sicherung erstellen und in einer getrennten Installation einschließlich Nummern und Historie rücksichern. Die Sicherung trägt 4.4.0. Der manuelle Parameter `Version` bleibt bei Bestandsupdates unverändert; bei Bedarf als Instanzkennzeichnung bewusst anpassen.

Neue Schreibvorgänge verwenden JSON-Schema 4. Bestehende JSON-Dateien werden durch das SQL-Update nicht verändert; die Archivprüfung kann die neue Nummernliste ergänzen. Schema 1 bis 3 bleiben lesbar. Eine Rückkehr zu älterer Software nach dem Schreiben erfordert den zusammengehörigen Sicherungsstand.

Neuinstallationen benötigen diese Updates nicht: `schema.sql` und `seed.sql` enthalten den vollständigen Stand. Nach Rücksicherung einer älteren unterstützten Sicherung werden Nummern automatisch migriert; fehlende KI-Parameter können mit den oben genannten Skripten ergänzt werden, ohne vorhandene Werte zu ersetzen.

## Aktualisierung von 4.2.0 auf 4.3.0

Vor dem Update mit dem bisherigen Stand eine vollständige Sicherung erstellen. Alle Anwendungsdienste stoppen, [update_document_history.sql](../sql/update_document_history.sql) in der richtigen Datenbank ausführen und Web, Backend und Worker gemeinsam aktualisieren. Bei Neuinstallation ist die Tabelle in `schema.sql` enthalten. Bereits geöffnete Formulare neu öffnen.

Die Migration legt `ereignisprotokoll` an oder benennt die bisherige `dokument_protokoll` mit allen Einträgen um. Auch bei bereits eingeführter Historie vor diesem Update erneut ausführen: Das Skript ergänzt außerdem `protokollLoeschdatenBehalten` mit Standard ja, ohne vorhandene Einstellungen zu überschreiben. Unter Einstellungen → Archiv die gewünschte Aufbewahrung wählen; Nein bereinigt bei künftigen endgültigen Löschungen auch alle früheren Protokolldetails des Dokuments. Bei zwei vorhandenen Protokolltabellen zuerst den gemeldeten Namenskonflikt klären. Alte Info-Texte und Metadateien bleiben erhalten. Neue Schreibvorgänge erzeugen JSON-Schema 3 ohne Info-Feld. Eine Rücknahme auf einen älteren Anwendungsstand nach ersten Schema-3-Schreibvorgängen erfordert den gesicherten zusammengehörigen DB-/Dateibestand; ältere Software unterstützt Schema 3 nicht.

Version 4.3.0 in den Einstellungen und unter `/health` prüfen. Archivierung, Bearbeitung, Papierkorb und Wiederherstellung sowie die Historie testen. Eine neue SQL-Sicherung erstellen und in einer getrennten Installation einschließlich Protokollzeilen rücksichern. Auch den Import einer älteren Sicherung ohne Protokoll prüfen. [Testfälle](DOKUMENTHISTORIE.md#funktionsprüfung).

Auch die neu ergänzte **OCR-Text einlesen**-Aktion in der Klassifizierung und den korrigierten OCR-Aufruf in der Bearbeitung testen: Eine PDF mit vorhandener, aber fehlerhafter Textschicht muss vollständig neu erkannt werden. In der Klassifizierung ersetzt die bestätigte Aktion vorbereitete Ergebnisse und ungespeicherte Eingaben durch neue Vorschläge; in der Bearbeitung bleiben die eingegebenen Metadaten erhalten. Die Original-PDF bleibt unverändert. Dafür sind die gemeinsam aktualisierten Web-, Backend- und Worker-Images erforderlich; ein bloßer Containerneustart übernimmt keine Codeänderungen. Der Wechsel der Stammdatenlisten erfolgt jetzt über Reiter; die Protokolldetails bleiben zunächst eingeklappt.

## Vorbereitung

1. Gewünschten Commit bestimmen und dokumentieren. Für unveröffentlichte Erweiterungen ist der Commit die eindeutige Standkennung.
2. Eine [zusammengehörige Sicherung](SICHERUNG.md) erstellen und die Wiederherstellbarkeit prüfen.
3. Zuerst in einer getrennten Testinstanz arbeiten. Port, Datenbank und Datenordner dürfen nicht mit Produktion geteilt werden.
4. Schema und benötigte Parameter anhand der [Datenbankreferenz](DATENBANK.md) prüfen. Nur erforderliche Updates ausführen; Schema/Seed nicht erneut in einen vorhandenen Bestand importieren.
5. Neue Archivierungen, Bearbeitungen und Wartung beenden. Vor Datenbankmigrationen alle Anwendungsdienste stoppen.

Bei einer Installation ohne `eablage-worker` in der eingesetzten Compose-Datei im folgenden Stoppbefehl nur die vorhandenen Dienste `eablage-web` und `eablage-backend` nennen.

Bei einer Installation mit separater Datenbank:

```bash
sudo docker compose stop eablage-web eablage-backend eablage-worker
```

Bei einer Instanz mit integrierter Datenbank für jeden Befehl die zur Installation gehörenden Optionen verwenden. `eablage` ist nur der Beispiel-Projektname: Für bestehende Instanzen immer den bei ihrer Einrichtung verwendeten Namen beibehalten (zum Beispiel `eablage-fresh`), damit weiterhin dasselbe Datenbank-Volume angesprochen wird:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage stop eablage-web eablage-backend eablage-worker
```

## Aktualisierung von 4.1.0 auf 4.2.0

Nach der Sicherung und bei gestoppten Anwendungsdiensten:

1. Archivdateinamen gegen `<DokID>dok.pdf` prüfen. Bei vorhandener Spalte `dokumente.Dateipfad` das geprüfte [Bestandsupdate](../sql/update_document_path.sql) ausführen; bei gemeldeten Abweichungen den Bestand zuerst gemäß [Datenbankanleitung](DATENBANK.md#redundanten-dokumentpfad-entfernen) klären.
2. Bei fehlender Tabelle `verarbeitungsauftraege` [update_inbox_processing.sql](../sql/update_inbox_processing.sql) ausführen.
3. Projektdateien einschließlich Compose-Datei gemeinsam übernehmen. Der neue Dienst `eablage-worker` benötigt dieselbe Datenbank und denselben Datenpfad wie das Backend. OCR ist standardmäßig aktiv; optionale Einstellungen stehen unter [OCR](OCR.md) und [Stapelverarbeitung](STAPELVERARBEITUNG.md).
4. Web, Backend und Worker gemeinsam neu bauen und erstellen, wie unten beschrieben. Poppler und Tesseract werden mit dem Backend-/Worker-Image installiert; auf dem NAS sind keine zusätzlichen OCR-Pakete nötig.
5. Version 4.2.0 in der Oberfläche und unter `/health`, manuelle Klassifizierung, Hintergrundverarbeitung und Archivierung prüfen. Danach eine neue SQL-Sicherung erstellen und die Rücksicherung in einer getrennten Instanz prüfen.

Die PDF-Uploadgrenze beträgt 50 MiB. Sie wird durch Web-/Backend-Code und die PHP-Konfiguration im Web-Image festgelegt, nicht über die Parametertabelle. Die optionale manuelle Instanzkennzeichnung `Version` in bestehenden Datenbanken wird nicht automatisch geändert. Version 4.2.0 verwendet JSON-Schema 2; beim anschließenden Update auf 4.3.0 gilt der oben beschriebene Übergang auf Schema 3.

Unterstützte 4.1.0-Sicherungen können weiterhin in eine leere Installation importiert werden. Anschließend die im Dump enthaltenen Altspalten gemäß [Sicherung und Wiederherstellung](SICHERUNG.md) prüfen und gegebenenfalls migrieren. Eine Rückkehr zu 4.1.0 nach Entfernung von `Dateipfad` benötigt den zusammengehörigen Vor-Update-Stand.

## Projektdateien übernehmen

Code, Compose-Dateien und gegebenenfalls geänderte SQL-Dateien aus demselben Commit übernehmen. Lokale `.env`/`.env.fresh` und `web/konfiguration.php` erhalten; neue Variablen bewusst aus den Beispielen ergänzen. Keine Zugangsdaten anderer Instanzen kopieren. Nutzdaten gehören außerhalb von `web/` und `backend/` in das konfigurierte Datenverzeichnis.

### Fehlende Auftragstabelle ergänzen

Wenn `verarbeitungsauftraege` fehlt, bei gestoppten Anwendungsdiensten [update_inbox_processing.sql](../sql/update_inbox_processing.sql) ausführen. Nach allen benötigten SQL-Updates die Anwendungsdienste gemäß dem Abschnitt „Gemeinsam bauen und starten“ aktualisieren. Vor dem Austausch der alten Compose-Datei nur deren bestehende Dienste stoppen. Ablauf und Umgebungsvariablen: [Stapelverarbeitung](STAPELVERARBEITUNG.md).

### Vorhandene Spalte dokumente.Dateipfad entfernen

Wenn `dokumente.Dateipfad` vorhanden ist, [update_document_path.sql](../sql/update_document_path.sql) gemäß [Datenbankanleitung](DATENBANK.md#redundanten-dokumentpfad-entfernen) ausführen: Das Skript prüft die bisherigen Archivdateinamen und entfernt `dokumente.Dateipfad` nur ohne Abweichungen. Vorher Datenbank, Archiv und Metadaten zusammen sichern und alle Anwendungsdienste stoppen.

### Datenbank von 4.0.0 auf 4.1.0

Nach der Sicherung und bei gestoppten Anwendungsdiensten auf der **richtigen Instanz** prüfen, ob `dokumente.GeloeschtAm` fehlt. Falls ja, [update_papierkorb.sql](../sql/update_papierkorb.sql) einmalig ausführen. Anschließend [update_autokat_4.1.0.sql](../sql/update_autokat_4.1.0.sql) ausführen; das Skript entfernt nur `Suchbegriff1`, `Suchbegriff2` und `sucheBeenden` aus `autokat` und kann wiederholt werden. Die aktuelle Regelbezeichnung, Such- und Ausschlussbegriffe werden nicht verändert.

Vorher den Bestandszustand beispielsweise mit `SHOW COLUMNS FROM dokumente LIKE 'GeloeschtAm';` und `SHOW COLUMNS FROM autokat;` prüfen. Nachher müssen `GeloeschtAm` vorhanden und die drei alten Spalten entfernt sein. `schema.sql` und `seed.sql` sind ausschließlich für Neuinstallationen. Ein altes, mit 4.1.0 markiertes Test-Backup kann die drei Spalten nach einer Initialrücksicherung wiederherstellen; dann die AutoKat-Migration erneut ausführen. Das Backup vor der Migration für eine mögliche Rücknahme aufbewahren.

Git muss auf dem NAS nicht installiert sein: Ein auf einem anderen Rechner bereitgestellter vollständiger Projektstand kann kopiert werden. Den Commit dabei festhalten; der Git-Arbeitsordner selbst muss nicht ins Deployment.

Entfallene Code-Dateien beim Abgleich ebenfalls entfernen. Den Projektwurzelordner mit lokalen Laufzeitdateien nicht unkontrolliert spiegeln. Die Markdown-Dokumentation unter `docs/` zusammen mit dem Code aktualisieren.

### Vorhandene PowerShell-Skripte

`deploy-test.ps1` und `deploy-prod.ps1` übertragen `compose.yaml` und spiegeln die Codeordner `web/` und `backend/`. Sie sparen lokale PHP-Konfigurationen, `.env`-Dateien und bestimmte Umgebungsverzeichnisse aus. Dokumentdaten dürfen nicht in den gespiegelten Codeordnern liegen.

Das Testskript erwartet einen Git-Arbeitsstand auf `develop`, das Produktivskript einen sauberen Stand auf `main`. Beide prüfen die lokale Ziel-`.env`: Sie muss genau eine passende Instanzkennung enthalten. Fehlende, doppelte oder falsche Kennungen stoppen das Skript vor dem Kopieren. Werte dürfen ohne Anführungszeichen oder mit passenden einfachen/doppelten Anführungszeichen angegeben werden.

Vor dem ersten Deployment in bestehenden Installationen die passende Zeile ergänzen:

Testziel:

```dotenv
EABLAGE_INSTANCE=test
```

Produktivziel:

```dotenv
EABLAGE_INSTANCE=prod
```

Die Kennzeichnung wählt weder Datenbank noch Datenpfad oder Webport aus und trennt diese Ressourcen nicht automatisch. Diese Werte weiterhin pro Instanz in der lokalen Konfiguration festlegen und auf Trennung prüfen. Die Skripte enthalten keine vorgeschriebenen Datenpfade oder Datenbanknamen; bestehende Pfade und Namen können erhalten bleiben.

Quell- und Zielverzeichnisse müssen verschieden und dürfen nicht ineinander verschachtelt sein. Der verpflichtende Parameter `-Target` zeigt auf das vorhandene Codeverzeichnis, nicht auf den Dokumentdatenordner. Neue `.env`-Werte, SQL-Migrationen und Dokumentationsdateien werden durch diese Skripte nicht automatisch eingerichtet beziehungsweise synchronisiert.

Wenn Windows die Ausführung des geprüften Skripts verhindert, kann für die laufende PowerShell-Sitzung `Set-ExecutionPolicy -Scope Process -ExecutionPolicy RemoteSigned` verwendet werden. Die Instanz- und Quell-/Zielprüfung bleibt unabhängig davon erforderlich.

## Gemeinsam bauen und starten

Installation mit separater Datenbank, im Projektverzeichnis:

```bash
sudo docker compose config --quiet
sudo docker compose build eablage-web eablage-backend eablage-worker
sudo docker compose up -d --force-recreate eablage-web eablage-backend eablage-worker
sudo docker compose ps -a
```

Instanz mit integrierter Datenbank:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage config --quiet
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage build eablage-web eablage-backend eablage-worker
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage up -d --force-recreate eablage-web eablage-backend eablage-worker
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage ps -a
```

Ein bloßer Containerneustart übernimmt geänderten Code nicht. Der Webdienst heißt `eablage-web`, der Backenddienst `eablage-backend` und der Hintergrunddienst `eablage-worker`; `web` ist kein Service-Name dieser Compose-Dateien.

## Nachkontrolle

Browserseite neu laden, interne Backend-/Datenbankverbindung prüfen und die [Funktionsprüfung](ENTWICKLUNG.md#funktionsprüfung-in-einer-getrennten-instanz) durchführen. `GET /health` liefert für diesen Stand `status=ok`, `service=eablage-backend`, `version=4.4.0`. Der manuelle Parameter `Version` wird nicht automatisch aktualisiert.

Für Wartung PHP- und Backend-Datenbankidentität, Metadaten-Mount und UID/GID kontrollieren. Ein gemischter Web-/Backend-/Workerstand soll nicht zum Schreiben verwendet werden. JSON-Reparaturen werden ausschließlich ausdrücklich gestartet.

## Rücknahme

Nach Schreibvorgängen genügt der Wechsel auf alte Images möglicherweise nicht: Datenbank und Begleitdateien können bereits anders strukturiert sein. Den zusammengehörigen Vor-Update-Stand aus Code/Images, Konfiguration, Datenbank, PDFs und Metadaten wiederherstellen. DDL-Migrationen lassen sich nicht als Gesamtvorgang zurückrollen. Die Rücknahme zunächst in der getrennten Instanz prüfen.

### Fehlender DokID-Index im Altbestand

Das Nummern-Update ergänzt vor dem Anlegen der Fremdschlüssel den eindeutigen Index `uq_dokumente_dokid` auf `dokumente.DokID`. Ein vorhandener Primärschlüssel nur auf `ID` genügt dafür nicht. Bei doppelten DokIDs stoppt das Update; es löscht oder nummeriert keine Dokumente um. In diesem Fall zuerst die betreffenden Datensätze prüfen, nicht den Fehler überspringen. Nach einem früheren Abbruch bei `CREATE TABLE dokument_nummer` kann das korrigierte vollständige Update erneut ausgeführt werden; die bereits angelegte Tabelle `nummernart` bleibt erhalten.
