<?php
require_once __DIR__ . '/konfiguration.php';
require_once __DIR__ . '/dokumentvorschau.php';
require_once __DIR__ . '/dokument_navigation.php';
require_once __DIR__ . '/nummern_funktionen.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Diese Dokumentansicht ist nur lesend verfügbar.');
}
$dokId = filter_var($_GET['dokid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
if ($dokId === false || $dokId === null) {
    http_response_code(400);
    exit('Ungültige Dokument-ID.');
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function display_value($value): string { return trim((string)$value) !== '' ? h($value) : '—'; }

$returnUrl = valid_return_url(is_string($_GET['return'] ?? null) ? $_GET['return'] : null);
// This screen is entered from search; keep one return level when switching to editing.
if (parse_url($returnUrl, PHP_URL_PATH) !== 'dokumente.php') $returnUrl = 'dokumente.php';
$viewUrl = 'ansehen.php?' . http_build_query(['dokid' => $dokId, 'return' => $returnUrl]);
$editUrl = 'bearbeiten.php?' . http_build_query(['dokid' => $dokId, 'return' => $viewUrl]);
$pdfUrl = 'archiv_pdf.php?dokid=' . rawurlencode((string)$dokId);
try {
    $stmt = $db_config->prepare('SELECT d.*, k.katBez AS Kategorie, k2.katBez AS Kategorie2, t.doktypBez AS Dokumenttyp
        FROM ' . DB_TABELLE_ARCHIV . ' d
        LEFT JOIN kategorie k ON k.id=d.kategorie_id
        LEFT JOIN kategorie k2 ON k2.id=d.kategorie2_id
        LEFT JOIN doktyp t ON t.id=d.doktyp_id
        WHERE d.DokID=? AND d.GeloeschtAm IS NULL');
    $stmt->execute([$dokId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) {
        http_response_code(404);
        exit('Das angeforderte Dokument wurde nicht gefunden.');
    }
    $numbers = eablage_nummern_laden($db_config, $dokId);
    $stmt = $db_config->prepare('SELECT v.ID,v.Bezeichnung FROM vorgang v JOIN dokument_vorgang dv ON dv.VorgangID=v.ID WHERE dv.DokumentID=? ORDER BY v.Bezeichnung,v.ID');
    $stmt->execute([$dokId]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('e_ablage Dokument ansehen: ' . $e->getMessage());
    http_response_code(500);
    exit('Das Dokument konnte nicht geladen werden.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage – Dokument ansehen</title>
    <link href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>" rel="stylesheet">
    <link rel="icon" href="ea_icon4.png" type="image/png">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="container ea-document-view">
    <div class="ea-document-layout">
        <section class="panel panel-primary">
            <div class="panel-heading"><h1 class="ea-panel-title"><?=eablage_icon('document')?>Dokument ansehen</h1></div>
            <div class="panel-body">
                <div class="ea-document-scroll">
                    <div class="ea-document-summary">
                        <p><?=display_value($document['Partner'])?></p>
                        <h2><?=display_value($document['Betreff'])?></h2>
                    </div>
                    <dl class="ea-document-metadata">
                        <dt>Dokumentdatum</dt><dd><?=display_value($document['Datum'])?></dd>
                        <dt>Kategorie</dt><dd><?=display_value($document['Kategorie'])?></dd>
                        <dt>Kategorie 2</dt><dd><?=display_value($document['Kategorie2'])?></dd>
                        <dt>Dokumententyp</dt><dd><?=display_value($document['Dokumenttyp'])?></dd>
                        <dt>Nummern</dt><dd><?php if ($numbers): ?><dl class="ea-document-numbers"><?php foreach ($numbers as $number): ?><dt><?=h($number['label'])?></dt><dd><?=h($number['value'])?></dd><?php endforeach; ?></dl><?php else: ?><?=display_value($document['Nummer'] ?? '')?><?php endif; ?></dd>
                        <dt>Stichwörter</dt><dd><?=display_value($document['Tags'])?></dd>
                        <dt>Vorgänge</dt><dd><?php if ($cases): ?><ul class="ea-document-cases"><?php foreach ($cases as $case): ?><li><a href="<?=h('vorgaenge.php?' . http_build_query(['id' => $case['ID'], 'return' => $viewUrl]))?>"><?=h($case['Bezeichnung'])?></a></li><?php endforeach; ?></ul><?php else: ?>—<?php endif; ?></dd>
                    </dl>
                    <details class="ea-detail-panel">
                        <summary>Technische Angaben</summary>
                        <div><dl class="ea-document-metadata">
                            <?php foreach (['DokID'=>'Dokument-ID', 'Originaldateiname'=>'Originaldateiname', 'Archivierungsdatum'=>'Archiviert am', 'Aenderungsdatum'=>'Zuletzt geändert', 'Aufbewahrungsdauer'=>'Aufbewahrungsdauer', 'DateiSHA256'=>'SHA-256', 'Info'=>'Erkennung'] as $field=>$label): ?>
                                <dt><?=h($label)?></dt><dd><?=display_value($document[$field] ?? '')?></dd>
                            <?php endforeach; ?>
                        </dl></div>
                    </details>
                </div>
                <div class="ea-document-actions">
                    <a href="<?=h($returnUrl)?>" class="btn btn-default">Zurück zur Suche</a>
                    <div>
                        <a href="<?=h($editUrl)?>" class="btn btn-primary"><?=eablage_icon('edit')?>Metadaten bearbeiten</a>
                    </div>
                </div>
            </div>
        </section>
        <div class="ea-document-preview"><?php eablage_dokumentvorschau($pdfUrl, (string)($document['Doktext'] ?? '')); ?></div>
    </div>
</main>
</body>
</html>
