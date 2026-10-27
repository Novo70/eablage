<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'nummern_funktionen.php';
require_once 'meldungen.php';
require_once 'csrf.php';

if (!isset($db) && isset($db_config)) {
    $db = $db_config;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function eablage_rule_return_url($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'autokat_regeln.php';
    }

    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return 'autokat_regeln.php';
    }

    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if (!in_array($path, ['autokat_regeln.php', 'verarbeiten.php', 'stammdaten.php', 'eingang.php'], true)) {
        return 'autokat_regeln.php';
    }

    return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
}

function next_regel_id(PDO $db): string
{
    $stmt = $db->query("SELECT RegelID FROM autokat WHERE RegelID REGEXP '^R[0-9]+$'");
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $regelId) {
        if (preg_match('/^R(\d+)$/', (string)$regelId, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'R' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

$fachfelder = [
    'Suchbegriffe', 'Ausschlussbegriffe', 'Regeltyp', 'kat', 'kat2', 'dok', 'partner', 'betreff', 'nr', 'tags',
    'prDatum', 'prBetreff', 'prBetreffAnz', 'BetreffLänge', 'BetreffEnde', 'prNum', 'prNumAnz', 'NumLänge', 'NumEnde'
];

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$aktionToken = eablage_aktion_token();
$isNewPost = $isPost && (($_POST['is_new'] ?? '') === '1');
$returnUrl = eablage_rule_return_url($_GET['return'] ?? $_POST['return'] ?? '');
$regelId = trim((string)($_GET['regel'] ?? $_POST['regel_id'] ?? ''));
$neu = $isNewPost || $regelId === '';
$fehler = '';
if ($isPost) {
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
    } catch (RuntimeException $e) {
        http_response_code(403);
        $fehler = $e->getMessage();
    }
}
$hinweis = '';
$flash = eablage_flash_get();

$db = $db ?? ($db_config ?? null);
$kategorien = eablage_lade_kategorien($db, true);
$dokumenttypen = eablage_lade_dokumenttypen($db, true);

$regel = [
    'RegelID' => $neu ? ($isNewPost ? trim((string)($_POST['regel_id'] ?? '')) : next_regel_id($db)) : $regelId,
    'Version' => 1,
    'Rang' => 100,
    'Regeltyp' => 'K',
    'Aktiv' => 1,
    'Bezeichnung' => '',
    'Kommentar' => '',
    'Suchbegriffe' => '', 'Ausschlussbegriffe' => '',
    'kat' => '', 'kat2' => '', 'dok' => '', 'partner' => '', 'betreff' => '', 'nr' => '', 'tags' => '',
    'prDatum' => '', 'prBetreff' => '', 'prBetreffAnz' => '', 'BetreffLänge' => 0, 'BetreffEnde' => '',
    'prNum' => '', 'prNumAnz' => '', 'NumLänge' => 0, 'NumEnde' => '',
];

$sourceDokId = null;
if ($neu && isset($_GET['source_dokid'])) {
    $rawSourceId = $_GET['source_dokid'];
    if (!is_string($rawSourceId) || !preg_match('/^[1-9][0-9]*$/D', $rawSourceId)) {
        http_response_code(400);
        die('Ungültige Dokument-ID für die Regelvorlage.');
    }
    $sourceDokId = (int)$rawSourceId;
    if (!$isPost) {
        $stmt = $db->prepare('SELECT kategorie_id, kategorie2_id, doktyp_id, Partner, Betreff, Nummer, Tags FROM dokumente WHERE DokID = :dokid AND GeloeschtAm IS NULL');
        $stmt->execute([':dokid' => $sourceDokId]);
        $sourceDocument = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sourceDocument) {
            http_response_code(404);
            die('Das archivierte Dokument für die Regelvorlage wurde nicht gefunden.');
        }
        $regel['Aktiv'] = 0;
        $regel['kat'] = (string)($sourceDocument['kategorie_id'] ?? '') === '0' ? '' : (string)($sourceDocument['kategorie_id'] ?? '');
        $regel['kat2'] = (string)($sourceDocument['kategorie2_id'] ?? '') === '0' ? '' : (string)($sourceDocument['kategorie2_id'] ?? '');
        $regel['dok'] = (string)($sourceDocument['doktyp_id'] ?? '') === '0' ? '' : (string)($sourceDocument['doktyp_id'] ?? '');
        $regel['partner'] = (string)($sourceDocument['Partner'] ?? '');
        $regel['betreff'] = (string)($sourceDocument['Betreff'] ?? '');
        $regel['nr'] = (string)($sourceDocument['Nummer'] ?? '');
        $regel['tags'] = (string)($sourceDocument['Tags'] ?? '');
    }
}

if (!$neu) {
    $stmt = $db->prepare('SELECT * FROM autokat WHERE RegelID = :regel_id');
    $stmt->execute([':regel_id' => $regelId]);
    $geladen = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$geladen) {
        http_response_code(404);
        die('AutoKat-Regel nicht gefunden.');
    }
    $regel = array_merge($regel, $geladen);
}

$numberDefinitions = !$neu ? eablage_regel_nummern_laden($db,(int)$regel['id']) : [];
if ($neu && $sourceDokId) {
    foreach (eablage_nummern_laden($db,$sourceDokId) as $n) $numberDefinitions[]=['NummernartID'=>$n['kind_id'],'Startanker'=>'','Endmodus'=>'zeile','Endanker'=>'','Festwert'=>$n['value'],'Praefix'=>'','MaxLaenge'=>0,'Sortierung'=>count($numberDefinitions)*10+10];
}
$numberDefinitionsRaw = $isPost && is_string($_POST['number_rules_json']??null) ? $_POST['number_rules_json'] : json_encode($numberDefinitions);
if ($isPost && $fehler === '') {
    $isNew = $isNewPost;
    $postedRegelId = trim((string)($_POST['regel_id'] ?? ''));

    foreach (array_keys($regel) as $feld) {
        if (array_key_exists($feld, $_POST) && !in_array($feld, ['RegelID', 'Version', 'id', 'id_alt'], true)) {
            $regel[$feld] = is_string($_POST[$feld]) ? trim($_POST[$feld]) : $_POST[$feld];
        }
    }
    $regel['RegelID'] = $postedRegelId;
    $regel['Aktiv'] = isset($_POST['Aktiv']) ? 1 : 0;
    $regel['Regeltyp'] = strtoupper(trim((string)$regel['Regeltyp']));
    $regel['Rang'] = (int)$regel['Rang'];
    $regel['BetreffLänge'] = (int)$regel['BetreffLänge'];
    $regel['NumLänge'] = (int)$regel['NumLänge'];

    if (!preg_match('/^R\d{4,}$/', $postedRegelId)) {
        $fehler = 'Die RegelID muss dem Schema Rxxxx entsprechen.';
    } elseif (!in_array($regel['Regeltyp'], ['E', 'K'], true)) {
        $fehler = 'Bitte einen gültigen Regeltyp auswählen.';
    } elseif (trim((string)$regel['Suchbegriffe']) === '') {
        $fehler = 'Mindestens ein Suchbegriff ist erforderlich.';
    } else {
        try {
            if ($isNew) {
                $check = $db->prepare('SELECT COUNT(*) FROM autokat WHERE RegelID = :regel_id');
                $check->execute([':regel_id' => $postedRegelId]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new RuntimeException('Diese RegelID existiert bereits. Bitte Seite neu laden und erneut versuchen.');
                }

                $sql = 'INSERT INTO autokat
                    (RegelID, Version, Rang, Regeltyp, Aktiv, Bezeichnung, Kommentar, Suchbegriffe, Ausschlussbegriffe,
                     kat, kat2, dok, partner, betreff, nr, tags, prDatum, prBetreff, prBetreffAnz, `BetreffLänge`, BetreffEnde,
                     prNum, prNumAnz, `NumLänge`, NumEnde)
                    VALUES
                    (:RegelID, 1, :Rang, :Regeltyp, :Aktiv, :Bezeichnung, :Kommentar, :Suchbegriffe, :Ausschlussbegriffe,
                     :kat, :kat2, :dok, :partner, :betreff, :nr, :tags, :prDatum, :prBetreff, :prBetreffAnz, :BetreffLaenge, :BetreffEnde,
                     :prNum, :prNumAnz, :NumLaenge, :NumEnde)';
                $version = 1;
            } else {
                $stmtAlt = $db->prepare('SELECT * FROM autokat WHERE RegelID = :regel_id');
                $stmtAlt->execute([':regel_id' => $postedRegelId]);
                $alt = $stmtAlt->fetch(PDO::FETCH_ASSOC);
                if (!$alt) {
                    throw new RuntimeException('Die Regel wurde zwischenzeitlich nicht gefunden.');
                }

                $fachlichGeaendert = false;
                foreach ($fachfelder as $feld) {
                    $altWert = trim((string)($alt[$feld] ?? ''));
                    $neuWert = trim((string)($regel[$feld] ?? ''));
                    if ($altWert !== $neuWert) {
                        $fachlichGeaendert = true;
                        break;
                    }
                }
                $version = (int)$alt['Version'] + ($fachlichGeaendert ? 1 : 0);

                $sql = 'UPDATE autokat SET
                    Version=:Version, Rang=:Rang, Regeltyp=:Regeltyp, Aktiv=:Aktiv, Bezeichnung=:Bezeichnung, Kommentar=:Kommentar,
                    Suchbegriffe=:Suchbegriffe, Ausschlussbegriffe=:Ausschlussbegriffe, kat=:kat, kat2=:kat2, dok=:dok,
                    partner=:partner, betreff=:betreff, nr=:nr, tags=:tags, prDatum=:prDatum,
                    prBetreff=:prBetreff, prBetreffAnz=:prBetreffAnz, `BetreffLänge`=:BetreffLaenge, BetreffEnde=:BetreffEnde,
                    prNum=:prNum, prNumAnz=:prNumAnz, `NumLänge`=:NumLaenge, NumEnde=:NumEnde
                    WHERE RegelID=:RegelID';
            }

            $params = [
                ':RegelID' => $postedRegelId,
                ':Rang' => $regel['Rang'], ':Regeltyp' => $regel['Regeltyp'], ':Aktiv' => $regel['Aktiv'],
                ':Bezeichnung' => $regel['Bezeichnung'], ':Kommentar' => $regel['Kommentar'],
                ':Suchbegriffe' => $regel['Suchbegriffe'], ':Ausschlussbegriffe' => $regel['Ausschlussbegriffe'],
                ':kat' => $regel['kat'], ':kat2' => $regel['kat2'], ':dok' => $regel['dok'], ':partner' => $regel['partner'],
                ':betreff' => $regel['betreff'], ':nr' => $regel['nr'], ':tags' => $regel['tags'], ':prDatum' => $regel['prDatum'],
                ':prBetreff' => $regel['prBetreff'], ':prBetreffAnz' => $regel['prBetreffAnz'], ':BetreffLaenge' => $regel['BetreffLänge'],
                ':BetreffEnde' => $regel['BetreffEnde'], ':prNum' => $regel['prNum'], ':prNumAnz' => $regel['prNumAnz'],
                ':NumLaenge' => $regel['NumLänge'], ':NumEnde' => $regel['NumEnde'],
            ];
            if (!$isNew) {
                $params[':Version'] = $version;
            }

            $db->beginTransaction();
            $newNumberDefinitions = eablage_regel_nummern_pruefen($db,$numberDefinitionsRaw);
            if (!$isNew && json_encode($newNumberDefinitions) !== json_encode($numberDefinitions) && !$fachlichGeaendert) {
                $version++; $params[':Version']=$version;
            }
            eablage_pruefe_zuordnungen($db, ['kategorie_id'=>$regel['kat'], 'kategorie2_id'=>$regel['kat2'], 'doktyp_id'=>$regel['dok']]);
            $stmtSave = $db->prepare($sql);
            $stmtSave->execute($params);
            $savedRuleStmt=$db->prepare('SELECT id FROM autokat WHERE RegelID=?'); $savedRuleStmt->execute([$postedRegelId]);
            eablage_regel_nummern_speichern($db,(int)$savedRuleStmt->fetchColumn(),$newNumberDefinitions);
            $db->commit();

            $msg = $isNew
                ? 'Regel ' . $postedRegelId . ' wurde angelegt.'
                : 'Regel ' . $postedRegelId . ' wurde gespeichert' . ($version > (int)($alt['Version'] ?? $version) ? ' und auf Version ' . $version . ' erhöht.' : '.');

            if ($returnUrl === 'autokat_regeln.php') {
                header('Location: autokat_regeln.php?msg=' . rawurlencode($msg));
            } else {
                if ($returnUrl === 'eingang.php') {
                    eablage_flash_set('success', $msg);
                }
                header('Location: ' . $returnUrl);
            }
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('e_ablage AutoKat Regel speichern: ' . $e->getMessage());
            $fehler = $e instanceof RuntimeException ? $e->getMessage() : 'Die Regel konnte nicht gespeichert werden. Details wurden protokolliert.';
        }
    }
}

function selected($a, $b): string { return (string)$a === (string)$b ? 'selected' : ''; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e_ablage - AutoKat-Regel bearbeiten</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <link rel="icon" href="ea_icon4.png" type="image/png">
    <style>
        .ea-rule-section { border:1px solid #ddd; border-radius:4px; margin-bottom:15px; background:#fff; }
        .ea-rule-section__title { padding:10px 15px; font-weight:700; background:#f7f7f7; border-bottom:1px solid #ddd; }
        .ea-rule-section__body { padding:15px; }
        .ea-rule-id { font-family:monospace; font-weight:700; }
        .ea-help { color:#777; font-size:12px; margin-top:4px; }
    </style>
</head>
<body>
<?php require_once 'navbar.php'; ?>
<div class="ea-main-container">
<div class="container" style="margin-top:20px; margin-bottom:30px;">
    <?php if ($fehler !== ''): ?>
        <?=eablage_render_message('danger', $fehler)?>
    <?php endif; ?>
    <?=eablage_render_flash($flash)?>
    <?php if ($sourceDokId !== null): ?>
        <?=eablage_render_message('info', 'Dokument ' . $sourceDokId . ' ist bereits archiviert. Die Regelvorlage übernimmt die Klassifikationswerte und bleibt zunächst inaktiv. Bitte Suchbegriffe festlegen und Betreff sowie Nummer auf wiederverwendbare Werte prüfen. Abbrechen verwirft nur die Regelvorlage.')?>
    <?php endif; ?>

    <div class="panel panel-primary">
        <div class="panel-heading">✏️ <?= $neu ? 'Neue AutoKat-Regel' : 'AutoKat-Regel ' . h($regel['RegelID']) ?></div>
        <div class="panel-body">
            <form method="post">
                <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                <input type="hidden" name="is_new" value="<?= $neu ? '1' : '0' ?>">
                <input type="hidden" name="regel_id" value="<?= h($regel['RegelID']) ?>">
                <input type="hidden" name="return" value="<?= h($returnUrl) ?>">

                <div class="ea-rule-section">
                    <div class="ea-rule-section__title">Identität und Steuerung</div>
                    <div class="ea-rule-section__body">
                        <div class="row">
                            <div class="col-md-2"><div class="form-group"><label>RegelID</label><input class="form-control ea-rule-id" value="<?= h($regel['RegelID']) ?>" readonly><div class="ea-help">Dauerhafte Identität; nicht änderbar.</div></div></div>
                            <div class="col-md-2"><div class="form-group"><label>Version</label><input class="form-control" value="<?= h($regel['Version']) ?>" readonly><div class="ea-help">Erhöht sich bei fachlichen Änderungen.</div></div></div>
                            <div class="col-md-2"><div class="form-group"><label for="Rang">Rang</label><input type="number" class="form-control" id="Rang" name="Rang" value="<?= h($regel['Rang']) ?>"></div></div>
                            <div class="col-md-3"><div class="form-group"><label for="Regeltyp">Regeltyp</label><select class="form-control" id="Regeltyp" name="Regeltyp"><option value="E" <?= selected($regel['Regeltyp'], 'E') ?>>E – Ergänzungsregel</option><option value="K" <?= selected($regel['Regeltyp'], 'K') ?>>K – Klassifikationsregel</option></select></div></div>
                            <div class="col-md-3"><div class="checkbox" style="margin-top:28px;"><label><input type="checkbox" name="Aktiv" value="1" <?= (int)$regel['Aktiv'] === 1 ? 'checked' : '' ?>> Regel aktiv</label></div></div>
                        </div>
                        <div class="form-group"><label for="Bezeichnung">Bezeichnung</label><input type="text" class="form-control" id="Bezeichnung" name="Bezeichnung" value="<?= h($regel['Bezeichnung']) ?>" placeholder="Kurze verständliche Beschreibung der Regel"></div>
                        <div class="form-group"><label for="Kommentar">Kommentar</label><textarea class="form-control" id="Kommentar" name="Kommentar" rows="2"><?= h($regel['Kommentar']) ?></textarea></div>
                    </div>
                </div>

                <div class="ea-rule-section">
                    <div class="ea-rule-section__title">Suchbedingungen</div>
                    <div class="ea-rule-section__body">
                        <div class="form-group">
                            <label for="Suchbegriffe">Suchbegriffe *</label>
                            <input type="text" class="form-control" id="Suchbegriffe" name="Suchbegriffe" value="<?= h($regel['Suchbegriffe']) ?>" required>
                            <div class="ea-help">Mehrere Begriffe durch Semikolon trennen. Alle Begriffe müssen im Dokument vorkommen; gesucht wird jeweils nach vollständigem Wort bzw. vollständiger Phrase.</div>
                        </div>
                        <div class="form-group">
                            <label for="Ausschlussbegriffe">Ausschlussbegriffe</label>
                            <input type="text" class="form-control" id="Ausschlussbegriffe" name="Ausschlussbegriffe" value="<?= h($regel['Ausschlussbegriffe']) ?>">
                            <div class="ea-help">Optional; mehrere Begriffe durch Semikolon trennen. Sobald einer dieser Begriffe vorkommt, trifft die Regel nicht zu.</div>
                        </div>
                    </div>
                </div>

                <div class="ea-rule-section">
                    <div class="ea-rule-section__title">Feste Klassifikationswerte</div>
                    <div class="ea-rule-section__body">
                        <div class="row">
                            <div class="col-md-4"><div class="form-group"><label for="kat">Kategorie</label><select class="form-control" id="kat" name="kat"><?php foreach ($kategorien as $id=>$bez): ?><option value="<?= h($id === '0' ? '' : $id) ?>" <?= selected($regel['kat'], $id === '0' ? '' : $id) ?>><?= h($bez) ?></option><?php endforeach; ?></select></div></div>
                            <div class="col-md-4"><div class="form-group"><label for="kat2">Kategorie 2</label><select class="form-control" id="kat2" name="kat2"><?php foreach ($kategorien as $id=>$bez): ?><option value="<?= h($id === '0' ? '' : $id) ?>" <?= selected($regel['kat2'], $id === '0' ? '' : $id) ?>><?= h($bez) ?></option><?php endforeach; ?></select></div></div>
                            <div class="col-md-4"><div class="form-group"><label for="dok">Dokumenttyp</label><select class="form-control" id="dok" name="dok"><?php foreach ($dokumenttypen as $id=>$bez): ?><option value="<?= h($id === '0' ? '' : $id) ?>" <?= selected($regel['dok'], $id === '0' ? '' : $id) ?>><?= h($bez) ?></option><?php endforeach; ?></select></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label for="partner">Partner</label><input type="text" class="form-control" id="partner" name="partner" value="<?= h($regel['partner']) ?>"></div></div>
                            <div class="col-md-6"><div class="form-group"><label for="betreff">Betreff</label><input type="text" class="form-control" id="betreff" name="betreff" value="<?= h($regel['betreff']) ?>"></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label for="tags">Tags</label><input type="text" class="form-control" id="tags" name="tags" value="<?= h($regel['tags']) ?>"></div></div>
                        </div>
                    </div>
                </div>

                <div class="ea-rule-section">
                    <div class="ea-rule-section__title">Dynamische Extraktion aus Dokumenttext</div>
                    <div class="ea-rule-section__body">
                        <div class="form-group"><label for="prDatum">Datumsanker (prDatum)</label><input type="text" class="form-control" id="prDatum" name="prDatum" value="<?= h($regel['prDatum']) ?>"></div>
                        <hr>
                        <div class="row">
                            <div class="col-md-4"><div class="form-group"><label for="prBetreff">Betreff-Anker</label><input type="text" class="form-control" id="prBetreff" name="prBetreff" value="<?= h($regel['prBetreff']) ?>"></div></div>
                            <div class="col-md-3"><div class="form-group"><label for="prBetreffAnz">Anzeigepräfix</label><input type="text" class="form-control" id="prBetreffAnz" name="prBetreffAnz" value="<?= h($regel['prBetreffAnz']) ?>"></div></div>
                            <div class="col-md-2"><div class="form-group"><label for="BetreffLänge">max. Länge</label><input type="number" min="0" class="form-control" id="BetreffLänge" name="BetreffLänge" value="<?= h($regel['BetreffLänge']) ?>"></div></div>
                            <div class="col-md-3"><div class="form-group"><label for="BetreffEnde">Endmarkierung</label><input type="text" class="form-control" id="BetreffEnde" name="BetreffEnde" value="<?= h($regel['BetreffEnde']) ?>"></div></div>
                        </div>
                        <div class="row">
                        </div>
                    </div>
                </div>

                <div class="ea-rule-section"><div class="ea-rule-section__title">Nummern erkennen</div><div class="ea-rule-section__body" id="number-rule-editor">
                <p>Je Nummernart wird ausschließlich das erste Vorkommen des Startankers ausgewertet. Ein Festwert wird verwendet, wenn kein Startanker angegeben ist.</p>
                <div data-rule-number-rows></div><button type="button" class="btn btn-default" data-rule-number-add>Nummernerkennung hinzufügen</button>
                <input type="hidden" name="number_rules_json" id="number_rules_json" value="<?=h($numberDefinitionsRaw)?>">
                <script type="application/json" id="rule-number-kinds"><?=json_encode($db->query('SELECT id,Bezeichnung FROM nummernart WHERE Aktiv=1 ORDER BY Sortierung,Bezeichnung')->fetchAll(PDO::FETCH_ASSOC),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
                </div></div><script src="nummern_regeln.js"></script>
                <div class="ea-form-actions">
                    <a href="<?= h($returnUrl) ?>" class="btn btn-default">Abbrechen</a>
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
</body>
</html>

