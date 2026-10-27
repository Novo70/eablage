# Klassifizierungsregeln mit AutoKat

AutoKat gewinnt Metadaten aus dem Dokumenttext. Im Menü **Klassifizierungsregeln** können Regeln angelegt, bearbeitet, gefiltert, getestet und aktiviert beziehungsweise deaktiviert werden. Die grundsätzliche Verarbeitungskette steht im [Benutzerhandbuch](BENUTZERHANDBUCH.md#klassifizierung).

## Regeltypen und Rangfolge

| Typ | Wirkung |
| --- | --- |
| E – Ergänzungsregel | Alle passenden E-Regeln werden verarbeitet. Befüllte Vorgaben überschreiben Werte früherer E-Regeln; leere Vorgaben erhalten sie. Ein gezielter Datumsanker darf die allgemeine Datumserkennung und Datumswerte früherer E-Regeln ersetzen. |
| K – Klassifikationsregel | Alle Treffer werden ermittelt. Die erste passende K-Regel liefert die Klassifikation und darf E-Werte überschreiben. Weitere K-Treffer ändern die Metadaten nicht. |

Kleinere Rangwerte werden zuerst verarbeitet, bei gleichem Rang folgt die interne numerische ID. Eine leere Regelvorgabe löscht keine vorhandenen Vorschläge. Kategorie-/Dokumententypwert `0` gilt als nicht zugeordnet. Für eine verlässliche fachliche Priorität möglichst eindeutige Ränge vergeben.

Ergänzungsregeln daher vom Allgemeinen zum Speziellen anordnen: Eine E-Regel auf Rang 10 kann Kategorie „Versicherung“ und Dokumenttyp „Schriftverkehr“ liefern. Eine passende E-Regel auf Rang 20 ersetzt den Dokumenttyp durch „Versicherungsschein“; eine weitere auf Rang 30 ergänzt die Versicherungsnummer. Die Kategorie bleibt erhalten, wenn spätere Regeln dafür keine Vorgabe enthalten. Die Feldherkunft zeigt jeweils die zuletzt wirksame Regel. Das gilt auch für erfolgreich extrahierte Werte; eine erfolglose Extraktion ohne festen Ersatzwert verändert das Feld nicht. Tags werden wie andere Textfelder ersetzt, nicht zusammengeführt.

Fachlich zusammengehörende Felder gemeinsam angeben, wenn eine speziellere Regel ihre Zuordnung ändert: Eine neue Kategorie allein entfernt beispielsweise keinen zuvor gesetzten Dokumenttyp. Die K-Logik bleibt unverändert: Die erste passende K-Regel überschreibt ihre befüllten Felder; in ihren leeren Feldern bleiben E-Vorschläge erhalten. Weitere K-Treffer werden nur zur Prüfung angezeigt.

Die stabile RegelID, etwa `R0042`, bezeichnet die Regel unabhängig vom Rang. Regelversion und Rang erscheinen zusammen beispielsweise als `R0042/v3/r70`.

## Such- und Ausschlussbegriffe

Mehrere Begriffe werden durch Semikolon getrennt. Alle positiven Begriffe müssen vorkommen:

```text
Rechnung;Stadtwerke;Kundennummer
```

Ein einziger gefundener Ausschlussbegriff verhindert den Treffer:

```text
Mahnung;Stornorechnung
```

Groß-/Kleinschreibung spielt keine Rolle. Begriffe werden an Wortgrenzen erkannt; `Rechnung` trifft daher nicht innerhalb von `Verbrauchsabrechnung`. Mehrteilige Phrasen sind möglich; ihre innere Schreibweise muss zum eingelesenen Text passen. Es handelt sich um wörtliche Suchbegriffe, nicht um reguläre Ausdrücke. Leere Einträge zwischen Semikolons werden ignoriert. Ohne positiven Suchbegriff trifft eine Regel nicht.

## Feste Werte und Textextraktion

Feste Vorgaben sind für Kategorie, zweite Kategorie, Dokumenttyp, Partner, Betreff und Tags möglich. Nummern werden getrennt nach Nummernart konfiguriert; siehe [Nummern erkennen](NUMMERN.md#autokat).

| Regelfeld | Bedeutung |
| --- | --- |
| `prDatum` | Datumssuche hinter dem ersten eigenständigen Vorkommen |
| `prBetreff` | Startanker für den Betreff |
| `prBetreffAnz` | Anzeigepräfix vor dem Betreff |
| `BetreffLänge` | Maximale Entnahmelänge; 0 begrenzt nicht |
| `BetreffEnde` | Optionale Endmarkierung |

Beim Betreff gilt die frühere Grenze aus Endanker und positiver Maximallänge. Ohne gefundenen Endanker gilt die Maximallänge beziehungsweise der verbleibende Text. Der Sonderwert 99 beendet die Übernahme zusätzlich an einer Leerzeile innerhalb der ersten 99 Zeichen. Zeilenumbrüche werden entfernt, mehrfache Leerzeichen zusammengefasst und Schrägstriche durch Bindestriche ersetzt. Dieses Betreffverhalten gilt nicht für die neuen Nummernerkennungen.

## Neue Regel anlegen

1. **Klassifizierungsregeln → Neue Regel** öffnen.
2. Typ **E** oder **K**, Rang und verständliche Bezeichnung festlegen.
3. Mindestens einen Suchbegriff eintragen; bei Bedarf Ausschlussbegriffe ergänzen.
4. Feste Metadaten und/oder Extraktionsfelder setzen.
5. **Speichern** wählen und die Regel mit geeignetem Dokumenttext testen.

Die nächste RegelID wird automatisch vorgeschlagen, beispielsweise `R0001`, `R0002`. Eine neue Regel beginnt mit Version 1. Änderungen an Such-/Ausschlussbegriffen, Regeltyp, Zuordnungen, festen Ergebniswerten oder Extraktionsfeldern erhöhen die fachliche Version. Änderungen an Rang, Aktivstatus, Bezeichnung oder Kommentar erhöhen sie nicht. Die RegelID bleibt stabil.

Alternativ kann bei einem neuen Dokument ohne passende Klassifikationsregel (K-Regel) im Drei-Punkte-Menü der Klassifizierung **Speichern und Regel vorbereiten** gewählt werden. Treffer von Ergänzungsregeln (E-Regeln) schließen diese Aktion nicht aus. Manuell ergänzte Werte werden in die Regelvorlage übernommen. Das Dokument wird zuerst archiviert. Danach öffnet sich eine inaktive neue K-Regel mit den gespeicherten Kategorien, dem Dokumenttyp, Partner, Betreff, Nummer und Tags als Vorbelegung. Such- und Ausschlussbegriffe sowie gegebenenfalls dynamische Extraktionsanker müssen fachlich festgelegt werden; insbesondere Betreff und Nummer können nur für das Ausgangsdokument gelten. **Abbrechen** verwirft allein die Regelvorlage, nicht das archivierte Dokument. Nach dem Speichern führt der Weg zum Eingang zurück. Eine spätere Aktivierung erfolgt bewusst über das Regel-Formular oder die Übersicht.

## Übersicht, Status und Mobilansicht

Filter und Suche der Übersicht helfen, Regeln nach Typ, Status und Inhalt einzugrenzen. Der Status kann direkt über das Symbol umgeschaltet werden. Es ändert sich erst nach bestätigter Speicherung; währenddessen ist der Knopf gesperrt. Die Seite wird nicht neu geladen, Filter und Position bleiben erhalten. Bei aktivem Statusfilter bleibt die umgeschaltete Zeile bis zum nächsten Filtern oder Neuladen sichtbar.

Fehler einer Zeilenaktion erscheinen direkt unter der betroffenen Zeile. Bearbeitungsformulare verwenden zentrale Meldungen. Auf schmalen Bildschirmen werden Regelidentität, Typ und Status sowie Suchbegriffe und Ergebnis kompakt gezeigt; Rang und Nutzung sind in der reduzierten Übersicht ausgeblendet. Die Bearbeitung bietet weiterhin alle Felder.

## Regeln testen und verbessern

In der Übersicht Dokumenttext in die Testfunktion einfügen und **Test ausführen** wählen. Angezeigt werden passende E-Regeln, die primäre und weitere K-Regeln, Metadaten und Herkunft. Der Test berücksichtigt das aktive Regelwerk, nicht nur eine isolierte gerade ausgewählte Regel.

Typische Dokumente und ähnliche Gegenbeispiele verwenden. Mehrere K-Treffer durch präzisere Such-/Ausschlussbegriffe eingrenzen. Den Rang nur ändern, wenn die fachliche Priorität tatsächlich anders sein soll. Änderungen speichern, bevor sie mit dem aktiven Regelwerk geprüft werden.

## Trefferstatistik

Trefferzahl und Zeitpunkt des letzten Treffers werden beim tatsächlichen Archivieren aktualisiert. Eine beteiligte Regel wird je archiviertem Dokument höchstens einmal gezählt. Analyseaufrufe und Tests ohne Archivierung erhöhen die Statistik nicht. Weitere gemeldete K-Treffer sind keine zusätzliche primäre Klassifikation.

Ein regelbasiert gesetztes Feld besitzt eine Herkunftskennzeichnung. Die Dokumenthistorie hält bei der Archivierung beteiligte Regelreferenzen strukturiert fest. Änderungen an der heutigen Regel verändern diese gespeicherten Referenzen nicht rückwirkend. Das frühere Info-Feld bleibt nur für den Altbestand erhalten.

