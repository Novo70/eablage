<?php
require_once __DIR__ . '/archiv_pdf_funktionen.php';

function eablage_dokument_technik(array $document, string $returnUrl, ?string $pendingText = null): void
{
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $docId = (int)$document['DokID'];
    $formatBytes = static fn(string $text): string => number_format(strlen($text), 0, ',', '.') . ' Byte';
    $textStatus = $formatBytes((string)($document['Doktext'] ?? '')) . ' gespeichert';
    if ($pendingText !== null && $pendingText !== '') {
        $textStatus .= ' / ' . $formatBytes($pendingText) . ' neu eingelesen';
    }
    $fields = [
        'DokID' => $docId,
        'Archiviert' => $document['Archivierungsdatum'] ?? '',
        'Zuletzt geändert' => $document['Aenderungsdatum'] ?? '',
        'Originaldateiname' => $document['Originaldateiname'] ?? '',
        'PDF SHA-256' => $document['DateiSHA256'] ?? '',
        'Bisheriges Info-Feld (Altbestand)' => $document['Info'] ?? '',
        'Dokumenttext' => $textStatus,
        'Datei' => eablage_archiv_pdf_dateiname($docId),
    ];
    $historyUrl = 'protokoll.php?' . http_build_query(['dokid' => $docId, 'return' => $returnUrl]);
    ?>
    <details class="ea-detail-panel">
        <summary>Technische Informationen</summary>
        <div>
            <dl class="ea-document-metadata">
                <?php foreach ($fields as $label => $value): ?>
                    <dt><?=$escape($label)?></dt>
                    <dd><?=trim((string)$value) !== '' ? $escape($value) : '—'?></dd>
                <?php endforeach; ?>
            </dl>
            <p><a href="<?=$escape($historyUrl)?>"<?=$pendingText !== null ? ' data-draft-link' : ''?>>Dokumenthistorie öffnen</a></p>
        </div>
    </details>
    <?php
}
