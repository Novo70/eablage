"""Explicit local-only integration test; creates/drops uniquely named test databases.
Run with a disposable MariaDB on 127.0.0.1 and EABLAGE_TEST_DB_PORT set.
Needs mysql-connector-python and PHP 8.3 with pdo_mysql; PHP_BIN can select PHP.
"""
import json
import http.cookiejar
import re
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import uuid
import shutil
import socket
import time
import urllib.parse
import urllib.request
import sys
import threading
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import mysql.connector

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from fastapi import FastAPI
from fastapi.testclient import TestClient
from app.metadata_api import router


@contextmanager
def metadata_backend():
    app = FastAPI()
    app.include_router(router)
    failure = {'enabled': False}
    with TestClient(app) as client:
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_POST(self):
                payload = self.rfile.read(int(self.headers['Content-Length']))
                if failure['enabled']:
                    self.send_response(503)
                    self.end_headers()
                    self.wfile.write(b'{"detail":"test backend unavailable"}')
                    return
                response = client.post(self.path, content=payload, headers={'Content-Type': 'application/json'})
                self.send_response(response.status_code)
                self.end_headers()
                self.wfile.write(response.content)
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            yield f'http://127.0.0.1:{server.server_port}', failure
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)

port = int(os.environ['EABLAGE_TEST_DB_PORT'])
php = os.environ.get('PHP_BIN', 'php')
connection = mysql.connector.connect(host='127.0.0.1', port=port, user='root', password=os.environ.get('EABLAGE_TEST_DB_PASSWORD', ''), autocommit=True)
cursor = connection.cursor()
name = 'eablage_regression_' + uuid.uuid4().hex[:12]
restore = name + '_restore'
created = []

try:
    for db in (name, restore):
        cursor.execute(f'CREATE DATABASE `{db}` CHARACTER SET utf8mb4')
        created.append(db)
    cursor.execute(f'USE `{name}`')
    for statement in (ROOT/'sql/schema.sql').read_text().split(';'):
        if statement.strip(): cursor.execute(statement)
    with tempfile.TemporaryDirectory(prefix='eablage-regression-') as temporary, metadata_backend() as (backend_url, failure):
        base = Path(temporary)
        for folder in ('inbox','archiv','meta','backup'): (base/folder).mkdir()
        configuration = "<?php\n"
        configuration += f"define('MYSQL_DATENBANK', '{name}');\n"
        configuration += "$db_config = new PDO(" + json.dumps(f'mysql:host=127.0.0.1;port={port};dbname={name};charset=utf8mb4') + ", 'root', getenv('EABLAGE_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);\n"
        configuration += "$config=[]; define('DB_TABELLE_ARCHIV','dokumente'); define('DOKID_STARTWERT',10000);\n"
        configuration += "putenv('BACKEND_URL=" + backend_url + "');\n"
        for constant,folder in [('PFAD_INBOX','inbox'),('PFAD_ARCHIV','archiv'),('PFAD_META','meta')]:
            configuration += f"define('{constant}', " + json.dumps(str(base/folder)+'/') + ");\n"
        (base/'konfiguration.php').write_text(configuration)
        runner = base/'save.php'
        runner.write_text("<?php require " + json.dumps(str(ROOT/'web/csrf.php')) + "; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['aktion_token'=>eablage_aktion_token(),'original_datei'=>$argv[1], 'Datum'=>'2026-09-13','Partner'=>$argv[1],'dokument_text'=>'Text']; require " + json.dumps(str(ROOT/'web/speichern.php')) + ';')
        def save(filename):
            return subprocess.Popen([php,str(runner),filename],cwd=base,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        def rows():
            cursor.execute('SELECT DokID,Partner FROM dokumente ORDER BY DokID')
            return cursor.fetchall()
        # Existing archive target must remain untouched, with source intact.
        source = base/'inbox/a.pdf'; source.write_bytes(b'%PDF-source-A')
        failure['enabled'] = True
        p=save('a.pdf'); p.communicate(timeout=20)
        assert rows()==[] and source.exists() and not list((base/'meta').iterdir()) and not list((base/'archiv').iterdir())
        failure['enabled'] = False
        print('Backend failure before archive writes: PASS')
        target = base/'archiv/10000dok.pdf'; target.write_bytes(b'%PDF-existing')
        p=save('a.pdf'); p.communicate(timeout=20)
        assert rows()==[] and target.read_bytes()==b'%PDF-existing' and source.read_bytes()==b'%PDF-source-A'
        target.unlink()
        print('Existing archive conflict: PASS')
        # JSON conflict: retain the foreign file and the inbox source.
        meta=base/'meta/10000meta.json'; meta.write_text('existing metadata')
        p=save('a.pdf'); p.communicate(timeout=20)
        assert rows()==[] and not target.exists() and source.exists() and meta.read_text()=='existing metadata'
        meta.unlink()
        print('Metadata failure rollback: PASS')
        # Simulate failure after the INSERT/PDF/JSON but before COMMIT.
        cursor.execute("INSERT INTO autokat (RegelID, Regeltyp) VALUES ('R9999','K')")
        cursor.execute("CREATE TRIGGER fail_rule BEFORE UPDATE ON autokat FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'")
        fail_runner=base/'save_late_failure.php'
        fail_runner.write_text("<?php require " + json.dumps(str(ROOT/'web/csrf.php')) + "; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['aktion_token'=>eablage_aktion_token(),'original_datei'=>'a.pdf','Datum'=>'2026-09-13','erkennungs_info'=>'AutoKat:R9999']; require " + json.dumps(str(ROOT/'web/speichern.php')) + ';')
        subprocess.run([php,str(fail_runner)],cwd=base,capture_output=True,text=True,timeout=20)
        assert rows()==[] and source.exists() and not target.exists() and not meta.exists()
        cursor.execute('DROP TRIGGER fail_rule'); cursor.execute('DELETE FROM autokat')
        print('Late archive failure rollback: PASS')
        cursor.execute("CREATE TRIGGER fail_history BEFORE INSERT ON ereignisprotokoll FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test history failure'")
        p=save('a.pdf'); p.communicate(timeout=20)
        assert rows()==[] and source.exists() and not target.exists() and not meta.exists()
        cursor.execute('DROP TRIGGER fail_history')
        print('History failure rolls back archive and files: PASS')
        # Failure before PDF creation must never remove an existing target.
        target.write_bytes(b'%PDF-existing')
        cursor.execute("CREATE TRIGGER fail_insert BEFORE INSERT ON dokumente FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'")
        p=save('a.pdf'); p.communicate(timeout=20)
        assert rows()==[] and target.read_bytes()==b'%PDF-existing' and source.read_bytes()==b'%PDF-source-A'
        cursor.execute('DROP TRIGGER fail_insert'); target.unlink()
        print('INSERT failure ownership: PASS')
        # Two simultaneous successful requests preserve both PDFs and IDs.
        (base/'inbox/b.pdf').write_bytes(b'%PDF-source-B')
        processes=[save('a.pdf'),save('b.pdf')]
        for p in processes:
            out,err=p.communicate(timeout=25)
            assert p.returncode==0,(out,err)
        documents=rows()
        assert len(documents)==2 and len({r[0] for r in documents})==2,documents
        for dokid,partner in documents:
            expected=b'%PDF-source-A' if partner=='a.pdf' else b'%PDF-source-B'
            assert (base/f'archiv/{dokid}dok.pdf').read_bytes()==expected
            metadata=json.loads((base/f'meta/{dokid}meta.json').read_text())
            cursor.execute('SELECT Archivierungsdatum, Info, Originaldateiname, DateiSHA256, Aenderungsdatum FROM dokumente WHERE DokID=%s',(dokid,))
            archived, info, original_name, reference_hash, modified=cursor.fetchone()
            assert metadata['archived_at']==archived.isoformat(timespec='seconds')
            assert 'info' not in metadata and info is None and metadata['document_id']==dokid
            assert metadata['schema_version']==3
            assert metadata['original_filename']==original_name==partner
            assert metadata['pdf_sha256']==reference_hash==hashlib.sha256(expected).hexdigest()
            assert metadata['modified_at'] is None and modified is None
            assert not (base/f'meta/{dokid}meta.txt').exists()
        assert not list((base/'inbox').iterdir())
        print('Parallel archive requests: PASS')
        # Exercise the real POST parsing in bearbeiten.php via PHP's HTTP server.
        web=base/'web'; web.mkdir()
        for file in (ROOT/'web').glob('*.php'): shutil.copy2(file,web/file.name)
        shutil.copy2(base/'konfiguration.php',web/'konfiguration.php')
        shutil.copy2(ROOT/'web/app.css',web/'app.css')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1',0)); http_port=listener.getsockname()[1]
        server=subprocess.Popen([php,'-S',f'127.0.0.1:{http_port}','-t',str(web)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(50):
                with socket.socket() as probe:
                    if probe.connect_ex(('127.0.0.1',http_port))==0: break
                time.sleep(.05)
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            with opener.open(f'http://127.0.0.1:{http_port}/bearbeiten.php?dokid={documents[0][0]}') as response:
                initial = response.read().decode()
            token = re.search(r'name="aktion_token" value="([a-f0-9]+)"', initial).group(1)
            initial_revision = re.search(r'name="document_revision" value="([a-f0-9]+)"', initial).group(1)
            with opener.open(f'http://127.0.0.1:{http_port}/archiv_pdf.php?dokid={documents[0][0]}') as response:
                assert response.read() == (base/f'archiv/{documents[0][0]}dok.pdf').read_bytes()
            with opener.open(f'http://127.0.0.1:{http_port}/dokumente.php') as response:
                search_html = response.read().decode()
                assert 'archiv_pdf.php?dokid=' in search_html and 'Fatal error' not in search_html
            print('PDF display and search without Dateipfad: PASS')
            data=urllib.parse.urlencode({'aktion_token':token,'document_revision':initial_revision,'dokid':documents[0][0],'action':'reload_text','Datum':'2026-09-13','Partner':'Ungespeicherter Partner','Betreff':'Neuer Betreff','reclassification_info':'AutoKat:R0001'}).encode()
            request=urllib.request.Request(f'http://127.0.0.1:{http_port}/bearbeiten.php',data=data)
            with opener.open(request) as response: html=response.read().decode()
            assert 'value="Ungespeicherter Partner"' in html and 'value="Neuer Betreff"' in html, html[:5000]
            assert 'value="AutoKat:R0001"' in html
            assert rows()==documents
            print('Reload text preserves unsaved fields: PASS')
            dokid=documents[0][0]
            json_path=base/f'meta/{dokid}meta.json'
            old_json=json_path.read_bytes()
            legacy_path=base/f'meta/{dokid}meta.txt'
            legacy_path.write_bytes(b'unchanged legacy bytes')
            def edit(partner, revision=None, action='save'):
                if revision is None:
                    with opener.open(f'http://127.0.0.1:{http_port}/bearbeiten.php?dokid={dokid}') as response:
                        current_html = response.read().decode()
                    revision = re.search(r'name="document_revision" value="([a-f0-9]+)"', current_html).group(1)
                data=urllib.parse.urlencode({'aktion_token':token,'document_revision':revision,'dokid':dokid,'action':action,'Datum':'2026-09-13',
                                            'Kategorie':'0','Kategorie2':'0','Doktyp':'0','Partner':partner,
                                            'pending_document_text':'Neue Zeile\r\nUmlaute äöü'}).encode()
                with opener.open(urllib.request.Request(f'http://127.0.0.1:{http_port}/bearbeiten.php',data=data)) as response:
                    return response.read().decode()
            failure['enabled']=True
            edit('Nicht speichern')
            assert rows()==documents and json_path.read_bytes()==old_json
            assert legacy_path.read_bytes()==b'unchanged legacy bytes'
            failure['enabled']=False
            cursor.execute("CREATE TRIGGER fail_edit BEFORE UPDATE ON dokumente FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'")
            edit('SQL-Fehler')
            assert rows()==documents and json_path.read_bytes()==old_json
            cursor.execute('DROP TRIGGER fail_edit')
            cursor.execute("CREATE TRIGGER fail_history BEFORE INSERT ON ereignisprotokoll FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test history failure'")
            edit('Protokollfehler')
            assert rows()==documents and json_path.read_bytes()==old_json
            cursor.execute('DROP TRIGGER fail_history')
            # Future JSON formats must not be overwritten by metadata edits.
            json_path.write_text('{"schema_version":99}')
            edit('Unbekannte Version')
            assert rows()==documents and json_path.read_text()=='{"schema_version":99}'
            # A supported schema-1 file is upgraded only on actual change.
            legacy_json=json.loads(old_json)
            legacy_json['schema_version']=1
            legacy_json['info']='Historischer Text'
            for key in ('original_filename', 'pdf_sha256', 'modified_at'): del legacy_json[key]
            json_path.write_text(json.dumps(legacy_json))
            cursor.execute('UPDATE dokumente SET Info=%s WHERE DokID=%s', ('Altbestand unverändert', dokid))
            edit('Geändert')
            cursor.execute('SELECT Partner,Doktext,Info,Archivierungsdatum,Originaldateiname,DateiSHA256,Aenderungsdatum FROM dokumente WHERE DokID=%s',(dokid,))
            partner,text,info,archived,original_name,reference_hash,modified=cursor.fetchone()
            metadata=json.loads(json_path.read_text())
            assert partner=='Geändert' and metadata['partner']==partner
            assert metadata['document_text']==text and 'info' not in metadata
            assert info == 'Altbestand unverändert'
            assert metadata['archived_at']==archived.isoformat(timespec='seconds')
            before=json.loads(old_json)
            assert metadata['schema_version']==3
            assert metadata['original_filename']==original_name==before['original_filename']
            assert metadata['pdf_sha256']==reference_hash==before['pdf_sha256']
            assert metadata['modified_at']==modified.isoformat(timespec='seconds')
            cursor.execute("SELECT Details FROM ereignisprotokoll WHERE DokID=%s AND Ereignistyp='metadaten_geaendert' ORDER BY ID DESC LIMIT 1", (dokid,))
            event=json.loads(cursor.fetchone()[0])
            assert event['felder']['Partner']['nachher']=='Geändert'
            assert event['dokumenttext_geaendert'] is True
            saved_bytes=json_path.read_bytes()
            saved_rows=rows()
            for stale_action in ('save', 'reclassify', 'reload_text'):
                stale_html=edit('Altes Fenster', initial_revision, stale_action)
                assert 'zwischenzeitlich geändert' in stale_html
                assert 'value="Altes Fenster"' in stale_html
                assert f'name="document_revision" value="{initial_revision}"' in stale_html
                assert rows()==saved_rows and json_path.read_bytes()==saved_bytes
            # Reposting the failed form cannot silently acquire a fresh revision.
            repeated=edit('Nochmals altes Fenster', initial_revision)
            assert 'zwischenzeitlich geändert' in repeated and rows()==saved_rows
            missing=edit('Ohne Revision', '')
            assert 'Formular ist veraltet' in missing and rows()==saved_rows
            print('Two-tab conflict, repeated stale POST and helper actions: PASS')
            # A failing trigger proves that a no-op does not issue UPDATE.
            cursor.execute("CREATE TRIGGER fail_noop BEFORE UPDATE ON dokumente FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='no-op must not update'")
            no_op_html=edit('Geändert')
            cursor.execute('DROP TRIGGER fail_noop')
            assert 'Die Änderungen wurden gespeichert.' in no_op_html
            assert json_path.read_bytes()==saved_bytes
            cursor.execute('SELECT Aenderungsdatum FROM dokumente WHERE DokID=%s',(dokid,))
            assert cursor.fetchone()[0]==modified
            assert legacy_path.read_bytes()==b'unchanged legacy bytes'
            # An edit of a legacy-only document creates JSON without touching TXT.
            json_path.unlink()
            edit('Legacy aktualisiert')
            assert json.loads(json_path.read_text())['partner']=='Legacy aktualisiert'
            assert legacy_path.read_bytes()==b'unchanged legacy bytes'
            assert not list((base/'meta').glob('.eablage-meta-*'))
            documents=rows()
            print('Edit JSON, backend/SQL failure and legacy preservation: PASS')
        finally:
            server.terminate(); server.wait(timeout=5)
        # Dump and restore through the actual backup helper.
        backup_runner=base/'backup.php'
        backup_runner.write_text("<?php require 'konfiguration.php'; require " + json.dumps(str(ROOT/'web/datenbank_sicherung.php')) + "; echo json_encode(eablage_create_database_backup($db_config,MYSQL_DATENBANK," + json.dumps(str(base/'backup')) + '));')
        result=subprocess.run([php,str(backup_runner)],cwd=base,capture_output=True,text=True,check=True)
        backup=json.loads(result.stdout)
        cursor.execute('SELECT COUNT(*) FROM ereignisprotokoll')
        event_count=cursor.fetchone()[0]
        assert backup['tables']==6 and backup['rows']==2+event_count,backup
        # Let PDO execute the generated SQL, preserving quoted strings/semicolons.
        restore_runner=base/'restore.php'
        restore_runner.write_text("<?php require 'konfiguration.php'; $db_config->exec('USE " + restore + "'); $db_config->exec(file_get_contents(" + json.dumps(backup['path']) + '));')
        subprocess.run([php,str(restore_runner)],cwd=base,capture_output=True,text=True,check=True)
        cursor.execute(f'SELECT DokID,Partner FROM `{restore}`.dokumente ORDER BY DokID')
        assert cursor.fetchall()==documents
        cursor.execute(f'SELECT COUNT(*) FROM `{restore}`.ereignisprotokoll')
        assert cursor.fetchone()[0]==event_count
        print('SQL backup/restore including history: PASS')
        cursor.execute("SHOW COLUMNS FROM dokumente LIKE 'Dateipfad'")
        assert not cursor.fetchall()
        trash_runner = base/'trash.php'
        trash_runner.write_text("<?php require 'konfiguration.php'; require " + json.dumps(str(ROOT/'web/papierkorb_funktionen.php'))
                                + "; $id=(int)$argv[1]; eablage_papierkorb_aendern($db_config,$id,'trash');"
                                + "eablage_papierkorb_aendern($db_config,$id,'restore');"
                                + "eablage_papierkorb_aendern($db_config,$id,'trash');"
                                + "eablage_papierkorb_endgueltig($db_config,$id,PFAD_ARCHIV,PFAD_META);")
        removed_id = documents[0][0]
        cursor.execute('SELECT Datum, Partner, Betreff, Originaldateiname FROM dokumente WHERE DokID=%s', (removed_id,))
        deleted_document = cursor.fetchone()
        subprocess.run([php,str(trash_runner),str(removed_id)],cwd=base,capture_output=True,text=True,check=True)
        assert not (base/f'archiv/{removed_id}dok.pdf').exists()
        assert not (base/f'meta/{removed_id}meta.json').exists()
        assert all(row[0] != removed_id for row in rows())
        cursor.execute('SELECT Ereignistyp FROM ereignisprotokoll WHERE DokID=%s ORDER BY ID DESC LIMIT 4', (removed_id,))
        assert [r[0] for r in cursor.fetchall()]==['endgueltig_geloescht','papierkorb','wiederhergestellt','papierkorb']
        cursor.execute("SELECT Details FROM ereignisprotokoll WHERE DokID=%s AND Ereignistyp='endgueltig_geloescht'", (removed_id,))
        deleted_details = json.loads(cursor.fetchone()[0])['dokument']
        assert deleted_details['Originaldateiname'] == deleted_document[3]
        assert deleted_details.get('Partner') == (deleted_document[1] or None)
        assert deleted_details.get('Betreff') == (deleted_document[2] or None)
        assert 'Doktext' not in deleted_details and 'Info' not in deleted_details
        print('Trash, restore and permanent delete preserve history and document identity: PASS')
        cursor.execute('SELECT ID, DokID, Zeitpunkt, Ereignistyp, Quelle, Details FROM ereignisprotokoll WHERE DokID=%s ORDER BY ID', (removed_id,))
        retained_events = cursor.fetchall()
        cleared_id = rows()[0][0]
        cursor.execute("INSERT INTO parameter (param_key,param_value,beschreibung,gruppierung,rang,anzeige) VALUES ('protokollLoeschdatenBehalten','nein','Test','Archiv','20','ja') ON DUPLICATE KEY UPDATE param_value='nein'")
        cursor.execute('SELECT ID, DokID, Zeitpunkt, Ereignistyp, Quelle FROM ereignisprotokoll WHERE DokID=%s ORDER BY ID', (cleared_id,))
        previous_events = cursor.fetchall()
        subprocess.run([php,str(trash_runner),str(cleared_id)],cwd=base,capture_output=True,text=True,check=True)
        assert all(row[0] != cleared_id for row in rows())
        assert not (base/f'archiv/{cleared_id}dok.pdf').exists()
        assert not (base/f'meta/{cleared_id}meta.json').exists()
        cursor.execute('SELECT ID, DokID, Zeitpunkt, Ereignistyp, Quelle, Details FROM ereignisprotokoll WHERE DokID=%s ORDER BY ID', (cleared_id,))
        cleared_events = cursor.fetchall()
        assert [r[:5] for r in cleared_events[:len(previous_events)]] == previous_events
        assert cleared_events[-1][3] == 'endgueltig_geloescht'
        assert all(json.loads(r[5]) == {} for r in cleared_events)
        cursor.execute('SELECT ID, DokID, Zeitpunkt, Ereignistyp, Quelle, Details FROM ereignisprotokoll WHERE DokID=%s ORDER BY ID', (removed_id,))
        assert cursor.fetchall() == retained_events
        print('Disabled retention clears all old details and preserves other document history: PASS')
finally:
    for db in reversed(created): cursor.execute(f'DROP DATABASE `{db}`')
    connection.close()
