"""Bounded thinking fallback; no real Ollama or long waits required."""
from contextlib import nullcontext
import hashlib
import json
import socket
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch
from app.services import ollama


class FallbackTests(unittest.TestCase):
    def setUp(self):
        self.context = dict(url='http://192.168.1.2:11434', model='qwen3.5:9b', thinking=True,
                            categories={'1': 'Haus'}, types={'2': 'Rechnung'},
                            text='Rechnung Nr. A123 vom 11.10.2026', revision='revision')
        self.proposal = dict(date='2026-10-11', partner='Händler', subject='Rechnung',
                             category='Haus', document_type='Rechnung', number='A123', tags='',
                             date_evidence='11.10.2026', number_evidence='Nr. A123')
        self.success = dict(done=True, done_reason='stop', message={'content': json.dumps(self.proposal)})
        self.length = dict(done=True, done_reason='length', eval_count=8192,
                           message={'content': '{', 'thinking': 'PRIVATE THOUGHTS'})
        self.identity = ('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())

    def run_request(self, responses, context=None, contexts=None):
        with patch.object(ollama, 'generation_lock', return_value=nullcontext(True)), \
             patch.object(ollama, 'check_model'), \
             patch.object(ollama, 'load_context', return_value=context or self.context, side_effect=contexts), \
             patch.object(ollama, 'request_json', side_effect=responses) as network:
            try:
                result = ollama.suggest(*self.identity, {'partner': 'Vorbelegt'})
            except ollama.OllamaError as exc:
                return exc, network
            return result, network

    def test_length_falls_back_once_preserving_prompt_and_schema(self):
        result, network = self.run_request([self.length, self.success])
        self.assertEqual(network.call_count, 2)
        first, second = [call.args[2] for call in network.call_args_list]
        self.assertTrue(first['think']); self.assertFalse(second['think'])
        self.assertEqual(first['options']['num_predict'], 8192)
        self.assertEqual(second['options']['num_predict'], 1500)
        self.assertEqual(first['messages'], second['messages'])
        self.assertEqual(first['format'], second['format'])
        self.assertEqual(json.loads(second['messages'][1]['content'])['current_fields']['partner'], 'Vorbelegt')
        self.assertTrue(any('ohne Denkmodus' in warning for warning in result['warnings']))
        self.assertFalse(result['diagnostic']['request']['think'])
        self.assertEqual(json.loads(result['diagnostic']['body']), second)
        self.assertEqual(result['diagnostic']['attempts'][0]['abort_reason'], 'length')
        self.assertNotIn('PRIVATE THOUGHTS', json.dumps(result['diagnostic']))

    def test_complete_response_and_empty_fields_do_not_trigger_fallback(self):
        response = {**self.success, 'message': {'content': json.dumps({**self.proposal, 'partner': ''})}}
        result, network = self.run_request([response])
        self.assertEqual(result['proposal']['partner'], '')
        self.assertEqual(network.call_count, 1)
        self.assertEqual(result['warnings'], [])

    def test_two_aborts_are_terminal_and_no_third_attempt_is_sent(self):
        error, network = self.run_request([self.length, self.length])
        self.assertIsInstance(error, ollama.OllamaGenerationError)
        self.assertFalse(error.retryable)
        self.assertEqual(network.call_count, 2)
        self.assertEqual(len(error.diagnostic['attempts']), 2)

    def test_disabled_thinking_has_only_one_attempt(self):
        error, network = self.run_request([self.length], {**self.context, 'thinking': False})
        self.assertFalse(error.retryable)
        self.assertEqual(network.call_count, 1)

    def test_hard_generation_timeout_never_starts_an_unconfirmed_second_request(self):
        error, network = self.run_request([ollama.OllamaTimeout('Zeitlimit', generation_started=True)])
        self.assertFalse(error.retryable)
        self.assertIn('nicht bestätigt', str(error))
        self.assertEqual(network.call_count, 1)

    def test_first_and_fallback_timeouts_have_distinct_errors_and_history(self):
        from app.services.ollama_history import describe_attempts
        for responses, phrase, attempts in [
                ([ollama.OllamaTimeout('Zeitlimit', True)], 'Fallback nicht gestartet', 1),
                ([self.length, ollama.OllamaTimeout('Zeitlimit', True)], 'Fallback wurde ebenfalls abgebrochen', 2)]:
            with self.subTest(attempts=attempts):
                error, network = self.run_request(responses)
                self.assertIn(phrase, str(error))
                self.assertEqual(network.call_count, attempts)
                records = error.diagnostic['attempts']
                self.assertEqual(len(records), attempts)
                self.assertEqual(records[-1]['status'], 'timeout')
                self.assertFalse(records[-1]['end_confirmed'])
                self.assertGreaterEqual(records[-1]['elapsed_ms'], 0)
                summary = describe_attempts(records)['summary']
                self.assertIn('nicht gestartet' if attempts == 1 else 'gestartet, aber fehlgeschlagen', summary)

    def test_connection_failures_remain_retryable_and_have_no_fallback(self):
        for failure in [ollama.OllamaError('Nicht erreichbar'), ollama.OllamaTimeout('Verbindungsaufbau')]:
            with self.subTest(failure=str(failure)):
                error, network = self.run_request([failure])
                self.assertTrue(getattr(error, 'retryable', True))
                self.assertEqual(error.diagnostic['attempts'][0]['status'], 'connection_error')
                self.assertEqual(network.call_count, 1)

    def test_transport_distinguishes_connect_timeout_from_dispatched_generation(self):
        for stage, started in [('connect', False), ('getresponse', True)]:
            with self.subTest(stage=stage), patch('http.client.HTTPConnection') as transport:
                getattr(transport.return_value, stage).side_effect = socket.timeout()
                with self.assertRaises(ollama.OllamaTimeout) as caught:
                    ollama.request_json(self.context['url'], '/api/chat', {'think': True})
                self.assertEqual(caught.exception.generation_started, started)
                transport.return_value.close.assert_called_once()

    def test_incomplete_unacknowledged_response_and_invalid_json_have_no_fallback(self):
        for response in [dict(done=False, done_reason='length'),
                         dict(done=True, message={'content': 'invalid'})]:
            error, network = self.run_request([response])
            self.assertIsInstance(error, ollama.OllamaError)
            self.assertFalse(error.retryable)
            self.assertEqual(network.call_count, 1)

    def test_changed_document_prevents_fallback(self):
        error, network = self.run_request([self.length], contexts=[self.context, {**self.context, 'revision': 'changed'}])
        self.assertIn('zwischenzeitlich', str(error))
        self.assertEqual(network.call_count, 1)

    def test_fallback_connection_failure_is_terminal(self):
        error, network = self.run_request([self.length, ollama.OllamaError('Nicht erreichbar')])
        self.assertFalse(error.retryable)
        self.assertEqual(network.call_count, 2)

    def test_soft_timeout_falls_back_only_after_done_with_local_http_server(self):
        requests = []
        case = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_): pass
            def do_POST(self):
                payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                requests.append(payload)
                if payload['think']:
                    time.sleep(0.12)
                self.send_response(200); self.end_headers()
                self.wfile.write(json.dumps(case.success).encode())
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        self.addCleanup(lambda: (server.shutdown(), server.server_close(), thread.join(2)))
        context = {**self.context}
        payload = dict(model=context['model'], think=True, stream=False, options={'num_predict':8192})
        with patch.object(ollama, 'validate_url', return_value=('127.0.0.1', server.server_port)), \
             patch.object(ollama, 'GENERATION_TIMEOUT', 0.08), patch.object(ollama, 'COMPLETION_GRACE', 0.3):
            diagnostic = {}
            response, fallback = ollama.generate(context, payload, diagnostic, lambda: None)
        self.assertTrue(fallback)
        self.assertEqual(response, self.success)
        self.assertEqual([request['think'] for request in requests], [True, False])
        self.assertEqual(diagnostic['attempts'][0]['abort_reason'], 'timeout')


if __name__ == '__main__': unittest.main()
