# Dokumente verwerfen und Papierkorb

## Datenbank vorbereiten

Die Funktion benötigt `dokumente.GeloeschtAm`. Falls die Spalte fehlt, nach Sicherung und bei gestoppten Anwendungsdiensten `sql/update_papierkorb.sql` einmalig ausführen. Neuinstallationen erhalten die Spalte über `sql/schema.sql`.

Anforderungen an SQL-Dumps und das Vorgehen bei älteren Sicherungen beschreibt [Sicherung und Wiederherstellung](SICHERUNG.md).

## Bedienung

Eine noch nicht archivierte PDF kann im **Eingang** oder auf der Seite
**Verarbeiten** verworfen werden. Die Datei wird nur gelöscht, wenn sie seit
dem Öffnen der Seite nicht verändert wurde.

Ein archiviertes Dokument wird auf **Bearbeiten** in den Papierkorb verschoben.
Dabei setzt eAblage `GeloeschtAm`; der Datenbankeintrag sowie PDF und
Metadateien bleiben bestehen. Suche, Ablage-Explorer, Export und Textvergleich
zeigen es dann nicht mehr an. Im Menü **Papierkorb** kann es wiederhergestellt
werden. Die Archivprüfung prüft auch Dokumente im Papierkorb und weist ihre
Anzahl gesondert aus.

**Endgültig löschen** erfordert die Eingabe der DokID. Die Funktion prüft
vorhandene PDF-Prüfsummen, entfernt den Datenbankeintrag und löscht PDF sowie
JSON-Metadatei. Die höchste vergebene DokID bleibt als Parameter
`eablageLetzteDokID` gespeichert, damit sie nicht erneut vergeben wird.
Der Parameter `protokollLoeschdatenBehalten` unter **Einstellungen → Archiv** steuert die Ereignisdetails. **Ja** (Standard) erhält Originaldateiname, Datum, Partner, Betreff, gegebenenfalls Nummer und Zuordnungen im Löschereignis sowie frühere Details. **Nein** leert sämtliche Details der bisherigen Protokolleinträge dieses Dokuments und erzeugt ein Löschereignis ohne Details. Die Ereignisse mit DokID, Zeitpunkt, Ereignistyp und Quelle bleiben erhalten. Liste und Bestätigungsdialog erklären das aktuelle Verhalten. Bei zwischenzeitlich geänderter Einstellung wird die Löschung abgewiesen; den Papierkorb neu öffnen.

Die Bereinigung erfolgt in derselben Datenbanktransaktion wie die Dokumentlöschung. Ein Fehler nimmt beide Änderungen zurück. Die Einstellung wirkt nur bei künftigen endgültigen Löschungen; bereits gelöschte Dokumente und vorhandene Sicherungen werden nicht rückwirkend bereinigt. Zur Einrichtung siehe [Konfiguration](KONFIGURATION.md#protokolldaten-bei-endgültiger-löschung).

Bei Dateisystemfehlern nach der Datenbankänderung können verborgene Dateien
mit Präfix `.eablage-delete-` zurückbleiben. Den Fehler im Containerprotokoll
prüfen und solche Dateien nach Abgleich manuell entfernen.

## Test in einer isolierten Installation

1. Benötigtes Schema prüfen und die Anwendungsdienste gemäß [Updateanleitung](UPDATE.md) bereitstellen.
2. Eingangs-PDF hochladen und verwerfen; sie muss aus dem Eingang verschwinden.
3. Testdokument archivieren, DokID notieren und in den Papierkorb verschieben.
   Suche, Explorer, Textvergleich und Export dürfen es nicht mehr anbieten.
4. Archivprüfung starten: sie zählt das Papierkorb-Dokument mit und findet PDF
   und Metadatei weiterhin.
5. Dokument wiederherstellen und in Suche und Explorer öffnen.
6. Erneut in den Papierkorb verschieben und endgültig löschen. Archivprüfung
   ausführen; bei einem weiteren archivierten Dokument darf die gelöschte DokID
   nicht wiederverwendet werden.
7. Mit getrennten Testdokumenten beide Parameterwerte prüfen: Ja erhält Details; Nein entfernt auch alte Feldwerte und Regelreferenzen ausschließlich für die gelöschte DokID. Die Ereignisse selbst bleiben erhalten. Parameter nach Öffnen des Papierkorbs ändern: Die alte Bestätigung muss abgewiesen werden.
