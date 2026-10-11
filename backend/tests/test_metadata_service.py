import copy
from datetime import date, datetime
import json
import unittest
from unittest.mock import patch

from app.services.metadata_service import (
    MetadataError, UnsupportedMetadataVersion, REQUIRED_FIELDS, build_metadata,
    metadata_filename, pdf_filename, serialize_metadata, parse_metadata,
)


class MetadataTests(unittest.TestCase):
    def setUp(self):
        self.document = dict.fromkeys(REQUIRED_FIELDS)
        self.document.update(
            DokID=10001,
            Datum='2026-09-19', Archivierungsdatum=datetime(2026, 9, 19, 14, 30, 5),
            kategorie_id='001', katBez='Versicherung',
            kategorie2_id='02', kat2Bez='Haus', doktyp_id='03', doktypBez='Rechnung',
            Partner='Müller & Söhne', Betreff='Haus "A" ~ Rechnung', Nummer='00042',
            Tags='Haus; Steuer', Doktext='Zeile 1\r\nZeile 2 ~te~ 😀',
            Info='AutoKat R0042/v3@70', Aufbewahrungsdauer=10,
            Originaldateiname='Konto Müller 09.PDF', DateiSHA256='a' * 64,
            Aenderungsdatum='2026-09-20 16:40:00',
        )

    def test_roundtrip_preserves_all_business_values(self):
        original = copy.deepcopy(self.document)
        raw = serialize_metadata(self.document)
        data = json.loads(raw.encode('utf-8'))
        self.assertEqual(data, {
            'schema_version': 3, 'document_id': 10001, 'pdf_filename': '10001dok.pdf',
            'original_filename': 'Konto Müller 09.PDF', 'pdf_sha256': 'a' * 64,
            'modified_at': '2026-09-20T16:40:00',
            'document_date': '2026-09-19', 'archived_at': '2026-09-19T14:30:05',
            'retention_duration': 10,
            'category': {'id': '001', 'label': 'Versicherung'},
            'category2': {'id': '02', 'label': 'Haus'},
            'document_type': {'id': '03', 'label': 'Rechnung'},
            'partner': 'Müller & Söhne', 'subject': 'Haus "A" ~ Rechnung',
            'number': '00042', 'tags': 'Haus; Steuer',
            'document_text': 'Zeile 1\r\nZeile 2 ~te~ 😀',
        })
        self.assertEqual(self.document, original)
        self.assertEqual(raw, serialize_metadata(self.document))
        self.assertIn('Müller', raw)
        self.assertTrue(raw.endswith('\n'))

    def test_missing_fields_never_silently_disappear(self):
        for field in REQUIRED_FIELDS:
            with self.subTest(field=field):
                row = dict(self.document)
                del row[field]
                with self.assertRaises(MetadataError):
                    build_metadata(row)

    def test_nulls_empty_strings_and_unknown_columns(self):
        row = dict.fromkeys(REQUIRED_FIELDS)
        row.update(DokID=1, Partner='', ID=999)
        result = build_metadata(row)
        self.assertIsNone(result['partner'])
        self.assertIsNone(result['archived_at'])
        self.assertEqual(result['category'], {'id': None, 'label': None})
        self.assertNotIn('ID', result)

    def test_invalid_values_are_rejected(self):
        cases = [('DokID', True), ('DokID', '1'), ('DokID', 0),
                 ('Datum', '2026-02-30'), ('Datum', '19.09.2026'),
                 ('Archivierungsdatum', '2026-09-19T14:00:00Z'),
                 ('Archivierungsdatum', '2026-02-30 14:00:00'),
                 ('Aufbewahrungsdauer', '10'), ('Aufbewahrungsdauer', False),
                 ('Aufbewahrungsdauer', 1.5),
                 ('kategorie_id', 1), ('Partner', ['Müller']),
                 ('Doktext', '\ud800')]
        for field, value in cases:
            with self.subTest(field=field, value=repr(value)):
                with self.assertRaises(MetadataError):
                    build_metadata({**self.document, field: value})

    def test_lookup_without_label_is_preserved_but_label_without_id_fails(self):
        result = build_metadata({**self.document, 'katBez': None})
        self.assertEqual(result['category'], {'id': '001', 'label': None})
        with self.assertRaises(MetadataError):
            build_metadata({**self.document, 'kategorie_id': None})

    def test_filename_comes_from_id_without_path_column(self):
        row = {key: value for key, value in self.document.items() if key != 'Dateipfad'}
        self.assertEqual(build_metadata(row)['pdf_filename'], '10001dok.pdf')
        # Obsolete input fields cannot redirect metadata to another PDF.
        self.assertEqual(build_metadata({**row, 'Dateipfad': '../other.pdf'})['pdf_filename'], '10001dok.pdf')
        self.assertEqual(pdf_filename(42), '42dok.pdf')
        for value in [True, 0, -1, '42', '../1', 1.5]:
            with self.assertRaises(MetadataError):
                pdf_filename(value)

    def test_reserved_retention_is_preserved_without_interpretation(self):
        for value in [None, 0, -1, 10]:
            with self.subTest(value=value):
                data = json.loads(serialize_metadata({**self.document, 'Aufbewahrungsdauer': value}))
                self.assertEqual(data['retention_duration'], value)

    def test_filename_rejects_non_positive_integer(self):
        self.assertEqual(metadata_filename(10001), '10001meta.json')
        for value in [True, 0, -1, '../1', 1.5]:
            with self.assertRaises(MetadataError):
                metadata_filename(value)

    def test_parser_roundtrip_and_rejects_malformed_schema(self):
        raw = serialize_metadata(self.document).encode()
        self.assertEqual(parse_metadata(raw), build_metadata(self.document))
        for raw in [b'{', b'[]', b'{"schema_version":true}',
                    b'{"schema_version":1,"schema_version":1}', b'\xff']:
            with self.subTest(raw=raw), self.assertRaises(MetadataError):
                parse_metadata(raw)
        with self.assertRaises(UnsupportedMetadataVersion):
            parse_metadata(b'{"schema_version":99}')
        valid = build_metadata(self.document)
        for key, value in [('document_id', True), ('category', {'id': 1, 'label': None}),
                           ('pdf_filename', '/data/10001dok.pdf'), ('partner', ''),
                           ('retention_duration', 1.5)]:
            with self.subTest(key=key), self.assertRaises(MetadataError):
                parse_metadata(json.dumps({**valid, key: value}).encode())
        with self.assertRaises(MetadataError):
            parse_metadata(json.dumps({**valid, 'extra': 'unrecognized'}).encode())

    def test_v1_is_readable_but_has_no_v2_fields(self):
        legacy = build_metadata(self.document)
        legacy['schema_version'] = 1
        legacy['info'] = self.document['Info']
        for field in ('original_filename', 'pdf_sha256', 'modified_at'):
            del legacy[field]
        self.assertEqual(parse_metadata(json.dumps(legacy).encode()), legacy)
        with self.assertRaises(MetadataError):
            parse_metadata(json.dumps({**legacy, 'original_filename': None}).encode())

    def test_v2_keeps_legacy_info_but_v3_never_writes_it(self):
        current = build_metadata(self.document)
        self.assertNotIn('info', current)
        self.assertNotIn('Info', REQUIRED_FIELDS)
        old = {**current, 'schema_version': 2, 'info': self.document['Info']}
        self.assertEqual(parse_metadata(json.dumps(old).encode()), old)
        self.assertEqual(build_metadata({**self.document, 'Info': 'Andere Historie'}), current)
        with self.assertRaises(MetadataError):
            parse_metadata(json.dumps({**current, 'info': 'Nicht zulässig'}).encode())
        del old['info']
        with self.assertRaises(MetadataError):
            parse_metadata(json.dumps(old).encode())

    def test_v2_fields_are_strict_and_original_basename_is_preserved(self):
        for field, values in {
            'Originaldateiname': ['../a.pdf', '/a.pdf', r'C:\a.pdf', 'a\x00.pdf', 'a.txt', 'a' * 256 + '.pdf'],
            'DateiSHA256': ['', 'a' * 63, 'g' * 64, 'A' * 64, 123],
            'Aenderungsdatum': ['2026-02-30 12:00:00', '2026-09-20T12:00:00Z'],
        }.items():
            for value in values:
                with self.subTest(field=field, value=value), self.assertRaises(MetadataError):
                    build_metadata({**self.document, field: value})
        name = '  Müller: Konto?.PDF'
        self.assertEqual(build_metadata({**self.document, 'Originaldateiname': name})['original_filename'], name)

    def test_oversized_output_is_rejected_before_any_writer_uses_it(self):
        with patch('app.services.metadata_service.MAX_METADATA_BYTES', 10):
            with self.assertRaises(MetadataError):
                serialize_metadata(self.document)


if __name__ == '__main__':
    unittest.main()
