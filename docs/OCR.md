# Lokale OCR-Texterkennung

OCR ergänzt bei gescannten PDFs den Dokumenttext für Klassifizierung und Volltextsuche. Das Original-PDF wird weder ersetzt noch um eine Textschicht ergänzt. Es wird ausschließlich in einem privaten temporären Arbeitsverzeichnis verarbeitet; keine Dokumentdaten werden an einen externen OCR-Dienst übertragen.

## Bedienung

Beim Klassifizieren und bei **Text neu einlesen** versucht die Anwendung zunächst die vorhandene PDF-Textschicht auszulesen. Ist der gesamte Dokumenttext leer, startet sie automatisch OCR. Erkannter Text läuft durch die bestehenden Datums-, AutoKat- und Textvergleichsfunktionen und wird beim Archivieren als Doktext in Datenbank und JSON gespeichert. Die Originaldatei und ihre SHA-256-Prüfsumme bleiben unverändert.

Im Drei-Punkte-Menü der Klassifizierung und der Dokumentbearbeitung steht **OCR-Text einlesen** zur Verfügung. Dieser ausdrückliche Aufruf erkennt alle Seiten aus dem sichtbaren PDF-Bild neu, auch wenn bereits eine Textschicht vorhanden ist. So lassen sich fehlerhafte Ergebnisse einer anderen OCR-Software ersetzen oder gemischte PDFs vollständig erkennen. Die automatische Erkennung übernimmt vorhandenen Text weiterhin; sie bewertet dessen Qualität nicht.

In der Klassifizierung wird ein vorbereitetes Inbox-Ergebnis bewusst neu verarbeitet. AutoKat, Datumserkennung und Textvergleich erzeugen Vorschläge aus dem neuen OCR-Text. Die Bestätigung weist darauf hin, dass ungespeicherte Eingaben ersetzt werden. Der neue Text wird erst beim Archivieren in Dokumentdatenbank und JSON übernommen. Ist dieselbe Datei noch in Verarbeitung oder Übertragung, wird OCR nicht parallel gestartet; nach Abschluss **OCR erneut starten** wählen.

Bei archivierten Dokumenten bleiben eingegebene Metadaten erhalten. Nach dem Einlesen die Vorschau prüfen und bei Bedarf **Neu klassifizieren** verwenden. Die Übernahme des neuen Textes und der Metadaten erfolgt erst mit **Speichern**. OCR-Fehler verändern keine gespeicherten Werte. Die Qualität hängt von Scanauflösung, Ausrichtung, Sprache und Vorlage ab; Erkennungswerte vor dem Speichern prüfen. Handschrift ist nicht das Ziel dieser Funktion.

## Installation und Grenzen

Das Backend-Image enthält Poppler und Tesseract mit Deutsch und Englisch und wird auch vom Worker verwendet. Nach dem Update alle Anwendungsdienste gemeinsam aktualisieren und die Images neu bauen; ein bloßer Containerneustart ergänzt keine Werkzeuge. Die vollständigen Befehle für beide Installationsvarianten stehen unter [Aktualisierung und Deployment](UPDATE.md#gemeinsam-bauen-und-starten).

Optional in der Instanz-.env:

```dotenv
EABLAGE_OCR_ENABLED=true
EABLAGE_OCR_LANGUAGES=deu+eng
```

Mit `EABLAGE_OCR_ENABLED=false` wird automatische und manuelle OCR deaktiviert. Weitere Sprachen müssen zuerst als Sprachpaket im Backend-Image installiert sein. Die Umgebung wird beim Erstellen des Containers übernommen.

Eine PDF darf höchstens 50 MiB groß sein. Die Textextraktion ist auf 90 Sekunden begrenzt; für OCR gilt zusätzlich ein Limit von 20 Seiten. Bilder werden seitenweise gerendert, die längste Seite auf 2500 Pixel begrenzt, Tesseract verwendet einen Thread. Temporäre Dateien werden auch bei Fehlern entfernt. Bei Überschreitung wird kein Teiltext übernommen. Bei großen PDFs kleinere Dokumente verwenden. Der [Inbox-Worker](STAPELVERARBEITUNG.md) bereitet neue Dateien im Hintergrund vor. Bei einem manuellen Aufruf ohne vorbereitetes Ergebnis wird die Erkennung unmittelbar ausgeführt und kann die Anzeige verzögern. Für bestehende Dokumente wird keine automatische Massen-OCR gestartet.

Bei einer Installation ohne das mitgelieferte Backend-Image müssen im Python-Prozess pdfinfo, pdftotext, pdftoppm und tesseract unter /usr/bin samt Sprachpaketen sowie ein beschreibbares temporäres Verzeichnis verfügbar sein. PHP ruft die interne Backend-API auf.
