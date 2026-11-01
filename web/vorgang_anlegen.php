<?php
require_once 'konfiguration.php';
require_once 'csrf.php';
require_once 'vorgaenge_funktionen.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); throw new RuntimeException('Nur POST ist erlaubt.'); }
    try { eablage_aktion_pruefen($_POST['aktion_token'] ?? null); }
    catch (RuntimeException $e) { http_response_code(403); throw $e; }
    $name = eablage_vorgang_text($_POST['name'] ?? '', 'Bezeichnung', 255, true);
    $id = eablage_vorgang_anlegen($db_config, $name, $_POST['description'] ?? '');
    echo json_encode(['ok'=>true, 'id'=>$id, 'name'=>$name], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    if ($e instanceof PDOException) { error_log('eAblage Vorgang anlegen: ' . $e->getMessage()); $message = 'Vorgang konnte nicht angelegt werden. Bitte den aktuellen Stand prüfen.'; }
    else $message = $e->getMessage();
    echo json_encode(['ok'=>false, 'error'=>$message]);
}
