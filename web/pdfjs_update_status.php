<?php
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/pdfjs_update.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

try {
    eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$installed = eablage_pdfjs_installed_version();

session_start();
$cached = $_SESSION['eablage_pdfjs_update'] ?? [];
if (($cached['installed'] ?? null) !== $installed || ($cached['eablage_installed'] ?? null) !== EABLAGE_WEB_VERSION) $cached = [];
$now = time();
if (($cached['attempt'] ?? 0) > $now - 60) {
    session_write_close();
    echo json_encode($cached);
    exit;
}

// Release the session lock before contacting the external services.
session_write_close();
$result = eablage_pdfjs_check($installed);
$result['attempt'] = $now;
$result['last_success'] = $cached['last_success'] ?? null;
if ($result['upstream'] !== null && $result['advisories'] !== null && $result['eablage_pdfjs'] !== null) {
    $result['last_success'] = $now;
}
session_start();
$current = $_SESSION['eablage_pdfjs_update'] ?? [];
if (($current['attempt'] ?? 0) <= $now || ($current['installed'] ?? null) !== $installed
    || ($current['eablage_installed'] ?? null) !== EABLAGE_WEB_VERSION) {
    $_SESSION['eablage_pdfjs_update'] = $result;
}
session_write_close();
echo json_encode($result);
