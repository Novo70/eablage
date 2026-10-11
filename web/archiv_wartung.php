<?php
// Included by gesamtexport.php before output. Backend owns audit and repair.
require_once 'csrf.php';
$aktionToken = eablage_aktion_token();
$archiveReport = null;
$archiveRepair = null;
$archiveError = '';
function archive_backend_call(string $endpoint, array $payload): array
{
    $response = eablage_backend_request('POST', $endpoint, $payload, 120);
    if (!$response['ok'] || $response['http_status'] !== 200) {
        throw new RuntimeException($response['error'] . ' Nach einer unterbrochenen Reparatur bitte das Archiv erneut prüfen.');
    }
    return $response['data'];
}

function archive_selections($selected, bool $jsonField = false): array
{
    if ($jsonField) {
        if (!is_string($selected)) throw new RuntimeException('Ungültige Auswahl.');
        $selected = json_decode($selected, true, 8, JSON_THROW_ON_ERROR);
    }
    if (!is_array($selected) || !array_is_list($selected) || count($selected) < 1 || count($selected) > 100) {
        throw new RuntimeException('Bitte 1 bis 100 Metadateien auswählen.');
    }
    $items = [];
    foreach ($selected as $value) {
        $item = $jsonField ? $value : (is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : null);
        if (!is_array($item) || !is_int($item['dok_id'] ?? null) || $item['dok_id'] < 1 || !is_string($item['token'] ?? null)) {
            throw new RuntimeException('Ungültige Auswahl.');
        }
        $items[] = $item;
    }
    return $items;
}

$archiveAction = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $archiveAction === 'archive_repair_batch') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $items = archive_selections($_POST['selection_json'] ?? null, true);
        echo json_encode(archive_backend_call('/archive/repair', ['database' => MYSQL_DATENBANK, 'selections' => $items]), JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['detail' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($archiveAction, ['archive_audit', 'archive_repair'], true)) {
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        if ($archiveAction === 'archive_repair') {
            $items = archive_selections($_POST['archive_selection'] ?? null);
            $archiveRepair = archive_backend_call('/archive/repair', ['database' => MYSQL_DATENBANK, 'selections' => $items]);
        }
        $archiveReport = archive_backend_call('/archive/audit', ['database' => MYSQL_DATENBANK]);
    } catch (Throwable $e) {
        $archiveError = $e->getMessage();
    }
}
