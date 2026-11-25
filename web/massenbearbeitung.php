<?php
require_once 'konfiguration.php';
require_once __DIR__ . '/seitenkopf.php';
require_once 'csrf.php';
require_once 'massen_aenderungen.php';
require_once 'dokument_navigation.php';
$token = eablage_aktion_token();
$return = valid_return_url($_POST['return'] ?? 'dokumente.php');
$error = '';
$rows = [];
$ids = [];
$batch = '';
$operations = [];
$labels = eablage_massen_felder();
$categories = eablage_lade_kategorien($db_config, true);
$types = eablage_lade_dokumenttypen($db_config, true);
$kinds = $db_config->query('SELECT id,Bezeichnung FROM nummernart ORDER BY Sortierung,Bezeichnung,id')->fetchAll(PDO::FETCH_KEY_PAIR);
$options = ['kategorie_id'=>$categories, 'kategorie2_id'=>$categories, 'doktyp_id'=>$types, 'numbers'=>$kinds];
$displayOptions = array_replace($options, ['kategorie_id'=>eablage_lade_kategorien($db_config), 'kategorie2_id'=>eablage_lade_kategorien($db_config), 'doktyp_id'=>eablage_lade_dokumenttypen($db_config)]);
function shown($value, string $field, array $options): string {
    if ($field === 'numbers') return implode(', ', array_map(fn($n)=>($options['numbers'][$n['kind_id']] ?? $n['kind_id']) . ' · ' . $n['value'], $value));
    return isset($options[$field]) ? (string)($options[$field][$value] ?? $value) : (string)$value;
}
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Bitte Dokumente in der Suche auswählen.');
    eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
    if (($_POST['action'] ?? '') === 'apply') {
        header('Content-Type: application/json; charset=utf-8');
        session_start();
        $key = is_string($_POST['batch'] ?? null) ? $_POST['batch'] : '';
        $id = eablage_vorgang_id($_POST['id'] ?? null);
        $entry = $_SESSION['eablage_mass'][$key]['entries'][$id] ?? null;
        if (!$entry) throw new RuntimeException('Vorschau nicht mehr verfügbar. Bitte neu erstellen.');
        if (!isset($entry['result'])) {
            // Persist a claim before writing, so a disconnected retry cannot repeat a mutation.
            $_SESSION['eablage_mass'][$key]['entries'][$id]['result'] = 'Speicherung unterbrochen oder noch aktiv. Bitte Dokument prüfen.';
            session_write_close();
            try {
                $before = eablage_massen_laden($db_config, $id);
                if (!hash_equals($entry['revision'], document_revision($before))) throw new UnexpectedValueException('Zwischenzeitlich geändert. Bitte neue Vorschau erstellen.');
                $after = array_replace($before, $entry['changes']);
                eablage_dokument_speichern($db_config, $before, $after, $entry['revision']);
                $result = 'Gespeichert';
            } catch (Throwable $failure) {
                error_log('eAblage Massenänderung: ' . $failure->getMessage());
                $result = $failure instanceof UnexpectedValueException ? $failure->getMessage() : 'Speichern fehlgeschlagen. Bitte Dokument und Archivprüfung prüfen.';
            }
            session_start();
            $_SESSION['eablage_mass'][$key]['entries'][$id]['result'] = $result;
        } else $result = $entry['result'];
        session_write_close();
        $metadata = null;
        if ($result === 'Gespeichert') {
            try {
                $current = eablage_massen_laden($db_config, $id);
                $metadata = [];
                foreach ($labels as $field=>$label) $metadata[$field] = shown($current[$field], $field, $displayOptions);
            } catch (Throwable $failure) { /* The stored result remains authoritative. */ }
        }
        echo json_encode(['result'=>$result, 'metadata'=>$metadata]);
        exit;
    }
    $raw = json_decode($_POST['document_ids'] ?? '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($raw) || !array_is_list($raw) || !$raw || count($raw)>1000) throw new RuntimeException('Bitte 1 bis 1.000 Dokumente auswählen.');
    $ids = array_values(array_unique(array_map('eablage_vorgang_id', $raw)));
    if (($_POST['action'] ?? '') === 'preview') {
        $operations = eablage_massen_operationen($_POST['ops'] ?? []);
        $entries = [];
        foreach ($ids as $id) {
            $row = ['id'=>$id, 'changes'=>[], 'status'=>''];
            try {
                $db_config->beginTransaction();
                $before = eablage_massen_laden($db_config, $id);
                $after = eablage_massen_anwenden($before, $operations);
                eablage_pruefe_zuordnungen($db_config, $after, $before);
                if (isset($operations['numbers'])) {
                    $after['numbers'] = eablage_nummern_pruefen($db_config, json_encode($after['numbers'], JSON_THROW_ON_ERROR), $before['numbers']);
                    $after['Nummer'] = $after['numbers'][0]['value'] ?? '';
                }
                $changes = [];
                foreach ($operations as $field=>$op) {
                    if ($field === 'numbers' ? $before[$field] === $after[$field] : normalized_compare_value($field, $before[$field]) === normalized_compare_value($field, $after[$field])) continue;
                    $changes[$field] = $after[$field];
                    $row['changes'][] = [$labels[$field], shown($before[$field], $field, $options), shown($after[$field], $field, $options)];
                }
                $row['status'] = $changes ? 'Bereit' : 'Keine Änderung';
                if ($changes) $entries[$id] = ['revision'=>document_revision($before), 'changes'=>$changes];
            } catch (Throwable $failure) { $row['status'] = $failure instanceof PDOException ? 'Dokument konnte nicht geprüft werden.' : $failure->getMessage(); }
            if ($db_config->inTransaction()) $db_config->rollBack();
            $rows[] = $row;
        }
        $batch = bin2hex(random_bytes(24));
        session_start();
        // Keep concurrent previews available for separate tabs.
        $_SESSION['eablage_mass'] ??= [];
        foreach ($_SESSION['eablage_mass'] as $key=>$old) if ($old['created'] < time()-86400) unset($_SESSION['eablage_mass'][$key]);
        $_SESSION['eablage_mass'][$batch] = ['created'=>time(), 'entries'=>$entries];
        session_write_close();
    }
} catch (Throwable $failure) {
    if (($_POST['action'] ?? '') === 'apply') {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'Vorschau oder Sitzung ungültig. Bitte erneut öffnen.']);
        exit;
    }
    $error = $failure instanceof PDOException ? 'Die Daten konnten nicht geladen werden.' : $failure->getMessage();
}
$currentDocuments = [];
foreach ($ids as $id) {
    try { $currentDocuments[$id] = eablage_massen_laden($db_config, $id); }
    catch (Throwable $failure) { $currentDocuments[$id] = null; }
}
?>
<!doctype html>
<html lang="de"><head>
    <?php eablage_seitenkopf('Metadaten ändern'); ?><link rel="stylesheet" href="massenbearbeitung.css?v=<?=filemtime(__DIR__ . '/massenbearbeitung.css')?>"><script src="massenbearbeitung.js?v=<?=filemtime(__DIR__ . '/massenbearbeitung.js')?>" defer></script></head>
<body><?php require 'navbar.php'; ?><main class="container ea-mass-layout">
<section class="panel panel-primary ea-mass-panel"><div class="panel-heading">Metadaten ändern</div><div class="panel-body">
<?php if ($error): ?><p class="alert alert-danger" role="alert"><?=h($error)?></p><?php endif; ?>
<p><?=count($ids)?> Dokumente ausgewählt</p>
<form method="post" id="mass-form">
<input type="hidden" name="aktion_token" value="<?=h($token)?>"><input type="hidden" name="return" value="<?=h($return)?>"><input type="hidden" name="document_ids" value="<?=h(json_encode($ids))?>"><input type="hidden" name="action" value="preview">
<?php foreach ($labels as $field=>$label): $op = $operations[$field] ?? []; ?>
<fieldset class="form-group" data-mass-field><legend><?=h($label)?></legend>
<label for="mode-<?=h($field)?>">Aktion</label><select class="form-control" id="mode-<?=h($field)?>" name="ops[<?=h($field)?>][mode]" data-mode>
<?php $modes = match ($field) { 'numbers'=>['set'=>'Wert setzen','move'=>'Nummernart wechseln','remove'=>'Bestimmte Nummer entfernen'], 'Tags'=>['set'=>'Ersetzen','add'=>'Hinzufügen','remove'=>'Entfernen','clear'=>'Leeren'], 'Partner','Betreff'=>['set'=>'Text setzen','replace'=>'Text ersetzen','clear'=>'Leeren'], default=>['set'=>'Wert setzen','clear'=>'Leeren'] }; ?>
<option value="">Nicht ändern</option><?php foreach ($modes as $mode=>$text): ?><option value="<?=h($mode)?>" <?=($op['mode']??'')===$mode?'selected':''?>><?=h($text)?></option><?php endforeach; ?></select>
<div data-inputs>
<?php if ($field==='numbers'): ?>
<label for="kind">Nummernart</label><select class="form-control" id="kind" name="ops[numbers][kind]"><?php foreach ($kinds as $id=>$name): ?><option value="<?=h($id)?>" <?=($op['kind']??'')===(string)$id?'selected':''?>><?=h($name)?></option><?php endforeach; ?></select>
<div data-for="move"><label for="target">Ziel-Nummernart</label><select class="form-control" id="target" name="ops[numbers][target]"><?php foreach ($kinds as $id=>$name): ?><option value="<?=h($id)?>" <?=($op['target']??'')===(string)$id?'selected':''?>><?=h($name)?></option><?php endforeach; ?></select></div>
<p>Beim Wechseln und Entfernen muss der Wert exakt übereinstimmen. Eine bereits belegte Ziel-Nummernart wird als Konflikt angezeigt.</p>
<?php endif; ?>
<?php if (in_array($field,['Partner','Betreff'],true)): ?><div data-for="replace"><label for="find-<?=h($field)?>">Text suchen (Groß-/Kleinschreibung beachten)</label><input class="form-control" id="find-<?=h($field)?>" name="ops[<?=h($field)?>][find]" value="<?=h($op['find']??'')?>" maxlength="255"></div><?php endif; ?>
<label for="value-<?=h($field)?>"><?= $field==='Tags' ? 'Stichworte, durch Komma getrennt' : 'Wert' ?></label>
<?php if (isset($options[$field]) && $field!=='numbers'): ?><select class="form-control" id="value-<?=h($field)?>" name="ops[<?=h($field)?>][value]"><?php foreach ($options[$field] as $id=>$name): ?><option value="<?=h($id)?>" <?=($op['value']??'')===(string)$id?'selected':''?>><?=h($name)?></option><?php endforeach; ?></select>
<?php else: ?><input class="form-control" id="value-<?=h($field)?>" name="ops[<?=h($field)?>][value]" value="<?=h($op['value']??'')?>" maxlength="255"><?php endif; ?>
</div></fieldset><?php endforeach; ?>
<button class="btn btn-primary" type="submit">Vorschau erstellen</button> <a class="btn btn-default" href="<?=h($return)?>">Zurück zur Suche</a>
</form>
<?php if ($rows): ?><section id="mass-preview" data-batch="<?=h($batch)?>" data-token="<?=h($token)?>"><h2>Änderungsvorschau</h2>
<p><?=count($entries)?> Dokumente zur Änderung bereit · <?=count($rows)-count($entries)?> ohne Änderung oder mit Konflikt</p>
<div><table class="table"><thead><tr><th>Dokument</th><th>Änderungen (bisher → neu)</th><th>Ergebnis</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?=h($row['id'])?></td><td><?php foreach ($row['changes'] as [$label,$old,$new]): ?><p><strong><?=h($label)?></strong><br><?=h($old)?> → <?=h($new)?></p><?php endforeach; ?></td><td data-result="<?=h($row['id'])?>" <?=isset($entries[$row['id']])?'data-ready':''?>><?=h($row['status'])?></td></tr><?php endforeach; ?>
</tbody></table></div><button type="button" class="btn btn-primary" id="mass-save" <?=!$entries?'disabled':''?>>Änderungen speichern</button><p id="mass-progress" role="status"></p></section><?php endif; ?>
</div></section>
<section class="panel panel-primary ea-mass-panel" aria-labelledby="mass-current-heading">
<div class="panel-heading" id="mass-current-heading">Aktuelle Metadaten · <?=count($ids)?> Dokumente</div>
<div class="panel-body ea-mass-documents">
<?php foreach ($currentDocuments as $id=>$current): ?>
<article class="ea-mass-document" data-current-document="<?=h($id)?>">
<h2>Dokument <?=h($id)?><?php if ($current && $current['Datum']): ?> · <?=h(date('d.m.Y', strtotime($current['Datum'])))?><?php endif; ?></h2>
<?php if (!$current): ?><p>Dokument nicht mehr verfügbar.</p>
<?php else: ?><dl>
<?php foreach ($labels as $field=>$label): ?>
<dt><?=h($label)?></dt><dd data-current-field="<?=h($field)?>"><?=h(shown($current[$field], $field, $displayOptions) ?: '—')?></dd>
<?php endforeach; ?>
</dl><p data-current-status role="status" hidden></p>
<?php endif; ?>
</article>
<?php endforeach; ?>
</div></section>
</main></body></html>

