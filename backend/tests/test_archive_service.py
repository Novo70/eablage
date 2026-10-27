import os
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from app.services import archive_service as service
from app.services.metadata_service import REQUIRED_FIELDS, serialize_metadata, parse_metadata




class Cursor:
    def __init__(self, case):
        self.case = case
        self.query = ''
        self.params = ()

    def execute(self, query, params=()):
        self.query, self.params = query, params
        self.case.queries.append(query)

    def fetchone(self):
        if self.query.startswith('SELECT DATABASE'):
            return {'name': 'test'}
        return next((d for d in self.case.documents if d['DokID'] == self.params[0]), None)

    def fetchall(self):
        if 'FROM dokument_nummer' in self.query:
            return [dict(n, DokumentID=self.case.documents[0]['DokID']) for n in self.case.documents[0].get('numbers',[])] if 'n.DokumentID,' in self.query else self.case.documents[0].get('numbers',[])
        if 'FROM parameter' in self.query:
            return [{'param_key': key, 'param_value': value} for key, value in self.case.settings.items()]
        return self.case.documents


class Connection:
    def __init__(self, case):
        self.case = case

    def cursor(self, **kwargs):
        return Cursor(self.case)

    def close(self):
        pass

    def rollback(self):
        pass


class ArchiveTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.archive = self.root / 'archiv'
        self.meta = self.root / 'meta'
        self.archive.mkdir()
        self.meta.mkdir()
        self.settings = {'pfadAblage_linux': str(self.archive), 'pfadMetadaten_linux': str(self.meta)}
        document = dict.fromkeys(REQUIRED_FIELDS)
        document.update(DokID=10001, Datum='2026-09-13',
                        Partner='Müller ~ Partner', Doktext='Zeile 1\nZeile 2 ~ Ende')
        self.documents = [document]
        self.pdf = self.archive / '10001dok.pdf'
        self.pdf.write_bytes(b'%PDF-example-unchanged')
        self.target = self.meta / '10001meta.json'
        self.legacy = self.meta / '10001meta.txt'
        self.queries = []
        for patcher in [patch.dict(os.environ, {'ARCHIVE_DATA_ROOT': str(self.root)}),
                        patch.object(service, '_connection', side_effect=lambda: Connection(self))]:
            patcher.start()
            self.addCleanup(patcher.stop)

    def selection(self):
        return [{k: item[k] for k in ('dok_id', 'token')} for item in service.audit('test')['issues'] if 'token' in item]

    def test_legacy_info_is_not_a_repair_reason(self):
        document = self.documents[0]
        expected = json.loads(serialize_metadata(document))
        expected.pop('numbers')
        legacy = {**expected, 'schema_version': 2, 'info': 'Historischer Text'}
        self.assertEqual(service._meta_status(document, json.dumps(legacy).encode()), ('ok', []))
        document['Info'] = 'Anderer Alttext'
        self.assertEqual(service._meta_status(document, json.dumps(legacy).encode()), ('ok', []))

    def test_missing_metadata_repair_then_clean_and_no_db_or_pdf_writes(self):
        before = self.pdf.read_bytes()
        selected = self.selection()
        self.assertFalse(self.target.exists())
        self.assertTrue(service.repair('test', selected)['results'][0]['success'])
        self.assertEqual(service.audit('test')['issues'], [])
        self.assertEqual(self.pdf.read_bytes(), before)
        self.assertTrue(all(query.startswith('SELECT') for query in self.queries))
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o664)

    def test_changed_db_rejected(self):
        selected = self.selection()
        self.documents[0]['Partner'] = 'Neu'
        self.assertFalse(service.repair('test', selected)['results'][0]['success'])
        self.assertFalse(self.target.exists())

    def test_changed_file_rejected(self):
        selected = self.selection()
        self.target.write_text('Extern geändert')
        self.assertFalse(service.repair('test', selected)['results'][0]['success'])
        self.assertEqual(self.target.read_text(), 'Extern geändert')

    def test_invalid_file_repair(self):
        self.target.write_bytes(b'\xffbroken')
        self.assertEqual(service.audit('test')['issues'][0]['status'], 'meta_invalid')
        self.assertTrue(service.repair('test', self.selection())['results'][0]['success'])


    def test_old_txt_is_not_a_metadata_source_and_remains_untouched(self):
        self.legacy.write_bytes(b'old metadata')
        report = service.audit('test')
        self.assertTrue(any(issue['status'] == 'meta_missing' for issue in report['issues']))
        self.assertTrue(any(issue['status'] == 'meta_orphan' for issue in report['issues']))
        self.assertTrue(service.repair('test', self.selection())['results'][0]['success'])
        self.assertEqual(self.legacy.read_bytes(), b'old metadata')
        self.assertEqual(parse_metadata(self.target.read_bytes())['document_id'], 10001)

    def test_difference_reports_field(self):
        self.target.write_text(serialize_metadata({**self.documents[0], 'Partner': 'Alt'}))
        issue = service.audit('test')['issues'][0]
        self.assertEqual(issue['details'], ['partner'])
        self.assertEqual(issue['status'], 'meta_different')





    def test_orphans_and_missing_pdf_report_only(self):
        self.pdf.unlink()
        (self.archive / 'orphan.pdf').write_bytes(b'unchanged')
        (self.meta / '42meta.txt').write_bytes(b'unchanged')
        statuses = {i['status'] for i in service.audit('test')['issues']}
        self.assertEqual(statuses, {'pdf_missing', 'pdf_orphan', 'meta_missing', 'meta_orphan'})
        self.assertEqual((self.archive / 'orphan.pdf').read_bytes(), b'unchanged')

    def test_symlink_not_followed_or_repaired(self):
        victim = self.root / 'victim'
        victim.write_text('unchanged')
        self.target.symlink_to(victim)
        self.assertEqual(service.audit('test')['issues'][0]['status'], 'meta_unreadable')
        self.assertEqual(self.selection(), [])
        self.assertEqual(victim.read_text(), 'unchanged')

    def test_wrong_database_fails(self):
        with self.assertRaises(service.ArchiveError):
            service.audit('production')

    def test_missing_outside_and_overlapping_directories_fail(self):
        for value in [str(self.root / 'missing'), '/', str(self.archive)]:
            self.settings['pfadMetadaten_linux'] = value
            with self.assertRaises(service.ArchiveError):
                service.audit('test')

    def test_unreadable_directory_fails_instead_of_empty_result(self):
        with patch.object(Path, 'iterdir', side_effect=PermissionError):
            with self.assertRaises(PermissionError):
                service.audit('test')

    def test_expired_tampered_and_restart_tokens_rejected(self):
        selected = self.selection()
        with patch.object(service.time, 'time', return_value=service.time.time()+1900):
            self.assertFalse(service.repair('test', selected)['results'][0]['success'])
        selected[0]['token'] += 'x'
        self.assertFalse(service.repair('test', selected)['results'][0]['success'])
        selected = self.selection()
        with patch.object(service, '_SECRET', b'new process'):
            self.assertFalse(service.repair('test', selected)['results'][0]['success'])

    def test_failed_replace_keeps_old_file_and_cleans_temp(self):
        self.target.write_text('old')
        with patch.object(service.os, 'replace', side_effect=PermissionError):
            result = service.repair('test', self.selection())
        self.assertFalse(result['results'][0]['success'])
        self.assertEqual(self.target.read_text(), 'old')
        self.assertEqual(list(self.meta.iterdir()), [self.target])




    def test_future_schema_is_not_repairable(self):
        self.target.write_text('{"schema_version": 99}')
        report = service.audit('test')
        self.assertEqual(report['issues'][0]['status'], 'meta_unsupported')
        self.assertEqual(report['repairable'], 0)

    def test_invalid_database_values_are_not_repairable(self):
        self.documents[0]['Datum'] = '2026-02-30'
        report = service.audit('test')
        self.assertEqual(report['issues'][0]['status'], 'meta_source_invalid')
        self.assertEqual(report['repairable'], 0)

    def test_new_fields_and_lookup_labels_invalidate_token(self):
        self.documents[0].update(kategorie_id='001', katBez='Original')
        for field, value in [('katBez', 'Neu'), ('Aufbewahrungsdauer', -1),
                             ('Archivierungsdatum', '2026-09-20 12:00:00'),
                             ('Originaldateiname', 'Original.pdf'), ('DateiSHA256', 'a' * 64),
                             ('Aenderungsdatum', '2026-09-21 12:00:00')]:
            with self.subTest(field=field):
                selected = self.selection()
                self.documents[0][field] = value
                self.assertFalse(service.repair('test', selected)['results'][0]['success'])

    def test_json_orphan_and_semantic_json_comparison(self):
        data = json.loads(serialize_metadata(self.documents[0]))
        self.target.write_bytes(('\ufeff' + json.dumps(data, sort_keys=True)).encode())
        self.assertEqual(service.audit('test')['issues'], [])
        (self.meta / '42meta.json').write_text('{}')
        self.assertEqual(service.audit('test')['issues'][0]['status'], 'meta_orphan')

    def test_schema1_with_unknown_new_values_is_current(self):
        data = json.loads(serialize_metadata(self.documents[0]))
        data.pop('numbers')
        data['schema_version'] = 1
        data['info'] = None
        for key in ('original_filename', 'pdf_sha256', 'modified_at'):
            del data[key]
        self.target.write_text(json.dumps(data))
        self.assertEqual(service.audit('test')['issues'], [])
        self.documents[0]['Originaldateiname'] = 'Scan.pdf'
        issue = service.audit('test')['issues'][0]
        self.assertEqual(issue['details'], ['original_filename'])
        self.assertTrue(service.repair('test', self.selection())['results'][0]['success'])
        self.assertEqual(parse_metadata(self.target.read_bytes())['schema_version'], 4)

    def test_pdf_hash_audit_never_rebases_reference_or_repairs_pdf(self):
        reference = hashlib.sha256(self.pdf.read_bytes()).hexdigest()
        self.documents[0]['DateiSHA256'] = reference
        self.target.write_text(serialize_metadata(self.documents[0]))
        report = service.audit('test')
        self.assertEqual(report['issues'], [])
        self.assertEqual(report['checksums_checked'], 1)
        self.assertEqual(report['checksums_unknown'], 0)
        self.pdf.write_bytes(b'%PDF-changed')
        report = service.audit('test')
        self.assertEqual([i['status'] for i in report['issues']], ['pdf_hash_mismatch'])
        self.assertEqual(report['repairable'], 0)
        self.assertEqual(self.documents[0]['DateiSHA256'], reference)
        self.assertEqual(self.pdf.read_bytes(), b'%PDF-changed')
        self.assertTrue(all(query.startswith('SELECT') for query in self.queries))

    def test_unknown_hash_is_not_backfilled_and_links_are_rejected(self):
        self.assertEqual(service.audit('test')['checksums_unknown'], 1)
        self.assertIsNone(self.documents[0]['DateiSHA256'])
        link = self.archive / 'link.pdf'
        link.symlink_to(self.pdf)
        with self.assertRaises(OSError):
            service._hash_pdf(link)

    def test_concurrently_created_json_is_not_overwritten(self):
        selected = self.selection()
        original_link = service.os.link
        def create_conflict(source, target):
            Path(target).write_text('external JSON')
            return original_link(source, target)
        with patch.object(service.os, 'link', side_effect=create_conflict):
            self.assertFalse(service.repair('test', selected)['results'][0]['success'])
        self.assertEqual(self.target.read_text(), 'external JSON')


if __name__ == '__main__':
    unittest.main()

