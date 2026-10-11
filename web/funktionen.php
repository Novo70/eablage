<?php
require_once __DIR__ . '/archiv_pdf_funktionen.php';
require_once __DIR__ . '/backend_client.php';

require_once 'stammdaten_funktionen.php';

/**
 * Gemeinsame Hilfsfunktionen für e_ablage:web.
 */

function eablage_lade_kategorien(PDO $db, bool $nurAktiv = false, array $behalten = []): array
{
    return eablage_stammdaten_liste($db, 'kategorie', $nurAktiv, $behalten);
}

function eablage_lade_dokumenttypen(PDO $db, bool $nurAktiv = false, array $behalten = []): array
{
    return eablage_stammdaten_liste($db, 'doktyp', $nurAktiv, $behalten);
}

/** Requests document text from the Python service; PHP never runs PDF tools. */
function eablage_pdf_text_einlesen(string $pdfPath, bool $ocrRequested = false): array
{
    $scope = defined('PFAD_ARCHIV') && realpath(dirname($pdfPath)) === realpath(PFAD_ARCHIV) ? 'archive' : 'inbox';
    $response = eablage_analysis_post('/documents/text', [
        'database' => MYSQL_DATENBANK, 'filename' => basename($pdfPath),
        'scope' => $scope, 'ocr_requested' => $ocrRequested,
    ], 110);
    return $response['ok'] ? $response['data'] : [
        'ok' => false, 'text' => '', 'mode' => '', 'used_mode' => '', 'error' => $response['error'],
    ];
}

/** Analysis tolerates invalid source-text bytes; archival metadata stays strict. */
function eablage_analysis_post(string $endpoint, array $payload, int $timeout = 5): array
{
    return eablage_backend_request('POST', $endpoint, $payload, $timeout, JSON_INVALID_UTF8_SUBSTITUTE);
}

function eablage_rule_ref_text($ref): string
{
    if (!is_array($ref)) return '';
    $regelId = trim((string)($ref['regel_id'] ?? ''));
    if ($regelId === '') return '';
    $version = $ref['version'] ?? null;
    $rank = $ref['rank'] ?? null;
    $text = $regelId;
    if ($version !== null && $version !== '') $text .= '/v' . $version;
    if ($rank !== null && $rank !== '') $text .= '/r' . $rank;
    return $text;
}

function eablage_rule_refs_text($refs): string
{
    if (!is_array($refs)) return '';
    $parts = [];
    foreach ($refs as $ref) {
        $text = eablage_rule_ref_text($ref);
        if ($text !== '') $parts[] = $text;
    }
    return implode(',', $parts);
}

/**
 * Führt die fachliche Dokumentklassifizierung aus.
 * AutoKat hat Vorrang; ohne Klassifikationsregel folgt der Textvergleich.
 */
function eablage_klassifizieren(string $dokumentText, ?int $excludeDokId = null): array
{
    $result = [
        'date' => '', 'partner' => '', 'subject' => '', 'category' => '0',
        'category2' => '0', 'document_type' => '0', 'number' => '', 'tags' => '',
        'info' => 'Manuell', 'autokat_sources' => [], 'additional_classification_rules' => [],
        'textcompare_sources' => [], 'autokat_ok' => false, 'autokat_error' => '',
        'autokat' => [], 'textcompare_ok' => false, 'textcompare_error' => '', 'textcompare' => [],
    ];
    if (trim($dokumentText) === '') return $result;
    $response = eablage_analysis_post('/analyze/classify', ['text' => $dokumentText, 'exclude_document_id' => $excludeDokId], 30);
    if ($response['ok']) return array_replace($result, $response['data']);
    $result['autokat_error'] = $response['error'];
    return $result;
}
