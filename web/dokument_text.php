<?php
require_once __DIR__ . '/konfiguration.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function document_text_response(int $status, array $data): never {
    http_response_code($status);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    document_text_response(405, ['error' => 'Diese Abfrage ist nur lesend verfügbar.']);
}
$id = filter_var($_GET['dokid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
if (!$id) document_text_response(400, ['error' => 'Ungültige Dokument-ID.']);
try {
    $stmt = $db_config->prepare('SELECT Doktext FROM ' . DB_TABELLE_ARCHIV . ' WHERE DokID = ? AND GeloeschtAm IS NULL');
    $stmt->execute([$id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) document_text_response(404, ['error' => 'Dokument nicht gefunden.']);
    document_text_response(200, ['text' => (string)($document['Doktext'] ?? '')]);
} catch (PDOException $e) {
    error_log('e_ablage Dokumenttext: ' . $e->getMessage());
    document_text_response(500, ['error' => 'Der Dokumenttext konnte nicht geladen werden.']);
}
