<?php
// Real SQL cleanup, lookup guards, backup and restore; disposable localhost DB only.
require_once __DIR__ . '/../web/stammdaten_funktionen.php';
require_once __DIR__ . '/../web/datenbank_sicherung.php';
require_once __DIR__ . '/../web/datenbank_wiederherstellung.php';
require_once __DIR__ . '/web_fixture.php';
if (!getenv('EABLAGE_TEST_DB_PORT')) { echo "Empty master data: SKIP (EABLAGE_TEST_DB_PORT required)\n"; exit; }
$db = new PDO('mysql:host=127.0.0.1;port=' . (int)getenv('EABLAGE_TEST_DB_PORT') . ';charset=utf8mb4',
    'root', getenv('EABLAGE_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'eablage_empty_master_' . bin2hex(random_bytes(6));
$dir = sys_get_temp_dir() . '/' . $name;
mkdir($dir);
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$name`");
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$script = static function(string $path) use ($db): void {
    foreach (eablage_restore_statements($path) as $sql) $db->exec($sql);
};
$rows = static fn(string $table): array => $db->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
$noPlaceholders = static function() use ($db, $check): void {
    foreach (['kategorie', 'doktyp'] as $table) {
        $check((int)$db->query("SELECT COUNT(*) FROM `$table` WHERE id='0'")->fetchColumn() === 0, 'Placeholder survived: ' . $table);
        $check((int)$db->query("SELECT COUNT(*) FROM `$table` WHERE id='99'")->fetchColumn() === 1, 'Other empty-label row removed');
    }
};
try {
    $script(__DIR__ . '/../sql/schema.sql');
    $db->exec("INSERT INTO kategorie (id,katBez,Aktiv) VALUES ('0','',0),('10','Kategorie',1),('99','',1)");
    $db->exec("INSERT INTO doktyp (id,doktypBez,Aktiv) VALUES ('0',NULL,0),('10','Dokumenttyp',1),('99','',1)");
    $db->exec("INSERT INTO dokumente (DokID,Datum,kategorie_id,kategorie2_id,doktyp_id) VALUES
        (10001,'2026-01-01','0',NULL,'0'),(10002,'2026-01-02','10','0','10')");
    $db->exec("INSERT INTO autokat (RegelID,Regeltyp,kat,kat2,dok) VALUES
        ('R0001','K','0','0','0'),('R0002','E','10','0','10'),('R0003','E',NULL,'',NULL)");
    $documents = $rows('dokumente'); $rules = $rows('autokat');
    // Even before cleanup, the UI's own empty choice must not become '(inaktiv)'.
    $check(eablage_stammdaten_liste($db, 'kategorie')['0'] === '', 'Legacy category changed empty choice');
    $check(eablage_stammdaten_liste($db, 'doktyp')['0'] === '', 'Legacy type changed empty choice');
    $legacy = eablage_create_database_backup($db, $name, $dir . '/legacy');
    $script(__DIR__ . '/../sql/update_empty_master_data.sql');
    $noPlaceholders();
    $categories = $rows('kategorie'); $types = $rows('doktyp');
    $script(__DIR__ . '/../sql/update_empty_master_data.sql');
    $check($categories === $rows('kategorie') && $types === $rows('doktyp'), 'Cleanup is not repeatable');
    $check($documents === $rows('dokumente') && $rules === $rows('autokat'), 'Assignments or rules changed');
    $db->beginTransaction();
    eablage_pruefe_zuordnungen($db, ['kategorie_id'=>'0','kategorie2_id'=>'0','doktyp_id'=>'0']);
    eablage_pruefe_zuordnungen($db, ['kategorie_id'=>'10','kategorie2_id'=>'0','doktyp_id'=>'10']);
    $db->rollBack();
    $check(eablage_stammdaten_liste($db, 'kategorie', true)['0'] === '', 'Empty category choice missing');
    $check(eablage_stammdaten_liste($db, 'doktyp', true)['10'] === 'Dokumenttyp', 'Real type missing');
    $current = eablage_create_database_backup($db, $name, $dir . '/current');
    foreach ([$legacy, $current] as $backup) {
        $db->exec('DELETE FROM dokumente');
        eablage_restore_database($db, $backup['path']);
        $noPlaceholders();
        $check($documents === $rows('dokumente') && $rules === $rows('autokat'), 'Restore changed documents/rules');
        $check($categories === $rows('kategorie') && $types === $rows('doktyp'), 'Restore changed real master data');
    }
    // Do not silently delete unexpectedly named rows, even under the reserved ID.
    $db->exec("INSERT INTO kategorie (id,katBez) VALUES ('0','Unerwarteter Name')");
    $db->exec("INSERT INTO doktyp (id,doktypBez) VALUES ('0','Unerwarteter Name')");
    $script(__DIR__ . '/../sql/update_empty_master_data.sql');
    $check((int)$db->query("SELECT COUNT(*) FROM kategorie WHERE id='0'")->fetchColumn() === 1, 'Named ID-0 row deleted');
    $check((int)$db->query("SELECT COUNT(*) FROM doktyp WHERE id='0'")->fetchColumn() === 1, 'Named ID-0 type deleted');
    echo "Empty master data: cleanup, zero assignments, rule preservation, lookup and old/new restore PASS\n";
} finally {
    $db->exec("DROP DATABASE `$name`");
    eablage_test_remove_tree($dir);
}
