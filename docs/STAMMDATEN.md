# Stammdaten

Kategorien ordnen Dokumente thematisch zu; Dokumententypen beschreiben ihre Art, beispielsweise Rechnung oder Vertrag. Die Verwaltung gilt für den aktuellen Bestand und neue Zuordnungen.

## Bedienung

Im Menü **Stammdaten** über die Reiter **Kategorien** und **Dokumententypen** zwischen den Listen wechseln. Der weitere Reiter **Nummernarten** verwaltet Kunden-, Vertrags- und andere Nummernarten; [Details](NUMMERN.md). Alle Listen beginnen mit der Bezeichnung; technische IDs werden nicht angezeigt. Der aktive Reiter ist hervorgehoben und mit dem Inhaltsbereich verbunden. Die Reiter sind normale Seitenlinks und funktionieren auch ohne JavaScript.
Neue Einträge werden aktiv angelegt. Die nächste numerische ID wird automatisch
vergeben. Der aktuelle Bereich endet bei 999, passend zu den dreistelligen
AutoKat-Zuordnungsfeldern. Bestehende IDs werden niemals geändert.

- Bezeichnung und Erläuterung speichern: Speichern steht zusammen mit Löschen in Aktionen. Der neue
  Name gilt auch für ältere Dokumente. Kein Neuladen; der Button bestätigt kurz mit
  „✓ Gespeichert“. Andere noch nicht gespeicherte Zeilen bleiben erhalten.
- Deaktivieren: nur möglich, wenn keine AutoKat-Regel verweist. Auch inaktive
  Regeln zählen. Der aktive Status ganz rechts ist dann ausgegraut und nicht
  anklickbar. Die Spalte Verwendung zeigt die verknüpften Regeln mit Links.
- Reaktivieren: Eintrag ist wieder für neue Zuordnungen verfügbar.
- Löschen: nur ohne Dokument- UND Regelverknüpfungen; Kategorie 2 wird mitgeprüft.

Recherchefilter zeigen weiterhin sämtliche Einträge, inaktive mit Kennzeichnung.
Dokumentbearbeitung erlaubt je Feld den bisherigen inaktiven Eintrag beizubehalten,
aber keine neue inaktive Zuordnung. Eingangsverarbeitung und Regelbearbeitung bieten
nur aktive Werte zur Neuzuordnung an. Zwischengespeicherte Inbox-Vorschläge, die inzwischen deaktiviert oder gelöscht wurden, bleiben mit „bitte neu wählen“ sichtbar und werden beim Speichern abgewiesen. Beim Textvergleich werden inaktive Zuordnungen der gefundenen
Vorlage nicht übernommen; andere Vorschlagswerte bleiben nutzbar.

Aktivieren/Deaktivieren erfolgt direkt über das Statussymbol ohne Neuladen.
Fehler erscheinen unter der betroffenen Zeile über die Tabellenbreite. Löschen
verlangt eine Bestätigung und lässt anschließend einen Platzhalter stehen, damit
die Bildschirmposition erhalten bleibt. Anlegen ist eine eigene Formularaktion.

## Optionale Erläuterung

Jede Kategorie und jeder Dokumententyp kann eine **Erläuterung** mit bis zu 1000 Zeichen einschließlich Zeilenumbrüchen erhalten. Sie dokumentiert Definition, typische Inhalte und Abgrenzungen der eigenen Ablagesystematik und ist auch ohne KI nutzbar. In der Liste den Bereich **Erläuterung** unter der Bezeichnung aufklappen; der Button **Speichern** speichert beide Angaben zusammen. Ein leerer Text entfernt die Erläuterung. Beim Anlegen steht das optionale Feld ebenfalls zur Verfügung.

Beispiel für **Haus und Garten**: „Unterlagen zu eigenen Immobilien, deren Nutzung, Unterhaltung und Absicherung; einschließlich Gebäudeversicherung.“ Für Dokumententypen eignen sich beispielsweise Abgrenzungen zwischen Rechnung, Vertrag und Vertragsänderung.

Die lokale KI erhält Name und Erläuterung der aktiven Einträge. Erläuterungen ersetzen keine festen AutoKat-Zuordnungen. **Kategorie2** wird weiterhin manuell ergänzt und erhält keinen KI-Vorschlag; nach manueller Ergänzung können redundante Tags bei Bedarf entfernt werden. Kategorien und Dokumententypen behalten ihre bisherigen IDs und Zuordnungen. Erläuterungen werden nicht in alle Dokument-Metadateien kopiert.

Bestehende Installationen benötigen vor der Nutzung [update_master_explanations.sql](../sql/update_master_explanations.sql); siehe [Datenbank](DATENBANK.md#optionale-stammdaten-erläuterungen).

## Konsistenz

Die fachlichen Stammdatenänderungen erfolgen ausschließlich über Python.
Alle neuen PHP-Zuordnungen werden innerhalb ihrer Schreibtransaktion erneut geprüft
und die Stammdatenzeilen mit geteilten Sperren bis zum Commit geschützt.
Python sperrt den zu ändernden Stammdatensatz exklusiv und prüft die Verwendung
mit READ COMMITTED. Dadurch können paralleles Zuordnen und Deaktivieren/Löschen
nicht unbemerkt eine verwaiste oder neue inaktive Zuordnung erzeugen.
Voraussetzung sind InnoDB und die gemeinsam aktualisierten Komponenten.
Direkte Änderungen mit Datenbankwerkzeugen umgehen diese Anwendungskontrollen.

Bezeichnungen in JSON-Metadateien sind Momentaufnahmen. Umbenennen schreibt nicht alle Metadateien neu. Die Archivprüfung erkennt Unterschiede zu den aktuellen Bezeichnungen; ausgewählte Reparaturen aktualisieren die JSON-Dateien. Die Datenbank bleibt maßgeblich.

