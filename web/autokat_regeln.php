<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'nummern_funktionen.php';
require_once 'meldungen.php';
require_once 'status_toggle.php';
require_once 'csrf.php';
$statusRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['status_inline'] ?? '') === '1';
$aktionToken = eablage_aktion_token();
if ($statusRequest && ($_POST['action'] ?? '') !== 'toggle') {
    eablage_status_json(['ok'=>false, 'message'=>'Ungültige Statusaktion.'], 400);
}

if (!isset($db) && isset($db_config)) {
    $db = $db_config;
}
if (!isset($db)) {
    $db = null;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$meldung = trim((string)($_GET['msg'] ?? ''));
$meldungTyp = 'success';
$testText = '';
$testResult = null;
$testFehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $regelId = trim((string)($_POST['regel_id'] ?? ''));
    $aktiv = (int)($_POST['aktiv'] ?? 0) === 1 ? 1 : 0;

    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $stmt = $db->prepare('SELECT RegelID FROM autokat WHERE RegelID = :regel_id');
        $stmt->execute([':regel_id'=>$regelId]);
        if ($stmt->fetchColumn() === false) {
            if ($statusRequest) eablage_status_json(['ok'=>false, 'message'=>'Regel wurde nicht gefunden. Bitte Ansicht aktualisieren.'], 404);
            throw new RuntimeException('Regel wurde nicht gefunden.');
        }
        $stmt = $db->prepare('UPDATE autokat SET Aktiv = :aktiv WHERE RegelID = :regel_id');
        $stmt->execute([':aktiv' => $aktiv, ':regel_id' => $regelId]);
        if ($statusRequest) eablage_status_json(['ok'=>true, 'active'=>$aktiv === 1]);
        header('Location: autokat_regeln.php?msg=' . rawurlencode($aktiv ? 'Regel wurde aktiviert.' : 'Regel wurde deaktiviert.'));
        exit;
    } catch (Throwable $e) {
        error_log('e_ablage AutoKat Aktivstatus: ' . $e->getMessage());
        $meldung = 'Der Aktivstatus konnte nicht geändert werden.';
        $meldungTyp = 'danger';
        if ($statusRequest) eablage_status_json(['ok'=>false, 'message'=>$meldung . ' Bitte Ansicht aktualisieren und erneut versuchen.'], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    $testText = trim((string)($_POST['test_text'] ?? ''));

    if ($testText === '') {
        $testFehler = 'Bitte einen Beispieltext eingeben.';
    } else {
        $apiResult = eablage_analysis_post('/analyze/autokat', ['text' => $testText], 10);

        if (!$apiResult['ok']) {
            $testFehler = $apiResult['error'];
        } else {
            $testResult = $apiResult['data'];
        }
    }
}

$kategorien = eablage_lade_kategorien($db);
$dokumenttypen = eablage_lade_dokumenttypen($db);

$filterText = trim((string)($_GET['q'] ?? ''));
$filterTyp = strtoupper(trim((string)($_GET['typ'] ?? '')));
$filterAktiv = trim((string)($_GET['aktiv'] ?? ''));
$filterNutzung = trim((string)($_GET['nutzung'] ?? ''));
$seite = max(1, (int)($_GET['seite'] ?? 1));
$regelnProSeite = 25;

$where = [];
$params = [];

if ($filterText !== '') {
    $searchFields = ['RegelID', 'Bezeichnung', 'Suchbegriffe', 'Ausschlussbegriffe', 'partner', 'betreff', 'Kommentar'];
    $searchConditions = [];
    foreach ($searchFields as $index => $field) {
        $placeholder = ':q' . $index;
        $searchConditions[] = $field . ' LIKE ' . $placeholder;
        $params[$placeholder] = '%' . $filterText . '%';
    }
    $where[] = '(' . implode(' OR ', $searchConditions) . ')';
}
if (in_array($filterTyp, ['E', 'K'], true)) {
    $where[] = 'Regeltyp = :typ';
    $params[':typ'] = $filterTyp;
}
if ($filterAktiv === '1' || $filterAktiv === '0') {
    $where[] = 'Aktiv = :aktiv';
    $params[':aktiv'] = (int)$filterAktiv;
}
if ($filterNutzung === 'mit') {
    $where[] = 'Treffer > 0';
} elseif ($filterNutzung === 'ohne') {
    $where[] = 'Treffer = 0';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare('SELECT COUNT(*) FROM autokat' . $whereSql);
$countStmt->execute($params);
$anzahlRegeln = (int)$countStmt->fetchColumn();
$anzahlSeiten = max(1, (int)ceil($anzahlRegeln / $regelnProSeite));
$seite = min($seite, $anzahlSeiten);
$offset = ($seite - 1) * $regelnProSeite;

$sql = 'SELECT id, RegelID, Version, Rang, Regeltyp, Aktiv, Bezeichnung, Suchbegriffe, Ausschlussbegriffe,
               kat, kat2, dok, partner, betreff, nr, tags, Kommentar, Treffer, LetzterTreffer, ErstelltAm
        FROM autokat'
        . $whereSql
        . " ORDER BY CASE Regeltyp WHEN 'E' THEN 0 ELSE 1 END, Rang ASC, RegelID ASC"
        . ' LIMIT ' . $regelnProSeite
        . ' OFFSET ' . $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$regeln = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ersteRegel = $anzahlRegeln === 0 ? 0 : $offset + 1;
$letzteRegel = min($offset + $regelnProSeite, $anzahlRegeln);

function rule_ref_text($ref): string
{
    if (!is_array($ref)) return '';

    $id = trim((string)($ref['regel_id'] ?? ''));
    $version = $ref['version'] ?? null;
    $rank = $ref['rank'] ?? null;

    if ($id === '') return $rank !== null && $rank !== '' ? 'Rang ' . $rank : '';

    $text = $id;
    if ($version !== null && $version !== '') $text .= '/v' . $version;
    if ($rank !== null && $rank !== '') $text .= '/r' . $rank;

    return $text;
}

function lookup_text(array $werte, $id): string
{
    $key = (string)$id;
    if ($key === '' || $key === '0') return '';

    return isset($werte[$key]) && trim((string)$werte[$key]) !== '' ? (string)$werte[$key] : $key;
}

function test_result_text(string $key, $value, array $kategorien, array $dokumenttypen): string
{
    $raw = trim((string)$value);
    if ($raw === '') return '';

    if ($key === 'category' || $key === 'category2') {
        $text = lookup_text($kategorien, $raw);
        return $text !== $raw ? $text . ' (' . $raw . ')' : $raw;
    }

    if ($key === 'document_type') {
        $text = lookup_text($dokumenttypen, $raw);
        return $text !== $raw ? $text . ' (' . $raw . ')' : $raw;
    }

    return $raw;
}

function format_datetime_short($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '–';

    try {
        return (new DateTime($value))->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function format_terms($value): string
{
    $terms = array_values(array_filter(
        array_map('trim', explode(';', (string)$value)),
        static fn($term) => $term !== ''
    ));

    return implode(' · ', $terms);
}

function pagination_url(int $seite, string $filterText, string $filterTyp, string $filterAktiv, string $filterNutzung): string
{
    $query = [];

    if ($filterText !== '') $query['q'] = $filterText;
    if ($filterTyp !== '') $query['typ'] = $filterTyp;
    if ($filterAktiv !== '') $query['aktiv'] = $filterAktiv;
    if ($filterNutzung !== '') $query['nutzung'] = $filterNutzung;
    if ($seite > 1) $query['seite'] = $seite;

    return 'autokat_regeln.php' . ($query ? '?' . http_build_query($query) : '');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e_ablage - AutoKat-Regeln</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <link rel="icon" href="ea_icon4.png" type="image/png">
    <style>
        .ea-autokat-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:15px; }
        .ea-autokat-toolbar .form-group { margin-bottom:0; }
        .ea-autokat-heading { display:flex; justify-content:space-between; align-items:center; gap:10px; }
        .ea-autokat-table td { vertical-align:middle !important; }
        .ea-rule-main { width: 18%; min-width:190px; }
        .ea-rule-action { width:38px; text-align:center; white-space:nowrap; }
        .ea-rule-type { display:inline-block; min-width:28px; text-align:center; font-weight:700; padding:3px 7px; border-radius:12px; }
        .ea-rule-type--e { background:#fff4c2; color:#806000; border:1px solid #e3c85a; }
        .ea-rule-type--k { background:#e5f6e9; color:#26723b; border:1px solid #8cc79a; }
        .ea-rule-inactive { opacity:.55; }
        .ea-rule-terms { line-height:1.2; }
        .ea-rule-exclusions { margin-top:3px; color:#a94442; font-size:12px; }
        .ea-rule-meta { color:#777; font-size:12px; }
        .ea-rule-label { margin-top:3px; line-height:1.25; font-weight:600; color:#52606d; }
        .ea-rule-result { margin-top:6px; color:#555; font-size:12px; line-height:1.35; }
        .ea-rule-status-form { display:inline; margin:0; padding:0; }
        .ea-rule-status { border:0; background:transparent; padding:2px 4px; cursor:pointer; font:inherit; white-space:nowrap; }
        .ea-rule-status:hover, .ea-rule-status:focus { text-decoration:underline; outline:none; }
        .ea-rule-usage { white-space:nowrap; }
        .ea-rule-mobile-label { display:none; }
        .ea-rule-hit-count { font-weight:700; font-size:1.02rem; }
        .ea-rule-last-hit { margin-top:2px; color:#777; font-size:12px; white-space:nowrap; }
        .ea-test-result { margin-top:15px; }
        @media (max-width: 767px) {
            .ea-autokat-toolbar { display:block; }
            .ea-autokat-toolbar .form-group { margin-bottom:10px; }
            .ea-autokat-table { font-size:12px; }
            .ea-rule-main { min-width:160px; }
        }
        @media (max-width: 899px) {
            .ea-autokat-table tbody tr:not(.ea-rule-empty) {
                display:grid;
                grid-template-columns:minmax(0, 1fr) auto auto;
                align-items:start;
                gap:8px;
                padding:12px;
            }
            .ea-autokat-table td {
                padding:0;
                border-bottom:0;
            }
            .ea-autokat-table .ea-rule-main {
                grid-column:1;
                grid-row:1;
                min-width:0;
                width:auto;
                overflow-wrap:anywhere;
            }
            .ea-autokat-table .ea-rule-type-cell { grid-column:2; grid-row:1; }
            .ea-autokat-table .ea-rule-status-cell { grid-column:3; grid-row:1; white-space:nowrap; }
            .ea-autokat-table .ea-rule-rank,
            .ea-autokat-table .ea-rule-usage { display:none; }
            .ea-autokat-table .ea-rule-terms {
                grid-column:1 / -1;
                grid-row:2;
                border-top:1px solid var(--ea-gray-200);
                padding-top:8px;
                overflow-wrap:anywhere;
            }
            .ea-autokat-table .ea-rule-mobile-label {
                display:inline;
                font-weight:700;
                color:var(--ea-gray-700);
            }
        }
    </style>
</head>
<body>
<?php require_once 'navbar.php'; ?>
<div class="ea-main-container">
<div class="container">
    <?php if ($meldung !== ''): ?>
        <?=eablage_render_message($meldungTyp, $meldung)?>
    <?php endif; ?>

    <div class="panel panel-primary">
        <div class="panel-heading ea-autokat-heading">
            <span>🧩 Klassifizierungsregeln</span>
            <a href="autokat_regel_bearbeiten.php" class="btn btn-default ea-heading-btn">➕ Neue Regel</a>
        </div>
        <div class="panel-body">
            <form method="get" class="ea-autokat-toolbar">
                <div class="form-group" style="min-width:280px; flex:1;">
                    <label for="q">Suche</label>
                    <input type="text" class="form-control" id="q" name="q" value="<?=h($filterText)?>" placeholder="RegelID, Bezeichnung, Such- oder Ausschlussbegriff, Partner, Betreff ...">
                </div>
                <div class="form-group">
                    <label for="typ">Regeltyp</label>
                    <select class="form-control" id="typ" name="typ">
                        <option value="">Alle</option>
                        <option value="E" <?=$filterTyp === 'E' ? 'selected' : ''?>>Ergänzungsregel</option>
                        <option value="K" <?=$filterTyp === 'K' ? 'selected' : ''?>>Klassifikationsregel</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="aktiv">Status</label>
                    <select class="form-control" id="aktiv" name="aktiv">
                        <option value="">Alle</option>
                        <option value="1" <?=$filterAktiv === '1' ? 'selected' : ''?>>Aktiv</option>
                        <option value="0" <?=$filterAktiv === '0' ? 'selected' : ''?>>Inaktiv</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nutzung">Nutzung</label>
                    <select class="form-control" id="nutzung" name="nutzung">
                        <option value="">Alle</option>
                        <option value="mit" <?=$filterNutzung === 'mit' ? 'selected' : ''?>>Mit Treffern</option>
                        <option value="ohne" <?=$filterNutzung === 'ohne' ? 'selected' : ''?>>Ohne Treffer</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-default">🔍 Filtern</button>
                <a href="autokat_regeln.php" class="btn btn-link">Zurücksetzen</a>
            </form>

            <div class="search-summary">
                <strong><?=h($anzahlRegeln)?></strong> Regeln gefunden
                <?php if ($anzahlRegeln > 0): ?>
                    — angezeigt werden <?=h($ersteRegel)?> bis <?=h($letzteRegel)?>
                    — Seite <?=h($seite)?> von <?=h($anzahlSeiten)?>
                <?php endif; ?>
            </div>

            <?php if ($anzahlRegeln > 0): ?>
                <div class="pagination-wrapper">
                    <div>
                        <?php if ($seite > 1): ?>
                            <a class="btn btn-default btn-sm" href="<?=h(pagination_url($seite - 1, $filterText, $filterTyp, $filterAktiv, $filterNutzung))?>">&laquo; Vorherige</a>
                        <?php else: ?>
                            <button class="btn btn-default btn-sm" disabled>&laquo; Vorherige</button>
                        <?php endif; ?>

                        <?php if ($seite < $anzahlSeiten): ?>
                            <a class="btn btn-default btn-sm" href="<?=h(pagination_url($seite + 1, $filterText, $filterTyp, $filterAktiv, $filterNutzung))?>">Nächste &raquo;</a>
                        <?php else: ?>
                            <button class="btn btn-default btn-sm" disabled>Nächste &raquo;</button>
                        <?php endif; ?>
                    </div>
                    <div class="ea-pagination-info">Seite <?=h($seite)?> von <?=h($anzahlSeiten)?></div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-striped table-hover ea-autokat-table">
                    <thead>
                        <tr>
                            <th class="ea-rule-main">Regel / Bezeichnung</th>
                            <th>Typ</th>
                            <th>Rang</th>
                            <th>Suchbegriffe / Ergebnis</th>
                            <th>Nutzung</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$regeln): ?>
                        <tr class="ea-rule-empty"><td colspan="6" class="text-muted">Keine Regeln gefunden.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($regeln as $regel): ?>
                        <?php
                        $ziele = [];
                        $katText = lookup_text($kategorien, $regel['kat']);
                        $kat2Text = lookup_text($kategorien, $regel['kat2']);
                        $dokText = lookup_text($dokumenttypen, $regel['dok']);

                        if ($katText !== '') $ziele[] = 'Kategorie: ' . $katText;
                        if ($kat2Text !== '') $ziele[] = 'Kategorie 2: ' . $kat2Text;
                        if ($dokText !== '') $ziele[] = 'Dokumenttyp: ' . $dokText;
                        if ((string)$regel['partner'] !== '') $ziele[] = 'Partner: ' . $regel['partner'];
                        if ((string)$regel['betreff'] !== '') $ziele[] = 'Betreff: ' . $regel['betreff'];
                        foreach (eablage_regel_nummern_laden($db,(int)$regel['id']) as $n) $ziele[] = 'Nummernart ' . $n['NummernartID'] . ': ' . ($n['Startanker'] !== '' ? 'Anker ' . $n['Startanker'] : $n['Festwert']);
                        if ((string)$regel['tags'] !== '') $ziele[] = 'Tags: ' . $regel['tags'];

                        $suchbegriffe = format_terms($regel['Suchbegriffe']);
                        $ausschlussbegriffe = format_terms($regel['Ausschlussbegriffe']);
                        ?>
                        <tr class="<?=(int)$regel['Aktiv'] === 1 ? '' : 'ea-rule-inactive'?>">
                            <td class="ea-rule-main">
                                <?=h($regel['RegelID'])?>
                                <a
                                    href="autokat_regel_bearbeiten.php?regel=<?=rawurlencode($regel['RegelID'])?>"
                                    class="ea-rule-edit"
                                    title="Regel bearbeiten"
                                    aria-label="Regel <?=h($regel['RegelID'])?> bearbeiten"
                                >✏️</a>
                                <?php if (trim((string)$regel['Bezeichnung']) !== ''): ?>
                                    <div class="ea-rule-label"><?=h($regel['Bezeichnung'])?></div>
                                <?php endif; ?>
                                <div class="ea-rule-meta">Version <?=h($regel['Version'])?></div>
                            </td>
                            <td class="ea-rule-type-cell"><span class="ea-rule-type ea-rule-type--<?=strtolower(h($regel['Regeltyp']))?>" title="<?=$regel['Regeltyp'] === 'E' ? 'Ergänzungsregel' : 'Klassifikationsregel'?>" aria-label="<?=$regel['Regeltyp'] === 'E' ? 'Ergänzungsregel' : 'Klassifikationsregel'?>"><?=h($regel['Regeltyp'])?></span></td>
                            <td class="ea-rule-rank"><?=h($regel['Rang'])?></td>
                            <td class="ea-rule-terms">
                                <span class="ea-rule-mobile-label">Suchbegriffe: </span><?=h($suchbegriffe)?>
                                <?php if ($ausschlussbegriffe !== ''): ?>
                                    <div class="ea-rule-exclusions">Ausschluss: <?=h($ausschlussbegriffe)?></div>
                                <?php endif; ?>
                                <?php if ($ziele): ?>
                                    <div class="ea-rule-result"><span class="ea-rule-mobile-label">Ergebnis: </span><?=h(implode(' · ', $ziele))?></div>
                                <?php endif; ?>
                            </td>
                            <td class="ea-rule-usage">
                                <div class="ea-rule-hit-count"><?=h((int)$regel['Treffer'])?></div>
                                <div class="ea-rule-last-hit" title="Letzter Treffer">zuletzt: <?=h(format_datetime_short($regel['LetzterTreffer']))?></div>
                            </td>
                            <td class="ea-rule-status-cell">
                                <form method="post" class="ea-rule-status-form" data-status-toggle="rule">
                                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="regel_id" value="<?=h($regel['RegelID'])?>">
                                    <input type="hidden" name="aktiv" value="<?=(int)$regel['Aktiv'] === 1 ? '0' : '1'?>">
                                    <button type="submit" class="ea-rule-status" title="<?=(int)$regel['Aktiv'] === 1 ? 'Regel deaktivieren' : 'Regel aktivieren'?>" aria-label="<?=(int)$regel['Aktiv'] === 1 ? 'Regel deaktivieren' : 'Regel aktivieren'?>">
                                        <?=(int)$regel['Aktiv'] === 1 ? '✅ aktiv' : '⏸️ inaktiv'?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($anzahlRegeln > 0): ?>
                <div class="pagination-wrapper">
                    <div>
                        <?php if ($seite > 1): ?>
                            <a class="btn btn-default btn-sm" href="<?=h(pagination_url($seite - 1, $filterText, $filterTyp, $filterAktiv, $filterNutzung))?>">&laquo; Vorherige</a>
                        <?php else: ?>
                            <button class="btn btn-default btn-sm" disabled>&laquo; Vorherige</button>
                        <?php endif; ?>

                        <?php if ($seite < $anzahlSeiten): ?>
                            <a class="btn btn-default btn-sm" href="<?=h(pagination_url($seite + 1, $filterText, $filterTyp, $filterAktiv, $filterNutzung))?>">Nächste &raquo;</a>
                        <?php else: ?>
                            <button class="btn btn-default btn-sm" disabled>Nächste &raquo;</button>
                        <?php endif; ?>
                    </div>
                    <div class="ea-pagination-info">Seite <?=h($seite)?> von <?=h($anzahlSeiten)?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">🧪 AutoKat testen</h3></div>
        <div class="panel-body">
            <p class="text-muted">Der Beispieltext wird gegen den aktuell gespeicherten und aktiven Regelbestand im Python-Dienst getestet. Änderungen müssen daher vorher gespeichert sein.</p>
            <form method="post" action="autokat_regeln.php#test-ergebnis">
                <input type="hidden" name="action" value="test">
                <div class="form-group">
                    <textarea class="form-control" name="test_text" rows="7" placeholder="Beispieltext eines Dokuments einfügen ..."><?=h($testText)?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">▶️ Test ausführen</button>
            </form>
            <div id="test-ergebnis"></div>

            <?php if ($testFehler !== ''): ?>
                <div class="alert alert-warning ea-test-result">⚠️ <?=h($testFehler)?></div>
            <?php elseif (is_array($testResult)): ?>
                <div class="alert <?=!empty($testResult['matched']) ? 'alert-success' : 'alert-info'?> ea-test-result">
                    <?php if (!empty($testResult['matched'])): ?>
                        <?php
                        $eRefs = is_array($testResult['matched_supplement_rule_refs'] ?? null) ? $testResult['matched_supplement_rule_refs'] : [];
                        $kRefs = is_array($testResult['matched_classification_rule_refs'] ?? null) ? $testResult['matched_classification_rule_refs'] : [];
                        if (!$kRefs && is_array($testResult['matched_classification_rule_ref'] ?? null)) {
                            $kRefs = [$testResult['matched_classification_rule_ref']];
                        }
                        $primaryK = $kRefs[0] ?? null;
                        $additionalK = count($kRefs) > 1 ? array_slice($kRefs, 1) : [];
                        ?>
                        <strong>✅ Regel(n) erkannt.</strong>
                        <div style="margin-top:8px;">
                            <strong>Ergänzungsregeln:</strong> <?=$eRefs ? h(implode(', ', array_map('rule_ref_text', $eRefs))) : 'keine'?><br>
                            <strong>Primäre Klassifikationsregel:</strong> <?=is_array($primaryK) ? h(rule_ref_text($primaryK)) : 'keine'?>
                            <?php if ($additionalK): ?>
                                <br><strong>Weitere Klassifikationsregeln:</strong> <?=h(implode(', ', array_map('rule_ref_text', $additionalK)))?>
                            <?php endif; ?>
                        </div>
                        <?php if ($additionalK): ?>
                            <div style="margin-top:8px;"><strong>⚠️ Mehrfachtreffer:</strong> Für die Vorbelegung wird nur die primäre Klassifikationsregel mit dem niedrigsten Rang verwendet.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <strong>ℹ️ Keine passende AutoKat-Regel gefunden.</strong>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-condensed">
                        <thead><tr><th>Feld</th><th>Ergebnis</th><th>Quelle</th></tr></thead>
                        <tbody>
                        <?php
                        $felder = [
                            'date' => 'Datum', 'category' => 'Kategorie', 'category2' => 'Kategorie 2',
                            'document_type' => 'Dokumenttyp', 'partner' => 'Partner', 'subject' => 'Betreff',
                            'number' => 'Nummer', 'tags' => 'Tags'
                        ];
                        foreach ($felder as $key => $label):
                            $source = $testResult['sources'][$key] ?? null;
                            $anzeigeWert = test_result_text($key, $testResult[$key] ?? '', $kategorien, $dokumenttypen);
                        ?>
                            <tr>
                                <td><?=h($label)?></td>
                                <td><?=h($anzeigeWert)?></td>
                                <td>
                                    <?php if (is_array($source)): ?>
                                        <?php if (($source['type'] ?? '') === 'D'): ?>
                                            Allgemeine Datumserkennung
                                        <?php else: ?>
                                            <?=h(rule_ref_text($source))?><?=!empty($source['label']) ? ' · ' . h($source['label']) : ''?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
<script src="status_toggle.js?v=<?=filemtime(__DIR__ . '/status_toggle.js')?>" defer></script>
</body>
</html>

