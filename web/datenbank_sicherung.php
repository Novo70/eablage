<?php

function eablage_sql_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function eablage_sql_value(PDO $db, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $quoted = $db->quote((string)$value);
    if ($quoted === false) {
        throw new RuntimeException('Ein Datenbankwert konnte für die Sicherung nicht maskiert werden.');
    }
    return $quoted;
}

function eablage_backup_write($handle, string $data): void
{
    $offset = 0;
    while ($offset < strlen($data)) {
        $written = fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Sicherungsdatei konnte nicht vollständig geschrieben werden. Speicherplatz prüfen.');
        }
        $offset += $written;
    }
}

function eablage_create_database_backup(PDO $db, string $databaseName, string $targetDirectory): array
{
    $targetDirectory = rtrim(trim($targetDirectory), '/\\');
    if ($targetDirectory === '') {
        throw new RuntimeException('Kein Zielverzeichnis für die Datensicherung konfiguriert.');
    }

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('Das Sicherungsverzeichnis konnte nicht angelegt werden.');
    }
    if (!is_writable($targetDirectory)) {
        throw new RuntimeException('Das Sicherungsverzeichnis ist nicht beschreibbar.');
    }

    $safeDatabaseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $databaseName);
    $safeDatabaseName = trim((string)$safeDatabaseName, '_');
    if ($safeDatabaseName === '') {
        $safeDatabaseName = 'e_ablage';
    }

    $timestamp = date('Y-m-d_His');
    $filename = $safeDatabaseName . '_' . $timestamp . '.sql';
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
    $handle = @fopen($targetPath, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Die Sicherungsdatei konnte nicht angelegt werden.');
    }

    $snapshotStarted = false;
    $tableCount = 0;
    $rowCount = 0;

    try {
        if ($db->inTransaction()) throw new RuntimeException('Datensicherung benötigt eine eigene Transaktion.');
        // The application schema uses InnoDB. Fail instead of promising a
        // consistent snapshot for a non-transactional table.
        $engines = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($engines as $engine) {
            if (strtoupper((string)$engine) !== 'INNODB') throw new RuntimeException('Konsistente Sicherung unterstützt nur InnoDB-Tabellen.');
        }
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        $snapshotStarted = true;
        eablage_backup_write($handle, "-- e_ablage MariaDB-Datensicherung; Version: 4.4.0\n");
        eablage_backup_write($handle, "-- Ereignisprotokoll: 1\n");
        eablage_backup_write($handle, '-- Datenbank: ' . $databaseName . "\n");
        eablage_backup_write($handle, '-- Erstellt: ' . date('Y-m-d H:i:s') . "\n\n");
        eablage_backup_write($handle, "SET NAMES utf8mb4;\n");
        eablage_backup_write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tableStmt = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        while (($row = $tableStmt->fetch(PDO::FETCH_NUM)) !== false) {
            if (isset($row[0]) && !in_array($row[0], ['verarbeitungsauftraege', 'ki_vorschlaege', 'ki_worker_status'], true)) {
                $tables[] = (string)$row[0];
            }
        }

        if (!in_array('ereignisprotokoll', $tables, true)) throw new RuntimeException('Ereignisprotokoll fehlt. Zuerst sql/update_document_history.sql ausführen.');

        foreach ($tables as $table) {
            $identifier = eablage_sql_identifier($table);
            $createStmt = $db->query('SHOW CREATE TABLE ' . $identifier);
            $createRow = $createStmt->fetch(PDO::FETCH_NUM);
            if ($createRow === false || !isset($createRow[1])) {
                throw new RuntimeException('Die Tabellenstruktur von ' . $table . ' konnte nicht gelesen werden.');
            }

            eablage_backup_write($handle, "-- --------------------------------------------------------\n");
            eablage_backup_write($handle, '-- Tabelle: ' . $table . "\n\n");
            eablage_backup_write($handle, 'DROP TABLE IF EXISTS ' . $identifier . ";\n");
            eablage_backup_write($handle, (string)$createRow[1] . ";\n\n");

            $dataStmt = $db->query('SELECT * FROM ' . $identifier);
            $columnCount = $dataStmt->columnCount();
            $columns = [];
            for ($i = 0; $i < $columnCount; $i++) {
                $meta = $dataStmt->getColumnMeta($i);
                $columns[] = eablage_sql_identifier((string)($meta['name'] ?? ('column_' . $i)));
            }

            while (($dataRow = $dataStmt->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($dataRow as $value) {
                    $values[] = eablage_sql_value($db, $value);
                }
                eablage_backup_write(
                    $handle,
                    'INSERT INTO ' . $identifier . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n"
                );
                $rowCount++;
            }

            eablage_backup_write($handle, "\n");
            $tableCount++;
        }

        eablage_backup_write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fflush($handle)) throw new RuntimeException('Sicherung konnte nicht vollständig auf den Datenträger geschrieben werden.');
        if (!fclose($handle)) throw new RuntimeException('Sicherungsdatei konnte nicht abgeschlossen werden.');
        $handle = null;
        $db->commit();
        $snapshotStarted = false;
    } catch (Throwable $e) {
        if ($snapshotStarted && $db->inTransaction()) $db->rollBack();
        if (is_resource($handle)) fclose($handle);
        @unlink($targetPath);
        throw $e;
    }

    clearstatcache(true, $targetPath);
    $size = filesize($targetPath);

    return [
        'filename' => $filename,
        'path' => $targetPath,
        'size' => $size === false ? 0 : (int)$size,
        'tables' => $tableCount,
        'rows' => $rowCount,
        'created_at' => date('d.m.Y H:i:s'),
    ];
}

