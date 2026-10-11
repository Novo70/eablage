<?php
require_once 'konfiguration.php';
require_once 'meldungen.php';
require_once 'csrf.php';
require_once 'papierkorb_funktionen.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$db = $db_config;
$token = eablage_aktion_token();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $dokId = filter_input(INPUT_POST, 'dokid', FILTER_VALIDATE_INT);
        if (!$dokId || $dokId < 1) throw new RuntimeException('Ungültige Dokument-ID.');
        $action = $_POST['action'] ?? '';
        if ($action === 'trash' || $action === 'restore') {
            eablage_papierkorb_aendern($db, $dokId, $action);
        } elseif ($action === 'purge') {
            if (($_POST['confirm_id'] ?? '') !== (string)$dokId) throw new RuntimeException('Bitte die Dokument-ID zur Bestätigung eingeben.');
            $retention = $_POST['retain_history_details'] ?? '';
            if (!in_array($retention, ['0', '1'], true)) throw new RuntimeException('Bitte den Papierkorb neu öffnen und den Löschhinweis prüfen.');
            eablage_papierkorb_endgueltig($db, $dokId, PFAD_ARCHIV, PFAD_META, $retention === '1');
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
        eablage_flash_set('success', $action === 'trash' ? 'Dokument in den Papierkorb verschoben.' : ($action === 'restore' ? 'Dokument wiederhergestellt.' : 'Dokument endgültig gelöscht.'));
        header('Location: papierkorb.php');
        exit;
    } catch (Throwable $e) {
        error_log('e_ablage Papierkorb: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Die Aktion konnte nicht abgeschlossen werden.';
    }
}

$flash = eablage_flash_get();
try {
    $retainDetails = eablage_protokoll_loeschdaten_behalten($db);
    $deletionNotice = $retainDetails
        ? 'Im Ereignisprotokoll bleiben identifizierende Dokumentangaben und bisherige Details erhalten.'
        : 'Alle Details der bisherigen Protokolleinträge dieses Dokuments werden ebenfalls gelöscht. DokID, Zeitpunkt, Ereignistyp und Quelle bleiben erhalten.';
    $documents = $db->query('SELECT DokID, Datum, Partner, Betreff, GeloeschtAm FROM dokumente WHERE GeloeschtAm IS NOT NULL ORDER BY GeloeschtAm DESC, DokID DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('e_ablage Papierkorb/Liste: ' . $e->getMessage());
    $documents = [];
    $error = 'Der Papierkorb konnte nicht geladen werden. Datenbankmigration prüfen.';
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage – Papierkorb</title>
    <link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
</head>

<body><?php require 'navbar.php'; ?><main class="container">
        <?= eablage_render_flash($flash) ?>
        <?php if ($error !== ''): ?><?= eablage_render_message('danger', $error) ?><?php endif; ?>
        <div class="panel panel-primary">
            <div class="panel-heading">🗑️ Papierkorb (<?= count($documents) ?> Dokumente)</div>
            <div class="panel-body">
                <p>Die PDF und die Metadatei bleiben bis zum endgültigen Löschen im Archiv. Wiederherstellen macht das Dokument erneut sichtbar.</p>
                <?php if (!$error): ?><p><?= h($deletionNotice) ?></p><?php endif; ?>
                <?php if (!$documents): ?><p>Der Papierkorb ist leer.</p><?php endif; ?>
                <?php foreach ($documents as $document): $id = (int)$document['DokID']; ?>
                    <div class="ea-inbox-entry" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                        <span><strong>DokID <?= h($id) ?></strong> · <?= h($document['Datum'] ?? '') ?> · <?= h($document['Partner'] ?? '') ?> · <?= h($document['Betreff'] ?? '') ?> <small>(seit <?= h($document['GeloeschtAm']) ?>)</small></span>
                        <form method="post"><input type="hidden" name="aktion_token" value="<?= h($token) ?>"><input type="hidden" name="dokid" value="<?= h($id) ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-default btn-sm">Wiederherstellen</button></form>
                        <form method="post" onsubmit="return confirm(<?= h(json_encode('Dokument ' . $id . ' samt PDF, Metadatei und Dokumentdatensatz endgültig löschen? ' . $deletionNotice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"><input type="hidden" name="aktion_token" value="<?= h($token) ?>"><input type="hidden" name="dokid" value="<?= h($id) ?>"><input type="hidden" name="action" value="purge"><input type="hidden" name="retain_history_details" value="<?= $retainDetails ? '1' : '0' ?>"><label>DokID bestätigen: <input name="confirm_id" size="7" inputmode="numeric" required></label> <button class="btn btn-danger btn-sm">Endgültig löschen</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</body>

</html>
