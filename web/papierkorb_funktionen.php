<?php
require_once __DIR__ . '/archiv_pdf_funktionen.php';
require_once __DIR__ . '/protokoll_funktionen.php';

function eablage_papierkorb_aendern(PDO $db, int $dokId, string $action): void
{
    if ($dokId < 1 || !in_array($action, ['trash', 'restore'], true)) throw new RuntimeException('Ungültige Aktion.');
    $db->beginTransaction();
    try {
        $sql = $action === 'trash'
            ? 'UPDATE dokumente SET GeloeschtAm = NOW() WHERE DokID = ? AND GeloeschtAm IS NULL'
            : 'UPDATE dokumente SET GeloeschtAm = NULL WHERE DokID = ? AND GeloeschtAm IS NOT NULL';
        $stmt = $db->prepare($sql);
        $stmt->execute([$dokId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Dokument nicht gefunden oder Status zwischenzeitlich geändert.');
        eablage_protokoll($db, $dokId, $action === 'trash' ? 'papierkorb' : 'wiederhergestellt');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function eablage_papierkorb_datei(string $root, string $name): string
{
    if ($name === '' || basename($name) !== $name) throw new RuntimeException('Ungültiger Dateiname.');
    $directory = realpath($root);
    if ($directory === false || !is_dir($directory)) throw new RuntimeException('Archivverzeichnis fehlt.');
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Archivdatei ist keine reguläre Datei.');
    return $path;
}

function eablage_papierkorb_endgueltig(PDO $db, int $dokId, string $archiveRoot, string $metaRoot, ?bool $expectedRetention = null): void
{
    if ($dokId < 1) throw new RuntimeException('Ungültige Dokument-ID.');
    $staged = [];
    $lockName = 'eablage.archive.' . substr(hash('sha256', (string)MYSQL_DATENBANK), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Archivierung ist belegt. Bitte erneut versuchen.');
    $committed = false;
    $commitStarted = false;
    try {
        $db->beginTransaction();
        $retainDetails = eablage_protokoll_loeschdaten_behalten($db, true);
        if ($expectedRetention !== null && $expectedRetention !== $retainDetails) {
            throw new RuntimeException('Die Protokolleinstellung wurde zwischenzeitlich geändert. Bitte den Papierkorb neu öffnen und den Löschhinweis prüfen.');
        }
        $stmt = $db->prepare('SELECT d.DateiSHA256, d.Datum, d.Partner, d.Betreff, d.Nummer,
            d.Originaldateiname, d.kategorie_id, d.kategorie2_id, d.doktyp_id,
            k1.katBez AS kategorie_bezeichnung, k2.katBez AS kategorie2_bezeichnung,
            dt.doktypBez AS doktyp_bezeichnung
            FROM dokumente d
            LEFT JOIN kategorie k1 ON k1.id=d.kategorie_id
            LEFT JOIN kategorie k2 ON k2.id=d.kategorie2_id
            LEFT JOIN doktyp dt ON dt.id=d.doktyp_id
            WHERE d.DokID = ? AND d.GeloeschtAm IS NOT NULL FOR UPDATE');
        $stmt->execute([$dokId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Dokument ist nicht mehr im Papierkorb.');
        $pdfName = eablage_archiv_pdf_dateiname($dokId);
        $paths = [eablage_papierkorb_datei($archiveRoot, $pdfName)];
        if (file_exists($paths[0]) && $row['DateiSHA256'] !== null) {
            $actual = hash_file('sha256', $paths[0]);
            if ($actual === false || !hash_equals(strtolower((string)$row['DateiSHA256']), $actual)) {
                throw new RuntimeException('PDF-Prüfsumme weicht ab. Bitte Archivprüfung starten.');
            }
        }
        foreach ([$dokId . 'meta.json'] as $name) {
            $paths[] = eablage_papierkorb_datei($metaRoot, $name);
        }
        foreach ($paths as $path) {
            if (!file_exists($path)) continue;
            $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.eablage-delete-' . $dokId . '-' . bin2hex(random_bytes(12)) . '-' . basename($path);
            if (!rename($path, $temporary)) throw new RuntimeException('Eine Datei konnte nicht für das Löschen vorbereitet werden.');
            $staged[$temporary] = $path;
        }
        // Reserve the highest used ID even after the last document is removed.
        $reserve = $db->prepare("INSERT INTO parameter (param_key, param_value, beschreibung, gruppierung, rang, anzeige)
            VALUES ('eablageLetzteDokID', ?, 'Höchste vergebene Dokument-ID', 'System', '999', 'nein')
            ON DUPLICATE KEY UPDATE param_value = GREATEST(CAST(param_value AS UNSIGNED), CAST(VALUES(param_value) AS UNSIGNED))");
        $reserve->execute([(string)$dokId]);
        $delete = $db->prepare('DELETE FROM dokumente WHERE DokID = ? AND GeloeschtAm IS NOT NULL');
        $delete->execute([$dokId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Datenbankeintrag konnte nicht entfernt werden.');
        if (!$retainDetails) eablage_protokoll_details_bereinigen($db, $dokId);
        eablage_protokoll($db, $dokId, 'endgueltig_geloescht', $retainDetails ? ['dokument'=>eablage_protokoll_dokument($row)] : []);
        $commitStarted = true;
        $db->commit();
        $committed = true;
    } catch (Throwable $e) {
        $rolledBack = false;
        if ($db->inTransaction()) { $db->rollBack(); $rolledBack = true; }
        if ($commitStarted && !$committed && !$rolledBack) {
            error_log('e_ablage Papierkorb: Commitstatus unklar; bereitgestellte Dateien nicht automatisch verschoben.');
            throw new RuntimeException('Datenbankstatus nach Verbindungsfehler unklar. Bitte Datenbank und Archiv prüfen.', 0, $e);
        }
        foreach (array_reverse($staged, true) as $temporary => $original) {
            if (file_exists($original) || is_link($original) || !@rename($temporary, $original)) {
                error_log('e_ablage Papierkorb: Rückverschieben fehlgeschlagen: ' . $temporary);
            }
        }
        throw $e;
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        } catch (Throwable $e) { error_log('e_ablage Papierkorb: Archivierungssperre wird beim Verbindungsende freigegeben.'); }
    }
    $leftovers = [];
    foreach ($staged as $temporary => $original) {
        if (!@unlink($temporary)) {
            error_log('e_ablage Papierkorb: Datei nach DB-Löschung nicht entfernt: ' . $temporary);
            $leftovers[] = basename($temporary);
        }
    }
    if ($leftovers) throw new RuntimeException('Datenbankeintrag entfernt, aber Dateien konnten nicht vollständig gelöscht werden. Verwaiste Dateien in der Archivprüfung ansehen.');
}
