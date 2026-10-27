"""Background policy, cache freshness, exclusion of raw diagnostics and shared lane."""
from contextlib import nullcontext
import hashlib
import json
import threading
import unittest
from unittest.mock import MagicMock, patch
from app.services import ollama, ollama_background as bg


class BackgroundTests(unittest.TestCase):
    def setUp(self):
        self.raw = json.dumps({'pdf': {'text': 'Rechnung Nr. A123 vom 11.10.2026'},
                               'classification': {'partner': 'Auto Partner', 'category': '0'}})
        self.context = dict(source_sha256='a'*64, text='Rechnung Nr. A123 vom 11.10.2026', revision=bg.revision(self.raw),
                            url='http://192.168.1.2:11434', model='qwen3.5:9b', thinking=True,
                            categories={'64': 'Haus'}, types={'10': 'Rechnung'})
        self.identity = ('db', 'scan.pdf', 'a'*64, 1, hashlib.sha256(self.context['text'].encode()).hexdigest())
        self.proposal = {'model': 'qwen3.5:9b', 'proposal': dict(date='2026-10-11', partner='KI Partner', subject='Rechnung', category='64', document_type='10', number='A123', tags='', date_evidence='11.10.2026', number_evidence='Nr. A123'), 'warnings': [], 'labels': {'category': 'Haus', 'document_type': 'Rechnung'}, 'diagnostic': {'body': 'PRIVATE RAW DOCUMENT'}}
        limits = patch.object(ollama, 'load_text_settings', return_value=(9000,3000))
        limits.start(); self.addCleanup(limits.stop)
        self.connection = MagicMock(); self.cursor = self.connection.cursor.return_value
        self.row = dict(status='bereit', id=1, dateiname='scan.pdf', sha256='a'*64, ergebnis=self.raw)

    def test_policy_distinguishes_classification_supplements_and_textcomparison(self):
        cases = [({}, True), ({'autokat_ok': True, 'autokat': {'matched': True, 'matched_supplement_rule_refs': [{'regel_id': 1}]}, 'category': '64'}, True),
                 ({'autokat_ok': True, 'autokat': {'matched_classification_rule_ref': {'regel_id': 2}}}, False),
                 ({'autokat_ok': True, 'autokat': {'matched_classification_rule_refs': [{'regel_id': 2}]}}, False),
                 ({'textcompare_ok': True, 'textcompare': {'matched': True, 'category': '64'}}, False),
                 ({'textcompare_ok': True, 'textcompare': {'matched': True, 'category': '0'}}, True),
                 ({'textcompare_ok': False, 'textcompare': {'matched': True, 'category': '64'}}, True)]
        for classification, expected in cases:
            with self.subTest(classification=classification):
                self.assertEqual(bg.eligible(classification, 'fehlende'), expected)
                self.assertTrue(bg.eligible(classification, 'alle'))
                self.assertFalse(bg.eligible(classification, 'aus'))

    def test_mode_missing_disabled_and_bad_values(self):
        self.cursor.fetchone.return_value = {'name': 'db'}
        for values, expected in [({}, 'aus'), ({'ollamaAktiv': 'ja'}, 'aus'), ({'ollamaAktiv': 'nein', 'ollamaHintergrund': 'alle'}, 'aus'), ({'ollamaAktiv': 'ja', 'ollamaHintergrund': 'fehlende'}, 'fehlende')]:
            self.cursor.fetchall.return_value = [{'param_key': k, 'param_value': v} for k, v in values.items()]
            self.assertEqual(bg.mode(self.connection, 'db'), expected)
        self.cursor.fetchall.return_value = [{'param_key': 'ollamaHintergrund', 'param_value': 'invalid'}]
        with self.assertRaises(ollama.OllamaError): bg.mode(self.connection, 'db')
        self.cursor.fetchone.return_value = {'name': 'other'}
        with self.assertRaises(ollama.OllamaError): bg.mode(self.connection, 'db')

    def run_one(self, mode='alle', acquired=True, fail=None):
        self.cursor.fetchall.return_value = [self.row]
        self.cursor.fetchone.return_value = self.row
        with patch.object(bg, 'get_connection', return_value=self.connection), patch.object(bg, 'mode', return_value=mode), patch.object(ollama, 'generation_lock', return_value=nullcontext(acquired)), patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, '_suggest', return_value=self.proposal, side_effect=fail) as suggest:
            outcome = bg.run_one('db')
        return outcome, suggest

    def test_disabled_and_busy_never_generate(self):
        for mode, acquired in [('aus', True), ('alle', False)]:
            outcome, suggest = self.run_one(mode, acquired)
            self.assertEqual(outcome, 'idle'); suggest.assert_not_called()

    def test_cache_stores_only_validated_fields_and_is_revision_guarded(self):
        outcome, suggest = self.run_one()
        self.assertEqual(outcome, 'done')
        self.assertEqual(suggest.call_args.args[-1], {'partner': 'Auto Partner', 'category': '0'})
        sql, args = self.cursor.execute.call_args.args
        self.assertIn("v.status='bereit'", sql)
        self.assertIn('CAST(SHA2(v.ergebnis,256) AS BINARY)=CAST(%s AS BINARY)', sql)
        self.assertIn('CAST(v.sha256 AS BINARY)=CAST(%s AS BINARY)', sql)
        stored = json.loads(args[1])
        self.assertEqual(stored['proposal'], self.proposal['proposal'])
        self.assertNotIn('diagnostic', stored)
        self.assertNotIn('PRIVATE RAW DOCUMENT', args[1])
        self.assertEqual(args[-1], self.context['revision'])

    def test_worker_resolves_model_names_before_caching_and_read_uses_ids(self):
        response = {'done': True, 'message': {'content': json.dumps({**self.proposal['proposal'],
                    'category': 'Haus', 'document_type': 'Rechnung'})}}
        self.cursor.fetchall.return_value = [self.row]
        self.cursor.fetchone.return_value = self.row
        with patch.object(bg, 'get_connection', return_value=self.connection), patch.object(bg, 'mode', return_value='alle'), patch.object(ollama, 'generation_lock', return_value=nullcontext(True)), patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'check_model'), patch.object(ollama, 'request_json', return_value=response) as network:
            self.assertEqual(bg.run_one('db'), 'done')
        stored = json.loads(self.cursor.execute.call_args.args[1][1])
        self.assertEqual(stored['proposal'], self.proposal['proposal'])
        user = json.loads(network.call_args.args[2]['messages'][1]['content'])
        self.assertEqual(user['categories'], ['Haus'])
        self.assertNotIn('diagnostic', stored)
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'],
                      kontext=bg.context_signature(self.context), status='bereit', ergebnis=json.dumps(stored))
        result = self.read(cached)
        self.assertEqual(result['suggestion']['proposal']['category'], '64')
        self.assertEqual(result['suggestion']['labels']['category'], 'Haus')

    def test_id_prompt_cache_is_invalidated_when_read(self):
        keys = ('source_sha256', 'revision', 'url', 'model', 'thinking', 'instruction', 'categories', 'types',
                'categories_explanations', 'types_explanations')
        old_signature = bg.revision(json.dumps({k: self.context.get(k) for k in keys}, ensure_ascii=False, sort_keys=True))
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'],
                      kontext=old_signature, status='bereit')
        self.assertEqual(self.read(cached), {'status': 'wartend'})
        self.assertIn("status='veraltet'", self.cursor.execute.call_args.args[0])

    def test_failure_retains_normal_job_and_has_no_raw_diagnostic(self):
        outcome, _ = self.run_one(fail=ollama.OllamaError('Nicht erreichbar'))
        self.assertEqual(outcome, 'failed')
        _, args = self.cursor.execute.call_args.args
        self.assertEqual((args[0], args[2]), ('fehler', 'Nicht erreichbar'))
        self.assertEqual(json.loads(args[1]), {'retryable': True, 'attempts': []})
        self.assertFalse(any('SET v.' in call.args[0] for call in self.cursor.execute.call_args_list))

    def test_terminal_failure_is_stored_without_global_pause_or_raw_diagnostic(self):
        error = ollama.OllamaGenerationError('Beide Durchläufe gescheitert')
        error.diagnostic = {'body': 'PRIVATE REQUEST'}
        outcome, _ = self.run_one(fail=error)
        self.assertEqual(outcome, 'stopped')
        _, args = self.cursor.execute.call_args.args
        self.assertEqual(args[0], 'fehler')
        self.assertEqual(json.loads(args[1]), {'retryable': False, 'attempts': []})
        self.assertEqual(args[3], bg.context_signature(self.context))
        self.assertNotIn('PRIVATE REQUEST', str(args))

    def test_failed_history_survives_cache_and_read_without_private_request_data(self):
        error = ollama.OllamaGenerationError('Fallback fehlgeschlagen')
        error.diagnostic = {'body':'PRIVATE REQUEST', 'attempts':[
            {'thinking':True, 'status':'token_limit', 'end_confirmed':True, 'elapsed_ms':50000,
             'content':'PRIVATE DOCUMENT'},
            {'thinking':False, 'status':'timeout', 'end_confirmed':False, 'elapsed_ms':120000,
             'error':'PRIVATE ERROR'}]}
        outcome, _ = self.run_one(fail=error)
        self.assertEqual(outcome, 'stopped')
        stored = self.cursor.execute.call_args.args[1][1]
        self.assertNotIn('PRIVATE', stored)
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'],
                      kontext=bg.context_signature(self.context), status='fehler', ergebnis=stored, fehler=str(error))
        result = self.read(cached)
        self.assertFalse(result['retryable'])
        self.assertIn('gestartet, aber fehlgeschlagen', result['attempt_history']['summary'])
        self.assertEqual(len(result['attempt_history']['lines']), 2)

    def test_success_history_survives_cache_without_retaining_diagnostics(self):
        self.proposal['attempts'] = [{'thinking':True, 'status':'token_limit', 'end_confirmed':True},
                                    {'thinking':False, 'status':'complete', 'end_confirmed':True,
                                     'request':'PRIVATE REQUEST'}]
        self.assertEqual(self.run_one()[0], 'done')
        stored = self.cursor.execute.call_args.args[1][1]
        self.assertNotIn('PRIVATE', stored)
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'],
                      kontext=bg.context_signature(self.context), status='bereit', ergebnis=stored)
        result = self.read(cached)
        self.assertIn('erfolgreich', result['suggestion']['attempt_history']['summary'])
        self.assertNotIn('diagnostic', result['suggestion'])

    def test_terminal_failure_is_not_generated_again_with_unchanged_context(self):
        self.row.update(ki_ergebnis=json.dumps({'retryable': False}),
                        ki_kontext=bg.context_signature(self.context))
        outcome, network = self.run_one()
        self.assertEqual(outcome, 'idle')
        network.assert_not_called()
        self.assertFalse(any('INSERT INTO ki_vorschlaege' in call.args[0] for call in self.cursor.execute.call_args_list))

    def test_changed_settings_allow_new_attempt_after_terminal_failure(self):
        self.row.update(ki_ergebnis=json.dumps({'retryable': False}), ki_kontext='old-context')
        outcome, network = self.run_one()
        self.assertEqual(outcome, 'done')
        network.assert_called_once()

    def test_terminal_read_stops_polling_but_changed_settings_schedule_refresh(self):
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'],
                      kontext=bg.context_signature(self.context), status='fehler',
                      ergebnis=json.dumps({'retryable': False}), fehler='Beide Durchläufe gescheitert')
        result = self.read(cached)
        self.assertEqual((result['status'], result['error'], result['retryable']), ('fehler', cached['fehler'], False))
        self.assertIn('Keine Durchlaufhistorie', result['attempt_history']['summary'])
        result = self.read(cached, {**self.context, 'model': 'other-local-model'})
        self.assertEqual(result, {'status': 'wartend'})
        self.assertIn("status='veraltet'", self.cursor.execute.call_args.args[0])

    def test_changed_background_mode_discards_generated_result(self):
        self.cursor.fetchall.return_value = [self.row]
        self.cursor.fetchone.return_value = self.row
        with patch.object(bg, 'get_connection', return_value=self.connection), patch.object(bg, 'mode', side_effect=['alle', 'aus']), patch.object(ollama, 'generation_lock', return_value=nullcontext(True)), patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, '_suggest', return_value=self.proposal):
            self.assertEqual(bg.run_one('db'), 'failed')
        self.assertTrue(json.loads(self.cursor.execute.call_args.args[1][1])['retryable'])

    def read(self, cached, context=None):
        self.cursor.fetchone.side_effect = [{**self.row, 'status': 'bereit'}, cached]
        with patch.object(bg, 'get_connection', return_value=self.connection), patch.object(bg, 'mode', return_value='alle'), patch.object(ollama, 'load_context', return_value=context or self.context), patch.object(ollama, 'request_json') as network:
            result = bg.read(*self.identity)
        network.assert_not_called()
        return result

    def test_retrieval_uses_current_labels_and_never_calls_ollama(self):
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'], kontext=bg.context_signature(self.context), status='bereit', ergebnis=json.dumps({k:v for k,v in self.proposal.items() if k!='diagnostic'}))
        result = self.read(cached)
        self.assertEqual(result['status'], 'bereit')
        self.assertEqual(result['suggestion']['labels']['category'], 'Haus')
        self.assertEqual(self.read(None), {'status': 'wartend'})

    def test_changed_identity_or_text_is_rejected_before_returning_proposal(self):
        for changed in [{'id': 2}, {'sha256': 'c'*64}, {'status': 'archiviert'}, {'ergebnis': None},
                        {'ergebnis': json.dumps({'pdf': {'text': 'anderer Text'}})}]:
            self.cursor.fetchone.side_effect = None
            self.cursor.fetchone.return_value = {**self.row, 'status': 'bereit', **changed}
            with self.subTest(changed=changed), patch.object(bg, 'get_connection', return_value=self.connection), patch.object(bg, 'mode', return_value='alle'), patch.object(ollama, 'request_json') as network:
                with self.assertRaises(ollama.OllamaError): bg.read(*self.identity)
                network.assert_not_called()

    def test_window_change_invalidates_cache_and_updates_context_signature(self):
        cached=dict(textfenster='9000:3000',source_sha256='a'*64,revision=self.context['revision'],kontext=bg.context_signature(self.context),status='bereit')
        with patch.object(ollama,'load_text_settings',return_value=(9000,0)):
            self.assertEqual(self.read(cached),{'status':'wartend'})
        self.assertNotEqual(bg.context_signature(self.context),bg.context_signature({**self.context,'text_tail':0}))
        self.assertEqual(bg.context_signature(self.context),bg.context_signature({**self.context,'text_head':9000,'text_tail':3000}))

    def test_context_changes_schedule_refresh_instead_of_showing_stale_cache(self):
        cached = dict(textfenster='9000:3000', source_sha256='a'*64, revision=self.context['revision'], kontext=bg.context_signature(self.context), status='bereit')
        for changed in [{'thinking': False}, {'instruction': 'Neue Anweisung'}, {'categories': {'64': 'Anderer Name'}}, {'model': 'other'}]:
            self.assertEqual(self.read(cached, {**self.context, **changed}), {'status': 'wartend'})
            self.assertIn("status='veraltet'", self.cursor.execute.call_args.args[0])


class GenerationLockTests(unittest.TestCase):
    def test_connection_lock_released_even_on_generation_failure(self):
        connection = MagicMock(); cursor = connection.cursor.return_value
        cursor.fetchone.return_value = (1,)
        with patch.object(ollama, 'get_connection', return_value=connection):
            with self.assertRaisesRegex(RuntimeError, 'failed'):
                with ollama.generation_lock('db') as acquired:
                    self.assertTrue(acquired); raise RuntimeError('failed')
        self.assertIn('RELEASE_LOCK', cursor.execute.call_args.args[0])
        connection.close.assert_called_once()

    def test_manual_request_is_rejected_while_another_generation_owns_lane(self):
        with patch.object(ollama, 'generation_lock', return_value=nullcontext(False)), patch.object(ollama, '_suggest') as generate:
            with self.assertRaisesRegex(ollama.OllamaError, 'läuft bereits'):
                ollama.suggest('db', 'scan.pdf', 'a'*64, 1, 'b'*64)
            generate.assert_not_called()

class WorkerConcurrencyTests(unittest.TestCase):
    def test_inbox_scan_continues_while_ai_waits(self):
        import os
        from pathlib import Path
        import tempfile
        import worker
        entered, release = threading.Event(), threading.Event()
        def blocked(_, **kwargs):
            entered.set(); release.wait(5); worker.stop.set(); return 'done'
        worker.stop.clear()
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder)/'inbox'; root.mkdir(); pdf=root/'scan.pdf'; pdf.write_bytes(b'pdf'); os.utime(pdf, (1, 1))
            with patch.dict(os.environ, {'DB_NAME':'db', 'EABLAGE_INBOX_ENABLED':'true', 'ARCHIVE_DATA_ROOT':folder}), patch.object(worker, 'WorkerMonitor'), patch.object(worker, 'run_one', side_effect=blocked), patch.object(worker, 'get_connection', return_value=MagicMock()), patch.object(worker, 'settings', return_value={'pfadAblageNeu_linux':str(root)}), patch.object(worker, '_root', return_value=root), patch.object(worker, 'reconcile_missing'), patch.object(worker, 'list_jobs', return_value={'jobs':[]}), patch.object(worker, 'process', return_value={'id':1,'sha256':'a'*64,'status':'bereit'}) as process:
                thread=threading.Thread(target=worker.ai_loop, args=('db', 5)); thread.start()
                try:
                    self.assertTrue(entered.wait(1))
                    observed=worker.scan({})
                    worker.scan(observed)
                    process.assert_called_once_with('db', 'scan.pdf')
                    self.assertFalse(release.is_set())
                finally:
                    release.set(); thread.join(2); worker.stop.clear()
                self.assertFalse(thread.is_alive())


class WorkerErrorLogTests(unittest.TestCase):
    def test_database_error_logs_codes_without_private_exception_message(self):
        import os
        import worker
        class DatabaseError(Exception):
            errno=1267
            sqlstate='HY000'
        worker.stop.clear()
        try:
            with patch.dict(os.environ, {'EABLAGE_INBOX_ENABLED':'true'}), patch.object(worker, 'WorkerMonitor'), patch.object(worker, 'run_one', side_effect=DatabaseError('private document and SQL text')), patch.object(worker.LOG, 'error') as log, patch.object(worker.stop, 'wait', side_effect=lambda *_: worker.stop.set()):
                worker.ai_loop('db', 60)
            self.assertEqual(log.call_args.args[1:], ('DatabaseError',1267,'HY000'))
            self.assertNotIn('private document', str(log.call_args))
        finally:
            worker.stop.clear()
