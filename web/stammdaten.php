<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'meldungen.php';
require_once 'status_toggle.php';
require_once 'csrf.php';
$statusRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['status_inline'] ?? '') === '1';
$aktionToken = eablage_aktion_token();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$message = $_SESSION['master_message'] ?? '';
unset($_SESSION['master_message']);
session_write_close();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function master_call(string $kind, array $payload): array {
    $payload['database'] = MYSQL_DATENBANK;
    $response = eablage_backend_request('POST', '/master-data/' . $kind, $payload, 20);
    if (!is_array($response['data'])) throw new RuntimeException($response['error'] . ' Nach einem Verbindungsabbruch bitte den aktuellen Stand prüfen.');
    return ['ok'=>$response['ok'] && $response['http_status'] === 200, 'data'=>$response['data']];
}

$kind = $_POST['kind'] ?? $_GET['kind'] ?? 'categories';
if (!is_string($kind) || !in_array($kind, ['categories', 'document_types'], true)) $kind = 'categories';
$title = $kind === 'categories' ? 'Kategorien' : 'Dokumententypen';
$error = '';
$blockingRules = [];
$items = [];
$newName = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $action = $_POST['action'] ?? '';
        $id = $_POST['entry_id'] ?? '';
        $name = $_POST['name'] ?? '';
        if (!is_string($action) || !in_array($action, ['create','rename','activate','deactivate','delete'], true)
            || !is_string($id) || !is_string($name)) throw new RuntimeException('Ungültige Eingabe.');
        if ($statusRequest && !in_array($action, ['activate', 'deactivate', 'rename', 'delete'], true)) {
            throw new RuntimeException('Ungültige Zeilenaktion.');
        }
        $newName = $action === 'create' ? $name : '';
        $response = master_call($kind, ['action'=>$action, 'entry_id'=>$id, 'name'=>$name]);
        if ($response['ok']) {
            if ($statusRequest) {
                $result = ['ok'=>true, 'action'=>$action];
                if (in_array($action, ['activate', 'deactivate'], true)) $result['active'] = $action === 'activate';
                eablage_status_json($result);
            }
            session_start();
            $_SESSION['master_message'] = $response['data']['message'];
            session_write_close();
            header('Location: stammdaten.php?kind=' . $kind);
            exit;
        }
        $detail = $response['data']['detail'] ?? [];
        $error = is_array($detail) && isset($detail['message']) ? $detail['message'] : 'Eingabe konnte nicht verarbeitet werden.';
        $blockingRules = is_array($detail) ? ($detail['rules'] ?? []) : [];
    }
    if ($statusRequest) eablage_status_json(['ok'=>false, 'message'=>$error, 'rules'=>$blockingRules], 422);
    $response = master_call($kind, ['action'=>'list']);
    if (!$response['ok']) {
        $detail = $response['data']['detail'] ?? [];
        throw new RuntimeException(is_array($detail) ? ($detail['message'] ?? 'Stammdaten nicht verfügbar.') : 'Stammdaten nicht verfügbar.');
    }
    $items = $response['data']['items'];
} catch (Throwable $e) {
    $error = $e->getMessage();
    if ($statusRequest) eablage_status_json(['ok'=>false, 'message'=>$error], 400);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage – Stammdaten</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="icon" href="ea_icon4.png">
    <style>
        .ea-master-heading { margin:0; font:inherit; }
        .ea-master-section { margin-top:24px; }
        .ea-master-section + .ea-master-section { padding-top:24px; border-top:1px solid var(--ea-gray-300); }
        .ea-master-section h2 { margin:0 0 16px; font-size:1.05rem; color:var(--ea-blue-900); }
        .ea-master-table td { vertical-align:middle; }
        .ea-master-edit { margin:0; }
        .ea-master-edit .form-control { min-width:0; }
        .ea-master-actions { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
        .ea-master-actions form { margin:0; }
        .ea-master-actions .btn { margin:0; white-space:nowrap; }
        .ea-master-status-form { display:inline; margin:0; padding:0; }
        .ea-master-status { border:0; background:transparent; padding:2px 4px; cursor:pointer; font:inherit; white-space:nowrap; }
        .ea-master-status:not(:disabled):hover, .ea-master-status:not(:disabled):focus { text-decoration:underline; }
        .ea-master-status:disabled { opacity:.55; filter:grayscale(1); cursor:not-allowed; }
        @media (min-width:900px) {
            .ea-master-edit { min-width:280px; }
        }
    </style>
</head>
<body>
<?php require 'navbar.php'; ?>
<div class="container">
    <?php if ($message !== ''): ?><?=eablage_render_message('success', $message)?><?php endif; ?>
    <?php if ($error !== ''): ?><?=eablage_render_message('danger', $error)?><?php endif; ?>
    <?php if ($blockingRules): ?>
        <ul><?php foreach ($blockingRules as $rule): ?>
            <li><a href="autokat_regel_bearbeiten.php?regel=<?=rawurlencode($rule['RegelID'])?>&amp;return=<?=rawurlencode('stammdaten.php?kind=' . $kind)?>"><?=h($rule['RegelID'] . ' – ' . ($rule['Bezeichnung'] ?? ''))?></a>
                <?=!(int)$rule['Aktiv'] ? '(inaktive Regel)' : ''?></li>
        <?php endforeach; ?></ul>
    <?php endif; ?>
    <div class="panel panel-primary">
        <div class="panel-heading"><h1 class="ea-master-heading">🗂️ Stammdaten</h1></div>
        <div class="panel-body">
            <nav class="ea-tabs" aria-label="Stammdatenbereiche">
                <a href="stammdaten.php?kind=categories" <?=$kind === 'categories' ? 'aria-current="page"' : ''?>>Kategorien</a>
                <a href="stammdaten.php?kind=document_types" <?=$kind === 'document_types' ? 'aria-current="page"' : ''?>>Dokumententypen</a>
            </nav>
            <section class="ea-master-section" aria-labelledby="master-create-heading">
                <h2 id="master-create-heading">Neuen Eintrag anlegen</h2>
                    <form method="POST" action="stammdaten.php">
                        <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        <input type="hidden" name="kind" value="<?=h($kind)?>">
                        <input type="hidden" name="action" value="create">
                        <label for="new-name">Bezeichnung</label>
                        <input class="form-control" id="new-name" name="name" required maxlength="255" value="<?=h($newName)?>">
                        <p>Neue Einträge sind aktiv. Die ID wird automatisch vergeben.</p>
                        <button class="btn btn-primary" type="submit">Anlegen</button>
                    </form>
            </section>
            <section class="ea-master-section" aria-labelledby="master-list-heading">
                <h2 id="master-list-heading"><?=h($title)?></h2>
                    <p>Umbenennen gilt auch für bestehende Dokumente. Deaktivierte Einträge bleiben recherchierbar und vorhandene Zuordnungen bleiben erhalten.</p>
                    <p>Deaktivieren ist erst ohne Regelverknüpfungen möglich; Löschen zusätzlich nur ohne Dokumentverknüpfungen.</p>
                    <div class="table-responsive">
                    <table class="table table-striped ea-master-table">
                        <thead><tr><th>ID</th><th>Bezeichnung</th><th>Verwendung</th><th>Aktionen</th><th>Status</th></tr></thead>
                        <tbody><?php foreach ($items as $item): ?>
                        <tr>
                            <td><?=h($item['id'])?></td>
                            <td>
                                <form method="POST" action="stammdaten.php" data-row-action="rename" class="ea-master-edit" id="master-edit-<?=bin2hex((string)$item['id'])?>">
                                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                                    <input type="hidden" name="kind" value="<?=h($kind)?>">
                                    <input type="hidden" name="entry_id" value="<?=h($item['id'])?>">
                                    <input type="hidden" name="action" value="rename">
                                    <input class="form-control" name="name" required maxlength="255" aria-label="Bezeichnung für ID <?=h($item['id'])?>" value="<?=h($item['name'])?>">
                                </form>
                            </td>
                            <td><?=h($item['documents'])?> Dokumente
                                <?php if ($item['rules']): ?>
                                    <details><summary><?=count($item['rules'])?> AutoKat-Regeln</summary>
                                    <ul><?php foreach ($item['rules'] as $rule): ?>
                                        <li><a href="autokat_regel_bearbeiten.php?regel=<?=rawurlencode($rule['RegelID'])?>&amp;return=<?=rawurlencode('stammdaten.php?kind=' . $kind)?>"><?=h($rule['RegelID'])?></a><?=!(int)$rule['Aktiv'] ? ' (inaktiv)' : ''?></li>
                                    <?php endforeach; ?></ul></details>
                                <?php else: ?>, 0 Regeln<?php endif; ?>
                            </td>
                            <td>
                                <div class="ea-master-actions">
                                    <button class="btn btn-default btn-sm" type="submit" form="master-edit-<?=bin2hex((string)$item['id'])?>">Speichern</button>
                                <form method="POST" action="stammdaten.php" data-row-action="delete">
                                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                                    <input type="hidden" name="kind" value="<?=h($kind)?>">
                                    <input type="hidden" name="entry_id" value="<?=h($item['id'])?>">
                                    <button class="btn btn-danger btn-sm" type="submit" name="action" value="delete"
                                        <?=($item['documents'] || $item['rules']) ? 'disabled' : ''?>
                                        onclick="return confirm('Diesen unbenutzten Eintrag endgültig löschen?');">Löschen</button>
                                </form>
                                </div>
                            </td>
                            <td>
                                <form method="POST" action="stammdaten.php" class="ea-master-status-form" data-status-toggle="master" data-has-rules="<?=$item['rules'] ? '1' : '0'?>">
                                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                                    <input type="hidden" name="kind" value="<?=h($kind)?>">
                                    <input type="hidden" name="entry_id" value="<?=h($item['id'])?>">
                                    <button class="ea-master-status" type="submit" name="action" value="<?=(int)$item['active'] ? 'deactivate' : 'activate'?>"
                                        <?=((int)$item['active'] && $item['rules']) ? 'disabled' : ''?>
                                        title="<?=((int)$item['active'] && $item['rules']) ? 'Deaktivieren nicht möglich: AutoKat-Regeln sind verknüpft.' : ((int)$item['active'] ? 'Eintrag deaktivieren' : 'Eintrag reaktivieren')?>"
                                        aria-label="<?=h($item['name'])?>: <?=(int)$item['active'] ? 'Eintrag deaktivieren' : 'Eintrag reaktivieren'?>">
                                        <?=(int)$item['active'] ? '✅ aktiv' : '⏸️ inaktiv'?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                    </div>
            </section>
        </div>
    </div>
</div>
<script src="status_toggle.js?v=<?=filemtime(__DIR__ . '/status_toggle.js')?>" defer></script>
</body>
</html>
