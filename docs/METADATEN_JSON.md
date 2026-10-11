# Dokumentmodell und JSON-Metadaten

Datenbank und PDF-Archiv sind der maßgebliche Bestand. Begleitdateien machen Metadaten unabhängig von der Anwendung lesbar. Neue Schreibvorgänge verwenden JSON-Schema 3; die Schema-Version ist unabhängig von der Anwendungsversion.

## Schnittstelle

`backend/app/services/metadata_service.py` benötigt nur die Python-Standardbibliothek.
`build_metadata(document)` validiert einen vollständigen Dokumentstand und liefert
ein Dictionary. `serialize_metadata(document)` liefert lesbares JSON mit zwei
Leerzeichen Einrückung und abschließendem Zeilenumbruch. Der Aufrufer
kodiert es als UTF-8 ohne BOM. `metadata_filename(DokID)` liefert `<DokID>meta.json`.
Die Komponente greift weder auf Datenbank noch Dateisystem zu.
`parse_metadata(raw)` validiert Struktur, Pflichtfelder, Typen und Formatversion.
Doppelte/zusätzliche Schlüssel werden abgelehnt; unbekannte ganzzahlige Versionen
werden separat gemeldet und nicht automatisch repariert. Die Ausgabe ist auf
16 MiB begrenzt, passend zum Leselimit der Archivwartung.

## Vertrag für Schema 3

| Quelle | JSON-Feld | Typ / Bedeutung |
| --- | --- | --- |
| Formatkonstante | schema_version | Ganze Zahl 3, unabhängig von Anwendungsversion |
| DokID | document_id | Positive ganze Zahl; fachliche Dokument-ID |
| DokID (abgeleitet) | pdf_filename | `<DokID>dok.pdf`, relativ zum Archivordner |
| Datum | document_date | YYYY-MM-DD oder null |
| Archivierungsdatum | archived_at | YYYY-MM-DDTHH:MM:SS oder null |
| Originaldateiname | original_filename | Ursprünglicher PDF-Basisname ohne Pfad, maximal 255 Zeichen oder null |
| DateiSHA256 | pdf_sha256 | SHA-256 der archivierten PDF: 64 kleine Hexadezimalzeichen oder null |
| Aenderungsdatum | modified_at | Zeitpunkt der letzten tatsächlichen Metadatenänderung, YYYY-MM-DDTHH:MM:SS oder null |
| Aufbewahrungsdauer | retention_duration | Reserviert: ganze Zahl oder null; unveränderter DB-Wert |
| kategorie_id, katBez | category | Objekt mit id und label |
| kategorie2_id, kat2Bez | category2 | Objekt mit id und label |
| doktyp_id, doktypBez | document_type | Objekt mit id und label |
| Partner | partner | Text oder null |
| Betreff | subject | Text oder null |
| Nummer | number | Text oder null |
| Tags | tags | Unveränderter Text oder null, keine Liste |
| Doktext | document_text | Vollständiger Text oder null |

Alle Quellschlüssel sind erforderlich, einschließlich der drei Bezeichnungen.
Der Aufrufer muss die Bezeichnungen zum vorgesehenen Dokumentstand ergänzen;
die Export-JOINs verwenden dieselben Aliasnamen.
Explizites null bedeutet fehlende Daten; leere fachliche Textwerte werden ebenfalls
null. Eine leere Prüfsumme ist dagegen ungültig. Sonst bleibt Text einschließlich Leerzeichen und Zeilenenden erhalten.
IDs der Stammdaten bleiben Zeichenketten, insbesondere mit führenden Nullen.
Eine vorhandene ID ohne Bezeichnung bleibt erhalten; eine Bezeichnung ohne ID
wird abgelehnt. Bezeichnungen sind Momentaufnahmen zum Erzeugungszeitpunkt,
keine belegten historischen Bezeichnungen zum Dokumentdatum.
Die Archivprüfung vergleicht sie mit aktuellen Stammdatenbezeichnungen. Eine
Umbenennung erscheint als Abweichung; ausgewählte Reparaturen aktualisieren sie.

Die technische Auto-Increment-Spalte ID wird nicht exportiert. Weitere
Quellspalten werden ignoriert. Neue fachliche DB-Felder müssen bewusst in den
Formatvertrag aufgenommen werden.

Archivierungsdatum und Aenderungsdatum sind in der Datenbank zeitzonenlos. Es wird keine UTC-Zone
erfunden. Zeitzonenbehaftete Werte und Sekundenbruchteile werden abgelehnt.
Aufbewahrungsdauer wird derzeit nicht genutzt und bleibt als reserviertes Feld
ohne festgelegte Einheit oder fachliche Bedeutung erhalten. Vorhandene ganzzahlige
Werte werden unverändert übernommen, fehlende Werte als null. Auch 0 und negative
Werte erhalten keine Sonderbedeutung. Es werden keine Fristen oder Löschaktionen
abgeleitet. Eine spätere Nutzung erfordert eine eigene fachliche Festlegung;

Ungültige Bestandsdaten werden gemeldet, nicht stillschweigend korrigiert.

`pdf_filename` muss exakt dem aus der positiven DokID gebildeten Namen `<DokID>dok.pdf` entsprechen. Pfade sind in diesem Feld nicht zulässig. Die Archivprüfung kontrolliert zusätzlich die Existenz und Zuordnung der PDF.

Der Originaldateiname wird unabhängig vom generierten Archivdateinamen gespeichert: Er kommt
bei der Archivierung aus dem Eingang. Pfadbestandteile, Steuerzeichen und Namen
ohne PDF-Endung werden abgelehnt. Er bleibt anschließend unverändert und wird
nicht als Dateizugriffspfad benutzt. Exportbereinigung verändert nur die Darstellung.

Schema 3 enthält kein `info`-Feld. Die Dokumenthistorie liegt ausschließlich in der Datenbank; `dokumente.Info` bleibt unveränderter Altbestand. Für eine Rekonstruktion der fachlichen Dokumentdaten aus JSON ist die Historie nicht erforderlich.

Schema 1 und 2 enthalten weiterhin das damals vorgeschriebene `info`-Feld und bleiben strikt lesbar. Bei der Archivprüfung wird dieses Alt-Feld nicht mehr mit der Datenbank verglichen. Ein vorhandenes `info` allein löst keine Reparatur aus. Erst bei tatsächlichem Neuschreiben entfällt es.

Schema 1 ist strikt lesbar und enthält `original_filename`, `pdf_sha256` und `modified_at` nicht.
Die Archivprüfung behandelt die fehlenden Felder als unbekannt (`null`), ohne
allein wegen der Versionsnummer eine Reparatur anzubieten. Sobald die DB einen
neuen Wert enthält, wird dessen Fehlen in der Metadatei als Abweichung gemeldet.
Neue Schreibvorgänge erzeugen ausschließlich Schema 3. Unbekannte Versionen
bleiben vor automatischem Überschreiben geschützt.

## Speicherwege und Altbestand

PHP behält die Schreibhoheit für Archivierung und Bearbeitung. Es fordert JSON
vor INSERT/UPDATE und Dateiänderungen an. Bei Neuanlage werden DokID unter der
Archivierungssperre reserviert und Archivierungsdatum einmal mit SELECT NOW()
ermittelt; derselbe Wert wird im INSERT gespeichert. Bei Bearbeitung wird der
vollständige Datensatz gesperrt und gegen den beim Öffnen des Bearbeitungsformulars geladenen Stand geprüft.
Normalisierter Text und fachliche Metadaten stehen vor dem Backendaufruf fest und werden
identisch in JSON und UPDATE verwendet. Bezeichnungen stammen direkt aus den
Stammdaten ohne UI-Zusätze wie „inaktiv“. Lookup-IDs bleiben Zeichenketten.

Bei Neuanlage wird die SHA-256-Prüfsumme vor der JSON-Vorbereitung aus der
Eingangs-PDF ermittelt. Die Archivkopie muss vor COMMIT dieselbe Prüfsumme haben;
anderenfalls greift der bestehende Rollback. Aenderungsdatum ist bei der Archivierung null.
Bei tatsächlicher Bearbeitung wird es zusammen mit dem Protokolleintrag aus derselben
DB-Uhr (`SELECT NOW()`) gesetzt. Originalname und Prüfsumme bleiben unverändert.
Die Archivprüfung vergleicht vorhandene Referenzprüfsummen mit den PDF-Bytes;
sie korrigiert weder PDFs noch Referenzwerte und zählt unbekannte Prüfsummen separat.

Neue Archivierungen schreiben ausschließlich JSON. Eine tatsächliche Änderung
erzeugt/aktualisiert JSON; Speichern ohne Änderung löst keine Migration aus.
Zurückgebliebene JSON-Dateien verhindern eine Neuanlage mit derselben Dokument-ID.
Die Archivprüfung wertet ausschließlich JSON aus. Fehlende, ungültige und abweichende JSON-Dateien können aus den Datenbankwerten neu erzeugt werden. Prüfnachweise berücksichtigen alle JSON-Felder sowie die bestehende JSON-Datei. Ungültige DB-Werte und unbekannte JSON-Versionen sind nicht reparierbar. Alte TXT-Dateien werden nur als nicht zugeordnet gemeldet, ohne ihren Inhalt zu lesen oder sie zu verändern.

Bestehendes JSON wird per Temporärdatei atomar ersetzt; neues JSON beim Bearbeiten
und Reparieren exklusiv per Hardlink veröffentlicht. Alle Dateien liegen im selben
Metadatenverzeichnis. Nach Fehlern werden DB-Änderungen zurückgerollt und bereits
ersetztes JSON aus einer vorbereiteten Sicherung wiederhergestellt. Schlägt die
Wiederherstellung fehl, bleibt die Sicherung zur Diagnose erhalten.
MariaDB und Dateisystem bilden keine gemeinsame atomare Transaktion: Bei Absturz
oder unklarem COMMIT-Ergebnis bleibt eine Archivprüfung erforderlich.

Fehlende JSON-Dateien können durch die [Archivprüfung und Metadatenreparatur](ARCHIVPRUEFUNG.md) aus den Datenbankwerten erzeugt werden.

`pdf_filename` wird aus `document_id` erzeugt; ein gespeicherter Quellpfad wird nicht verwendet. Für Schema 1, 2 und 3 gilt derselbe kanonische Archivdateiname. Ein abweichender Wert wird als ungültiger Formatvertrag gemeldet und muss gezielt geprüft werden.

## Vorbereitungs-API und PHP

`POST /metadata/prepare` erwartet `{"document": {...}}` mit allen oben genannten
Quellschlüsseln. Antwort bei Erfolg: `{"schema_version": 3, "filename":
"<DokID>meta.json", "content": "...JSON-Text..."}`. Ungültige Daten liefern HTTP 422. Der Endpunkt liest oder schreibt weder Datenbank noch Dateien; übermittelte
Bezeichnungen werden nicht gegen die Datenbank geprüft. PHP ruft diesen Endpunkt intern auf.

`web/metadaten_funktionen.php` stellt `eablage_metadaten_vorbereiten($document)`
bereit und verwendet `web/backend_client.php` mit `BACKEND_URL`. Bei ungültigem UTF-8,
Netzwerk-/HTTP-Fehlern oder unpassender Antwort wird eine Exception ausgelöst.
Kein stiller Ersatz von Zeichen, kein lokaler Ersatz-Serializer. PHP prüft
Formatversion, Dokument-ID und Dateinamen der Antwort.

Der Aufrufer muss vollständige Werte einschließlich Bezeichnungen,
reservierter DokID und exakt des zu speichernden Archivierungsdatums liefern.
Es wird weder eine neue ID noch eine Uhrzeit in diesem Endpunkt erzeugt.
Die PHP-Sperr-/Transaktionslogik sichert diese Identität; reine Vorschauwerte
dürfen nicht ungeprüft später gespeichert werden.
