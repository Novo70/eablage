import unittest
from unittest.mock import MagicMock, patch

from app.services.text_compare import analyze_text_compare


class TextCompareParameterTests(unittest.TestCase):
    def compare(self, threshold_key, threshold):
        connection = MagicMock()
        connection.cursor.return_value.fetchall.return_value = [
            {'param_key': threshold_key, 'param_value': threshold},
            {'param_key': 'MINLAENGEVERGL', 'param_value': '0'},
            {'param_key': 'txtLaengeVergl', 'param_value': '5000'},
        ]
        with patch('app.services.text_compare.get_connection', return_value=connection), patch(
            'app.services.text_compare._load_documents',
            return_value=[{'DokID': 41, 'Doktext': 'Rechnung Stromverbrauch'}],
        ):
            result = analyze_text_compare('Rechnung Stromverbrauch Abschlag')
        connection.close.assert_called_once()
        return result

    def test_seed_and_alternative_spellings_use_saved_threshold(self):
        for key in ('SchwUebereinst', 'schwUebereinst', 'SCHWUEBEREINST'):
            with self.subTest(key=key):
                result = self.compare(key, '55')
                self.assertEqual(result['similarity'], 67)
                self.assertTrue(result['matched'])
                self.assertEqual(result['source_document_id'], 41)

    def test_higher_saved_threshold_rejects_same_candidate(self):
        self.assertFalse(self.compare('SchwUebereinst', '80')['matched'])

    def test_invalid_threshold_uses_seventy_percent_fallback(self):
        self.assertFalse(self.compare('SchwUebereinst', 'invalid')['matched'])


if __name__ == '__main__':
    unittest.main()
