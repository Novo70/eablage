# Einzel- und Gesamtexport

Exportiert werden Kopien der archivierten PDFs. Archivdateiname und PDF-Original bleiben unverändert. Das Backend berechnet Namen und Ordner; PHP stellt den Download bereit beziehungsweise kopiert die Dateien.

## Einzelne PDF herunterladen

In der Bearbeitung eines archivierten Dokuments **Exportieren** wählen. Das Einzelexportprofil **2** legt den Downloadnamen fest. Es erzeugt keine zusätzliche Ordnerstruktur auf dem NAS.

## Gesamten Bestand exportieren

1. Unter **Einstellungen** das Gesamtexportprofil **1** und `1_expVerzeichnis_linux` kontrollieren.
2. **Export & Datensicherung → Dokumenten-Gesamtexport** öffnen.
3. Den Gesamtexport starten und das Ergebnis mit geplanter/erfolgreicher Anzahl sowie einzelnen Fehlern prüfen.

Fehlende Zielordner werden bei ausreichenden Rechten angelegt. Gleichnamige vorhandene Exportdateien werden aktualisiert. Dateien aus früheren Exportprofilen oder früheren Zuordnungen werden nicht automatisch entfernt. Für einen exakten Vergleich mit dem aktuellen Plan ein leeres separates Ziel verwenden.

Ein PDF-Export enthält nicht die SQL-Datenbank oder einen vollständigen Begleitdateibestand. Zur Wiederherstellung ist eine [zusammengehörige Sicherung](SICHERUNG.md) nötig.

## Dateinamen konfigurieren

Die Parameter beginnen mit `1_` für Gesamtexport/Explorer und `2_` für Einzelexport. `1_expDateinameModus` beziehungsweise `2_expDateinameModus` wählen:

| Modus | Verhalten |
| --- | --- |
| `generiert` | Name aus den aktivierten Metadaten in der unten genannten Reihenfolge |
| `original` | Gespeicherter Originalname; fehlt er, gilt die generierte Namensbildung |

Im generierten Modus werden aktivierte, nicht leere Teile mit ` - ` verbunden:

| Reihenfolge | Parametersuffix | Inhalt |
| --- | --- | --- |
| 1 | `expDokID` | Dokument-ID |
| 2 | `expDatum` | Dokumentdatum |
| 3 | `expKategorieID` | Erste Kategorie-ID |
| 4 | `expKategorie` | Erste Kategoriebezeichnung |
| 5 | `expKategorie2ID` | Zweite Kategorie-ID |
| 6 | `expKategorie2` | Zweite Kategoriebezeichnung |
| 7 | `expDoktypID` | Dokumententyp-ID |
| 8 | `expDoktyp` | Dokumententypbezeichnung |
| 9 | `expPartner` | Partner |
| 10 | `expBetreff` | Betreff |
| 11 | `expNummer` | Nummer |
| 12 | `expTags` | Tags |

Beispiel: `1_expDatum=ja`, `1_expPartner=ja`, `1_expBetreff=ja`. Die übrigen Namensbestandteile mit `nein` deaktivieren. Sind alle aktiven Werte leer, wird die DokID verwendet. `1_expParameterBezeichnung` und `2_expParameterBezeichnung` benennen das jeweilige Profil.

`expMaxLaengeDateiname` begrenzt je Profil den Namensstamm, voreingestellt auf 250 Zeichen. Zusätzlich gilt die Grenze von 255 UTF-8-Bytes einschließlich `.pdf` und Kollisionszusatz. Problematische Zeichen und Steuerzeichen werden bereinigt, Leerraum vereinheitlicht, reservierte Windows-Gerätenamen geschützt und die Endung auf `.pdf` vereinheitlicht. Das verändert den in DB/JSON gespeicherten Originalnamen nicht.

Im Gesamtexport werden Namenskollisionen innerhalb desselben Ordners über den vollständigen Bestand in stabiler DokID-Reihenfolge aufgelöst. Die weiteren Dateien erhalten Zusätze wie ` - DokID 10001`; nötigenfalls folgt eine laufende Ergänzung. Die Prüfung berücksichtigt Groß-/Kleinschreibung gemeinsam, damit die Namen auch auf Windows-Freigaben unterscheidbar bleiben.

## Verzeichnisebenen

`1_expVerzeichnisstruktur=ja` aktiviert bis zu fünf Ebenen, `nein` legt alle PDFs direkt ins Exportziel. `1_expVerzeichnisEbene1` bis `1_expVerzeichnisEbene5` akzeptieren:

| Wert | Quelle / Beispiel |
| --- | --- |
| `(keine)` | Ebene entfällt |
| `Kategorie` | Erste Kategoriebezeichnung |
| `Kategorie2` | Zweite Kategoriebezeichnung |
| `Dokumententyp` | Dokumententypbezeichnung |
| `Partner` | Partner |
| `Nummer` | Gespeicherte Nummer, beispielsweise Vertragsnummer |
| `Jahr` | Jahr des Dokumentdatums, beispielsweise `2026` |
| `Monat` | Monat des Dokumentdatums, beispielsweise `09 - September` |

Beispiel: Kategorie → Jahr → Monat → Dokumententyp → Nummer. Leere Merkmale werden übersprungen; die anderen behalten ihre Reihenfolge. Unzulässige Pfadzeichen werden bereinigt. Unterschiedliche Nummerntexte wie `12345` und `Konto 12345` ergeben unterschiedliche Ordner.

Jahr/Monat werden bei jedem Laden aus einem gültigen Dokumentdatum abgeleitet, nicht zusätzlich gespeichert. Fehlendes oder ungültiges Datum lässt beide Ebenen entfallen; Archivierungsdatum oder Tagesdatum dienen nicht als Ersatz. Monatsnamen sind fest deutsch und durch die zweistellige Nummer chronologisch sortierbar.

Die Einstellungen bieten Auswahllisten und prüfen erlaubte Werte beim Speichern. Bereits gespeicherte leere Werte und `nein` gelten als keine Ebene. Unbekannte Bestandswerte werden angezeigt; die Exportlogik ignoriert diese Ebenen, bis ein gültiger Wert gespeichert wird.

## Gemeinsame Struktur mit dem Explorer

Gesamtexport und [Ablage-Explorer](EXPLORER.md) nutzen denselben vollständigen Plan aus `GET /export/plan`. Die Struktur muss nicht erst physisch exportiert werden. Der Explorer zeigt die Dokumentmetadaten statt des virtuellen Dateinamens und sortiert nach Datum und sichtbaren Feldern. Diese Anzeigesortierung verändert den Exportplan nicht.
