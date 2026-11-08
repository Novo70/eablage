<?php
require_once __DIR__ . '/../web/pdfjs_update.php';

$installed = eablage_pdfjs_installed_version();
if ($installed === null) {
    echo "Installierte PDF.js-Version konnte nicht gelesen werden.\n";
    exit(1);
}
$result = eablage_pdfjs_check($installed, 'eablage_pdfjs_http', false);
echo "Mitgeliefertes PDF.js: $installed\n\n";
$upstream = $result['upstream'];
$advisories = $result['advisories'];
echo 'Aktuelles Mozilla-Release: ' . ($upstream ?? 'nicht abrufbar') . "\n\n";
echo 'OSV-Sicherheitsmeldungen: ' . ($advisories === null ? 'nicht abrufbar' : (implode(', ', $advisories) ?: 'keine Treffer')) . "\n";
$update = $result['upstream_newer'];
if ($update || $advisories) {
    echo "\nPDF.js-Aktualisierung prüfen. Siehe docs/PDF_VORSCHAU.md.\n";
}
exit($update || $advisories || $upstream === null || $advisories === null ? 1 : 0);
