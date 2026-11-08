# PDF-Vorschau mit PDF.js

Alle eingebetteten Vorschauen verwenden den lokal mitgelieferten PDF.js-Viewer:
Ablage-Explorer, Vorgänge, Dokumentansicht, Bearbeiten und Klassifizierung.
Direkte PDF-Links und „PDF öffnen“ liefern weiterhin das unveränderte Original
über `archiv_pdf.php` beziehungsweise `pdf.php`. Archivierung, OCR, Metadaten und
Datenbankschema ändern sich nicht.

Der Viewer startet auf Seitenbreite und bietet Seitennavigation, Zoom, Textsuche,
Textauswahl, Druck und Download. Die Volltextsuche benötigt eine Textschicht im
PDF; der getrennt gespeicherte OCR-Text bleibt über die vorhandene Textansicht
verfügbar. PDF-Skripte und PDF-Bearbeitung sind deaktiviert. Der Viewer übernimmt
keine Änderungen in Archivdateien.

Die Schaltfläche mit dem Seitensymbol direkt in der PDF.js-Werkzeugleiste
wechselt zwischen Einzelseiten und Doppelseiten (1+2, 3+4 usw.). Der Tooltip benennt die nächste
Aktion, der aktive Zustand die zweiseitige Darstellung. Aktuelle Seite und
manueller Zoom bleiben beim Umschalten erhalten; bei automatischer Seitenbreite
passt PDF.js den Zoom an. Die Auswahl bleibt beim Dokumentwechsel innerhalb
der Ansicht erhalten. Der Schalter ist Teil der internen Werkzeugleiste der
PDF-Ansicht. Änderungen über das PDF.js-Menü aktualisieren seinen Zustand.
Schaltfläche und Symbole werden über `pdfjs.js` und `pdfjs.css` ergänzt; die
PDF.js-Bibliothek, `viewer.mjs` und `viewer.css` bleiben unverändert.

## Einbindung und Gestaltung

- `web/dokumentvorschau.php`: gemeinsame Komponente einschließlich Titel,
  Werkzeugleiste, Leerzustand und PDF-/Textbereich für alle fünf Ansichten.
- `web/dokumentvorschau.js`: gemeinsame Viewer-URL, Iframe-Verwaltung, PDF/Text-
  Umschaltung und bedarfsgesteuertes Laden des gespeicherten Dokumenttexts.
- `web/dokument_text.php`: lesender JSON-Endpunkt für den Text aktiver
  Archivdokumente; gelöschte Dokumente sind ausgeschlossen.
- `web/pdfjs_loader.mjs`: lädt CSS und Integrationsskript mit der vom Server
  berechneten Inhaltsversion, danach den Viewer.
- `web/pdfjs.js`: Viewer-Optionen, erlaubte PDF-Quellen, Fehlermeldung und Anpassung
  automatischer Zoomstufen bei einer Größenänderung des Vorschaufensters.
- `web/pdfjs.css`: Farben und reduzierte Werkzeugleiste. Änderungen hier vornehmen,
  statt die mitgelieferten Viewer-Skripte oder `viewer.css` zu bearbeiten.
- `web/vendor/pdfjs/`: festgeschriebene Laufzeitdateien einschließlich Worker,
  Schriftarten, CMaps, WASM und Lizenztexten.

Der Viewer bleibt zur CSS-Isolierung in einem lokalen Iframe. PDF.js und alle
Zusatzdateien werden vom eigenen Webserver geladen; ein CDN, externer Dienst,
Node.js im Betrieb oder ein zusätzlicher Docker-Dienst ist nicht erforderlich.
Auch auf Smartphones und Tablets wird die Vorschau direkt eingebettet angezeigt.
Unter 900 Pixeln Breite steht sie unter dem Arbeitsbereich und nutzt 70 % der
aktuellen Fensterhöhe, mindestens 360 Pixel. Der bisherige mobile Platzhalter
„PDF im Vollbild öffnen“ entfällt; die normalen Original-PDF-Links bleiben verfügbar.
Viewer-HTML, PDF.js-Module, Integrationsskript, CSS und Symbole erhalten dieselbe Inhaltsversion
in ihrer URL. Änderungen an den mitgelieferten Integrationsdateien werden so beim
nächsten Seitenaufruf geladen, auch wenn ältere Dateien noch im Browsercache
liegen. Das Integrationsskript ist vor dem Viewer initialisiert.
Die PDF/Text-Umschaltung und die Breitenwahl laden den Viewer nicht neu.
Die Vorschau heißt überall **Dokumentvorschau**. **PDF öffnen**, **PDF/Text** und
die optionale Breitenwahl stehen rechts in derselben Werkzeugleiste.
**Metadaten bearbeiten** und die dokumentbezogene Aktion **Zuordnung entfernen**
stehen links unten außerhalb des scrollenden Inhalts. Der Text wird in Explorer
und Vorgängen erst beim ersten Umschalten geladen; Dokumentwechsel brechen
laufende Textabfragen ab. Alte Antworten können die neue Auswahl nicht ersetzen.
Klassifizierung und Bearbeitung verwenden weiterhin den aktuellen Formular-/OCR-Text.
Manueller Zoom bleibt bei einer Größenänderung bestehen.

Import- und Renderfehler zeigen einen Hinweis mit „PDF öffnen“ zum Original.
Ist in der sichtbaren Vorschau nach 15 Sekunden noch keine Seite gezeichnet,
erscheint ein vorläufiger Hinweis mit demselben Link. Er verschwindet nach
einem erfolgreichen Rendern. Zusätzlich bleibt
„PDF öffnen“ außerhalb des Viewers erreichbar, auch bei deaktiviertem JavaScript.
Die bestehenden serverseitigen Prüfungen der PDF-Endpunkte bleiben maßgeblich.

## Installation und Update

Die Dateien werden durch das vorhandene Dockerfile automatisch mitkopiert.
Bei Installationen ohne Container den gesamten Ordner `web/vendor/pdfjs` sowie
die neuen Integrationsdateien übernehmen. Der Webserver muss `.mjs` als
JavaScript (`application/javascript` oder `text/javascript`) und `.wasm` als
`application/wasm` ausliefern. Der Legacy-Build benötigt einen Browser mit JavaScript-Modulen und
Web-Worker-Unterstützung. Die vollständige eAblage-Vorschau ist mit
**Chromium 141 und 151** geprüft. Für die Browserfamilien verwendet Mozilla
als Build-Ziele unter anderem Chrome ab 125, Firefox ESR und Safari ab 18.
Diese Build-Ziele sind keine von eAblage geprüften Mindestversionen. Für
Edge, Firefox und Safari einschließlich iOS liegt noch kein eigener Test vor.

Der vendorte Stand ist **PDF.js 6.5.438 Legacy** aus dem offiziellen Release:
https://github.com/mozilla/pdf.js/releases/tag/v6.5.438

Archiv: `pdfjs-6.5.438-legacy-dist.zip`

SHA-256 des heruntergeladenen Archivs:
`55138aa33b5f1f6f68809895338b947f395f344eb6381d424b2420d6daa6a844`

Enthalten sind die Laufzeitdateien ohne Source Maps, Debugger und Beispiel-PDF.
Sprachdateien sind auf Deutsch und Englisch als Rückfall reduziert;
`web/locale/locale.json` ist entsprechend angepasst. In `web/viewer.html` sind
Titel, Sprache, der eAblage-Modullader und die auf eigene Quellen
begrenzte `connect-src`-Regel angepasst. Alle übrigen Upstream-Laufzeitdateien
bleiben unverändert. Lizenztexte der Schriftarten, CMaps, ICC- und WASM-Dateien
bleiben enthalten.

Für Aktualisierungen den offiziellen Legacy-Build verwenden, Archivchecksumme
festhalten und den kompletten Vendor-Stand gemeinsam austauschen. Bibliothek,
Worker und Viewer müssen dieselbe Version haben. Die genannten Anpassungen an
HTML und Sprachindex erneut anwenden. Beim Austausch der PDF.js-Module oder einer Änderung am Modullader dessen
URL-Version in `viewer.html` erhöhen und anschließend die Tests ausführen.
Sicherheitsupdates von PDF.js sind Teil der eAblage-Abhängigkeitspflege.

Unter **Einstellungen → Aktualisierung** stehen die installierten Versionen
von eAblage und PDF.js in getrennten Bereichen. Die eAblage-Version stammt aus
`EABLAGE_WEB_VERSION` in `web/pdfjs_update.php`. Dieser Wert gilt auch für
die Fußzeile der Einstellungsseite und wird bei einem Release mitgepflegt.
**Auf Aktualisierung prüfen** ruft erst auf Klick die neuesten stabilen
Releases von Mozilla und eAblage ab. OSV erhält den Paketnamen `pdfjs-dist`
und die installierte Version für die Suche nach bekannten Sicherheitslücken.
Das Ergebnis und der Zeitpunkt der letzten vollständigen Prüfung bleiben für
die Browsersitzung erhalten. Bei nicht erreichbaren Quellen zeigt die Seite
den jeweiligen Ausfall an.

Die Prüfung zeigt beide öffentlichen Release-Versionen an. Eine neuere
Mozilla-Version erscheint als Hinweis. Die Update-Anleitung wird angeboten,
sobald die öffentliche eAblage-Version neuer als die installierte ist.
Das Update zuerst in die Testinstallation und anschließend über den
[üblichen eAblage-Updateweg](UPDATE.md) übernehmen. Danach in den Einstellungen
die angezeigte Version kontrollieren und ein Dokument in der Vorschau öffnen.

Der GitHub-Workflow **PDF.js-Aktualisierung prüfen** läuft montags und lässt sich
manuell starten. Der Zeitplan prüft den Standardbranch des Repositorys. Bei einem manuellen
Start prüft der Workflow den ausgewählten Branch. Die Laufzusammenfassung nennt die mitgelieferte und die aktuelle
Mozilla-Version sowie passende OSV-Meldungen. Ein neueres Release, ein
Sicherheitsbefund oder ein Abruffehler markiert den Lauf als fehlgeschlagen.
Der Paketwechsel erfolgt anschließend als geprüfte Repository-Änderung.

## Prüfung

```sh
node --test tests/pdfjs_browser.test.cjs
node --test tests/document_preview.test.cjs tests/document_links_preview.test.cjs
node --test tests/document_preview_loading.test.cjs tests/document_text_endpoint.test.cjs
node --test tests/explorer_browser.test.cjs tests/preview_layout.test.cjs
```

Die Browsertests benötigen Playwright mit installiertem Chromium; die
Komponententests zusätzlich jsdom und für die PHP-Vorschau PHP CLI.
Die neuen Browsertests verwenden ein selbst erzeugtes dreiseitiges PDF und einen
lokalen HTTP-Server. Sie prüfen echte Darstellung und Textschicht, Seitennavigation,
Suche, Größenwechsel, manuellen Zoom, Sonderzeichen in Dateinamen, Fehlerfälle
und das Ausbleiben externer Netzwerkanfragen.

Vor der Freigabe zusätzlich eigene große Scans, Drucken und Download sowie
die eingebettete mobile Vorschau und Original-PDF-Links in der Testinstallation prüfen. Bei großen Scans hängt die
Darstellungsleistung vom Endgerät ab; PDF.js wird im Browser ausgeführt.
