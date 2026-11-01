<?php
// Actual view/controller with synthetic read-only PDO data; no NAS or backend.
$directory = sys_get_temp_dir() . '/eablage-view-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    foreach (['ansehen.php', 'dokumentvorschau.php', 'dokument_navigation.php', 'nummern_funktionen.php', 'icons.php', 'navbar.php', 'app.css', 'dokumentvorschau.js'] as $file) {
        copy(__DIR__ . '/../web/' . $file, $directory . '/' . $file);
    }
    $fixture = file_get_contents(__DIR__ . '/fixtures/ui_data.php');
    $fixture .= "\n";
    // Reject accidental writes in the page or a future shared loader.
    $fixture .= '$db_config = new class extends UiDatabase {
        public function prepare(string $query, array $options = []): PDOStatement|false {
            if (!str_starts_with(ltrim($query), "SELECT ")) throw new RuntimeException("Unexpected write");
            return parent::prepare($query, $options);
        }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            if (!str_starts_with(ltrim($query), "SELECT ")) throw new RuntimeException("Unexpected write");
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
    };';
    file_put_contents($directory . '/konfiguration.php', $fixture);
    file_put_contents($directory . '/run.php', '<?php
        $_GET=json_decode($argv[1],true); $_SERVER["REQUEST_METHOD"]=$argv[2];
        $_SERVER["REQUEST_URI"]="ansehen.php?".http_build_query($_GET);
        ob_start(); register_shutdown_function(function() {
            $html=ob_get_clean(); echo json_encode(["status"=>http_response_code() ?: 200,"html"=>$html]);
        });
        require __DIR__."/ansehen.php";');
    $render = function(array $params, string $method = 'GET') use ($directory): array {
        $process = proc_open([PHP_BINARY, $directory . '/run.php', json_encode($params), $method], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException($errors . $output);
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    };
    $check = function(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
    $search = 'dokumente.php?Suchbegriff=Haus%20%26%20Hof&Kategorie=1&page=2';
    $response=$render(['dokid'=>'1','return'=>$search]);
    $check($response['status']===200,'View failed');
    $html=$response['html'];
    $check(str_contains($html, 'Müller &amp; Söhne'),'Partner missing');
    $check(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'),'Unescaped subject');
    $check(str_contains($html, 'Kundennummer') && str_contains($html, '0012345'),'Additional number missing');
    $check(str_contains($html, 'Heizung 2026'),'Linked case missing');
    $check(!preg_match('/<(input|textarea|select|form)\b/i',$html),'Editing controls in read-only view');
    $check(str_contains($html, 'href="'.htmlspecialchars($search,ENT_QUOTES,'UTF-8').'"'),'Search context lost');
    $view='ansehen.php?'.http_build_query(['dokid'=>1,'return'=>$search]);
    $edit='bearbeiten.php?'.http_build_query(['dokid'=>1,'return'=>$view]);
    $check(str_contains($html, 'href="'.htmlspecialchars($edit,ENT_QUOTES,'UTF-8').'"'),'Editing return lost');
    foreach ([[],['dokid'=>'0'],['dokid'=>'-1'],['dokid'=>'abc'],['dokid'=>['1']],['dokid'=>'2147483648']] as $params) {
        $check($render($params)['status']===400,'Invalid ID accepted');
    }
    $check($render(['dokid'=>'999'])['status']===404,'Missing document accepted');
    $check($render(['dokid'=>'1'],'POST')['status']===405,'POST accepted');
    $check($render(['dokid'=>'1'],'HEAD')['status']===200,'HEAD failed');
    foreach (['https://example.com','//example.com','../dokumente.php',"dokumente.php\r\nX-Test: bad",'ansehen.php?dokid=1'] as $url) {
        $check(str_contains($render(['dokid'=>'1','return'=>$url])['html'],'href="dokumente.php"'),'Unsafe return accepted');
    }
    echo "Document view: PASS\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
