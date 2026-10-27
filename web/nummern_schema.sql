-- Vorher vollständige Datenbanksicherung erstellen; Web/Worker während Migration stoppen.
-- Wiederholbar. Altspalten bleiben ausschließlich für die Übergangskompatibilität erhalten.
SET NAMES utf8mb4;
-- Ältere Bestände besitzen teilweise nur den Primärschlüssel auf ID.
-- Der Fremdschlüssel benötigt einen Index auf DokID. Die fachliche DokID ist eindeutig.
-- Bei doppelten DokIDs bricht dieser Schritt ab: keine automatische Datenbereinigung.
ALTER TABLE dokumente ADD UNIQUE INDEX IF NOT EXISTS uq_dokumente_dokid (DokID);
CREATE TABLE IF NOT EXISTS nummernart (
 id VARCHAR(20) NOT NULL PRIMARY KEY,
 Bezeichnung VARCHAR(255) NOT NULL,
 Erlaeuterung VARCHAR(1000) DEFAULT NULL,
 Sortierung INT NOT NULL DEFAULT 10,
 Aktiv TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_nummernart_bezeichnung (Bezeichnung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO nummernart (id,Bezeichnung) VALUES ('1','Allgemein');
CREATE TABLE IF NOT EXISTS dokument_nummer (
 ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 DokumentID INT NOT NULL,
 NummernartID VARCHAR(20) NOT NULL,
 Wert VARCHAR(255) NOT NULL,
 Suchwert VARCHAR(255) NOT NULL,
 Sortierung INT NOT NULL DEFAULT 10,
 UNIQUE KEY uq_dokument_nummernart (DokumentID,NummernartID),
 KEY ix_nummer_suchwert (Suchwert),
 CONSTRAINT fk_nummer_dokument FOREIGN KEY (DokumentID) REFERENCES dokumente(DokID) ON DELETE CASCADE,
 CONSTRAINT fk_nummer_art FOREIGN KEY (NummernartID) REFERENCES nummernart(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS autokat_nummer (
 ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 AutoKatID INT NOT NULL,
 NummernartID VARCHAR(20) NOT NULL,
 Startanker VARCHAR(255) NOT NULL DEFAULT '',
 Endmodus VARCHAR(20) NOT NULL DEFAULT 'zeile',
 Endanker VARCHAR(255) NOT NULL DEFAULT '',
 Festwert VARCHAR(255) NOT NULL DEFAULT '',
 Praefix VARCHAR(255) NOT NULL DEFAULT '',
 MaxLaenge INT NOT NULL DEFAULT 0,
 Sortierung INT NOT NULL DEFAULT 10,
 UNIQUE KEY uq_autokat_nummernart (AutoKatID,NummernartID),
 CONSTRAINT fk_nummer_regel FOREIGN KEY (AutoKatID) REFERENCES autokat(id) ON DELETE CASCADE,
 CONSTRAINT fk_regel_nummer_art FOREIGN KEY (NummernartID) REFERENCES nummernart(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE dokumente MODIFY COLUMN Nummer VARCHAR(255) DEFAULT NULL;
START TRANSACTION;
INSERT IGNORE INTO dokument_nummer (DokumentID,NummernartID,Wert,Suchwert)
 SELECT DokID,'1',TRIM(Nummer),UPPER(REGEXP_REPLACE(TRIM(Nummer),'[^a-zA-Z0-9]',''))
 FROM dokumente WHERE DokID IS NOT NULL AND TRIM(COALESCE(Nummer,''))<>''
 AND NOT EXISTS (SELECT 1 FROM parameter WHERE param_key='nummernMigration' AND param_value='1');
INSERT IGNORE INTO autokat_nummer (AutoKatID,NummernartID,Startanker,Endmodus,Endanker,Festwert,Praefix,MaxLaenge)
 SELECT id,'1',COALESCE(prNum,''),'legacy',COALESCE(NumEnde,''),COALESCE(nr,''),COALESCE(prNumAnz,''),COALESCE(NumLänge,0)
 FROM autokat WHERE (TRIM(COALESCE(prNum,''))<>'' OR TRIM(COALESCE(nr,''))<>'')
 AND NOT EXISTS (SELECT 1 FROM parameter WHERE param_key='nummernMigration' AND param_value='1');
INSERT INTO parameter (param_key,param_value,anzeige) VALUES ('nummernMigration','1','nein') ON DUPLICATE KEY UPDATE param_value='1';

COMMIT;
