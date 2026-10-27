import unittest
from unittest.mock import patch
from app.services.classification import classify

class ClassificationTest(unittest.TestCase):
    def test_primary_rule_skips_comparison_and_preserves_sources(self):
        ref={'regel_id':'R0001','version':2,'rank':250}
        auto={'matched':True,'matched_classification_rule_ref':ref,'matched_classification_rule_refs':[ref], 'partner':'Firma','category':3,'sources':{'partner':ref}}
        with patch('app.services.classification.analyze_autokat',return_value=auto), patch('app.services.classification.analyze_text_compare') as compare:
            result=classify('Text');compare.assert_not_called()
        self.assertEqual(result['info'],'AutoKat:K[R0001/v2/r250]')
        self.assertEqual(result['category'],'3');self.assertEqual(result['autokat_sources']['partner'],ref)

    def test_comparison_fills_only_missing_fields_after_supplement(self):
        auto={'matched':True,'partner':'Regelpartner','date':'2026-10-06','category':'0'}
        comparison={'matched':True,'partner':'Vergleichspartner','subject':'Betreff','category':7,'source_document_id':42,'similarity':98}
        with patch('app.services.classification.analyze_autokat',return_value=auto), patch('app.services.classification.analyze_text_compare',return_value=comparison) as compare:
            result=classify('Text',12);compare.assert_called_once_with('Text',12)
        self.assertEqual(result['partner'],'Regelpartner');self.assertEqual(result['subject'],'Betreff')
        self.assertEqual(result['date'],'2026-10-06');self.assertEqual(result['category'],'7')
        self.assertNotIn('partner',result['textcompare_sources'])
        self.assertEqual(result['info'],'AutoKat | Textvergleich:42 (98%)')

    def test_empty_document_does_not_query_database(self):
        with patch('app.services.classification.analyze_autokat') as analyze:
            self.assertEqual(classify(' \n')['info'],'Manuell');analyze.assert_not_called()

    def test_comparison_numbers_include_source_without_replacing_rule_numbers(self):
        rule_number = {'kind_id': '1', 'value': 'AutoKat', 'source': {'type': 'E', 'regel_id': 'R0001'}}
        comparison_numbers = [{'kind_id': '1', 'value': 'Vergleich'}, {'kind_id': '2', 'value': '000 123'}]
        auto = {'numbers': [rule_number]}
        comparison = {'matched': True, 'source_document_id': 42, 'similarity': 98, 'numbers': comparison_numbers}
        with patch('app.services.classification.analyze_autokat', return_value=auto), patch('app.services.classification.analyze_text_compare', return_value=comparison):
            result = classify('Text')
        self.assertEqual(result['numbers'], [rule_number, {'kind_id': '2', 'value': '000 123', 'source': {'type': 'textcompare', 'source_document_id': 42, 'similarity': 98}}])
        self.assertNotIn('source', comparison_numbers[1])
