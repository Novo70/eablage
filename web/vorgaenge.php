<?php
require_once __DIR__ . '/dokumentvorschau.php';
require_once __DIR__ . '/icons.php';
require_once 'konfiguration.php';
require_once 'csrf.php';
require_once 'meldungen.php';
require_once 'vorgaenge_funktionen.php';
$db = $db_config;
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function vorgang_return($raw): string {
    if (!is_string($raw) || preg_match('/[\x00-\x1f\x7f\\\\]/', $raw)) return '';
    $parts = parse_url($raw);
    if (!$parts || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) return '';
    if (!in_array($parts['path'] ?? '', ['bearbeiten.php','verarbeiten.php','ansehen.php'], true)) return '';
    return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
$token = eablage_aktion_token();
$id = 0; $error = ''; $item = null; $items = []; $documents = []; $candidates = [];
$return = vorgang_return($_POST['return'] ?? $_GET['return'] ?? '');
$search = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
$documentSearch = is_string($_GET['document_q'] ?? '') ? trim($_GET['document_q'] ?? '') : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$total = 0; $pages = 1;
$newName = ''; $newDescription = '';
try {
    if (isset($_POST['id']) || isset($_GET['id'])) $id = eablage_vorgang_id($_POST['id'] ?? $_GET['id']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try { eablage_aktion_pruefen($_POST['aktion_token'] ?? null); }
        catch (RuntimeException $e) { http_response_code(403); throw $e; }
        $action = $_POST['action'] ?? '';
        if (!is_string($action) || !in_array($action, ['create','update','delete','attach','detach'], true)) throw new RuntimeException('Ungültige Aktion.');
        if ($action === 'create') {
            $newName = eablage_vorgang_text($_POST['name'] ?? '', 'Bezeichnung', 255, true);
            $newDescription = eablage_vorgang_text($_POST['description'] ?? '', 'Beschreibung', 10000);
            $id = eablage_vorgang_anlegen($db, $newName, $newDescription);
        } else {
            if (!$id) throw new RuntimeException('Vorgang fehlt.');
            $db->beginTransaction();
            if (in_array($action, ['attach','detach'], true)) {
                $dokId = eablage_vorgang_id($_POST['document_id'] ?? null);
                // Same lock order as document editing: document, then target case.
                $stmt = $db->prepare('SELECT DokID FROM dokumente WHERE DokID=? AND GeloeschtAm IS NULL FOR UPDATE');
                $stmt->execute([$dokId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Das Dokument ist nicht mehr verfügbar.');
                $stmt = $db->prepare('SELECT ID FROM vorgang WHERE ID=? LOCK IN SHARE MODE'); $stmt->execute([$id]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Vorgang wurde gelöscht.');
                $ids = eablage_vorgaenge_laden($db, $dokId);
                if ($action === 'attach' && !in_array($id, $ids, true)) $ids[] = $id;
                if ($action === 'detach') $ids = array_values(array_diff($ids, [$id]));
                eablage_vorgaenge_speichern($db, $dokId, $ids);
            } else {
                $stmt = $db->prepare('SELECT * FROM vorgang WHERE ID=? FOR UPDATE'); $stmt->execute([$id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new RuntimeException('Vorgang wurde gelöscht.');
                if (!is_string($_POST['revision'] ?? null) || !hash_equals(eablage_vorgang_revision($current), $_POST['revision'])) throw new RuntimeException('Der Vorgang wurde zwischenzeitlich geändert. Bitte neu öffnen.');
                if ($action === 'update') {
                    $name = eablage_vorgang_text($_POST['name'] ?? '', 'Bezeichnung', 255, true);
                    $description = eablage_vorgang_text($_POST['description'] ?? '', 'Beschreibung', 10000);
                    $stmt = $db->prepare('UPDATE vorgang SET Bezeichnung=?,Beschreibung=?,GeaendertAm=NOW() WHERE ID=?'); $stmt->execute([$name,$description,$id]);
                } else {
                    // The case lock blocks new assignments until deletion commits.
                    $stmt = $db->prepare('SELECT DokumentID FROM dokument_vorgang WHERE VorgangID=? ORDER BY DokumentID FOR UPDATE'); $stmt->execute([$id]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dokId) {
                        eablage_protokoll($db, (int)$dokId, 'vorgaenge_geaendert', ['vorgang_entfernt'=>$id, 'bezeichnung'=>$current['Bezeichnung']]);
                    }
                    $stmt = $db->prepare('DELETE FROM vorgang WHERE ID=?'); $stmt->execute([$id]);
                    $id = 0;
                }
            }
            $db->commit();
        }
        header('Location: vorgaenge.php' . ($id ? '?id=' . $id . ($return !== '' ? '&return=' . rawurlencode($return) : '') : ($return !== '' ? '?return=' . rawurlencode($return) : '')));
        exit;
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($e instanceof PDOException) { error_log('eAblage Vorgänge: ' . $e->getMessage()); $error = 'Die Änderung konnte nicht ausgeführt werden. Bitte den aktuellen Stand prüfen und erneut versuchen.'; }
    else $error = $e->getMessage();
}
try {
    if ($id) {
        $stmt = $db->prepare('SELECT * FROM vorgang WHERE ID=?'); $stmt->execute([$id]); $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) { http_response_code(404); $error = 'Der Vorgang wurde nicht gefunden.'; }
        else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM dokument_vorgang v JOIN dokumente d ON d.DokID=v.DokumentID WHERE v.VorgangID=? AND d.GeloeschtAm IS NULL');
            $stmt->execute([$id]); $total = (int)$stmt->fetchColumn();
            $pages = max(1, (int)ceil($total / 50)); $page = min($page, $pages);
            $stmt = $db->prepare('SELECT d.DokID,d.Datum,d.Partner,d.Betreff,k.katBez,t.doktypBez FROM dokument_vorgang v JOIN dokumente d ON d.DokID=v.DokumentID LEFT JOIN kategorie k ON k.id=d.kategorie_id LEFT JOIN doktyp t ON t.id=d.doktyp_id WHERE v.VorgangID=? AND d.GeloeschtAm IS NULL ORDER BY d.Datum DESC,d.DokID DESC LIMIT 50 OFFSET ' . (($page - 1)*50));
            $stmt->execute([$id]); $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare('SELECT COUNT(*) FROM dokument_vorgang v JOIN dokumente d ON d.DokID=v.DokumentID WHERE v.VorgangID=? AND d.GeloeschtAm IS NOT NULL');
            $stmt->execute([$id]); $trashCount = (int)$stmt->fetchColumn();
            if ($documentSearch !== '') {
                $stmt = $db->prepare('SELECT d.DokID,d.Datum,d.Partner,d.Betreff FROM dokumente d WHERE d.GeloeschtAm IS NULL AND NOT EXISTS (SELECT 1 FROM dokument_vorgang v WHERE v.DokumentID=d.DokID AND v.VorgangID=?) AND (d.Partner LIKE ? OR d.Betreff LIKE ? OR EXISTS (SELECT 1 FROM dokument_nummer n WHERE n.DokumentID=d.DokID AND n.Wert LIKE ?)) ORDER BY d.Datum DESC,d.DokID DESC LIMIT 51');
                $like = '%' . $documentSearch . '%'; $stmt->execute([$id,$like,$like,$like]); $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        $like = '%' . $search . '%';
        $stmt = $db->prepare('SELECT COUNT(*) FROM vorgang WHERE Bezeichnung LIKE ? OR Beschreibung LIKE ?'); $stmt->execute([$like,$like]); $total=(int)$stmt->fetchColumn();
        $pages=max(1,(int)ceil($total/50)); $page=min($page,$pages);
        $stmt = $db->prepare('SELECT v.*, (SELECT COUNT(*) FROM dokument_vorgang dv JOIN dokumente d ON d.DokID=dv.DokumentID WHERE dv.VorgangID=v.ID AND d.GeloeschtAm IS NULL) AS Anzahl FROM vorgang v WHERE v.Bezeichnung LIKE ? OR v.Beschreibung LIKE ? ORDER BY v.Bezeichnung,v.ID LIMIT 50 OFFSET ' . (($page-1)*50));
        $stmt->execute([$like,$like]); $items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('eAblage Vorgänge laden: ' . $e->getMessage()); http_response_code(500);
    $error = 'Vorgänge konnten nicht geladen werden. Bitte prüfen, ob sql/update_document_links.sql ausgeführt wurde.';
}
$detail = 'vorgaenge.php?id=' . $id . ($return !== '' ? '&return=' . rawurlencode($return) : '');
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>eAblage – Vorgänge</title><link rel="icon" href="ea_icon4.png"><link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__.'/app.css')?>"><?php if ($item): ?><link rel="stylesheet" href="explorer.css?v=<?=filemtime(__DIR__.'/explorer.css')?>">
    <?php eablage_dokumentvorschau_script(); ?>
    <script src="vorgang_vorschau.js?v=<?=filemtime(__DIR__.'/vorgang_vorschau.js')?>" defer></script><?php endif; ?></head>
<body><?php include 'navbar.php'; ?><main class="container ea-vorgaenge <?=$item ? 'ea-explorer' : ''?>">
    <?php if ($item): ?><div class="ea-explorer-layout" data-vorgang-workspace><?php endif; ?>
    <section class="panel panel-primary <?=$item ? 'ea-explorer-documents' : ''?>" aria-labelledby="vorgang-heading">
        <div class="panel-heading"><h1 class="ea-vorgang-title" id="vorgang-heading"><?=eablage_icon('link')?><?=$item ? 'Vorgang: ' . h($item['Bezeichnung']) : 'Vorgänge'?></h1></div>
        <div class="panel-body">
    <?php if ($error !== ''): ?><?=eablage_render_message('danger',$error)?><?php endif; ?>
    <?php if ($item): ?>
        <?php if ($item['Beschreibung'] !== ''): ?><p class="ea-vorgang-description"><?=h($item['Beschreibung'])?></p><?php endif; ?>
        <details class="panel panel-default"><summary class="panel-heading">Vorgang bearbeiten</summary><div class="panel-body">
            <form method="post"><input type="hidden" name="aktion_token" value="<?=h($token)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="revision" value="<?=h(eablage_vorgang_revision($item))?>"><input type="hidden" name="return" value="<?=h($return)?>">
                <div class="form-group"><label for="name">Bezeichnung</label><input class="form-control" id="name" name="name" required maxlength="255" value="<?=h($error !== '' && ($_POST['action']??'') === 'update' && is_string($_POST['name']??null) ? $_POST['name'] : $item['Bezeichnung'])?>"></div>
                <div class="form-group"><label for="description">Beschreibung</label><textarea class="form-control" id="description" name="description" rows="3" maxlength="10000"><?=h($error !== '' && ($_POST['action']??'') === 'update' && is_string($_POST['description']??null) ? $_POST['description'] : $item['Beschreibung'])?></textarea></div>
                <div class="ea-form-actions"><button class="btn btn-danger" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('Vorgang und alle seine Zuordnungen löschen? Die Dokumente bleiben erhalten.');"><?=eablage_icon('trash')?>Vorgang löschen</button><button class="btn btn-primary" type="submit" name="action" value="update"><?=eablage_icon('save')?>Speichern</button></div>
            </form>
        </div></details>
        <section class="ea-vorgang-documents" aria-labelledby="vorgang-documents-heading"><div class="ea-vorgang-documents-heading"><h2 id="vorgang-documents-heading">Zugeordnete Dokumente (<?=$total?>)</h2>

        </div>
            <?php if ($trashCount): ?><p class="text-muted"><?=$trashCount?> weitere Dokumente befinden sich im Papierkorb. Ihre Zuordnungen bleiben bis zum endgültigen Löschen erhalten.</p><?php endif; ?>
            <?php if (!$documents): ?><p>Noch keine Dokumente zugeordnet.</p><?php endif; ?>
            <ul class="ea-explorer-items">
            <?php foreach ($documents as $doc): ?>
                <li class="ea-explorer-document-row"><a class="ea-explorer-item ea-explorer-document" data-vorgang-document data-case-preview data-document-id="<?=$doc['DokID']?>" data-edit-url="bearbeiten.php?dokid=<?=$doc['DokID']?>&amp;return=<?=rawurlencode($detail)?>" href="archiv_pdf.php?dokid=<?=$doc['DokID']?>" target="_blank" rel="noopener">
                    <span class="ea-explorer-document-meta"><span class="ea-explorer-document-date"><?=h($doc['Datum'])?></span><span class="ea-badge ea-badge-category"><?=h($doc['katBez'])?></span><span class="ea-badge ea-badge-type"><?=h($doc['doktypBez'])?></span></span>
                    <span class="ea-explorer-document-line"><span class="ea-explorer-document-partner"><?=h($doc['Partner'])?></span><span class="ea-explorer-document-subject"><?=h($doc['Betreff'] ?: 'Dokument öffnen')?></span></span>
                </a>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php if ($pages>1): ?><div class="pagination-wrapper"><?php if ($page>1): ?><a href="<?=h($detail.'&page='.($page-1))?>">« Vorherige</a><?php endif; ?><span>Seite <?=$page?> von <?=$pages?></span><?php if ($page<$pages): ?><a href="<?=h($detail.'&page='.($page+1))?>">Nächste »</a><?php endif; ?></div><?php endif; ?>
            <p><a href="dokumente.php?Vorgang=<?=$id?>">Dokumente in der Suche anzeigen</a></p>
        </section>
        <details class="panel panel-default" <?=$documentSearch !== '' ? 'open' : ''?>><summary class="panel-heading">Vorhandenes Dokument zuordnen</summary><div class="panel-body">
            <form method="get"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="return" value="<?=h($return)?>"><div class="form-group"><label for="document_q">Partner, Betreff oder Nummer</label><input class="form-control" id="document_q" name="document_q" value="<?=h($documentSearch)?>" required></div><div class="ea-form-actions"><button class="btn btn-default" type="submit">Dokument suchen</button></div></form>
            <?php if ($documentSearch !== '' && !$candidates): ?><p>Keine weiteren passenden Dokumente gefunden.</p><?php endif; ?>
            <?php if (count($candidates)>50): ?><p>Die ersten 50 Treffer werden angezeigt. Bitte den Suchbegriff eingrenzen.</p><?php endif; ?>
            <?php foreach (array_slice($candidates,0,50) as $doc): ?><div class="ea-vorgang-document"><div><?=h($doc['Datum'])?> · <?=h($doc['Partner'])?><br><?=h($doc['Betreff'])?></div><form method="post"><input type="hidden" name="aktion_token" value="<?=h($token)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="return" value="<?=h($return)?>"><input type="hidden" name="document_id" value="<?=$doc['DokID']?>"><button class="btn btn-default btn-sm" type="submit" name="action" value="attach">Zuordnen</button></form></div><?php endforeach; ?>
        </div></details>
    <?php elseif (!$id): ?>
        <form method="get" class="ea-vorgang-search"><input type="hidden" name="return" value="<?=h($return)?>"><label for="q">Vorgänge suchen</label><div class="ea-vorgang-add"><input class="form-control" type="search" name="q" id="q" value="<?=h($search)?>"><button class="btn btn-default" type="submit"><?=eablage_icon('search')?>Suchen</button></div></form>
        <details class="panel panel-default" <?=$newName!=='' ? 'open' : ''?>><summary class="panel-heading">Neuen Vorgang anlegen</summary><div class="panel-body"><form method="post"><input type="hidden" name="aktion_token" value="<?=h($token)?>"><input type="hidden" name="return" value="<?=h($return)?>"><div class="form-group"><label for="new_name">Bezeichnung</label><input class="form-control" id="new_name" name="name" required maxlength="255" value="<?=h($newName)?>"></div><div class="form-group"><label for="new_description">Beschreibung (optional)</label><textarea class="form-control" id="new_description" name="description" rows="3" maxlength="10000"><?=h($newDescription)?></textarea></div><div class="ea-form-actions"><button class="btn btn-primary" type="submit" name="action" value="create">Vorgang anlegen</button></div></form></div></details>
        <section class="panel panel-default"><div class="panel-heading"><h3>Vorgänge (<?=$total?>)</h3></div><div class="panel-body">
            <?php if (!$items): ?><p>Keine Vorgänge gefunden.</p><?php endif; ?>
            <?php foreach ($items as $entry): ?><article class="ea-vorgang-document"><div><a href="vorgaenge.php?id=<?=$entry['ID']?><?=$return !== '' ? '&amp;return='.rawurlencode($return) : ''?>"><strong><?=h($entry['Bezeichnung'])?></strong></a><p class="ea-vorgang-description"><?=h($entry['Beschreibung'])?></p></div><span><?=h($entry['Anzahl'])?> Dokumente</span></article><?php endforeach; ?>
            <?php if ($pages>1): ?><div class="pagination-wrapper"><?php if ($page>1): ?><a href="?<?=h(http_build_query(['q'=>$search,'page'=>$page-1,'return'=>$return]))?>">« Vorherige</a><?php endif; ?><span>Seite <?=$page?> von <?=$pages?></span><?php if ($page<$pages): ?><a href="?<?=h(http_build_query(['q'=>$search,'page'=>$page+1,'return'=>$return]))?>">Nächste »</a><?php endif; ?></div><?php endif; ?>
        </div></section>
    <?php endif; ?>
        </div>
        <?php if ($item || $return !== ''): ?>
        <div class="ea-workspace-footer">
            <?php if ($return !== ''): ?><a class="btn btn-default" href="<?=h($return)?>">Zurück zum Dokument</a><?php endif; ?>
            <?php if ($item): ?>
            <div id="vorgang-preview-actions" hidden><a class="btn btn-primary" id="vorgang-preview-edit"><?=eablage_icon('edit')?>Metadaten bearbeiten</a></div>
            <form method="post" id="vorgang-detach-form"><input type="hidden" name="aktion_token" value="<?=h($token)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="document_id" id="vorgang-detach-document" value=""><input type="hidden" name="return" value="<?=h($return)?>"><button class="btn btn-default btn-sm" type="submit" name="action" value="detach" id="vorgang-detach" disabled title="Bitte zuerst ein Dokument auswählen.">Zuordnung entfernen</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php if ($item): ?>
        <?php eablage_dokumentvorschau('', '', ['pdf_id'=>'vorgang-preview-pdf', 'open_id'=>'vorgang-preview-open', 'empty_id'=>'vorgang-preview-empty', 'heading_id'=>'vorgang-preview-heading', 'class'=>'ea-explorer-preview']); ?>
    </div>
    <noscript><p class="alert alert-info">Für die PDF-Vorschau bitte JavaScript aktivieren. Die Dokumentlinks öffnen das PDF direkt.</p></noscript>
    <?php endif; ?>
</main></body></html>


