# Bestandskompatibilität

eAblage benötigt keine Access-Installation, VBA-Module oder TXT-Metadaten mehr. Datenbank und PDF-Archiv bilden den maßgeblichen Bestand.

## Archiv- und Metadatenformate

Archivdateien heißen `<DokID>dok.pdf`, Begleitdateien `<DokID>meta.json`. Die Instanzkonfiguration legt die Verzeichnisse fest. JSON-Schema 1, 2 und 3 sind lesbar; Schreibvorgänge erzeugen Schema 3 ohne das bisherige Info-Feld. Die Dokumenthistorie wird in der Datenbank geführt und nicht in Metadateien übernommen. Der [Formatvertrag](METADATEN_JSON.md) beschreibt die Feldtypen und die Behandlung unbekannter Werte.

TXT-Dateien sind keine aktuellen Metadaten. Die Archivprüfung meldet sie als nicht zugeordnet, liest oder verändert sie aber nicht. Fehlendes JSON kann aus gültigen Datenbankwerten über die [Archivprüfung](ARCHIVPRUEFUNG.md) erzeugt werden. Nicht mehr benötigte TXT-Dateien können nach eigener Bestandsprüfung außerhalb der Anwendung entfernt werden.

## Datenbank und Rücksicherung

Vorhandene Datenbanken anhand der [Schemaübersicht und Migrationstabelle](DATENBANK.md) prüfen. Die dort aufgeführten SQL-Skripte passen Bestandsdatenbanken an; sie sind keine Laufzeitabhängigkeit. Unterstützung und Grenzen von SQL-Sicherungen beschreibt [Sicherung und Wiederherstellung](SICHERUNG.md).

Die technische Tabellen-ID und die fachliche DokID haben unterschiedliche Aufgaben. Originaldateinamen dienen unter anderem dem Export. Das reservierte JSON-Feld `retention_duration` besitzt keine aktive Fristen- oder Löschfunktion. AutoKat-Extraktionsregeln einschließlich Absatzende bei Länge 99 sind Bestandteil des aktuellen [Regelmodells](AUTOKAT.md).
