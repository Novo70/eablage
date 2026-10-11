<?php
// php tests/archiv_pdf_helpers.php (temporary files only, no NAS/database)
require __DIR__ . '/../web/archiv_pdf_funktionen.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$root = sys_get_temp_dir() . '/eablage-pdf-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir($root . '/archive');
file_put_contents($root . '/archive/42dok.pdf', '%PDF-test');
file_put_contents($root . '/outside.pdf', '%PDF-outside');
symlink($root . '/outside.pdf', $root . '/archive/43dok.pdf');
try {
    $archive = $root . '/archive';
    check(eablage_archiv_pdf_dateiname(42) === '42dok.pdf', 'Incorrect basename');
    check(eablage_archiv_pdf_pfad($archive, 42) === $archive . '/42dok.pdf', 'PDF missing');
    foreach ([0, -1, 41, 43] as $id) {
        check(eablage_archiv_pdf_pfad($archive, $id) === null, 'Unsafe/missing target accepted');
    }
    // Check the actual edit-return validator without including the editing page.
    $source = file_get_contents(__DIR__ . '/../web/bearbeiten.php');
    $start = strpos($source, 'function valid_return_url(');
    $end = strpos($source, 'function valid_date(', $start);
    eval(substr($source, $start, $end - $start));
    $return = 'explorer.php?dokid=17&folder=%5B%22Haus%22%5D';
    check(valid_return_url($return) === $return, 'Explorer return lost');
    foreach (['https://example.com', '//example.com/explorer.php', '../explorer.php', "explorer.php\r\nX-Test: bad"] as $url) {
        check(valid_return_url($url) === 'dokumente.php', 'Unsafe return accepted');
    }
    echo "PDF path and edit-return tests passed.\n";
} finally {
    unlink($root . '/archive/43dok.pdf');
    unlink($root . '/archive/42dok.pdf');
    unlink($root . '/outside.pdf');
    rmdir($root . '/archive');
    rmdir($root);
}
