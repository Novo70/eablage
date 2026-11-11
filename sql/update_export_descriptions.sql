-- Verständliche Exportparameter-Hinweise für bestehende Installationen.
-- In der gewünschten eAblage-Datenbank ausführen; wiederholbar.
-- Ändert nur fehlende bzw. bekannte Standardbeschreibungen.
-- Eigene Beschreibungen, Parameterwerte und Dokumentdaten bleiben erhalten.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE `parameter`
SET `beschreibung` = 'Betreff in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expBetreff'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Dokumentdatum (JJJJ-MM-TT) in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expDatum'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'generiert: Dateiname aus den aktivierten Dokumentangaben; original: ursprünglicher PDF-Dateiname. Fehlt der Originalname, wird der Name generiert. Sonderzeichen und Überlänge werden auch im Originalmodus bereinigt.'
WHERE `param_key` = '1_expDateinameModus'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '' OR `beschreibung` = 'Dateiname: generiert oder original (fehlender Originalname: generiert)');

UPDATE `parameter`
SET `beschreibung` = 'Interne Dokument-ID in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expDokID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Bezeichnung des Dokumententyps in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expDoktyp'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Interne ID des Dokumententyps in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expDoktypID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Bezeichnung der ersten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expKategorie'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Bezeichnung der zweiten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expKategorie2'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Interne ID der zweiten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expKategorie2ID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Interne ID der ersten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expKategorieID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Maximale Länge des Dateinamens ohne .pdf in Zeichen, z. B. 250. Gilt auch für Originalnamen. Lange Namen werden gekürzt; zusätzlich gilt die Dateisystemgrenze von 255 UTF-8-Bytes einschließlich .pdf und Namenszusätzen.'
WHERE `param_key` = '1_expMaxLaengeDateiname'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Wichtigste gespeicherte Nummer des Dokuments in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expNummer'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Frei wählbarer Name dieses Exportprofils, z. B. Gesamtexport. Ändert weder Dateinamen noch Ordnerstruktur.'
WHERE `param_key` = '1_expParameterBezeichnung'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Partner bzw. Absender in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expPartner'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Schlagwörter (Tags) in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '1_expTags'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Zielordner für die PDF-Kopien des Gesamtexports, z. B. /data/export. Im Container erreichbaren, beschreibbaren Pfad angeben. Vorhandene gleichnamige Exportdateien werden aktualisiert; das Archiv bleibt unverändert.'
WHERE `param_key` = '1_expVerzeichnis_linux'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '' OR `beschreibung` = 'Zielverzeichnis für den Gesamtexport der Webanwendung');

UPDATE `parameter`
SET `beschreibung` = 'Oberste Ordnerebene für Gesamtexport und Explorer: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr oder Monat. (keine) lässt die Ebene weg. Wirkt nur bei aktivierter Verzeichnisstruktur; leere Dokumentangaben entfallen.'
WHERE `param_key` = '1_expVerzeichnisEbene1'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = '2. Ordnerebene für Gesamtexport und Explorer: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr oder Monat. (keine) lässt die Ebene weg. Wirkt nur bei aktivierter Verzeichnisstruktur; leere Dokumentangaben entfallen.'
WHERE `param_key` = '1_expVerzeichnisEbene2'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = '3. Ordnerebene für Gesamtexport und Explorer: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr oder Monat. (keine) lässt die Ebene weg. Wirkt nur bei aktivierter Verzeichnisstruktur; leere Dokumentangaben entfallen.'
WHERE `param_key` = '1_expVerzeichnisEbene3'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = '4. Ordnerebene für Gesamtexport und Explorer: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr oder Monat. (keine) lässt die Ebene weg. Wirkt nur bei aktivierter Verzeichnisstruktur; leere Dokumentangaben entfallen.'
WHERE `param_key` = '1_expVerzeichnisEbene4'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = '5. Ordnerebene für Gesamtexport und Explorer: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr oder Monat. (keine) lässt die Ebene weg. Wirkt nur bei aktivierter Verzeichnisstruktur; leere Dokumentangaben entfallen.'
WHERE `param_key` = '1_expVerzeichnisEbene5'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '' OR `beschreibung` = 'Fünfte Ebene: Kategorie, Kategorie2, Dokumententyp, Partner, Nummer, Jahr, Monat oder (keine)');

UPDATE `parameter`
SET `beschreibung` = 'ja: Unterordner gemäß den Ebenen 1 bis 5 bilden; nein: alle PDFs direkt im Exportziel ablegen. Bestimmt zugleich die virtuelle Ordnerstruktur im Ablage-Explorer.'
WHERE `param_key` = '1_expVerzeichnisstruktur'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Betreff in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expBetreff'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Dokumentdatum (JJJJ-MM-TT) in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expDatum'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'generiert: Dateiname aus den aktivierten Dokumentangaben; original: ursprünglicher PDF-Dateiname. Fehlt der Originalname, wird der Name generiert. Sonderzeichen und Überlänge werden auch im Originalmodus bereinigt.'
WHERE `param_key` = '2_expDateinameModus'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '' OR `beschreibung` = 'Dateiname: generiert oder original (fehlender Originalname: generiert)');

UPDATE `parameter`
SET `beschreibung` = 'Interne Dokument-ID in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expDokID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Bezeichnung des Dokumententyps in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expDoktyp'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Interne ID des Dokumententyps in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expDoktypID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Bezeichnung der ersten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expKategorie'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Bezeichnung der zweiten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expKategorie2'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Interne ID der zweiten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expKategorie2ID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Interne ID der ersten Kategorie in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expKategorieID'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Maximale Länge des Dateinamens ohne .pdf in Zeichen, z. B. 250. Gilt auch für Originalnamen. Lange Namen werden gekürzt; zusätzlich gilt die Dateisystemgrenze von 255 UTF-8-Bytes einschließlich .pdf und Namenszusätzen.'
WHERE `param_key` = '2_expMaxLaengeDateiname'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Wichtigste gespeicherte Nummer des Dokuments in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expNummer'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Frei wählbare Bezeichnung dieses Exportprofils, z. B. Einzelexport. Derzeit nur zur Kennzeichnung in den Einstellungen; ohne Einfluss auf den Downloadnamen.'
WHERE `param_key` = '2_expParameterBezeichnung'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Partner bzw. Absender in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expPartner'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

UPDATE `parameter`
SET `beschreibung` = 'Schlagwörter (Tags) in den Exportdateinamen aufnehmen: ja = verwenden, nein = weglassen. Gilt nur bei generiertem Dateinamen; leere Angaben entfallen.'
WHERE `param_key` = '2_expTags'
  AND (`beschreibung` IS NULL OR TRIM(`beschreibung`) = '');

COMMIT;
