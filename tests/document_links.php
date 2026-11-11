<?php
/** Disposable MariaDB only: EABLAGE_TEST_DB_PORT=3317 php tests/document_links.php */
require_once __DIR__.'/../web/vorgaenge_funktionen.php';
require_once __DIR__.'/../web/datenbank_sicherung.php';
require_once __DIR__.'/../web/datenbank_wiederherstellung.php';
if (!getenv('EABLAGE_TEST_DB_PORT')) { echo "SKIP: EABLAGE_TEST_DB_PORT required\n"; exit; }
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}
$port=(int)getenv('EABLAGE_TEST_DB_PORT');
$db=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root',getenv('EABLAGE_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='eablage_links_'.bin2hex(random_bytes(6));
$dir=sys_get_temp_dir().'/'.$name;
mkdir($dir);
$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE `'.$name.'`');
function script(PDO $db,string $sql,string $dir): void {
    $path=$dir.'/schema.sql'; file_put_contents($path,$sql);
    foreach(eablage_restore_statements($path) as $statement) $db->exec($statement);
    unlink($path);
}
try {
    $schema=file_get_contents(__DIR__.'/../sql/schema.sql');
    [$legacy]=explode('-- Vorher Datenbank sichern; Web/Worker während der Migration stoppen.',$schema);
    script($db,$legacy,$dir);
    $db->exec("INSERT INTO dokumente (DokID,Datum,Partner,Betreff,Originaldateiname,DateiSHA256,Aenderungsdatum) VALUES (10001,'2026-01-01','Partner','Rechnung','test.pdf',REPEAT('a',64),'2026-01-02 00:00:00'),(10002,'2026-02-01','Partner','Vertrag','vertrag.pdf',REPEAT('b',64),NULL)");
    $legacyDir=$dir.'/legacy';
    $old=eablage_create_database_backup($db,$name,$legacyDir);
    $oldPath=$legacyDir.'/'.$old['filename'];
    file_put_contents($oldPath,str_replace('Version: '.EABLAGE_WEB_VERSION,'Version: 4.4.0',file_get_contents($oldPath)));
    $before=$db->query('SELECT * FROM dokumente ORDER BY DokID')->fetchAll(PDO::FETCH_ASSOC);
    $migration=file_get_contents(__DIR__.'/../sql/update_document_links.sql');
    check($migration===file_get_contents(__DIR__.'/../web/vorgaenge_schema.sql'),'Restore migration differs');
    script($db,$migration,$dir);script($db,$migration,$dir);
    $a=eablage_vorgang_anlegen($db,'Heizung 2026','Angebot und Rechnung');
    $b=eablage_vorgang_anlegen($db,'Steuererklärung 2026');
    rejects(fn()=>eablage_vorgang_anlegen($db,'   '),'Empty name accepted');
    rejects(fn()=>eablage_vorgang_anlegen($db,str_repeat('ä',256)),'Long name accepted');
    rejects(fn()=>eablage_vorgang_anlegen($db,'A',str_repeat('ä',10001)),'Long description accepted');
    foreach(['{}','null','[0]','[-1]','[true]','[1.5]','["1 OR 1"]','[2147483648]','['.$a.','.$a.']','[999999]'] as $raw) rejects(fn()=>eablage_vorgaenge_pruefen($db,$raw),'Invalid list accepted: '.$raw);
    rejects(fn()=>eablage_vorgaenge_speichern($db,10001,[$a]),'Write outside transaction');
    $db->beginTransaction();
    eablage_vorgaenge_speichern($db,10001,[$b,$a]);
    eablage_vorgaenge_speichern($db,10002,[$a]);$db->commit();
    check(eablage_vorgaenge_laden($db,10001)===[$a,$b],'Multiple links missing');
    check((int)$db->query("SELECT COUNT(*) FROM ereignisprotokoll WHERE Ereignistyp='vorgaenge_geaendert'")->fetchColumn()===2,'History missing');
    $db->beginTransaction();eablage_vorgaenge_speichern($db,10001,[$a,$b]);$db->commit();
    check((int)$db->query('SELECT COUNT(*) FROM ereignisprotokoll')->fetchColumn()===2,'No-op created event');
    $db->beginTransaction();eablage_vorgaenge_speichern($db,10001,[]);$db->rollBack();
    check(eablage_vorgaenge_laden($db,10001)===[$a,$b],'Rollback lost links');
    check($before===$db->query('SELECT * FROM dokumente ORDER BY DokID')->fetchAll(PDO::FETCH_ASSOC),'Document metadata changed');
    $db->exec('UPDATE dokumente SET GeloeschtAm=NOW() WHERE DokID=10001');
    check(eablage_vorgaenge_laden($db,10001)===[$a,$b],'Trash lost links');
    check((int)$db->query('SELECT COUNT(*) FROM dokument_vorgang dv JOIN dokumente d ON d.DokID=dv.DokumentID WHERE d.GeloeschtAm IS NULL')->fetchColumn()===1,'Trash included');
    $db->exec('UPDATE dokumente SET GeloeschtAm=NULL WHERE DokID=10001');
    $currentDir=$dir.'/current';$current=eablage_create_database_backup($db,$name,$currentDir);
    $currentPath=$currentDir.'/'.$current['filename'];
    check(str_starts_with(file_get_contents($currentPath),"-- e_ablage MariaDB-Datensicherung; Version: ".EABLAGE_WEB_VERSION."\n"),'Incorrect backup version');
    check(eablage_restore_validate($currentPath)['cases']===true,'Cases not recognized');
    // Reject partial link-table pairs before any import.
    $raw=file_get_contents($currentPath);
    $incomplete=preg_replace('/-- Tabelle: dokument_vorgang.*?(?=-- Tabelle:|SET FOREIGN_KEY_CHECKS=1;)/s','',$raw);
    $invalid=$dir.'/partial.sql';file_put_contents($invalid,$incomplete);
    rejects(fn()=>eablage_restore_validate($invalid),'Partial case backup accepted');unlink($invalid);
    $db->exec('DELETE FROM dokumente');
    check((int)$db->query('SELECT COUNT(*) FROM dokument_vorgang')->fetchColumn()===0,'Document cascade failed');
    eablage_restore_database_unlocked($db,$currentPath);
    check(eablage_vorgaenge_laden($db,10001)===[$a,$b],'Restore lost multiple links');
    check($before===$db->query('SELECT * FROM dokumente ORDER BY DokID')->fetchAll(PDO::FETCH_ASSOC),'Restore changed metadata');
    $db->exec('DELETE FROM vorgang WHERE ID='.$a);
    check(eablage_vorgaenge_laden($db,10001)===[$b] && eablage_vorgaenge_laden($db,10002)===[],'Case cascade failed');
    check((int)$db->query('SELECT COUNT(*) FROM dokumente')->fetchColumn()===2,'Case deleted documents');
    // Restore a 4.4.0 backup without cases into an empty installation with existing cases.
    $db->exec('DELETE FROM dokumente');
    eablage_restore_database_unlocked($db,$legacyDir.'/'.$old['filename']);
    check((int)$db->query('SELECT COUNT(*) FROM vorgang')->fetchColumn()===0,'Legacy restore retained unrelated cases');
    check((int)$db->query('SELECT COUNT(*) FROM dokument_vorgang')->fetchColumn()===0,'Legacy restore retained links');
    check((int)$db->query('SELECT COUNT(*) FROM dokumente')->fetchColumn()===2,'Legacy restore lost documents');
    echo "Document links: migration, validation, multiple assignments, rollback, history, trash, cascade, current and legacy restore OK\n";
} finally {
    if($db->inTransaction())$db->rollBack();
    $db->exec('DROP DATABASE `'.$name.'`');
    foreach (glob($dir.'/*') as $p) { if(is_dir($p)){foreach(glob($p.'/*')as$f)unlink($f);rmdir($p);}else unlink($p); }
    rmdir($dir);
}
