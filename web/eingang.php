<?php

require_once 'konfiguration.php';
require_once 'meldungen.php';
require_once 'csrf.php';
require_once 'funktionen.php';
require_once 'inbox_funktionen.php';
require_once 'upload_funktionen.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$scanOrdner = PFAD_INBOX;
$meldung = '';
$meldungsTyp = 'info';
$flashMeldung = eablage_flash_get();
$aktionToken = eablage_aktion_token();

if (($_GET['action'] ?? '') === 'discard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inboxLock = null;
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $name = $_POST['file'] ?? null;
        $expected = $_POST['sha256'] ?? null;
        if (!is_string($name) || $name === '' || basename($name) !== $name || preg_match('/[\x00-\x1f\x7f]/', $name)
            || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf'
            || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
            throw new RuntimeException('Ungültige Eingangsdatei.');
        }
        $inboxLock = eablage_inbox_lock($db_config, $name);
        $root = realpath($scanOrdner);
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $name);
        if ($path === false || dirname($path) !== $root || is_link($root . DIRECTORY_SEPARATOR . $name) || !is_file($path)
            || !hash_equals($expected, (string)hash_file('sha256', $path))) {
            throw new RuntimeException('Die Eingangsdatei wurde zwischenzeitlich geändert. Bitte Ansicht neu laden.');
        }
        if (!unlink($path)) throw new RuntimeException('Die Datei konnte nicht entfernt werden.');
        try {
            $stmt = $db_config->prepare("UPDATE verarbeitungsauftraege SET status='verworfen', ergebnis=NULL, fehler=NULL WHERE dateischluessel=? AND (sha256=? OR (sha256='' AND ergebnis IS NULL AND status IN ('fehler','wartend')))");
            $stmt->execute([hash('sha256', MYSQL_DATENBANK . "\n" . $name), $expected]);
        } catch (PDOException $e) { error_log('Inbox-Auftrag konnte nicht abgeschlossen werden: ' . $e->getMessage()); }
        eablage_inbox_unlock($db_config, $inboxLock);
        eablage_flash_set('success', 'Eingangsdatei verworfen.');
        header('Location: eingang.php');
        exit;
    } catch (Throwable $e) {
        eablage_inbox_unlock($db_config, $inboxLock);
        $meldung = $e->getMessage();
        $meldungsTyp = 'danger';
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (eablage_upload_post_zu_gross()) {
        // PHP discards POST and FILES when the whole request exceeds post_max_size.
        // Report this before CSRF validation; no file operation is performed.
        $meldung = 'Die Uploadanfrage ist zu groß. Erlaubte Dateigröße: ' . eablage_upload_limit_text() . '.';
        $meldungsTyp = 'danger';
    } else {
        try {
            eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
            if (isset($_FILES['pdf_datei']) && $_FILES['pdf_datei']['error'] === UPLOAD_ERR_OK && (int)$_FILES['pdf_datei']['size'] > eablage_upload_max_bytes()) {
                $meldung = eablage_upload_fehler(UPLOAD_ERR_INI_SIZE);
                $meldungsTyp = 'danger';
            } elseif (isset($_FILES['pdf_datei']) && $_FILES['pdf_datei']['error'] === UPLOAD_ERR_OK) {
                $originalName = $_FILES['pdf_datei']['name'];
                $erweiterung = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if ($erweiterung === 'pdf' && $originalName === basename(str_replace('\\', '/', $originalName))
                    && !preg_match('/[\x00-\x1f\x7f]/', $originalName)) {
                    // Den echten Eingangsdateinamen erhalten: er wird später als Originaldateiname archiviert.
                    // Exklusives Anlegen verhindert auch bei gleichzeitigen Uploads ein Überschreiben.
                    $zielPfad = $scanOrdner . $originalName;
                    $ziel = @fopen($zielPfad, 'xb');
                    if ($ziel === false) {
                        $meldung = file_exists($zielPfad)
                            ? 'Eine Datei mit diesem Namen liegt bereits im Eingang. Bitte zuerst verarbeiten oder umbenennen.'
                            : 'Fehler beim Speichern der Datei im Eingang. Stimmen die Ordnerrechte?';
                        $meldungsTyp = file_exists($zielPfad) ? 'warning' : 'danger';
                    } else {
                        flock($ziel, LOCK_EX);
                        $quelle = @fopen($_FILES['pdf_datei']['tmp_name'], 'rb');
                        $geschrieben = $quelle !== false ? @stream_copy_to_stream($quelle, $ziel) : false;
                        if ($quelle !== false) fclose($quelle);
                        $vollstaendig = $geschrieben !== false
                            && $geschrieben === (int)$_FILES['pdf_datei']['size']
                            && @fflush($ziel);
                        if (!fclose($ziel)) $vollstaendig = false;
                        if ($vollstaendig) {
                            $meldung = 'Datei erfolgreich hochgeladen und in den Eingang gelegt.';
                            $meldungsTyp = 'success';
                        } else {
                            @unlink($zielPfad);
                            $meldung = 'Fehler beim Speichern der Datei im Eingang. Stimmen die Ordnerrechte?';
                            $meldungsTyp = 'danger';
                        }
                    }
                } else {
                    $meldung = 'Es dürfen nur PDF-Dateien hochgeladen werden.';
                    $meldungsTyp = 'warning';
                }
            } else {
                $meldung = eablage_upload_fehler((int)($_FILES['pdf_datei']['error'] ?? UPLOAD_ERR_NO_FILE));
                $meldungsTyp = 'danger';
            }
        } catch (RuntimeException $e) {
            http_response_code(403);
            $meldung = $e->getMessage();
            $meldungsTyp = 'danger';
        }
    }
}

$inboxJobs = [];
$kiWorker = [];
$jobsResponse = eablage_analysis_post('/inbox/jobs', ['database'=>MYSQL_DATENBANK], 3);
if ($jobsResponse['ok']) $kiWorker = $jobsResponse['data']['ki_worker'] ?? [];
if ($jobsResponse['ok']) foreach ($jobsResponse['data']['jobs'] as $item) $inboxJobs[$item['dateiname']] = $item;
$jobLabels = ['wartend'=>'Wartet auf Erkennung', 'verarbeitung'=>'In Verarbeitung', 'bereit'=>'Zur Prüfung bereit', 'fehler'=>'Erkennung fehlgeschlagen', 'archiviert'=>'Bereits archiviert', 'verworfen'=>'Wartet auf Erkennung'];
$kiStatusLabels = ['wartend'=>'KI wartet.', 'verarbeitung'=>'KI läuft.', 'bereit'=>'KI-Vorschlag verfügbar.', 'fehler'=>'KI fehlgeschlagen.', 'pausiert'=>'KI-Verarbeitung pausiert.'];
$gefundeneDateien = [];

if (is_dir($scanOrdner)) {
    $alleDateien = array_diff(scandir($scanOrdner), ['.', '..']);

    foreach ($alleDateien as $datei) {
        if (strtolower(pathinfo($datei, PATHINFO_EXTENSION)) === 'pdf') {
            $gefundeneDateien[] = $datei;
        }
    }
} else {
    $meldung = 'Der konfigurierte Scan-Ordner wurde nicht gefunden: ' . $scanOrdner;
    $meldungsTyp = 'danger';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage - Eingang</title>
    <link href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>" rel="stylesheet">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <link rel="icon" href="ea_icon4.png" type="image/png">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
    <?php if ($flashMeldung !== null): ?>
        <?=eablage_render_flash($flashMeldung)?>
    <?php endif; ?>

    <?php if ($meldung !== ''): ?>
        <?=eablage_render_message($meldungsTyp, $meldung)?>
    <?php endif; ?>

    <div class="row ea-workspace-row">
        <div class="col-md-6 ea-workspace-column">
            <div class="panel panel-primary">
                <div class="panel-heading">📂 Eingang (<?=count($gefundeneDateien)?> neue Dokumente)</div>
                <div class="panel-body">
                    <?php $kiWorkerNotice = !empty($kiWorker['enabled']) && (empty($kiWorker['fresh']) || in_array($kiWorker['state'] ?? '', ['pausiert','deaktiviert'], true)); ?>
                    <p id="ki-worker-status" class="text-muted" role="status" <?=!$kiWorkerNotice ? 'hidden' : ''?>><?=h($kiWorkerNotice ? ($kiWorker['message'] ?? '') : '')?></p>
                    <?php if (empty($gefundeneDateien)): ?>
                        <div class="search-summary">✨ Es liegen aktuell keine neuen Dokumente im Scan-Ordner.</div>
                    <?php else: ?>
                        <ul class="ea-inbox-list">
                            <?php foreach ($gefundeneDateien as $datei): ?>
                                <li class="ea-inbox-entry">
                                    <div class="ea-inbox-file">
                                        <span class="ea-pdf-icon" aria-hidden="true">📄</span>
                                        <div class="ea-inbox-filename"><?=h($datei)?><br><small class="text-muted" data-inbox-status="<?=h($datei)?>"><?=h($jobLabels[$inboxJobs[$datei]['status'] ?? 'wartend'] ?? 'Wartet auf Erkennung')?></small>
                                            <?php $ki = $inboxJobs[$datei]['ki'] ?? null; ?>
                                            <div data-inbox-ai-block <?=!$ki ? 'hidden' : ''?>>
                                                <small class="text-muted" data-inbox-ai-status><?=h($kiStatusLabels[$ki['state'] ?? ''] ?? '')?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ea-inbox-actions">
                                        <?php $inboxPath = $scanOrdner . $datei; if (is_file($inboxPath) && !is_link($inboxPath)): ?>
                                            <form action="eingang.php?action=discard" method="POST" onsubmit="return confirm('Diese Eingangsdatei endgültig löschen? Sie wird nicht in den Papierkorb verschoben.');">
                                                <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                                                <input type="hidden" name="file" value="<?=h($datei)?>">
                                                <input type="hidden" name="sha256" value="<?=h(hash_file('sha256', $inboxPath))?>">
                                                <button type="submit" class="btn btn-danger">Verwerfen</button>
                                            </form>
                                        <?php endif; ?>
                                        <a class="btn btn-default" href="verarbeiten.php?file=<?=urlencode($datei)?>">Klassifizieren</a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6 ea-workspace-column">
            <div class="panel panel-primary">
                <div class="panel-heading">📤 Dokument hochladen</div>
                <div class="panel-body">
                    <form action="eingang.php?action=upload" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        <div class="form-group ea-upload-dropzone" id="pdf_dropzone">
                            <label for="pdf_datei">PDF-Dokument auswählen oder hier ablegen</label>
                            <p class="ea-upload-dropzone__hint">Eine PDF-Datei aus dem Dateimanager in diesen Bereich ziehen. Maximal <?=h(eablage_upload_limit_text())?> pro Datei.</p>
                            <input
                                type="file"
                                id="pdf_datei"
                                name="pdf_datei"
                                class="form-control"
                                accept="application/pdf,.pdf"
                                required
                            >
                            <p class="ea-upload-dropzone__status" id="pdf_drop_status" role="status" aria-live="polite"></p>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:10px;">
                            🚀 Datei in den Eingang hochladen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="inbox_status.js?v=<?=filemtime(__DIR__ . '/inbox_status.js')?>" defer></script>
<script src="upload_dropzone.js?v=<?=filemtime(__DIR__ . '/upload_dropzone.js')?>"></script>
</body>
</html>
