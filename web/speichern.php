<?php

require_once 'konfiguration.php';
require_once 'meldungen.php';
require_once 'csrf.php';
require_once 'stammdaten_funktionen.php';
require_once 'metadaten_funktionen.php';
require_once 'inbox_funktionen.php';
require_once 'protokoll_funktionen.php';

$db = $db_config;

function eablage_autokat_regel_ids(string $erkennungsInfo): array
{
    if (!preg_match_all('/\bR\d{4,}\b/i', $erkennungsInfo, $matches)) {
        return [];
    }

    return array_values(array_unique(array_map('strtoupper', $matches[0])));
}

function eablage_save_error_page(string $message, int $status = 500): void
{
    http_response_code($status);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>e_ablage - Fehler</title>
        <link href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>" rel="stylesheet">
    </head>
    <body>
        <?php include 'navbar.php'; ?>
        <div class="container">
            <?=eablage_render_message('danger', $message)?>
            <a href="javascript:history.back()" class="btn btn-default">Zurück zum Formular</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pfadInbox = PFAD_INBOX;
$pfadArchiv = PFAD_ARCHIV;
$pfadMeta = PFAD_META;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['original_datei'])) {
    header('Location: eingang.php');
    exit;
}

try {
    eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
} catch (RuntimeException $e) {
    eablage_save_error_page($e->getMessage(), 403);
}

$regelVorbereiten = ($_POST['after_save'] ?? '') === 'prepare_rule';

$originalFileRaw = (string)$_POST['original_datei'];
$originalFile = basename($originalFileRaw);

if (
    $originalFileRaw !== $originalFile ||
    str_contains($originalFile, chr(92)) || preg_match('/[\x00-\x1f\x7f]/', $originalFile) ||
    strtolower(pathinfo($originalFile, PATHINFO_EXTENSION)) !== 'pdf'
) {
    eablage_save_error_page('Ungültiger Dateiname. Das Dokument konnte nicht archiviert werden.');
}

$datum = !empty($_POST['Datum']) ? $_POST['Datum'] : date('Y-m-d');
$kategorieId = (!empty($_POST['Kategorie']) || (isset($_POST['Kategorie']) && $_POST['Kategorie'] === '0'))
    ? trim((string)$_POST['Kategorie'])
    : '0';
$kategorie2Id = (!empty($_POST['Kategorie2']) || (isset($_POST['Kategorie2']) && $_POST['Kategorie2'] === '0'))
    ? trim((string)$_POST['Kategorie2'])
    : '0';
$doktypId = (!empty($_POST['Doktyp']) || (isset($_POST['Doktyp']) && $_POST['Doktyp'] === '0'))
    ? trim((string)$_POST['Doktyp'])
    : '0';
$partner = !empty($_POST['Partner']) ? trim($_POST['Partner']) : '';
$betreff = !empty($_POST['Betreff']) ? trim($_POST['Betreff']) : '';
$nummer = !empty($_POST['Nummer']) ? trim($_POST['Nummer']) : '';
$tags = !empty($_POST['Tags']) ? trim($_POST['Tags']) : '';
$dokText = !empty($_POST['dokument_text']) ? $_POST['dokument_text'] : '';
$erkennungsInfo = !empty($_POST['erkennungs_info'])
    ? trim($_POST['erkennungs_info'])
    : 'Manuell';
$autokatRegelIds = eablage_autokat_regel_ids($erkennungsInfo);
$quellPfad = $pfadInbox . $originalFile;

if (!file_exists($quellPfad)) {
    eablage_save_error_page(
        'Die Quelldatei existiert nicht mehr im Eingang. Das Dokument konnte nicht archiviert werden.'
    );
}

$inboxLock = null;
$sourceGuard = null;
$archiveCreated = false;
$metaCreated = false;
$archiveLockHeld = false;
$archiveLockName = 'eablage.archive.' . substr(hash('sha256', (string)MYSQL_DATENBANK), 0, 40);

try {
    $inboxLock = eablage_inbox_lock($db, $originalFile);
    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lockStmt->execute([$archiveLockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Archivierung ist belegt. Bitte erneut versuchen.');
    }
    $archiveLockHeld = true;
    if (is_link($quellPfad) || !is_file($quellPfad)) throw new RuntimeException('Quelldatei ist keine reguläre Eingangsdatei.');
    $sourceGuard = @fopen($quellPfad, 'rb');
    if ($sourceGuard === false || !flock($sourceGuard, LOCK_SH | LOCK_NB)) throw new RuntimeException('Die Eingangsdatei wird noch übertragen. Bitte später erneut versuchen.');
    $dateiSHA256 = hash_file('sha256', $quellPfad);
    if ($dateiSHA256 === false) throw new RuntimeException('Prüfsumme der Quelldatei konnte nicht ermittelt werden.');
    if (isset($_POST['source_sha256']) && !hash_equals((string)$_POST['source_sha256'], $dateiSHA256)) {
        throw new RuntimeException('Die Eingangsdatei wurde seit der Erkennung geändert. Bitte erneut klassifizieren.');
    }
    $db->beginTransaction();
    $inboxJobId = (int)($_POST['inbox_job_id'] ?? 0);
    if ($inboxJobId > 0) {
        $jobStmt = $db->prepare('SELECT * FROM verarbeitungsauftraege WHERE id=? FOR UPDATE');
        $jobStmt->execute([$inboxJobId]);
        $savedJob = $jobStmt->fetch(PDO::FETCH_ASSOC);
        // Read failures have no content hash or proposals. Manual entry is still
        // bound to the source_sha256 captured when this form was opened.
        $manualReadFailure = $savedJob && $savedJob['sha256'] === '' && $savedJob['status'] === 'fehler'
            && $savedJob['ergebnis'] === null && isset($_POST['source_sha256']);
        if (!$savedJob || $savedJob['dateiname'] !== $originalFile || (!$manualReadFailure && !hash_equals($savedJob['sha256'], $dateiSHA256))
            || !in_array($savedJob['status'], ['bereit', 'fehler'], true)) {
            throw new RuntimeException('Der Verarbeitungsvorgang ist nicht mehr aktuell. Bitte den Eingang neu öffnen.');
        }
    }
    eablage_pruefe_zuordnungen($db, ['kategorie_id'=>$kategorieId, 'kategorie2_id'=>$kategorie2Id, 'doktyp_id'=>$doktypId]);

    $queryMax = $db->query('SELECT MAX(DokID) AS max_id FROM ' . DB_TABELLE_ARCHIV);
    $resultMax = $queryMax->fetch(PDO::FETCH_ASSOC);
    $lastIdStmt = $db->prepare('SELECT param_value FROM parameter WHERE param_key = ?');
    $lastIdStmt->execute(['eablageLetzteDokID']);
    $lastId = $lastIdStmt->fetchColumn();
    $neueDokID = max((int)DOKID_STARTWERT - 1, (int)($resultMax['max_id'] ?? 0), $lastId === false ? 0 : (int)$lastId) + 1;

    $neuerDateiname = eablage_archiv_pdf_dateiname((int)$neueDokID);
    $zielPfadArchiv = $pfadArchiv . $neuerDateiname;

    // Reserve identity under the existing archive lock and use the same DB time
    // for JSON and INSERT. Backend validation happens before all writes.
    $archivierungsdatum = $db->query('SELECT NOW()')->fetchColumn();
    $metadata = eablage_metadaten_vorbereiten(eablage_metadaten_dokument($db, [
        'DokID'=>(int)$neueDokID, 'Datum'=>$datum,
        'Archivierungsdatum'=>$archivierungsdatum, 'Aufbewahrungsdauer'=>null,
        'Originaldateiname'=>$originalFile, 'DateiSHA256'=>$dateiSHA256, 'Aenderungsdatum'=>null,
        'kategorie_id'=>$kategorieId, 'kategorie2_id'=>$kategorie2Id, 'doktyp_id'=>$doktypId,
        'Partner'=>$partner, 'Betreff'=>$betreff, 'Nummer'=>$nummer, 'Tags'=>$tags,
        'Doktext'=>$dokText,
    ]));
    $zielPfadMeta = $pfadMeta . $metadata['filename'];
    $metaInhalt = $metadata['content'];
    // Do not reuse an ID with leftover JSON metadata.
    foreach ([$zielPfadMeta] as $candidate) {
        if (file_exists($candidate) || is_link($candidate)) throw new RuntimeException('Metadatenziel existiert bereits.');
    }

    $sql = 'INSERT INTO ' . DB_TABELLE_ARCHIV . '
            (DokID, Datum, kategorie_id, kategorie2_id, doktyp_id, partner, betreff, Nummer, tags, Doktext, Archivierungsdatum, Originaldateiname, DateiSHA256, Aenderungsdatum)
            VALUES
            (:dokid, :datum, :kategorie_id, :kategorie2_id, :doktyp_id, :partner, :betreff, :nummer, :tags, :doktext, :archivierungsdatum, :originaldateiname, :dateisha256, NULL)';

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':dokid', $neueDokID, PDO::PARAM_INT);
    $stmt->bindValue(':datum', $datum);
    $stmt->bindValue(':kategorie_id', $kategorieId, PDO::PARAM_STR);
    $stmt->bindValue(':kategorie2_id', $kategorie2Id, PDO::PARAM_STR);
    $stmt->bindValue(':doktyp_id', $doktypId, PDO::PARAM_STR);
    $stmt->bindValue(':partner', $partner);
    $stmt->bindValue(':betreff', $betreff);
    $stmt->bindValue(':nummer', $nummer);
    $stmt->bindValue(':tags', $tags);
    $stmt->bindValue(':doktext', $dokText);
    $stmt->bindValue(':archivierungsdatum', $archivierungsdatum);
    $stmt->bindValue(':originaldateiname', $originalFile);
    $stmt->bindValue(':dateisha256', $dateiSHA256);
    $stmt->execute();

    // Exclusive creation prevents overwriting orphaned/existing archive files.
    // Keep the inbox PDF intact until the database transaction has committed.
    $archiveHandle = @fopen($zielPfadArchiv, 'xb');
    if ($archiveHandle === false) throw new RuntimeException('Archivziel existiert bereits oder ist nicht beschreibbar.');
    $archiveCreated = true;
    $sourceHandle = @fopen($quellPfad, 'rb');
    try {
        if ($sourceHandle === false) throw new RuntimeException('Quelldatei ist nicht lesbar.');
        $sourceStat = fstat($sourceHandle);
        $copied = stream_copy_to_stream($sourceHandle, $archiveHandle);
        if ($copied === false || $sourceStat === false || $copied !== $sourceStat['size'] || !fflush($archiveHandle)) {
            throw new RuntimeException('PDF konnte nicht vollständig ins Archiv geschrieben werden.');
        }
    } finally {
        if (is_resource($sourceHandle)) fclose($sourceHandle);
        fclose($archiveHandle);
    }

    $archiveSHA256 = hash_file('sha256', $zielPfadArchiv);
    if ($archiveSHA256 === false || !hash_equals($dateiSHA256, $archiveSHA256)) {
        throw new RuntimeException('Archivkopie stimmt nicht mit der geprüften Quelldatei überein.');
    }

    $metaHandle = @fopen($zielPfadMeta, 'xb');
    if ($metaHandle === false) throw new RuntimeException('Metadatenziel existiert bereits oder ist nicht beschreibbar.');
    $metaCreated = true;
    try {
        $offset = 0;
        while ($offset < strlen($metaInhalt)) {
            $written = fwrite($metaHandle, substr($metaInhalt, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Metadatei konnte nicht vollständig geschrieben werden.');
            $offset += $written;
        }
        if (!fflush($metaHandle)) throw new RuntimeException('Metadatei konnte nicht abgeschlossen werden.');
    } finally {
        fclose($metaHandle);
    }

    if ($autokatRegelIds) {
        $trefferStmt = $db->prepare(
            'UPDATE autokat
             SET Treffer = Treffer + 1, LetzterTreffer = NOW()
             WHERE RegelID = :regel_id'
        );

        foreach ($autokatRegelIds as $regelId) {
            $trefferStmt->execute([':regel_id' => $regelId]);
        }
    }

    if ($inboxJobId > 0) {
        $jobStmt = $db->prepare("UPDATE verarbeitungsauftraege SET status='archiviert', dok_id=?, sha256=?, ergebnis=NULL, fehler=NULL WHERE id=?");
        $jobStmt->execute([$neueDokID, $dateiSHA256, $inboxJobId]);
    }
    eablage_protokoll($db, (int)$neueDokID, 'archiviert', [
        'klassifizierung' => eablage_protokoll_klassifizierung($erkennungsInfo),
    ], $archivierungsdatum);
    $db->commit();
    // After commit these files belong to the durable document, never to rollback.
    $archiveCreated = false;
    $metaCreated = false;
    // Retain a source replaced/changed during archival instead of deleting new data.
    clearstatcache(true, $quellPfad);
    $sourceSHA256 = !is_link($quellPfad) && is_file($quellPfad) ? @hash_file('sha256', $quellPfad) : false;
    $inboxRemoved = $sourceSHA256 !== false && hash_equals($dateiSHA256, $sourceSHA256) && @unlink($quellPfad);
    if (!$inboxRemoved) error_log('e_ablage: Archiviert, aber Eingang konnte nicht entfernt werden: ' . $quellPfad);
    try {
        $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$archiveLockName]);
    } catch (Throwable $releaseError) { error_log('e_ablage: Archivierungssperre wird beim Verbindungsende freigegeben.'); }
    $archiveLockHeld = false;

    if (is_resource($sourceGuard)) fclose($sourceGuard);
    eablage_inbox_unlock($db, $inboxLock);
    $inboxLock = null;
    eablage_flash_set(
        $inboxRemoved ? 'success' : 'warning',
        'Dokument ' . $neueDokID . ' wurde erfolgreich archiviert.' . ($inboxRemoved ? '' : ' Die Eingangskopie konnte nicht entfernt werden. Bitte nicht erneut archivieren.')
    );

    header('Location: ' . ($regelVorbereiten
        ? 'autokat_regel_bearbeiten.php?source_dokid=' . rawurlencode((string)$neueDokID) . '&return=eingang.php'
        : 'eingang.php'));
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ($metaCreated && !@unlink($zielPfadMeta)) error_log('e_ablage Rollback: Eigene Metadatei konnte nicht entfernt werden.');
    if ($archiveCreated && !@unlink($zielPfadArchiv)) error_log('e_ablage Rollback: Eigene Archivkopie konnte nicht entfernt werden.');
    if ($archiveLockHeld) {
        try {
            $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStmt->execute([$archiveLockName]);
        } catch (Throwable $releaseError) { error_log('e_ablage: Archivierungssperre wird beim Verbindungsende freigegeben.'); }
    }

    if (is_resource($sourceGuard)) fclose($sourceGuard);
    eablage_inbox_unlock($db, $inboxLock);
    error_log(
        'e_ablage Speicherfehler: ' . $e->getMessage()
        . ' in ' . $e->getFile()
        . ':' . $e->getLine()
    );

    eablage_save_error_page(
        ($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Das Dokument konnte nicht archiviert werden. Details wurden im PHP-Fehlerprotokoll gespeichert.'
    );
}
