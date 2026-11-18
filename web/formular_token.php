<?php
require_once __DIR__ . '/csrf.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ($_SERVER['HTTP_X_EABLAGE_FORMULAR'] ?? '') !== '1') {
    http_response_code(403);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['token' => eablage_aktion_token()]);
