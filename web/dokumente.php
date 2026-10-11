<?php
// Modernisierte Ergebnisliste für e_ablage:web
require_once 'konfiguration.php';
require_once 'funktionen.php';
$db = $db_config;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function request_value(string $name, string $default = ''): string
{
    return isset($_REQUEST[$name]) ? trim((string)$_REQUEST[$name]) : $default;
}
function selected_value(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}
function build_query(array $overrides = []): string
{
    $params = $_GET;
    foreach ($_POST as $key => $value) {
        $params[$key] = $value;
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) unset($params[$key]);
        else $params[$key] = $value;
    }
    return http_build_query($params);
}
function highlight_text($text, array $terms): string
{
    $escaped = h($text);
    foreach ($terms as $term) {
        if (mb_strlen($term, 'UTF-8') < 2) continue;
        $pattern = preg_quote(h($term), '/');
        $escaped = preg_replace('/(' . $pattern . ')/iu', '<mark>$1</mark>', $escaped);
    }
    return $escaped;
}


$kategorien = eablage_lade_kategorien($db);
$dokumenttypen = eablage_lade_dokumenttypen($db);
$suchbegriff = request_value('Suchbegriff');
$volltextsuche = isset($_REQUEST['Volltextsuche']) && $_REQUEST['Volltextsuche'] !== '';
$datumvon = request_value('Datumvon');
$datumbis = request_value('Datumbis');
$kategorie = request_value('Kategorie', '0');
$dokumenttyp = request_value('Dokumententyp', '0');
$nummer = request_value('Nummer', '0');
$page = max(1, (int)request_value('page', '1'));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$terms = preg_split('/\s+/u', $suchbegriff, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$where = ['dokumente.GeloeschtAm IS NULL'];
$types = '';
$params = [];
if ($dokumenttyp !== '' && $dokumenttyp !== '0') {
    $where[] = 'dokumente.doktyp_id = ?';
    $types .= 'i';
    $params[] = (int)$dokumenttyp;
}
if ($kategorie !== '' && $kategorie !== '0') {
    $where[] = '(dokumente.kategorie_id = ? OR dokumente.kategorie2_id = ?)';
    $types .= 'ii';
    $params[] = (int)$kategorie;
    $params[] = (int)$kategorie;
}
if ($datumvon !== '') {
    $where[] = 'dokumente.Datum >= ?';
    $types .= 's';
    $params[] = $datumvon;
}
if ($datumbis !== '') {
    $where[] = 'dokumente.Datum <= ?';
    $types .= 's';
    $params[] = $datumbis;
}
$searchColumns = ['dokumente.Partner', 'dokumente.Betreff', 'dokumente.Nummer', 'dokumente.Tags'];
if ($volltextsuche) $searchColumns[] = 'dokumente.Doktext';
foreach ($terms as $term) {
    $orParts = [];
    foreach ($searchColumns as $column) {
        $orParts[] = $column . ' LIKE ?';
        $types .= 's';
        $params[] = '%' . $term . '%';
    }
    $where[] = '(' . implode(' OR ', $orParts) . ')';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$joinSql = ' FROM dokumente LEFT JOIN kategorie ON kategorie.id = dokumente.kategorie_id LEFT JOIN doktyp ON doktyp.id = dokumente.doktyp_id ';
try {
    $countStmt = $db->prepare('SELECT COUNT(*) AS anzahl ' . $joinSql . ' ' . $whereSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
    $dataSql = 'SELECT dokumente.DokID, dokumente.Datum, dokumente.Partner, dokumente.Betreff, dokumente.Nummer, kategorie.katBez, doktyp.doktypBez ' . $joinSql . ' ' . $whereSql . ' ORDER BY dokumente.Datum DESC LIMIT ? OFFSET ?';
    $dataStmt = $db->prepare($dataSql);
    $position = 1;
    foreach ($params as $index => $value) {
        $pdoType = (($types[$index] ?? 's') === 'i') ? PDO::PARAM_INT : PDO::PARAM_STR;
        $dataStmt->bindValue($position++, $value, $pdoType);
    }
    $dataStmt->bindValue($position++, $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue($position, $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $documents = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('e_ablage Dokumentensuche: ' . $e->getMessage());
    http_response_code(500);
    die('Die Dokumente konnten nicht geladen werden.');
}
$firstHit = $totalRows === 0 ? 0 : $offset + 1;
$lastHit = min($offset + $perPage, $totalRows);
$returnUrl = 'dokumente.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage</title>
    <link href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>" rel="stylesheet">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <link rel="icon" href="ea_icon4.png" type="image/png">
</head>

<body>
    <div id="app">
        <?php include "navbar.php"; ?>
        <div class="container">
            <div class="row">
                <div class="col-md-12">
                    <details class="panel panel-default ea-filter-panel" open>
                        <summary class="panel-heading ea-filter-summary">🔍 Suche verfeinern</summary>
                        <div class="panel-body ea-filter-body">
                            <form method="GET" action="dokumente.php" accept-charset="UTF-8"><input type="hidden" name="page" value="1">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group"><label for="Suchbegriff">Suchbegriff</label><input class="form-control" id="Suchbegriff" name="Suchbegriff" type="text" value="<?= h($suchbegriff) ?>"></div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group"><label for="Datumvon">Datum von</label><input class="form-control" id="Datumvon" name="Datumvon" type="date" value="<?= h($datumvon) ?>"></div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group"><label for="Datumbis">Datum bis</label><input class="form-control" id="Datumbis" name="Datumbis" type="date" value="<?= h($datumbis) ?>"></div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group"><label for="Kategorie">Kategorie</label><select class="form-control" id="Kategorie" name="Kategorie"><?php foreach ($kategorien as $value => $label): ?><option value="<?= h($value) ?>" <?= selected_value($kategorie, $value) ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group"><label for="Dokumententyp">Dokumententyp</label><select class="form-control" id="Dokumententyp" name="Dokumententyp"><?php foreach ($dokumenttypen as $value => $label): ?><option value="<?= h($value) ?>" <?= selected_value($dokumenttyp, $value) ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group ea-form-check"><input id="Volltextsuche" name="Volltextsuche" value="1" type="checkbox" <?= $volltextsuche ? ' checked' : '' ?>><label for="Volltextsuche">Volltextsuche</label></div>
                                    </div>
                                    <div class="col-md-8 ea-form-actions"><a class="btn btn-default" href="index.php">Neue Suche</a><button class="btn btn-primary" type="submit">Suche aktualisieren</button></div>
                                </div>
                            </form>
                        </div>
                    </details>
                    <div class="panel panel-primary ea-documents-panel">
                        <div class="panel-heading">Dokumente</div>
                        <div class="panel-body">
                            <div class="search-summary"><strong><?= h($totalRows) ?></strong> Treffer gefunden<?php if ($totalRows > 0): ?> — angezeigt werden <?= h($firstHit) ?> bis <?= h($lastHit) ?> — Seite <?= h($page) ?> von <?= h($totalPages) ?><?php endif; ?> <?php if ($volltextsuche): ?><span class="label label-info">Volltextsuche aktiv</span><?php endif; ?></div>
                            <?php if ($totalRows === 0): ?><div class="alert alert-warning">Keine Dokumente gefunden. Bitte Suchkriterien ändern.</div><?php else: ?>
                                <div class="pagination-wrapper">
                                    <div><?php if ($page > 1): ?><a class="btn btn-default btn-sm" href="dokumente.php?<?= h(build_query(['page' => $page - 1])) ?>">&laquo; Vorherige</a><?php else: ?><button class="btn btn-default btn-sm" disabled>&laquo; Vorherige</button><?php endif; ?> <?php if ($page < $totalPages): ?><a class="btn btn-default btn-sm" href="dokumente.php?<?= h(build_query(['page' => $page + 1])) ?>">Nächste &raquo;</a><?php else: ?><button class="btn btn-default btn-sm" disabled>Nächste &raquo;</button><?php endif; ?></div>
                                    <div class="ea-pagination-info">Seite <?= h($page) ?> von <?= h($totalPages) ?></div>
                                </div>
                                <table class="table table-striped table-hover ea-results-table">
                                    <thead>
                                        <tr>
                                            <th>Datum / Kategorie</th>
                                            <th>Partner</th>
                                            <th>Betreff</th>
                                            <th>Nummer</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documents as $row):
                                                                                                                                                        $link = 'archiv_pdf.php?dokid=' . rawurlencode((string)$row['DokID']);
                                                                                                                                                        $editLink = 'bearbeiten.php?dokid=' . rawurlencode((string)$row['DokID']) . '&return=' . rawurlencode($returnUrl); ?>
                                            <tr>
                                                <td class="ea-result-datecell">
                                                    <div class="ea-result-meta-row"><span class="ea-result-date"><?= h($row['Datum'] ?? '') ?></span><span class="ea-result-meta"><span class="ea-badge ea-badge-category"><?= h($row['katBez'] ?? '') ?></span><span class="ea-badge ea-badge-type"><?= h($row['doktypBez'] ?? '') ?></span></span></div>
                                                </td>
                                                <td class="ea-partner"><?= highlight_text($row['Partner'] ?? '', $terms) ?></td>
                                                <td class="ea-subject"><a href="<?= h($link) ?>" target="_blank" rel="noopener" class="ea-document-link"><span class="ea-pdf-icon" aria-hidden="true">📄</span><span class="ea-subject-text"><?= highlight_text($row['Betreff'] ?? '', $terms) ?></span></a> <a href="<?= h($editLink) ?>" class="ea-edit-link" title="Dokument bearbeiten" aria-label="Dokument <?= h($row['DokID']) ?> bearbeiten">✏️</a></td>
                                                <td class="ea-number"><?= highlight_text($row['Nummer'] ?? '', $terms) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="pagination-wrapper">
                                    <div><?php if ($page > 1): ?><a class="btn btn-default btn-sm" href="dokumente.php?<?= h(build_query(['page' => $page - 1])) ?>">&laquo; Vorherige</a><?php else: ?><button class="btn btn-default btn-sm" disabled>&laquo; Vorherige</button><?php endif; ?> <?php if ($page < $totalPages): ?><a class="btn btn-default btn-sm" href="dokumente.php?<?= h(build_query(['page' => $page + 1])) ?>">Nächste &raquo;</a><?php else: ?><button class="btn btn-default btn-sm" disabled>Nächste &raquo;</button><?php endif; ?></div>
                                    <div class="ea-pagination-info">Seite <?= h($page) ?> von <?= h($totalPages) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function() {
            var filter = document.querySelector('.ea-filter-panel');
            if (filter && window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
                filter.removeAttribute('open');
            }
        }());
    </script>
</body>

</html>
