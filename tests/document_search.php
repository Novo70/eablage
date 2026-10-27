<?php
// Nur gegen eine ausdrücklich freigegebene lokale Wegwerf-MariaDB ausführen:
// EABLAGE_TEST_DB_PORT=3307 php tests/document_search.php
if (!getenv('EABLAGE_TEST_DB_PORT')) {
    echo "Document search: SKIP (EABLAGE_TEST_DB_PORT required)\n";
    exit(0);
}
$dsn = 'mysql:host=127.0.0.1;port=' . (int)getenv('EABLAGE_TEST_DB_PORT') . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$db = new PDO($dsn, 'root', getenv('EABLAGE_TEST_DB_PASSWORD') ?: '', $options);
$name = 'eablage_search_' . bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/' . $name;
mkdir($directory);
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`");
    $db->exec('CREATE TABLE dokumente (DokID INT PRIMARY KEY, Datum DATE, Partner TEXT, Betreff TEXT,
        Nummer TEXT, Tags TEXT, Doktext TEXT, GeloeschtAm DATETIME NULL, doktyp_id INT, kategorie_id INT, kategorie2_id INT)');
    $db->exec('CREATE TABLE kategorie (id INT PRIMARY KEY, katBez TEXT)');
    $db->exec('CREATE TABLE doktyp (id INT PRIMARY KEY, doktypBez TEXT)');
    $db->exec('CREATE TABLE dokument_nummer (DokumentID INT, Wert VARCHAR(255), Suchwert VARCHAR(255))');
    $insertNumber=$db->prepare('INSERT INTO dokument_nummer VALUES (?,?,?)');
    $insert = $db->prepare('INSERT INTO dokumente VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $fixtures = [
        [1, '2026-01-01', 'Bank', '', 'AB12345', '', '', null, 1, 1, null],
        [2, '2026-01-02', 'Bank', '', 'ab-123/45', '', '', null, 1, 1, null],
        [3, '2026-01-03', 'Bank', '', 'AB.123 (45)', '', '', null, 1, 1, null],
        [4, '2026-01-04', '', 'AB-123/45', null, '', '', null, 2, 2, null],
        [5, '2026-01-05', '', 'AB12345', null, '', '', null, 2, 2, null],
        [6, '2026-01-06', '', '', null, '', 'AB-123/45', null, 2, 2, null],
        [7, '2026-01-07', 'Bank', '', 'AB12345', '', '', '2026-01-08', 1, 1, null],
        [8, '2026-01-08', '', '', '0012345', '', '', null, 1, 1, null],
        [9, '2026-01-09', '', '', '12345', '', '', null, 1, 1, null],
        [10, '2026-01-10', 'Bank', '', "AB\u{00A0}123\u{2011}45", '', '', null, 1, 2, 1],
    ];
    foreach ($fixtures as $row) { $insert->execute($row); if ($row[4]!==null) $insertNumber->execute([$row[0],$row[4],strtoupper(preg_replace('/[^a-zA-Z0-9]/','',$row[4]))]); }
    $insertNumber->execute([1,'AB12345','AB12345']);
    $insertNumber->execute([4,'VER98765','VER98765']);
    copy(__DIR__ . '/../web/dokumente.php', "$directory/dokumente.php");
    file_put_contents("$directory/app.css", '');
    file_put_contents("$directory/navbar.php", '');
    file_put_contents("$directory/funktionen.php", '<?php
        function eablage_lade_kategorien($db) { return []; }
        function eablage_lade_dokumenttypen($db) { return []; }');
    file_put_contents("$directory/konfiguration.php", '<?php $db_config = new PDO(' .
        var_export($dsn . ';dbname=' . $name, true) . ', "root", getenv("EABLAGE_TEST_DB_PASSWORD") ?: "", ' .
        var_export($options, true) . ');');
    file_put_contents("$directory/run.php", '<?php
        parse_str($argv[1], $_GET); $_REQUEST = $_GET; $_POST = [];
        $_SERVER["QUERY_STRING"] = $argv[1];
        chdir(__DIR__); require "dokumente.php";');

    $search = function (string $term, array $expected, array $filters = [], ?int $total = null) use ($directory): void {
        $query = http_build_query(['Suchbegriff' => $term] + $filters);
        $process = proc_open([PHP_BINARY, "$directory/run.php", $query],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException($errors);
        preg_match_all('/href="archiv_pdf\.php\?dokid=(\d+)"/', $html, $matches);
        $actual = array_map('intval', $matches[1]);
        sort($actual); sort($expected);
        if ($actual !== $expected) throw new RuntimeException($query . ': ' . json_encode($actual));
        $total = $total ?? count($expected);
        if (!str_contains($html, '<strong>' . $total . '</strong> Treffer gefunden')) {
            throw new RuntimeException('Incorrect count: ' . $query);
        }
    };
    $search('VER-987/65', [4]);
    $search('AB-123/45', [1, 2, 3, 4, 10]);
    $search('AB12345', [1, 2, 3, 5, 10]);
    $search('ab12345', [1, 2, 3, 5, 10]);
    $search('AB-123/45', [1, 2, 3, 4, 6, 10], ['Volltextsuche' => '1']);
    $search('AB12345', [1, 2, 3, 5, 10], ['Volltextsuche' => '1']);
    $search('Bank AB-123/45', [1, 2, 3, 10]);
    $search('AB 123 45', [1, 2, 3, 4, 5, 10]);
    $search('AB12345 unbekannt', []);
    $search('AB12345', [10], ['Kategorie' => '1', 'Dokumententyp' => '1', 'Datumvon' => '2026-01-10']);
    $search('AB12345', [1, 2], ['Datumbis' => '2026-01-02']);
    $search('00-123/45', [8]);
    $search('-', [2, 4]); // Kein leerer normalisierter Vergleich mit LIKE '%%'.
    $search('()', []);
    $search('AB%unbekannt', []);
    $search('AB_unbekannt', []);
    foreach (range(100, 154) as $id) {
        $date = (new DateTimeImmutable('2026-02-01'))->modify('+' . ($id - 100) . ' days')->format('Y-m-d');
        $insert->execute([$id, $date, '', '', 'PAGE-123/45', '', '', null, 1, 1, null]);
        $insertNumber->execute([$id,'PAGE-123/45','PAGE12345']);
    }
    $search('PAGE12345', [], ['page' => '2', 'Kategorie' => '2']);
    $search('PAGE12345', [100, 101, 102, 103, 104], ['page' => '2'], 55);
    if ($db->query('SELECT Nummer FROM dokumente WHERE DokID=2')->fetchColumn() !== 'ab-123/45') {
        throw new RuntimeException('Original number changed');
    }
    echo "Document search: PASS\n";
} finally {
    $db->exec("DROP DATABASE `$name`");
    foreach (glob("$directory/*") as $file) unlink($file);
    rmdir($directory);
}

