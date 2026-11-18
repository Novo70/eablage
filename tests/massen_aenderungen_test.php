<?php
require_once __DIR__ . '/../web/massen_aenderungen.php';
function check($actual, $expected): void {
    if ($actual !== $expected) throw new RuntimeException('Erwartet ' . json_encode($expected) . ', erhalten ' . json_encode($actual));
}
$document = ['Partner'=>'Bank AG', 'Betreff'=>'Rechnung Bank AG 123', 'Tags'=>'Steuer, Haus', 'kategorie_id'=>'1', 'kategorie2_id'=>'0', 'doktyp_id'=>'2', 'numbers'=>[['kind_id'=>'1','value'=>'004711']]];
$ops = eablage_massen_operationen(['Partner'=>['mode'=>'set','value'=>'Bank'], 'Betreff'=>['mode'=>'replace','find'=>'Bank AG','value'=>'Bank'], 'Tags'=>['mode'=>'add','value'=>'Haus, Steuer 2026'], 'numbers'=>['mode'=>'move','kind'=>'1','target'=>'2','value'=>'004711']]);
$changed = eablage_massen_anwenden($document, $ops);
check($changed['Partner'], 'Bank');
check($changed['Betreff'], 'Rechnung Bank 123');
check($changed['Tags'], 'Steuer, Haus, Steuer 2026');
check($changed['numbers'], [['kind_id'=>'2','value'=>'004711']]);
check($changed['doktyp_id'], '2');
check(eablage_massen_anwenden($document, eablage_massen_operationen(['Tags'=>['mode'=>'remove','value'=>'Haus']]))['Tags'], 'Steuer');
check(eablage_massen_anwenden($document, eablage_massen_operationen(['Partner'=>['mode'=>'clear']]))['Partner'], '');
check(eablage_massen_anwenden($document, eablage_massen_operationen(['kategorie2_id'=>['mode'=>'clear']]))['kategorie2_id'], '0');
check(eablage_massen_anwenden($document, eablage_massen_operationen(['numbers'=>['mode'=>'set','kind'=>'2','value'=>'ABC']]))['numbers'][1]['value'], 'ABC');
check(eablage_massen_anwenden($document, eablage_massen_operationen(['numbers'=>['mode'=>'remove','kind'=>'1','value'=>'004711']]))['numbers'], []);
foreach (['wrong-value','occupied-target','empty-replace','empty-set'] as $case) {
    $failed = false;
    try {
        $input = $document;
        $operation = ['numbers'=>['mode'=>'move','kind'=>'1','target'=>'2','value'=>'004711']];
        if ($case === 'wrong-value') $operation['numbers']['value']='4711';
        if ($case === 'occupied-target') $input['numbers'][]=['kind_id'=>'2','value'=>'999'];
        if ($case === 'empty-replace') $operation=['Betreff'=>['mode'=>'replace','find'=>'','value'=>'x']];
        if ($case === 'empty-set') $operation=['Partner'=>['mode'=>'set','value'=>'']];
        eablage_massen_anwenden($input, eablage_massen_operationen($operation));
    } catch (RuntimeException $e) { $failed = true; }
    check($failed, true);
}
echo "Massenänderungen erfolgreich geprüft.\n";

// A stale preview must fail before metadata publication and roll back its transaction.
class TestDatabase extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:'); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE',' LOCK IN SHARE MODE'], '', $query), $options);
    }
}
define('DB_TABELLE_ARCHIV', 'dokumente');
define('PFAD_META', sys_get_temp_dir());
$db = new TestDatabase();
$db->exec('CREATE TABLE dokumente (DokID INTEGER, GeloeschtAm TEXT, Partner TEXT)');
$db->exec("INSERT INTO dokumente VALUES (1,NULL,'Neu')");
$db->exec('CREATE TABLE dokument_nummer (ID INTEGER, DokumentID INTEGER, NummernartID TEXT, Wert TEXT, Sortierung INTEGER)');
$db->exec('CREATE TABLE nummernart (id TEXT, Bezeichnung TEXT)');
$db->exec('CREATE TABLE dokument_vorgang (DokumentID INTEGER, VorgangID INTEGER)');
$before = array_merge($document, ['DokID'=>1, 'Datum'=>'2026-01-01', 'Doktext'=>'', 'Nummer'=>'004711', 'vorgaenge'=>[]]);
$after = array_replace($before, ['Partner'=>'Geplant']);
$failed = false;
try { eablage_dokument_speichern($db, $before, $after, document_revision($before)); }
catch (UnexpectedValueException $e) { $failed = true; }
check($failed, true);
check($db->inTransaction(), false);
check($db->query('SELECT Partner FROM dokumente')->fetchColumn(), 'Neu');
echo "Revisionskonflikt und Rollback erfolgreich geprüft.\n";
