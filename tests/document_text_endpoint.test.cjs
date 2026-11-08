const {test,before,after}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const {spawnSync}=require('node:child_process');
let dir;
before(()=>{
    dir=fs.mkdtempSync(path.join(os.tmpdir(),'eablage-text-'));
    fs.copyFileSync(path.join(__dirname,'../web/dokument_text.php'),path.join(dir,'dokument_text.php'));
    fs.writeFileSync(path.join(dir,'konfiguration.php'),`<?php
    define('DB_TABELLE_ARCHIV','dokumente');
    class TextStatement extends PDOStatement {
        private array $params=[];
        public function __construct(private bool $activeOnly) {}
        public function execute(?array $params=null): bool { $this->params=$params; return true; }
        public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
            $id=$this->params[0];
            if ($id===5) throw new PDOException('Private database diagnostic');
            if ($id===1) return ['Doktext'=>'Müller & Söhne <script>literal</script>'];
            if ($id===2 && !$this->activeOnly) return ['Doktext'=>'Deleted document'];
            if ($id===3) return ['Doktext'=>null];
            return false;
        }
    }
    $db_config=new class extends PDO {
        public function __construct() {}
        public function prepare(string $query,array $options=[]): PDOStatement|false {
            if (!str_starts_with($query,'SELECT ')) throw new RuntimeException('Unexpected write');
            return new TextStatement(str_contains($query,'GeloeschtAm IS NULL'));
        }
    };`);
    fs.writeFileSync(path.join(dir,'run.php'),`<?php
    $_GET=json_decode($argv[1],true); $_SERVER['REQUEST_METHOD']=$argv[2];
    ini_set('log_errors','1'); ini_set('error_log',__DIR__.'/errors.log');
    ob_start(); register_shutdown_function(function(){ $body=ob_get_clean(); echo json_encode(['status'=>http_response_code() ?: 200,'body'=>$body]); });
    require __DIR__.'/dokument_text.php';`);
});
after(()=>{if(dir)fs.rmSync(dir,{recursive:true,force:true});});
function request(params,method='GET') {
    const r=spawnSync(process.env.PHP_BIN||'php',[path.join(dir,'run.php'),JSON.stringify(params),method],{encoding:'utf8'});
    assert.equal(r.status,0,r.stderr);assert.equal(r.stderr,'');return JSON.parse(r.stdout);
}
test('text endpoint returns literal stored text and an empty string for absent OCR text',()=>{
    const r=request({dokid:1});assert.equal(r.status,200);
    assert.equal(JSON.parse(r.body).text,'Müller & Söhne <script>literal</script>');
    assert.deepEqual(JSON.parse(request({dokid:3}).body),{text:''});
    assert.deepEqual(request({dokid:1},'HEAD'),{status:200,body:''});
});
test('text endpoint excludes deleted and missing documents, validates IDs and rejects writes',()=>{
    for(const params of [{},{dokid:0},{dokid:-1},{dokid:'abc'},{dokid:[1]},{dokid:2147483648}]) assert.equal(request(params).status,400);
    for(const id of [2,999]) {const r=request({dokid:id});assert.equal(r.status,404);assert.equal(JSON.parse(r.body).text,undefined);}
    assert.equal(request({dokid:1},'POST').status,405);
});
test('text endpoint hides database diagnostics on a server error',()=>{
    const r=request({dokid:5});assert.equal(r.status,500);
    assert.doesNotMatch(r.body,/Private database diagnostic/);
});
