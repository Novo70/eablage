<?php
require_once __DIR__ . '/icons.php';
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

function master_help(string $label, string $text): string {
    return '<button type="button" class="ea-help-button" data-help-tooltip="' . h($text)
        . '" title="' . h($text) . '" aria-label="Hinweis zu ' . h($label) . '">?</button>';
}
$masterHints = [
    'Bezeichnung' => 'Umbenennen gilt auch für bestehende Dokumente.',
    'Erläuterung' => 'Die Erläuterung dient der eigenen Dokumentation und hilft der KI, Dokumente passend zu klassifizieren.',
    'Status' => 'Deaktivierte Einträge bleiben recherchierbar und vorhandene Zuordnungen bleiben erhalten. Deaktivieren ist erst ohne Regelverknüpfungen möglich.',
    'Aktionen' => 'Löschen ist nur ohne Dokumentverknüpfungen und ohne Regelverknüpfungen möglich.',
];

function master_call(string $kind, array $payload): array {
    $payload['database'] = MYSQL_DATENBANK;
    $response = eablage_backend_request('POST', '/master-data/' . $kind, $payload, 20);
    if (!is_array($response['data'])) throw new RuntimeException($response['error'] . ' Nach einem Verbindungsabbruch bitte den aktuellen Stand prüfen.');
    return ['ok'=>$response['ok'] && $response['http_status'] === 200, 'data'=>$response['data']];
}

$kind = $_POST['kind'] ?? $_GET['kind'] ?? 'categories';
if (!is_string($kind) || !in_array($kind, ['categories', 'document_types', 'number_types'], true)) $kind = 'categories';
$title = ['categories'=>'Kategorien','document_types'=>'Dokumententypen','number_types'=>'Nummernarten'][$kind];
$error = '';
$blockingRules = [];
$items = [];
$newName = '';
$newExplanation = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $action = $_POST['action'] ?? '';
        $id = $_POST['entry_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $explanation = $_POST['explanation'] ?? null;
        if (!is_string($action) || !in_array($action, ['create','rename','activate','deactivate','delete'], true)
            || !is_string($id) || !is_string($name) || ($explanation !== null && !is_string($explanation))) throw new RuntimeException('Ungültige Eingabe.');
        if ($statusRequest && !in_array($action, ['activate', 'deactivate', 'rename', 'delete'], true)) {
            throw new RuntimeException('Ungültige Zeilenaktion.');
        }
        $newName = $action === 'create' ? $name : '';
        $newExplanation = $action === 'create' ? ($explanation ?? '') : '';
        $payload = ['action'=>$action, 'entry_id'=>$id, 'name'=>$name];
        if ($explanation !== null) $payload['explanation'] = $explanation;
        if ($kind==='number_types' && isset($_POST['sort_order'])) {
            $sort=filter_var($_POST['sort_order'],FILTER_VALIDATE_INT);
            if ($sort===false || $sort<0 || $sort>9999) throw new RuntimeException('Ungültige Sortierung.');
            $payload['sort_order']=$sort;
        }
        $response = master_call($kind, $payload);
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
    if ($error !== '' && ($action ?? '') === 'rename') {
        foreach ($items as &$item) {
            if ((string)$item['id'] === $id) { $item['name'] = $name; $item['explanation'] = $explanation ?? ($item['explanation'] ?? ''); }
        }
        unset($item);
    }
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

</head>
<body>
<?php require 'navbar.php'; ?>
<div class="container ea-master-container">
    <div class="ea-admin-notices">
    <?php if ($message !== ''): ?><?=eablage_render_message('success', $message)?><?php endif; ?>
    <?php if ($error !== ''): ?><?=eablage_render_message('danger', $error)?><?php endif; ?>
    <?php if ($blockingRules): ?>
        <ul><?php foreach ($blockingRules as $rule): ?>
            <li><a href="autokat_regel_bearbeiten.php?regel=<?=rawurlencode($rule['RegelID'])?>&amp;return=<?=rawurlencode('stammdaten.php?kind=' . $kind)?>"><?=h($rule['RegelID'] . ' – ' . ($rule['Bezeichnung'] ?? ''))?></a>
                <?=!(int)$rule['Aktiv'] ? '(inaktive Regel)' : ''?></li>
        <?php endforeach; ?></ul>
    <?php endif; ?>
    </div>
    <div class="panel panel-primary ea-master-panel">
        <div class="panel-heading"><h1 class="ea-master-heading"><?=eablage_icon('table')?>Stammdaten</h1></div>
        <div class="panel-body">
            <nav class="ea-tabs" aria-label="Stammdatenbereiche">
                <a href="stammdaten.php?kind=categories" <?=$kind === 'categories' ? 'aria-current="page"' : ''?>>Kategorien</a>
                <a href="stammdaten.php?kind=document_types" <?=$kind === 'document_types' ? 'aria-current="page"' : ''?>>Dokumententypen</a>
                <a href="stammdaten.php?kind=number_types" <?=$kind === 'number_types' ? 'aria-current="page"' : ''?>>Nummernarten</a>
            </nav>
            <div class="ea-master-scroll">
            <details class="ea-master-section ea-master-create" id="master-create" <?= $error !== '' && ($action ?? '') === 'create' ? 'open' : '' ?>>
                <summary id="master-create-heading">Neuen Eintrag anlegen</summary>
                    <form method="POST" action="stammdaten.php">
                        <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        <input type="hidden" name="kind" value="<?=h($kind)?>">
                        <input type="hidden" name="action" value="create">
                        <div<?=$kind==='number_types' ? ' class="ea-master-create-number-fields"' : ''?>>
                            <div>
                                <label for="new-name">Bezeichnung</label>
                                <input class="form-control" id="new-name" name="name" required maxlength="255" value="<?=h($newName)?>">
                            </div>
                            <?php if ($kind==='number_types'): ?><div><label for="new-sort-order">Sortierung</label><input type="number" id="new-sort-order" name="sort_order" class="form-control" min="0" max="9999" value="10"></div><?php endif; ?>
                        </div>
                        <label class="ea-master-new-explanation" for="new-explanation">Erläuterung (optional, bis 1000 Zeichen)</label>
                        <textarea class="form-control" id="new-explanation" name="explanation" maxlength="1000" rows="2"><?=h($newExplanation)?></textarea>
                        <p>Definition, typische Inhalte und Abgrenzungen dokumentieren die Ablagesystematik und unterstützen die lokale KI.</p>
                        <p>Neue Einträge sind aktiv.</p>
                        <button class="btn btn-primary" type="submit">Anlegen</button>
                    </form>
            </details>
            <section class="ea-master-section" aria-label="<?=h($title)?>">
                    <div class="ea-master-mobile-help" aria-label="Hinweise zu Stammdaten">
                        <?php foreach ($masterHints as $label => $text): ?><span><?=h($label)?> <?=master_help($label, $text)?></span><?php endforeach; ?>
                    </div>
                    <div class="table-responsive">
                    <table class="table table-striped ea-master-table<?=$kind==='number_types' ? ' ea-master-table--numbers' : ''?>"<?=$kind==='number_types' ? ' data-master-sort' : ''?>>
                        <colgroup>
                            <col class="ea-master-name-col"><col>
                            <?php if ($kind==='number_types'): ?><col class="ea-master-sort-col"><?php endif; ?>
                            <col class="ea-master-usage-col"><col class="ea-master-actions-col"><col class="ea-master-status-col">
                        </colgroup>
                        <thead><tr>
                            <?php if ($kind==='number_types'): ?>
                            <th aria-sort="none"><button type="button" class="ea-master-sort-button" data-sort-key="name">Bezeichnung<span data-sort-arrow aria-hidden="true"></span></button> <?=master_help('Bezeichnung', $masterHints['Bezeichnung'])?></th>
                            <th>Erläuterung <?=master_help('Erläuterung', $masterHints['Erläuterung'])?></th>
                            <th aria-sort="ascending"><button type="button" class="ea-master-sort-button" data-sort-key="sort_order">Sortierung<span data-sort-arrow aria-hidden="true"> ↑</span></button></th>
                            <?php else: ?><th>Bezeichnung <?=master_help('Bezeichnung', $masterHints['Bezeichnung'])?></th><th>Erläuterung <?=master_help('Erläuterung', $masterHints['Erläuterung'])?></th><?php endif; ?>
                            <th>Verwendung</th><th>Aktionen <?=master_help('Aktionen', $masterHints['Aktionen'])?></th><th>Status <?=master_help('Status', $masterHints['Status'])?></th>
                        </tr></thead>
                        <tbody><?php foreach ($items as $item): ?>
                        <tr>
                            <td class="ea-master-name-cell">
                                <form method="POST" action="stammdaten.php" data-row-action="rename" class="ea-master-edit" id="master-edit-<?=bin2hex((string)$item['id'])?>">
                                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                                    <input type="hidden" name="kind" value="<?=h($kind)?>">
                                    <input type="hidden" name="entry_id" value="<?=h($item['id'])?>">
                                    <input type="hidden" name="action" value="rename">
                                    <input class="form-control" name="name" required maxlength="255" aria-label="Bezeichnung: <?=h($item['name'])?>" value="<?=h($item['name'])?>">
                                </form>
                            </td>
                            <td class="ea-master-explanation-cell">
                                    <details class="ea-master-explanation">
                                        <summary>Erläuterung <?=trim((string)($item['explanation'] ?? '')) !== '' ? '(vorhanden)' : '(optional)'?></summary>
                                        <textarea class="form-control" name="explanation" form="master-edit-<?=bin2hex((string)$item['id'])?>" maxlength="1000" rows="2" aria-label="Erläuterung: <?=h($item['name'])?>"><?=h($item['explanation'] ?? '')?></textarea>
                                    </details>
                            </td>
                            <?php if ($kind==='number_types'): ?>
                            <td class="ea-master-sort" data-label="Sortierung"><input type="number" name="sort_order" form="master-edit-<?=bin2hex((string)$item['id'])?>" class="form-control" min="0" max="9999" aria-label="Sortierung: <?=h($item['name'])?>" value="<?=h($item['sort_order']??10)?>"></td>
                            <?php endif; ?>
                            <td class="ea-master-usage-cell"><div><?=h($item['documents'])?> Dokumente</div>
                                <?php if ($item['rules']): ?>
                                    <details><summary class="ea-master-rule-count"><?=count($item['rules'])?> AutoKat-Regeln</summary>
                                    <ul><?php foreach ($item['rules'] as $rule): ?>
                                        <li><a href="autokat_regel_bearbeiten.php?regel=<?=rawurlencode($rule['RegelID'])?>&amp;return=<?=rawurlencode('stammdaten.php?kind=' . $kind)?>"><?=h($rule['RegelID'])?></a><?=!(int)$rule['Aktiv'] ? ' (inaktiv)' : ''?></li>
                                    <?php endforeach; ?></ul></details>
                                <?php else: ?><span class="ea-master-rule-count">0 AutoKat-Regeln</span><?php endif; ?>
                            </td>
                            <td class="ea-master-actions-cell">
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
                            <td class="ea-master-status-cell">
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
</div>
<script src="help_tooltip.js?v=<?=filemtime(__DIR__ . '/help_tooltip.js')?>" defer></script>
<script src="master_data_layout.js?v=<?=filemtime(__DIR__ . '/master_data_layout.js')?>" defer></script>
<script src="status_toggle.js?v=<?=filemtime(__DIR__ . '/status_toggle.js')?>" defer></script>
<?php if ($kind==='number_types'): ?><script src="master_data_sort.js?v=<?=filemtime(__DIR__ . '/master_data_sort.js')?>" defer></script><?php endif; ?>
</body>
</html>

