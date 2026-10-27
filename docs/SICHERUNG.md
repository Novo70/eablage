# Sicherung und Wiederherstellung

Eine wiederherstellbare eAblage benötigt einen zusammengehörigen Stand von SQL-Datenbank, PDF-Archiv, Metadateien und lokaler Konfiguration. Die Anwendung erstellt SQL-Sicherungen und kann eine leere Dokumentdatenbank aus einer kompatiblen Sicherung befüllen. Sicherungen enthalten auch die Datensätze im Papierkorb und den Parameter für die höchste vergebene DokID. Sie stellt die dazugehörigen Dateien nicht selbst wieder her.

## Zusammengehörigen Stand sichern

1. Archivierungen, Bearbeitungen und Wartungsschreibvorgänge beenden; für die gemeinsame Datei-/Datenbanksicherung eine Schreibpause einhalten.
2. In der Anwendung die Datenbank sichern.
3. PDF-Archiv und Metadatenverzeichnis vom selben Stand sichern; den Eingang bei noch nicht archivierten Dokumenten mit einschließen.
4. `.env` beziehungsweise `.env.fresh`, lokale PHP-Konfiguration, eingesetzten Commit und gegebenenfalls getestete Images sichern beziehungsweise dokumentieren.
5. Sicherung zusätzlich außerhalb der aktiven Datenordner aufbewahren und eine Rücksicherung in einer getrennten Instanz prüfen.

Ein NAS-Backup ergänzt die SQL-Sicherung. Ein Docker-Volume oder eine erfolgreich erzeugte SQL-Datei allein bestätigt keine vollständige Wiederherstellbarkeit. Exportkopien ersetzen die Archiv-PDFs und Datenbank nicht.

## Datenbanksicherung erstellen

1. `pfadDatensicherung_linux` auf ein geeignetes Ziel, beispielsweise `/data/backup/`, setzen.
2. **Export & Datensicherung → Backup der Datenbank** öffnen.
3. **Datenbanksicherung erstellen** wählen und bestätigen.
4. Ergebnis mit Dateiname, Zeitpunkt, Größe, Tabellen- und Datensatzzahl kontrollieren.

PHP erzeugt den Dump über die bestehende PDO-Verbindung. Die Datei heißt beispielsweise `eablage_2026-10-02_210000.sql`. Ein identisches vorhandenes Ziel wird nicht überschrieben. Bei einem erkannten Schreibfehler wird die unvollständige Datei entfernt.

Gesichert werden Strukturen und Datensätze der InnoDB-Basistabellen dieser Datenbank mit Ausnahme von `verarbeitungsauftraege`, `ki_vorschlaege` und `ki_worker_status`. Views, Trigger, Routinen, Datenbankbenutzer, Rechte und PDF-/Metadateien sind nicht enthalten. Währenddessen keine Schemaänderungen oder Tabellenmigrationen durchführen. Für eine übergreifend konsistente Sicherung zusätzlich die oben genannte Schreibpause einhalten.

## Voraussetzungen für die Rücksicherung

Die Oberfläche zeigt **Datenbank aus Backup initialisieren** nur, wenn `dokumente` leer und ein Sicherungsverzeichnis konfiguriert ist. Das ist eine Initialbefüllung einer vorbereiteten Installation, keine Wiederherstellung über einen laufenden gefüllten Dokumentbestand. Die Anwendung legt dafür keine zusätzliche Testdatenbank an.

Die SQL-Datei muss im aktuell konfigurierten Sicherungsverzeichnis liegen. Ein Browser-Upload ist nicht vorgesehen. Aufgelistet werden reguläre, nicht verlinkte Dateien mit dem Namensmuster `<Datenbankname>_YYYY-MM-DD_HHMMSS.sql`; der Namensteil besteht aus Buchstaben, Ziffern, Unterstrichen oder Bindestrichen. Den ursprünglichen Dateinamen beibehalten.

Der Import akzeptiert ausschließlich den unterstützten, vollständigen eAblage-Dump mit dieser ersten Zeile:

```sql
-- e_ablage MariaDB-Datensicherung; Version: 4.4.0
-- Ereignisprotokoll: 1
```

Unterstützte Sicherungen mit der Kennung `Version: 4.1.0`, `Version: 4.2.0` oder `Version: 4.3.0` bleiben ebenfalls importierbar. Die Kennung allein genügt nicht: geprüft werden SQL-Form, Reihenfolge, InnoDB und erwartete Spalten einschließlich `GeloeschtAm` der fünf Grundtabellen `autokat`, `doktyp`, `dokumente`, `kategorie`, `parameter` sowie bei Historien-Sicherungen der Tabelle `ereignisprotokoll` und bei Nummern-Sicherungen der drei Tabellen `nummernart`, `dokument_nummer` und `autokat_nummer`. Die zusätzliche zweite Kopfzeile `-- Ereignisprotokoll: 1` macht die Protokolltabelle verbindlich. Aktuelle 4.4.0-Sicherungen umfassen insgesamt neun Tabellen einschließlich der Nummerntabellen. Bisherige Historienbackups mit `-- Dokumentprotokoll: 1` und `dokument_protokoll` werden ebenfalls akzeptiert und beim Import auf den neuen Tabellennamen umgesetzt. Ältere Sicherungen ohne diese Kennzeichnung enthalten weiterhin fünf Tabellen. Dumps anderer Versionen oder unmarkierte Dumps, einschließlich 4.0.0-Dumps, und Sicherungen mit zusätzlichen Basistabellen werden von dieser Funktion abgewiesen. Die manuelle Parameterkennzeichnung `Version` ersetzt diese Prüfung nicht. Eine ältere Sicherung zuerst in einer getrennten Umgebung fachgerecht importieren und auf das [aktuelle Schema](DATENBANK.md) bringen; danach mit der aktuellen Sicherungsfunktion neu sichern. Nicht nur die Kopfzeile ändern.

Vor der Entfernung der drei alten AutoKat-Spalten bereits mit 4.1.0 gekennzeichnete Test-Sicherungen bleiben importierbar: Der Import übernimmt das vollständige Tabellenschema des Dumps. Falls darin `Suchbegriff1`, `Suchbegriff2` oder `sucheBeenden` enthalten sind, danach [update_autokat_4.1.0.sql](../sql/update_autokat_4.1.0.sql) bei Schreibpause ausführen und eine neue Sicherung erstellen. Für einen älteren 4.0.0-Dump gilt weiterhin der oben beschriebene gesonderte Migrationsweg.

## Leere Installation befüllen

1. Eine Installation mit leerer Tabelle `dokumente` vorbereiten; für eine Probe die [Installation mit integrierter Datenbank](INSTALL_FRESH.md) verwenden. Einen gefüllten Bestand nicht allein zum Freischalten der Schaltfläche löschen.
2. Die zusammengehörigen Archiv-PDFs und Metadateien in die für diese Installation erreichbaren Datenordner zurückkopieren. Dateiinhalte unverändert übernehmen.
3. Den kompatiblen SQL-Dump in das vor dem Import konfigurierte Sicherungsverzeichnis legen.
4. Datenbanknamen und Instanz prüfen. Alle anderen Schreiber während des Imports ausschließen.
5. **Export & Datensicherung → Datenbank aus Backup initialisieren** öffnen, Datei auswählen, Bestätigung setzen und **Datenbank initialisieren** wählen.
6. Erfolgsanzeige mit Tabellen- und Dokumentzahl prüfen. Die Seite anschließend neu laden, damit die übernommenen Parameter verwendet werden.
7. Übernommene Pfade mit den tatsächlichen Container-Mounts vergleichen und bei einem anderen Zielsystem bewusst anpassen.
8. Die eigenständige [Archivprüfung](ARCHIVPRUEFUNG.md) starten. Fehlende/unlesbare Dateien, abweichende JSON-Werte und vorhandene Prüfsummen kontrollieren.
9. Dokument öffnen, Suche/Explorer und Parameter prüfen. Erst nach geklärten Befunden wieder regulär schreiben.

Alle im Dump enthaltenen Tabellen werden ersetzt, einschließlich vorhandener Kategorien, Dokumenttypen, AutoKat-Regeln und Parameter. Dadurch bleiben gesicherte Einstellungen erhalten. Der Import schreibt Pfade nicht automatisch für das Zielsystem um. Zugangsdaten und Docker-Mounts kommen aus der lokalen Konfiguration und sind kein Bestandteil dieser Tabellenrücksicherung.

## Fehler nach Importbeginn

Vor der ersten Änderung wird die gesamte SQL-Datei geprüft. Nach Beginn sind `DROP`/`CREATE`-Anweisungen nicht als Gesamtvorgang zurückrollbar. Eine Störung kann deshalb einen teilweise befüllten Bestand hinterlassen.

Im Verzeichnis der gewählten Sicherung bleibt dann `.eablage-restore-incomplete` bestehen. Diese Markierung verhindert einen weiteren Importstart. Zunächst die Fehlerursache beheben und die frische Instanz einschließlich Datenbank auf den vorbereiteten leeren Zustand zurücksetzen. Erst danach die Markierung entfernen und erneut versuchen. Nicht einfach den Import wiederholen oder nur die Markierung löschen. Ein erfolgreicher Import entfernt sie selbst.

## Wiederherstellungsprobe

In einer getrennten Instanz den gesamten Ablauf mit Sicherung, Dateien, Import und Archivprüfung durchführen. Tabellen-/Dokumentzahlen vergleichen und repräsentative PDFs öffnen. Insbesondere Metadaten mit Umlauten, Zeilenumbrüchen, Datumswerten und unbekannten Referenzprüfsummen prüfen. Die Produktivinstanz und ihre Datenpfade bleiben während der Probe getrennt. Das Ergebnis mit eingesetztem Commit und Sicherungsstand dokumentieren.


## Dokumentpfad bei Rücksicherung

Unterstützte Sicherungen mit der früheren Spalte `dokumente.Dateipfad` bleiben importierbar. Da die Rücksicherung das vollständige Schema übernimmt, bei Schreibpause danach [update_document_path.sql](../sql/update_document_path.sql) gemäß [Datenbankanleitung](DATENBANK.md#redundanten-dokumentpfad-entfernen) ausführen. Abweichende Archivdateinamen zunächst klären. Dann Archivprüfung starten und eine neue Sicherung erstellen. Die Anwendung verwendet ausschließlich `<DokID>dok.pdf`, auch solange die Altspalte noch vorhanden ist.

## Vorläufige Inbox-Ergebnisse

Die reproduzierbaren Tabellen `verarbeitungsauftraege` und `ki_vorschlaege` sind vom integrierten SQL-Archivbackup ausgeschlossen. Noch nicht archivierte Original-PDFs liegen in der Inbox und benötigen eine Dateisicherung. Bei Rücksicherung werden alte Aufträge entfernt und später neu erstellt. Während aktiver Verarbeitung wird die Rücksicherung abgelehnt; bei Bedarf den Worker vorher stoppen. [Stapelverarbeitung](STAPELVERARBEITUNG.md).

## Historie bei Sicherung und Rücksicherung

Die Protokolltabelle gehört zum konsistenten SQL-Backup, einschließlich Ereignissen endgültig gelöschter Dokumente. Ein Backup nur aus PDF und JSON kann die Ereignishistorie nicht rekonstruieren. Neue Backups werden ohne vorhandene Protokolltabelle abgewiesen; zuerst die Migration ausführen.

Vor einer Rücksicherung muss die Protokolltabelle ebenfalls vorhanden sein. Neue Backups ersetzen ihren Inhalt vollständig. Bei einer alten Sicherung ohne Protokoll wird eine vorhandene Historie geleert, damit keine Ereignisse eines anderen Bestands an rückgesicherte DokIDs angehängt werden. Deshalb auch bei leerer Tabelle `dokumente` eine noch benötigte bisherige Historie vorher sichern. Die Rücksicherung selbst schreibt keine künstlichen Dokumentereignisse.

## Stammdaten-Erläuterungen

Die integrierte SQL-Sicherung erfasst automatisch `Erlaeuterung` in Kategorien und Dokumententypen, einschließlich Zeilenumbrüchen, da Tabellenstruktur und alle Spalten exportiert werden. Die Rücksicherung erhält Erläuterungen aus aktuellen Sicherungen. Bei älteren unterstützten Sicherungen werden die optionalen Spalten beim Wiederanlegen der Tabellen ergänzt; die Texte bleiben zunächst leer. Bestehende Erläuterungen der Zielinstallation werden wie die übrigen Stammdaten durch den Sicherungsstand ersetzt. Erläuterungen sind kein Bestandteil der Dokument-JSON-Dateien.

## Fachliche KI-Anweisung

Die SQL-Sicherung enthält die längere TEXT-Spalte `parameter.param_value` und den aktuellen Wert von `ollamaAnweisung` einschließlich Zeilenumbrüchen. Die Rücksicherung übernimmt diesen Stand. Bei älteren Sicherungen wird eine VARCHAR-basierte Parameterdefinition beim Import auf TEXT erweitert. Fehlt der Anweisungsparameter, gelten zunächst die Backend-Standards; `sql/update_ollama_instruction.sql` ergänzt ihn anschließend für die Bearbeitung in den Einstellungen, ohne andere Werte zu ersetzen.

Die abgeleiteten Eingangsaufträge `verarbeitungsauftraege` und Hintergrund-KI-Vorschläge `ki_vorschlaege` sind kein Archivbestand und werden nicht gesichert. Bei einer Rücksicherung werden vorhandene Caches geleert; der Worker kann sie anschließend neu aufbauen. Eine laufende KI-Generierung verhindert die Rücksicherung bis zu ihrem Abschluss. Stammdaten, Erläuterungen und alle KI-Parameter werden weiterhin regulär gesichert.

Die Laufzeittabelle `ki_worker_status` wird ebenfalls nicht gesichert und bei Rücksicherung geleert. Nach Rücksicherung den Worker neu starten, damit seine Rückmeldung mit einer neuen Startkennung angelegt wird.


## Nummernarten und Nummern

SQL-Sicherungen enthalten `nummernart`, `dokument_nummer` und `autokat_nummer` mit allen Werten und Erläuterungen. Die Rücksicherung erhält diese Tabellen vollständig. Unterstützte ältere Sicherungen ohne Nummerntabellen werden nach dem Import automatisch migriert: bestehende Nummern und Regelerkennungen erhalten die Art Allgemein. Die zugehörigen PDFs und JSON-Dateien werden weiterhin separat unverändert zurückkopiert. Nach dem Import die Archivprüfung und eine Probe mit mehreren Nummern durchführen.
