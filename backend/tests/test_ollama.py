from contextlib import nullcontext
import copy
import hashlib
import json
from pathlib import Path
import socket
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch, MagicMock

from fastapi import FastAPI
from fastapi.testclient import TestClient
from app.ollama_api import router
from app.services import ollama


class ProposalTests(unittest.TestCase):
    def setUp(self):
        lane = patch.object(ollama, 'generation_lock', side_effect=lambda *_: nullcontext(True))
        lane.start(); self.addCleanup(lane.stop)
        self.context = dict(url='http://192.168.1.2:11434', model='qwen3.5:9b',
                            categories={'001': 'Versicherung'}, types={'002': 'Rechnung'},
                            text='Rechnung Nr. AB-123 vom 11.10.2026', revision='revision')
        self.proposal = dict(date='2026-10-11', partner='Versicherung', subject='Rechnung',
                             category='001', document_type='002', number='AB-123', tags='',
                             date_evidence='vom 11.10.2026', number_evidence='Nr. AB-123')
        self.model_proposal = {**self.proposal, 'category': 'Versicherung', 'document_type': 'Rechnung'}

    def test_url_only_accepts_literal_lan_addresses(self):
        for url in ['http://192.168.1.2:11434', 'http://10.0.0.1', 'http://[fd00::1]:11434/']:
            ollama.validate_url(url)
        for url in ['', 'http://example.com', 'http://127.0.0.1', 'http://169.254.169.254',
                    'http://8.8.8.8', 'file:///tmp/doc', 'http://192.168.0.1/api',
                    'http://user@192.168.0.1', 'http://192.168.0.1?x=1',
                    'http://192.168.0.1:0', 'http://192.168.0.1:99999', 'http://[::1]']:
            with self.subTest(url=url), self.assertRaises(ollama.OllamaError):
                ollama.validate_url(url)

    def test_validation_keeps_exact_ids_and_rejects_bad_fields(self):
        actual, warnings = ollama.validate_proposal(copy.deepcopy(self.proposal), self.context, self.context['text'])
        self.assertEqual(actual, self.proposal)
        self.assertEqual(warnings, [])
        for values in [{'date': '2026-02-30'}, {'category': '999'}, {'document_type': '2'},
                       {'number_evidence': 'Invented'}, {'date_evidence': ''}]:
            actual, warnings = ollama.validate_proposal({**self.proposal, **values}, self.context, self.context['text'])
            self.assertTrue(warnings)
        for values in [{'subject': '<script>\n'}, {'number': 'x'*51}, {'partner': []}, {'extra': 'value'}]:
            with self.assertRaises(ollama.OllamaError):
                ollama.validate_proposal({**self.proposal, **values}, self.context, self.context['text'])

    def test_tags_remove_metadata_generic_terms_duplicates_and_limit_count(self):
        proposal = {**self.proposal, 'tags': 'Versicherung, Rechnung, Wärmepumpe, wärmepumpe, Förderantrag; Dokument, Stadtwerke, Förderung, Heizung, Sanierung, Energie, Extras'}
        actual, _ = ollama.validate_proposal(proposal, self.context, self.context['text'], {'partner': 'Stadtwerke'})
        self.assertEqual(actual['tags'], 'Wärmepumpe, Förderantrag, Förderung, Heizung, Sanierung')
        self.assertEqual(ollama.clean_tags('Haus, Haushalt, Straße, STRASSE', ['Haus']), 'Haushalt, Straße')

    def test_current_fields_are_validated_and_used_in_prompt(self):
        response = {'done': True, 'message': {'content': json.dumps({**self.model_proposal, 'tags': 'Stadtwerke, Wärmepumpe'})}}
        current = {'partner': 'Stadtwerke', 'category2': '001'}
        with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
            result = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest(), current)
        self.assertEqual(result['proposal']['tags'], 'Wärmepumpe')
        prompt = json.loads(request.call_args.args[2]['messages'][1]['content'])
        self.assertEqual(prompt['current_fields'], {'partner': 'Stadtwerke', 'category2': 'Versicherung'})
        self.assertNotIn('current_category_labels', prompt)
        for invalid in [{'evil': 'x'}, {'partner': 'x'*256}, {'partner': []}, {'tags': 'x\n'}]:
            with self.subTest(invalid=invalid), self.assertRaises(ollama.OllamaError):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, 'b'*64, invalid)

    def test_model_names_resolve_to_exact_ids_and_long_names_are_allowed(self):
        context = {**self.context, 'categories': {'00064': 'Haus und Garten mit langem Namen'},
                   'types': {'00010': 'Rechnung über Anschaffungen'}}
        data = {**self.model_proposal, 'category': context['categories']['00064'],
                'document_type': context['types']['00010']}
        resolved, warnings = ollama.resolve_proposal_names(data, context)
        actual, _ = ollama.validate_proposal(resolved, context, context['text'])
        self.assertEqual((actual['category'], actual['document_type']), ('00064', '00010'))
        self.assertEqual(warnings, [])
        self.assertEqual(data['category'], context['categories']['00064'])
        self.assertEqual(ollama.response_schema(context)['properties']['category']['maxLength'], 255)

    def test_unknown_names_ids_and_wrong_case_are_discarded_without_guessing(self):
        for name in ['001', 'versicherung', 'Erfundene Kategorie']:
            actual, warnings = ollama.resolve_proposal_names({**self.model_proposal, 'category': name}, self.context)
            self.assertEqual(actual['category'], '')
            self.assertEqual(actual['document_type'], '002')
            self.assertTrue(warnings)
        actual, warnings = ollama.resolve_proposal_names({**self.model_proposal, 'category': '', 'document_type': ''}, self.context)
        self.assertEqual((actual['category'], actual['document_type']), ('', ''))
        self.assertEqual(warnings, [])
        for invalid in [None, 1, [], 'x'*256, 'Versicherung\n']:
            with self.subTest(invalid=invalid), self.assertRaises(ollama.OllamaError):
                ollama.resolve_proposal_names({**self.model_proposal, 'category': invalid}, self.context)
        for names in [{'1': 'Haus', '2': 'Haus'}, {'1': ''}, {'1': ' Haus'}]:
            with self.assertRaisesRegex(ollama.OllamaError, 'eindeutig'):
                ollama.response_schema({**self.context, 'categories': names})

    def test_request_uses_names_everywhere_without_duplicate_schema(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        current = {'category': '001', 'category2': '001', 'document_type': '002'}
        with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
            result = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest(), current)
        payload = request.call_args.args[2]
        user = json.loads(payload['messages'][1]['content'])
        self.assertEqual(user['categories'], ['Versicherung'])
        self.assertEqual(user['document_types'], ['Rechnung'])
        self.assertEqual(user['current_fields'], {'category': 'Versicherung', 'category2': 'Versicherung', 'document_type': 'Rechnung'})
        self.assertNotIn('001', json.dumps(payload))
        self.assertNotIn('002', json.dumps(payload))
        self.assertNotIn('properties', payload['messages'][0]['content'])
        self.assertEqual(result['proposal'], self.proposal)
        self.assertEqual(current['category'], '001')

    def test_model_missing_and_cloud_fail_before_document_request(self):
        for responses in [[{'models': []}], [{'models': [{'name': 'qwen3.5:9b'}]}, {'remote_host': 'ollama.com'}]]:
            with patch.object(ollama, 'request_json', side_effect=responses), self.assertRaises(ollama.OllamaError):
                ollama.check_model(self.context)

    def test_suggestion_is_read_only_and_uses_structured_output(self):
        response = {'done': True, 'done_reason': 'stop', 'message': {'content': json.dumps(self.model_proposal)}}
        with patch.object(ollama, 'load_context', return_value=self.context) as load, \
             patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
            result = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())
        self.assertEqual(result['proposal'], self.proposal)
        self.assertEqual(load.call_count, 2)
        payload = request.call_args.args[2]
        self.assertFalse(payload['stream'])
        self.assertFalse(payload['think'])
        self.assertEqual(payload['format']['properties']['category']['enum'], ['', 'Versicherung'])
        self.assertNotIn('tools', payload)

    def test_thinking_setting_controls_request_and_diagnostic(self):
        response = {'done': True, 'done_reason': 'stop', 'message': {'content': json.dumps(self.model_proposal)}}
        for thinking, budget in [(False, 1500), (True, 8192)]:
            context = {**self.context, 'thinking': thinking}
            with self.subTest(thinking=thinking), patch.object(ollama, 'load_context', return_value=context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
                result = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(context['text'].encode()).hexdigest())
            payload = request.call_args.args[2]
            self.assertEqual(payload['think'], thinking)
            self.assertEqual(payload['options']['num_predict'], budget)
            self.assertEqual(result['diagnostic']['request'], payload)
            self.assertEqual(result['proposal'], self.proposal)
            self.assertEqual(request.call_args.args[3], 150 if thinking else 120)

    def test_thinking_change_during_generation_rejects_old_result(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        for before, after in [(False, True), (True, False)]:
            with self.subTest(before=before), patch.object(ollama, 'load_context', side_effect=[{**self.context, 'thinking': before}, {**self.context, 'thinking': after}]), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response):
                with self.assertRaisesRegex(ollama.OllamaError, 'KI-Einstellungen'):
                    ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())

    def test_thinking_reload_missing_default_and_invalid_setting(self):
        connection = MagicMock(); cursor = connection.cursor.return_value
        cursor.fetchone.return_value = {'name': 'db'}
        for value, expected in [(None, False), ('nein', False), ('ja', True), ('invalid', None), ('', None)]:
            values = {'ollamaAktiv': 'ja', 'ollamaURL': self.context['url'], 'ollamaModell': self.context['model']}
            if value is not None:
                values['ollamaDenkmodus'] = value
            cursor.fetchall.return_value = [{'param_key': k, 'param_value': v} for k, v in values.items()]
            with self.subTest(value=value), patch.object(ollama, 'get_connection', return_value=connection):
                if expected is None:
                    with self.assertRaisesRegex(ollama.OllamaError, 'Denkmodus'):
                        ollama.load_context('db')
                else:
                    self.assertEqual(ollama.load_context('db')['thinking'], expected)

    def test_explanations_reach_prompt_without_category2_proposal(self):
        context = {**self.context, 'categories_explanations': {'001': 'Haus einschließlich Gebäudeversicherung'}, 'types_explanations': {'002': 'Zahlungsaufforderung'}}
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        with patch.object(ollama, 'load_context', return_value=context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
            ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(context['text'].encode()).hexdigest())
        payload = request.call_args.args[2]
        prompt = json.loads(payload['messages'][1]['content'])
        self.assertEqual(prompt['categories'], {'Versicherung': 'Haus einschließlich Gebäudeversicherung'})
        self.assertEqual(prompt['document_types']['Rechnung'], 'Zahlungsaufforderung')
        self.assertNotIn('category2', payload['format']['properties'])
        self.assertEqual(ollama.response_schema(self.context)['properties']['category']['enum'], ['', 'Versicherung'])

    def test_explanation_change_during_generation_rejects_old_result(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        latest = {**self.context, 'categories_explanations': {'001': 'Geänderte Abgrenzung'}}
        with patch.object(ollama, 'load_context', side_effect=[self.context, latest]), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response):
            with self.assertRaisesRegex(ollama.OllamaError, 'Stammdaten'):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())

    def test_load_context_reads_active_explanations(self):
        connection = MagicMock(); cursor = connection.cursor.return_value
        cursor.fetchone.side_effect = [{'name': 'db'}, {'id': 1, 'sha256': 'a'*64, 'status': 'bereit', 'ergebnis': json.dumps({'pdf': {'text': self.context['text']}})}]
        cursor.fetchall.side_effect = [[{'param_key': k, 'param_value': v} for k, v in {'ollamaAktiv': 'ja', 'ollamaURL': self.context['url'], 'ollamaModell': self.context['model']}.items()], [{'id': '001', 'name': 'Haus', 'explanation': 'Gebäudeversicherung'}, {'id': '0', 'name': 'Keine', 'explanation': 'Nicht verwenden'}], [{'id': '002', 'name': 'Rechnung', 'explanation': ''}], [{'id':'1','name':'Kundennummer','explanation':'Kundenkonto'}]]
        with patch.object(ollama, 'get_connection', return_value=connection), patch.object(ollama, 'file_path', return_value='scan.pdf'), patch.object(ollama, 'read_pdf', return_value=(b'pdf', 'a'*64)):
            context = ollama.load_context('db', 'scan.pdf', 'a'*64, 1)
        self.assertEqual(context['categories_explanations'], {'001': 'Gebäudeversicherung'})
        self.assertEqual(context['types_explanations'], {'002': ''})
        cursor.execute.assert_any_call("SELECT id, katBez AS name, COALESCE(Erlaeuterung, '') AS explanation FROM kategorie WHERE Aktiv=1 ORDER BY katBez,id")

    def test_sql_initial_instruction_matches_backend_default(self):
        root = Path(__file__).resolve().parents[2]
        quoted = ollama.DEFAULT_INSTRUCTION.replace("'", "''")
        for filename in ['sql/seed.sql', 'sql/update_ollama_instruction.sql']:
            self.assertIn("('ollamaAnweisung', '" + quoted + "'", (root / filename).read_text())

    def test_custom_instruction_is_passed_as_guidance_with_fixed_technical_rules(self):
        context = {**self.context, 'instruction': 'Ausschlüsse beachten. Versicherungen nicht Gesundheit zuordnen.'}
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        with patch.object(ollama, 'load_context', return_value=context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
            ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(context['text'].encode()).hexdigest())
        payload = request.call_args.args[2]
        self.assertEqual(json.loads(payload['messages'][1]['content'])['classification_guidance'], context['instruction'])
        self.assertIn('technischen Vorgaben haben Vorrang', payload['messages'][0]['content'])
        self.assertIn('Kategorie2 wird nicht vorgeschlagen', payload['messages'][0]['content'])
        self.assertIn('date_evidence', payload['format']['properties'])

    def test_instruction_change_while_generating_rejects_result(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        with patch.object(ollama, 'load_context', side_effect=[self.context, {**self.context, 'instruction': 'Neue Hinweise'}]), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response):
            with self.assertRaisesRegex(ollama.OllamaError, 'KI-Einstellungen'):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())

    def test_instruction_reload_and_blank_fallback(self):
        for instruction, expected in [(None, ollama.DEFAULT_INSTRUCTION), ('  ', ollama.DEFAULT_INSTRUCTION), ('Neue Hinweise\r\nZweite Zeile', 'Neue Hinweise\nZweite Zeile')]:
            connection = MagicMock(); cursor = connection.cursor.return_value
            cursor.fetchone.return_value = {'name': 'db'}
            values = {'ollamaAktiv': 'ja', 'ollamaURL': self.context['url'], 'ollamaModell': self.context['model']}
            if instruction is not None: values['ollamaAnweisung'] = instruction
            cursor.fetchall.return_value = [{'param_key': k, 'param_value': v} for k, v in values.items()]
            with patch.object(ollama, 'get_connection', return_value=connection):
                self.assertEqual(ollama.load_context('db')['instruction'], expected)
        values['ollamaAnweisung'] = 'x'*4001
        cursor.fetchall.return_value = [{'param_key': k, 'param_value': v} for k, v in values.items()]
        with patch.object(ollama, 'get_connection', return_value=connection), self.assertRaisesRegex(ollama.OllamaError, '4000'):
            ollama.load_context('db')

    def test_response_metrics_survive_success_and_invalid_model_answers(self):
        metrics = dict(total_duration=25000000000, load_duration=0, prompt_eval_duration=3000000000,
                       eval_duration=22000000000, prompt_eval_count=1800, prompt_eval_cached_count=1200, eval_count=1100)
        for done, reason, content in [(True, 'stop', json.dumps(self.model_proposal)), (True, 'length', '{}'), (True, 'stop', 'invalid JSON')]:
            response = {**metrics, 'done': done, 'done_reason': reason, 'message': {'content': content, 'thinking': 'private reasoning'}}
            with self.subTest(reason=reason, content=content), patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
                try:
                    diagnostic = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())['diagnostic']
                except ollama.OllamaError as exc:
                    diagnostic = exc.diagnostic
                self.assertEqual(diagnostic['metrics'], metrics)
                self.assertEqual(diagnostic['done_reason'], reason)
                self.assertEqual(diagnostic['request'], request.call_args.args[2])
                self.assertNotIn('private reasoning', json.dumps(diagnostic))
                self.assertNotIn('message', diagnostic)
                self.assertEqual(diagnostic['request']['options']['temperature'], 0)

    def test_missing_or_invalid_metrics_are_not_invented_or_forwarded(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}, 'total_duration': -1,
                    'load_duration': 'bad', 'eval_count': True, 'prompt_eval_count': 2**53, 'eval_duration': 1.5}
        with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response):
            diagnostic = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())['diagnostic']
        self.assertEqual(diagnostic['metrics'], {})
        self.assertIsNone(diagnostic['done_reason'])

    def test_diagnostic_is_the_exact_transported_request(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        context = {**self.context, 'instruction': 'Ausschlüsse beachten', 'categories_explanations': {'001': 'Versicherungsunterlagen'}}
        fields = {'partner': 'Vorheriger Wert'}
        with patch.object(ollama, 'load_context', return_value=context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as request:
            result = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(context['text'].encode()).hexdigest(), fields)
        diagnostic = result['diagnostic']
        self.assertEqual(diagnostic['request'], request.call_args.args[2])
        self.assertEqual(diagnostic['body'], json.dumps(request.call_args.args[2], ensure_ascii=False))
        self.assertEqual(diagnostic['url'], 'http://192.168.1.2:11434/api/chat')
        self.assertTrue(diagnostic['response_received'])
        user = json.loads(diagnostic['request']['messages'][1]['content'])
        self.assertEqual(user['current_fields'], fields)
        self.assertEqual(user['document_text'], context['text'])
        self.assertNotIn('aktion_token', diagnostic['body'])
        self.assertNotIn('database', diagnostic['request'])

    def test_failed_generation_retains_request_snapshot(self):
        for answer in [ollama.OllamaError('Nicht erreichbar'), {'done': True, 'done_reason': 'length'}]:
            with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json') as request:
                if isinstance(answer, Exception): request.side_effect = answer
                else: request.return_value = answer
                with self.assertRaises(ollama.OllamaError) as failure:
                    ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())
                self.assertEqual(failure.exception.diagnostic['request'], request.call_args.args[2])
                self.assertEqual(failure.exception.diagnostic['response_received'], not isinstance(answer, Exception))

    def test_changed_document_or_incomplete_response_is_rejected(self):
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        for newer in [{**self.context, 'revision': 'new'}, {**self.context, 'url': 'http://10.0.0.2'}]:
            with patch.object(ollama, 'load_context', side_effect=[self.context, newer]), \
                 patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response), \
                 self.assertRaises(ollama.OllamaError):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())
        for bad in [{'done': False}, {'done': True, 'done_reason': 'length'}, {'done': True, 'message': {'content': 'invalid'}}]:
            with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), \
                 patch.object(ollama, 'request_json', return_value=bad), self.assertRaises(ollama.OllamaError):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())

    def test_stale_display_text_is_rejected_before_network_call(self):
        with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model') as check:
            with self.assertRaisesRegex(ollama.OllamaError, 'angezeigte Dokumenttext'):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, 'b'*64)
            check.assert_not_called()

    def test_text_selection_boundaries_and_zero_tail_never_duplicate_text(self):
        self.assertEqual(ollama.text_limits({}), (9000,3000))
        self.assertEqual(ollama.text_limits({'ollamaTextEnde':'0'}), (9000,0))
        for length in [9,10]:
            text='ä'*length
            self.assertEqual(ollama.select_text(text,7,3),(text,False))
        selected, truncated=ollama.select_text('ABCDEFGHIJK',7,3)
        self.assertTrue(truncated); self.assertTrue(selected.startswith('ABCDEFG')); self.assertTrue(selected.endswith('IJK'))
        selected, truncated=ollama.select_text('ABCDEFGHIJK',7,0)
        self.assertTrue(truncated); self.assertTrue(selected.startswith('ABCDEFG')); self.assertNotIn('HIJK',selected)
        self.assertEqual(ollama.select_text('ABCDEFG',7,0),('ABCDEFG',False))
        for values in [{'ollamaTextAnfang':'0'}, {'ollamaTextAnfang':'12000'}, {'ollamaTextEnde':'-1'},
                       {'ollamaTextEnde':'1.5'}, {'ollamaTextAnfang':None}, {'ollamaTextEnde':'99999'}]:
            with self.subTest(values=values), self.assertRaises(ollama.OllamaError): ollama.text_limits(values)

    def test_configured_text_window_reaches_transport_warning_and_diagnostic(self):
        context={**self.context,'text':self.context['text']+'x'*20000+'UNIQUE END','text_head':9000,'text_tail':0}
        response={'done':True,'message':{'content':json.dumps(self.model_proposal)}}
        with patch.object(ollama,'load_context',return_value=context), patch.object(ollama,'check_model'), patch.object(ollama,'request_json',return_value=response) as request:
            result=ollama.suggest('db','scan.pdf','a'*64,1,hashlib.sha256(context['text'].encode()).hexdigest())
        sent=json.loads(request.call_args.args[2]['messages'][1]['content'])['document_text']
        self.assertTrue(sent.startswith(context['text'][:9000]))
        self.assertNotIn('UNIQUE END',sent)
        self.assertEqual(result['diagnostic']['text_selection']['tail'],0)
        self.assertEqual(result['diagnostic']['text_selection']['selected_characters'],9000)
        self.assertIn('ersten 9.000 Zeichen',result['warnings'][0])
        self.assertNotIn('letzten',result['warnings'][0])

    def test_text_window_change_during_generation_rejects_old_answer(self):
        response={'done':True,'message':{'content':json.dumps(self.model_proposal)}}
        with patch.object(ollama,'load_context',side_effect=[self.context,{**self.context,'text_tail':0}]), patch.object(ollama,'check_model'), patch.object(ollama,'request_json',return_value=response):
            with self.assertRaisesRegex(ollama.OllamaError,'KI-Einstellungen'):
                ollama.suggest('db','scan.pdf','a'*64,1,hashlib.sha256(self.context['text'].encode()).hexdigest())

    def test_load_context_reads_configured_text_limits(self):
        connection=MagicMock(); cursor=connection.cursor.return_value
        cursor.fetchone.return_value={'name':'db'}
        values={'ollamaAktiv':'ja','ollamaURL':self.context['url'],'ollamaModell':self.context['model'],
                'ollamaTextAnfang':'8000','ollamaTextEnde':'0'}
        cursor.fetchall.return_value=[{'param_key':k,'param_value':v} for k,v in values.items()]
        with patch.object(ollama,'get_connection',return_value=connection):
            context=ollama.load_context('db')
        self.assertEqual(ollama.text_window(context),(8000,0))

    def test_long_text_truncation_is_disclosed(self):
        context = {**self.context, 'text': self.context['text'] + 'x'*20000 + 'THE END'}
        response = {'done': True, 'message': {'content': json.dumps(self.model_proposal)}}
        with patch.object(ollama, 'load_context', return_value=context), patch.object(ollama, 'check_model'), \
             patch.object(ollama, 'request_json', return_value=response) as request:
            result = ollama.suggest('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(context['text'].encode()).hexdigest())
        self.assertTrue(result['warnings'])
        text = json.loads(request.call_args.args[2]['messages'][1]['content'])['document_text']
        self.assertLess(len(text), 12100)
        self.assertTrue(text.endswith('THE END'))

    def test_disabled_database_mismatch_and_stale_jobs_fail_closed(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value
        cursor.fetchone.return_value = {'name': 'other'}
        with patch.object(ollama, 'get_connection', return_value=connection), self.assertRaises(ollama.OllamaError):
            ollama.load_context('db')
        connection.close.assert_called()
        cursor.fetchone.return_value = {'name': 'db'}
        cursor.fetchall.return_value = []
        with patch.object(ollama, 'get_connection', return_value=connection), self.assertRaisesRegex(ollama.OllamaError, 'deaktiviert'):
            ollama.load_context('db')
        cursor.fetchall.return_value = [{'param_key': k, 'param_value': v} for k,v in
                                       {'ollamaAktiv': 'ja', 'ollamaURL': self.context['url'], 'ollamaModell': self.context['model']}.items()]
        for job in [None, {'id': 1, 'sha256': 'b'*64, 'status': 'bereit'}, {'id': 1, 'sha256': 'a'*64, 'status': 'archiviert'}]:
            cursor.fetchone.side_effect = [{'name': 'db'}, job]
            with patch.object(ollama, 'get_connection', return_value=connection), self.assertRaisesRegex(ollama.OllamaError, 'nicht mehr aktuell'):
                ollama.load_context('db', 'scan.pdf', 'a'*64, 1)


class TransportTests(unittest.TestCase):
    def setUp(self):
        self.status = 200
        self.body = b'{"models":[]}'
        self.requests = []
        case = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args): pass
            def do_GET(self): self.reply()
            def do_POST(self): self.reply()
            def reply(self):
                case.requests.append((self.command, self.path, self.rfile.read(int(self.headers.get('Content-Length', 0)))))
                self.send_response(case.status)
                self.send_header('Location', 'http://external.example/')
                self.send_header('Content-Length', str(len(case.body)))
                self.end_headers()
                self.wfile.write(case.body)
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.cleanup)
        self.address = patch.object(ollama, 'validate_url', return_value=('127.0.0.1', self.server.server_port))
        self.address.start()
        self.addCleanup(self.address.stop)

    def cleanup(self):
        self.server.shutdown(); self.server.server_close(); self.thread.join(2)

    def test_json_roundtrip_and_no_redirect_retry(self):
        self.assertEqual(ollama.request_json('test', '/api/tags'), {'models': []})
        ollama.request_json('test', '/api/chat', {'text': 'Äö'})
        self.assertEqual(json.loads(self.requests[-1][2]), {'text': 'Äö'})
        self.assertEqual(self.requests[-1][2].decode(), json.dumps({'text': 'Äö'}, ensure_ascii=False))
        self.status = 302
        with self.assertRaises(ollama.OllamaError): ollama.request_json('test', '/api/tags')
        self.assertEqual(len(self.requests), 3)

    def test_invalid_oversized_and_timeout(self):
        for body in [b'bad', b'[]', b'x'*(ollama.MAX_RESPONSE+1)]:
            self.body = body
            with self.assertRaises(ollama.OllamaError): ollama.request_json('test', '/api/tags')
        with patch('http.client.HTTPConnection.connect', side_effect=socket.timeout), self.assertRaisesRegex(ollama.OllamaError, 'Zeitlimit'):
            ollama.request_json('test', '/api/tags')


class ApiTests(unittest.TestCase):
    def test_validation_errors_and_sanitized_failures(self):
        app = FastAPI(); app.include_router(router)
        with TestClient(app) as client:
            self.assertEqual(client.post('/ollama/suggest', json={'database': 'db', 'filename': 'f', 'sha256': 'bad', 'job_id': 1}).status_code, 422)
            self.assertEqual(client.post('/ollama/check', json={'database': 'db', 'url': 'http://evil'}).status_code, 422)
            with patch.object(ollama, 'check_connection', side_effect=ollama.OllamaError('Nicht erreichbar')):
                self.assertEqual(client.post('/ollama/check', json={'database': 'db'}).json()['detail'], 'Nicht erreichbar')
            error = ollama.OllamaError('Modellantwort ungültig')
            error.diagnostic = {'body': '{\"model\": \"test\"}'}
            identity = {'database': 'db', 'filename': 'scan.pdf', 'sha256': 'a'*64, 'text_sha256': 'b'*64, 'job_id': 1}
            with patch.object(ollama, 'suggest', side_effect=error):
                failed = client.post('/ollama/suggest', json=identity)
                self.assertEqual(failed.status_code, 400)
                self.assertEqual(failed.json()['diagnostic'], error.diagnostic)
            with patch.object(ollama, 'check_connection', side_effect=Exception('secret document text')):
                response = client.post('/ollama/check', json={'database': 'db'})
                self.assertEqual(response.status_code, 500)
                self.assertNotIn('secret', response.text)


if __name__ == '__main__': unittest.main()

