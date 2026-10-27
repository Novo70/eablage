# Inbox im Hintergrund verarbeiten

Der seit Version 4.2.0 zusätzliche Hintergrunddienst `eablage-worker` bereitet PDF-Dateien aus der Inbox automatisch vor. Er liest Text aus, führt bei Bedarf OCR durch und klassifiziert mit AutoKat und anschließend gegebenenfalls Textvergleich. Es findet keine automatische Archivierung statt. Die Original-PDF bleibt bis zum Speichern oder Verwerfen im Eingang.

## Bedienung

Im Eingang erscheinen die Zustände **Wartet auf Erkennung**, **In Verarbeitung**, **Zur Prüfung bereit** oder **Erkennung fehlgeschlagen**. Die angezeigten Zustände werden alle fünf Sekunden in Paketen von höchstens 200 Dateinamen aktualisiert. Es werden nur die Dateien der geöffneten Eingangsliste abgefragt. Neu extern hinzugefügte Dateien erscheinen nach erneutem Öffnen/Aktualisieren der Eingangsliste.

**Klassifizieren** bleibt jederzeit möglich:

- Ohne fertiges Ergebnis startet der Aufruf die Verarbeitung sofort, auch bei gestopptem Worker.
- Während dieselbe Datei bereits verarbeitet wird, aktualisiert sich die Warteansicht alle drei Sekunden. Danach öffnet sich das Ergebnis.
- Fertige Ergebnisse werden ohne erneute OCR oder Klassifizierung geöffnet.
- Ein anderes Dokument kann manuell verarbeitet werden, während der Worker beschäftigt ist.

Ergebnisse prüfen, gegebenenfalls korrigieren und mit **Speichern** archivieren. Die DokID entsteht erst bei der Archivierung. Der Worker ersetzt keine fertigen Vorschläge und keine geöffneten Eingaben. **Erkennung erneut starten** im Drei-Punkte-Menü des Klassifizierungsfensters verarbeitet die aktuelle PDF ausdrücklich neu, beispielsweise nach Regeländerungen oder einem Fehler; ungespeicherte Eingaben gehen dabei nach Bestätigung verloren. **OCR-Text einlesen** im selben Menü erkennt nach Bestätigung alle Seiten neu, auch bei vorhandener Textschicht, und ersetzt das vorbereitete Ergebnis durch neue Klassifikationsvorschläge. Unveränderte Dateien werden danach weiterhin aus diesem Ergebnis geöffnet; der Worker überschreibt es nicht. Bei noch laufender Verarbeitung OCR nach Abschluss erneut anstoßen. Bei Fehlern bleiben manuelle Metadateneingabe und Verwerfen möglich. Sind Backend oder SQL-Update nicht verfügbar, zeigt die Oberfläche den Fehler; die automatische Erkennung benötigt das Backend.

Ist eine vorgeschlagene Kategorie oder ein Dokumententyp inzwischen deaktiviert oder gelöscht, bleibt der Vorschlag mit **bitte neu wählen** sichtbar. Vor dem Speichern eine gültige Zuordnung auswählen oder das Feld ausdrücklich leeren. Ungültige Zuordnungen werden nicht gespeichert.

Nicht lesbare Dateien und PDFs über der Erkennungsgrenze von 50 MiB erhalten ebenfalls den Status **Erkennung fehlgeschlagen**. Solange eine Datei gesperrt ist oder sich beim Lesen verändert, bleibt sie **Wartet auf Erkennung** und wird erneut versucht. Manuelle Archivierung und Verwerfen setzen voraus, dass die Webanwendung die Quelldatei sicher lesen kann; die Prüfsumme schützt auch hier vor einem zwischenzeitlichen Austausch.

## Speicherung und Wiederanlauf

Die MariaDB-Tabelle `verarbeitungsauftraege` speichert Dateiname, SHA-256, Status, Versuche, Fehler und das JSON-Ergebnis mit Dokumenttext, Metadatenvorschlägen und Erkennungsquellen. Diese **ungeprüften Vorschläge** sind noch keine Einträge in `dokumente` und keine Archiv-Metadateien. Änderungen im geöffneten Formular werden weiterhin erst beim Speichern archiviert; der Auftrag ist kein automatischer Speicher für Formulareingaben.

Konnte die Datei noch nicht sicher gelesen werden, ist das SHA-256-Feld leer und es gibt keine Vorschläge. Die Lesbarkeit wird beim nächsten Durchlauf erneut geprüft. Bei manueller Archivierung wird die geprüfte SHA-256 der archivierten Datei im Auftrag hinterlegt.

PHP und Python verwenden pro Datei dieselbe verbindungsgebundene Datenbanksperre. Stirbt ein Prozess, gibt MariaDB seine Sperre frei. Ein späterer Aufruf übernimmt den unterbrochenen Auftrag. Die Archivierung markiert den Auftrag innerhalb ihrer Dokumenttransaktion als abgeschlossen und entfernt dessen vorläufigen Text. Die SHA-256-Prüfung verhindert das Speichern eines Entwurfs zu einer zwischenzeitlich ausgetauschten Datei.

Entfernt man eine Datei außerhalb der Anwendung aus der Inbox, löscht der nächste Worker-Durchlauf deren vorläufigen Text und markiert den Auftrag als entfernt.

Der laufende Worker merkt sich fertig verarbeitete Dateien anhand von Geräte-/Dateikennung, Größe und Änderungszeiten. Solange diese Merkmale und der gespeicherte Auftragsstatus passen, liest und hasht er die PDF nicht erneut. Ein Worker-Neustart, ein geänderter Dateistand oder eine geleerte Auftragstabelle führt zu erneuter Prüfung. Manuelle Verarbeitung und Archivierung prüfen den Inhalt stets vollständig.

Fehlgeschlagene, unveränderte Dateien werden nicht endlos erneut analysiert. **Erkennung erneut starten** wiederholt den Versuch; ein geänderter Dateiinhalt wird beim nächsten Durchlauf neu verarbeitet. Fehler einer Datei blockieren die übrigen Dateien nicht.

## Konfiguration und Update

Bestehende Installation: Anwendungsdienste stoppen, [update_inbox_processing.sql](../sql/update_inbox_processing.sql) einmal auf der richtigen Datenbank ausführen, Code und Compose-Datei gemeinsam aktualisieren und Images neu bauen. Das SQL-Skript kann wiederholt ausgeführt werden. Es verändert keine Archivdaten. Bei Neuinstallationen ist die Tabelle bereits in `schema.sql` enthalten.

```bash
sudo docker compose stop eablage-web eablage-backend eablage-worker
# SQL-Update über den zur Instanz gehörenden Datenbankzugang ausführen.
sudo docker compose up -d --build
sudo docker compose ps
sudo docker compose logs eablage-worker --tail 100
```

Enthält die eingesetzte Compose-Datei noch keinen Dienst `eablage-worker`, vor dem Dateiaustausch nur `eablage-web` und `eablage-backend` stoppen. Vollständige Befehle einschließlich `--env-file .env.fresh -f compose.fresh.yaml -p eablage` für die Installation mit integrierter Datenbank stehen unter [Aktualisierung und Deployment](UPDATE.md).

Optionale Einstellungen in der Instanz-`.env`:

```dotenv
EABLAGE_INBOX_ENABLED=true
EABLAGE_INBOX_INTERVAL=60
EABLAGE_OCR_ENABLED=true
EABLAGE_OCR_LANGUAGES=deu+eng
```

Das Intervall ist die Pause zwischen Durchläufen (5–3600 Sekunden), keine feste Startzeit. Der Worker arbeitet jeweils eine Datei ab. Er berücksichtigt Dateien erst, wenn Größe und Änderungsdaten über zwei Scans unverändert sind und die letzte Änderung mindestens 15 Sekunden zurückliegt. Beim ersten Start dauert es deshalb mindestens ein Prüfintervall. Bei externen Kopien ist das eine Stabilitätsprüfung; zuverlässig ist das Kopieren unter einer temporären Endung und anschließendes Umbenennen nach `.pdf`. Der Webupload sperrt seine Datei bis zum vollständigen Schreiben.

`EABLAGE_INBOX_ENABLED=false` deaktiviert die automatische Prüfung; die manuelle Klassifizierung bleibt aktiv. Für eine sofortige Pause kann `docker compose stop eablage-worker` verwendet werden. `docker compose start eablage-worker` setzt die Prüfung fort.

Der Worker nutzt denselben Backend-Quellcode und dieselben OCR-Werkzeuge wie FastAPI. Er benötigt nur lesenden Zugriff auf `/data` und Schreibrechte auf die Datenbank. Inbox und Archiv werden aus den Datenbankparametern ermittelt; es gibt keinen zusätzlichen Inbox-Pfad in `.env`. Der Worker öffnet keinen Netzwerkport. Es wird kein Redis oder weiterer Nachrichtendienst benötigt.

## Sicherung und Wiederherstellung

Die integrierte Archiv-Datensicherung lässt die reproduzierbare Auftragstabelle aus. Inbox-PDFs deshalb in die Dateisicherung einbeziehen, wenn auch noch nicht archivierte Dokumente gesichert werden sollen. Nach einer Rücksicherung werden alte Aufträge gelöscht und aus der Inbox neu erzeugt. Eine Rücksicherung ist während einer aktiven Verarbeitung gesperrt; nach deren Abschluss erneut versuchen, bei Bedarf den Worker vorher stoppen. Neue Verarbeitungen sind während der Rücksicherung blockiert.

## Prüfliste nach dem Update

1. PDF in die Inbox legen und ohne Klick auf die Vorbereitung warten.
2. **Klassifizieren** öffnen, Vorschläge prüfen und speichern.
3. Neue PDF sofort manuell klassifizieren, bevor der Worker sie verarbeitet.
4. Bei laufender Verarbeitung dieselbe Datei öffnen: Warteansicht statt doppelter OCR.
5. Fehlerhafte PDF und gültige PDF gemeinsam ablegen: gültige Datei wird weiterverarbeitet.
6. Worker stoppen: manuelle Klassifizierung bleibt möglich.

OCR-Grenzen und gemischte PDFs: [OCR](OCR.md). Technische Zuständigkeiten: [Technik](TECHNIK.md).

