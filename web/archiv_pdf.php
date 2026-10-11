<?php
require_once 'konfiguration.php';
require_once 'archiv_pdf_funktionen.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$dokId = filter_input(INPUT_GET, 'dokid', FILTER_VALIDATE_INT);
if (!$dokId || $dokId < 1) {
    http_response_code(400);
    exit('Ungültige Dokument-ID.');
}
try {
    $stmt = $db_config->prepare('SELECT DokID FROM ' . DB_TABELLE_ARCHIV . ' WHERE DokID = ? AND GeloeschtAm IS NULL');
    $stmt->execute([$dokId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    $path = $document ? eablage_archiv_pdf_pfad(PFAD_ARCHIV, (int)$document['DokID']) : null;
    $stream = $path !== null ? @fopen($path, 'rb') : false;
    if ($stream === false) {
        http_response_code(404);
        exit('Das Original-PDF wurde im Archiv nicht gefunden oder ist nicht lesbar.');
    }
    $stat = fstat($stream);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $dokId . 'dok.pdf"');
    if ($stat !== false) header('Content-Length: ' . $stat['size']);
    fpassthru($stream);
    fclose($stream);
} catch (Throwable $e) {
    error_log('e_ablage Archiv-PDF: ' . $e->getMessage());
    http_response_code(500);
    echo 'Das Original-PDF konnte nicht geladen werden.';
}
