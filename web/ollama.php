<?php
/** Same-origin bridge: browsers never contact the PC directly. */
require_once 'konfiguration.php';
require_once 'csrf.php';
require_once 'backend_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        throw new InvalidArgumentException('Nur POST ist erlaubt.');
    }
    eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['check', 'suggest', 'background'], true)) throw new InvalidArgumentException('Unbekannte KI-Aktion.');
    $payload = ['database' => MYSQL_DATENBANK];
    if (in_array($action, ['suggest', 'background'], true)) {
        $filename = $_POST['filename'] ?? null;
        $sha = $_POST['sha256'] ?? null;
        $textSha = $_POST['text_sha256'] ?? null;
        $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($filename) || basename($filename) !== $filename || !is_string($sha)
            || !preg_match('/^[a-f0-9]{64}$/', $sha) || !is_string($textSha)
            || !preg_match('/^[a-f0-9]{64}$/', $textSha) || !$jobId) {
            throw new InvalidArgumentException('Ungültiger Dokumententwurf. Bitte den Eingang neu öffnen.');
        }
        if (isset($_POST['current_fields'])) {
            $rawFields = $_POST['current_fields'];
            if (!is_string($rawFields) || strlen($rawFields) > 100000) throw new InvalidArgumentException('Ungültige aktuelle Feldwerte.');
            $currentFields = json_decode($rawFields);
            $limits = ['date'=>10, 'partner'=>255, 'subject'=>255, 'category'=>15, 'category2'=>15, 'document_type'=>15, 'number'=>50, 'tags'=>255];
            if (!is_object($currentFields)) throw new InvalidArgumentException('Ungültige aktuelle Feldwerte.');
            foreach ($currentFields as $key => $value) {
                if ($key === 'numbers') {
                    if (!is_array($value)||count($value)>999) throw new InvalidArgumentException('Ungültige aktuelle Nummern.');
                    foreach ($value as $n) if (!is_object($n)||!is_string($n->kind_id??null)||!is_string($n->value??null)||preg_match_all('/./us',$n->value)>255) throw new InvalidArgumentException('Ungültige aktuelle Nummer.');
                    continue;
                }
                if (!isset($limits[$key]) || !is_string($value) || strlen($value) > $limits[$key] * 4 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new InvalidArgumentException('Ungültige aktuelle Feldwerte.');
                }
            }
            $payload['current_fields'] = $currentFields;
        }
        $payload += ['filename' => $filename, 'sha256' => $sha, 'job_id' => $jobId, 'text_sha256' => $textSha];
    }
    @set_time_limit(330);
    $response = eablage_backend_request('POST', '/ollama/' . $action, $payload, $action === 'suggest' ? 310 : 10);
    if (!$response['ok']) {
        http_response_code(400);
        $detail = $response['data']['detail'] ?? null;
        $result = ['error' => is_string($detail) ? $detail : $response['error']];
        if ($action === 'suggest' && is_array($response['data']['diagnostic'] ?? null)) $result['diagnostic'] = $response['data']['diagnostic'];
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode($response['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
} catch (InvalidArgumentException $e) {
    if (http_response_code() !== 405) http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Sitzung ungültig. Bitte Seite neu laden.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Die KI-Anfrage konnte nicht verarbeitet werden.']);
}

