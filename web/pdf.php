<?php

require_once 'konfiguration.php';

$dateiRaw = is_string($_GET['file'] ?? null) ? trim($_GET['file']) : '';
$datei = basename($dateiRaw);

if ($dateiRaw === '' || $dateiRaw !== $datei || strtolower(pathinfo($datei, PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(404);
    die('Datei nicht gefunden.');
}

// Nur Eingangsdateien. Archiv-PDFs werden ausschließlich über archiv_pdf.php
// mit Prüfung des aktiven Datenbankeintrags ausgeliefert.
$echterPfad = PFAD_INBOX . $datei;

if ($datei !== '' && is_file($echterPfad) && !is_link($echterPfad)
    && strtolower(pathinfo($echterPfad, PATHINFO_EXTENSION)) === 'pdf') {
    header('Content-Type: application/pdf');
    $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $datei);
    header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($datei));
    header('Content-Length: ' . filesize($echterPfad));
    readfile($echterPfad);
    exit;
} else {
    header("HTTP/1.0 404 Not Found");
    echo "Datei nicht gefunden.";
}
?>
