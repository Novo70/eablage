import unittest
from unittest.mock import patch

from app.services.date_parser import find_document_date
from app.services.export_service import build_export_filename, _unique_export_names
from app.services.text_compare import analyze_text_compare
from app.services.autokat import analyze_autokat


class RegressionTests(unittest.TestCase):
    def test_invalid_date_does_not_hide_later_date(self):
        self.assertEqual(find_document_date('32.13.2026 danach 13.09.2026'), '2026-09-13')

    def test_invalid_long_date_is_not_month_only(self):
        self.assertIsNone(find_document_date('31. Februar 2026'))
        self.assertEqual(find_document_date('31. Februar 2026 danach 13. September 2026'), '2026-09-13')

    def test_valid_date_formats_and_anchor(self):
        for text in ('13.09.26', '13. September 2026', '13.09.2026'):
            self.assertEqual(find_document_date(text), '2026-09-13')
        self.assertEqual(find_document_date('September 2026'), '2026-09-01')
        self.assertEqual(find_document_date('Geburtsdatum 01.01.1970 Datum: 13.09.2026', 'Datum'), '2026-09-13')

    def test_utf8_filename_limit(self):
        for value in ('ä'*250, '😀'*250, 'a'*400):
            result = build_export_filename({'DokID':1, 'Betreff':value}, {'expBetreff':'ja','expMaxLaengeDateiname':'500'})
            self.assertLessEqual(len(result.encode('utf-8')), 255)
            self.assertTrue(result.endswith('.pdf'))

    def test_export_collisions_and_reserved_suffix(self):
        items = [{'dok_id':i, 'filename':name, 'directories':[]} for i,name in enumerate(
            ['Test.pdf','test.pdf','test - DokID 1.pdf','test.pdf'], start=0)]
        _unique_export_names(items)
        self.assertEqual(len({i['filename'].casefold() for i in items}), len(items))
        self.assertEqual(items[2]['filename'], 'test - DokID 1.pdf')
        again = [dict(item) for item in items]
        _unique_export_names(again)
        self.assertEqual(items, again)

    def test_export_different_directories_and_unicode_suffix(self):
        name=build_export_filename({'DokID':1,'Betreff':'ä'*250},{'expBetreff':'ja'})
        items=[{'dok_id':i,'filename':name,'directories':[directory]} for i,directory in enumerate(['A','a','B'])]
        _unique_export_names(items)
        self.assertNotEqual(items[0]['filename'],items[1]['filename'])
        self.assertEqual(items[0]['filename'],items[2]['filename'])
        self.assertTrue(all(len(item['filename'].encode())<=255 for item in items))

    def test_reclassification_excludes_self(self):
        text='Rechnung Stromverbrauch Abschlag'
        with patch('app.services.text_compare._load_parameters',return_value={'minLaengeVergl':'0'}), patch('app.services.text_compare._load_documents',return_value=[{'DokID':42,'Doktext':text,'partner':'Alt'}]):
            self.assertEqual(analyze_text_compare(text)['source_document_id'],42)
            self.assertFalse(analyze_text_compare(text,42)['matched'])

    def test_reclassification_can_find_other_document(self):
        text='Rechnung Stromverbrauch Abschlag'
        with patch('app.services.text_compare._load_parameters',return_value={'minLaengeVergl':'0'}), patch('app.services.text_compare._load_documents',return_value=[{'DokID':41,'Doktext':text,'partner':'Vorlage'},{'DokID':42,'Doktext':text,'partner':'Alt'}]):
            self.assertEqual(analyze_text_compare(text,42)['source_document_id'],41)

    def test_autokat_priority_and_multiple_matches(self):
        rules=[{'id':i,'RegelID':f'R{i:04}','Regeltyp':typ,'Suchbegriffe':'Rechnung','partner':partner} for i,typ,partner in [(1,'E','E'),(2,'K','K'),(3,'K','K2')]]
        with patch('app.services.autokat.load_autokat_rules',return_value=rules):
            result=analyze_autokat('Rechnung Datum: 13.09.2026')
        self.assertEqual(result['partner'],'K')
        self.assertEqual(len(result['matched_classification_rule_refs']),2)


if __name__ == '__main__':
    unittest.main()
