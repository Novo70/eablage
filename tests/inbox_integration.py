"""Real MariaDB + Poppler + PHP tests. Only disposable local DBs are created.
Run with EABLAGE_TEST_DB_PORT and PHP_BIN; no NAS access.
"""
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import unittest
import uuid
from unittest.mock import patch
import mysql.connector
from reportlab.pdfgen import canvas

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from app.db import database
from app.services import inbox
from app.services.classification import classify
from fastapi.testclient import TestClient
from main import app
import worker


class InboxIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.name = 'eablage_inbox_' + uuid.uuid4().hex[:12]
        cls.db_args = dict(host='127.0.0.1', port=int(os.environ['EABLAGE_TEST_DB_PORT']), user='root', password=os.getenv('EABLAGE_TEST_DB_PASSWORD',''))
        cls.admin = mysql.connector.connect(**cls.db_args, autocommit=True)
        c = cls.admin.cursor()
        c.execute(f'CREATE DATABASE {cls.name} CHARACTER SET utf8mb4')
        c.execute(f'USE {cls.name}')
        for filename in ('sql/schema.sql', 'sql/seed.sql'):
            # Statements here have no stored routines. Connector handles the script.
            c.execute((ROOT/filename).read_text())
            while c.nextset(): pass
        cls.temp = tempfile.TemporaryDirectory()
        cls.base = Path(cls.temp.name)
        for folder in ('inbox','archive'): (cls.base/folder).mkdir()
        for key, value in [('pfadAblageNeu_linux', str(cls.base/'inbox')),('pfadAblage_linux',str(cls.base/'archive'))]:
            c.execute('UPDATE parameter SET param_value=%s WHERE param_key=%s',(value,key))
        cls.env = patch.dict(os.environ, {'ARCHIVE_DATA_ROOT':str(cls.base), 'DB_NAME':cls.name})
        cls.env.start()
        cls.config = patch.dict(database.DB_CONFIG, {**cls.db_args,'database':cls.name})
        cls.config.start()

    @classmethod
    def tearDownClass(cls):
        cls.config.stop(); cls.env.stop()
        cls.admin.cursor().execute(f'DROP DATABASE {cls.name}')
        cls.admin.close(); cls.temp.cleanup()

    def setUp(self):
        self.admin.cursor().execute('DELETE FROM verarbeitungsauftraege')
        for p in (self.base/'inbox').iterdir(): p.unlink()

    def pdf(self, filename, text='Document 12345'):
        output = io.BytesIO(); c=canvas.Canvas(output)
        c.drawString(40,700,text); c.save()
        path=self.base/'inbox'/filename;path.write_bytes(output.getvalue())
        return path

    def test_manual_without_worker_and_cached_result(self):
        self.pdf('manual.pdf')
        with TestClient(app) as client:
            response=client.post('/inbox/process',json={'database':self.name,'filename':'manual.pdf'})
            self.assertEqual(response.status_code,200,response.text)
            first=response.json();self.assertEqual(first['status'],'bereit',first)
            self.assertIn('12345',first['result']['pdf']['text'])
            with patch('app.services.inbox.extract',side_effect=AssertionError('must use cached result')):
                self.assertEqual(inbox.process(self.name,'manual.pdf'),first)
            self.assertEqual(client.post('/inbox/process',json={'database':'wrong','filename':'manual.pdf'}).status_code,400)

    def test_explicit_ocr_replaces_cached_proposals(self):
        self.pdf('force.pdf', 'Wrong old text layer')
        old = inbox.process(self.name, 'force.pdf')
        pdf = dict(ok=True, text='Fresh OCR 12345', used_mode='ocr', error='', ocr_pages=1)
        with TestClient(app) as client, patch('app.services.inbox.extract', return_value=pdf) as extract:
            response = client.post('/inbox/process', json={
                'database': self.name, 'filename': 'force.pdf', 'ocr_requested': True})
            self.assertEqual(response.status_code, 200, response.text)
            new = response.json()
            self.assertEqual(new['id'], old['id'])
            self.assertEqual(new['sha256'], old['sha256'])
            self.assertEqual(new['status'], 'bereit')
            self.assertEqual(new['result']['pdf']['text'], 'Fresh OCR 12345')
            self.assertTrue(extract.call_args.kwargs['ocr_requested'])
        with patch('app.services.inbox.extract', side_effect=AssertionError('forced result must remain cached')):
            self.assertEqual(inbox.process(self.name, 'force.pdf'), new)

    def test_concurrency_php_lock_and_other_document_immediate(self):
        self.pdf('busy.pdf','BUSY');self.pdf('other.pdf','OTHER')
        started=threading.Event();release=threading.Event(); results=[]
        def slow(text):
            if 'BUSY' in text: started.set();release.wait(10)
            return classify('')
        with patch('app.services.inbox.classify',side_effect=slow):
            t=threading.Thread(target=lambda:results.append(inbox.process(self.name,'busy.pdf')));t.start()
            try:
                self.assertTrue(started.wait(5))
                self.assertEqual(inbox.process(self.name,'busy.pdf')['status'],'verarbeitung')
                self.assertEqual(inbox.process(self.name,'other.pdf')['status'],'bereit')
                code="define('MYSQL_DATENBANK',$argv[1]); require $argv[2]; echo eablage_inbox_lock_name('busy.pdf');"
                lock=subprocess.check_output([os.environ['PHP_BIN'],'-r',code,self.name,str(ROOT/'web/inbox_funktionen.php')],text=True)
                c=self.admin.cursor();c.execute('SELECT GET_LOCK(%s,0)',(lock,));self.assertEqual(c.fetchone()[0],0)
            finally:
                release.set();t.join(10)
        self.assertEqual(results[0]['status'],'bereit')

    def test_recovery_after_killed_owner(self):
        self.pdf('recover.pdf')
        job=inbox.process(self.name,'recover.pdf')
        c=self.admin.cursor();c.execute("UPDATE verarbeitungsauftraege SET status='verarbeitung',ergebnis=NULL WHERE id=%s",(job['id'],))
        owner=database.get_connection();oc=owner.cursor();oc.execute('SELECT GET_LOCK(%s,0)',(inbox.lock_name(self.name,'recover.pdf'),));oc.fetchone()
        self.assertEqual(inbox.process(self.name,'recover.pdf')['status'],'verarbeitung')
        owner.close()
        recovered=inbox.process(self.name,'recover.pdf');self.assertEqual(recovered['status'],'bereit')
        self.assertEqual(recovered['id'],job['id'])

    def test_replacement_invalidation_and_changed_during_processing(self):
        path=self.pdf('replace.pdf','OLD');old=inbox.process(self.name,path.name)
        self.pdf(path.name,'NEW');new=inbox.process(self.name,path.name)
        self.assertNotEqual(new['sha256'],old['sha256']);self.assertIn('NEW',new['result']['pdf']['text'])
        self.pdf('race.pdf','BEFORE')
        def replace(text):
            self.pdf('race.pdf','AFTER')
            return classify('')
        with patch('app.services.inbox.classify',side_effect=replace):
            result=inbox.process(self.name,'race.pdf')
        self.assertEqual(result['status'],'fehler')
        self.assertIn('geändert',result['error'])

    def test_failed_job_retry_and_next_file(self):
        bad=self.base/'inbox'/'bad.pdf';bad.write_bytes(b'not a PDF')
        result=inbox.process(self.name,bad.name);self.assertEqual(result['status'],'fehler')
        with patch('app.services.inbox.extract',side_effect=AssertionError('no automatic retry')):
            self.assertEqual(inbox.process(self.name,bad.name)['status'],'fehler')
        self.assertEqual(inbox.process(self.name,bad.name,retry=True)['status'],'fehler')
        self.pdf('good.pdf');self.assertEqual(inbox.process(self.name,'good.pdf')['status'],'bereit')

    def test_read_failures_are_visible_and_recoverable(self):
        oversized = self.base/'inbox'/'large.pdf'
        with oversized.open('wb') as stream:
            stream.truncate(inbox.MAX_PDF + 1)
        result = inbox.process(self.name, oversized.name)
        self.assertEqual(result['status'], 'fehler')
        self.assertEqual(result['sha256'], '')
        self.assertIn('50 MiB', result['error'])
        self.assertIsNone(result['result'])
        self.assertEqual(inbox.list_jobs(self.name)['jobs'][0]['status'], 'fehler')
        self.pdf(oversized.name, 'Replacement readable')
        recovered = inbox.process(self.name, oversized.name)
        self.assertEqual(recovered['id'], result['id'])
        self.assertEqual(recovered['status'], 'bereit')
        # A previously ready proposal must not survive a later read failure.
        with patch.object(inbox, 'read_pdf', side_effect=PermissionError('denied')):
            failed = inbox.process(self.name, oversized.name)
        self.assertEqual(failed['status'], 'fehler')
        self.assertIn('Leserechte', failed['error'])
        self.assertIsNone(failed['result'])
        self.assertEqual(inbox.process(self.name, oversized.name)['status'], 'bereit')
        c = self.admin.cursor()
        c.execute("UPDATE verarbeitungsauftraege SET status='archiviert',dok_id=12345,ergebnis=NULL WHERE id=%s", (result['id'],))
        with patch.object(inbox, 'read_pdf', side_effect=PermissionError('denied')):
            completed = inbox.process(self.name, oversized.name)
        self.assertEqual(completed['status'], 'archiviert')
        self.assertEqual(completed['document_id'], 12345)

    def test_locked_or_changing_source_waits_and_next_file_runs(self):
        path = self.pdf('copy.pdf')
        import fcntl
        with path.open('rb') as stream:
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            pending = inbox.process(self.name, path.name)
            self.assertEqual(pending['status'], 'wartend')
            self.assertIsNone(pending['result'])
        with patch.object(inbox, 'read_pdf', side_effect=inbox.PendingPDFError('changed')):
            self.assertEqual(inbox.process(self.name, path.name)['status'], 'wartend')
        self.pdf('next.pdf')
        self.assertEqual(inbox.process(self.name, 'next.pdf')['status'], 'bereit')
        self.assertEqual(inbox.process(self.name, path.name)['status'], 'bereit')

    def test_worker_stability_and_path_rejection(self):
        p=self.pdf('worker.pdf');os.utime(p,(1,1))
        first=worker.scan({});self.assertEqual(inbox.list_jobs(self.name)['jobs'],[])
        worker.scan(first);self.assertEqual(inbox.list_jobs(self.name)['jobs'][0]['status'],'bereit')
        p.unlink();worker.scan(first)
        self.assertEqual(inbox.list_jobs(self.name, ['worker.pdf'])['jobs'][0]['status'],'entfernt')
        p=self.pdf('worker.pdf')
        for name in ('../outside.pdf','a\\b.pdf'):
            with self.assertRaises(ValueError):inbox.process(self.name,name)
        link=self.base/'inbox'/'link.pdf';link.symlink_to(p)
        with self.assertRaises(ValueError):inbox.process(self.name,link.name)

    def test_php_manual_screen_and_archive_job_completion(self):
        self.pdf('screen.pdf', 'Invoice 12345')
        with TestClient(app) as client:
            class Handler(BaseHTTPRequestHandler):
                def log_message(self,*args): pass
                def do_POST(self):
                    response=client.post(self.path,content=self.rfile.read(int(self.headers['Content-Length'])),headers={'Content-Type':'application/json'})
                    self.send_response(response.status_code);self.end_headers();self.wfile.write(response.content)
            server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            try:
                (self.base/'meta').mkdir(exist_ok=True)
                config="<?php "
                config+=f"define('MYSQL_DATENBANK','{self.name}'); define('DB_TABELLE_ARCHIV','dokumente'); define('DOKID_STARTWERT',10000); $config=[]; "
                config+="$db_config=new PDO("+json.dumps(f"mysql:host=127.0.0.1;port={self.db_args['port']};dbname={self.name};charset=utf8mb4")+",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); "
                for key,folder in [('PFAD_INBOX','inbox'),('PFAD_ARCHIV','archive'),('PFAD_META','meta')]:
                    config+=f"define('{key}',"+json.dumps(str(self.base/folder)+'/')+"); "
                config+=f"putenv('BACKEND_URL=http://127.0.0.1:{server.server_port}');"
                (self.base/'konfiguration.php').write_text(config)
                code="$_SERVER['REQUEST_METHOD']='GET'; $_GET=['file'=>'screen.pdf']; require $argv[1];"
                screen=subprocess.run([os.environ['PHP_BIN'],'-r',code,str(ROOT/'web/verarbeiten.php')],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertNotIn('Fatal error',screen.stdout+screen.stderr)
                self.assertIn('Invoice 12345',screen.stdout)
                self.assertIn('value="prepare_rule"',screen.stdout)
                job=inbox.process(self.name,'screen.pdf')
                # Only a classification rule suppresses preparing a new K rule.
                c=self.admin.cursor()
                auto=job['result']['classification']['autokat']
                auto.update(matched=True, matched_supplement_rule_refs=[{'type':'E','regel_id':'R0001','version':1,'rank':10}])
                for primary in (None, {'type':'K','regel_id':'R0002','version':1,'rank':20}):
                    with self.subTest(classification_rule=primary):
                        auto['matched_classification_rule_ref']=primary
                        c.execute('UPDATE verarbeitungsauftraege SET ergebnis=%s WHERE id=%s', (json.dumps(job['result']),job['id']))
                        rendered=subprocess.run([os.environ['PHP_BIN'],'-r',code,str(ROOT/'web/verarbeiten.php')],cwd=self.base,capture_output=True,text=True,check=True)
                        self.assertEqual('value="prepare_rule"' in rendered.stdout, primary is None)
                # Cached assignments survive visibly when master data changes.
                c.execute("INSERT INTO kategorie (id,katBez,Aktiv) VALUES ('991','Alte Kategorie',0)")
                job['result']['classification'].update(category='991',category2='992',document_type='993')
                c.execute('UPDATE verarbeitungsauftraege SET ergebnis=%s WHERE id=%s', (json.dumps(job['result']),job['id']))
                screen=subprocess.run([os.environ['PHP_BIN'],'-r',code,str(ROOT/'web/verarbeiten.php')],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertIn('Diese Vorschläge sind nicht mehr verfügbar',screen.stdout)
                self.assertIn('Alte Kategorie (inaktiv) – bitte neu wählen',screen.stdout)
                self.assertIn('ID 992 (gelöscht) – bitte neu wählen',screen.stdout)
                self.assertIn('ID 993 (gelöscht) – bitte neu wählen',screen.stdout)
                for value in ('991','992','993'):
                    self.assertIn(f'value="{value}" selected',screen.stdout)
                self.assertEqual(screen.stdout.count('name="Kategorie2"'),1)
                save="require $argv[1]; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['aktion_token'=>eablage_aktion_token(),'original_datei'=>'screen.pdf','Datum'=>'2026-10-06','dokument_text'=>'Invoice 12345','inbox_job_id'=>$argv[3],'source_sha256'=>$argv[4]]; require $argv[2];"
                args=[os.environ['PHP_BIN'],'-r',save,str(ROOT/'web/csrf.php'),str(ROOT/'web/speichern.php'),str(job['id'])]
                stale=subprocess.run([*args,'0'*64],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertIn('seit der Erkennung geändert',stale.stdout)
                self.assertTrue((self.base/'inbox/screen.pdf').exists())
                invalid_save=save.replace("'Datum'=>'2026-10-06'", "'Kategorie'=>'991','Kategorie2'=>'992','Doktyp'=>'993','Datum'=>'2026-10-06'")
                invalid=subprocess.run([os.environ['PHP_BIN'],'-r',invalid_save,*args[3:],job['sha256']],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertIn('deaktiviert oder gelöscht',invalid.stdout)
                self.assertTrue((self.base/'inbox/screen.pdf').exists())
                success=subprocess.run([*args,job['sha256']],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertFalse((self.base/'inbox/screen.pdf').exists(),success.stdout+success.stderr)
                c=self.admin.cursor();c.execute('SELECT status,dok_id,ergebnis FROM verarbeitungsauftraege WHERE id=%s',(job['id'],));row=c.fetchone()
                self.assertEqual(row,('archiviert',10000,None))
                # Manual archive after a read failure still verifies the source
                # and records its hash, so a leftover copy cannot be archived twice.
                source=self.pdf('manual-error.pdf', 'Manual after read error')
                original=source.read_bytes()
                digest=hashlib.sha256(original).hexdigest()
                failed_code=code.replace('screen.pdf','manual-error.pdf')
                with patch.object(inbox, 'read_pdf', side_effect=PermissionError('denied')):
                    failed_screen=subprocess.run([os.environ['PHP_BIN'],'-r',failed_code,str(ROOT/'web/verarbeiten.php')],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertIn('Leserechte',failed_screen.stdout)
                self.assertIn(f'name="source_sha256" value="{digest}"',failed_screen.stdout)
                c.execute("SELECT id FROM verarbeitungsauftraege WHERE dateiname='manual-error.pdf'")
                failed_id=c.fetchone()[0]
                manual_save=save.replace('screen.pdf','manual-error.pdf')
                manual_args=[os.environ['PHP_BIN'],'-r',manual_save,str(ROOT/'web/csrf.php'),str(ROOT/'web/speichern.php'),str(failed_id)]
                stale_manual=subprocess.run([*manual_args,'0'*64],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertIn('seit der Erkennung geändert',stale_manual.stdout)
                saved_manual=subprocess.run([*manual_args,digest],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertFalse(source.exists(),saved_manual.stdout+saved_manual.stderr)
                c.execute('SELECT status,sha256 FROM verarbeitungsauftraege WHERE id=%s',(failed_id,))
                self.assertEqual(c.fetchone(),('archiviert',digest))
                source.write_bytes(original)
                self.assertEqual(inbox.process(self.name,source.name)['status'],'archiviert')
                source.unlink()
                discarded=self.pdf('discard-error.pdf')
                discarded_hash=hashlib.sha256(discarded.read_bytes()).hexdigest()
                with patch.object(inbox,'read_pdf',side_effect=PermissionError('denied')):
                    discard_job=inbox.process(self.name,discarded.name)
                discard="require $argv[1]; $_SERVER['REQUEST_METHOD']='POST'; $_GET=['action'=>'discard']; $_POST=['aktion_token'=>eablage_aktion_token(),'file'=>'discard-error.pdf','sha256'=>$argv[3]]; require $argv[2];"
                subprocess.run([os.environ['PHP_BIN'],'-r',discard,str(ROOT/'web/csrf.php'),str(ROOT/'web/eingang.php'),discarded_hash],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertFalse(discarded.exists())
                c.execute('SELECT status FROM verarbeitungsauftraege WHERE id=%s',(discard_job['id'],))
                self.assertEqual(c.fetchone()[0],'verworfen')
                c.execute('DELETE FROM dokumente')
                restore_code="require 'konfiguration.php'; require $argv[1]; require $argv[2]; $backup=eablage_create_database_backup($db_config,MYSQL_DATENBANK,getcwd()); echo json_encode(eablage_restore_database($db_config,$backup['path']));"
                restored=subprocess.run([os.environ['PHP_BIN'],'-r',restore_code,str(ROOT/'web/datenbank_sicherung.php'),str(ROOT/'web/datenbank_wiederherstellung.php')],cwd=self.base,capture_output=True,text=True,check=True)
                self.assertEqual(json.loads(restored.stdout)['documents'],0)
                c.execute('SELECT COUNT(*) FROM verarbeitungsauftraege');self.assertEqual(c.fetchone()[0],0)
            finally:
                server.shutdown();server.server_close();thread.join(5)

    def test_worker_skips_unchanged_but_recovers_after_queue_reset(self):
        path = self.pdf('cached.pdf'); os.utime(path, (1, 1))
        cache = {}
        observed = worker.scan({}, cache)
        observed = worker.scan(observed, cache)
        with patch.object(inbox, 'read_pdf', side_effect=AssertionError('unchanged PDF must not be read')):
            observed = worker.scan(observed, cache)
        # Restore/manual queue cleanup must invalidate the in-memory cache.
        self.admin.cursor().execute('DELETE FROM verarbeitungsauftraege')
        with patch.object(inbox, 'read_pdf', wraps=inbox.read_pdf) as reader:
            observed = worker.scan(observed, cache)
            self.assertTrue(reader.called)
        self.pdf(path.name, 'Different content'); os.utime(path, (1, 1))
        observed = worker.scan(observed, cache)
        worker.scan(observed, cache)
        self.assertIn('Different content', inbox.process(self.name, path.name)['result']['pdf']['text'])
        path.unlink(); worker.scan(observed, cache)
        self.assertNotIn(path.name, cache)

    def test_status_queries_only_requested_or_present_files(self):
        self.pdf('present.pdf'); self.pdf('gone.pdf')
        inbox.process(self.name, 'present.pdf'); inbox.process(self.name, 'gone.pdf')
        (self.base/'inbox/gone.pdf').unlink()
        self.assertEqual([r['dateiname'] for r in inbox.list_jobs(self.name)['jobs']], ['present.pdf'])
        with TestClient(app) as client:
            self.assertEqual(client.post('/inbox/jobs', json={'database':self.name,'filenames':[]}).json()['jobs'], [])
            reply=client.post('/inbox/jobs', json={'database':self.name,'filenames':['gone.pdf']})
            self.assertEqual([r['dateiname'] for r in reply.json()['jobs']], ['gone.pdf'])
            self.assertEqual(client.post('/inbox/jobs', json={'database':self.name,'filenames':['x.pdf']*201}).status_code,422)

    def test_restore_gate_prevents_new_processing(self):
        self.pdf('restore.pdf')
        c=self.admin.cursor();key='eablage.inbox.gate.'+hashlib.sha256(self.name.encode()).hexdigest()[:40]
        c.execute('SELECT GET_LOCK(%s,0)',(key,));c.fetchone()
        try:self.assertEqual(inbox.process(self.name,'restore.pdf')['status'],'verarbeitung')
        finally:c.execute('SELECT RELEASE_LOCK(%s)',(key,));c.fetchone()
        self.assertEqual(inbox.process(self.name,'restore.pdf')['status'],'bereit')

if __name__=='__main__':unittest.main()
