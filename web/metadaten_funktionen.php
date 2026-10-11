<?php
// JSON formatting belongs to Python. PHP owns the save/edit transaction.
require_once __DIR__ . '/funktionen.php';

function eablage_metadaten_dokument(PDO $db, array $document): array
{
    // PDO may return integer DB columns as strings. Never coerce lookup IDs.
    foreach (['DokID', 'Aufbewahrungsdauer'] as $field) {
        if (!array_key_exists($field, $document)) throw new RuntimeException('Dokumentstand unvollständig: ' . $field);
        if ($document[$field] === null && $field === 'Aufbewahrungsdauer') continue;
        $value = filter_var($document[$field], FILTER_VALIDATE_INT);
        if ($value === false || is_bool($document[$field])) throw new RuntimeException('Ungültiger Datenbankwert: ' . $field);
        $document[$field] = $value;
    }
    foreach (['kategorie_id'=>['kategorie','katBez'], 'kategorie2_id'=>['kategorie','kat2Bez'], 'doktyp_id'=>['doktyp','doktypBez']] as $field=>$definition) {
        if (!array_key_exists($field, $document)) throw new RuntimeException('Dokumentstand unvollständig: ' . $field);
        $id = $document[$field] === null ? null : (string)$document[$field];
        $document[$field] = $id;
        [$table, $alias] = $definition;
        $label = $table === 'kategorie' ? 'katBez' : 'doktypBez';
        $document[$alias] = null;
        if ($id === null || $id === '') continue;
        $stmt = $db->prepare("SELECT $label FROM $table WHERE id = ?" . ($db->inTransaction() ? ' LOCK IN SHARE MODE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $document[$alias] = $row[$label];
    }
    return $document;
}

function eablage_metadaten_lesen(string $path): ?string
{
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Metadatenziel ist keine reguläre Datei.');
    if (!file_exists($path)) return null;
    $content = file_get_contents($path);
    if ($content === false) throw new RuntimeException('Vorhandene Metadatei ist nicht lesbar.');
    return $content;
}

function eablage_metadaten_temp(string $directory, string $content): string
{
    $path = tempnam($directory, '.eablage-meta-');
    if ($path === false) throw new RuntimeException('Metadatei konnte nicht vorbereitet werden.');
    try {
        if (realpath(dirname($path)) !== realpath($directory)) throw new RuntimeException('Metadatenverzeichnis nicht beschreibbar.');
        if (file_put_contents($path, $content) !== strlen($content) || !chmod($path, 0664)) {
            throw new RuntimeException('Metadatei konnte nicht vollständig geschrieben werden.');
        }
        return $path;
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
}

function eablage_metadaten_vorbereiten(array $document, int $timeout = 10): array
{
    $response = eablage_backend_request('POST', '/metadata/prepare', ['document'=>$document], $timeout);
    if (!$response['ok'] || $response['http_status'] !== 200) {
        throw new RuntimeException('Metadaten konnten nicht vorbereitet werden: ' . $response['error']);
    }
    $data = $response['data'];
    if (!is_array($data) || ($data['schema_version'] ?? null) !== 3 ||
        !is_string($data['content'] ?? null) || !is_string($data['filename'] ?? null) ||
        !is_int($document['DokID'] ?? null) || $document['DokID'] < 1 ||
        $data['filename'] !== $document['DokID'] . 'meta.json') {
        throw new RuntimeException('Ungültige Metadatenantwort des Backends.');
    }
    $metadata = json_decode($data['content'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($metadata) || ($metadata['schema_version'] ?? null) !== 3 ||
        ($metadata['document_id'] ?? null) !== $document['DokID']) {
        throw new RuntimeException('Metadatenantwort gehört nicht zum angeforderten Dokument oder Format.');
    }
    return $data;
}
