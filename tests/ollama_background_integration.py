"""Actual MariaDB cache/lease tests; isolated DB only, no Ollama or NAS access."""
import hashlib
import json
import os
from pathlib import Path
import sys
import unittest
import uuid
from unittest.mock import patch
import mysql.connector

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'backend'))
from app.db import database
from app.services import ollama, ollama_background as bg, ki_status


@unittest.skipUnless(os.getenv('EABLAGE_TEST_DB_PORT'), 'Disposable local MariaDB required')
class BackgroundIntegration(unittest.TestCase):
    def setUp(self):
        self.name='eablage_ai_'+uuid.uuid4().hex[:12]
        args=dict(host='127.0.0.1',port=int(os.environ['EABLAGE_TEST_DB_PORT']),user='root',password=os.getenv('EABLAGE_TEST_DB_PASSWORD',''))
        self.admin=mysql.connector.connect(**args,autocommit=True)
        self.cursor=self.admin.cursor()
        self.cursor.execute(f'CREATE DATABASE {self.name} CHARACTER SET utf8mb4')
        self.cursor.execute(f'USE {self.name}')
        for script in ['sql/schema.sql','sql/seed.sql']:
            self.script(script)
        self.config=patch.dict(database.DB_CONFIG,{**args,'database':self.name}); self.config.start()
        self.cursor.execute("UPDATE parameter SET param_value='ja' WHERE param_key='ollamaAktiv'")
        self.cursor.execute("UPDATE parameter SET param_value='alle' WHERE param_key='ollamaHintergrund'")
        self.raw=json.dumps({'pdf':{'text':'Rechnung A123 vom 11.10.2026'},'classification':{'category':'0'}})
        self.cursor.execute("INSERT INTO verarbeitungsauftraege (dateischluessel,dateiname,sha256,status,ergebnis) VALUES (%s,'scan.pdf',%s,'bereit',%s)",('f'*64,'a'*64,self.raw))
        self.job_id=self.cursor.lastrowid
        self.context=dict(source_sha256='a'*64,url='http://192.168.1.2:11434',model='qwen3.5:9b',text='Rechnung A123 vom 11.10.2026',revision=bg.revision(self.raw),categories={},types={})
        self.proposal=dict(model='qwen3.5:9b',proposal={},warnings=[],labels={},diagnostic={'body':'private'})

    def tearDown(self):
        self.config.stop(); self.cursor.execute(f'DROP DATABASE {self.name}'); self.admin.close()

    def script(self,path):
        self.cursor.execute((ROOT/path).read_text())
        while self.cursor.nextset(): pass

    def run_ai(self, callback=None):
        with patch.object(ollama,'load_context',return_value=self.context), patch.object(ollama,'_suggest',side_effect=callback,return_value=self.proposal):
            return bg.run_one(self.name)

    def cache(self):
        self.cursor.execute('SELECT status,ergebnis FROM ki_vorschlaege WHERE auftrag_id=%s',(self.job_id,)); return self.cursor.fetchone()

    def test_migration_repeatability_preserves_setting_and_existing_job(self):
        for _ in range(2): self.script('sql/update_ollama_background.sql')
        self.cursor.execute("SELECT param_value FROM parameter WHERE param_key='ollamaHintergrund'")
        self.assertEqual(self.cursor.fetchone()[0],'alle')
        self.cursor.execute('SELECT ergebnis FROM verarbeitungsauftraege WHERE id=%s',(self.job_id,))
        self.assertEqual(self.cursor.fetchone()[0],self.raw)

    def test_text_selection_repeat_has_no_result_sets_with_either_connector(self):
        for pure in ((False, True) if mysql.connector.HAVE_CEXT else (True,)):
            with self.subTest(use_pure=pure):
                db = mysql.connector.connect(host='127.0.0.1',
                    port=int(os.environ['EABLAGE_TEST_DB_PORT']), user='root',
                    password=os.getenv('EABLAGE_TEST_DB_PASSWORD', ''),
                    database=self.name, use_pure=pure, autocommit=True)
                try:
                    cursor = db.cursor()
                    for _ in range(2):
                        cursor.execute((ROOT/'sql/update_ollama_text_selection.sql').read_text())
                        while True:
                            self.assertFalse(cursor.with_rows, 'Migrationsskript liefert unerwartete Ergebnismenge')
                            if not cursor.nextset(): break
                finally:
                    db.close()

    def test_queue_and_publication_work_with_utf8_connection_collations(self):
        # Use actual server-supported collations, including the reported MySQL 8
        # default when available. Keep both hash columns in ascii_bin.
        self.cursor.execute("SHOW COLLATION WHERE Collation IN ('utf8mb4_unicode_ci','utf8mb4_general_ci','utf8mb4_0900_ai_ci')")
        collations=[row[0] for row in self.cursor.fetchall()]
        self.assertTrue(collations)
        connect=database.get_connection
        for collation in collations:
            with self.subTest(collation=collation):
                self.cursor.execute('DELETE FROM ki_vorschlaege')
                def utf8_connection():
                    connection=connect()
                    cursor=connection.cursor()
                    cursor.execute('SET NAMES utf8mb4 COLLATE '+collation)
                    cursor.close()
                    return connection
                with patch.object(bg,'get_connection',side_effect=utf8_connection), patch.object(ollama,'get_connection',side_effect=utf8_connection):
                    self.assertEqual(self.run_ai(),'done')
                    self.assertEqual(self.cache()[0],'bereit')
                    self.assertEqual(self.run_ai(),'idle')
                    # Exercise the mismatch branch, not just missing/ready rows.
                    self.cursor.execute("UPDATE ki_vorschlaege SET revision=%s", ('0'*64,))
                    self.assertEqual(self.run_ai(),'done')
                    self.assertEqual(self.cache()[0],'bereit')

    def test_generation_cached_once_and_no_raw_diagnostic_stored(self):
        self.assertEqual(self.run_ai(),'done')
        self.assertEqual(self.run_ai(),'idle')
        state,raw=self.cache(); self.assertEqual(state,'bereit'); self.assertNotIn('diagnostic',json.loads(raw))

    def test_archival_during_generation_prevents_publication(self):
        def archive(*args):
            self.cursor.execute("UPDATE verarbeitungsauftraege SET status='archiviert',ergebnis=NULL WHERE id=%s",(self.job_id,)); return self.proposal
        self.run_ai(archive)
        self.assertEqual(self.cache(),('verarbeitung',None))
        self.assertEqual(self.run_ai(),'idle'); self.assertIsNone(self.cache())

    def test_new_ocr_revision_during_generation_prevents_publication(self):
        def change(*args):
            self.cursor.execute('UPDATE verarbeitungsauftraege SET ergebnis=%s WHERE id=%s',(self.raw+' ',self.job_id)); return self.proposal
        self.run_ai(change)
        self.assertEqual(self.cache(),('verarbeitung',None))
        self.context['revision']=bg.revision(self.raw+' ')
        self.assertEqual(self.run_ai(),'done'); self.assertEqual(self.cache()[0],'bereit')

    def test_global_lane_blocks_manual_and_worker_generation(self):
        with ollama.generation_lock(self.name) as acquired:
            self.assertTrue(acquired)
            self.assertEqual(bg.run_one(self.name),'idle')
            with self.assertRaisesRegex(ollama.OllamaError,'läuft bereits'):
                ollama.suggest(self.name,'scan.pdf','a'*64,self.job_id,'b'*64)
        self.assertEqual(self.run_ai(),'done')

    def test_runtime_status_migration_and_database_clock_based_progress(self):
        for _ in range(2): self.script('sql/update_ollama_worker_status.sql')
        monitor=ki_status.WorkerMonitor(self.name)
        monitor.processing(self.job_id); monitor._publish()
        self.cursor.execute("INSERT INTO ki_vorschlaege (auftrag_id,revision,source_sha256,textfenster,status) VALUES (%s,%s,%s,'9000:3000','verarbeitung')",(self.job_id,bg.revision(self.raw),'a'*64))
        info=ki_status.snapshot(self.name)
        self.assertTrue(info['worker']['fresh'])
        self.assertEqual(info['jobs'][self.job_id]['state'],'verarbeitung')
        self.assertGreaterEqual(info['worker']['elapsed'],0)
        monitor.update('pausiert',delay=300); monitor._publish()
        self.script('sql/update_ollama_worker_status.sql')
        info=ki_status.snapshot(self.name)
        self.assertTrue(info['worker']['fresh'])
        self.assertEqual(info['jobs'][self.job_id]['state'],'pausiert')
        self.assertGreaterEqual(info['worker']['retry_in'],295)
        self.cursor.execute("UPDATE ki_worker_status SET heartbeat=DATE_SUB(NOW(),INTERVAL 1 MINUTE)")
        info=ki_status.snapshot(self.name)
        self.assertFalse(info['worker']['fresh'])
        self.assertEqual(info['jobs'][self.job_id]['state'],'wartend')

    def test_text_selection_migration_preserves_existing_settings_cache_and_attempt_time(self):
        self.assertEqual(self.run_ai(),'done')
        self.cursor.execute("UPDATE ki_vorschlaege SET textfenster=NULL,geaendert_am='2020-01-01 00:00:00' WHERE auftrag_id=%s",(self.job_id,))
        self.cursor.execute("UPDATE parameter SET param_value='0' WHERE param_key='ollamaTextEnde'")
        for _ in range(2): self.script('sql/update_ollama_text_selection.sql')
        self.cursor.execute('SELECT textfenster,status,geaendert_am FROM ki_vorschlaege WHERE auftrag_id=%s',(self.job_id,))
        key,state,changed=self.cursor.fetchone()
        self.assertEqual((key,state),('9000:3000','bereit'))
        self.assertEqual(str(changed),'2020-01-01 00:00:00')
        self.context['text_tail']=0
        self.assertEqual(self.run_ai(),'done')
        self.cursor.execute('SELECT textfenster FROM ki_vorschlaege WHERE auftrag_id=%s',(self.job_id,))
        self.assertEqual(self.cursor.fetchone()[0],'9000:0')
        self.assertEqual(self.run_ai(),'idle')

    def test_text_selection_migration_also_supports_manual_only_installations(self):
        self.cursor.execute('DROP TABLE ki_vorschlaege')
        for _ in range(2): self.script('sql/update_ollama_text_selection.sql')
        self.cursor.execute("SHOW COLUMNS FROM ki_vorschlaege LIKE 'textfenster'")
        self.assertIsNotNone(self.cursor.fetchone())
        self.assertEqual(self.run_ai(),'done')

    def test_worker_recovers_abandoned_processing_state(self):
        self.cursor.execute("INSERT INTO ki_vorschlaege (auftrag_id,revision,source_sha256,textfenster,status) VALUES (%s,%s,%s,'9000:3000','verarbeitung')",(self.job_id,bg.revision(self.raw),'a'*64))
        self.assertEqual(self.run_ai(),'done'); self.assertEqual(self.cache()[0],'bereit')

    def test_terminal_failure_is_not_retried_until_context_changes(self):
        def fail(*_):
            raise ollama.OllamaGenerationError('Beide Durchläufe gescheitert')
        self.assertEqual(self.run_ai(fail), 'stopped')
        self.cursor.execute('SELECT ergebnis,kontext FROM ki_vorschlaege WHERE auftrag_id=%s', (self.job_id,))
        raw, signature = self.cursor.fetchone()
        self.assertEqual(json.loads(raw), {'retryable': False, 'attempts': []})
        self.assertEqual(signature, bg.context_signature(self.context))
        self.cursor.execute("UPDATE ki_vorschlaege SET geaendert_am=DATE_SUB(NOW(),INTERVAL 10 MINUTE)")
        with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, '_suggest') as generate:
            self.assertEqual(bg.run_one(self.name), 'idle')
            generate.assert_not_called()
        self.context['model'] = 'other-local-model'
        self.assertEqual(self.run_ai(), 'done')

    def test_failed_attempt_history_is_persisted_and_read_without_private_payload(self):
        error = ollama.OllamaGenerationError('Fallback ohne Denkmodus: Zeitlimit überschritten')
        error.diagnostic = {'body':'PRIVATE REQUEST', 'attempts':[
            {'thinking':True, 'status':'token_limit', 'end_confirmed':True, 'elapsed_ms':50000},
            {'thinking':False, 'status':'timeout', 'end_confirmed':False, 'elapsed_ms':120000,
             'content':'PRIVATE DOCUMENT'}]}
        def fail(*_): raise error
        self.assertEqual(self.run_ai(fail), 'stopped')
        state, raw = self.cache()
        self.assertEqual(state, 'fehler')
        self.assertNotIn('PRIVATE', raw)
        self.assertEqual(len(json.loads(raw)['attempts']), 2)
        self.cursor.execute('UPDATE verarbeitungsauftraege SET dateischluessel=%s WHERE id=%s',
                            (ollama.file_key(self.name,'scan.pdf'), self.job_id))
        with patch.object(ollama, 'load_context', return_value=self.context), patch.object(ollama, 'request_json') as network:
            result = bg.read(self.name,'scan.pdf','a'*64,self.job_id,hashlib.sha256(self.context['text'].encode()).hexdigest())
        network.assert_not_called()
        self.assertIn('gestartet, aber fehlgeschlagen', result['attempt_history']['summary'])
        self.assertEqual(len(result['attempt_history']['lines']), 2)

if __name__=='__main__': unittest.main()

