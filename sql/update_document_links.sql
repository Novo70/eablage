-- Vorher Datenbank sichern; Web/Worker während der Migration stoppen.
SET NAMES utf8mb4;
ALTER TABLE dokumente ADD UNIQUE INDEX IF NOT EXISTS uq_dokumente_dokid (DokID);
CREATE TABLE IF NOT EXISTS vorgang (
 ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 Bezeichnung VARCHAR(255) NOT NULL,
 Beschreibung TEXT NOT NULL,
 ErstelltAm DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 GeaendertAm DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_vorgang_bezeichnung (Bezeichnung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dokument_vorgang (
 DokumentID INT NOT NULL,
 VorgangID INT NOT NULL,
 PRIMARY KEY (DokumentID,VorgangID),
 KEY ix_dokument_vorgang (VorgangID,DokumentID),
 CONSTRAINT fk_vorgang_dokument FOREIGN KEY (DokumentID) REFERENCES dokumente(DokID) ON DELETE CASCADE,
 CONSTRAINT fk_dokument_vorgang FOREIGN KEY (VorgangID) REFERENCES vorgang(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
