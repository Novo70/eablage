<?php
require_once __DIR__ . '/../web/datenbank_wiederherstellung.php';

function assert_restore(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$dir = sys_get_temp_dir() . '/eablage-restore-test-' . bin2hex(random_bytes(5));
mkdir($dir);
$file = $dir . '/eablage_2026-09-29_190000.sql';
try {
    $tables = EABLAGE_RESTORE_TABLES;
    $sql = "-- e_ablage MariaDB-Datensicherung; Version: 4.1.0\n-- Datenbank: eablage\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($tables as $table) {
        $columns = match ($table) {
            'dokumente' => '`DokID` int, `Originaldateiname` text, `DateiSHA256` text, `Aenderungsdatum` datetime, `GeloeschtAm` datetime',
            'doktyp', 'kategorie' => '`Aktiv` int',
            'parameter' => '`param_key` text, `param_value` text',
            'autokat' => '`RegelID` text, `Version` int, `Rang` int',
        };
        $sql .= "-- Tabelle: $table\nDROP TABLE IF EXISTS `$table`;\nCREATE TABLE `$table` (`id` int, `wert` text, $columns) ENGINE=InnoDB;\n";
        $sql .= "INSERT INTO `$table` (`id`, `wert`) VALUES (1, 'Zeile 1\nZeile 2; mit ''Quote''');\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($file, $sql);
    $summary = eablage_restore_validate($file);
    assert_restore($summary['tables'] === 5 && $summary['statements'] === 18, 'Gültiger Dump nicht erkannt');
    foreach (['kategorie', 'doktyp'] as $table) {
        $legacyCreate = "CREATE TABLE `$table` (`id` varchar(20), `Aktiv` int) ENGINE=InnoDB";
        $upgraded = eablage_restore_master_statement($legacyCreate);
        assert_restore(str_contains($upgraded, '`Erlaeuterung` varchar(1000) DEFAULT NULL'), 'Ältere Stammdatenstruktur nicht ergänzt');
        assert_restore(eablage_restore_master_statement($upgraded) === $upgraded, 'Erläuterung doppelt ergänzt');
        $insert = "INSERT INTO `$table` (`id`, `Erlaeuterung`) VALUES ('1', 'Gebäudeversicherung; Zeile 2')";
        assert_restore(eablage_restore_master_statement($insert) === $insert, 'Erläuterungsdaten bei Rücksicherung verändert');
    }
    assert_restore(eablage_restore_master_statement('CREATE TABLE `dokumente` (`ID` int) ENGINE=InnoDB') === 'CREATE TABLE `dokumente` (`ID` int) ENGINE=InnoDB', 'Andere Tabelle verändert');
    $parameterCreate = "CREATE TABLE `parameter` (`param_key` varchar(255), `param_value` varchar(255) DEFAULT NULL) ENGINE=InnoDB";
    $upgradedParameters = eablage_restore_parameter_statement($parameterCreate);
    assert_restore(str_contains($upgradedParameters, '`param_value` text DEFAULT NULL'), 'Ältere Parameterstruktur nicht erweitert');
    assert_restore(str_contains($upgradedParameters, '`param_key` varchar(255)'), 'Andere Parameterspalte verändert');
    assert_restore(eablage_restore_parameter_statement($upgradedParameters) === $upgradedParameters, 'Aktuelle Parameterstruktur verändert');
    $parameterInsert = "INSERT INTO `parameter` (`param_key`, `param_value`) VALUES ('ollamaAnweisung', 'Text mit varchar(255); Zeile 2')";
    assert_restore(eablage_restore_parameter_statement($parameterInsert) === $parameterInsert, 'Gespeicherte Anweisung verändert');
    assert_restore(count(eablage_restore_files($dir)) === 1, 'Sicherung nicht gelistet');
    assert_restore(eablage_restore_path($dir, basename($file)) === realpath($file), 'Pfadprüfung fehlgeschlagen');
    foreach (['4.1.0', '4.2.0', '4.3.0', '4.4.0'] as $version) {
        $supported = str_replace('Version: 4.1.0', 'Version: ' . $version, $sql);
        file_put_contents($file, $supported);
        assert_restore(eablage_restore_validate($file)['tables'] === 5, 'Unterstützte Sicherung abgewiesen: ' . $version);
        file_put_contents($file, str_replace('`GeloeschtAm`', '`AlterWert`', $supported));
        $rejected = false;
        try { eablage_restore_validate($file); } catch (RuntimeException $e) { $rejected = true; }
        assert_restore($rejected, 'Ungültiges Schema angenommen: ' . $version);
    }
    $historyDdl = "DROP TABLE IF EXISTS `ereignisprotokoll`;\nCREATE TABLE `ereignisprotokoll` (`ID` bigint, `DokID` int, `Zeitpunkt` datetime, `Ereignistyp` varchar(40), `Quelle` varchar(40), `Details` longtext CHECK (json_valid(`Details`))) ENGINE=InnoDB;\nINSERT INTO `ereignisprotokoll` (`ID`, `DokID`, `Details`) VALUES ('1', '42', '{\"text\":\"Mit ; und Umlaut ä\"}');\n";
    $withHistory = str_replace("-- Datenbank:", "-- Ereignisprotokoll: 1\n-- Datenbank:", $sql);
    $withHistory = str_replace('SET FOREIGN_KEY_CHECKS=1;', $historyDdl . 'SET FOREIGN_KEY_CHECKS=1;', $withHistory);
    file_put_contents($file, $withHistory);
    $summary = eablage_restore_validate($file);
    assert_restore($summary['tables'] === 6 && $summary['history'] === true, 'Protokollbackup nicht erkannt');
    foreach (['4.1.0', '4.2.0', '4.3.0', '4.4.0'] as $version) {
        file_put_contents($file, str_replace('Version: 4.1.0', 'Version: ' . $version, $withHistory));
        $summary = eablage_restore_validate($file);
        assert_restore($summary['tables'] === 6 && $summary['history'], 'Historienbackup abgewiesen: ' . $version);
    }
    $oldHistory = str_replace(['Ereignisprotokoll:', 'ereignisprotokoll'], ['Dokumentprotokoll:', 'dokument_protokoll'], $withHistory);
    file_put_contents($file, $oldHistory);
    assert_restore(eablage_restore_validate($file)['legacy_history'] === true, 'Bisheriges Historienbackup abgewiesen');
    $oldInsert = "INSERT INTO `dokument_protokoll` (`Details`) VALUES ('{\"text\":\"dokument_protokoll\"}')";
    $newInsert = eablage_restore_history_statement($oldInsert, true);
    assert_restore(str_starts_with($newInsert, 'INSERT INTO `ereignisprotokoll`') && str_contains($newInsert, '"text":"dokument_protokoll"'), 'Migration verändert JSON-Werte');
    assert_restore(eablage_restore_history_statement($oldInsert, false) === $oldInsert, 'Aktuelles Backup wird verändert');
    $failures = [
        str_replace('Dokumentprotokoll: 1', 'Dokumentprotokoll: 2', $oldHistory),
        str_replace('ereignisprotokoll', 'dokument_protokoll', $withHistory),
        str_replace('dokument_protokoll', 'ereignisprotokoll', $oldHistory),
        str_replace($historyDdl, '', $withHistory),
        str_replace("-- Ereignisprotokoll: 1\n", '', $withHistory),
        str_replace('-- Ereignisprotokoll: 1', '-- Ereignisprotokoll: 2', $withHistory),
        str_replace('`Details`', '`Fremd`', $withHistory),
        str_replace('Version: 4.1.0', 'Version: 4.5.0', $sql),
        str_replace('Version: 4.1.0', 'Version: 4.0.0', $sql),
        str_replace('`GeloeschtAm`', '`AlterWert`', $sql),
        str_replace('`DateiSHA256`', '`AlterWert`', $sql),
        str_replace('SET FOREIGN_KEY_CHECKS=1;', '', $sql),
        str_replace('CREATE TABLE `autokat`', 'CREATE TABLE `fremd`', $sql),
    ];
    foreach ($failures as $bad) {
        file_put_contents($file, $bad);
        $rejected = false;
        try { eablage_restore_validate($file); } catch (RuntimeException $e) { $rejected = true; }
        assert_restore($rejected, 'Ungültiger Dump angenommen');
    }
    echo "Rücksicherungs-Vorprüfung OK\n";
} finally {
    @unlink($file);
    rmdir($dir);
}

