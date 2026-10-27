# Nummern am Dokument

Ein Dokument kann mehrere benannte Nummern enthalten, beispielsweise eine Kunden- und eine Vertragsnummer. Pro Nummernart ist genau ein Wert erlaubt. Nummern sind Text: führende Nullen, Buchstaben und die ursprüngliche Schreibweise bleiben erhalten. Die maximale Länge beträgt 255 Zeichen.

## Nummernarten verwalten

Unter **Stammdaten → Nummernarten** können Bezeichnung, optionale Erläuterung (bis 1000 Zeichen), Sortierung und Aktivstatus gepflegt werden. Erläuterungen helfen bei der manuellen Zuordnung und werden der lokalen KI mitgegeben. Die Liste beginnt mit Bezeichnung / Erläuterung, gefolgt von Sortierung; technische IDs werden nicht angezeigt. Ein Klick auf **Bezeichnung** ordnet alphabetisch, ein Klick auf **Sortierung** nach Zahlenwert und bei Gleichstand alphabetisch. Ein Pfeil markiert die aktive Spalte. Standard ist die hinterlegte Sortierung. Die Umschaltung betrifft nur die Ansicht und erhält ungespeicherte Eingaben. Neue Nummernarten erhalten eine stabile ID. Umbenennen ändert keine Zuordnungen.

Verwendete Arten können nicht gelöscht werden. Eine Deaktivierung ist erst möglich, wenn auch aus inaktiven AutoKat-Regeln alle Verknüpfungen entfernt wurden. Bestehende Dokumentzuordnungen bleiben sichtbar und recherchierbar. Deaktivierte Arten werden nicht neu vorgeschlagen.

## Nummern bearbeiten

In der Klassifizierung und Dokumentbearbeitung zeigt jede Zeile Nummernart und Wert. **Weitere Nummer hinzufügen** ergänzt eine Zeile; **Entfernen** nimmt sie aus dem Entwurf. Gespeichert wird erst mit dem Dokument. Leere Werte werden nicht gespeichert. Die Zeilen werden nach der Sortierung ihrer Nummernarten geordnet (kleinste Zahl zuerst, bei Gleichstand nach Bezeichnung und ID). Das gilt auch für später ergänzte KI-Vorschläge. Beim Speichern erhalten sie daraus die Positionen 10, 20, 30 usw. Die erste nicht leere Nummer wird als Kompatibilitätswert `number` beziehungsweise `dokumente.Nummer` für kompakte Listen und bestehende Dateinamen-/Verzeichnisoptionen verwendet.

Bestehende Dokumente werden beim Öffnen nach der aktuellen Stammdatensortierung angezeigt. Die gespeicherten Positionen, der Kompatibilitätswert und die JSON-Datei werden beim nächsten Speichern gemeinsam aktualisiert; eine Stammdatenänderung schreibt nicht automatisch den gesamten Archivbestand um.

Beim erneuten Klassifizieren eines archivierten Dokuments werden nur fehlende Nummernarten ergänzt. Bereits vorhandene Nummern bleiben erhalten. Änderungen an Nummern werden zusammen mit den übrigen Metadaten gespeichert und im Ereignisprotokoll festgehalten.

## AutoKat

Jede Regel enthält unter **Nummern erkennen** höchstens eine Erkennungsdefinition pro Nummernart:

| Einstellung | Verhalten |
| --- | --- |
| Nummernart | Zuordnung des erkannten Wertes |
| Startanker | Erster passender Begriff oder Phrase im extrahierten Text; unabhängig von Groß-/Kleinschreibung |
| Zeilenende | Übernahme bis zum Ende der Zeile des Ankers |
| Nächstes Leerzeichen | Übernahme des ersten Wertes hinter dem Anker |
| Endanker | Übernahme bis zur Begrenzung innerhalb derselben Zeile; ohne passenden Endanker kein Ergebnis |
| Maximale Länge | Optional: leer oder 0 begrenzt nicht zusätzlich; sonst höchstens diese Anzahl Zeichen des Nummernwertes |
| Festwert | Wird verwendet, wenn kein Startanker angegeben ist |

Nach dem Anker werden horizontale Leerzeichen und ein anschließender Doppelpunkt übersprungen. Ein Zeilenumbruch wird dabei nicht übersprungen. Liefert das erste Ankervorkommen keinen Wert, werden weitere Vorkommen nicht ausgewertet. Es gibt keine Mehrfachtrefferoption. Die maximale Länge zählt ab dem eigentlichen Wert nach dem Überspringen der Leerzeichen und des Doppelpunkts hinter dem Anker. Enthaltene Leerzeichen zählen mit und bleiben erhalten. Es gilt die frühere Grenze aus Enderkennung und maximaler Länge; ein benötigter, aber fehlender Endanker liefert weiterhin kein Ergebnis. Für Nummern mit Leerzeichen beispielsweise **Zeilenende** und die passende maximale Länge wählen; **Nächstes Leerzeichen** würde schon am ersten enthaltenen Leerzeichen stoppen. OCR und PDF-Textreihenfolge können die Erkennung beeinflussen.

**Eine Klassifizierungsregel (K) greift:** Nur die erste passende K-Regel liefert Nummern. Ergänzungsregeln und weitere K-Regeln liefern keine zusätzlichen Nummern. Auch fehlende Nummernarten werden von diesen Regeln nicht ergänzt.

**Keine K-Regel greift:** Passende Ergänzungsregeln (E) werden in bestehender Rang-/ID-Reihenfolge ausgewertet. Je Nummernart gilt der erste erfolgreiche Wert. Nachrangige Regeln können weitere, noch fehlende Arten ergänzen.

Die Nummernzuordnung zeigt die Regelnummer als farbiges Herkunftsbadge: grün für K-Regeln, gelb für E-Regeln. Der Tooltip enthält Regelversion, Rang und Bezeichnung; der Link öffnet die Regel unter Beibehaltung des Dokumententwurfs. Ein Regeltest wertet das gespeicherte aktive Regelwerk aus; Änderungen an Erkennungen erhöhen die fachliche Regelversion.

## Lokale KI

Die KI erhält aktive Nummernarten einschließlich Erläuterungen. Sie liefert je Art höchstens einen Wert und eine wörtliche Belegstelle. Die Anwendung ordnet exakte Stammdatennamen den internen IDs zu und verwirft unbekannte Arten, Mehrfachzuordnungen und unbelegte Werte.

Aus dem Textvergleich übernommene Nummern zeigen ein **DokID**-Badge mit Link zum Vergleichsdokument und dem Übereinstimmungswert im Tooltip. AutoKat-Nummern behalten ihre Regelherkunft.

Stimmt ein bereits vorhandener Nummernwert mit dem KI-Vorschlag überein, erscheint das Badge **KI bestätigt** direkt neben dem Nummernfeld zwischen Herkunftsbadge und Entfernen-Button; das bisherige Herkunftsbadge bleibt erhalten. Leere Nummern werden ergänzt und mit **KI** gekennzeichnet. Abweichende Werte stehen als übernehmbare Vorschläge bereit. Manuelle Änderungen werden nicht überschrieben; nach einer Änderung bleibt der KI-Vorschlag erneut übernehmbar. Eine während der Hintergrundberechnung bewusst geleerte oder entfernte Nummer wird nicht automatisch wieder eingesetzt. Die Belegstelle steht am KI-Badge als Tooltip zur Verfügung: per Maus, Tastaturfokus oder Antippen. Escape oder Antippen außerhalb schließt den Beleg. Nur abweichende Vorschläge benötigen eine zusätzliche Zeile unter dem Feld.

## Suche und Export

Die allgemeine Suche berücksichtigt alle Nummern, einschließlich zusätzlicher Nummern. Neben der ursprünglichen Schreibweise wird ein Suchwert aus Großbuchstaben A–Z und Ziffern 0–9 verglichen: `AB-123/45` und `AB12345` finden einander. Reine Trennzeichen erzeugen keinen leeren Suchvergleich. Die Teilzeichenkettensuche in den übrigen Feldern bleibt bestehen.

JSON-Metadaten verwenden Schema 4 und enthalten `numbers` mit Nummernart-ID, Bezeichnung, Wert und Reihenfolge. Die vollständige Nummernliste ist auch Teil der Exportdaten. Ältere JSON-Dateien bleiben lesbar; fehlende neue Nummerninformationen können durch die Archivprüfung aus der Datenbank ergänzt werden. Das reine PDF enthält keine zusätzlichen Metadaten.

## Datenhaltung und Migration

- `nummernart`: stabile Text-ID, Bezeichnung, Erläuterung, Sortierung und Aktivstatus.
- `dokument_nummer`: DokumentID, NummernartID, Wert, normalisierter Suchwert und Reihenfolge; eindeutige Kombination aus Dokument und Nummernart. Endgültiges Löschen eines Dokuments entfernt dessen Nummern über einen Fremdschlüssel.
- `autokat_nummer`: Regel-ID, Nummernart-ID, Startanker, Endmodus, Endanker, Festwert und Reihenfolge. Die maximale Länge steht auch für neue Erkennungen zur Verfügung. Das Anzeigepräfix wird nur für übernommene alte Erkennungen verwendet.

`dokument_nummer` ist die fachliche Quelle. `dokumente.Nummer` bleibt als automatisch gepflegter Anzeigewert der ersten Nummer für bestehende Listen-/Exportoptionen erhalten und ist nicht unabhängig bearbeitbar. Die Spalte wird auf 255 Zeichen erweitert. Die alten Regelspalten werden nicht mehr in der Oberfläche bearbeitet; maßgeblich sind die Erkennungsdefinitionen in `autokat_nummer`.

`sql/update_document_numbers.sql` übernimmt bisherige Dokumentnummern und Nummernerkennungen einmalig als Art **Allgemein**. Übernommene Erkennungen verwenden den Modus **Bisherige Erkennung**, der ihr bisheriges Präfix-, Längen- und Endankerverhalten erhält. Ein bewusster Wechsel auf einen neuen Endmodus verwendet die oben beschriebenen Zeilengrenzen und erhält Schrägstriche.

Der Parameter `nummernMigration` verhindert, dass ein erneuter Skriptlauf gelöschte Nummern aus dem Anzeigewert rekonstruiert. Nicht manuell zurücksetzen. Die SQL-Datei `web/nummern_schema.sql` enthält dieselbe Migration für die Rücksicherung älterer Dumps und muss bei Schemaänderungen synchron gehalten werden; ein Test prüft die Übereinstimmung.

Datenbanksicherungen enthalten die drei neuen Tabellen. Die Rücksicherung erkennt vollständige neue Tabellenbestände; bei älteren unterstützten Sicherungen werden die Nummern nach dem Import automatisch migriert.
