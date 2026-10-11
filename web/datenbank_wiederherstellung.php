<?php
// Initialbefüllung aus einer unterstützten eAblage-Sicherung.
// Die Archivprüfung bleibt ein eigenständiger Vorgang.

const EABLAGE_RESTORE_VERSIONS = ['4.1.0', '4.2.0', '4.3.0'];
const EABLAGE_RESTORE_TABLES = ['autokat', 'doktyp', 'dokumente', 'kategorie', 'parameter'];

function eablage_restore_empty(PDO $db): bool
{
    return (int)$db->query('SELECT COUNT(*) FROM dokumente')->fetchColumn() === 0;
}

function eablage_restore_files(string $directory): array
{
    if (!is_dir($directory) || !is_readable($directory)) return [];
    $files = [];
    foreach (new DirectoryIterator($directory) as $item) {
        if ($item->isFile() && !$item->isLink() && preg_match('/^[A-Za-z0-9_-]+_\d{4}-\d{2}-\d{2}_\d{6}\.sql$/D', $item->getFilename())) {
            $files[] = $item->getFilename();
        }
    }
    rsort($files, SORT_STRING);
    return $files;
}

function eablage_restore_path(string $directory, string $filename): string
{
    if (!in_array($filename, eablage_restore_files($directory), true)) {
        throw new RuntimeException('Die Sicherungsdatei wurde im konfigurierten Verzeichnis nicht gefunden.');
    }
    $root = realpath($directory);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($root === false || $path === false || dirname($path) !== $root || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Die Sicherungsdatei ist nicht sicher lesbar.');
    }
    return $path;
}

function eablage_restore_statements(string $path): Generator
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Sicherungsdatei kann nicht gelesen werden.');
    $buffer = '';
    $quote = '';
    $escaped = false;
    try {
        while (($line = fgets($handle)) !== false) {
            if (trim($buffer) === '' && (trim($line) === '' || str_starts_with($line, '-- '))) { $buffer = ''; continue; }
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $buffer .= $char;
                if (strlen($buffer) > 64 * 1024 * 1024) throw new RuntimeException('SQL-Anweisung zu groß.');
                if ($quote !== '') {
                    if ($escaped) { $escaped = false; continue; }
                    if ($char === '\\' && $quote === "'") { $escaped = true; continue; }
                    if ($char === $quote) {
                        if ($i + 1 < $length && $line[$i + 1] === $quote) {
                            $buffer .= $line[++$i];
                        } else {
                            $quote = '';
                        }
                    }
                } elseif ($char === "'" || $char === '`') {
                    $quote = $char;
                } elseif ($char === ';') {
                    $statement = trim(substr($buffer, 0, -1));
                    if ($statement !== '') yield $statement;
                    $buffer = '';
                }
            }
        }
        if (!feof($handle) || trim($buffer) !== '' || $quote !== '') {
            throw new RuntimeException('Sicherungsdatei ist unvollständig.');
        }
    } finally {
        fclose($handle);
    }
}

function eablage_restore_validate(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Sicherungsdatei kann nicht gelesen werden.');
    $header = fgets($handle);
    $format = fgets($handle);
    fclose($handle);
    $legacyHistory = $format === "-- Dokumentprotokoll: 1\n";
    $hasHistory = $legacyHistory || $format === "-- Ereignisprotokoll: 1\n";
    $historyTable = $legacyHistory ? 'dokument_protokoll' : 'ereignisprotokoll';
    if ((str_starts_with((string)$format, '-- Ereignisprotokoll:') || str_starts_with((string)$format, '-- Dokumentprotokoll:')) && !$hasHistory) {
        throw new RuntimeException('Nicht unterstützte Protokollversion in der Sicherung.');
    }
    $expectedTables = EABLAGE_RESTORE_TABLES;
    if ($hasHistory) $expectedTables[] = $historyTable;
    $supportedHeaders = array_map(
        static fn(string $version): string => '-- e_ablage MariaDB-Datensicherung; Version: ' . $version . "\n",
        EABLAGE_RESTORE_VERSIONS
    );
    if (!in_array($header, $supportedHeaders, true)) {
        throw new RuntimeException('Diese Sicherung ist nicht eindeutig als unterstützte eAblage-Version (' . implode(', ', EABLAGE_RESTORE_VERSIONS) . ') gekennzeichnet. Andere Dumps müssen vor der Nutzung gesondert migriert werden.');
    }
    $created = [];
    $dropped = [];
    $phase = 0;
    $count = 0;
    foreach (eablage_restore_statements($path) as $sql) {
        $count++;
        if ($count === 1 && $sql === 'SET NAMES utf8mb4') continue;
        if ($count === 2 && $sql === 'SET FOREIGN_KEY_CHECKS=0') { $phase = 1; continue; }
        if ($sql === 'SET FOREIGN_KEY_CHECKS=1' && $phase === 1) { $phase = 2; continue; }
        if ($phase !== 1) throw new RuntimeException('Unerwartete SQL-Anweisung in der Sicherung.');
        if (preg_match('/^DROP TABLE IF EXISTS `([a-z_]+)`$/D', $sql, $m)) {
            $table = $m[1];
            if (!in_array($table, $expectedTables, true) || isset($dropped[$table])) throw new RuntimeException('Ungültige Tabellenstruktur im Backup.');
            $dropped[$table] = true;
        } elseif (preg_match('/^CREATE TABLE `([a-z_]+)` \(/', $sql, $m)) {
            $table = $m[1];
            if (!isset($dropped[$table]) || isset($created[$table])) throw new RuntimeException('Ungültige Tabellenstruktur im Backup.');
            if (stripos($sql, 'ENGINE=InnoDB') === false) throw new RuntimeException('Die Sicherung enthält eine nicht unterstützte Tabellen-Engine.');
            $required = match ($table) {
                'dokumente' => ['DokID', 'Originaldateiname', 'DateiSHA256', 'Aenderungsdatum', 'GeloeschtAm'],
                'doktyp', 'kategorie' => ['Aktiv'],
                'parameter' => ['param_key', 'param_value'],
                'autokat' => ['RegelID', 'Version', 'Rang'],
                'ereignisprotokoll', 'dokument_protokoll' => ['ID', 'DokID', 'Zeitpunkt', 'Ereignistyp', 'Quelle', 'Details'],
            };
            foreach ($required as $column) {
                if (!str_contains($sql, '`' . $column . '`')) throw new RuntimeException('Sicherung enthält nicht das unterstützte eAblage-Schema: ' . $table . '.' . $column);
            }
            $created[$table] = true;
        } elseif (preg_match('/^INSERT INTO `([a-z_]+)` \(.+\) VALUES \(.+\)$/sD', $sql, $m)) {
            if (!isset($created[$m[1]])) throw new RuntimeException('Datensatz ohne zugehörige Tabelle im Backup.');
        } else {
            throw new RuntimeException('Unbekannte SQL-Anweisung in der Sicherung.');
        }
    }
    if ($phase !== 2 || $count < 3 || count($created) !== count($expectedTables) || count($dropped) !== count($expectedTables)) {
        throw new RuntimeException('Sicherungsdatei enthält keinen vollständigen eAblage-Tabellenbestand.');
    }
    return ['tables' => count($created), 'statements' => $count, 'history' => $hasHistory, 'legacy_history' => $legacyHistory];
}

/** Used only after validating the complete dump. Preserve all stored values. */
function eablage_restore_history_statement(string $sql, bool $legacy): string
{
    if (!$legacy) return $sql;
    return preg_replace('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `dokument_protokoll`/', '$1 `ereignisprotokoll`', $sql);
}

function eablage_restore_database_unlocked(PDO $db, string $path): array
{
    $summary = eablage_restore_validate($path); // Vollständige Prüfung vor der ersten Änderung.
    if (!eablage_restore_empty($db)) throw new RuntimeException('Die Tabelle dokumente ist nicht leer. Rücksicherung gesperrt.');
    // The migration is required even when restoring a legacy backup.
    $db->query('SELECT ID FROM ereignisprotokoll LIMIT 0');
    $marker = dirname($path) . DIRECTORY_SEPARATOR . '.eablage-restore-incomplete';
    $handle = @fopen($marker, 'xb');
    if ($handle === false) throw new RuntimeException('Eine Rücksicherung ist bereits gestartet oder fehlgeschlagen. Installation vor erneutem Versuch prüfen bzw. zurücksetzen.');
    fwrite($handle, date('c') . ' ' . basename($path) . "\n");
    fclose($handle);
    // Legacy snapshots have no history: do not retain events from a different archive.
    if (!$summary['history']) $db->exec('DELETE FROM ereignisprotokoll');
    // Ein abgebrochener DDL-Import kann nicht mit ROLLBACK zurückgenommen werden.
    foreach (eablage_restore_statements($path) as $sql) {
        $db->exec(eablage_restore_history_statement($sql, $summary['legacy_history']));
    }
    $summary['documents'] = (int)$db->query('SELECT COUNT(*) FROM dokumente')->fetchColumn();
    unlink($marker);
    return $summary;
}


/** Block new jobs and reject restore while a file is actively being processed. */
function eablage_restore_database(PDO $db, string $path): array
{
    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $gate = 'eablage.inbox.gate.' . substr(hash('sha256', $database), 0, 40);
    $held = [];
    try {
        $stmt = $db->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$gate]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Inbox-Verarbeitung läuft. Bitte kurz warten und Rücksicherung erneut starten.');
        $held[] = $gate;
        $exists = $db->query("SHOW TABLES LIKE 'verarbeitungsauftraege'")->fetchColumn() !== false;
        if ($exists) {
            foreach ($db->query('SELECT dateischluessel FROM verarbeitungsauftraege')->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $lock = 'eablage.inbox.' . substr($key, 0, 40);
                $stmt->execute([$lock]);
                if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Ein Dokument wird verarbeitet. Bitte nach Abschluss erneut versuchen.');
                $held[] = $lock;
            }
        }
        $result = eablage_restore_database_unlocked($db, $path);
        if ($exists) $db->exec('DELETE FROM verarbeitungsauftraege');
        return $result;
    } finally {
        foreach (array_reverse($held) as $lock) {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$lock]);
        }
    }
}
