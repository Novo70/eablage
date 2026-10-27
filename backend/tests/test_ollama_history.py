import json
import unittest
from app.services.ollama_history import safe_attempts, describe_attempts


class HistoryTests(unittest.TestCase):
    def test_legacy_unknown_and_not_started_are_distinct(self):
        for raw in [None, 'null', 'not JSON', {}, [{'thinking': 'PRIVATE THOUGHTS'}]]:
            self.assertIsNone(safe_attempts(raw))
            self.assertIn('Keine Durchlaufhistorie', describe_attempts(raw)['summary'])
        self.assertEqual(describe_attempts([]), {'summary': 'Kein KI-Durchlauf gestartet.', 'lines': []})

    def test_only_bounded_numeric_metadata_and_known_labels_are_retained(self):
        raw = [{'thinking': True, 'status': 'timeout', 'end_confirmed': False, 'elapsed_ms': 150001,
                'document_text': 'PRIVATE DOCUMENT', 'error': 'PRIVATE ERROR', 'request': 'PRIVATE REQUEST',
                'done_reason': 'PRIVATE OUTPUT', 'metrics': {'eval_count': 8192, 'prompt_eval_count': True,
                'total_duration': -1, 'load_duration': 2**54, 'other': 'PRIVATE METRICS'}}]
        result = safe_attempts(json.dumps(raw))
        self.assertEqual(result, [{'thinking': True, 'status': 'timeout', 'end_confirmed': False,
                                   'elapsed_ms': 150001, 'metrics': {'eval_count': 8192}}])
        self.assertNotIn('PRIVATE', json.dumps(result))
        self.assertEqual(len(safe_attempts(raw * 3)), 2)
        self.assertIn('150,0 s', describe_attempts(raw)['lines'][0])

    def test_first_timeout_and_failed_fallback_are_unambiguous(self):
        first = {'thinking': True, 'status': 'timeout', 'end_confirmed': False}
        second = {'thinking': False, 'status': 'timeout', 'end_confirmed': False}
        self.assertIn('Fallback nicht gestartet', describe_attempts([first])['summary'])
        history = describe_attempts([first, second])
        self.assertIn('gestartet, aber fehlgeschlagen', history['summary'])
        self.assertIn('Erstversuch mit Denkmodus', history['lines'][0])
        self.assertIn('Fallback ohne Denkmodus', history['lines'][1])

    def test_successful_fallback_and_disabled_thinking_are_distinct(self):
        first = {'thinking': True, 'status': 'token_limit', 'end_confirmed': True}
        second = {'thinking': False, 'status': 'complete', 'end_confirmed': True}
        self.assertEqual(describe_attempts([first, second])['summary'], 'Fallback ohne Denkmodus erfolgreich.')
        self.assertIn('kein Fallback vorgesehen', describe_attempts([second])['summary'])
        self.assertIn('Kein Fallback erforderlich', describe_attempts([{**first, 'status':'complete'}])['summary'])
