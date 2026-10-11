<?php
// Match the maximum PDF size accepted by the Python document processing service.
const EABLAGE_UPLOAD_MAX_BYTES = 50 * 1024 * 1024;

function eablage_upload_max_bytes(): int
{
    $phpLimit = ini_parse_quantity((string)ini_get('upload_max_filesize'));
    $limit = $phpLimit > 0 ? min(EABLAGE_UPLOAD_MAX_BYTES, $phpLimit) : EABLAGE_UPLOAD_MAX_BYTES;
    $postLimit = ini_parse_quantity((string)ini_get('post_max_size'));
    // Reserve multipart headers, filename and action token before accepting a PDF.
    return $postLimit > 0 ? min($limit, max(0, $postLimit - 64 * 1024)) : $limit;
}

function eablage_upload_limit_text(): string
{
    return rtrim(rtrim(number_format(eablage_upload_max_bytes() / (1024 * 1024), 2, ',', ''), '0'), ',') . ' MiB';
}

function eablage_upload_post_zu_gross(): bool
{
    $limit = ini_parse_quantity((string)ini_get('post_max_size'));
    return $limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
}

function eablage_upload_fehler(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei überschreitet die erlaubte Uploadgröße von ' . eablage_upload_limit_text() . '.',
        UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise übertragen. Bitte erneut hochladen.',
        UPLOAD_ERR_NO_FILE => 'Keine Datei ausgewählt. Bitte eine PDF-Datei auswählen.',
        UPLOAD_ERR_NO_TMP_DIR => 'Das temporäre Uploadverzeichnis fehlt. Web-Container prüfen.',
        UPLOAD_ERR_CANT_WRITE => 'Die hochgeladene Datei konnte nicht zwischengespeichert werden. Speicherplatz und Schreibrechte prüfen.',
        UPLOAD_ERR_EXTENSION => 'Der Upload wurde durch eine PHP-Erweiterung abgebrochen. Web-Container-Protokoll prüfen.',
        default => 'Die Datei konnte nicht hochgeladen werden. Bitte erneut versuchen.',
    };
}
