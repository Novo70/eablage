# Optionaler Desktop-Modus

Seit Version 4.6.0 ist der Desktop-Modus als eigene Darstellung wählbar. Die
Standarddarstellung bleibt voreingestellt. Die modusspezifischen Regeln stehen in `web/desktop.css`. Gemeinsame Regeln stehen in `web/app.css` und `web/explorer.css`.

## Aktivieren und zurückwechseln

Im Menü **Verwaltung** unter **Einstellungen** die Auswahl **Darstellung** nutzen:

- **Standard:** bisherige Darstellung.
- **Desktop:** optionaler Desktop-Entwurf.

Die Darstellung wechselt sofort, ohne Neuladen; offene Formulare und die
Dokumentauswahl bleiben erhalten. Die Radiobuttons sind auch mit Tab und
Pfeiltasten bedienbar. Auf Touch-Geräten und in schmalen Fenstern erläutert ein
Hinweis bei gewähltem Desktop-Entwurf die Voraussetzungen.

Die bisherigen Testadressen funktionieren weiterhin:

- Desktop-Entwurf: `explorer.php?layout=desktop`
- Bisherige Darstellung: `explorer.php?layout=touch`

Diese Adressen relativ zur eigenen Installation öffnen. Bei bereits vorhandenen
Abfrageparametern `&layout=desktop` beziehungsweise `&layout=touch` anhängen.
Die Auswahl gilt auch nach Seitenwechseln und wird nur im jeweiligen Browser
unter `eablage.layout` gespeichert. Andere Geräte und Browser behalten ihre
eigene Auswahl. Bei Auswahl über das Menü wird ein vorhandener `layout`-Parameter
aus der aktuellen Adresse entfernt, damit Neuladen die neue Auswahl beibehält.
Andere Abfrageparameter und der URL-Anker bleiben erhalten.

Die zusätzliche CSS-Datei wird nur bei gewähltem Desktop-Entwurf geladen. Eine
kleine Ergänzung in `navbar.php` liest die Auswahl und setzt
`data-ea-layout="desktop"` am HTML-Element. Ohne JavaScript oder ohne Auswahl
gilt die bisherige Ansicht. Ist Browserspeicher gesperrt, funktioniert die
Auswahl per Menü oder URL weiterhin für die aktuell aufgerufene Seite.

Die Desktop-Regeln greifen ausschließlich ab 1100 CSS-Pixeln Breite bei einem
primären Zeigegerät mit feiner Positionierung und Hover-Unterstützung. Auf
Touch-Geräten und in schmaleren Fenstern bleibt die bisherige Darstellung
auch bei gespeicherter Desktop-Auswahl aktiv. Eine hohe Bildschirmauflösung
allein löst keine kompakte Ansicht aus. Bei Hybridgeräten entscheidet die vom
Browser gemeldete primäre Eingabemethode.

## Umfang

Der Desktop-Modus nutzt eine 250 Pixel breite linke Seitenleiste. Suche,
Ablage, Vorgänge und Eingang sind direkt erreichbar. Darunter stehen alle
Verwaltungsfunktionen und die Darstellungswahl dauerhaft sichtbar unter der
dezenten Überschrift **Verwaltung**. Die Seitenleiste kann bei niedrigen Fenstern
gescrollt werden; ein Aufklappen des Verwaltungsmenüs entfällt.

Blaue Haupttitelleisten mit weißer Schrift, eine weiße Seitenleiste und der
Hintergrundverlauf des Standardlayouts verbinden beide Darstellungen.
Untergeordnete Titelleisten bleiben hell mit dunkler Schrift. Weiße Fokusrahmen
auf den blauen Titelleisten, kräftigere Rahmen und klar markierte aktive
Einträge sichern die Erkennbarkeit der Bedienelemente.
Flache Flächen, kleinere Eckenradien und kompaktere
Formulare und Tabellen prägen die Darstellung. Die bisherige Schriftgröße
des Desktop-Entwurfs bleibt erhalten. Die Arbeitsfläche nutzt die verfügbare
Fensterbreite rechts neben der Seitenleiste statt einer Begrenzung auf 1920
Pixel. Randlose PDF-Bereiche, Scrollbereiche und Fußaktionen bleiben erhalten.

Im Standard-Layout, auf Touch-Geräten und unter 1100 CSS-Pixeln bleibt die
bisherige horizontale Navigation mit aufklappbarem Menü aktiv. Beim Wechsel
von Desktop auf Standard wird das Menü geöffnet gehalten, damit die
Darstellungswahl und ihr Tastaturfokus sichtbar bleiben.

## Oberer und unterer Rand der Arbeitsansichten

Im Desktop-Variablenblock von `web/desktop.css` stehen:

```css
--ea-page-top: 10px;
--ea-page-bottom: var(--ea-page-top);
```

Damit haben die zweigeteilten Arbeitsansichten oben und unten jeweils 10 Pixel
Abstand zum Browserfensterrand. Änderungen an `--ea-page-top` werden automatisch
für unten übernommen. Für einen abweichenden unteren Rand kann
`--ea-page-bottom` einen eigenen Pixelwert erhalten.

Die Höhenregeln in `app.css` und `explorer.css` verwenden den unteren Rand mit
`--ea-space-6` als Rückfallwert. Dadurch behalten Standard- und Touch-Modus ihre
bisherigen Abstände. Andere Inhaltsabstände bleiben unabhängig. Bei niedrigen
Fenstern oder sehr hohen Inhalten gelten weiterhin die vorhandenen
Mindesthöhen; in diesen Fällen kann Scrollen erforderlich sein.

## Aufteilung zwischen Arbeitsbereich und Vorschau

Ab 1600 CSS-Pixeln Fensterbreite mit primärer Mausbedienung erscheint im
Desktop-Layout in der Vorschau-Titelzeile eine Schaltfläche mit zwei nach außen
zeigenden Pfeilen und dem Tooltip **Vorschau verbreitern**. Ein Klick schaltet
auf **Breite Vorschau** um. Dann zeigen die Pfeile nach innen und der Tooltip
lautet **Vorschau schmaler anzeigen**:

| Auswahl | Arbeitsbereich links | Vorschau rechts |
| --- | --- | --- |
| Ausgeglichen | 1/2 | 1/2 |
| Breite Vorschau | 1/3 | 2/3 |

Ein weiterer Klick stellt die hälftige Aufteilung wieder her. Die Auswahl
gilt für Ablage, Vorgänge, Dokumentansicht, Bearbeitung und Klassifizierung
und wird pro Browser unter `eablage.previewSplit` gespeichert. Formulare,
Dokumentauswahl und PDF bleiben beim Umschalten geladen. Bei gesperrtem
Browserspeicher gilt die Auswahl nur für die aktuelle Seite.

Unter 1600 Pixeln und im Standard-Layout gelten die bisherigen Aufteilungen.
Der Schalter bleibt in schmaleren Desktop-Fenstern sichtbar, ist dort aber
deaktiviert. Sein Tooltip nennt die erforderliche Fensterbreite. Auf Touch-Geräten
und im Standard-Modus bleibt er ausgeblendet.
Die Grenze berücksichtigt die Seitenleiste, damit links bei der
Drittelaufteilung noch ungefähr 440 Pixel Arbeitsbreite verfügbar sind.
Die gespeicherte Breitenwahl wird wieder wirksam, sobald das Fenster und
die gewählte Darstellung die Voraussetzungen erfüllen. Auf Tablets ändert
sich die bisherige Darstellung nicht.

Die PDF-Vorschau startet weiterhin mit **Seitenbreite**. Eine automatische
Zwei-Seiten-Ansicht wird nicht gesetzt: Die geprüften nativen Viewer bieten
dafür keine gemeinsame verlässliche URL-Vorgabe. Zwei Seiten können bei
Unterstützung im PDF-Viewer selbst gewählt werden. Referenzen:
[Chromium-Startparameter](https://chromium.googlesource.com/chromium/src/+/HEAD/chrome/browser/resources/pdf/open_pdf_params_parser.ts),
[PDF.js-Vieweroptionen](https://github.com/mozilla/pdf.js/wiki/Viewer-options).

Die Zustandslogik lässt sich ohne Browser prüfen:
`node --test tests/preview_layout.test.cjs`.

## Prüfung in der Testinstallation

1. Ohne Auswahl Suche, Ablage und Dokumentansicht prüfen: bisherige Ansicht.
2. Über **Verwaltung → Darstellung → Desktop** aktivieren, Dokument auswählen, Vorschau
   öffnen und zu Suche, Ansicht, Bearbeiten und Klassifizierung wechseln.
3. In Listen und Formularen lange Texte, Meldungen, Tastaturfokus und offene
   Menüs prüfen. Vorschau und Fußaktionen müssen erreichbar bleiben.
4. Fenster unter 1100 Pixel verkleinern und wieder vergrößern: bisherige
   Darstellung beziehungsweise Desktop-Entwurf ohne Neuladen.
5. Auf einem Tablet ohne Auswahl und mit `layout=desktop` prüfen: ursprüngliche
   Touch-Darstellung. Außerdem Browserzoom 200 % prüfen.
6. Über **Verwaltung → Darstellung → Standard** zurückwechseln und eine andere Seite öffnen:
   die bisherige Darstellung bleibt auch nach Neuladen erhalten.

7. Mit Tab und Pfeiltasten umschalten. In einem bearbeiteten Formular muss die
   Auswahl ohne Neuladen und ohne Verlust der Eingaben funktionieren. Nach Aufruf
   mit `layout=desktop` im Menü Standard wählen und neu laden: Standard bleibt.

8. In jeder der fünf Vorschauansichten bei mindestens 1600 Pixeln zwischen
   beiden Aufteilungen wechseln. Formulareingaben, ausgewähltes Dokument,
   PDF-Seite und manuelle Zoomwahl müssen erhalten bleiben.
9. Mit breiter Vorschau das Fenster unter 1600 Pixel verkleinern, wieder
   vergrößern und zwischen Standard/Desktop wechseln. Die hälftige Ansicht
   muss automatisch zurückkehren, die gespeicherte Auswahl erhalten bleiben.
10. Per Tab und Eingabe-/Leertaste die Breitenwahl bedienen. Titelzeile,
    Vorschauaktionen und linke Formulare auch mit langen Texten prüfen.
11. Bei niedrigen Fenstern bis zu Einstellungen und Darstellungswahl in der
    Seitenleiste scrollen. Alle Menüpunkte per Tastatur erreichen. Auf Standard
    zurückschalten: Die Darstellungswahl muss im offenen Menü sichtbar bleiben.

Der Entwurf ist für einen visuellen Vergleich vorgesehen. Vor Übernahme in
`develop` die tatsächliche PDF-Darstellung und Touch-Bedienung mit eigenen
Testdokumenten beurteilen.



