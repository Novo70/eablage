import unittest
from unittest.mock import patch

from app.services.autokat import analyze_autokat
from app.services.classification import classify


def rule(rule_id, rank, **values):
    return dict(id=rule_id, RegelID=f'R{rule_id:04}', Version=1,
                Rang=rank, Regeltyp='E', Suchbegriffe='SV-Versicherung', **values)


class SupplementPriorityTests(unittest.TestCase):
    def analyze(self, rules, text='SV-Versicherung Versicherungsschein'):
        with patch('app.services.autokat.load_autokat_rules', return_value=rules):
            return analyze_autokat(text)

    def test_specific_supplements_overwrite_and_keep_unassigned_fields(self):
        general = rule(1, 10, kat='7', dok='1', partner='SV-Versicherung')
        specific = rule(2, 20, dok='2')
        specific['Suchbegriffe'] = 'SV-Versicherung;Versicherungsschein'
        number = rule(3, 30, nr='VS-123')
        result = self.analyze([general, specific, number])
        self.assertEqual(result['category'], '7')
        self.assertEqual(result['document_type'], '2')
        self.assertEqual(result['partner'], 'SV-Versicherung')
        self.assertEqual(result['number'], 'VS-123')
        self.assertEqual(result['sources']['category']['rank'], 10)
        self.assertEqual(result['sources']['document_type']['rank'], 20)
        self.assertEqual(result['sources']['number']['rank'], 30)
        self.assertEqual(result['matched_rules'], 3)

    def test_each_nonempty_field_can_be_replaced(self):
        fields = {'kat': 'category', 'kat2': 'category2', 'dok': 'document_type',
                  'partner': 'partner', 'betreff': 'subject', 'nr': 'number', 'tags': 'tags'}
        for source, target in fields.items():
            with self.subTest(field=target):
                result = self.analyze([rule(1, 10, **{source: '1'}),
                                       rule(2, 20, **{source: '2'})])
                self.assertEqual(result[target], '2')
                self.assertEqual(result['sources'][target]['regel_id'], 'R0002')

    def test_empty_values_and_zero_lookups_preserve_previous_values_and_sources(self):
        for empty in (None, '', '   ', '0', 0):
            with self.subTest(empty=empty):
                result = self.analyze([rule(1, 10, kat='7', kat2='8', dok='2', partner='SV'),
                                       rule(2, 20, kat=empty, kat2=empty, dok=empty, partner=' ')])
                for field, expected in [('category', '7'), ('category2', '8'),
                                        ('document_type', '2'), ('partner', 'SV')]:
                    self.assertEqual(result[field], expected)
                    self.assertEqual(result['sources'][field]['rank'], 10)

    def test_extraction_overwrites_but_failed_extraction_preserves_value(self):
        rules = [rule(1, 10, nr='allgemein', betreff='allgemein'),
                 rule(2, 20, prNum='Nummer', NumEnde='Betreff',
                      prBetreff='Betreff'),
                 rule(3, 30, prNum='Fehlende Nummer', prBetreff='Fehlender Betreff')]
        result = self.analyze(rules, 'SV-Versicherung Nummer: VS-123 Betreff: Police')
        self.assertEqual(result['number'], 'VS-123')
        self.assertEqual(result['subject'], 'Police')
        self.assertEqual(result['sources']['number']['method'], 'extract')
        self.assertEqual(result['sources']['subject']['rank'], 20)

    def test_later_date_anchor_overwrites_earlier_anchor(self):
        rules = [rule(1, 10, prDatum='Ausgestellt'),
                 rule(2, 20, prDatum='Beginn'), rule(3, 30, prDatum='Fehlt')]
        result = self.analyze(rules, 'SV-Versicherung 01.01.2026 Ausgestellt: 02.02.2026 Beginn: 03.03.2026')
        self.assertEqual(result['date'], '2026-03-03')
        self.assertEqual(result['sources']['date']['rank'], 20)

    def test_nonmatching_and_excluded_rules_do_not_overwrite(self):
        missing = rule(2, 20, dok='2')
        missing['Suchbegriffe'] = 'SV-Versicherung;Mahnung'
        excluded = rule(3, 30, dok='3', Ausschlussbegriffe='Versicherungsschein')
        result = self.analyze([rule(1, 10, dok='1'), missing, excluded])
        self.assertEqual(result['document_type'], '1')
        self.assertEqual(result['matched_rules'], 1)

    def test_equal_rank_uses_existing_loader_id_order(self):
        result = self.analyze([rule(1, 10, dok='1'), rule(2, 10, dok='2')])
        self.assertEqual(result['document_type'], '2')
        self.assertEqual(result['sources']['document_type']['rule_id'], 2)

    def test_first_classification_still_overwrites_and_retains_supplement_remainders(self):
        first_k = rule(3, 30, dok='3')
        first_k['Regeltyp'] = 'K'
        second_k = rule(4, 40, dok='4', partner='Andere Versicherung')
        second_k['Regeltyp'] = 'K'
        rules = [rule(1, 10, dok='1', partner='SV'), rule(2, 20, dok='2'), first_k, second_k]
        with patch('app.services.autokat.load_autokat_rules', return_value=rules), \
             patch('app.services.classification.analyze_text_compare') as compare:
            result = classify('SV-Versicherung Versicherungsschein')
        compare.assert_not_called()
        self.assertEqual(result['document_type'], '3')
        self.assertEqual(result['partner'], 'SV')
        self.assertEqual(result['autokat_sources']['document_type']['type'], 'K')
        self.assertEqual(result['autokat_sources']['partner']['type'], 'E')
        self.assertTrue(result['autokat']['mixed_rule_sources'])
        self.assertEqual(len(result['autokat']['matched_classification_rule_refs']), 2)


if __name__ == '__main__':
    unittest.main()
