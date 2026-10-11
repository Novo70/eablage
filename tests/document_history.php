<?php
require_once __DIR__ . '/../web/protokoll_funktionen.php';
require_once __DIR__ . '/../web/papierkorb_funktionen.php';
define('MYSQL_DATENBANK', 'history_test');

function check_history(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

// Transactional test double exercises the real write helper and trash workflow.
class HistoryDatabase extends PDO {
    public bool $transaction = false;
    public bool $failHistory = false;
    public bool $trashed = false;
    public array $events = [];
    public string|false $policy = false;
    public bool $deleted = false;
    public bool $failCleanup = false;
    private array $snapshot = [];
    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool {
        $this->snapshot = [$this->trashed, $this->events, $this->deleted]; $this->transaction = true; return true;
    }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool {
        [$this->trashed, $this->events, $this->deleted] = $this->snapshot; $this->transaction = false; return true;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new HistoryStatement($this, $query);
    }
}
class HistoryStatement extends PDOStatement {
    private int $affected = 0;
    private mixed $value = false;
    public function __construct(private HistoryDatabase $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->sql, 'INSERT INTO ereignisprotokoll')) {
            if ($this->db->failHistory) throw new RuntimeException('Simulierter Protokollfehler');
            $this->db->events[] = $params; $this->affected = 1;
        } elseif (str_starts_with($this->sql, 'SELECT param_value')) {
            $this->value = $this->db->policy;
        } elseif (str_starts_with($this->sql, 'SELECT GET_LOCK') || str_starts_with($this->sql, 'SELECT RELEASE_LOCK')) {
            $this->value = 1;
        } elseif (str_starts_with($this->sql, 'SELECT d.DateiSHA256')) {
            $this->value = $this->db->deleted ? false : ['DateiSHA256'=>null, 'Originaldateiname'=>'secret.pdf', 'Partner'=>'Private partner'];
        } elseif (str_starts_with($this->sql, 'UPDATE ereignisprotokoll')) {
            if ($this->db->failCleanup) throw new RuntimeException('Simulierter Bereinigungsfehler');
            foreach ($this->db->events as &$event) if ($event[0] === $params[0]) $event[4] = '{}';
            unset($event);
        } elseif (str_starts_with($this->sql, 'INSERT INTO parameter')) {
            // ID reservation is unchanged by the retention policy.
        } elseif (str_starts_with($this->sql, 'DELETE FROM dokumente')) {
            $this->affected = $this->db->trashed && !$this->db->deleted ? 1 : 0;
            $this->db->deleted = true;
        } elseif (str_starts_with($this->sql, 'UPDATE dokumente')) {
            $trash = str_contains($this->sql, 'SET GeloeschtAm = NOW()');
            $this->affected = $this->db->trashed !== $trash ? 1 : 0;
            $this->db->trashed = $trash;
        } else { throw new RuntimeException('Unerwartetes SQL: ' . $this->sql); }
        return true;
    }
    public function rowCount(): int { return $this->affected; }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->value; }
}

$db = new HistoryDatabase();
$rejected = false;
try { eablage_protokoll($db, 42, 'archiviert'); } catch (LogicException $e) { $rejected = true; }
check_history($rejected && !$db->events, 'Schreiben außerhalb einer Transaktion möglich');
$recognition = eablage_protokoll_klassifizierung('AutoKat:E[R0012/v1/r20,R0013/v2/r30];K[R0042/v3/r70] | Textvergleich:99 (98%)');
check_history($recognition['erkennung'] === 'AutoKat + Textvergleich', 'Erkennung enthält redundante Regelcodes');
check_history(eablage_protokoll_klassifizierung('')['erkennung'] === 'Manuell', 'Manuelle Erkennung falsch');
check_history(count($recognition['regeln']) === 3, 'Regeltreffer fehlen');
check_history($recognition['regeln'][2] === ['typ'=>'K','regel_id'=>'R0042','version'=>3,'rang'=>70], 'Regelreferenz falsch');
check_history($recognition['vergleich_dokid'] === 99, 'Vergleichs-DokID fehlt');
$snapshot = eablage_protokoll_dokument([
    'Datum'=>'2026-10-09', 'Partner'=>'SV-Versicherung', 'Betreff'=>'Versicherungsschein',
    'Nummer'=>'12345', 'Originaldateiname'=>'versicherung.pdf',
    'kategorie_id'=>'K1', 'kategorie_bezeichnung'=>'Versicherung',
    'kategorie2_id'=>null, 'doktyp_id'=>'D1', 'doktyp_bezeichnung'=>'Versicherungsschein',
    'Doktext'=>'Geheimer Text', 'Info'=>'Altbestand', 'DateiSHA256'=>'abc',
]);
check_history($snapshot['Originaldateiname'] === 'versicherung.pdf', 'Dateiname fehlt beim Löschen');
check_history($snapshot['kategorie_id'] === ['id'=>'K1','bezeichnung'=>'Versicherung'], 'Kategoriebezeichnung fehlt');
check_history($snapshot['doktyp_id']['bezeichnung'] === 'Versicherungsschein', 'Dokumenttypbezeichnung fehlt');
check_history(!array_intersect(['Doktext','Info','DateiSHA256','kategorie2_id'], array_keys($snapshot)), 'Snapshot enthält unnötige Daten');
$legacy = ['klassifizierung'=>['erkennung'=>'AutoKat:E[R0012/v1/r20]', 'regeln'=>$recognition['regeln']], 'originaldateiname'=>'alt.pdf'];
$display = eablage_protokoll_anzeige($legacy, 'archiviert');
check_history($display['klassifizierung']['erkennung'] === 'AutoKat' && !isset($display['originaldateiname']), 'Altbestand redundant dargestellt');
check_history($display['klassifizierung']['regeln'] === $recognition['regeln'] && $legacy['originaldateiname'] === 'alt.pdf', 'Altbestand verändert');
check_history(eablage_protokoll_anzeige(['dokument'=>$snapshot], 'endgueltig_geloescht')['dokument'] === $snapshot, 'Löschdetails ausgeblendet');
$changes = eablage_protokoll_aenderungen(['Partner'=>'Alt', 'Info'=>'Alttext', 'Doktext'=>'A'], ['Partner'=>'Neu', 'Info'=>'Anderer Text', 'Doktext'=>'B']);
check_history($changes === ['Partner'=>['vorher'=>'Alt','nachher'=>'Neu']], 'Änderungsdetails enthalten Info oder Dokumenttext');
$db->beginTransaction();
eablage_protokoll($db, 42, 'archiviert', ['klassifizierung'=>$recognition], '2026-10-09 07:00:00');
check_history($db->inTransaction(), 'Protokollfunktion hat selbst committed');
check_history($db->events[0][1] === '2026-10-09 07:00:00', 'Gemeinsamer Zeitpunkt fehlt');
check_history(json_decode($db->events[0][4], true)['klassifizierung'] === $recognition, 'JSON-Details beschädigt');
$db->rollBack();
check_history(!$db->events, 'Rollback erhält Ereignisse');
eablage_papierkorb_aendern($db, 42, 'trash');
check_history($db->trashed && $db->events[0][2] === 'papierkorb', 'Papierkorb nicht gemeinsam protokolliert');
$db->failHistory = true;
$rejected = false;
try { eablage_papierkorb_aendern($db, 42, 'restore'); } catch (RuntimeException $e) { $rejected = true; }
check_history($rejected && $db->trashed && count($db->events) === 1, 'Fehlerhafter Protokolleintrag lässt Dokumentänderung bestehen');
$db->failHistory = false;
eablage_papierkorb_aendern($db, 42, 'restore');
check_history(!$db->trashed && $db->events[1][2] === 'wiederhergestellt', 'Wiederherstellung nicht protokolliert');
check_history(eablage_protokoll_loeschdaten_behalten($db), 'Fehlender Parameter verändert Standard');
foreach (['ja'=>true, 'nein'=>false, ' NEIN '=>false] as $value=>$expected) {
    $db->policy = $value;
    check_history(eablage_protokoll_loeschdaten_behalten($db) === $expected, 'Parameter falsch gelesen');
}
$db->policy = 'invalid';
$rejected = false;
try { eablage_protokoll_loeschdaten_behalten($db); } catch (RuntimeException $e) { $rejected = true; }
check_history($rejected, 'Ungültige Einstellung akzeptiert');
$rejected = false;
try { eablage_protokoll_details_bereinigen($db, 42); } catch (LogicException $e) { $rejected = true; }
check_history($rejected, 'Bereinigung ohne Transaktion möglich');

// Exercise the complete purge, including file staging and rollback of old details.
foreach (['retain', 'clear', 'cleanup-failure', 'insert-failure', 'changed-policy'] as $scenario) {
    $db = new HistoryDatabase();
    $db->policy = $scenario === 'retain' ? 'ja' : 'nein';
    $db->trashed = true;
    $db->beginTransaction();
    eablage_protokoll($db, 42, 'metadaten_geaendert', ['felder'=>['Betreff'=>['vorher'=>'Secret', 'nachher'=>'Private']]]);
    eablage_protokoll($db, 99, 'archiviert', ['other'=>'Keep']);
    $db->commit();
    $before = $db->events;
    $root = sys_get_temp_dir() . '/eablage-history-' . bin2hex(random_bytes(8));
    mkdir($root); mkdir($root . '/archiv'); mkdir($root . '/meta');
    $pdf = $root . '/archiv/42dok.pdf'; $json = $root . '/meta/42meta.json';
    file_put_contents($pdf, 'original PDF bytes'); file_put_contents($json, '{"partner":"Private"}');
    $db->failCleanup = $scenario === 'cleanup-failure';
    $db->failHistory = $scenario === 'insert-failure';
    $failed = false;
    try {
        eablage_papierkorb_endgueltig($db, 42, $root . '/archiv', $root . '/meta', $scenario === 'retain' || $scenario === 'changed-policy');
    } catch (RuntimeException $e) { $failed = true; }
    if (in_array($scenario, ['cleanup-failure', 'insert-failure', 'changed-policy'], true)) {
        check_history($failed && !$db->deleted && $db->events === $before, 'Fehler erhält nicht Dokument und Details: ' . $scenario);
        check_history(file_get_contents($pdf) === 'original PDF bytes' && file_get_contents($json) === '{"partner":"Private"}', 'Dateien bei Fehler nicht zurückgenommen');
        unlink($pdf); unlink($json);
    } else {
        check_history(!$failed && $db->deleted && !file_exists($pdf) && !file_exists($json), 'Endgültige Löschung fehlgeschlagen');
        check_history($db->events[1] === $before[1], 'Fremdes Dokument bereinigt');
        check_history($db->events[2][2] === 'endgueltig_geloescht', 'Löschereignis fehlt');
        if ($scenario === 'clear') {
            check_history($db->events[0][4] === '{}' && $db->events[2][4] === '{}', 'Alte oder neue Details bleiben erhalten');
            check_history(array_slice($db->events[0], 0, 4) === array_slice($before[0], 0, 4), 'Ereignisidentität verändert');
        } else {
            check_history($db->events[0] === $before[0] && json_decode($db->events[2][4], true)['dokument']['Originaldateiname'] === 'secret.pdf', 'Aufbewahrung beschädigt');
        }
    }
    check_history(!glob($root . '/archiv/.eablage-delete-*') && !glob($root . '/meta/.eablage-delete-*'), 'Temporäre Dateien bleiben zurück');
    rmdir($root . '/archiv'); rmdir($root . '/meta'); rmdir($root);
}
echo "Dokumenthistorie: Parameter, vollständige Löschung, Detailbereinigung und Fehler-Rollback OK\n";
