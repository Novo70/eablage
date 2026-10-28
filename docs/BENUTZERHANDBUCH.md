# eAblage Benutzerhandbuch

eAblage verarbeitet PDF-Dokumente im Browser: vom Eingang über die automatische oder manuelle Klassifizierung bis zur Archivierung und späteren Recherche. Automatisch erkannte Angaben sind Vorschläge und müssen vor dem Speichern geprüft werden.

Für Installation und Administration führt die [Dokumentationsübersicht](README.md) zu den zuständigen Anleitungen. Dieses Handbuch beschreibt den täglichen Arbeitsablauf.

Am Desktop sind **Suche**, **Ablage** und **Eingang** direkt in der Kopfleiste erreichbar. Unter **Verwaltung** stehen Stammdaten, Regeln, Papierkorb, Export und Einstellungen. Auf kleinen Bildschirmen enthält das Menü oben rechts alle Bereiche. Der aktuelle Bereich ist hervorgehoben.

## Empfohlener Arbeitsablauf

1. PDF in den Eingang kopieren, dorthin scannen oder über die Oberfläche hochladen.
2. Beim Dokument **Klassifizieren** wählen.
3. PDF und vorgeschlagene Metadaten einschließlich Herkunft kontrollieren.
4. Fehlende oder falsche Angaben ergänzen; insbesondere das Dokumentdatum prüfen.
5. **Speichern** wählen. Das Dokument wird archiviert und verschwindet bei erfolgreichem Abschluss aus dem Eingang.
6. Über **Dokumente** recherchieren oder über den **Ablage-Explorer** wieder öffnen.
7. Bei Bedarf Metadaten bearbeiten oder eine PDF exportieren.

Regelmäßig [Datenbank und Dateien zusammen sichern](SICHERUNG.md). Die zentrale Seite **Export & Datensicherung** enthält Export, Sicherung und Wartungsfunktionen.


## Gescannte PDFs

PDFs ohne Textschicht werden automatisch lokal erkannt. Bei unvollständiger oder fehlerhafter Textschicht **OCR-Text einlesen** im Drei-Punkte-Menü der Klassifizierung oder Bearbeitung verwenden. Dabei werden alle Seiten neu erkannt. In der Klassifizierung entstehen nach Bestätigung neue Vorschläge, die ungespeicherte Eingaben ersetzen. Im Bearbeiten bleibt die Metadateneingabe erhalten; neuer Text wird erst mit **Speichern** übernommen. [OCR-Anleitung und Grenzen](OCR.md).

## Vorbereitete Dokumente im Eingang

Die Eingangsliste zeigt den Verarbeitungsstatus. **Klassifizieren** öffnet fertige Vorschläge oder startet die Erkennung sofort. **Erkennung erneut starten** im Drei-Punkte-Menü erzeugt nach Bestätigung neue Vorschläge. Details und Fehlerbehandlung: [Stapelverarbeitung](STAPELVERARBEITUNG.md).

## Dokumenteneingang

**Eingang** zeigt die PDFs im konfigurierten Inbox-Verzeichnis. Bereits dort abgelegte Dateien sind ohne zusätzlichen Upload verfügbar. Auf kleinen Bildschirmen wechseln **Verwerfen** und **Klassifizieren** bei Platzmangel gemeinsam in die nächste Zeile.

### PDF hochladen

1. Im Bereich **Dokument hochladen** eine PDF auswählen. Am Desktop kann sie auch in die Ablagefläche gezogen werden.
2. Es wird jeweils eine Datei übernommen. Das Ablegen startet noch keinen Upload.
3. **Datei in den Eingang hochladen** wählen.
4. Die erfolgreiche Übernahme kontrollieren; die Datei erscheint unter ihrem ursprünglichen Namen.

Akzeptiert werden Dateinamen mit PDF-Endung ohne Pfadbestandteile oder Steuerzeichen. Eine gleichnamige Datei im Eingang wird nicht überschrieben. Zuerst die vorhandene Datei verarbeiten oder die neue Datei vor dem Upload umbenennen. Im mitgelieferten Docker-Setup sind bis zu **50 MiB pro PDF** erlaubt. Die effektive Grenze steht im Uploadbereich; eine abweichende PHP-Konfiguration oder ein vorgeschalteter Server kann sie reduzieren.

Reine Bildscans werden bei Bedarf lokal per OCR erkannt. Der Inbox-Worker bereitet die Erkennung und Klassifizierung automatisch vor; **Klassifizieren** bleibt jederzeit manuell nutzbar. Bei Problemen aus einer mobilen Cloud-Dateiauswahl die PDF zunächst lokal herunterladen und dann auswählen; siehe [Fehlerbehebung](FEHLERBEHEBUNG.md).

### Dokument verwerfen

Eine PDF, die nicht archiviert werden soll, kann im Eingang mit **Verwerfen** entfernt werden. In der Klassifizierung befindet sich **Eingangsdatei verwerfen** im Drei-Punkte-Menü oben im Formular. Nach Bestätigung wird die Eingangsdatei endgültig gelöscht; sie gelangt nicht in den Papierkorb. Vor dem Löschen wird geprüft, ob sich die Datei seit dem Öffnen der Seite geändert hat. Für bereits archivierte Dokumente gilt der [Papierkorb](PAPIERKORB.md).

### Dokument öffnen

Beim gewünschten Dokument **Klassifizieren** wählen. Die Anwendung öffnet die vorbereiteten Vorschläge oder startet die Texterkennung und Klassifizierung sofort. Die Arbeitsansicht stellt Formular und PDF gegenüber. Die Textansicht hilft, Erkennungsprobleme und Suchanker zu prüfen. Auf kleinen Bildschirmen wird die PDF separat über die angebotene Schaltfläche geöffnet.

## Klassifizierung

### Reihenfolge der Erkennung

1. Allgemeine Datumserkennung.
2. Alle passenden Ergänzungsregeln vom Typ **E**.
3. Alle passenden Klassifikationsregeln vom Typ **K**; die erste nach Rangfolge liefert die primäre Klassifikation.
4. Falls keine K-Regel passt: Textvergleich mit bereits archivierten Dokumenten. Er ergänzt freie Felder und erhält belegte Vorschläge.
5. Manuelle Kontrolle und Korrektur.

E-Regeln füllen freie Felder und überschreiben mit befüllten Vorgaben die Werte früherer E-Regeln. Leere Vorgaben erhalten die bisherigen Werte. Eine gezielte Datumserkennung darf das allgemein erkannte Datum und Datumswerte früherer E-Regeln ersetzen. Die primäre K-Regel darf Vorschläge von E-Regeln überschreiben; leere Regelwerte löschen nichts. Weitere K-Treffer werden angezeigt, verändern das Ergebnis aber nicht. Kleinere Rangwerte werden zuerst verarbeitet; bei gleichem Rang entscheidet die interne Regel-ID. Allgemeine E-Regeln deshalb vor spezielleren E-Regeln anordnen. Die [AutoKat-Anleitung](AUTOKAT.md) erläutert Regelbildung und Prioritäten vollständig.

### Metadaten prüfen

| Feld | Verwendung |
| --- | --- |
| Dokumenten-Datum | Fachliches Datum; vor dem Speichern erforderlich |
| Kategorie und zweite Kategorie | Thematische Zuordnung; zweite Kategorie ist optional |
| Dokumententyp | Art des Dokuments |
| Partner | Absender, Vertragspartner oder andere zugehörige Person/Organisation |
| Betreff | Inhaltliche Kurzbeschreibung |
| Nummern | Mehrere benannte Nummern, beispielsweise Kunden- und Vertragsnummer; je Art genau ein Wert ([Details](NUMMERN.md)) |
| Tags | Ergänzende Suchbegriffe als Text |
| Dokumenttext | Extrahierter, bei Bedarf korrigierbarer Text für Volltextsuche und Vergleich |

Kategorie, Dokumententyp, Partner und Betreff sollten fachlich überprüft werden, auch wenn das Formular sie nicht sämtlich als Pflichtfelder erzwingt. Aktive [Stammdaten](STAMMDATEN.md) stehen für neue Zuordnungen zur Auswahl.

Die Datumserkennung sucht das erste gültige Datum im Text oder nach dem Regelanker. Sie unterstützt Zahlen mit zwei- oder vierstelliger Jahreszahl, ausgeschriebene deutsche Monate sowie Monat/Jahr. Bei Monat/Jahr wird der erste Monatstag verwendet. Zweistellige Jahre `00–69` bedeuten `2000–2069`, `70–99` bedeuten `1970–1999`. Solche Vorschläge besonders prüfen. Ohne Treffer bleibt das Feld leer; das Tagesdatum wird nicht als Ersatz eingesetzt.

### Herkunft erkennen

Kennzeichnungen neben den Feldern zeigen die Herkunft:

- **E**: Ergänzungsregel.
- **K**: Primäre Klassifikationsregel.
- **D**: Allgemeine Datumserkennung.
- **Textvergleich**: Vergleichsdokument mit seiner DokID.

Der Tooltip einer Regel nennt RegelID, Version, Rang, Bezeichnung und Ermittlungsmethode. Referenzen verwenden beispielsweise `R0019/v2/r250`. Mehrere K-Treffer zeigen eine mögliche Regelüberschneidung; Such- und Ausschlussbegriffe sollten dann gezielt geprüft werden.

### Regeln und Vergleichsdokumente öffnen

Ein normaler Klick auf ein Herkunfts-Badge öffnet die Regel oder das Vergleichsdokument im selben Tab. **Zurück** führt zur ursprünglichen Klassifizierung. Speichern auf der Regel-Seite ändert nur die Regel, Speichern im Vergleichsdokument nur dieses Dokument.

Die ungespeicherten Klassifizierungsfelder werden beim Wechsel vorübergehend im Browser-Tab abgelegt. Bei Rückkehr werden die Eingaben einschließlich zweiter Kategorie, PDF-/Textansicht und Scrollposition einmalig wiederhergestellt. Der Zwischenspeicher wird anschließend entfernt. Das Ausgangsdokument wird durch diesen Wechsel nicht archiviert.

Kann der Browser nicht zwischenspeichern, bleibt die Klassifizierung geöffnet und zeigt einen Hinweis. Verwaiste Einträge werden nach 24 Stunden beim nächsten Aufruf bereinigt. Das Schließen des Tabs beendet den temporären Ablauf. Nach einer Regeländerung bleiben bei Rückkehr die bisherigen Eingaben erhalten; sie sind keine automatisch neu berechnete Klassifikation.

**Abbrechen** in der Klassifizierung führt ohne Archivierung zum Eingang zurück.

### Regel aus einer neuen Klassifizierung vorbereiten

Wenn keine Klassifikationsregel (K-Regel) passt, bietet das Drei-Punkte-Menü im Kopf der Klassifizierung **Speichern und Regel vorbereiten** an. Auch bei erkannten Ergänzungsregeln (E-Regeln) bleibt diese Aktion verfügbar. Die manuell vervollständigten Dokumentdaten werden in die Regelvorlage übernommen. Diese Aktion prüft und archiviert das Dokument zunächst genau wie **Speichern**. Nur nach erfolgreicher Archivierung öffnet sie die Maske für eine neue Klassifikationsregel. Schlägt das Archivieren fehl, bleibt die Regelmaske geschlossen.

Die neue Regel übernimmt Kategorie, Kategorie 2, Dokumenttyp, Partner, Betreff, Nummer und Tags aus dem gespeicherten Dokument. Sie ist zunächst inaktiv; mindestens einen Suchbegriff musst du selbst festlegen. Prüfe insbesondere, ob Betreff und Nummer auch für spätere Dokumente gelten sollen. Dokumentdatum und dynamische Suchanker werden nicht aus einem einzelnen Dokument abgeleitet.

**Abbrechen** in der Regelmaske verwirft nur den Regelentwurf; das Dokument bleibt archiviert. Nach dem Speichern der Regel führt der Weg zurück zum Eingang. Erst nach einer bewussten Aktivierung kann die Regel für spätere Dokumente verwendet werden. Dieser Ablauf unterscheidet sich vom Öffnen einer bestehenden Regel über ein Herkunfts-Badge: Dabei wird das neue Dokument noch nicht archiviert.

### Textvergleich beurteilen

Der Vergleich ermittelt, welcher Anteil der relevanten unterschiedlichen Wörter des neuen Dokuments auch in einer archivierten Vorlage vorkommt. Kurze Wörter und häufige deutsche Stopwörter werden ignoriert; Mehrfachvorkommen zählen einmal. Der Prozentwert belegt keine identischen PDF-Dateien.

Die beste Vorlage wird nach Ähnlichkeit, Zahl gemeinsamer Wörter und bei Gleichstand höherer DokID gewählt. Inaktive Kategorien oder Dokumententypen werden nicht als neue Zuordnung übernommen. Ein Dokument schließt sich bei erneuter Klassifizierung selbst als Vorlage aus. Vergleichslängen und Schwellenwert sind unter [Konfiguration](KONFIGURATION.md) beschrieben.

## Archivierung

**Speichern** in der Klassifizierung nimmt das Dokument in das Archiv auf:

1. Die nächste DokID wird aus der höchsten vorhandenen DokID ermittelt; bei leerer Tabelle gilt `dokStartwert`.
2. Ein Datensatz mit Metadaten, Dokumenttext, Zielpfad, Archivierungsdatum, Originaldateiname und SHA-256-Referenz wird vorbereitet und angelegt.
3. Die PDF wird exklusiv als `<DokID>dok.pdf` ins Archiv kopiert. Vorhandene Ziele werden nicht überschrieben; die Kopie muss dieselbe Prüfsumme besitzen wie die Eingangskopie.
4. `<DokID>meta.json` mit Schema 4 wird erstellt; beteiligte AutoKat-Regeln erhalten ihre Trefferstatistik.
5. Nach erfolgreichem Datenbank-Commit wird die Eingangskopie entfernt und der Eingang angezeigt.

Neue Dokumente haben zunächst kein Änderungsdatum. Die Dokumenthistorie dokumentiert die Erkennung einschließlich Regelreferenzen. Das bisherige Info-Feld wird nicht mehr befüllt und nicht in neue JSON-Metadateien geschrieben.

Bei einem Fehler vor dem Commit wird die Datenbanktransaktion zurückgerollt. Nur selbst erzeugte Dateien werden bereinigt; vorhandene Archivdateien bleiben erhalten, ebenso die Eingangskopie. Kann die Eingangskopie nach erfolgreicher Archivierung nicht gelöscht werden, erscheint eine Warnung: Das bereits archivierte Dokument nicht erneut speichern.

Datenbank und Dateisystem bilden keine gemeinsame atomare Transaktion. Nach einem Absturz oder unklaren Speicherausgang zuerst [Archiv prüfen](ARCHIVPRUEFUNG.md), bevor erneut archiviert wird. Die Details zu Originalnamen, Prüfsummen und Begleitdateien stehen im [Dokumentmodell](METADATEN_JSON.md).

## Dokumente recherchieren

### Suche und Filter

Unter **Dokumente** lassen sich Suchbegriff, Datum von/bis, Kategorie und Dokumententyp kombinieren. Der Kategoriefilter berücksichtigt auch die zweite Kategorie. Inaktive Stammdaten bleiben in den Recherchefiltern auswählbar.

Der Suchbegriff wird an Leerraum in einzelne Wörter zerlegt. Jedes Wort muss in mindestens einem der Felder Partner, Betreff, Nummer oder Tags vorkommen. **Volltextsuche** ergänzt den gespeicherten Dokumenttext; die PDF wird dafür nicht erneut eingelesen. Die Suche arbeitet mit Teilzeichenketten, anders als die Wortgrenzensuche der AutoKat-Regeln.

In den **Nummern** werden zusätzlich unterschiedliche Schreibweisen gefunden: Leerraum, Punkte, Schrägstriche, Klammern sowie Bindestriche und gebräuchliche Strichvarianten werden für diesen Vergleich ignoriert, ebenso Groß-/Kleinschreibung. So findet `AB-123/45` auch `AB12345` und umgekehrt. Führende Nullen bleiben erhalten. Die gespeicherte Nummer wird nicht geändert. In den anderen Feldern bleibt die bisherige Suche erhalten; sämtliche Filter und die UND-Verknüpfung mehrerer Suchwörter gelten weiterhin. Eingaben, die nur aus den genannten Trennzeichen bestehen, lösen keinen zusätzlichen Nummernvergleich aus.

### Ergebnisliste

Ergebnisse sind nach Dokumentdatum absteigend geordnet; eine Seite enthält bis zu 50 Treffer. Die Liste zeigt Datum, Kategorie, Dokumenttyp, Partner, Betreff und gegebenenfalls Nummer. Suchbegriffe werden in Partner und Betreff hervorgehoben.

- Betreff beziehungsweise PDF-Symbol anklicken: PDF in einem neuen Tab öffnen.
- Stiftsymbol anklicken: Metadaten bearbeiten.
- Seitensteuerung verwenden: weitere Treffer anzeigen.

Auf kleinen Bildschirmen ist der Suchfilter platzsparend eingeklappt.

## Archivierte Dokumente bearbeiten

Aus der Suche oder dem [Ablage-Explorer](EXPLORER.md) die Bearbeitung öffnen. Änderbar sind Datum, Kategorien, Dokumenttyp, Partner, Betreff, Nummer, Tags und Dokumenttext. DokID, Archivpfad, Originaldateiname und Prüfsumme sind geschützt.

**Speichern** übernimmt eine tatsächliche Änderung in Datenbank und JSON-Metadatei und setzt das Änderungsdatum aus der Datenbankuhr. Unverändertes Speichern setzt kein neues Änderungsdatum und erzeugt keine automatische Formatmigration. Die Archiv-PDF bleibt unverändert.

Nach erfolgreichem Speichern bleibt die Bearbeitung mit Erfolgsmeldung geöffnet. **Zurück** führt zur vorherigen Suche mit Filtern, zum Explorer oder zur ursprünglichen Klassifizierung eines Vergleichsdokuments. Beim Rückweg in den Explorer wird der aktuelle Bestand geladen; eine geänderte Zuordnung kann das Dokument in einen anderen virtuellen Ordner verschieben.

Wurde das Dokument seit dem Öffnen des Formulars bereits geändert, beispielsweise in einem anderen Browserfenster, werden veraltete Eingaben nicht gespeichert. Die eigenen Eingaben bleiben im Formular sichtbar. Benötigte Werte vor dem erneuten Öffnen sichern und anschließend mit dem aktuellen Dokumentstand abgleichen. Auch **Neu klassifizieren** und das erneute Texteinlesen übernehmen keinen veralteten Formularstand. Nach einem Update müssen bereits zuvor geöffnete Bearbeitungsformulare gegebenenfalls neu geöffnet werden.

### Hilfsaktionen

**Neu Klassifizieren** berechnet Vorschläge für das bestehende Dokument. **Text neu einlesen** extrahiert den Text erneut aus der archivierten PDF und erhält bereits geänderte Formularwerte. Beide Aktionen speichern noch nichts dauerhaft. Erst **Speichern** übernimmt die Änderungen. **Exportieren** lädt die archivierte PDF mit dem Namen des Einzelexportprofils herunter.

Zusatzfunktionen stehen im Drei-Punkte-Menü des Bearbeitungsfensters. **Speichern**, **In den Papierkorb** und **Zurück** beziehungsweise **Abbrechen** stehen gemeinsam am Formularende.

## Dokumenthistorie

Im Menü **Ereignisprotokoll** werden gespeicherte Ereignisse nach Zeitpunkt und Ereignis-ID absteigend angezeigt, jeweils 50 pro Seite. Filter nach DokID, Ereignistyp und RegelID (z. B. `R0042`) lassen sich kombinieren. In der Dokumentbearbeitung führt unter **Technische Informationen** ein Link direkt zur Historie des Dokuments; er öffnet die Historie im selben Tab. **Zurück** führt zur Dokumentbearbeitung, auch nach Filtern oder Blättern. Ungespeicherte Eingaben werden bei diesem Seitenwechsel im Browser zwischengespeichert und beim Rückweg einmalig wiederhergestellt; das Dokument wird dadurch nicht gespeichert. Dafür muss JavaScript aktiviert und der Sitzungsspeicher verfügbar sein.

Erfasst werden Archivierung mit Klassifikationsherkunft, tatsächliche Bearbeitung mit alten/neuen Feldwerten und Hinweis auf geänderten Dokumenttext, Papierkorb, Wiederherstellung und endgültige Löschung. Bei erneutem Klassifizieren wird die Erkennung mit der nächsten tatsächlichen Speicherung dokumentiert. Reine Vorschauen und Speichern ohne Änderung erzeugen kein Ereignis. Vollständige Dokumenttexte werden nicht im Protokoll dupliziert. Die Ereignisse werden als kompakte aufklappbare Gruppen angezeigt. Die Details sind zunächst eingeklappt; ein Klick auf die Ereigniszeile öffnet sie (auch per Tastatur). Bei endgültiger Löschung steuert **Einstellungen → Archiv → protokollLoeschdatenBehalten**, ob identifizierende Daten erhalten bleiben. **Ja** (Standard) erhält Originaldateiname, Dokumentdatum, Partner, Betreff und gegebenenfalls Nummer sowie Kategorie-/Dokumenttypbezeichnungen und frühere Details. **Nein** entfernt sämtliche Details der bisherigen Ereignisse dieses Dokuments und erzeugt ein Löschereignis ohne Details. DokID, Zeitpunkt, Ereignistyp und Quelle bleiben erhalten. Die Einstellung wirkt auf künftige Löschungen; bereits gelöschte Dokumente und vorhandene Sicherungen werden nicht rückwirkend bereinigt.

Die Historie beginnt mit der Einführung dieser Funktion. Alte Info-Texte bleiben unverändert unter **Technische Informationen** sichtbar. Ereignisse bleiben nach endgültigem Löschen eines Dokuments erhalten; sie sind dann weiterhin über die DokID auffindbar. Eine automatische Löschung der Historie gibt es nicht. [Datenmodell und Sicherung](DOKUMENTHISTORIE.md).

## Dokumente im Papierkorb

Im Bearbeitungsformular verschiebt **In den Papierkorb** ein archiviertes Dokument nach Bestätigung. Die Datei und der Datenbankeintrag bleiben erhalten, während Suche und Ablage-Explorer es ausblenden. Über den Menüpunkt **Papierkorb** lässt sich das Dokument wiederherstellen oder nach Eingabe der DokID endgültig löschen. Der Bestätigungsdialog erläutert, ob identifizierende Protokolldetails erhalten bleiben oder auch frühere Details entfernt werden; die Ereignisse selbst bleiben mit DokID und Zeitpunkt erhalten. Näheres: [Papierkorb](PAPIERKORB.md).

## Weitere Funktionen

| Aufgabe | Anleitung |
| --- | --- |
| Regel anlegen, testen oder deaktivieren | [AutoKat](AUTOKAT.md) |
| Kategorie oder Dokumententyp pflegen | [Stammdaten](STAMMDATEN.md) |
| Nach virtuellen Ordnern navigieren | [Explorer](EXPLORER.md) |
| Einzelne PDF oder gesamten Bestand exportieren | [Export](EXPORT.md) |
| Anwendungseinstellungen ändern | [Konfiguration](KONFIGURATION.md) |
| Datenbank sichern oder leere Installation befüllen | [Sicherung und Wiederherstellung](SICHERUNG.md) |
| Begleitdateien prüfen, reparieren oder ergänzen | [Archivprüfung](ARCHIVPRUEFUNG.md) |

Die Archivprüfung ist auch unabhängig von einer Wiederherstellung verwendbar. Änderungen am Bestand erfolgen nur über die jeweils ausdrücklich gestartete Aktion; die Vorschauen der Wartungsfunktionen schreiben noch nichts.

## Ablagesystematik dokumentieren

Unter **Stammdaten** können Kategorien und Dokumententypen eine optionale mehrzeilige **Erläuterung** erhalten. Sie dokumentiert Definition, Beispiele und Abgrenzungen und wird bei lokalen KI-Vorschlägen berücksichtigt. In der Liste unter der Bezeichnung aufklappen und mit **Speichern** sichern. [Details](STAMMDATEN.md#optionale-erläuterung).

## Ergänzender KI-Vorschlag

Bei aktivierter lokaler KI kann in der Klassifizierung über das Drei-Punkte-Menü **KI-Vorschlag erstellen** ein Vorschlag abgerufen werden. Leere Felder werden beim Eintreffen der Antwort direkt ergänzt und mit einem violetten **KI**-Badge gekennzeichnet. Die Angaben bleiben bis zum Speichern bearbeitbare Vorschläge. Für bereits gefüllte Felder erscheinen abweichende Vorschläge direkt darunter; Anklicken übernimmt den angezeigten Wert; identische Werte erhalten das Badge **KI bestätigt** bei der Feldbezeichnung beziehungsweise neben dem Nummernfeld. Ein Klick auf den Vorschlag ersetzt auch einen bereits gefüllten Wert ohne Bestätigungsdialog. Alle Angaben bleiben bis zum Speichern manuell änderbar; **Abbrechen** verwirft die ungespeicherte Klassifizierung. Die KI kann außerdem bis zu fünf konkrete Tags mit zusätzlichem Informationswert vorschlagen; Wiederholungen der übrigen Angaben werden herausgefiltert. Einrichtung und Bedienung: [Ollama](OLLAMA.md).

Für die Diagnose falscher Zuordnungen unter Einstellungen → Entwicklerwerkzeuge zunächst „KI-Diagnose anzeigen“ einschalten. Danach nach einem manuellen KI-Abruf im Drei-Punkte-Menü **KI-Anfrage anzeigen** wählen. Die reine Anzeige zeigt den ursprünglichen Anfrageinhalt und erlaubt das Kopieren des Anfrage-JSON, ohne weitere KI-Verarbeitung oder Änderung der Formularwerte. [Anleitung](OLLAMA.md#tatsächliche-ki-anfrage-anzeigen).

Bei aktivierter **KI im Hintergrund** bereitet der Worker Vorschläge bereits vor dem Öffnen vor. Auswählbar sind Aus, Nur ohne automatische Klassifizierung und Für alle Dokumente. Ergänzungsregeln allein verhindern die KI nicht. Die Maske bleibt während der Berechnung bedienbar; vorhandene und seit dem Öffnen bearbeitete Angaben werden nicht automatisch ersetzt. [Bedienung](OLLAMA.md#ki-im-hintergrund).


Die Verwaltung der Nummernarten und das Verhalten von AutoKat, KI und Suche beschreiben die [Nummernhinweise](NUMMERN.md).
