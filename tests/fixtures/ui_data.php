<?php
// Synthetic display data only. No connection to a database, backend or NAS.
define('MYSQL_DATENBANK', 'ui_test');
define('DB_TABELLE_ARCHIV', 'dokumente');
class UiStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    private array $params = [];
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
    public function fetchColumn(int $column = 0): mixed {
        if (str_contains($this->sql, 'protokollLoeschdatenBehalten')) return 'nein';
        if (str_contains($this->sql, 'SELECT param_value FROM parameter WHERE param_key = ?')) return false;
        return (int)($_GET['fixture_count'] ?? 2);
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (str_contains($this->sql, 'SELECT * FROM autokat')) return $this->fetchAll()[0];
        if (str_contains($this->sql, 'SELECT d.*')) return ($this->params[0] ?? 0) === 1 ? ['DokID'=>1,'Datum'=>'2026-10-26','Partner'=>'Müller & Söhne','Betreff'=>'Gebäudeversicherung <script>alert(1)</script>','Kategorie'=>'Haus & Garten','Kategorie2'=>'Versicherungen','Dokumenttyp'=>'Rechnung','Tags'=>'Haus, Versicherung','Nummer'=>'AB-123/45','Doktext'=>'Erkannter Text <script>','Originaldateiname'=>'Versicherung.pdf','DateiSHA256'=>str_repeat('a',64),'Archivierungsdatum'=>'2026-10-27 12:34:56','Aenderungsdatum'=>null,'Aufbewahrungsdauer'=>10,'Info'=>'Manuell'] : false;
        return ['ID'=>1,'Bezeichnung'=>'Heizung 2026','Beschreibung'=>'Angebot und Rechnung','Anzahl'=>2];
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if (str_contains($this->sql, 'FROM ereignisprotokoll')) return (int)($_GET['fixture_count'] ?? 2) === 0 ? [] : array_map(fn($id)=>[
            'ID'=>$id,'DokID'=>1,'Zeitpunkt'=>'2026-11-10 12:00:00','Ereignistyp'=>'metadaten_geaendert','Quelle'=>'Web',
            'vorhanden'=>1,'GeloeschtAm'=>null,'Details'=>json_encode(['felder'=>['Partner'=>['vorher'=>'Alt <script>','nachher'=>'Muster Versicherung']]])
        ],[2,1]);
        if (str_contains($this->sql, 'SELECT RegelID FROM autokat')) return ['R0001','R0002'];
        if (str_contains($this->sql, 'FROM autokat_nummer')) return [];
        if (str_contains($this->sql, 'FROM autokat')) return array_map(fn($id)=>[
            'id'=>$id,'RegelID'=>'R000'.$id,'Version'=>2,'Rang'=>$id*10,'Regeltyp'=>'K','Aktiv'=>$id===1?1:0,
            'Bezeichnung'=>'Gebäudeversicherung '.$id,'Suchbegriffe'=>'Gebäude;Versicherung','Ausschlussbegriffe'=>'Entwurf',
            'kat'=>'1','kat2'=>'0','dok'=>'1','partner'=>'Muster Versicherung','betreff'=>'Beitrag','nr'=>'','tags'=>'Haus',
            'Kommentar'=>'','Treffer'=>5,'LetzterTreffer'=>'2026-11-01 12:00:00','ErstelltAm'=>'2026-10-01 12:00:00'
        ],[1,2]);
        if (str_contains($this->sql, 'FROM dokument_nummer n JOIN nummernart')) return [['kind_id'=>'1','label'=>'Vertragsnummer','value'=>'AB-123/45','position'=>10],['kind_id'=>'2','label'=>'Kundennummer','value'=>'0012345','position'=>20]];
        if (str_contains($this->sql, 'SELECT v.ID,v.Bezeichnung')) return [['ID'=>1,'Bezeichnung'=>'Heizung 2026']];
        if (str_contains($this->sql, 'SELECT ID,Bezeichnung FROM vorgang')) return $mode === PDO::FETCH_KEY_PAIR ? [1=>'Heizung 2026'] : [['ID'=>1,'Bezeichnung'=>'Heizung 2026']];
        if (str_contains($this->sql, 'SELECT v.*')) return [['ID'=>1,'Bezeichnung'=>'Heizung 2026','Beschreibung'=>'Angebot und Rechnung','ErstelltAm'=>'2026-11-10 12:30:00','Anzahl'=>2]];
        if (str_contains($this->sql, 'FROM nummernart')) return [['id'=>'1','Bezeichnung'=>'Vertragsnummer','Aktiv'=>1,'Sortierung'=>10]];
        if (str_contains($this->sql, 'parameter')) return [
            ['param_key'=>'ollamaAktiv','param_value'=>'ja','beschreibung'=>'Lokale KI für Klassifizierung verwenden','gruppierung'=>'Lokale KI','rang'=>10],
            ['param_key'=>'ollamaAnweisung','param_value'=>'Ordne Dokumente anhand ihres Inhalts zu.','beschreibung'=>'Fachliche Hinweise zur Klassifizierung','gruppierung'=>'Lokale KI','rang'=>20],
            ['param_key'=>'1_expVerzeichnisEbene1','param_value'=>'Kategorie','beschreibung'=>'Erste Verzeichnisebene für den Export','gruppierung'=>'Export','rang'=>10]
        ];
        if (str_contains($this->sql, 'WHERE GeloeschtAm IS NOT NULL')) return [
            ['DokID'=>2,'Datum'=>'2026-10-20','Partner'=>'Musterstadt','Betreff'=>str_repeat('LangerBetreffOhneTrennzeichen', 6),'GeloeschtAm'=>'2026-11-01 12:00:00']
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
function eablage_backend_url() { return 'http://fixture.invalid'; }
function eablage_backend_request($method, $url, ...$args) {
    if (($args[0]['action'] ?? '') === 'create') return ['ok'=>false,'http_status'=>422,'data'=>['detail'=>['message'=>'Bezeichnung bereits vorhanden.']]];
    if ($url === '/archive/audit') return ['ok'=>true, 'http_status'=>200, 'data'=>[
        'documents'=>2, 'checked_at'=>'2026-11-10', 'issues'=>[], 'repairable'=>0,
        'archive_path'=>'/test/pdf', 'meta_path'=>'/test/meta'
    ]];
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

function eablage_analysis_post($url, $payload, $timeout) { return ['ok'=>true,'data'=>['matched'=>false,'partner'=>'Beispielpartner','sources'=>[]]]; }
