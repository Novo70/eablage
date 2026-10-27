"""Run only against an explicitly selected disposable local MariaDB.

EABLAGE_TEST_DB_PORT=3307 python tests/document_numbers_integration.py
Requires PHP with PDO MySQL and mbstring. Creates and drops its own database.
"""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import uuid
from unittest.mock import patch
import mysql.connector

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from app.services import autokat, master_data
from app.services.metadata_service import REQUIRED_FIELDS, serialize_metadata


def main():
    if not os.getenv('EABLAGE_TEST_DB_PORT'):
        print('Document numbers: SKIP (EABLAGE_TEST_DB_PORT required)')
        return
    config = dict(host='127.0.0.1',port=int(os.environ['EABLAGE_TEST_DB_PORT']),user='root',
                  password=os.getenv('EABLAGE_TEST_DB_PASSWORD',''),autocommit=True,use_pure=True)
    db = mysql.connector.connect(**config)
    cursor = db.cursor()
    name = 'eablage_numbers_' + uuid.uuid4().hex[:12]
    cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
    cursor.execute(f'USE `{name}`')
    def script(sql):
        sql='\n'.join(line for line in sql.splitlines() if not line.lstrip().startswith('--'))
        for statement in sql.split(';'):
            if statement.strip(): cursor.execute(statement)
    def query(sql):
        cursor.execute(sql)
        return cursor.fetchall()
    try:
        schema=(ROOT/'sql/schema.sql').read_text()
        script(schema.split('-- Vorher vollständige Datenbanksicherung')[0])
        cursor.execute('ALTER TABLE dokumente DROP INDEX uq_dokumente_dokid')
        cursor.execute("INSERT INTO dokumente (DokID,Nummer,Datum) VALUES (10001,'AB-123/45','2026-01-01')")
        cursor.execute("INSERT INTO autokat (RegelID,Regeltyp,Suchbegriffe,prNum,NumLänge) VALUES ('R0001','K','Rechnung','Kunde:',5)")
        with tempfile.TemporaryDirectory() as directory:
            php=Path(directory)/'check.php'
            def run_php(body):
                php.write_text('<?php\n'+f"$db=new PDO('mysql:host=127.0.0.1;port={config['port']};dbname={name};charset=utf8mb4','root',getenv('EABLAGE_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);\n"+
                    f"require {json.dumps(str(ROOT/'web/nummern_funktionen.php'))};\nrequire {json.dumps(str(ROOT/'web/datenbank_sicherung.php'))};\nrequire {json.dumps(str(ROOT/'web/datenbank_wiederherstellung.php'))};\n"+body)
                result=subprocess.run(['php',str(php)],capture_output=True,text=True,check=True)
                assert not result.stderr,result.stderr
                return result.stdout
            legacy_dir=Path(directory)/'legacy'
            legacy=run_php(f"$b=eablage_create_database_backup($db,'test',{json.dumps(str(legacy_dir))});echo {json.dumps(str(legacy_dir))}.'/'.$b['filename'];")
            migration=(ROOT/'sql/update_document_numbers.sql').read_text()
            script(migration)
            assert query('SELECT NummernartID,Wert,Suchwert FROM dokument_nummer')==[('1','AB-123/45','AB12345')]
            assert query('SELECT Startanker,Endmodus,MaxLaenge FROM autokat_nummer')==[('Kunde:','legacy',5)]
            script(migration)
            assert query('SELECT COUNT(*) FROM dokument_nummer')==[(1,)]
            cursor.execute("INSERT INTO nummernart (id,Bezeichnung,Erlaeuterung) VALUES ('2','Vertragsnummer','Einzelner Vertrag')")
            cursor.execute("INSERT INTO autokat_nummer (AutoKatID,NummernartID,Startanker) VALUES (1,'2','Vertrag:')")
            config['database']=name
            with patch.object(autokat,'get_connection',side_effect=lambda:mysql.connector.connect(**config)):
                result=autokat.analyze_autokat('Rechnung Kunde: 0001\nVertrag: V-2/3')
            assert [(n['kind_id'],n['value']) for n in result['numbers']]==[('1','0001'),('2','V-2/3')]
            with patch.object(master_data,'get_connection',side_effect=lambda:mysql.connector.connect(**config)):
                entries=master_data.list_entries('number_types',name)['items']
                assert len(entries)==2
                try: master_data.mutate('number_types',name,'deactivate','2')
                except master_data.MasterDataError: pass
                else: raise AssertionError('Referenced number type was deactivated')
            run_php("""
                $db->beginTransaction();
                $numbers=eablage_nummern_pruefen($db,'[{"kind_id":"2","value":"V-2/3"},{"kind_id":"1","value":"0001"}]');
                eablage_nummern_speichern($db,10001,$numbers);$db->commit();
                if (count(eablage_nummern_laden($db,10001))!==2) throw new Exception('Missing numbers');
                $db->beginTransaction();
                try {eablage_nummern_pruefen($db,'[{"kind_id":"1","value":"a"},{"kind_id":"1","value":"b"}]');throw new Exception('Duplicate accepted');}
                catch (RuntimeException $e) {} finally {$db->rollBack();}
                $db->beginTransaction();eablage_nummern_speichern($db,10001,[]);$db->rollBack();
                if(count(eablage_nummern_laden($db,10001))!==2)throw new Exception('Rollback failed');
            """)
            assert query('SELECT Nummer FROM dokumente')==[('0001',)]
            current_dir=Path(directory)/'current'
            current=run_php(f"$b=eablage_create_database_backup($db,'test',{json.dumps(str(current_dir))});echo {json.dumps(str(current_dir))}.'/'.$b['filename'];")
            cursor.execute('DELETE FROM dokumente')
            run_php(f"eablage_restore_database_unlocked($db,{json.dumps(current)});")
            assert query('SELECT Wert FROM dokument_nummer ORDER BY Sortierung')==[('0001',),('V-2/3',)]
            cursor.execute("UPDATE nummernart SET Sortierung=5 WHERE id='2'")
            prepared=json.loads(run_php("""
                $db->beginTransaction();
                $numbers=eablage_nummern_pruefen($db,'[{"kind_id":"1","value":"0001"},{"kind_id":"2","value":"V-2/3"}]');
                eablage_nummern_speichern($db,10001,$numbers);$db->commit();
                echo json_encode($numbers);
            """))
            assert [(n['kind_id'],n['position']) for n in prepared]==[('2',10),('1',20)]
            assert query('SELECT Nummer FROM dokumente')==[('V-2/3',)]
            document=dict.fromkeys(REQUIRED_FIELDS)
            document.update(DokID=10001,Nummer=query('SELECT Nummer FROM dokumente')[0][0],numbers=prepared)
            metadata=json.loads(serialize_metadata(document))
            assert metadata['number']==metadata['numbers'][0]['value']=='V-2/3'
            assert query('SELECT NummernartID,Sortierung FROM dokument_nummer ORDER BY Sortierung')==[('2',10),('1',20)]
            cursor.execute("DELETE FROM dokument_nummer WHERE NummernartID='1'")
            script(migration)
            assert query("SELECT COUNT(*) FROM dokument_nummer WHERE NummernartID='1'")==[(0,)]
            cursor.execute('DELETE FROM dokumente')
            run_php(f"eablage_restore_database_unlocked($db,{json.dumps(legacy)});")
            assert query('SELECT Wert,Suchwert FROM dokument_nummer')==[('AB-123/45','AB12345')]
            print('Document numbers: migration, rules, PHP writes, rollback, duplicate rejection, master data, typed and legacy restore OK')
    finally:
        cursor.execute(f'DROP DATABASE `{name}`')
        db.close()


if __name__=='__main__': main()
