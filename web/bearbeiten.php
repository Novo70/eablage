<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'nummern_funktionen.php';
require_once 'metadaten_funktionen.php';
require_once 'meldungen.php';
require_once 'csrf.php';
require_once 'protokoll_funktionen.php';

$db = $db_config;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_return_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url) || str_contains($url, chr(92))) return 'dokumente.php';
    $parts = parse_url($url);
    if (
        $parts === false || isset($parts['scheme']) || isset($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
    ) return 'dokumente.php';
    $path = (string)($parts['path'] ?? '');
    if (!in_array($path, ['dokumente.php', 'verarbeiten.php', 'bearbeiten.php', 'explorer.php'], true)) return 'dokumente.php';
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
function valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function changed_marker(array $changed, string $field): string
{
    return !empty($changed[$field]) ? ' <span class="ea-source-badge ea-source-badge--supplement" title="Dieser Wert weicht vom gespeicherten Wert ab.">neu</span>' : '';
}
function normalized_compare_value(string $field, $value): string
{
    if (in_array($field, ['kategorie_id', 'kategorie2_id', 'doktyp_id'], true)) {
        $value = trim((string)$value);
        return ($value === '' || $value === '0') ? '0' : $value;
    }
    return trim((string)$value);
}
function normalize_document_text($value): string
{
    return str_replace(["\r\n", "\r"], "\n", (string)$value);
}
function document_revision(array $document): string
{
    ksort($document);
    return hash('sha256', serialize(array_map(static fn($value) => $value === null ? null : (is_array($value) ? json_encode($value) : (string)$value), $document)));
}

$dokId = filter_input(INPUT_GET, 'dokid', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') $dokId = filter_input(INPUT_POST, 'dokid', FILTER_VALIDATE_INT);
if (!$dokId || $dokId < 1) {
    http_response_code(400);
    die('Ungültige Dokument-ID.');
}

$returnUrl = valid_return_url($_REQUEST['return'] ?? 'dokumente.php');
$kategorien = eablage_lade_kategorien($db);
$dokumenttypen = eablage_lade_dokumenttypen($db);
$error = '';
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$reclassified = false;
$reclassificationInfo = '';
$reclassificationChanged = [];
$textReloaded = false;
$textChanged = false;
$pendingDocumentText = '';
$aktionToken = eablage_aktion_token();

try {
    $stmt = $db->prepare('SELECT DokID, Datum, kategorie_id, kategorie2_id, doktyp_id, Partner, Betreff, Nummer, Tags, Doktext, Archivierungsdatum, Aufbewahrungsdauer, Info, Originaldateiname, DateiSHA256, Aenderungsdatum, GeloeschtAm FROM ' . DB_TABELLE_ARCHIV . ' WHERE DokID = ? AND GeloeschtAm IS NULL');
    $stmt->execute([$dokId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('e_ablage Dokument bearbeiten/laden: ' . $e->getMessage());
    http_response_code(500);
    die('Das Dokument konnte nicht geladen werden.');
}
if (!$document) {
    http_response_code(404);
    die('Das angeforderte Dokument wurde nicht gefunden.');
}
$document['numbers'] = eablage_nummern_laden($db,(int)$dokId);
$storedDocument = $document;
// Retain the original form revision across helper actions and failed saves.
$documentRevision = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (is_string($_POST['document_revision'] ?? null) ? $_POST['document_revision'] : '')
    : document_revision($storedDocument);
$kategorien = eablage_lade_kategorien($db, true, [$storedDocument['kategorie_id']]);
$kategorien2 = eablage_lade_kategorien($db, true, [$storedDocument['kategorie2_id']]);
$dokumenttypen = eablage_lade_dokumenttypen($db, true, [$storedDocument['doktyp_id']]);
$filename = eablage_archiv_pdf_dateiname((int)$storedDocument['DokID']);
$archivePdfPath = rtrim(PFAD_ARCHIV, '/\\') . DIRECTORY_SEPARATOR . $filename;
$storedDocumentText = normalize_document_text($storedDocument['Doktext'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
    } catch (RuntimeException $e) {
        http_response_code(403);
        $error = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $action = (string)($_POST['action'] ?? 'save');
    $pendingDocumentText = normalize_document_text($_POST['pending_document_text'] ?? '');
    $textChanged = $pendingDocumentText !== '' && $pendingDocumentText !== $storedDocumentText;

    // Keep unsaved form values during helper actions and backend failures.
    foreach (
        [
            'Datum' => 'Datum',
            'Kategorie' => 'kategorie_id',
            'Kategorie2' => 'kategorie2_id',
            'Doktyp' => 'doktyp_id',
            'Partner' => 'Partner',
            'Betreff' => 'Betreff',
            'Nummer' => 'Nummer',
            'Tags' => 'Tags'
        ] as $input => $field
    ) {
        if (isset($_POST[$input]) && is_string($_POST[$input])) $document[$field] = $_POST[$input];
    }
    if (isset($_POST['numbers_json']) && is_string($_POST['numbers_json'])) {
        try { $document['numbers'] = eablage_nummern_pruefen($db,$_POST['numbers_json'],$storedDocument['numbers']); }
        catch (Throwable $e) { $error=$e->getMessage(); }
    }
    $reclassificationInfo = trim((string)($_POST['reclassification_info'] ?? ''));

    if (!hash_equals(document_revision($storedDocument), $documentRevision)) {
        $error = 'Das Dokument wurde zwischenzeitlich geändert oder das Formular ist veraltet. Bitte die eigenen Eingaben sichern und das Dokument neu öffnen.';
    } elseif (in_array($action, ['reload_text', 'ocr_text'], true)) {
        $textResult = eablage_pdf_text_einlesen($archivePdfPath, $action === 'ocr_text');
        if (!$textResult['ok']) {
            $error = 'Dokumenttext konnte nicht neu eingelesen werden: ' . $textResult['error'];
        } else {
            $pendingDocumentText = normalize_document_text($textResult['text']);
            $textChanged = $pendingDocumentText !== $storedDocumentText;
            $textReloaded = true;
        }
    } elseif ($action === 'reclassify') {
        $classificationText = $pendingDocumentText !== '' ? $pendingDocumentText : $storedDocumentText;
        $result = eablage_klassifizieren($classificationText, (int)$dokId);
        $reclassificationInfo = (string)($result['info'] ?? 'Manuell');
        $backendFehler = ($result['autokat_error'] ?? '') !== '' || ($result['textcompare_error'] ?? '') !== '';
        if ($backendFehler) {
            $error = 'Die automatische Klassifizierung ist nicht verfügbar. Das Dokument kann weiterhin manuell bearbeitet werden.';
        } else {
            $document['Datum'] = (string)($result['date'] ?? '');
            $document['kategorie_id'] = (string)($result['category'] ?? '0');
            $document['kategorie2_id'] = (string)($result['category2'] ?? '0');
            $document['doktyp_id'] = (string)($result['document_type'] ?? '0');
            $document['Partner'] = (string)($result['partner'] ?? '');
            $document['Betreff'] = (string)($result['subject'] ?? '');
            // Reclassification can fill missing kinds; manual/current values remain.
            $existingKinds=array_map('strval',array_column($document['numbers'],'kind_id'));
            foreach ($result['numbers'] ?? [] as $n) if (!in_array((string)$n['kind_id'],$existingKinds,true)) {
                $document['numbers'][]=$n; $existingKinds[]=(string)$n['kind_id'];
            }
            $document['Nummer'] = (string)($result['number'] ?? '');
            $document['Tags'] = (string)($result['tags'] ?? '');
            foreach (['Datum', 'kategorie_id', 'kategorie2_id', 'doktyp_id', 'Partner', 'Betreff', 'Nummer', 'Tags'] as $field) {
                $reclassificationChanged[$field] = normalized_compare_value($field, $document[$field] ?? null) !== normalized_compare_value($field, $storedDocument[$field] ?? null);
            }
            $reclassified = true;
        }
    } else {
        $datum = trim((string)($_POST['Datum'] ?? ''));
        $kategorieId = trim((string)($_POST['Kategorie'] ?? '0'));
        $kategorie2Id = trim((string)($_POST['Kategorie2'] ?? '0'));
        $doktypId = trim((string)($_POST['Doktyp'] ?? '0'));
        $partner = trim((string)($_POST['Partner'] ?? ''));
        $betreff = trim((string)($_POST['Betreff'] ?? ''));
        $nummer = trim((string)($_POST['Nummer'] ?? ''));
        $tags = trim((string)($_POST['Tags'] ?? ''));
        $reclassificationInfo = trim((string)($_POST['reclassification_info'] ?? ''));
        if (!valid_date($datum)) $error = 'Bitte ein gültiges Dokumentdatum eingeben.';
        elseif (!array_key_exists((string)$kategorieId, $kategorien) || !array_key_exists((string)$kategorie2Id, $kategorien2) || !array_key_exists((string)$doktypId, $dokumenttypen)) $error = 'Kategorie oder Dokumententyp ist ungültig.';

        $updated = $storedDocument;
        $updated['Datum'] = $datum;
        $updated['kategorie_id'] = $kategorieId;
        $updated['kategorie2_id'] = $kategorie2Id;
        $updated['doktyp_id'] = $doktypId;
        $updated['Partner'] = $partner;
        $updated['Betreff'] = $betreff;
        $updated['numbers'] = $document['numbers'];
        $updated['Nummer'] = $nummer;
        $updated['Tags'] = $tags;
        if ($pendingDocumentText !== '') $updated['Doktext'] = $pendingDocumentText;

        if ($error === '') {
            $documentTextChanged = normalize_document_text($updated['Doktext'] ?? '') !== $storedDocumentText;
            $hasChanges = $documentTextChanged || $updated['numbers'] !== $storedDocument['numbers'];
            foreach (['Datum', 'kategorie_id', 'kategorie2_id', 'doktyp_id', 'Partner', 'Betreff', 'Nummer', 'Tags'] as $field) {
                if (normalized_compare_value($field, $updated[$field] ?? null) !== normalized_compare_value($field, $storedDocument[$field] ?? null)) {
                    $hasChanges = true;
                    break;
                }
            }
            if (!$hasChanges) {
                header('Location: bearbeiten.php?dokid=' . rawurlencode((string)$dokId) . '&saved=1&return=' . rawurlencode($returnUrl));
                exit;
            }

            $metaPath = rtrim(PFAD_META, '/\\') . DIRECTORY_SEPARATOR . $dokId . 'meta.json';
            $tempMeta = null;
            $backupMeta = null;
            $metaPublished = false;
            try {
                $db->beginTransaction();
                $currentStmt = $db->prepare('SELECT * FROM ' . DB_TABELLE_ARCHIV . ' WHERE DokID = ? AND GeloeschtAm IS NULL FOR UPDATE');
                $currentStmt->execute([$dokId]);
                $currentAssignments = $currentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$currentAssignments) throw new RuntimeException('Dokument nicht mehr vorhanden.');
                $currentAssignments['numbers'] = eablage_nummern_laden($db,(int)$dokId);
                if (!hash_equals(document_revision(array_intersect_key($currentAssignments, $storedDocument)), $documentRevision)) {
                    throw new UnexpectedValueException('Das Dokument wurde zwischenzeitlich geändert. Bitte die eigenen Eingaben sichern und das Dokument neu öffnen.');
                }
                $updated['numbers'] = eablage_nummern_pruefen($db,$_POST['numbers_json'] ?? '[]',$currentAssignments['numbers']);
                $nummer = ($updated['numbers'][0]['value'] ?? '');
                $updated['Nummer'] = $nummer;
                eablage_pruefe_zuordnungen($db, $updated, $currentAssignments);
                // Use the same database clock as the initial archive timestamp.
                $bearbeitungsdatum = $db->query('SELECT NOW()')->fetchColumn();
                if (!is_string($bearbeitungsdatum) || $bearbeitungsdatum === '') {
                    throw new RuntimeException('Die Bearbeitungszeit konnte nicht ermittelt werden.');
                }
                $updated['Aenderungsdatum'] = $bearbeitungsdatum;
                $updated['Doktext'] = normalize_document_text($updated['Doktext']);
                // All metadata must describe the exact UPDATE, including document text.
                $metadata = eablage_metadaten_vorbereiten(eablage_metadaten_dokument($db, $updated));
                $oldMeta = eablage_metadaten_lesen($metaPath);
                if ($oldMeta !== null) {
                    $oldJson = str_starts_with($oldMeta, "\xEF\xBB\xBF") ? substr($oldMeta, 3) : $oldMeta;
                    $oldFormat = json_decode($oldJson, true);
                    if (is_array($oldFormat) && isset($oldFormat['schema_version']) && !in_array($oldFormat['schema_version'], [1, 2, 3, 4], true)) {
                        throw new RuntimeException('Unbekannte Metadatenversion; Datei wird nicht überschrieben.');
                    }
                    $backupMeta = eablage_metadaten_temp(dirname($metaPath), $oldMeta);
                }
                $tempMeta = eablage_metadaten_temp(dirname($metaPath), $metadata['content']);
                $updateStmt = $db->prepare('UPDATE ' . DB_TABELLE_ARCHIV . ' SET Datum=:datum, kategorie_id=:kategorie_id, kategorie2_id=:kategorie2_id, doktyp_id=:doktyp_id, Partner=:partner, Betreff=:betreff, Nummer=:nummer, Tags=:tags, Doktext=:doktext, Aenderungsdatum=:aenderungsdatum WHERE DokID=:dokid');
                $updateStmt->execute([':datum' => $datum, ':kategorie_id' => $kategorieId, ':kategorie2_id' => $kategorie2Id, ':doktyp_id' => $doktypId, ':partner' => $partner, ':betreff' => $betreff, ':nummer' => $nummer, ':tags' => $tags, ':doktext' => normalize_document_text($updated['Doktext']), ':aenderungsdatum' => $bearbeitungsdatum, ':dokid' => $dokId]);
                eablage_nummern_speichern($db,(int)$dokId,$updated['numbers']);
                if (eablage_metadaten_lesen($metaPath) !== $oldMeta) throw new RuntimeException('Metadatei wurde zwischenzeitlich geändert.');
                if ($oldMeta === null) {
                    // Atomic exclusive publication, never overwrite a newly appeared file.
                    if (!link($tempMeta, $metaPath)) throw new RuntimeException('Metadatenziel existiert bereits oder ist nicht beschreibbar.');
                    $metaPublished = true;
                    @unlink($tempMeta);
                } else {
                    if (!rename($tempMeta, $metaPath)) throw new RuntimeException('Die Metadatei konnte nicht aktualisiert werden.');
                    $metaPublished = true;
                }
                $tempMeta = null;
                $details = ['felder' => eablage_protokoll_aenderungen($currentAssignments, $updated),
                    'dokumenttext_geaendert' => $documentTextChanged];
                if ($currentAssignments['numbers'] !== $updated['numbers']) {
                    $details['nummern_vorher']=$currentAssignments['numbers']; $details['nummern_nachher']=$updated['numbers'];
                }
                if ($reclassificationInfo !== '') {
                    $details['klassifizierung'] = eablage_protokoll_klassifizierung($reclassificationInfo);
                }
                eablage_protokoll($db, (int)$dokId, 'metadaten_geaendert', $details, $bearbeitungsdatum);
                $db->commit();
                $metaPublished = false;
                if ($backupMeta !== null) @unlink($backupMeta);
                header('Location: bearbeiten.php?dokid=' . rawurlencode((string)$dokId) . '&saved=1&return=' . rawurlencode($returnUrl));
                exit;
            } catch (Throwable $e) {
                // Restore the previous JSON while still holding the row lock if possible.
                if ($metaPublished) {
                    $restored = $backupMeta !== null ? @rename($backupMeta, $metaPath) : @unlink($metaPath);
                    if (!$restored) error_log('e_ablage: JSON-Rollback fehlgeschlagen; Sicherung: ' . ($backupMeta ?? 'keine') . '; Archivprüfung erforderlich.');
                    if ($restored) $backupMeta = null;
                } elseif ($backupMeta !== null) {
                    @unlink($backupMeta);
                }
                if ($db->inTransaction()) $db->rollBack();
                if ($tempMeta !== null && file_exists($tempMeta)) @unlink($tempMeta);
                error_log('e_ablage Dokument bearbeiten/speichern: ' . $e->getMessage());
                $error = $e instanceof UnexpectedValueException ? $e->getMessage() : 'Die Änderungen konnten nicht gespeichert werden.';
            }
        }
        $document = $updated;
    }
}

$pdfLink = 'archiv_pdf.php?dokid=' . rawurlencode((string)$dokId);
$displayDocumentText = $pendingDocumentText !== '' ? $pendingDocumentText : $storedDocumentText;
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage - Dokument bearbeiten</title>
    <link href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>" rel="stylesheet">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <link rel="icon" href="ea_icon4.png" type="image/png">
</head>

<body>
    <?php include 'navbar.php'; ?><div class="container">
        <?php if ($saved): ?><?= eablage_render_message('success', 'Die Änderungen wurden gespeichert.') ?><?php endif; ?>
        <?php if ($error !== ''): ?><?= eablage_render_message('danger', $error) ?><?php endif; ?>
        <?php if ($textReloaded): ?><?= eablage_render_message('info', ($action === 'ocr_text' ? 'Der Dokumenttext wurde seitenweise mit OCR eingelesen. ' : 'Der Dokumenttext wurde erneut aus der archivierten PDF eingelesen. ') . ($textChanged ? 'Der neue Text weicht vom gespeicherten Text ab und wird erst mit „Speichern“ übernommen.' : 'Der neu gelesene Text entspricht dem bereits gespeicherten Text.')) ?><?php endif; ?>
        <?php if ($reclassified): ?><?= eablage_render_message('info', 'Die Klassifizierung wurde erneut ausgeführt. Die angezeigten Werte sind nur Vorschläge und noch nicht gespeichert.' . ($reclassificationInfo !== '' && $reclassificationInfo !== 'Manuell' ? ' Erkennung: ' . $reclassificationInfo : '')) ?><?php endif; ?>
        <div class="row ea-classification-row">
            <div class="col-md-6 ea-classification-column">
                <div class="panel panel-primary ea-classification-panel">
                    <div class="panel-heading ea-edit-heading">
                        <span>✏️ Dokumentdaten bearbeiten</span>
                        <details class="ea-more-actions" id="edit-more-actions">
                            <summary aria-label="Weitere Aktionen" title="Weitere Aktionen">⋮</summary>
                            <div class="ea-more-actions-list">
                                <button type="submit" form="edit-document-form" name="action" value="reclassify" formnovalidate>🔄 Neu klassifizieren</button>
                                <button type="submit" form="edit-document-form" name="action" value="reload_text" formnovalidate>📄 Text neu einlesen</button>
                                <button type="submit" form="edit-document-form" name="action" value="ocr_text" formnovalidate>🔎 OCR-Text einlesen</button>
                                <a href="export.php?dokid=<?= rawurlencode((string)$storedDocument['DokID']) ?>">⬇️ Exportieren</a>
                            </div>
                        </details>
                    </div>
                    <div class="panel-body">
                        <form action="bearbeiten.php" method="POST" id="edit-document-form" data-document-draft>
                            <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                            <input type="hidden" name="document_revision" value="<?=h($documentRevision)?>">
                            <input type="hidden" name="dokid" value="<?= h($storedDocument['DokID']) ?>"><input type="hidden" name="return" value="<?= h($returnUrl) ?>"><input type="hidden" name="reclassification_info" value="<?= h($reclassificationInfo) ?>"><input type="hidden" name="pending_document_text" value="<?= h($pendingDocumentText) ?>">
                            <div class="form-group"><label for="Datum">Dokumenten-Datum<?= changed_marker($reclassificationChanged, 'Datum') ?></label><input type="date" id="Datum" name="Datum" class="form-control" value="<?= h($document['Datum']) ?>" required></div>
                            <div class="form-group"><label for="Partner">Partner / Absender<?= changed_marker($reclassificationChanged, 'Partner') ?></label><input type="text" id="Partner" name="Partner" class="form-control" value="<?= h($document['Partner']) ?>"></div>
                            <div class="form-group"><label for="Betreff">Betreff<?= changed_marker($reclassificationChanged, 'Betreff') ?></label><input type="text" id="Betreff" name="Betreff" class="form-control" value="<?= h($document['Betreff']) ?>"></div>
                            <div class="form-group"><label for="Doktyp">Dokumententyp<?= changed_marker($reclassificationChanged, 'doktyp_id') ?></label><select class="form-control" id="Doktyp" name="Doktyp"><?php foreach ($dokumenttypen as $id => $bez): ?><option value="<?= h($id) ?>" <?= (string)$id === (string)$document['doktyp_id'] ? 'selected' : '' ?>><?= h($bez) ?></option><?php endforeach; ?></select></div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group"><label for="Kategorie">Kategorie<?= changed_marker($reclassificationChanged, 'kategorie_id') ?></label><select class="form-control" id="Kategorie" name="Kategorie"><?php foreach ($kategorien as $id => $bez): ?><option value="<?= h($id) ?>" <?= (string)$id === (string)$document['kategorie_id'] ? 'selected' : '' ?>><?= h($bez) ?></option><?php endforeach; ?></select></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group"><label for="Kategorie2">Kategorie 2<?= changed_marker($reclassificationChanged, 'kategorie2_id') ?></label><select class="form-control" id="Kategorie2" name="Kategorie2"><?php foreach ($kategorien2 as $id => $bez): ?><option value="<?= h($id) ?>" <?= (string)$id === (string)$document['kategorie2_id'] ? 'selected' : '' ?>><?= h($bez) ?></option><?php endforeach; ?></select></div>
                                </div>
                            </div>
                            <?php eablage_nummern_editor($db,$document['numbers']); ?>
                            <div class="form-group"><label for="Tags">Stichworte / Tags<?= changed_marker($reclassificationChanged, 'Tags') ?></label><input type="text" id="Tags" name="Tags" class="form-control" value="<?= h($document['Tags']) ?>"></div>
                            <div class="ea-edit-actions">
                                <div class="ea-form-actions ea-document-actions">
                                    <a href="<?= h($returnUrl) ?>" class="btn btn-default" id="edit-document-exit"><?= $saved ? 'Zurück' : 'Abbrechen' ?></a>
                                    <button type="submit" form="trash-document-form" class="btn btn-danger">🗑️ In den Papierkorb</button>
                                    <button type="submit" name="action" value="save" class="btn btn-primary">💾 Speichern</button>
                                </div>
                            </div>
                        </form>
                        <form id="trash-document-form" action="papierkorb.php" method="POST" onsubmit="return confirm('Dokument <?=h($dokId)?> in den Papierkorb verschieben? Nicht gespeicherte Änderungen gehen verloren.');">
                            <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                            <input type="hidden" name="dokid" value="<?=h($dokId)?>">
                            <input type="hidden" name="action" value="trash">
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6 ea-classification-column">
                <div class="panel panel-primary ea-pdf-panel">
                    <div class="panel-heading"><span>📄 Dokument <?= h($storedDocument['DokID']) ?></span>
                        <div class="btn-group btn-group-xs" role="group" aria-label="Dokumentansicht"><button type="button" class="btn btn-default active" id="viewPdfButton" onclick="eablageSetDocumentView('pdf')">PDF</button><button type="button" class="btn btn-default" id="viewTextButton" onclick="eablageSetDocumentView('text')">Text</button></div>
                    </div>
                    <div class="panel-body ea-pdf-body" id="pdfDocumentView">
                        <div class="ea-mobile-pdf-overlay"><a href="<?= h($pdfLink) ?>" target="_blank" rel="noopener" class="btn btn-primary">PDF im Vollbild öffnen</a></div><iframe src="<?= h($pdfLink) ?>" title="PDF-Dokumentvorschau"></iframe>
                    </div>
                    <div class="panel-body" id="textDocumentView" hidden>
                        <pre><?= h($displayDocumentText) ?></pre>
                    </div>
                </div>
            </div>
        </div>
        <details class="ea-detail-panel">
            <summary>ℹ️ Technische Informationen</summary>
            <div>
                <p><strong>DokID:</strong> <?= h($storedDocument['DokID'] ?? '') ?></p>
                <p><strong>Archiviert:</strong> <?= h($storedDocument['Archivierungsdatum'] ?? '') ?></p>
                <p><strong>Zuletzt geändert:</strong> <?= h($storedDocument['Aenderungsdatum'] ?? '–') ?></p>
                <p><strong>Originaldateiname:</strong> <?= h($storedDocument['Originaldateiname'] ?? '–') ?></p>
                <p style="overflow-wrap:anywhere;"><strong>PDF SHA-256:</strong> <?= h($storedDocument['DateiSHA256'] ?? '–') ?></p>
                <p><a href="protokoll.php?<?= h(http_build_query(['dokid' => (int)$dokId, 'return' => 'bearbeiten.php?' . http_build_query(['dokid' => (int)$dokId, 'return' => $returnUrl])])) ?>" data-draft-link>Dokumenthistorie öffnen</a></p>
                <p><strong>Bisheriges Info-Feld (Altbestand):</strong> <?= h($storedDocument['Info'] ?? '') ?></p>
                <p><strong>Dokumenttext:</strong> <?= number_format(strlen((string)($storedDocument['Doktext'] ?? '')), 0, ',', '.') ?> Byte gespeichert<?php if ($pendingDocumentText !== ''): ?> / <?= number_format(strlen($pendingDocumentText), 0, ',', '.') ?> Byte neu eingelesen<?php endif; ?></p>
                <p style="margin-bottom:0;"><strong>Datei:</strong> <?= h($filename) ?></p>
            </div>
        </details>
    </div>
    <script>
        <?php if ($saved): ?>
            var editForm = document.getElementById('edit-document-form');
            var editExit = document.getElementById('edit-document-exit');
            ['input', 'change'].forEach(function(eventName) {
                editForm.addEventListener(eventName, function() {
                    editExit.textContent = 'Abbrechen';
                });
            });
        <?php endif; ?>

        function eablageSetDocumentView(view) {
            var pdf = document.getElementById('pdfDocumentView');
            var text = document.getElementById('textDocumentView');
            var pdfButton = document.getElementById('viewPdfButton');
            var textButton = document.getElementById('viewTextButton');
            var showText = view === 'text';
            pdf.hidden = showText;
            text.hidden = !showText;
            pdfButton.classList.toggle('active', !showText);
            textButton.classList.toggle('active', showText);
        }

        var moreActions = document.getElementById('edit-more-actions');
        document.addEventListener('click', function(event) {
            if (!moreActions.contains(event.target)) moreActions.open = false;
        });
        moreActions.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                moreActions.open = false;
                moreActions.querySelector('summary').focus();
            }
        });
    </script>
    <script src="classification_draft.js"></script>
</body>

</html>

