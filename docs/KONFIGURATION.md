# Konfiguration und Einstellungen

Die lokale Konfiguration bestimmt Datenbankzugang und Container-Mounts. Die Tabelle `parameter` bestimmt die Anwendungspfade und fachlichen Einstellungen. Hostpfad und Containerpfad haben unterschiedliche Aufgaben und müssen zueinander passen.

## Einstellungen bedienen

**Einstellungen** öffnet die nach Gruppen gegliederten Parameter. Die Gruppen sind zunächst eingeklappt; **Alle aufklappen** und **Alle zuklappen** ändern die Darstellung. Sichtbar und über die Oberfläche änderbar sind Einträge mit `anzeige=ja` beziehungsweise fehlender Kennzeichnung. Andere Parameter können intern weiterhin geladen werden.

Werte ändern und **Speichern** wählen. Die Anwendung speichert die freigegebenen Parameter gemeinsam in einer Transaktion; bei einem Fehler werden diese Änderungen zurückgenommen. Dateinamensmodi und Verzeichnisebenen sind Auswahllisten mit zusätzlicher Prüfung beim Speichern. Die Anwendung merkt sich die interne Ausgangsseite; **Zurück** führt dorthin. Nach ungespeicherten Eingaben lautet diese Schaltfläche **Abbrechen**; der Rückweg speichert sie nicht.

Die Oberfläche ändert nur vorhandene freigegebene Parameter. Die Erstbefüllung kommt aus `sql/seed.sql`, notwendige Ergänzungen aus den unter [Datenbank](DATENBANK.md) beschriebenen Skripten.

## Protokolldaten bei endgültiger Löschung

In der Gruppe **Archiv** steuert `protokollLoeschdatenBehalten` über eine Ja/Nein-Auswahl die Aufbewahrung:

- **Ja** (Standard): Identifizierende Dokumentdaten werden im Löschereignis erhalten; frühere Ereignisdetails bleiben bestehen.
- **Nein**: Bei künftigen endgültigen Löschungen werden sämtliche Details aller bisherigen Ereignisse mit dieser DokID geleert. Das neue Löschereignis enthält ebenfalls keine Details. DokID, Zeitpunkt, Ereignistyp, Quelle und Ereignis-ID bleiben erhalten.

Die Einstellung wirkt erst bei der endgültigen Löschung, nicht beim Verschieben in den Papierkorb. Ein Wechsel auf Nein bereinigt keine bereits endgültig gelöschten Dokumente rückwirkend. Bereinigte Angaben werden bei einem späteren Wechsel auf Ja nicht wiederhergestellt. Bereits erstellte Sicherungen werden nicht verändert. Die Bereinigung ist keine Zusage vollständiger Anonymisierung: DokIDs können mit anderen vorhandenen Beständen verknüpft sein.

Für bestehende Installationen `sql/update_document_history.sql` erneut ausführen; das Skript ergänzt den Parameter, ohne eine vorhandene Einstellung zu überschreiben. Ein fehlender Parameter erhält aus Kompatibilitätsgründen das bisherige Verhalten Ja; ungültige Werte verhindern die endgültige Löschung. Der Papierkorb zeigt den jeweils geltenden Hinweis. Ändert sich die Einstellung vor dem Absenden, muss der Papierkorb neu geöffnet werden.

## Pfade und Dokument-ID

| Parameter | Neutraler Containerwert | Aufgabe |
| --- | --- | --- |
| `pfadAblageNeu_linux` | `/data/inbox/` | Eingangs-PDFs |
| `pfadAblage_linux` | `/data/archiv/` | Archivierte Original-PDFs |
| `pfadMetadaten_linux` | `/data/meta/` | JSON-Begleitdateien |
| `1_expVerzeichnis_linux` | `/data/export/` | Gesamtexportziel |
| `pfadDatensicherung_linux` | `/data/backup/` | SQL-Sicherungen und auswählbare Rücksicherungen |
| `dokStartwert` | `10000` | Erste DokID bei leerer Dokumenttabelle |

Pfade mit abschließendem `/` setzen. Für die Einrichtung alle fünf Verzeichnisse anlegen. Die ersten drei Pfade und `dokStartwert` werden bereits beim Laden der PHP-Konfiguration benötigt. Platzhalter aus dem Seed vor dem ersten Anwendungsaufruf ersetzen. Export und Sicherung können ihre Zielverzeichnisse bei vorhandenen Rechten anlegen.

Ein Hostpfad wie `/volume1/eablage-data` wird durch Compose nach `/data` eingebunden. In den Datenbankparametern stehen die Containerpfade. Ein Wechsel des Hostverzeichnisses bei gleichbleibendem Mount verlangt deshalb keine Änderung dieser Parameter.

Für Archivwartung müssen Archiv und Metadaten getrennte, nicht ineinander verschachtelte Unterordner von `/data` sein. Sie dürfen nicht die Datenwurzel selbst bezeichnen.

Archiv und Metadatenbestand sind flach: PDFs heißen `<DokID>dok.pdf`, Begleitdateien `<DokID>meta.json`. Unterordner im physischen Archiv werden durch die Wartung nicht rekursiv durchsucht. Die virtuelle Ordnerstruktur des Explorers ist davon unabhängig.

## Installation mit separater Datenbank

`compose.yaml` nutzt eine separate MariaDB im externen Netzwerk `database`. `.env.example` nach `.env` und `web/konfiguration_example.php` nach `web/konfiguration.php` kopieren. Die vollständigen Einrichtungsschritte stehen unter [Installation mit separater Datenbank](INSTALL_STANDARD.md).

| `.env`-Variable | Beispiel / Funktion |
| --- | --- |
| `EABLAGE_INSTANCE` | `prod` oder `test`; verpflichtende Zielkennung für die Deployment-Skripte, keine automatische Ressourcentrennung |
| `EABLAGE_WEB_PORT` | `8082`; veröffentlichter Webport |
| `EABLAGE_CONFIG_PATH` | `/volume1/docker/eablage/web/konfiguration.php`; lokale PHP-Datei |
| `EABLAGE_DATA_PATH` | `/volume1/eablage-data`; Hostwurzel der Dokumentdaten |
| `EABLAGE_META_PATH` | `/volume1/eablage-data/meta`; separat beschreibbarer Metadaten-Mount |
| `EABLAGE_META_CONTAINER_PATH` | `/data/meta`; passend zum DB-Metadatenpfad |
| `EABLAGE_BACKEND_UID`, `EABLAGE_BACKEND_GID` | jeweils `33`; Prozessidentität des Backends |
| `EABLAGE_DB_HOST` | `mariadb`; interner Datenbankname im Docker-Netz |
| `EABLAGE_DB_NAME`, `EABLAGE_DB_USER` | `eablage`; bei mehreren Instanzen auf demselben Server getrennte Datenbanken und Benutzer verwenden |
| `EABLAGE_DB_PASSWORD` | Individuelles Passwort; an Backend übergeben |
| `EABLAGE_BACKEND_URL` | `http://backend:8000`; als `BACKEND_URL` an PHP übergeben |

In der lokalen PHP-Datei `MYSQL_HOST`, `MYSQL_BENUTZER`, `MYSQL_KENNWORT` und `MYSQL_DATENBANK` passend setzen. Die Archivtabellendefinition lautet `define('DB_TABELLE_ARCHIV', 'dokumente');`. PHP und Backend müssen dieselbe Datenbank verwenden. Bei der Installation mit separater Datenbank kommen die PHP-Datenbankwerte aus dieser Datei und nicht automatisch aus `.env`.

`BACKEND_URL` ist die einzige Backend-Adressquelle für PHP. Das Backend veröffentlicht keinen Hostport und ist im App-Netz als `backend` erreichbar. `localhost` im Webcontainer bezeichnet diesen Container, nicht das Backend oder das NAS.

Nur die lokale PHP-Konfiguration wird schreibgeschützt nach `/var/www/html/konfiguration.php` eingebunden. Der Anwendungscode steckt im Image; ein kompletter Host-Mount über `/var/www/html` würde ihn überdecken. Lokale Zugangsdaten bleiben außerhalb des Images und außerhalb der Versionsverwaltung.

## Installation mit integrierter Datenbank

`compose.fresh.yaml` verwendet `.env.fresh` und einen eindeutigen Compose-Projektnamen. Web, Backend und eigene MariaDB erhalten dasselbe Anwendungspasswort. PHP nutzt die eingebundene `web/konfiguration_docker.php` und ihre Umgebungsvariablen. Es gibt keine separat zu pflegende PHP-Passwortkopie.

Konfigurierbar sind der freie Webport, der neue Datenpfad, das Anwendungspasswort und das MariaDB-Rootpasswort. Die Containerdatenbank heißt intern `eablage`, ist aber durch projektspezifische Netze und Volume von anderen Instanzen getrennt. Details: [Installation mit integrierter Datenbank](INSTALL_FRESH.md).

## Mehrere Instanzen betreiben

Die Datenbankanbindung und der Einsatzzweck sind unabhängig voneinander: Beide Installationsvarianten eignen sich für produktive Nutzung und für Tests. Regelmäßige Updates, zusammengehörige Sicherungen und eine Wiederherstellungsprobe sind bei beiden erforderlich.

| Variante | Compose-Datei | Lokale Konfiguration | Datenbank |
| --- | --- | --- | --- |
| Integrierte Datenbank | `compose.fresh.yaml` | `.env.fresh` | MariaDB wird im selben Compose-Projekt bereitgestellt |
| Separate Datenbank | `compose.yaml` | `.env` und `web/konfiguration.php` | Unabhängig betriebene MariaDB, beispielsweise in einem eigenen Compose-Projekt |

Die Anleitungen verwenden alternative Beispiele für eine einzelne Instanz. Für mehrere gleichzeitig betriebene Instanzen pro Instanz getrennt festlegen:

- **Projektverzeichnis und Compose-Projektname:** etwa `eablage-a` und `eablage-b`. Den gewählten Projektnamen bei Start, Update, Stopp und Sicherungsarbeiten einheitlich verwenden. Bei einer bestehenden Installation den bisherigen Namen beibehalten: Er bestimmt unter anderem die Zuordnung zum Datenbank-Volume.
- **Webport:** beispielsweise `8082` und `8084`; jeder veröffentlichte Hostport darf nur einmal belegt sein.
- **Datenverzeichnisse:** eigene Ordner für Eingang, Archiv, Metadaten, Export und Sicherungen. `EABLAGE_META_PATH` muss zum Datenpfad derselben Instanz passen.
- **Datenbank:** Bei integrierter MariaDB sorgen unterschiedliche Projektnamen für getrennte Netze und Datenbank-Volumes; der interne Datenbankname darf jeweils `eablage` bleiben. Bei einem gemeinsamen separaten MariaDB-Server eigene Datenbanken und Benutzer mit passenden Rechten anlegen. Bei mehreren separaten Datenbankcontainern eindeutige Docker-Namen beziehungsweise getrennte Netze verwenden; keine mehrdeutigen `mariadb`-Aliasse im gemeinsamen Netzwerk.
- **Konfiguration und Zugangsdaten:** die zur jeweiligen Variante gehörende lokale Konfiguration verwenden und Web sowie Backend an dieselbe Instanzdatenbank anbinden.

`EABLAGE_INSTANCE=prod` beziehungsweise `test` ist ausschließlich eine Zielprüfung für die optionalen PowerShell-Deploymentskripte. Die Kennzeichnung entscheidet weder über die Installationsvariante noch trennt sie Ports, Datenbanken oder Verzeichnisse. Auch eine manuelle Instanzkennzeichnung unter `Version` ersetzt diese Trennung nicht.

## Rechte und Schutz des Betriebs

PHP benötigt Lesen/Schreiben in Eingang, Archiv, Metadaten, Export und Sicherungsziel. Das Backend erhält `/data` lesbar und ausschließlich das Metadatenverzeichnis zusätzlich beschreibbar. Es benötigt für seine Wartungsfunktionen dennoch gezielte Datenbank-Schreibrechte; siehe [Datenbankrechte](DATENBANK.md#rechte).

Apache arbeitet als `www-data`, UID/GID `33:33`; das Backend ist entsprechend voreingestellt. Elternordner müssen betretbar sein. Eigentümer, Gruppen und UGOS-ACLs passend vergeben. Keine pauschalen `777`-Rechte verwenden; `.env` und lokale Konfiguration vor fremdem Zugriff schützen.

Die Anwendung besitzt keine eigene Anmeldung oder Rollenverwaltung. Den Webzugang auf vertrauenswürdige Clients begrenzen; für externen Zugriff ist eine zusätzliche Zugangssicherung erforderlich. Datenbank und Backend benötigen keine veröffentlichten Hostports.

## Textextraktion und Vergleich

| Parameter | Wirkung |
| --- | --- |
| `PDFTextMode` | `layout`, `raw` oder `default`; steuert `pdftotext` |
| `txtLaengeVergl` | Maximale Zahl der Zeichen je Text für den Vergleich |
| `minLaengeVergl` | Mindestlänge des neuen Vergleichstexts in Zeichen |
| `SchwUebereinst` | Mindestähnlichkeit in Prozent; Groß-/Kleinschreibung des Schlüssels wird beim Laden vereinheitlicht |
| `dokTextLaenge` | Vorhandener Parameter ohne aktuelle Wirkung; begrenzt den gespeicherten Text nicht |

`layout` versucht die räumliche Textanordnung zu erhalten, `raw` nutzt die Inhaltsreihenfolge, `default` verwendet die Standardausgabe von Poppler. Das Backend versucht bei fehlender Textausgabe zusätzlich `raw`, und bereinigt bestimmte Sonderzeichen sowie mehrfache Leerzeichen. Bei reinen Bild-PDFs folgt die lokale OCR, sofern aktiviert.

Das Backend wertet die Vergleichsparameter unabhängig von ihrer Groß-/Kleinschreibung aus. Der Seed setzt `SchwUebereinst` auf **55 %**; ein gespeicherter anderer Wert wird direkt berücksichtigt. Vorhandene Schlüssel müssen nicht umbenannt werden; eine SQL-Migration ist dafür nicht erforderlich. Bei fehlenden oder nicht als Ganzzahl lesbaren Werten gelten die Ersatzwerte **70 %**, **5000 Zeichen** und **100 Zeichen** für Schwellenwert, Vergleichslänge und Mindestlänge.

## Export und Instanzkennzeichnung

Alle `1_exp…`- und `2_exp…`-Parameter sind vollständig unter [Export](EXPORT.md) beschrieben. Profil 1 gilt auch für den Explorer.

`Version` ist eine manuell gepflegte Instanzkennzeichnung, beispielsweise `Test` oder die eingesetzte Version. Ein Deployment ändert sie nicht automatisch. Der Wert beweist weder das verwendete Schema noch die Herkunft eines SQL-Dumps. Backend-Health, gespeicherter Commit und Sicherungskennung sind getrennt zu beachten.


## OCR

Die Compose-Dateien aktivieren lokale OCR mit Deutsch/Englisch im Backend und Worker. Optional `EABLAGE_OCR_ENABLED=false` oder `EABLAGE_OCR_LANGUAGES=deu+eng` in der Instanz-.env setzen. Image-Neubau und Grenzen: [OCR](OCR.md).

## Inbox-Worker

`EABLAGE_INBOX_ENABLED` (Standard `true`) und `EABLAGE_INBOX_INTERVAL` (Standard `60` Sekunden) steuern die Hintergrundverarbeitung. Die manuelle Klassifizierung funktioniert auch bei deaktiviertem Worker. [Details](STAPELVERARBEITUNG.md).

## Uploadgröße

Das Web-Image übernimmt `web/uploads.ini` nach `/usr/local/etc/php/conf.d/uploads.ini`: `upload_max_filesize=50M` und `post_max_size=55M`. Die Anwendung akzeptiert höchstens 50 MiB pro PDF, passend zur Verarbeitung im Backend. Die effektive Grenze wird im Uploadbereich angezeigt. Ein vorgeschalteter Proxy kann eine niedrigere Grenze haben. Änderungen an dieser Datei erfordern im mitgelieferten Docker-Setup einen Neubau des Web-Images und das Neuerstellen des Containers: `docker compose up -d --build eablage-web`. Ein Datenbankparameter kann diese Grenze nicht setzen, weil PHP die Anfrage vor Ausführung des Anwendungscodes verarbeitet.

Die verbindlichen Definitionen sind `web/uploads.ini` (PHP-Anfragegrenzen), `EABLAGE_UPLOAD_MAX_BYTES` in `web/upload_funktionen.php` (Webprüfung) und `MAX_PDF` in `backend/app/services/pdf_text.py` (Erkennung). Bei Änderung des PDF-Limits alle drei gemeinsam anpassen und vor dem Deployment `PHP_BIN=php python tests/deployment_limits.py -v` ausführen. Dieser Konsistenztest ist Teil der Freigabeprüfung. Anschließend Web, Backend und Worker neu bauen.

Die effektive Uploadgrenze ist das Minimum aus Anwendungslimit, `upload_max_filesize` und einem positiven `post_max_size` abzüglich 64 KiB Reserve für Multipart-Header und Formularfelder. `post_max_size=0` setzt keine zusätzliche POST-Grenze. Die Standardwerte bleiben 50 MiB pro Datei und 55 MiB pro Anfrage.

## Lokale KI (optional)

Die Parameter `ollamaAktiv`, `ollamaURL`, `ollamaModell`, `ollamaAnweisung` und `ollamaDenkmodus` stehen nach dem Parameterupdate in der Gruppe **Lokale KI**. Die Funktion ist zunächst deaktiviert. Einrichtung, Verbindungstest und Netzwerkfreigabe: [Ollama](OLLAMA.md).

`ollamaAnweisung` ist ein mehrzeiliger Text bis 4000 Zeichen für fachliche Vorgaben. Nach Speichern gilt er für die nächste KI-Anfrage; leer verwendet Standardvorgaben. Die erstmalige Einrichtung benötigt `sql/update_ollama_instruction.sql` und den passenden Backendstand. Antwortformat und technische Prüfungen bleiben fest im Code. [Details](OLLAMA.md#fachliche-ki-anweisung-bearbeiten).

`ollamaDenkmodus` ist optional (`ja`/`nein`, Vorgabe `nein`). Nach `sql/update_ollama_thinking.sql` in den Einstellungen aktivierbar; benötigt ein Modell mit Denkmodus. Aktiviert werden 8192 statt 1500 Ausgabetokens zugelassen. Änderungen gelten nach Speichern für neue Anfragen. [Details](OLLAMA.md#umfang-und-grenzen).

## Entwicklerwerkzeuge

**KI-Diagnose anzeigen** (`ollamaDiagnoseAnzeigen`, `ja`/`nein`, Vorgabe `nein`) schaltet den Menüeintrag **KI-Anfrage anzeigen** und den Diagnosebereich der Klassifizierung ein. Bestehende Installationen benötigen `sql/update_ollama_diagnostics.sql`. Nach Speichern die Klassifizierungsseite neu öffnen; ungespeicherte Eingaben vorher sichern. Die lokale KI arbeitet unabhängig vom Diagnoseschalter. [Bedienung](OLLAMA.md#tatsächliche-ki-anfrage-anzeigen).

`ollamaHintergrund` unter **Lokale KI → KI im Hintergrund** steuert die Worker-KI: `aus` (Vorgabe), `fehlende` (ohne AutoKat-Klassifikationsregel oder Kategorie durch erfolgreichen Textvergleich), `alle` (alle vorbereiteten Eingänge). Ergänzungsregeln allein verhindern den KI-Aufruf nicht. Benötigt aktive lokale KI, laufenden Worker und `sql/update_ollama_background.sql`. [Ablauf und Grenzen](OLLAMA.md#ki-im-hintergrund).

`ollamaTextAnfang` (Vorgabe 9000, mindestens 1) und `ollamaTextEnde` (Vorgabe 3000, mindestens 0) bestimmen die Textauswahl für manuelle und Worker-KI. Zusammen höchstens 12000 Zeichen; Ende 0 lässt den zusätzlichen Schlussteil weg. Kürzere Dokumente werden vollständig ausgewertet. Nach Speichern gelten neue Werte für neue Anfragen und veralten Hintergrundvorschläge. Einrichtung über `sql/update_ollama_text_selection.sql`. [Details](OLLAMA.md#textauswahl-einstellen).

