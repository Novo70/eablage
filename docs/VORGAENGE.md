# Vorgänge und Dokumentenverknüpfungen

Vorgänge fassen zusammengehörige Dokumente zusammen, zum Beispiel Angebot,
Rechnung und Schriftverkehr zu „Heizung erneuern 2026“. Ein Dokument kann mehreren
Vorgängen angehören, etwa zusätzlich „Steuererklärung 2026“.

Die Dokumente bleiben eigenständig archiviert. Zuordnungen erzeugen keine
PDF-Kopien und ändern weder Dateinamen noch Archivpfade oder Exportstruktur.
Kategorien und Dokumenttypen behalten ihre bisherige Bedeutung.

## Vorgänge verwalten

Im Hauptmenü **Vorgänge** öffnen. Die Übersicht durchsucht Bezeichnungen und
Beschreibungen. Sie zeigt das Anlagedatum und die Zahl der zugeordneten Dokumente
außerhalb des Papierkorbs. Die Spalten **Bezeichnung** und **Angelegt am**
lassen sich sortieren. Mobil erfolgt die Sortierung über ein Auswahlfeld.

**Neuen Vorgang anlegen** in der Fußleiste öffnet einen Dialog für die Bezeichnung
und eine optionale Beschreibung. Nach dem Speichern öffnet sich der Vorgang.
Gleichlautende Bezeichnungen sind erlaubt.

Ein Klick auf eine Zeile öffnet den Vorgang mit Dokumentliste und PDF-Vorschau.
Auf schmalen Bildschirmen steht die [PDF.js-Vorschau](PDF_VORSCHAU.md) unter
der Dokumentliste. Bei ausgewähltem Dokument sind **Metadaten anzeigen** und
**Zuordnung entfernen** in der Fußleiste verfügbar. Die Metadaten öffnen sich
in der Leseansicht. Dort ist bei Bedarf **Metadaten bearbeiten** verfügbar.
**Zurück zum Vorgang** stellt die Listenseite und die Dokumentauswahl wieder her,
sofern das Dokument weiterhin auf dieser Seite steht.

Unterhalb der Liste befinden sich die aufklappbaren Bereiche **Vorhandenes
Dokument zuordnen** und **Vorgang bearbeiten**. Im zweiten lassen sich
Bezeichnung und Beschreibung ändern. **Vorgang löschen** entfernt nach
Bestätigung den Vorgang und seine Zuordnungen. Die Dokumente bleiben erhalten.

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
Dokumentliste zunächst ein Dokument auswählen. **Zuordnung entfernen** in der Fußleiste löst die Verknüpfung zum aktuellen Vorgang sofort.
Ohne Auswahl ist die Schaltfläche deaktiviert.

## Suche und Papierkorb

Die vorhandene Dokumentensuche enthält einen zusätzlichen Filter **Vorgang**.
Aus der Vorgangsansicht führt **Dokumente in der Suche anzeigen** zur gefilterten
Suche; dort lassen sich weitere Suchkriterien kombinieren.

In der Trefferliste können Dokumente auf mehreren Seiten ausgewählt werden.
**Vorgang anlegen** öffnet bei mindestens einer Auswahl den Dialog. Erst
**Vorgang speichern** legt den Vorgang mit sämtlichen markierten Dokumenten
gemeinsam an. Abbrechen erhält die Auswahl. Nach erfolgreichem Speichern
öffnet sich der neue Vorgang.

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
