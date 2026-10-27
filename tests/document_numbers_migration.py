"""Regression: legacy DokID index missing, partial retry and duplicate rejection.

EABLAGE_TEST_DB_PORT opts into a disposable local MariaDB. Each test creates and
drops only its own randomly named database. No application/production connection.
"""
import os
from pathlib import Path
import unittest
import uuid
import mysql.connector

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipUnless(os.getenv('EABLAGE_TEST_DB_PORT'), 'Disposable local MariaDB required')
class NumberMigrationTests(unittest.TestCase):
    def setUp(self):
        self.db = mysql.connector.connect(host='127.0.0.1',
            port=int(os.environ['EABLAGE_TEST_DB_PORT']), user='root',
            password=os.getenv('EABLAGE_TEST_DB_PASSWORD', ''), autocommit=True)
        self.cursor = self.db.cursor()
        self.name = 'eablage_fk_' + uuid.uuid4().hex[:12]
        self.cursor.execute(f'CREATE DATABASE `{self.name}` CHARACTER SET utf8mb4')
        self.cursor.execute(f'USE `{self.name}`')
        self.addCleanup(self.cleanup_database)
        schema = (ROOT / 'sql/schema.sql').read_text()
        self.script(schema.split('-- Vorher vollständige Datenbanksicherung')[0])
        self.migration = (ROOT / 'sql/update_document_numbers.sql').read_text()

    def cleanup_database(self):
        self.cursor.execute(f'DROP DATABASE `{self.name}`')
        self.db.close()

    def script(self, sql):
        sql = '\n'.join(line for line in sql.splitlines() if not line.lstrip().startswith('--'))
        for statement in sql.split(';'):
            if statement.strip():
                self.cursor.execute(statement)

    def rows(self, sql):
        self.cursor.execute(sql)
        return self.cursor.fetchall()

    def test_fresh_schema_and_repeated_migration(self):
        self.script(self.migration)
        self.script(self.migration)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM nummernart'), [(1,)])

    def test_missing_index_and_preexisting_number_type_table(self):
        self.cursor.execute('ALTER TABLE dokumente DROP INDEX uq_dokumente_dokid')
        self.cursor.execute("INSERT INTO dokumente (DokID,Nummer) VALUES (10001,'AB-123/45'),(NULL,NULL),(NULL,NULL)")
        # Reproduce the user's first failed attempt: nummernart already exists,
        # but the next CREATE fails because the parent DokID has no index.
        prefix = self.migration.split('CREATE TABLE IF NOT EXISTS dokument_nummer')[0]
        prefix = prefix.replace('ALTER TABLE dokumente ADD UNIQUE INDEX IF NOT EXISTS uq_dokumente_dokid (DokID);', '')
        self.script(prefix)
        create_numbers = self.migration.split('CREATE TABLE IF NOT EXISTS dokument_nummer', 1)[1].split(';', 1)[0]
        with self.assertRaises(mysql.connector.Error) as failure:
            self.cursor.execute('CREATE TABLE IF NOT EXISTS dokument_nummer' + create_numbers)
        self.assertEqual(failure.exception.errno, 1005)
        self.script(self.migration)
        self.script(self.migration)
        self.assertEqual(self.rows('SELECT DokumentID,Wert FROM dokument_nummer'), [(10001,'AB-123/45')])
        self.assertEqual(self.rows('SELECT COUNT(*) FROM dokumente'), [(3,)])
        with self.assertRaises(mysql.connector.IntegrityError):
            self.cursor.execute('INSERT INTO dokumente (DokID) VALUES (10001)')
        self.cursor.execute('DELETE FROM dokumente WHERE DokID=10001')
        self.assertEqual(self.rows('SELECT COUNT(*) FROM dokument_nummer'), [(0,)])

    def test_duplicate_docids_abort_without_changing_documents(self):
        self.cursor.execute('ALTER TABLE dokumente DROP INDEX uq_dokumente_dokid')
        self.cursor.execute("INSERT INTO dokumente (DokID,Nummer) VALUES (10001,'A'),(10001,'B')")
        before = self.rows('SELECT * FROM dokumente ORDER BY ID')
        with self.assertRaises(mysql.connector.IntegrityError) as failure:
            self.script(self.migration)
        self.assertEqual(failure.exception.errno, 1062)
        self.assertEqual(self.rows('SELECT * FROM dokumente ORDER BY ID'), before)
        self.assertEqual(self.rows("SHOW TABLES LIKE 'dokument_nummer'"), [])
        self.assertEqual(self.rows("SELECT * FROM parameter WHERE param_key='nummernMigration'"), [])


if __name__ == '__main__':
    unittest.main()
