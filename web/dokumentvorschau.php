<?php
require_once __DIR__ . '/icons.php';
/** Shared PDF/text preview for viewing, classification and editing. */
function eablage_dokumentvorschau(string $pdfUrl, string $documentText): void
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
    <div class="panel panel-primary ea-pdf-panel">
        <div class="panel-heading">
            <span><?=eablage_icon('document')?>PDF-Vorschau</span>
            <div class="btn-group btn-group-xs" role="group" aria-label="Dokumentansicht">
                <button type="button" class="btn btn-default active" id="viewPdfButton" aria-pressed="true" onclick="eablageSetDocumentView('pdf')">PDF</button>
                <button type="button" class="btn btn-default" id="viewTextButton" aria-pressed="false" onclick="eablageSetDocumentView('text')">Text</button>
            </div>
        </div>
        <div class="panel-body ea-pdf-body" id="pdfDocumentView">
            <div class="ea-mobile-pdf-overlay"><a href="<?=$escape($pdfUrl)?>" target="_blank" rel="noopener" class="btn btn-primary">PDF im Vollbild öffnen</a></div>
            <iframe src="<?=$escape($pdfUrl)?>" title="PDF-Vorschau"></iframe>
        </div>
        <div class="panel-body" id="textDocumentView" hidden>
            <pre><?=$escape($documentText)?></pre>
        </div>
    </div>
    <script src="dokumentvorschau.js?v=<?=filemtime(__DIR__ . '/dokumentvorschau.js')?>"></script>
    <?php
}
