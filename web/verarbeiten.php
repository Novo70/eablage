<?php

require_once 'konfiguration.php';
require_once 'funktionen.php';
require_once 'meldungen.php';
require_once 'csrf.php';

setlocale(LC_CTYPE, 'de_DE.utf8');
$aktionToken = eablage_aktion_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST') eablage_aktion_pruefen($_POST['aktion_token'] ?? null);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function eablage_source_badge($source): string
{
    if (!is_array($source)) {
        return '';
    }

    $type = strtoupper(trim((string)($source['type'] ?? '')));

    if (!in_array($type, ['E', 'K', 'D'], true)) {
        return '';
    }

    $rank = $source['rank'] ?? null;
    $regelId = trim((string)($source['regel_id'] ?? ''));
    $version = $source['version'] ?? null;
    $label = trim((string)($source['label'] ?? ''));
    $method = trim((string)($source['method'] ?? ''));

    if ($type === 'E') {
        $text = $regelId !== '' ? $regelId : ($rank !== null && $rank !== '' ? (string)$rank : 'E');
        $title = 'Ergänzungsregel';
        $class = 'ea-source-badge ea-source-badge--supplement';
    } elseif ($type === 'K') {
        $text = $regelId !== '' ? $regelId : ($rank !== null && $rank !== '' ? (string)$rank : 'K');
        $title = 'Klassifikationsregel';
        $class = 'ea-source-badge ea-source-badge--classification';
    } else {
        $text = 'D';
        $title = 'Allgemeine Datumserkennung';
        $class = 'ea-source-badge ea-source-badge--date';
    }

    if ($regelId !== '') {
        $title .= ' ' . $regelId . ($version !== null && $version !== '' ? '/v' . $version : '');
    }
    if ($rank !== null && $rank !== '') {
        $title .= ' (Rang ' . $rank . ')';
    }
    if ($label !== '') {
        $title .= ' – ' . $label;
    }
    if ($method === 'extract') {
        $title .= ' (aus Dokumenttext extrahiert)';
    } elseif ($method === 'date_anchor') {
        $title .= ' (Datum über Regelanker erkannt)';
    }

    $content = h($text);
    $titleAttr = h($title);
    $classAttr = h($class);

    if (($type === 'E' || $type === 'K') && $regelId !== '') {
        $returnUrl = 'verarbeiten.php?file=' . rawurlencode(basename((string)($_GET['file'] ?? '')));
        return '<a data-draft-link class="' . $classAttr . '" href="autokat_regel_bearbeiten.php?regel=' . rawurlencode($regelId) . '&amp;return=' . rawurlencode($returnUrl) . '" title="' . $titleAttr . '">' . $content . '</a>';
    }

    return '<span class="' . $classAttr . '" title="' . $titleAttr . '">' . $content . '</span>';
}

function eablage_textcompare_badge($textcompare, bool $show = true): string
{
    if (!$show || !is_array($textcompare) || empty($textcompare['matched'])) {
        return '';
    }

    $dokId = trim((string)($textcompare['source_document_id'] ?? ''));
    if ($dokId === '' || !ctype_digit($dokId)) {
        return '';
    }

    $similarity = trim((string)($textcompare['similarity'] ?? ''));
    $title = 'Textvergleich mit DokID ' . $dokId;
    if ($similarity !== '') {
        $title .= ' (' . $similarity . ' % Übereinstimmung)';
    }
    $title .= ' – Vergleichsdokument öffnen';

    $returnUrl = 'verarbeiten.php?file=' . rawurlencode(basename((string)($_GET['file'] ?? '')));
    return '<a data-draft-link class="ea-source-badge ea-source-badge--date" style="cursor:pointer;" href="bearbeiten.php?dokid=' . rawurlencode($dokId) . '&amp;return=' . rawurlencode($returnUrl) . '" title="' . h($title) . '">DokID ' . h($dokId) . '</a>';
}

function eablage_rule_ref_badges($refs): string
{
    if (!is_array($refs)) {
        return '';
    }

    $badges = [];
    foreach ($refs as $ref) {
        $badge = eablage_source_badge($ref);
        if ($badge !== '') {
            $badges[] = $badge;
        }
    }

    return implode(' ', $badges);
}

function eablage_backend_diagnose_hinweis(string $error): string
{
    if ($error === '') {
        return '';
    }

    if (
        str_contains($error, 'nicht erreichbar') ||
        str_contains($error, 'nicht konfiguriert')
    ) {
        return 'Bitte prüfen Sie die Adresse des eablage-backends in den Einstellungen und ob der Backend-Dienst gestartet ist.';
    }

    if (str_contains($error, 'HTTP-Fehler')) {
        return 'Das eablage-backend ist erreichbar, hat die Anfrage aber mit einem Fehler beantwortet. Bitte prüfen Sie den Backend-Dienst und dessen Protokoll.';
    }

    if (str_contains($error, 'JSON-Antwort')) {
        return 'Das eablage-backend ist erreichbar, hat aber keine gültige Antwort geliefert. Bitte prüfen Sie den Backend-Dienst und dessen Protokoll.';
    }

    return 'Bitte prüfen Sie die Konfiguration und den Status des eablage-backends.';
}

$dateiRaw = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
$datei = basename($dateiRaw);
$scanOrdner = PFAD_INBOX;

if (
    $dateiRaw === '' ||
    $dateiRaw !== $datei ||
    !file_exists($scanOrdner . $datei) ||
    strtolower(pathinfo($datei, PATHINFO_EXTENSION)) !== 'pdf'
) {
    die('Ungültige Datei oder keine Datei ausgewählt.');
}

$vollstaendigerPfad = $scanOrdner . $datei;
@set_time_limit(120);
$ocrRequested = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ocr_requested'] ?? '') === '1';
$jobResponse = eablage_analysis_post('/inbox/process', [
    'database' => MYSQL_DATENBANK, 'filename' => $datei,
    'retry' => $ocrRequested || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry'])),
    'ocr_requested' => $ocrRequested,
], 110);
$job = $jobResponse['ok'] ? $jobResponse['data'] : null;
if ($job && in_array($job['status'], ['wartend', 'verarbeitung', 'archiviert'], true)) {
    $busy = $job['status'] !== 'archiviert';
    $busyMessage = $ocrRequested
        ? 'Die OCR konnte noch nicht gestartet werden, weil das Dokument gerade verarbeitet oder übertragen wird. Bitte nach Abschluss OCR erneut starten.'
        : (($job['error'] ?? '') ?: 'Das Dokument wird gerade verarbeitet. Das Ergebnis wird anschließend automatisch geöffnet.');
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($busy && !$ocrRequested): ?><meta http-equiv="refresh" content="3;url=verarbeiten.php?file=<?=rawurlencode($datei)?>"><?php endif; ?>
    <title>Dokumentverarbeitung</title><link href="app.css" rel="stylesheet"></head><body>
    <?php include 'navbar.php'; ?><div class="container">
    <?=eablage_render_message('info', $busy ? $busyMessage : 'Dieses Dokument wurde bereits archiviert. Die verbliebene Eingangskopie kann verworfen werden.')?>
    <a href="eingang.php" class="btn btn-default">Zurück zum Eingang</a>
    <?php if ($busy && $ocrRequested): ?>
    <form method="POST" action="verarbeiten.php?file=<?=rawurlencode($datei)?>">
        <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
        <button class="btn btn-primary" type="submit" name="ocr_requested" value="1">OCR erneut starten</button>
    </form>
    <?php endif; ?></div></body></html>
    <?php exit;
}
$pdfTextResult = $job['result']['pdf'] ?? ['ok'=>false, 'text'=>'', 'error'=>$job['error'] ?? $jobResponse['error']];
$dokumentText = $pdfTextResult['ok'] ? (string)$pdfTextResult['text'] : '';
$klassifizierung = $job['result']['classification'] ?? eablage_klassifizieren('');
$sourceSHA256 = @hash_file('sha256', $vollstaendigerPfad);
if ($sourceSHA256 === false) die('Die Eingangsdatei konnte nicht gelesen werden. Bitte Leserechte und Datenträger prüfen und anschließend den Eingang neu öffnen.');
if ($job && $job['sha256'] !== '' && !hash_equals((string)$job['sha256'], $sourceSHA256)) {
    die('Die Eingangsdatei wurde geändert. Bitte erneut vom Eingang aus klassifizieren.');
}
$kategorien = eablage_lade_kategorien($db_config, true);
$dokumenttypen = eablage_lade_dokumenttypen($db_config, true);

$erkanntesDatum = $klassifizierung['date'];
$erkannterPartner = $klassifizierung['partner'];
$erkannterBetreff = $klassifizierung['subject'];
$erkannteKategorie = $klassifizierung['category'];
$erkannteKategorie2 = $klassifizierung['category2'];
$erkanntesDoktyp = $klassifizierung['document_type'];
$erkannteNummer = $klassifizierung['number'];
$erkannteTags = $klassifizierung['tags'];
$kategorien2 = $kategorien;
$ungueltigeVorschlaege = [];
if (eablage_stammdaten_vorschlag($db_config, 'kategorie', $kategorien, $erkannteKategorie)) $ungueltigeVorschlaege[] = 'Kategorie';
if (eablage_stammdaten_vorschlag($db_config, 'kategorie', $kategorien2, $erkannteKategorie2)) $ungueltigeVorschlaege[] = 'Kategorie 2';
if (eablage_stammdaten_vorschlag($db_config, 'doktyp', $dokumenttypen, $erkanntesDoktyp)) $ungueltigeVorschlaege[] = 'Dokumententyp';
$erkennungsInfo = $klassifizierung['info'];
$autokatQuellen = $klassifizierung['autokat_sources'];
$weitereKlassifikationsregeln = $klassifizierung['additional_classification_rules'];
$textvergleichQuellen = $klassifizierung['textcompare_sources'];
$pythonAutoKatErfolgreich = $klassifizierung['autokat_ok'];
$pythonAutoKatFehler = $klassifizierung['autokat_error'];
$pythonAutoKat = $klassifizierung['autokat'];
$regelVorbereitungMoeglich = $pythonAutoKatErfolgreich && empty($pythonAutoKat['matched_classification_rule_ref']);
$pythonTextvergleichErfolgreich = $klassifizierung['textcompare_ok'];
$pythonTextvergleichFehler = $klassifizierung['textcompare_error'];
$pythonTextvergleich = $klassifizierung['textcompare'];
$backendFehler = $pythonAutoKatFehler !== '' || $pythonTextvergleichFehler !== '';
$erkennungsDetailsOffen = empty($dokumentText) || $backendFehler;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage - Dokument verarbeiten</title>
    <link href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>" rel="stylesheet">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
    <link rel="icon" href="ea_icon4.png" type="image/png">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
    <?php if ($ungueltigeVorschlaege): ?><?=eablage_render_message('warning', 'Diese Vorschläge sind nicht mehr verfügbar: ' . implode(', ', $ungueltigeVorschlaege) . '. Bitte die markierten Zuordnungen vor dem Speichern neu auswählen oder ausdrücklich leeren.')?><?php endif; ?>
    <?php if (!$jobResponse['ok']): ?><?=eablage_render_message('warning', $jobResponse['error'] . ' Die Felder können weiterhin manuell ausgefüllt werden.')?><?php endif; ?>
    <?php if ($job): ?>
    <?php if ($job['error'] !== ''): ?><p class="text-warning"><?=h($job['error'])?></p><?php endif; ?>
    <?php endif; ?>
    <?php if (($pdfTextResult['used_mode'] ?? '') === 'ocr'): ?><?=eablage_render_message('info', 'Der Dokumenttext wurde lokal per OCR erkannt. Bitte Erkennungswerte prüfen; die Original-PDF bleibt unverändert.')?><?php endif; ?>
    <?php if (!$pdfTextResult['ok']): ?>
        <?=eablage_render_message('danger', 'Der Dokumenttext konnte nicht eingelesen werden. Die automatische Klassifizierung steht für dieses Dokument nicht zur Verfügung.')?>
    <?php elseif ($backendFehler): ?>
        <?=eablage_render_message('danger', 'Die automatische Klassifizierung ist nicht verfügbar. Das Dokument kann weiterhin manuell klassifiziert werden.')?>
    <?php endif; ?>

    <?php if (!empty($weitereKlassifikationsregeln)): ?>
        <div class="alert alert-warning" role="alert">
            ⚠️ <strong>Weitere passende Klassifikationsregeln:</strong>
            <?=eablage_rule_ref_badges($weitereKlassifikationsregeln)?>
            <div class="small" style="margin-top:5px;">
                Die Regel mit dem niedrigsten Rang wurde für die Vorbelegung verwendet.
                Weitere Treffer können durch Anklicken geprüft, geändert oder deaktiviert werden.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pythonAutoKat['mixed_rule_sources'])): ?>
        <?=eablage_render_message('warning', 'Klassifikation erkannt – einzelne Werte stammen noch aus Ergänzungsregeln. Bitte diese Felder besonders prüfen.')?>
    <?php endif; ?>

    <div class="row ea-classification-row">
        <div class="col-md-6 ea-classification-column">
            <div class="panel panel-primary ea-classification-panel">
                <div class="panel-heading ea-classification-heading">
                    <span>✍️ Dokument klassifizieren</span>
                    <?php if ($job || $regelVorbereitungMoeglich): ?>
                        <details class="ea-more-actions" id="classification-more-actions">
                            <summary aria-label="Weitere Aktionen" title="Weitere Aktionen">⋮</summary>
                            <div class="ea-more-actions-list">
                                <?php if ($job): ?>
                                    <button type="submit" form="retry-inbox-file-form" name="retry" value="1">🔄 Erkennung erneut starten</button>
                                    <button type="submit" form="ocr-inbox-file-form" name="ocr_requested" value="1">🔎 OCR-Text einlesen</button>
                                <?php endif; ?>
                                <?php if ($regelVorbereitungMoeglich): ?>
                                    <button type="submit" form="classification-document-form" name="after_save" value="prepare_rule">🧩 Speichern und Regel vorbereiten</button>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <form action="speichern.php" method="POST" id="classification-document-form" data-classification-draft>
                        <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        <input type="hidden" name="inbox_job_id" value="<?=h($job['id'] ?? '')?>">
                        <input type="hidden" name="source_sha256" value="<?=h($sourceSHA256)?>">
                        <input type="hidden" name="original_datei" value="<?=h($datei)?>">
                        <input type="hidden" name="dokument_text" value="<?=h($dokumentText)?>">
                        <input type="hidden" name="erkennungs_info" value="<?=h($erkennungsInfo)?>">

                        <div class="form-group">
                            <label for="Datum">Dokumenten-Datum <?=eablage_source_badge($autokatQuellen['date'] ?? null)?></label>
                            <input type="date" id="Datum" name="Datum" class="form-control" value="<?=h($erkanntesDatum)?>" required>
                        </div>

                        <div class="form-group">
                            <label for="Partner">Partner / Absender <?=eablage_source_badge($autokatQuellen['partner'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['partner']))?></label>
                            <input type="text" id="Partner" name="Partner" class="form-control" value="<?=h($erkannterPartner)?>" placeholder="">
                        </div>

                        <div class="form-group">
                            <label for="Betreff">Betreff <?=eablage_source_badge($autokatQuellen['subject'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['subject']))?></label>
                            <input type="text" id="Betreff" name="Betreff" class="form-control" value="<?=h($erkannterBetreff)?>" placeholder="">
                        </div>

                        <div class="form-group">
                            <label for="Doktyp">Dokumententyp <?=eablage_source_badge($autokatQuellen['document_type'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['document_type']))?></label>
                            <select class="form-control" id="Doktyp" name="Doktyp">
                                <?php foreach ($dokumenttypen as $id => $bez): ?>
                                    <option value="<?=h($id)?>" <?=(string)$id === $erkanntesDoktyp ? 'selected' : ''?>><?=h($bez)?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="Kategorie">Kategorie <?=eablage_source_badge($autokatQuellen['category'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['category']))?></label>
                                    <select class="form-control" id="Kategorie" name="Kategorie">
                                        <?php foreach ($kategorien as $id => $bez): ?>
                                            <option value="<?=h($id)?>" <?=(string)$id === $erkannteKategorie ? 'selected' : ''?>><?=h($bez)?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="Kategorie2">Kategorie 2 <?=eablage_source_badge($autokatQuellen['category2'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['category2']))?></label>
                                    <select class="form-control" id="Kategorie2" name="Kategorie2">
                                        <?php foreach ($kategorien2 as $id => $bez): ?>
                                            <option value="<?=h($id)?>" <?=(string)$id === $erkannteKategorie2 ? 'selected' : ''?>><?=h($bez)?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="Nummer">Dokumenten-Nummer (optional) <?=eablage_source_badge($autokatQuellen['number'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['number']))?></label>
                            <input type="text" id="Nummer" name="Nummer" class="form-control" value="<?=h($erkannteNummer)?>" placeholder="">
                        </div>

                        <div class="form-group">
                            <label for="Tags">Stichworte / Tags (optional) <?=eablage_source_badge($autokatQuellen['tags'] ?? null)?><?=eablage_textcompare_badge($pythonTextvergleich, !empty($textvergleichQuellen['tags']))?></label>
                            <input type="text" id="Tags" name="Tags" class="form-control" value="<?=h($erkannteTags)?>" placeholder="">
                        </div>

                        <div class="ea-form-actions ea-classification-actions ea-document-actions">
                            <a href="eingang.php" class="btn btn-default" data-draft-cancel>Abbrechen</a>
                            <button type="submit" form="discard-inbox-file-form" class="btn btn-danger">🗑️ Verwerfen</button>
                            <button type="submit" class="btn btn-primary">💾 Speichern</button>
                        </div>
                    </form>
                    <?php if ($job): ?>
                        <form id="retry-inbox-file-form" method="POST" action="verarbeiten.php?file=<?=rawurlencode($datei)?>" onsubmit="return confirm('Erkennung neu starten? Nicht gespeicherte Eingaben werden durch neue Vorschläge ersetzt.');">
                            <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        </form>
                        <form id="ocr-inbox-file-form" method="POST" action="verarbeiten.php?file=<?=rawurlencode($datei)?>" onsubmit="return confirm('Alle Seiten mit OCR neu erkennen und neu klassifizieren? Nicht gespeicherte Eingaben werden durch neue Vorschläge ersetzt. Die Original-PDF bleibt unverändert.');">
                            <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                        </form>
                    <?php endif; ?>
                        <form id="discard-inbox-file-form" action="eingang.php?action=discard" method="POST" onsubmit="return confirm('Diese Eingangsdatei endgültig löschen? Sie wird nicht in den Papierkorb verschoben.');">
                            <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                            <input type="hidden" name="file" value="<?=h($datei)?>">
                            <input type="hidden" name="sha256" value="<?=h(hash_file('sha256', $vollstaendigerPfad))?>">
                        </form>
                </div>
            </div>
        </div>

        <div class="col-md-6 ea-classification-column">
            <div class="panel panel-primary ea-pdf-panel">
                <div class="panel-heading" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                    <span>📄 Dokument: <?=h($datei)?></span>
                    <div class="btn-group btn-group-xs" role="group" aria-label="Dokumentansicht">
                        <button type="button" class="btn btn-default active" id="viewPdfButton" onclick="eablageSetDocumentView('pdf')">PDF</button>
                        <button type="button" class="btn btn-default" id="viewTextButton" onclick="eablageSetDocumentView('text')">Text</button>
                    </div>
                </div>

                <div class="panel-body ea-pdf-body" id="pdfDocumentView">
                    <div class="ea-mobile-pdf-overlay">
                        <a href="pdf.php?file=<?=rawurlencode($datei)?>" target="_blank" rel="noopener" class="btn btn-primary">PDF im Vollbild öffnen</a>
                    </div>
                    <iframe src="pdf.php?file=<?=rawurlencode($datei)?>" style="border:none;"></iframe>
                </div>

                <div class="panel-body" id="textDocumentView" style="display:none;height:100%;overflow:auto;padding:0;">
                    <pre style="margin:0;padding:15px;border:0;border-radius:0;background:#f7f7f7;white-space:pre-wrap;word-wrap:break-word;min-height:100%;"><?=h($dokumentText)?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container" style="margin-top:20px;margin-bottom:20px;">
    <details <?=$erkennungsDetailsOffen ? 'open' : ''?> style="border:1px solid #ddd;border-radius:4px;background:#fff;">
        <summary style="cursor:pointer;padding:12px 15px;font-weight:600;background:#f7f7f7;">🔎 Erkennungsdetails</summary>

        <div style="padding:15px;">
            <?php if (!$pdfTextResult['ok']): ?>
                <div class="alert alert-warning">
                    ⚠️ PDF-Text konnte nicht eingelesen werden: <?=h($pdfTextResult['error'])?>
                </div>
            <?php endif; ?>

            <?php if ($backendFehler): ?>
                <div class="alert alert-warning">
                    <strong>Backend-Diagnose</strong><br>
                    <?php if ($pythonAutoKatFehler !== ''): ?>
                        AutoKat: <?=h($pythonAutoKatFehler)?><br>
                    <?php endif; ?>
                    <?php if ($pythonTextvergleichFehler !== ''): ?>
                        Textvergleich: <?=h($pythonTextvergleichFehler)?><br>
                    <?php endif; ?>
                    <?=h(eablage_backend_diagnose_hinweis($pythonAutoKatFehler !== '' ? $pythonAutoKatFehler : $pythonTextvergleichFehler))?>
                </div>
            <?php elseif ($pythonAutoKatErfolgreich && !empty($pythonAutoKat['matched'])): ?>
                <div class="alert alert-success">
                    ✅ AutoKat-Regel erkannt.
                    <p style="margin-bottom:0;">
                        <?php
                        $eDetail = eablage_rule_refs_text($pythonAutoKat['matched_supplement_rule_refs'] ?? []);
                        $kDetail = eablage_rule_ref_text($pythonAutoKat['matched_classification_rule_ref'] ?? null);
                        ?>

                        <?php if ($eDetail !== ''): ?>
                            <strong>Ergänzungsregeln:</strong> <?=h($eDetail)?> |
                        <?php elseif (!empty($pythonAutoKat['matched_supplement_rules'])): ?>
                            <strong>Ergänzungsregeln:</strong> <?=h(implode(', ', $pythonAutoKat['matched_supplement_rules']))?> |
                        <?php endif; ?>

                        <?php if ($kDetail !== ''): ?>
                            <strong>Primäre Klassifikationsregel:</strong> <?=h($kDetail)?>
                        <?php elseif (($pythonAutoKat['matched_classification_rule'] ?? null) !== null): ?>
                            <strong>Primäre Klassifikationsregel:</strong> <?=h($pythonAutoKat['matched_classification_rule'])?>
                        <?php else: ?>
                            <strong>Keine Klassifikationsregel</strong>
                        <?php endif; ?>

                        <?php if (!empty($weitereKlassifikationsregeln)): ?>
                            <br>
                            <strong>Weitere Klassifikationsregeln:</strong> <?=h(eablage_rule_refs_text($weitereKlassifikationsregeln))?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php elseif ($pythonAutoKatErfolgreich): ?>
                <div class="alert alert-info">ℹ️ Keine passende AutoKat-Regel gefunden.</div>
            <?php endif; ?>

            <?php if (!$backendFehler): ?>
                <?php if ($pythonTextvergleichErfolgreich && !empty($pythonTextvergleich['matched'])): ?>
                    <div class="alert alert-success">
                        ✅ Ähnliches Dokument gefunden:
                        DokID <?=h($pythonTextvergleich['source_document_id'] ?? '')?> |
                        <strong><?=h($pythonTextvergleich['similarity'] ?? 0)?> %</strong> Übereinstimmung |
                        <?=h($pythonTextvergleich['matching_words'] ?? 0)?> gemeinsame relevante Wörter
                    </div>
                <?php elseif ($pythonTextvergleichErfolgreich): ?>
                    <div class="alert alert-info">ℹ️ Kein ausreichend ähnliches Vergleichsdokument gefunden.</div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($dokumentText)): ?>
                <div class="alert alert-warning">⚠️ Es konnte kein Dokumenttext extrahiert werden.</div>
            <?php endif; ?>
        </div>
    </details>
</div>

<script>
function eablageSetDocumentView(view)
{
    var pdf = document.getElementById('pdfDocumentView'),
        text = document.getElementById('textDocumentView'),
        pdfButton = document.getElementById('viewPdfButton'),
        textButton = document.getElementById('viewTextButton');

    var showText = view === 'text';

    pdf.style.display = showText ? 'none' : '';
    text.style.display = showText ? '' : 'none';
    pdfButton.classList.toggle('active', !showText);
    textButton.classList.toggle('active', showText);
}

var classificationMoreActions = document.getElementById('classification-more-actions');
if (classificationMoreActions) {
    document.addEventListener('click', function(event) {
        if (!classificationMoreActions.contains(event.target)) classificationMoreActions.open = false;
    });
    classificationMoreActions.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            classificationMoreActions.open = false;
            classificationMoreActions.querySelector('summary').focus();
        }
    });
}
</script>
<script src="classification_draft.js?v=<?=filemtime(__DIR__ . '/classification_draft.js')?>" defer></script>
</body>
</html>
