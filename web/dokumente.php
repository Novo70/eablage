<?php
require_once __DIR__ . '/icons.php';
// Modernisierte Ergebnisliste für e_ablage:web
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'vorgaenge_funktionen.php';
require_once 'csrf.php';
$db = $db_config;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function request_value(string $name, string $default = ''): string
{
    $value = $_REQUEST[$name] ?? null;
    return is_string($value) ? trim($value) : $default;
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
    $parts = array_values(array_filter($terms, fn($term) => mb_strlen($term, 'UTF-8') >= 2));
    if (!$parts) return h($text);
    // Match only raw text: generated tags and escaped entities must never be searched.
    $pattern = '/(' . implode('|', array_map(fn($term) => preg_quote($term, '/'), $parts)) . ')/iu';
    $out = '';
    foreach (preg_split($pattern, (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
        $out .= $index % 2 ? '<mark>' . h($part) . '</mark>' : h($part);
    }
    return $out;
}


function number_search_condition(string $value, string $pattern, array &$params, string &$types, string $escape = ''): string
{
    $match = 'n.Wert LIKE ?' . $escape;
    $params[] = $pattern;
    $types .= 's';

    // Suchwert contains only ASCII letters and digits, without separators.
    $normalized = preg_replace('/[^a-zA-Z0-9]/', '', $value);
    if ($normalized !== '') {
        $match .= ' OR n.Suchwert LIKE ?';
        $params[] = '%' . strtoupper($normalized) . '%';
        $types .= 's';
    }
    return 'EXISTS (SELECT 1 FROM dokument_nummer n WHERE n.DokumentID = dokumente.DokID AND (' . $match . '))';
}

function literal_search_pattern(string $value): string
{
    return '%' . strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
}

$kategorien = eablage_lade_kategorien($db);
$dokumenttypen = eablage_lade_dokumenttypen($db);
$suchbegriff = request_value('Suchbegriff');
$volltextsuche = request_value('Volltextsuche') !== '';
$datumvon = request_value('Datumvon');
$datumbis = request_value('Datumbis');
$kategorie = request_value('Kategorie', '0');
$dokumenttyp = request_value('Dokumententyp', '0');
$feldsuche = [
    'Partner' => request_value('Partner'),
    'Betreff' => request_value('Betreff'),
    'Nummer' => request_value('Nummer'),
];
$vorgang = request_value('Vorgang', '0');
$vorgaenge = $db->query('SELECT ID,Bezeichnung FROM vorgang ORDER BY Bezeichnung,ID')->fetchAll(PDO::FETCH_KEY_PAIR);
$sortColumns = [
    'subject' => ['Betreff', "NULLIF(TRIM(dokumente.Betreff), '')"],
    'partner' => ['Partner', "NULLIF(TRIM(dokumente.Partner), '')"],
    'date' => ['Datum', 'dokumente.Datum'],
    'number' => ['Nummer', "NULLIF(TRIM(dokumente.Nummer), '')"],
];
$sortOptions = [];
foreach ($sortColumns as $key => [$label, $expression]) {
    $sortOptions[$key . '-asc'] = [$label . ' aufsteigend', $expression . ' IS NULL, ' . $expression . ' ASC'];
    $sortOptions[$key . '-desc'] = [$label . ' absteigend', $expression . ' DESC'];
}
$requestedSort = $_POST['sort'] ?? $_GET['sort'] ?? '';
$sort = is_string($requestedSort) && isset($sortOptions[$requestedSort]) ? $requestedSort : 'date-desc';
$page = max(1, (int)request_value('page', '1'));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$terms = preg_split('/\s+/u', $suchbegriff, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$where = ['dokumente.GeloeschtAm IS NULL'];
$types = '';
$params = [];
if ($vorgang !== '0' && $vorgang !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM dokument_vorgang dv WHERE dv.DokumentID=dokumente.DokID AND dv.VorgangID=?)';
    $types .= 'i'; $params[] = (int)$vorgang;
}
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
$searchColumns = ['dokumente.Partner', 'dokumente.Betreff', 'dokumente.Tags'];
if ($volltextsuche) $searchColumns[] = 'dokumente.Doktext';
foreach ($terms as $term) {
    $orParts = [];
    foreach ($searchColumns as $column) {
        $orParts[] = $column . ' LIKE ?';
        $types .= 's';
        $params[] = '%' . $term . '%';
    }
    $orParts[] = number_search_condition($term, '%' . $term . '%', $params, $types);
    $where[] = '(' . implode(' OR ', $orParts) . ')';
}
foreach (['Partner', 'Betreff'] as $field) {
    if ($feldsuche[$field] === '') continue;
    $where[] = "dokumente.$field LIKE ? ESCAPE '='";
    $params[] = literal_search_pattern($feldsuche[$field]);
    $types .= 's';
}
if ($feldsuche['Nummer'] !== '') {
    $number = $feldsuche['Nummer'];
    $where[] = number_search_condition($number, literal_search_pattern($number), $params, $types, " ESCAPE '='");
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
    $dataSql = 'SELECT dokumente.DokID, dokumente.Datum, dokumente.Partner, dokumente.Betreff, dokumente.Nummer, kategorie.katBez, doktyp.doktypBez '
        . $joinSql . ' ' . $whereSql . ' ORDER BY ' . $sortOptions[$sort][1] . ', dokumente.DokID DESC LIMIT ? OFFSET ?';
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
// Same selection for all pages of the same search, including GET/POST entry points.
$selectionKey = hash('sha256', serialize([
    $suchbegriff, $volltextsuche, $datumvon, $datumbis, $kategorie, $dokumenttyp, $feldsuche, $vorgang,
]));
$aktionToken = $documents ? eablage_aktion_token() : '';
$returnQuery = build_query([]);
$returnUrl = 'dokumente.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$resultColumns = ['subject', 'partner', 'date', 'classification', 'number'];
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
    <?php include "navbar.php"; ?>
    <main class="container ea-search-results">
        <section class="panel panel-primary ea-documents-panel" aria-labelledby="search-results-heading">
            <div class="panel-heading ea-search-heading">
                <h1 class="ea-panel-title" id="search-results-heading"><?=eablage_icon('search')?>Suchergebnisse <span class="ea-search-hit-count">(<?=h($totalRows)?> Treffer)</span></h1>
            </div>
            <details class="ea-filter-panel" id="search-refine">
                <summary class="ea-filter-summary ea-search-filter-summary"><?=eablage_icon('search')?>Suche verfeinern</summary>
                <div class="panel-body ea-filter-body">
                    <form method="GET" action="dokumente.php" accept-charset="UTF-8" id="search-refine-form"><input type="hidden" name="page" value="1">
                        <?php if (!$documents): ?><input type="hidden" name="sort" value="<?=h($sort)?>"><?php endif; ?>
                        <div class="form-group"><label for="Vorgang">Vorgang</label><select class="form-control" name="Vorgang" id="Vorgang"><option value="0">Alle Dokumente</option><?php foreach ($vorgaenge as $id=>$name): ?><option value="<?=h($id)?>" <?=selected_value($vorgang,(string)$id)?>><?=h($name)?></option><?php endforeach; ?></select><?php if (isset($vorgaenge[$vorgang])): ?><a href="vorgaenge.php?id=<?=h($vorgang)?>">Vorgang öffnen</a><?php endif; ?></div>
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
                        </div>
                        <div class="row">
                            <?php foreach ($feldsuche as $feld => $wert): ?>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="<?= h($feld) ?>"><?= h($feld) ?></label>
                                        <input class="form-control" id="<?= h($feld) ?>" name="<?= h($feld) ?>" type="text" value="<?= h($wert) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
            </details>
            <div class="ea-results-scroll" tabindex="0" role="region" aria-labelledby="search-results-heading">
                <div class="search-summary"><strong><?= h($totalRows) ?></strong> Treffer gefunden<?php if ($totalRows > 0): ?> — angezeigt werden <?= h($firstHit) ?> bis <?= h($lastHit) ?> — Seite <?= h($page) ?> von <?= h($totalPages) ?><?php endif; ?> <?php if ($volltextsuche): ?><span class="label label-info">Volltextsuche aktiv</span><?php endif; ?></div>
                <?php if ($totalRows === 0): ?>
                    <div class="alert alert-warning">Keine Dokumente gefunden. Bitte Suchkriterien ändern.</div>
                <?php else: ?>
                    <div class="ea-list-mobile-sort">
                        <label for="search-sort">Sortieren nach</label>
                        <div>
                            <select class="form-control" id="search-sort" name="sort" form="search-refine-form">
                                <?php foreach ($sortOptions as $value => [$label]): ?>
                                    <option value="<?=h($value)?>"<?=selected_value($sort, $value)?>><?=h($label)?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-default" type="submit" form="search-refine-form">Sortieren</button>
                        </div>
                    </div>
                    <label class="ea-search-page-selection" data-search-mobile-selection hidden>
                        <input type="checkbox" data-search-page-checkbox> Alle auf dieser Seite auswählen
                    </label>
                    <table class="table table-striped ea-results-table" data-clickable-rows data-search-selection="<?=h($selectionKey)?>">
                        <thead>
                            <tr>
                                <th scope="col" class="ea-search-selection-cell" hidden>
                                    <input type="checkbox" data-search-page-checkbox aria-label="Alle auf dieser Seite auswählen">
                                </th>
                                <?php foreach ($resultColumns as $column): ?>
                                    <?php if ($column === 'classification'): ?>
                                        <th scope="col">Kategorie / Dokumenttyp</th>
                                    <?php else:
                                        $ascending = $sort === $column . '-asc';
                                        $descending = $sort === $column . '-desc';
                                        $nextDirection = $ascending || (!$descending && $column === 'date') ? 'desc' : 'asc';
                                        $sortUrl = 'dokumente.php?' . build_query(['sort' => $column . '-' . $nextDirection, 'page' => 1]);
                                    ?>
                                        <th scope="col"<?=$ascending ? ' aria-sort="ascending"' : ($descending ? ' aria-sort="descending"' : '')?>>
                                            <a href="<?=h($sortUrl)?>"><?=h($sortColumns[$column][0])?><?php if ($ascending || $descending): ?> <span aria-hidden="true"><?=$ascending ? '↑' : '↓'?></span><?php endif; ?></a>
                                        </th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $row):
                                $link = 'ansehen.php?dokid=' . rawurlencode((string)$row['DokID']) . '&return=' . rawurlencode($returnUrl); ?>
                                <tr>
                                    <td class="ea-search-selection-cell" hidden>
                                        <?php if ((int)$row['DokID'] > 0): ?>
                                            <input type="checkbox" data-search-document="<?=h($row['DokID'])?>" aria-label="<?=h('Dokument ' . $row['DokID'] . ' auswählen')?>">
                                        <?php endif; ?>
                                    </td>
                                    <td class="ea-subject"><a href="<?= h($link) ?>" class="ea-document-link ea-list-title-link" data-row-link><?=eablage_icon('document')?><span><?= highlight_text(trim((string)($row['Betreff'] ?? '')) !== '' ? $row['Betreff'] : 'Dokument ' . $row['DokID'], [...$terms, $feldsuche['Betreff']]) ?></span></a></td>
                                    <td class="ea-partner"><?= highlight_text($row['Partner'] ?? '', [...$terms, $feldsuche['Partner']]) ?></td>
                                    <td class="ea-result-date"><?= h($row['Datum'] ?? '') ?></td>
                                    <td class="ea-result-classification"><?php if (($row['katBez'] ?? '') !== ''): ?><span class="ea-badge ea-badge-category"><?= h($row['katBez']) ?></span><?php endif; ?><?php if (($row['doktypBez'] ?? '') !== ''): ?><span class="ea-badge ea-badge-type"><?= h($row['doktypBez']) ?></span><?php endif; ?></td>
                                    <td class="ea-number"><?= highlight_text($row['Nummer'] ?? '', [...$terms, $feldsuche['Nummer']]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <footer class="ea-search-footer">
                <?php if ($totalPages > 1): ?>
                <nav class="pagination-wrapper" aria-label="Ergebnisseiten">
                    <div><?php if ($page > 1): ?><a class="btn btn-default btn-sm" href="dokumente.php?<?= h(build_query(['page' => $page - 1])) ?>">&laquo; Vorherige</a><?php else: ?><button class="btn btn-default btn-sm" disabled>&laquo; Vorherige</button><?php endif; ?> <?php if ($page < $totalPages): ?><a class="btn btn-default btn-sm" href="dokumente.php?<?= h(build_query(['page' => $page + 1])) ?>">Nächste &raquo;</a><?php else: ?><button class="btn btn-default btn-sm" disabled>Nächste &raquo;</button><?php endif; ?></div>
                    <div class="ea-pagination-info">Seite <?= h($page) ?> von <?= h($totalPages) ?></div>
                </nav>
                <?php endif; ?>
                <div class="ea-search-actions">
                    <?php if ($documents): ?>
                        <span id="search-selection-count" role="status">Dokumente auswählen</span>
                        <button class="btn btn-default" type="button" id="search-selection-clear" disabled>Auswahl aufheben</button>
                        <button class="btn btn-primary" type="button" id="search-mass-open" disabled>Metadaten ändern</button>
                        <button class="btn btn-primary" type="button" id="search-case-open" disabled aria-haspopup="dialog">Vorgang anlegen</button>
                    <?php endif; ?>
                    <a class="btn btn-default" href="index.php" id="search-new">Neue Suche</a>
                    <button class="btn btn-primary" type="submit" form="search-refine-form">Suche aktualisieren</button>
                </div>
            </footer>
        </section>
        <?php if ($documents): ?>
            <dialog id="search-case-dialog" class="ea-search-case-dialog" aria-labelledby="search-case-heading">
                <form id="search-case-form" method="post" action="vorgang_anlegen.php">
                    <h2 id="search-case-heading">Vorgang anlegen</h2>
                    <p id="search-case-summary"></p>
                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                    <input type="hidden" name="return" value="<?=h($returnUrl)?>">
                    <div class="form-group">
                        <label for="search-case-name">Bezeichnung</label>
                        <input class="form-control" id="search-case-name" name="name" required maxlength="255" autofocus>
                    </div>
                    <div class="form-group">
                        <label for="search-case-description">Beschreibung (optional)</label>
                        <textarea class="form-control" id="search-case-description" name="description" rows="3" maxlength="10000"></textarea>
                    </div>
                    <p class="alert alert-danger" id="search-case-error" role="alert" hidden></p>
                    <div class="ea-search-actions">
                        <button type="button" class="btn btn-default" id="search-case-cancel">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Vorgang speichern</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
    </main>
    <script src="clickable_rows.js?v=<?=filemtime(__DIR__ . '/clickable_rows.js')?>" defer></script>
    <script src="search_results.js?v=<?=filemtime(__DIR__ . '/search_results.js')?>" defer></script>
</body>

</html>



