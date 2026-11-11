# Oberfläche und CSS

Die Anwendung verwendet eigenes CSS, kein Bootstrap-Bundle. Bestehende Klassen wie `.panel`, `.row` und `.btn` sind die gemeinsame Kompatibilitätsschicht. Sie werden nicht allein zur Umbenennung in allen Templates und Tests ersetzt. Neue fachliche Komponenten erhalten `ea-`Klassen.

## Gemeinsame Gestaltung

- Alle einfarbigen Symbole liegen in `web/icons.svg`. PHP-Vorlagen verwenden `eablage_icon()` aus `web/icons.php`; browserseitige Komponenten referenzieren dieselben SVG-Symbole. Die Navigation verwendet 18 Pixel, Überschriften und Aktionen passen die Größe an den Text an. `currentColor` übernimmt die jeweilige Textfarbe, einschließlich Weiß in Titelleisten und Rot bei Löschaktionen. Beschriftungen bleiben sichtbar. Keine externe Icon-Bibliothek; die lokale SVG-Datei muss mit ausgeliefert werden. Statusanzeigen werden separat gepflegt.
- `web/app.css` enthält Variablen, Grundlayout, gemeinsame Komponenten und die Abschnitte der Arbeitsansichten und Stammdaten. Statische Darstellungsregeln hier pflegen, nicht als Inline-Styles in PHP-Vorlagen.
- `web/explorer.css` enthält ausschließlich die Exploreransicht und verwendet dieselben Farb-, Abstands- und Navigationsvariablen.
- Die bestehende Desktopbreite von 1600 Pixeln bleibt erhalten. Seitenbreite und Außenabstand: `--ea-content-width` und `--ea-page-inset`. Rasterabstand: `--ea-grid-gutter`. Ein negativer Rasterrand darf mobil den Seitenaußenabstand nicht überschreiten.
- Navigationshöhe und Seitenbeginn: `--ea-nav-height` und `--ea-page-top`. Beide ändern sich gemeinsam für die mobile Darstellung. Seiten dürfen keinen eigenen `body`-Abstand darüberlegen.
- Neue Abstände aus `--ea-space-1` bis `--ea-space-6` wählen. Vorhandene Werte bei Arbeiten am jeweiligen Bestandteil vereinheitlichen; nicht alle historischen Pixelwerte pauschal ersetzen.
- Statusfarben über `--ea-info-*`, `--ea-success-*`, `--ea-warning-*` und `--ea-danger-*` pflegen. Die vorhandenen Blautöne bleiben die Akzentfarben. Ein Dunkelmodus ist nicht Bestandteil von Version 4.6.1.
- Hauptumschaltung bei 900 CSS-Pixeln: darunter mobile Navigation, gestapelte Arbeitsansichten und Dokumentkarten; ab 900 Desktopansicht. Die ergänzende Grenze bei 600 Pixeln dient den mehrteiligen Nummernfeldern. CSS-Variablen sind ohne Vorverarbeitung nicht in Media Queries einsetzbar; zugehörige CSS- und JavaScript-Abfragen gemeinsam ändern.
- Formular und PDF behalten ihre bisherige Reihenfolge. Der PDF-/Text-Wechsel verwendet `hidden`, die Größenregeln liegen im CSS. Ab 900 px Breite und 600 px Höhe verwenden Ansicht, Klassifizierung und Bearbeitung gleich hohe Fenster mit intern scrollendem Inhalt und festen unteren Aktionen. Der gemeinsame Höhenrahmen berücksichtigt Meldungen oberhalb; sie sind über `meldungen.js` schließbar. Erkennungsdetails und technische Informationen gehören zum linken Scrollbereich. Die Mindesthöhe von 360 px erhält bei sehr langen Meldungen eine nutzbare Arbeitsfläche; dann scrollt die Seite mit. Mobil und bei geringer Fensterhöhe bleibt normales Seitenscrollen erhalten.

Beschriftete Links, ein sichtbarer Tastaturfokus und Escape zum Schließen sind praktische Bedienhilfen. Die Oberfläche wird damit nicht als vollständig barrierefrei zertifiziert. Aufwändige Zusatzfunktionen benötigen einen konkreten Bediennutzen.

Einstellungen und Stammdaten verwenden ab 900 px Breite und 600 px Höhe ebenfalls eine Arbeitsfläche mit fester Titelleiste. Nur Parameter beziehungsweise Stammdateneinträge scrollen innerhalb dieser Fläche; bei Stammdaten bleiben auch die Bereichsreiter und beim Scrollen der Tabellenkopf sichtbar. Die Einstellungen behalten ihre globalen Schaltflächen zum Speichern und Abbrechen, Stammdaten werden weiterhin je Eintrag gespeichert. Die Stammdaten haben keine Fußzeile und keine zusätzliche Bereichsüberschrift; der aktive Reiter kennzeichnet die Liste. Meldungen oberhalb verkleinern die Arbeitsfläche. Das gilt im Standard- und Desktop-Modus sowie auf breiten Touch-Geräten. Unterhalb der Breiten- oder Höhengrenze bleibt das bisherige Seitenscrollen erhalten.

Ab 900 px Breite stehen Bezeichnung und Erläuterung der Stammdaten in eigenen Spalten derselben Zeile. Die Bezeichnung ist schmaler, die Erläuterung nutzt die verbleibende Breite als zweizeiliges, vertikal vergrößerbares Textfeld. Verwendung, Aktionen, Status und gegebenenfalls Sortierung haben feste Spaltenbreiten; Dokumentanzahl und AutoKat-Regeln stehen in zwei eigenen Zeilen einer kompakteren Verwendungsspalte. Die Regelanzahl mit „AutoKat-Regeln“ bleibt zusammen; aufgeklappte Regelverknüpfungen dürfen umbrechen. Speichern-Schaltflächen richten sich nach ihrer Beschriftung. `master_data_layout.js` öffnet die Erläuterungen auf großen Bildschirmen und bewahrt beim Größenwechsel ihren mobilen Aufklappzustand. Es gibt nur ein Erläuterungsfeld pro Datensatz, das über das HTML-Attribut `form` zum jeweiligen Speicherformular gehört. Ohne JavaScript bleiben die nativen Aufklappfelder bedienbar. Unter 900 px bleiben die Eingabefelder untereinander angeordnet. Die Hinweise zu Bezeichnung, Status und Aktionen sind über Fragezeichen in den Spaltenüberschriften erreichbar, mobil über eine kompakte Hinweiszeile. Die Tooltips unterstützen Maus, Tastatur und Antippen, schließen mit Escape und werden außerhalb des Scrollbereichs positioniert, damit sie nicht abgeschnitten werden.

## Browser- und Screenshotprüfung

`tests/ui_layout_browser.test.cjs` rendert zwölf Ansichten (einschließlich der drei Stammdatenbereiche der Dokumentansicht und der Vorgangsübersicht/-details) in 390, 800 und 1440 CSS-Pixeln. PHP führt die tatsächlichen Vorlagen aus; `tests/fixtures/ui_data.php` ersetzt Datenbank und Backend durch synthetische Daten. Bei Klassifizierung und Bearbeitung wird die tatsächliche Darstellungsvorlage ohne die vorgelagerte Dokumentverarbeitung ausgeführt. Die eingebettete PDF wird durch einen gekennzeichneten Platzhalter ersetzt. Die Vorschau selbst ist mit einer echten PDF in der Testinstallation zu prüfen.

Die Tests prüfen Überlauf, Einstellungsspalten, aufklappbare Stammdaten, Fehlererhalt, Navigation/Rücksprung, Verwerfen mit Bestätigung, PDF-/Text-Wechsel und Pagination. Voraussetzungen: PHP CLI mit PDO und mbstring, Node, Playwright und Chromium. Keine zusätzliche Produktionsabhängigkeit.

Die Verwaltungsprüfungen ergänzen lange Parameter- und Stammdatenlisten in allen drei Bereichen. Sie kontrollieren internes Scrollen, feste Kopf-/Fußzeilen und Reiter, sichtbare Aktionen mit Meldung, Standard-/Desktop-Modus, breite Touch-Geräte und den Rückfall auf Seitenscrollen bei kleinen oder niedrigen Fenstern. Für unveränderte mobile Darstellung lassen sich die Bilder bei 390 und 800 px mit der unten beschriebenen Baseline-Prüfung vergleichen.

```bash
node --test tests/ui_layout_browser.test.cjs
node --test tests/master_data_browser.test.cjs tests/explorer_browser.test.cjs tests/document_numbers_browser.test.cjs tests/ollama_browser.test.cjs
```

Optional setzen `PHP_BIN` und `PLAYWRIGHT_EXECUTABLE_PATH` die Programme; die vorhandenen Explorertests verwenden dafür `CHROMIUM_EXECUTABLE_PATH`.

Vor einem Umbau Referenzbilder außerhalb des Repositorys erfassen:

```bash
UI_CAPTURE_ONLY=1 UI_SCREENSHOT_DIR=/tmp/eablage-ui-before node --test --test-name-pattern='layout and review capture' tests/ui_layout_browser.test.cjs
```

Nach dem Umbau mit `UI_SCREENSHOT_DIR=/tmp/eablage-ui-after` die Tests ausführen und beide Bildserien visuell vergleichen. `UI_SOURCE_DIR` kann auf das `web`-Verzeichnis eines separaten Checkouts zeigen, um denselben Test mit einem früheren Quellstand zu rendern. `UI_CAPTURE_ONLY` überspringt nur die Layoutassertionen der Erfassungsfälle; die Auswahl über `--test-name-pattern` grenzt die reine Bilderfassung ein.

Nach bewusster visueller Freigabe kann `UI_BASELINE_DIR` auf die freigegebene Bildserie zeigen. Dann vergleichen die Tests auch die vollständigen PNG-Dateien. Dafür dieselbe Browser-, Betriebssystem- und Schriftumgebung verwenden; Unterschiede werden als visuelle Änderung gemeldet. Baselines nicht automatisch bei einem Fehler überschreiben.

Zusätzlich in der Testinstallation prüfen: lange echte Metadaten und Dateinamen, schmale Fenster und 200 % Browserzoom, Menü mit Maus/Tastatur, mobile PDF-Vollbildansicht sowie die echten Speicher- und Löschvorgänge. Beispieldaten und Headless-Screenshots ersetzen diese Prüfung nicht.
