"""Real PHP/MariaDB workflows. EABLAGE_TEST_DB_PORT selects a disposable local DB.

Creates/drops its own random database; starts a temporary PHP web root and a local
metadata API fixture. Does not use an installed application or its configuration.
"""
from web_fixture import copy_web
import hashlib
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import re
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
import uuid
import mysql.connector

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from app.services.metadata_service import serialize_metadata


class Forms(HTMLParser):
    def __init__(self, text):
        super().__init__(); self.forms=[]; self.current=None; self.control=None; self.option=None; self.feed(text)
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if tag=='form': self.current={'_id':a.get('id','')};self.forms.append(self.current)
        if self.current is None:return
        if tag=='input' and a.get('name'): self.current[a['name']]=a.get('value','')
        if tag in ('textarea','select') and a.get('name'):
            self.control=(tag,a['name']);self.current[a['name']]='';self.chosen=False
        if tag=='option' and self.control:
            self.option=a
            if not self.chosen or 'selected' in a:self.current[self.control[1]]=a.get('value','');self.chosen=True
    def handle_data(self, text):
        if self.current is not None and self.control and self.control[0]=='textarea':self.current[self.control[1]]+=text
    def handle_endtag(self, tag):
        if tag=='form':self.current=None
        if tag in ('select','textarea'):self.control=None
    def form(self, id=None):
        return next(f for f in self.forms if (id is None and 'aktion_token' in f) or f['_id']==id).copy()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*args):return None


@unittest.skipUnless(os.getenv('EABLAGE_TEST_DB_PORT'), 'Disposable local MariaDB required')
class DocumentLinksHTTP(unittest.TestCase):
    def test_document_and_case_workflows(self):
        config=dict(host='127.0.0.1',port=int(os.environ['EABLAGE_TEST_DB_PORT']),user='root',password=os.getenv('EABLAGE_TEST_DB_PASSWORD',''),autocommit=True)
        db=mysql.connector.connect(**config);cursor=db.cursor(dictionary=True)
        name='eablage_links_http_'+uuid.uuid4().hex[:10]
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');cursor.execute(f'USE `{name}`')
        def rows(sql,params=()):cursor.execute(sql,params);return cursor.fetchall()
        with tempfile.TemporaryDirectory() as directory:
            work=Path(directory);web=work/'web';copy_web(web)
            for folder in ['archive','meta','inbox']: (work/folder).mkdir()
            setup=work/'setup.php'
            setup.write_text("<?php\n$db=new PDO('mysql:host=127.0.0.1;port="+str(config['port'])+';dbname='+name+";charset=utf8mb4','root',getenv('EABLAGE_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);\n"+f"require {json.dumps(str(ROOT/'web/datenbank_wiederherstellung.php'))};\n"+'foreach ($argv as $i=>$path) { if($i===0)continue;foreach(eablage_restore_statements($path) as $sql)$db->exec($sql);}')
            subprocess.run(['php',str(setup),str(ROOT/'sql/schema.sql'),str(ROOT/'sql/seed.sql')],check=True,capture_output=True)
            config_php=setup.read_text().split('require ')[0]
            for key,value in {'MYSQL_DATENBANK':name,'MYSQL_HOST':'127.0.0.1','MYSQL_BENUTZER':'root','MYSQL_KENNWORT':'','PFAD_ARCHIV':str(work/'archive')+'/','PFAD_META':str(work/'meta')+'/','PFAD_INBOX':str(work/'inbox')+'/','DB_TABELLE_ARCHIV':'dokumente','DOKID_STARTWERT':10001}.items():
                config_php+=f'define({json.dumps(key)},{json.dumps(value)});\n'
            config_php+='$db_config=$db;\n'
            (web/'konfiguration.php').write_text(config_php)
            cursor.execute("INSERT INTO dokumente (DokID,Datum,Partner,Betreff,Doktext,Nummer,kategorie_id,kategorie2_id,doktyp_id,Originaldateiname,DateiSHA256,Aenderungsdatum) VALUES (10001,'2026-01-01','Heizungsfirma','Heizung Rechnung','Rechnung','', '0','0','10','test.pdf',REPEAT('a',64),'2026-01-02 00:00:00'),(10002,'2026-02-01','Versicherung','Vertrag','Vertrag','', '0','0','40','vertrag.pdf',REPEAT('b',64),NULL)")
            original_meta=b'{"unchanged":true}\n';(work/'meta/10001meta.json').write_bytes(original_meta)
            calls=[];failure={'enabled':False}
            class MetadataHandler(BaseHTTPRequestHandler):
                def log_message(self,*args):pass
                def do_POST(self):
                    data=json.loads(self.rfile.read(int(self.headers['Content-Length'])));calls.append(self.path)
                    if failure['enabled']:
                        self.send_response(503);self.end_headers();self.wfile.write(b'{}');return
                    doc=data['document'];content=serialize_metadata(doc)
                    body=json.dumps({'schema_version':4,'filename':str(doc['DokID'])+'meta.json','content':content}).encode()
                    self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
            api=ThreadingHTTPServer(('127.0.0.1',0),MetadataHandler);thread=threading.Thread(target=api.serve_forever,daemon=True);thread.start()
            with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
            log=open(work/'php.log','w+')
            server=subprocess.Popen(['php','-d','session.save_path='+str(work),'-S',f'127.0.0.1:{port}','-t',str(web)],env={**os.environ,'BACKEND_URL':f'http://127.0.0.1:{api.server_port}'},stdout=log,stderr=log)
            opener=build_opener(HTTPCookieProcessor(CookieJar()),NoRedirect());base=f'http://127.0.0.1:{port}'
            def request(path,data=None):
                req=Request(base+'/'+path.lstrip('/'),data=urlencode(data).encode() if data is not None else None)
                try:response=opener.open(req,timeout=10)
                except HTTPError as error:response=error
                return response.status,response.read().decode(),response.headers
            try:
                for _ in range(60):
                    try:request('/vorgaenge.php');break
                    except OSError:time.sleep(.05)
                status,html,_=request('/vorgaenge.php');self.assertEqual(status,200)
                token=Forms(html).form()['aktion_token']
                self.assertEqual(request('/vorgang_anlegen.php')[0],405)
                self.assertEqual(request('/vorgang_anlegen.php',{'name':'blocked'})[0],403)
                status,create_html,headers=request('/vorgaenge.php',{'aktion_token':token,'action':'create','name':'Heizung 2026','description':'<script>danger</script>'})
                self.assertEqual(status,302,create_html);case_a=int(urlsplit(headers['Location']).query.split('=')[1])
                status,html,_=request(headers['Location']);self.assertEqual(status,200);self.assertIn('&lt;script&gt;danger&lt;/script&gt;',html)
                status,body,_=request('/vorgang_anlegen.php',{'aktion_token':token,'name':'Steuer 2026','description':''})
                self.assertEqual(status,200);case_b=json.loads(body)['id']
                # Multiple links via the document editor, without contacting metadata API.
                status,html,_=request('/bearbeiten.php?dokid=10001&return=vorgaenge.php%3Fid%3D'+str(case_a));self.assertEqual(status,200)
                form=Forms(html).form('edit-document-form');stale=form.copy()
                form.update(vorgaenge_json=json.dumps([case_a,case_b]),action='save')
                before=rows('SELECT * FROM dokumente WHERE DokID=10001')
                status,html,_=request('/bearbeiten.php',form);self.assertEqual(status,302,html)
                self.assertEqual(rows('SELECT * FROM dokumente WHERE DokID=10001'),before)
                self.assertEqual((work/'meta/10001meta.json').read_bytes(),original_meta);self.assertEqual(calls,[])
                self.assertEqual(len(rows('SELECT * FROM dokument_vorgang WHERE DokumentID=10001')),2)
                stale.update(vorgaenge_json='[]',action='save');status,html,_=request('/bearbeiten.php',stale)
                self.assertIn('zwischenzeitlich geändert',html);self.assertEqual(len(rows('SELECT * FROM dokument_vorgang WHERE DokumentID=10001')),2)
                # Metadata failure rolls back the attempted link changes as well.
                status,html,_=request('/bearbeiten.php?dokid=10001');failed=Forms(html).form('edit-document-form')
                failed.update(action='save',Betreff='Changed',vorgaenge_json='[]');failure['enabled']=True
                status,html,_=request('/bearbeiten.php',failed);failure['enabled']=False
                self.assertIn('Die Änderungen konnten nicht gespeichert werden.',html)
                self.assertEqual(calls,['/metadata/prepare'])
                self.assertEqual(len(rows('SELECT * FROM dokument_vorgang WHERE DokumentID=10001')),2)
                self.assertEqual(rows('SELECT * FROM dokumente WHERE DokID=10001'),before)
                self.assertEqual((work/'meta/10001meta.json').read_bytes(),original_meta)
                # Attach from case, filter in the existing search, unlink without deletion.
                self.assertEqual(request('/vorgaenge.php',{'aktion_token':token,'id':case_a,'document_id':10002,'action':'attach'})[0],302)
                status,html,_=request('/dokumente.php?Vorgang='+str(case_b));self.assertEqual(status,200);self.assertIn('Heizung Rechnung',html);self.assertNotIn('>Vertrag</span></a>',html)
                status,html,_=request('/vorgaenge.php?id='+str(case_a)+'&document_q=Versicherung');self.assertEqual(status,200);self.assertIn('Zuordnung entfernen',html)
                self.assertEqual(request('/vorgaenge.php',{'aktion_token':token,'id':case_a,'document_id':10002,'action':'detach'})[0],302)
                self.assertEqual(len(rows('SELECT * FROM dokumente')),2)
                # Archive an inbox PDF with multiple links through the real save transaction.
                source=work/'inbox/new.pdf';source.write_bytes(b'%PDF-1.4\nfixture\n%%EOF\n')
                status,html,_=request('/speichern.php',{'aktion_token':token,'original_datei':'new.pdf','source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'Datum':'2026-03-01','Kategorie':'0','Kategorie2':'0','Doktyp':'10','Partner':'Firma','Betreff':'Neu','numbers_json':'[]','vorgaenge_json':json.dumps([case_a,case_b]),'dokument_text':'Rechnung'})
                self.assertEqual(status,302,html)
                new_id=rows('SELECT MAX(DokID) AS id FROM dokumente')[0]['id']
                self.assertEqual(len(rows('SELECT * FROM dokument_vorgang WHERE DokumentID=%s',(new_id,))),2)
                self.assertNotIn('vorgaenge',json.loads((work/f'meta/{new_id}meta.json').read_text()))
                # Rename preserves links; stale update is rejected; delete preserves all documents.
                status,html,_=request('/vorgaenge.php?id='+str(case_a));f=Forms(html).form();f.update(action='update',name='Heizung neu',description='Beschreibung')
                self.assertEqual(request('/vorgaenge.php',f)[0],302)
                self.assertIn('zwischenzeitlich geändert',request('/vorgaenge.php',f)[1])
                status,html,_=request('/vorgaenge.php?id='+str(case_a));f=Forms(html).form();f['action']='delete'
                self.assertEqual(request('/vorgaenge.php',f)[0],302)
                self.assertEqual(len(rows('SELECT * FROM dokumente')),3)
                self.assertEqual(rows('SELECT * FROM dokument_vorgang WHERE VorgangID=%s',(case_a,)),[])
                self.assertEqual(len(rows('SELECT * FROM dokument_vorgang WHERE VorgangID=%s',(case_b,))),2)
                self.assertEqual(request('/vorgaenge.php?id='+str(case_a))[0],404)
                # Verify sorting of all filtered results before LIMIT/OFFSET with real MariaDB.
                cursor.executemany(
                    'INSERT INTO vorgang (Bezeichnung,Beschreibung,ErstelltAm) VALUES (%s,%s,%s)',
                    [(f'Testreihe {index:02d}', 'Suchfilter', f'2026-01-01 12:00:{(index + 17) % 52:02d}')
                     for index in range(52)]
                )
                for order in ('name', 'name-desc', 'date', 'date-asc', 'invalid'):
                    expected = list(range(52))
                    if order in ('date', 'date-asc'):
                        expected.sort(key=lambda index: (index + 17) % 52)
                    if order in ('name-desc', 'date'):
                        expected.reverse()
                    for page, expected_rows in ((1, expected[:50]), (2, expected[50:])):
                        status, html, _ = request('/vorgaenge.php?' + urlencode({
                            'q': 'Suchfilter', 'sort': order, 'page': page
                        }))
                        self.assertEqual(status, 200)
                        self.assertEqual(
                            [int(index) for index in re.findall(r'<strong>Testreihe (\d+)</strong>', html)],
                            expected_rows, (order, page)
                        )
                log.flush();log.seek(0);self.assertNotIn('Fatal error',log.read())
            finally:
                server.terminate();server.wait(timeout=10);api.shutdown();api.server_close();log.close()
                cursor.execute(f'DROP DATABASE `{name}`');db.close()

if __name__=='__main__':unittest.main()
