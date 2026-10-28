<?php
// Synthetic display data only. No connection to a database, backend or NAS.
define('MYSQL_DATENBANK', 'ui_test');
class UiStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool { return true; }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return (int)($_GET['fixture_count'] ?? 2); }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if (str_contains($this->sql, 'FROM nummernart')) return [['id'=>'1','Bezeichnung'=>'Vertragsnummer','Aktiv'=>1,'Sortierung'=>10]];
        if (str_contains($this->sql, 'parameter')) return [
            ['param_key'=>'ollamaAktiv','param_value'=>'ja','beschreibung'=>'Lokale KI für Klassifizierung verwenden','gruppierung'=>'Lokale KI','rang'=>10],
            ['param_key'=>'ollamaAnweisung','param_value'=>'Ordne Dokumente anhand ihres Inhalts zu.','beschreibung'=>'Fachliche Hinweise zur Klassifizierung','gruppierung'=>'Lokale KI','rang'=>20],
            ['param_key'=>'1_expVerzeichnisEbene1','param_value'=>'Kategorie','beschreibung'=>'Erste Verzeichnisebene für den Export','gruppierung'=>'Export','rang'=>10]
        ];
        return [
            ['DokID'=>1,'Datum'=>'2026-10-26','Partner'=>'Müller & Söhne','Betreff'=>'Jahresabrechnung Gebäudeversicherung','Nummer'=>'AB-123/45','katBez'=>'Haus & Garten','doktypBez'=>'Rechnung'],
            ['DokID'=>2,'Datum'=>'2026-10-20','Partner'=>'Stadtwerke Musterstadt','Betreff'=>str_repeat('LangerBetreffOhneTrennzeichen', 6),'Nummer'=>str_repeat('1234567890', 8),'katBez'=>'Energie und Versorgung','doktypBez'=>'Abrechnung']
        ];
    }
}
class UiDatabase extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new UiStatement($query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new UiStatement($query); }
}
$db_config = $db = new UiDatabase();
function eablage_lade_kategorien($db, ...$args) { return ['0'=>'Alle Kategorien','1'=>'Haus & Garten']; }
function eablage_lade_dokumenttypen($db, ...$args) { return ['0'=>'Alle Dokumententypen','1'=>'Rechnung']; }
function eablage_backend_request($method, $url, ...$args) {
    if (($args[0]['action'] ?? '') === 'create') return ['ok'=>false,'http_status'=>422,'data'=>['detail'=>['message'=>'Bezeichnung bereits vorhanden.']]];
    if ($url === '/export/plan') return ['ok'=>true,'http_status'=>200,'data'=>['profile'=>1,'directory_structure'=>true,'items'=>[
        ['dok_id'=>1,'directories'=>['Haus & Garten'],'filename'=>'Versicherung.pdf','display'=>['date'=>'2026-10-26','category'=>'Haus & Garten','type'=>'Rechnung','partner'=>'Müller & Söhne','subject'=>'Gebäudeversicherung','number'=>'AB-123/45']]
    ]]];
    return ['ok'=>true,'http_status'=>200,'data'=>['items'=>[
        ['sort_order'=>10,'id'=>'1','name'=>'Haus & Garten','active'=>1,'documents'=>2,'rules'=>[],'explanation'=>'Gebäudeversicherung und Hauskosten'],
        ['sort_order'=>20,'id'=>'2','name'=>str_repeat('LangerName', 8),'active'=>0,'documents'=>0,'rules'=>[],'explanation'=>'']
    ]]];
}
function eablage_source_badge(...$args) { return ''; }
function eablage_textcompare_badge(...$args) { return ''; }
