<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$filenames = $_POST['filenames'] ?? [];
if (!is_array($filenames) || count($filenames) > 200 || count(array_filter($filenames, 'is_string')) !== count($filenames)) {
    http_response_code(400);
    echo json_encode(['jobs'=>[]]);
    exit;
}
$response = eablage_analysis_post('/inbox/jobs', ['database'=>MYSQL_DATENBANK, 'filenames'=>array_values($filenames)], 3);
http_response_code($response['ok'] ? 200 : 503);
echo json_encode($response['ok'] ? $response['data'] : ['jobs'=>[]], JSON_UNESCAPED_UNICODE);
