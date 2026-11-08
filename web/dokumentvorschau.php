<?php
require_once __DIR__ . '/icons.php';
function eablage_dokumentvorschau_script(): void
{
    static $included = false;
    if ($included) return;
    $included = true;
    ?>
    <script src="dokumentvorschau.js?v=<?=filemtime(__DIR__ . '/dokumentvorschau.js')?>" defer></script>
    <?php
}
/** One preview component for static documents and selectable document lists. */
function eablage_dokumentvorschau(string $pdfUrl = '', string $documentText = '', array $options = []): void
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pdfId = $options['pdf_id'] ?? 'pdfDocumentView';
    $openId = $options['open_id'] ?? 'openPdfButton';
    $emptyId = $options['empty_id'] ?? 'documentPreviewEmpty';
    $headingId = $options['heading_id'] ?? 'documentPreviewHeading';
    $selected = $pdfUrl !== '';
    // A new iframe URL also invalidates previously cached viewer HTML.
    $assets = ['pdfjs.js', 'pdfjs.css', 'pdfjs_loader.mjs', 'icons.svg', 'vendor/pdfjs/web/viewer.html'];
    $assetHashes = array_map(static fn(string $file): string => hash_file('sha256', __DIR__ . '/' . $file), $assets);
    $viewerVersion = substr(hash('sha256', implode('', $assetHashes)), 0, 16);
    ?>
    <section class="panel panel-primary ea-pdf-panel <?=$escape($options['class'] ?? '')?>" data-document-preview data-preview-version="<?=$escape($viewerVersion)?>" data-preview-url="<?=$escape($pdfUrl)?>" aria-labelledby="<?=$escape($headingId)?>">
        <div class="panel-heading">
            <span id="<?=$escape($headingId)?>"><?=eablage_icon('document')?>Dokumentvorschau</span>
            <div class="btn-group btn-group-xs" role="group" aria-label="Dokumentansicht">
                <button type="button" class="btn btn-default ea-heading-btn ea-preview-split-toggle" data-preview-split-toggle aria-label="Vorschau verbreitern" title="Vorschau verbreitern" aria-pressed="false" hidden><?=eablage_icon('expand-horizontal')?></button>
                <a class="btn btn-default" id="<?=$escape($openId)?>" data-preview-open <?=$selected ? 'href="' . $escape($pdfUrl) . '"' : 'hidden'?> target="_blank" rel="noopener" title="Original-PDF im Browser öffnen">PDF öffnen</a>
                <button type="button" class="btn btn-default active" id="viewPdfButton" data-preview-view="pdf" aria-pressed="true" <?=$selected ? '' : 'disabled'?>>PDF</button>
                <button type="button" class="btn btn-default" id="viewTextButton" data-preview-view="text" aria-pressed="false" <?=$selected ? '' : 'disabled'?>>Text</button>
            </div>
        </div>
        <div class="panel-body ea-preview-empty" id="<?=$escape($emptyId)?>" data-preview-empty role="status" <?=$selected ? 'hidden' : ''?>>Bitte ein Dokument auswählen.</div>
        <div class="panel-body ea-pdf-body" id="<?=$escape($pdfId)?>" data-preview-pdf <?=$selected ? '' : 'hidden'?>></div>
        <div class="panel-body ea-preview-text" id="textDocumentView" data-preview-text hidden>
            <pre data-preview-text-content><?=$escape($documentText)?></pre>
        </div>
    </section>

    <?php
    eablage_dokumentvorschau_script();
}
