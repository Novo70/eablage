<?php
require_once __DIR__ . '/nummern_funktionen.php';
require_once __DIR__ . '/vorgaenge_funktionen.php';
require_once __DIR__ . '/metadaten_funktionen.php';
require_once __DIR__ . '/protokoll_funktionen.php';

function normalized_compare_value(string $field, $value): string
{
    if (in_array($field, ['kategorie_id', 'kategorie2_id', 'doktyp_id'], true)) {
        $value = trim((string)$value);
        return ($value === '' || $value === '0') ? '0' : $value;
    }
    return trim((string)$value);
}
function normalize_document_text($value): string
{
    return str_replace(["\r\n", "\r"], "\n", (string)$value);
}
function document_revision(array $document): string
{
    ksort($document);
    return hash('sha256', serialize(array_map(static fn($value) => $value === null ? null : (is_array($value) ? json_encode($value) : (string)$value), $document)));
}

function eablage_dokument_speichern(PDO $db, array $storedDocument, array $updated, string $documentRevision, string $reclassificationInfo = ""): bool
{
    $dokId = (int)$storedDocument['DokID'];
    $documentTextChanged = normalize_document_text($updated['Doktext'] ?? '') !== normalize_document_text($storedDocument['Doktext'] ?? '');
    $hasChanges = $documentTextChanged || $updated['numbers'] !== $storedDocument['numbers'];
    foreach (['Datum','kategorie_id','kategorie2_id','doktyp_id','Partner','Betreff','Nummer','Tags'] as $field) {
        $hasChanges = $hasChanges || normalized_compare_value($field, $updated[$field] ?? null) !== normalized_compare_value($field, $storedDocument[$field] ?? null);
    }
    $datum = $updated['Datum'];
    $kategorieId = $updated['kategorie_id'];
    $kategorie2Id = $updated['kategorie2_id'];
    $doktypId = $updated['doktyp_id'];
    $partner = $updated['Partner'];
    $betreff = $updated['Betreff'];
    $tags = $updated['Tags'];
    $metaPath = rtrim(PFAD_META, '/\\') . DIRECTORY_SEPARATOR . $dokId . 'meta.json';
    $tempMeta = null;
    $backupMeta = null;
    $metaPublished = false;
    try {
        $db->beginTransaction();
        $currentStmt = $db->prepare('SELECT * FROM ' . DB_TABELLE_ARCHIV . ' WHERE DokID = ? AND GeloeschtAm IS NULL FOR UPDATE');
        $currentStmt->execute([$dokId]);
        $currentAssignments = $currentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentAssignments) throw new RuntimeException('Dokument nicht mehr vorhanden.');
        $currentAssignments['numbers'] = eablage_nummern_laden($db,(int)$dokId);
        $currentAssignments['vorgaenge'] = eablage_vorgaenge_laden($db,(int)$dokId);
        if (!hash_equals(document_revision(array_intersect_key($currentAssignments, $storedDocument)), $documentRevision)) {
            throw new UnexpectedValueException('Das Dokument wurde zwischenzeitlich geändert. Bitte die eigenen Eingaben sichern und das Dokument neu öffnen.');
        }
        $updated['vorgaenge'] = eablage_vorgaenge_pruefen($db, json_encode($updated['vorgaenge'], JSON_THROW_ON_ERROR));
        eablage_vorgaenge_speichern($db, (int)$dokId, $updated['vorgaenge']);
        if (!$hasChanges) {
            $db->commit();
            return false;
        }
        $updated['numbers'] = eablage_nummern_pruefen($db,json_encode($updated['numbers'], JSON_THROW_ON_ERROR),$currentAssignments['numbers']);
        $nummer = ($updated['numbers'][0]['value'] ?? '');
        $updated['Nummer'] = $nummer;
        eablage_pruefe_zuordnungen($db, $updated, $currentAssignments);
        // Use the same database clock as the initial archive timestamp.
        $bearbeitungsdatum = $db->query('SELECT NOW()')->fetchColumn();
        if (!is_string($bearbeitungsdatum) || $bearbeitungsdatum === '') {
            throw new RuntimeException('Die Bearbeitungszeit konnte nicht ermittelt werden.');
        }
        $updated['Aenderungsdatum'] = $bearbeitungsdatum;
        $updated['Doktext'] = normalize_document_text($updated['Doktext']);
        // All metadata must describe the exact UPDATE, including document text.
        $metadata = eablage_metadaten_vorbereiten(eablage_metadaten_dokument($db, $updated));
        $oldMeta = eablage_metadaten_lesen($metaPath);
        if ($oldMeta !== null) {
            $oldJson = str_starts_with($oldMeta, "\xEF\xBB\xBF") ? substr($oldMeta, 3) : $oldMeta;
            $oldFormat = json_decode($oldJson, true);
            if (is_array($oldFormat) && isset($oldFormat['schema_version']) && !in_array($oldFormat['schema_version'], [1, 2, 3, 4], true)) {
                throw new RuntimeException('Unbekannte Metadatenversion; Datei wird nicht überschrieben.');
            }
            $backupMeta = eablage_metadaten_temp(dirname($metaPath), $oldMeta);
        }
        $tempMeta = eablage_metadaten_temp(dirname($metaPath), $metadata['content']);
        $updateStmt = $db->prepare('UPDATE ' . DB_TABELLE_ARCHIV . ' SET Datum=:datum, kategorie_id=:kategorie_id, kategorie2_id=:kategorie2_id, doktyp_id=:doktyp_id, Partner=:partner, Betreff=:betreff, Nummer=:nummer, Tags=:tags, Doktext=:doktext, Aenderungsdatum=:aenderungsdatum WHERE DokID=:dokid');
        $updateStmt->execute([':datum' => $datum, ':kategorie_id' => $kategorieId, ':kategorie2_id' => $kategorie2Id, ':doktyp_id' => $doktypId, ':partner' => $partner, ':betreff' => $betreff, ':nummer' => $nummer, ':tags' => $tags, ':doktext' => normalize_document_text($updated['Doktext']), ':aenderungsdatum' => $bearbeitungsdatum, ':dokid' => $dokId]);
        eablage_nummern_speichern($db,(int)$dokId,$updated['numbers']);
        if (eablage_metadaten_lesen($metaPath) !== $oldMeta) throw new RuntimeException('Metadatei wurde zwischenzeitlich geändert.');
        if ($oldMeta === null) {
            // Atomic exclusive publication, never overwrite a newly appeared file.
            if (!link($tempMeta, $metaPath)) throw new RuntimeException('Metadatenziel existiert bereits oder ist nicht beschreibbar.');
            $metaPublished = true;
            @unlink($tempMeta);
        } else {
            if (!rename($tempMeta, $metaPath)) throw new RuntimeException('Die Metadatei konnte nicht aktualisiert werden.');
            $metaPublished = true;
        }
        $tempMeta = null;
        $details = ['felder' => eablage_protokoll_aenderungen($currentAssignments, $updated),
            'dokumenttext_geaendert' => $documentTextChanged];
        if ($currentAssignments['numbers'] !== $updated['numbers']) {
            $details['nummern_vorher']=$currentAssignments['numbers']; $details['nummern_nachher']=$updated['numbers'];
        }
        if ($reclassificationInfo !== '') {
            $details['klassifizierung'] = eablage_protokoll_klassifizierung($reclassificationInfo);
        }
        eablage_protokoll($db, (int)$dokId, 'metadaten_geaendert', $details, $bearbeitungsdatum);
        $db->commit();
        $metaPublished = false;
        if ($backupMeta !== null) @unlink($backupMeta);
        return true;
    } catch (Throwable $e) {
        // Restore the previous JSON while still holding the row lock if possible.
        if ($metaPublished) {
            $restored = $backupMeta !== null ? @rename($backupMeta, $metaPath) : @unlink($metaPath);
            if (!$restored) error_log('e_ablage: JSON-Rollback fehlgeschlagen; Sicherung: ' . ($backupMeta ?? 'keine') . '; Archivprüfung erforderlich.');
            if ($restored) $backupMeta = null;
        } elseif ($backupMeta !== null) {
            @unlink($backupMeta);
        }
        if ($db->inTransaction()) $db->rollBack();
        if ($tempMeta !== null && file_exists($tempMeta)) @unlink($tempMeta);
        error_log('e_ablage Dokument bearbeiten/speichern: ' . $e->getMessage());
        throw $e;
    }
}
