# Fehlerbehebung

Zuerst betroffene Instanz, eingesetzten Commit und letzte Änderung bestimmen. Fehlermeldung und Logs gemeinsam prüfen. Bei unklarem Schreibausgang vor einer Wiederholung den tatsächlichen Bestand kontrollieren.

## Häufige Probleme

| Symptom | Prüfung und Maßnahme |
| --- | --- |
| Python-Dienst nicht konfiguriert | `BACKEND_URL` im Webcontainer und `EABLAGE_BACKEND_URL` in der regulären `.env` prüfen; siehe [Konfiguration](KONFIGURATION.md). |
| Backend nicht erreichbar | Service `eablage-backend`, App-Netz, Alias `backend` und interne URL prüfen. Kein `localhost` als Backendadresse verwenden. |
| Ungültige JSON-Antwort | Backend-Logs und gemeinsam eingesetzten Web-/Backendstand prüfen; nach Codeänderung Images neu bauen. |
| `service "web" is not running` | Der Compose-Service heißt `eablage-web`. |
| `network database not found` | Für die Installation mit separater Datenbank das externe Netz anlegen und MariaDB daran anbinden. Die Installation mit integrierter Datenbank erzeugt ein eigenes Netz. |
| Datenbankzugriff verweigert | PHP-Datei und Backend-Umgebung auf dieselbe Datenbank und passende Zugangsdaten prüfen. Änderung einer MariaDB-Initialisierungsvariable ändert ein vorhandenes Benutzerpasswort nicht automatisch. |
| Tabellen fehlen nach Neustart | SQL-Initialisierung läuft nur beim ersten Start eines leeren MariaDB-Volumes; Logs und SQL-Mounts prüfen. |
| Lokale PHP-Datei nicht lesbar | Hostpfad, reguläre Datei, Eigentümer, Elternordner und UGOS-ACL prüfen. Kein kompletter Quellcode-Mount über das Image. |
| Eingang fehlt / Upload scheitert | `pfadAblageNeu_linux`, Mount und Schreibrechte prüfen. Uploadlimit in PHP und gegebenenfalls vorgeschaltetem Server beachten. |
| Upload meldet vorhandenen Namen | Vorhandene Eingangskopie zuerst verarbeiten oder neue Datei vor Upload umbenennen. |
| Android-Cloudauswahl meldet `ERR_UPLOAD_FILE_CHANGED` | Der Browser konnte die ausgewählte Quelle nicht unverändert lesen. PDF zunächst in den lokalen Downloadordner laden und von dort hochladen. Die konkrete Ursache beim Dateianbieter lässt sich aus dieser Meldung allein nicht bestimmen. |
| PDF liefert keinen brauchbaren Text | Textschicht/OCR, `pdftotext` und `PDFTextMode` prüfen; den tatsächlichen Text statt des sichtbaren Layouts für Regeln verwenden. |
| AutoKat-Regel trifft nicht | Aktivstatus, alle positiven Begriffe, Wortgrenzen, Phrase und Ausschlussbegriffe prüfen; [Regeltest](AUTOKAT.md) verwenden. |
| Mehrere K-Regeln passen | Such-/Ausschlussbegriffe präzisieren. Rang steuert fachliche Priorität, entfernt aber die Überschneidung nicht. |
| Datum fehlt oder ist unplausibel | Fachliches Datum manuell ergänzen; geeigneten `prDatum`-Anker verwenden. Monat/Jahr und zweistellige Jahreszahlen kontrollieren. |
| Vergleich liefert keine passende Vorlage | Gespeicherten Schwellenwert, Mindesttextlänge und Vergleichslänge sowie den extrahierten Text prüfen; siehe [Vergleichsparameter](KONFIGURATION.md#textextraktion-und-vergleich). |
| Deployment meldet eine fehlende oder falsche Instanzkennung | In der Ziel-`.env` genau einmal `EABLAGE_INSTANCE=test` beziehungsweise `prod` setzen und tatsächliche Ressourcentrennung prüfen; siehe [Update](UPDATE.md#vorhandene-powershell-skripte). |
| Archivierung oder JSON-Schreiben scheitert | Zielpfade, Rechte, Speicherplatz, Backend und vorhandene Dateien zur nächsten DokID kontrollieren; anschließend [Archivprüfung](ARCHIVPRUEFUNG.md). |
| Dokument bleibt nach Erfolg im Eingang | Warnung zur entfernten Eingangskopie beachten. Bereits archiviertes Dokument nicht nochmals archivieren; DB/PDF/JSON prüfen. |
| Bearbeitung meldet zwischenzeitliche Änderung | Dokument neu laden und Änderungen mit dem aktuellen Stand vergleichen, statt ein altes Formular erneut zu erzwingen. |
| JSON passt nach Stammdatenumbenennung nicht | Bezeichnungen in JSON sind Momentaufnahmen; gezielt über Archivprüfung reparieren. |
| Explorer zeigt alte Zuordnung | Seite neu laden; Bestand wird beim Laden aus dem aktuellen Plan aufgebaut. Physischer Export ist keine Datenquelle. |
| PDF-Vorschau auf Smartphone fehlt | **PDF öffnen** verwenden; die eingebettete Vorschau ist auf kleinen Displays ausgeblendet. |
| Einzel-/Gesamtexport scheitert | Backendplan, Archivdateien und Exportparameter prüfen; Gesamtexportziel muss beschreibbar sein. |
| Alte Ordner nach neuem Export vorhanden | Export räumt vorherige Strukturen nicht auf; für einen aktuellen Vergleich ein leeres Ziel verwenden. |
| Datenbanksicherung scheitert | Zielpfad, Rechte, Speicherplatz und InnoDB aller Basistabellen prüfen. |
| Wiederherstellungsbereich fehlt | `dokumente` muss leer und Sicherungsziel konfiguriert sein; keine Dokumente nur zur Freischaltung löschen. |
| Dump nicht auswählbar oder abgewiesen | Originalnamen, reguläre Datei und kompatiblen Format-/Tabellenbestand gemäß [Sicherung](SICHERUNG.md) prüfen. |
| Rücksicherung nach Abbruch gesperrt | `.eablage-restore-incomplete` und teilbefüllte DB nach dem dort beschriebenen Wiederanlaufverfahren behandeln. |
| Wartung meldet abgelaufenen Nachweis | Neue Prüfung/Vorschau erstellen; 30-Minuten-Grenze, Backendneustart und zwischenzeitliche Änderungen beachten. |
| Batch oder Verbindung abgebrochen | Bereits abgeschlossene Einzelvorgänge können bestehen bleiben. Browsermeldung/Protokoll prüfen und neue Vorschau erstellen. |

## Archivziel existiert bereits

Die nächste DokID hängt vom Datenbankbestand ab. Wird nur die Testdatenbank zurückgesetzt, können alte PDFs oder Begleitdateien das nächste Ziel blockieren. Die Anwendung überschreibt diese Dateien nicht.

Archivprüfung starten und Dateien fachlich zuordnen. Bestätigte Testreste außerhalb der aktiven Verzeichnisse aufbewahren; keine ungeprüfte Löschung oder bloße Erhöhung der Start-ID als Ersatz für die Klärung. Datenbank und Dateien künftig gemeinsam zurücksetzen.

## Logs und Verbindung prüfen

Für den regulären Compose-Betrieb im Projektverzeichnis:

```bash
sudo docker compose ps -a
sudo docker compose logs eablage-web --tail 100
sudo docker compose logs eablage-backend --tail 100
sudo docker compose exec eablage-web php -r "echo file_get_contents('http://backend:8000/health');"
sudo docker compose exec eablage-backend python -c "from app.db.database import get_connection; c=get_connection(); print(c.is_connected()); c.close()"
```

Die ersten Verbindungsantworten sind JSON mit `status=ok` beziehungsweise `True`. Für die Installation mit integrierter Datenbank dieselben Optionen `--env-file .env.fresh -f compose.fresh.yaml -p eablage` vor jedem Unterbefehl verwenden; die MariaDB-Logs stehen dort unter `logs mariadb --tail 100`. Für eine separate MariaDB im zugehörigen Datenbankprojekt die Logs prüfen.

Diagnoseausgaben können Datenbank-, Datei- und Fehlermeldungen enthalten. Zugangsdaten und Dokumenttexte vor einer Weitergabe entfernen. Eine erfolgreiche Health-Antwort allein prüft nicht Dateirechte, Schema, PDF-Zugriff oder vollständige Wiederherstellbarkeit.

