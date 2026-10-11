<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'meldungen.php';

header('Cache-Control: no-store');
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$plan = null;
$error = '';
try {
    $response = eablage_backend_request('GET', '/export/plan', null, 120);
    if (!$response['ok'] || $response['http_status'] !== 200) {
        throw new RuntimeException('Die Ablage konnte nicht geladen werden. ' . $response['error']);
    }
    $plan = $response['data'];
    if (!is_array($plan) || ($plan['profile'] ?? null) !== 1 || !is_array($plan['items'] ?? null)) {
        throw new RuntimeException('Der Exportplan ist ungültig. Bitte das Backend aktualisieren.');
    }
    $planJson = json_encode($plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('e_ablage Explorer: ' . $e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Die Ablage konnte nicht geladen werden. Bitte erneut versuchen.';
    $plan = null;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e_ablage – Ablage-Explorer</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="stylesheet" href="explorer.css?v=<?=filemtime(__DIR__ . '/explorer.css')?>">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <script src="explorer.js?v=<?=filemtime(__DIR__ . '/explorer.js')?>" defer></script>
</head>
<body>
<?php require 'navbar.php'; ?>
<main class="container ea-explorer" id="explorer">
    <?php if ($error !== ''): ?>
        <?=eablage_render_message('danger', $error)?>
    <?php endif; ?>
    <noscript><p class="alert alert-warning">Bitte JavaScript aktivieren, um Ordner und Dokumente auszuwählen.</p></noscript>
    <?php if ($plan !== null): ?>
        <p id="explorer-notice" class="alert alert-info" role="status" hidden></p>
        <div class="ea-explorer-layout" id="explorer-layout" hidden>
            <section class="panel panel-primary ea-explorer-documents" aria-labelledby="explorer-list-heading">
                <div class="panel-heading" id="explorer-list-heading">📂 Ablage-Explorer</div>
                <div class="panel-body">
                    <nav id="explorer-breadcrumb" aria-label="Aktueller Ordner"></nav>
                    <div class="ea-explorer-filter">
                        <label for="explorer-filter">Dokumente filtern <span>(aktueller Ordner inkl. Unterordner)</span></label>
                        <div class="ea-explorer-filter-controls">
                            <input type="search" class="form-control" id="explorer-filter" placeholder="z. B. Partner, Betreff oder Nummer">
                            <button class="btn btn-default btn-sm" type="button" id="explorer-clear" disabled>Filter leeren</button>
                        </div>
                    </div>
                    <p id="explorer-count" aria-live="polite"></p>
                    <ul id="explorer-items" class="ea-explorer-items"></ul>
                    <p id="explorer-empty" hidden></p>
                    <div class="ea-explorer-pagination" id="explorer-pagination" hidden>
                        <button class="btn btn-default btn-sm" type="button" id="explorer-prev">Zurück</button>
                        <span id="explorer-page"></span>
                        <button class="btn btn-default btn-sm" type="button" id="explorer-next">Weiter</button>
                    </div>
                </div>
            </section>
            <section class="panel panel-primary ea-explorer-preview" aria-labelledby="explorer-preview-heading">
                <div class="panel-heading ea-explorer-preview-heading">
                    <span id="explorer-preview-heading"><span class="ea-preview-title-desktop">PDF-Vorschau</span><span class="ea-preview-title-mobile">Dokument</span></span>
                    <div class="ea-explorer-preview-actions" id="explorer-actions" hidden>
                        <a class="btn btn-default ea-heading-btn" id="explorer-open" target="_blank" rel="noopener">PDF öffnen</a>
                        <a class="btn btn-default ea-heading-btn" id="explorer-edit">✏️ Metadaten bearbeiten</a>
                    </div>
                </div>
                <div class="panel-body">
                    <p id="explorer-selection" class="ea-explorer-empty-preview">Bitte ein Dokument auswählen.</p>
                    <div id="explorer-pdf"></div>
                </div>
            </section>
        </div>
        <script type="application/json" id="explorer-plan"><?=$planJson?></script>
    <?php endif; ?>
</main>
</body>
</html>
