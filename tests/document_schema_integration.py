"""Explicit disposable local MariaDB test, never a NAS/production connection.

EABLAGE_TEST_DB_PORT opts in; optional EABLAGE_TEST_DB_PASSWORD for local root.
Creates and drops only one randomly named test database. No PHP required.
"""
import os
from pathlib import Path
import unittest
import uuid

import mysql.connector

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipUnless(os.environ.get('EABLAGE_TEST_DB_PORT'), 'Disposable local MariaDB required')
class DocumentSchemaIntegration(unittest.TestCase):
    def setUp(self):
        self.db = mysql.connector.connect(
            host='127.0.0.1', port=int(os.environ['EABLAGE_TEST_DB_PORT']), user='root',
            password=os.environ.get('EABLAGE_TEST_DB_PASSWORD', ''), autocommit=True, use_pure=True)
        self.addCleanup(self.db.close)
        self.cursor = self.db.cursor()
        self.name = 'eablage_schema_' + uuid.uuid4().hex[:12]
        self.cursor.execute(f'CREATE DATABASE `{self.name}` CHARACTER SET utf8mb4')
        self.addCleanup(self.drop_database)
        self.cursor.execute(f'USE `{self.name}`')
        self.run_script('sql/schema.sql')
        self.cursor.execute('ALTER TABLE dokumente DROP COLUMN Originaldateiname, '
                            'DROP COLUMN DateiSHA256, DROP COLUMN Aenderungsdatum, '
                            'MODIFY COLUMN Datum VARCHAR(15) DEFAULT NULL')

    def drop_database(self):
        self.cursor.execute(f'DROP DATABASE `{self.name}`')

    def execute(self, statement, params=()):
        self.cursor.execute(statement, params)
        results = []
        while True:
            if self.cursor.with_rows:
                results.extend(self.cursor.fetchall())
            if not self.cursor.nextset():
                break
        return results

    def run_script(self, path):
        delimiter, pending = ';', []
        for line in (ROOT / path).read_text().splitlines():
            if line.lstrip().startswith('--') or not line.strip():
                continue
            if line.startswith('DELIMITER '):
                self.assertFalse(pending)
                delimiter = line.split()[1]
                continue
            pending.append(line)
            if line.rstrip().endswith(delimiter):
                self.execute('\n'.join(pending).rstrip()[:-len(delimiter)])
                pending = []
        self.assertFalse(pending)

    def test_history_migration_preserves_entries_and_is_repeatable(self):
        self.execute('RENAME TABLE ereignisprotokoll TO dokument_protokoll')
        self.execute("INSERT INTO dokument_protokoll (ID, DokID, Zeitpunkt, Ereignistyp, Quelle, Details) "
                     "VALUES (17, 42, '2026-10-09 07:00:00', 'archiviert', 'Web', %s)",
                     ('{"originaldateiname":"alt.pdf","text":"dokument_protokoll"}',))
        before = self.execute('SELECT * FROM dokument_protokoll')
        for _ in range(2):
            self.run_script('sql/update_document_history.sql')
            self.assertEqual(self.execute('SELECT * FROM ereignisprotokoll'), before)
            self.assertFalse(self.execute("SHOW TABLES LIKE 'dokument_protokoll'"))
        self.execute('DROP TABLE ereignisprotokoll')
        self.run_script('sql/update_document_history.sql')
        self.assertEqual(self.execute('SELECT COUNT(*) FROM ereignisprotokoll'), [(0,)])

    def test_history_migration_rejects_conflicting_names_without_data_loss(self):
        self.execute('CREATE TABLE dokument_protokoll LIKE ereignisprotokoll')
        for table, dokid in [('dokument_protokoll', 41), ('ereignisprotokoll', 42)]:
            self.execute(f"INSERT INTO {table} (DokID, Ereignistyp, Quelle, Details) "
                         "VALUES (%s, 'archiviert', 'Web', '{}')", (dokid,))
        with self.assertRaises(mysql.connector.Error):
            self.run_script('sql/update_document_history.sql')
        self.assertEqual(self.execute('SELECT DokID FROM dokument_protokoll'), [(41,)])
        self.assertEqual(self.execute('SELECT DokID FROM ereignisprotokoll'), [(42,)])

    def test_access_parameter_cleanup_preserves_active_settings_and_columns(self):
        obsolete = ('1_expVerzeichnis', '2_expVerzeichnisEbene4', 'AnalysisApiUrl',
                    'PDFToTextLayout', 'dbTabelleArchiv')
        current = {'Version': 'Test 4.0.0', 'dokTextLaenge': '20000',
                   '1_expVerzeichnis_linux': '/data/export/',
                   '1_expVerzeichnisEbene5': 'Monat',
                   '2_expDateinameModus': 'original',
                   'PDFTextMode': 'layout',
                   'pfadAblage_linux': '/data/archiv/'}
        for key in obsolete:
            self.execute('INSERT INTO parameter (param_key,param_value) VALUES (%s,%s)',
                         (key, 'legacy'))
        for key, value in current.items():
            self.execute('INSERT INTO parameter (param_key,param_value) VALUES (%s,%s)',
                         (key, value))
        before = self.execute('SHOW COLUMNS FROM dokumente')
        for _ in range(2):
            self.run_script('sql/update_access_parameters_4.0.0.sql')
            self.assertEqual(sorted(self.execute('SELECT param_key,param_value FROM parameter ORDER BY param_key')),
                             sorted(current.items()))
            self.assertEqual(self.execute('SHOW COLUMNS FROM dokumente'), before)
            self.assertFalse(self.execute("SHOW COLUMNS FROM autokat LIKE 'Suchbegriff1'"))

    def test_autokat_41_cleanup_keeps_current_rule_fields(self):
        self.execute('ALTER TABLE autokat '
                     'ADD COLUMN Suchbegriff1 VARCHAR(255), '
                     'ADD COLUMN Suchbegriff2 VARCHAR(255), '
                     'ADD COLUMN sucheBeenden TINYINT(1) DEFAULT 0')
        self.execute("INSERT INTO autokat (RegelID, Regeltyp, Suchbegriffe, Ausschlussbegriffe, "
                     "Suchbegriff1, Suchbegriff2, sucheBeenden) "
                     "VALUES ('R0001', 'K', 'Rechnung', 'Entwurf', 'alt', 'alt2', 1)")
        for _ in range(2):
            self.run_script('sql/update_autokat_4.1.0.sql')
            for name in ('Suchbegriff1', 'Suchbegriff2', 'sucheBeenden'):
                self.assertFalse(self.execute(f"SHOW COLUMNS FROM autokat LIKE '{name}'"))
            self.assertEqual(
                self.execute("SELECT RegelID, Suchbegriffe, Ausschlussbegriffe FROM autokat"),
                [('R0001', 'Rechnung', 'Entwurf')])

    def test_additive_update_is_repeatable_and_preserves_unknowns_and_parameters(self):
        self.execute("INSERT INTO dokumente (DokID, Datum, Info) VALUES (10001, 'bad date', 'Altbestand')")
        self.run_script('sql/update_document_metadata_4.0.0.sql')
        self.assertEqual(self.execute('SELECT Datum,Info,Originaldateiname,DateiSHA256,Aenderungsdatum FROM dokumente'),
                         [('bad date', 'Altbestand', None, None, None)])
        self.execute("UPDATE parameter SET param_value='original' WHERE param_key='1_expDateinameModus'")
        self.execute("UPDATE dokumente SET Originaldateiname='Scan.pdf', DateiSHA256=%s", ('a' * 64,))
        self.run_script('sql/update_document_metadata_4.0.0.sql')
        self.assertEqual(self.execute("SELECT param_value FROM parameter WHERE param_key='1_expDateinameModus'"), [('original',)])
        self.assertEqual(self.execute('SELECT Originaldateiname,DateiSHA256 FROM dokumente'), [('Scan.pdf', 'a' * 64)])

    def test_date_guard_rejects_invalid_values_and_default_script_only_previews(self):
        self.run_script('sql/update_document_date_4.0.0.sql')
        self.assertEqual(self.execute("SHOW COLUMNS FROM dokumente LIKE 'Datum'")[0][1], 'varchar(15)')
        invalid = ['', ' ', '0000-00-00', '0999-12-31', '2026-00-01', '2026-13-01',
                   '2026-01-00', '2026-02-29', '1900-02-29', '2100-02-29', '2026-04-31',
                   '2026-9-01', '01.09.2026', '2026-09-01 ', '2026-09-01\n', '2026-09-01x']
        for value in invalid:
            with self.subTest(value=value):
                self.execute('INSERT INTO dokumente (DokID,Datum) VALUES (10001,%s)', (value,))
                with self.assertRaises(mysql.connector.Error):
                    self.execute('CALL eablage_400_document_date(TRUE)')
                self.assertEqual(self.execute('SELECT Datum FROM dokumente'), [(value,)])
                self.assertEqual(self.execute("SHOW COLUMNS FROM dokumente LIKE 'Datum'")[0][1], 'varchar(15)')
                self.execute('DELETE FROM dokumente WHERE DokID=10001')
        values = [None, '1000-01-01', '1900-02-28', '2000-02-29', '2024-02-29', '2026-09-22', '9999-12-31']
        for index, value in enumerate(values, 1):
            self.execute('INSERT INTO dokumente (DokID,Datum) VALUES (%s,%s)', (index, value))
        self.execute('CALL eablage_400_document_date(FALSE)')
        self.assertEqual(self.execute("SHOW COLUMNS FROM dokumente LIKE 'Datum'")[0][1], 'varchar(15)')
        self.execute('CALL eablage_400_document_date(TRUE)')
        self.execute('CALL eablage_400_document_date(TRUE)')
        self.assertEqual(self.execute("SHOW COLUMNS FROM dokumente LIKE 'Datum'")[0][1], 'date')
        self.assertEqual([row[0].isoformat() if row[0] else None
                          for row in self.execute('SELECT Datum FROM dokumente ORDER BY DokID')], values)

    def test_document_path_migration_preserves_canonical_inventory(self):
        self.execute('ALTER TABLE dokumente ADD Dateipfad VARCHAR(255), ADD KEY Dateipfad (Dateipfad)')
        paths = ['1dok.pdf', '/data/archiv/2dok.pdf', r'W:\Archiv\3dok.pdf', None, '']
        for doc_id, path in enumerate(paths, 1):
            self.execute('INSERT INTO dokumente (DokID,Dateipfad,GeloeschtAm) VALUES (%s,%s,%s)',
                         (doc_id, path, '2026-10-05 12:00:00' if doc_id == 3 else None))
        before = self.execute('SELECT ID,DokID,GeloeschtAm FROM dokumente ORDER BY DokID')
        self.run_script('sql/update_document_path.sql')
        self.assertEqual(self.execute("SHOW COLUMNS FROM dokumente LIKE 'Dateipfad'"), [])
        self.assertFalse(any(row[2] == 'Dateipfad' for row in self.execute('SHOW INDEX FROM dokumente')))
        self.assertEqual(self.execute('SELECT ID,DokID,GeloeschtAm FROM dokumente ORDER BY DokID'), before)
        self.run_script('sql/update_document_path.sql')

    def test_document_path_migration_rejects_mismatch_without_dropping_column(self):
        self.execute('ALTER TABLE dokumente ADD Dateipfad VARCHAR(255)')
        for path in ['other.pdf', '1dok.PDF', '1dok.pdf ', '   ', '/data/archiv/']:
            with self.subTest(path=path):
                self.execute('INSERT INTO dokumente (DokID,Dateipfad) VALUES (1,%s)', (path,))
                with self.assertRaises(mysql.connector.Error):
                    self.run_script('sql/update_document_path.sql')
                self.assertEqual(self.execute('SELECT Dateipfad FROM dokumente'), [(path,)])
                self.execute('DELETE FROM dokumente')

    def test_document_path_migration_rejects_invalid_id(self):
        self.execute('ALTER TABLE dokumente ADD Dateipfad VARCHAR(255)')
        for doc_id in [None, 0, -1]:
            self.execute('INSERT INTO dokumente (DokID,Dateipfad) VALUES (%s,NULL)', (doc_id,))
            with self.assertRaises(mysql.connector.Error):
                self.run_script('sql/update_document_path.sql')
            self.assertTrue(self.execute("SHOW COLUMNS FROM dokumente LIKE 'Dateipfad'"))
            self.execute('DELETE FROM dokumente')


if __name__ == '__main__':
    unittest.main()
