# Ablage-Explorer

## Bedienung

Im Menü **Ablage-Explorer** öffnen. Die Ansicht besteht aus zwei Hälften:
links Unterordner und Dokumente des gewählten Ordners, rechts die PDF-Vorschau.
Ein Klick öffnet einen Unterordner. Der anklickbare Pfad führt gezielt zu jeder
übergeordneten Ebene. Eine zusätzliche Zurück-Schaltfläche entfällt.
Unterordner zeigen ihre gesamte Dokumentzahl direkt hinter dem Namen;
die Liste enthält auch direkt im aktuellen Ordner liegende Dokumente.
Bei mehr als 100 Einträgen im aktuellen Ordner kann geblättert werden.

Die Dokumente erscheinen als kompakte Kacheln mit Datum, Kategorie, Dokumenttyp,
Partner, Betreff und, wenn vorhanden, Nummer. Der virtuelle Exportdateiname wird
hier nicht angezeigt, da er erst beim Export als Datei entsteht.

Die Dokumente sind nach Datum absteigend sortiert (neueste zuerst, fehlendes Datum
zuletzt). Bei gleichem Datum folgen Kategorie, Dokumenttyp, Partner, Betreff,
Nummer und schließlich die DokID. Das gilt auch für gefilterte Treffer aus
Unterordnern; Ordner selbst bleiben alphabetisch sortiert. Die Exportdateinamen
bestimmen die Anzeigereihenfolge nicht.

**Dokumente filtern (aktueller Ordner inkl. Unterordner)** sucht ohne Beachtung
der Groß-/Kleinschreibung in genau diesen angezeigten Dokumentdaten. Der Filter
gilt für den aktuellen Ordner einschließlich seiner Unterordner. Treffer aus
Unterordnern zeigen zusätzlich den relativen Ordnerpfad. Der PDF-Volltext und
der virtuelle Exportdateiname werden nicht durchsucht.

**Filter leeren** stellt die normale Ordneransicht wieder her. Beim Wechsel in
einen anderen Ordner wird der Filter geleert. Auswahl und Filter werden in der
URL erhalten. Bei der Rückkehr aus der Bearbeitung bleiben sie bestehen, solange
das Dokument weiterhin zum Filter und Ordner gehört; andernfalls folgt die
Ansicht dem Dokument und hebt den Filter auf.

Ein Dokument auswählen, um rechts das Original-PDF anzuzeigen. Die ausgewählte
Dokumentkachel bleibt links blau markiert und wird über der Vorschau nicht wiederholt.
**PDF öffnen**
und **Metadaten bearbeiten** stehen platzsparend in der Vorschau-Kopfzeile.
**PDF öffnen** öffnet es in einem neuen Tab, auch wenn der Browser keine eingebettete Vorschau
unterstützt. **Metadaten bearbeiten** führt zur bestehenden Bearbeitungsseite.
Nach dem Speichern dort **Zurück** wählen: Der Explorer lädt den aktuellen
Bestand neu und wählt das Dokument wieder aus. Nach Änderung von Kategorie,
Partner oder Datum erscheint es entsprechend im neuen virtuellen Ordner.

Die Exportstruktur lässt sich über **Einstellungen** im Menü ändern. Neuladen
im Browser übernimmt zwischenzeitliche Änderungen. Der Kopfbereich enthält nur
den Titel; zusätzliche Erklärzeilen und Aktionsbuttons entfallen.
Die Browser-Zurück-/Vorwärtsnavigation
wechselt zwischen zuvor geöffneten Ordnern und Dokumentauswahlen.
Auf schmalen Bildschirmen entfällt die eingebettete PDF-Vorschau. Die Schaltflächen **PDF öffnen** und **Metadaten bearbeiten** bleiben verfügbar; PDFs werden separat geöffnet.

## Datenquelle und Grenzen

Die Ansicht verwendet denselben vollständigen Backendplan (`GET /export/plan`)
wie der Gesamtexport. Zusätzlich liefert der Plan die Dokumentdaten für die Kacheln. Profil 1 wird bei jedem Laden aus der aktuellen Datenbank
gelesen. Es gibt keine zweite Berechnung von Dateinamen oder Verzeichnisebenen.
Insbesondere werden vom Backend aufgelöste Dateinamenskollisionen unverändert
übernommen. Filterung auf einzelne Ordner findet erst danach im Browser statt.

Die bis zu fünf Ebenen, einschließlich Jahr/Monat und dem Überspringen leerer Werte, werden über das [Gesamtexportprofil](EXPORT.md) konfiguriert.
Bei deaktivierter Verzeichnisstruktur liegen alle Dokumente virtuell direkt in
der Ablage. Ein physisches Exportverzeichnis wird weder gelesen noch angelegt.

Das Original wird ausschließlich über `archiv_pdf.php?dokid=…` angefordert.
PHP prüft den aktiven Datenbankeintrag, bildet `<DokID>dok.pdf` aus seiner
DokID und prüft das Ziel im konfigurierten Archiv.
Es gibt keinen Rückgriff auf Inbox- oder Exportkopien. Fehlende/unlesbare PDFs
liefern eine verständliche Meldung; Links aus dem Archiv auf externe Dateien
werden abgelehnt. Die Metadaten können weiterhin über die bestehende Bearbeitung
geöffnet werden, auch wenn das PDF fehlt.

Die URL speichert virtuelle Ordnerbezeichnungen, die DokID und den Dokumentfilter.
Diese Ordnerangaben werden nicht für Dateisystemzugriffe verwendet. Beim Rückweg
hat die DokID Vorrang: existiert sie nicht mehr, erscheint ein Hinweis und die
Ansicht wechselt zum nächsten noch vorhandenen übergeordneten Ordner.

Die Browseransicht ist ein Stand zum Zeitpunkt des Ladens. Bei Änderungen in
anderen Tabs die Seite im Browser neu laden. Beim Rückweg aus der Bearbeitung wird auch
eine vom Browser zwischengespeicherte Seite neu geladen.
