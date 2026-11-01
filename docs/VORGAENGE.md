# Vorgänge und Dokumentenverknüpfungen

Vorgänge fassen zusammengehörige Dokumente zusammen, zum Beispiel Angebot,
Rechnung und Schriftverkehr zu „Heizung erneuern 2026“. Ein Dokument kann mehreren
Vorgängen angehören, etwa zusätzlich „Steuererklärung 2026“.

Die Dokumente bleiben eigenständig archiviert. Zuordnungen erzeugen keine
PDF-Kopien und ändern weder Dateinamen noch Archivpfade oder Exportstruktur.
Kategorien und Dokumenttypen behalten ihre bisherige Bedeutung.

## Vorgänge verwalten

Im Hauptmenü **Vorgänge** öffnen. Die Übersicht durchsucht Bezeichnungen und
Beschreibungen und zeigt die Zahl der zugeordneten Dokumente außerhalb des
Papierkorbs. Über **Neuen Vorgang anlegen** eine Bezeichnung und optional eine
Beschreibung erfassen. Gleichlautende Bezeichnungen sind erlaubt; für die
Unterscheidung möglichst aussagekräftige Namen verwenden.

Ein Klick auf die Bezeichnung öffnet die Detailansicht mit der Dokumentliste links und einer PDF-Vorschau rechts. Ein Dokument in der Liste auswählen, um es rechts anzuzeigen. Über **PDF öffnen** lässt es sich separat öffnen; **Metadaten bearbeiten** führt zum Dokumentformular. Auf schmalen Bildschirmen entfällt die eingebettete Vorschau wie im Ablage-Explorer; nach der Auswahl stehen die Dokumentaktionen unterhalb der Liste bereit. Unter **Vorgang bearbeiten**
lassen sich Bezeichnung und Beschreibung ändern. Die Dokumente bleiben dabei
zugeordnet. **Vorgang löschen** entfernt nach Bestätigung den Vorgang und dessen
Zuordnungen. Es löscht keine Dokumente, auch keine Dokumente im Papierkorb.

## Dokumente zuordnen

Beim Klassifizieren und beim Bearbeiten eines archivierten Dokuments gibt es den
Bereich **Vorgänge**:

1. Vorhandenen Vorgang auswählen und **Zuordnen** wählen.
2. Bei Bedarf weitere Vorgänge ergänzen. Jede Zuordnung ist nur einmal möglich.
3. Das Dokument mit **Speichern** archivieren bzw. die Änderungen übernehmen.

**Entfernen** löst eine Zuordnung erst beim Speichern des Dokuments. Abbrechen
verwirft diese Auswahländerung.

Unter **Neuen Vorgang anlegen** kann direkt ein Vorgang erstellt und ausgewählt
werden. Dieser Vorgang wird sofort angelegt und bleibt beim Abbrechen des
Dokumentformulars als leerer Vorgang bestehen; die Dokumentzuordnung wird erst mit
**Speichern** wirksam. Für die Auswahl im Dokumentformular ist JavaScript nötig.

Bereits gespeicherte Vorgänge lassen sich am Dokument öffnen. Bei der Rückkehr
über **Zurück zum Dokument** bleiben die zwischengespeicherten Formulareingaben
wie bei anderen Verweisen erhalten. Zwischenzeitlich geänderte Zuordnungen werden
beim Speichern erkannt, damit ein veraltetes Formular sie nicht überschreibt.

Alternativ im Vorgang **Vorhandenes Dokument zuordnen** öffnen und nach Partner,
Betreff oder Nummer suchen. **Zuordnen** übernimmt ein Dokument sofort. In der
Dokumentliste zunächst ein Dokument auswählen. **Zuordnung entfernen** rechts in
der Überschrift löst dann nur dessen Verknüpfung zum aktuellen Vorgang sofort.
Ohne Auswahl ist die Schaltfläche deaktiviert.

## Suche und Papierkorb

Die vorhandene Dokumentensuche enthält einen zusätzlichen Filter **Vorgang**.
Aus der Vorgangsansicht führt **Dokumente in der Suche anzeigen** zur gefilterten
Suche; dort lassen sich weitere Suchkriterien kombinieren.

Dokumente im Papierkorb werden nicht in der Vorgangsliste mitgezählt oder als
reguläre Treffer angeboten. Ihre Verknüpfungen bleiben bestehen und sind nach der
Wiederherstellung wieder sichtbar. Beim endgültigen Löschen eines Dokuments
entfallen seine Zuordnungen automatisch; die Vorgänge und die anderen Dokumente
bleiben erhalten.

## Speicherung, Historie und Sicherung

- `vorgang` speichert ID, Bezeichnung, Beschreibung und Zeitstempel.
- `dokument_vorgang` verbindet Dokumente und Vorgänge über die fachliche `DokID`.
  Der zusammengesetzte Primärschlüssel verhindert doppelte Zuordnungen.
- PHP besitzt die Schreibhoheit für Vorgänge und Zuordnungen. Zuordnungen werden
  in derselben Transaktion wie das Archivieren oder Bearbeiten gespeichert.
- Zuordnungsänderungen erscheinen in der Dokumenthistorie. Eine reine
  Zuordnungsänderung schreibt weder die JSON-Metadatei noch das Änderungsdatum des
  Dokuments neu. Die Zuordnungen sind keine Klassifizierungsfelder; AutoKat und KI
  verändern sie nicht.
- Vorgänge und Zuordnungen sind Bestandteil der vollständigen SQL-Datensicherung.
  Einzelne JSON-Metadateien und der dateibasierte PDF-Export enthalten sie nicht.
  Eine Rekonstruktion allein aus PDFs/JSON stellt daher keine Vorgänge wieder her.
- Die Initialrücksicherung übernimmt beide Tabellen gemeinsam. Ältere unterstützte
  Sicherungen ohne diese Tabellen erzeugen leere Vorgangstabellen. Bereits in der
  leeren Zielinstallation angelegte Vorgänge werden dabei ersetzt.

## Bestehende Installation aktualisieren

Beim Update von 4.4.0 auf 4.5.0 eine vollständige Sicherung erstellen,
offene Dokumentformulare schließen und die Anwendungsdienste stoppen. In der
richtigen Datenbank [update_document_links.sql](../sql/update_document_links.sql)
ausführen und anschließend die Container aus demselben Projektstand neu bauen.
Das Skript ist wiederholbar und verändert keine bestehenden Dokumente.
Neuinstallationen erhalten die Tabellen bereits über `schema.sql`.

Die Webkopie `web/vorgaenge_schema.sql` wird für die Rücksicherung älterer
Sicherungen benötigt und muss mit dem SQL-Updateskript identisch bleiben.
