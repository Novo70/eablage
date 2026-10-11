<?php
require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'meldungen.php';
require_once 'csrf.php';
require_once 'datenbank_sicherung.php';
require_once 'datenbank_wiederherstellung.php';

$db = $db_config;
require_once 'archiv_wartung.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function export_parameter(PDO $db, string $key): ?string
{
    $stmt = $db->prepare('SELECT param_value FROM parameter WHERE param_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : trim((string)$value);
}

function configured_path(?string $path, array $placeholders = []): bool
{
    if ($path === null || trim($path) === '') return false;
    $invalid = array_merge(['(keine angabe)', '(bitte konfigurieren)'], $placeholders);
    return !in_array(strtolower(trim($path)), array_map('strtolower', $invalid), true);
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}

function export_backend_plan(): array
{
    $response = eablage_backend_request('GET', '/export/plan', null, 30);
    if (!$response['ok']) throw new RuntimeException('Der Exportplan konnte nicht abgerufen werden. ' . $response['error']);
    $plan = $response['data'];
    if (!is_array($plan) || !isset($plan['items']) || !is_array($plan['items'])) {
        throw new RuntimeException('Das Python-Backend hat keinen gültigen Exportplan geliefert.');
    }

    return $plan;
}

function export_source_files(PDO $db): array
{
    $stmt = $db->query('SELECT DokID FROM ' . DB_TABELLE_ARCHIV . ' WHERE GeloeschtAm IS NULL');
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dokId = (int)($row['DokID'] ?? 0);
        if ($dokId < 1) continue;
        $result[$dokId] = eablage_archiv_pdf_dateiname($dokId);
    }
    return $result;
}

function export_safe_directory_part($value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '.' || $value === '..') return '';
    return basename(str_replace('\\', '/', $value));
}

$meldung = '';
$meldungTyp = 'info';
$exportResult = null;
$backupResult = null;
$restoreResult = null;
$restoreAvailable = false;

$baseUrl = eablage_backend_url();
$targetRoot = export_parameter($db, '1_expVerzeichnis_linux');
$targetConfigured = configured_path($targetRoot, ['pfad_export']);
$backupRoot = export_parameter($db, 'pfadDatensicherung_linux');
$backupConfigured = configured_path($backupRoot, ['pfad_datensicherung']);
$restoreAvailable = eablage_restore_empty($db);
$restoreFiles = $backupConfigured && $restoreAvailable ? eablage_restore_files($backupRoot) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['export', 'backup', 'restore'], true)) {
        try {
            eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        } catch (RuntimeException $e) {
            http_response_code(403);
            $meldung = $e->getMessage();
            $meldungTyp = 'danger';
            $action = '';
        }
    }

    if ($action === 'export') {
        if ($baseUrl === '') {
            $meldung = 'Der Python-Analysedienst ist nicht konfiguriert.';
            $meldungTyp = 'danger';
        } elseif (!$targetConfigured) {
            $meldung = 'Für den Gesamtexport ist noch kein Zielverzeichnis im Parameter 1_expVerzeichnis_linux konfiguriert.';
            $meldungTyp = 'danger';
        } else {
            try {
                if (!is_dir($targetRoot) && !mkdir($targetRoot, 0775, true) && !is_dir($targetRoot)) {
                    throw new RuntimeException('Das Exportverzeichnis konnte nicht angelegt werden.');
                }
                if (!is_writable($targetRoot)) {
                    throw new RuntimeException('Das Exportverzeichnis ist nicht beschreibbar.');
                }

                $plan = export_backend_plan();
                $plannedPaths = [];
                foreach ($plan['items'] as $item) {
                    $parts = array_map('export_safe_directory_part', $item['directories'] ?? []);
                    $parts[] = basename(str_replace('\\', '/', (string)($item['filename'] ?? '')));
                    $key = mb_strtolower(implode('/', $parts), 'UTF-8');
                    if (isset($plannedPaths[$key])) throw new RuntimeException('Exportplan enthält doppelte Zielnamen. Backend aktualisieren.');
                    $plannedPaths[$key] = true;
                }
                $sourceFiles = export_source_files($db);
                $exported = 0;
                $errors = [];

                foreach ($plan['items'] as $item) {
                    $dokId = (int)($item['dok_id'] ?? 0);
                    $filename = basename(str_replace('\\', '/', trim((string)($item['filename'] ?? ''))));
                    if ($dokId < 1 || $filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
                        $errors[] = 'Ungültiger Exportplaneintrag.';
                        continue;
                    }

                    $sourceName = $sourceFiles[$dokId] ?? '';
                    if ($sourceName === '') {
                        $errors[] = 'DokID ' . $dokId . ': kein aktives Dokument in der Datenbank gefunden.';
                        continue;
                    }

                    $sourcePath = rtrim(PFAD_ARCHIV, '/\\') . DIRECTORY_SEPARATOR . $sourceName;
                    if (!is_file($sourcePath)) {
                        $errors[] = 'DokID ' . $dokId . ': Archivdatei nicht gefunden.';
                        continue;
                    }

                    $targetDir = rtrim($targetRoot, '/\\');
                    foreach (($item['directories'] ?? []) as $directory) {
                        $part = export_safe_directory_part($directory);
                        if ($part !== '') {
                            $targetDir .= DIRECTORY_SEPARATOR . $part;
                        }
                    }

                    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                        $errors[] = 'DokID ' . $dokId . ': Zielverzeichnis konnte nicht angelegt werden.';
                        continue;
                    }

                    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
                    if (!@copy($sourcePath, $targetPath)) {
                        $errors[] = 'DokID ' . $dokId . ': Datei konnte nicht kopiert werden.';
                        continue;
                    }
                    $exported++;
                }

                $exportResult = [
                    'planned' => count($plan['items']),
                    'exported' => $exported,
                    'errors' => $errors,
                ];

                if (count($errors) === 0) {
                    $meldung = 'Der Gesamtexport wurde erfolgreich abgeschlossen.';
                    $meldungTyp = 'success';
                } else {
                    $meldung = 'Der Gesamtexport wurde mit Hinweisen abgeschlossen.';
                    $meldungTyp = 'warning';
                }
            } catch (Throwable $e) {
                error_log('e_ablage Gesamtexport: ' . $e->getMessage());
                $meldung = 'Der Gesamtexport konnte nicht durchgeführt werden: ' . $e->getMessage();
                $meldungTyp = 'danger';
            }
        }
    } elseif ($action === 'restore') {
        try {
            if (!$restoreAvailable || !$backupConfigured || ($_POST['restore_confirm'] ?? '') !== 'yes') {
                throw new RuntimeException('Rücksicherung nur bei leerer Tabelle dokumente und ausdrücklicher Bestätigung möglich.');
            }
            $filename = $_POST['restore_file'] ?? null;
            if (!is_string($filename)) throw new RuntimeException('Keine Sicherungsdatei ausgewählt.');
            $path = eablage_restore_path($backupRoot, $filename);
            $restoreResult = eablage_restore_database($db, $path);
            $restoreResult['filename'] = $filename;
            $restoreAvailable = false;
            $meldung = 'Datenbank aus der Sicherung befüllt. Bitte jetzt die Archivprüfung ausführen.';
            $meldungTyp = 'success';
        } catch (Throwable $e) {
            error_log('e_ablage Rücksicherung: ' . $e->getMessage());
            $meldung = 'Rücksicherung fehlgeschlagen: ' . $e->getMessage() . ' Nach einem Importfehler die Installation prüfen und nicht einfach erneut starten.';
            $meldungTyp = 'danger';
        }
    } elseif ($action === 'backup') {
        if (!$backupConfigured) {
            $meldung = 'Für die Datensicherung ist noch kein Zielverzeichnis im Parameter pfadDatensicherung_linux konfiguriert.';
            $meldungTyp = 'danger';
        } else {
            try {
                $backupResult = eablage_create_database_backup($db, MYSQL_DATENBANK, $backupRoot);
                $meldung = 'Die Datenbanksicherung wurde erfolgreich erstellt.';
                $meldungTyp = 'success';
            } catch (Throwable $e) {
                error_log('e_ablage Datensicherung: ' . $e->getMessage());
                $meldung = 'Die Datenbanksicherung konnte nicht erstellt werden: ' . $e->getMessage();
                $meldungTyp = 'danger';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e_ablage - Export &amp; Datensicherung</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
</head>
<body>
<?php require_once 'navbar.php'; ?>
<div class="container">
    <?php if ($meldung !== ''): ?>
        <?=eablage_render_message($meldungTyp, $meldung)?>
    <?php endif; ?>

    <div class="panel panel-primary">
        <div class="panel-heading">📦 Dokumenten-Gesamtexport</div>
        <div class="panel-body">
            <p>Die Parameter für den Gesamtexport können in den Einstellungen geändert werden. Dort kann das Exportverzeichnis unter "Pfad" sowie die Zusammensetzung des Dateinamens unter "Gesamtexport"
                konfiguriert werden.</p>

            <dl>
                <dt>Zielverzeichnis</dt>
                <dd><code><?=h($targetRoot ?? '(nicht konfiguriert)')?></code></dd>
            </dl>

            <?php if (!$targetConfigured): ?>
                <?=eablage_render_message('warning', 'Bitte zunächst in den Einstellungen den Parameter 1_expVerzeichnis_linux auf ein beschreibbares NAS-Verzeichnis setzen.')?>
            <?php endif; ?>

            <form method="POST" action="gesamtexport.php" onsubmit="return confirm('Gesamtexport jetzt starten? Bereits vorhandene Exportdateien mit gleichem Namen werden überschrieben.');">
                <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                <input type="hidden" name="action" value="export">
                <button type="submit" class="btn btn-primary" <?=!$targetConfigured ? 'disabled' : ''?>>📦 Gesamtexport starten</button>
            </form>
        </div>
    </div>

    <?php if ($exportResult !== null): ?>
        <div class="panel panel-default">
            <div class="panel-heading">Ergebnis Gesamtexport</div>
            <div class="panel-body">
                <p><strong>Geplant:</strong> <?=h($exportResult['planned'])?> &nbsp; <strong>Exportiert:</strong> <?=h($exportResult['exported'])?> &nbsp; <strong>Fehler/Hinweise:</strong> <?=h(count($exportResult['errors']))?></p>
                <?php if (!empty($exportResult['errors'])): ?>
                    <details>
                        <summary>Fehler und Hinweise anzeigen</summary>
                        <ul style="margin-top:10px;">
                            <?php foreach ($exportResult['errors'] as $error): ?>
                                <li><?=h($error)?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel panel-primary">
        <div class="panel-heading">💾 Backup der Datenbank</div>
        <div class="panel-body">
            <p>Erstellt eine SQL-Sicherung der eAblage-Datenbank mit Tabellenstruktur und Daten. Die Sicherung wird im Zielverzeichnis gespeichert.</p>

            <dl>
                <dt>Datenbank</dt>
                <dd><code><?=h(defined('MYSQL_DATENBANK') ? MYSQL_DATENBANK : '(nicht ermittelbar)')?></code></dd>
                <dt>Zielverzeichnis</dt>
                <dd><code><?=h($backupRoot ?? '(nicht konfiguriert)')?></code></dd>
            </dl>

            <?php if (!$backupConfigured): ?>
                <?=eablage_render_message('warning', 'Bitte zunächst den Parameter pfadDatensicherung_linux anlegen bzw. in den Einstellungen auf ein beschreibbares NAS-Verzeichnis setzen.')?>
            <?php endif; ?>

            <form method="POST" action="gesamtexport.php" onsubmit="return confirm('Datenbanksicherung jetzt erstellen?');">
                <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="btn btn-primary" <?=!$backupConfigured ? 'disabled' : ''?>>💾 Datenbanksicherung erstellen</button>
            </form>
        </div>
    </div>

    <?php if ($backupResult !== null): ?>
        <div class="panel panel-default">
            <div class="panel-heading">Ergebnis Datensicherung</div>
            <div class="panel-body">
                <p><strong>Datei:</strong> <?=h($backupResult['filename'])?></p>
                <p><strong>Erstellt:</strong> <?=h($backupResult['created_at'])?></p>
                <p><strong>Größe:</strong> <?=h(format_bytes($backupResult['size']))?> &nbsp; <strong>Tabellen:</strong> <?=h($backupResult['tables'])?> &nbsp; <strong>Datensätze:</strong> <?=h($backupResult['rows'])?></p>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($restoreAvailable && $backupConfigured): ?>
        <div class="panel panel-primary" id="datenbank-wiederherstellen">
            <div class="panel-heading">Datenbank aus Backup initialisieren</div>
            <div class="panel-body">
                <p>Nur bei leerer Tabelle <code>dokumente</code>. Vorhandene Stammdaten, Parameter und Dokumenthistorie werden durch den gesicherten Stand ersetzt. Eine ältere Sicherung ohne Historie leert die vorhandene Protokolltabelle. Nur unterstützte eAblage-Sicherungen aus Version 4.1.0 oder 4.2.0 einschließlich Papierkorb werden angenommen. Andere Sicherungen benötigen eine gesonderte Migration.</p>
                <p>Die SQL-Sicherung enthält keine PDFs und Metadateien. Diese müssen zum gesicherten Stand passen und werden anschließend über die eigenständige Archivprüfung geprüft.</p>
                <?php if (empty($restoreFiles)): ?>
                    <p>Keine auswählbare Sicherung im konfigurierten Verzeichnis gefunden.</p>
                <?php else: ?>
                    <form method="POST" action="gesamtexport.php#datenbank-wiederherstellen" onsubmit="return confirm('Datenbank aus der ausgewählten Sicherung initialisieren? Die bestehenden Tabellen, Startdaten und die Dokumenthistorie werden ersetzt. Bei älteren Sicherungen ohne Historie wird das bisherige Protokoll geleert.');">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        <label for="restore_file">SQL-Sicherung</label>
                        <select id="restore_file" name="restore_file" required>
                            <?php foreach ($restoreFiles as $file): ?>
                                <option value="<?=h($file)?>"><?=h($file)?></option>
                            <?php endforeach; ?>
                        </select>
                        <p><label><input type="checkbox" name="restore_confirm" value="yes" required> Diese Datenbank jetzt mit der ausgewählten Sicherung befüllen.</label></p>
                        <button type="submit" class="btn btn-warning">Datenbank initialisieren</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($restoreResult !== null): ?>
        <div class="panel panel-default"><div class="panel-heading">Ergebnis Rücksicherung</div><div class="panel-body">
            <p><?=h($restoreResult['filename'])?>: <?=h($restoreResult['tables'])?> Tabellen, <?=h($restoreResult['documents'])?> Dokumente importiert.</p>
            <p><a href="gesamtexport.php#archivpruefung">Zur unabhängigen Archivprüfung</a>. Dort die Prüfung starten, nachdem die zugehörigen PDFs und Metadateien bereitgestellt wurden.</p>
        </div></div>
    <?php endif; ?>
    <?php require 'archiv_wartung_ansicht.php'; ?>
</div>
</body>
</html>
