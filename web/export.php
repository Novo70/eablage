<?php

require_once 'konfiguration.php';
require_once 'funktionen.php';

$dokId = filter_input(INPUT_GET, 'dokid', FILTER_VALIDATE_INT);
if (!$dokId || $dokId < 1) {
    http_response_code(400);
    die('Ungültige Dokument-ID.');
}

$response = eablage_backend_request('GET', '/export/document/' . rawurlencode((string)$dokId));
if (!$response['ok']) {
    http_response_code(502);
    die('Die Exportinformationen konnten nicht vom Python-Backend abgerufen werden.');
}
$exportInfo = $response['data'];
$downloadName = is_array($exportInfo) ? trim((string)($exportInfo['filename'] ?? '')) : '';
if ($downloadName === '' || strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(502);
    die('Das Python-Backend hat keinen gültigen Export-Dateinamen geliefert.');
}

// Der Webserver greift lokal auf das Archiv zu. Python kennt den NAS-Pfad bewusst nicht.
$sourceName = eablage_archiv_pdf_dateiname($dokId);
$sourcePath = rtrim(PFAD_ARCHIV, '/\\') . DIRECTORY_SEPARATOR . $sourceName;
if (!is_file($sourcePath)) {
    http_response_code(404);
    die('Die archivierte PDF-Datei wurde nicht gefunden.');
}

// Zusätzliche Absicherung für den vom Backend gelieferten Downloadnamen.
$downloadName = basename(str_replace('\\', '/', $downloadName));
$downloadNameAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: ($dokId . '.pdf');

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $downloadNameAscii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($sourcePath));
header('X-Content-Type-Options: nosniff');
readfile($sourcePath);
exit;
