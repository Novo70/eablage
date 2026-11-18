<?php
require_once __DIR__ . '/../web/export_kopie.php';

$dir = sys_get_temp_dir() . '/eablage-export-' . bin2hex(random_bytes(8));
mkdir($dir);
$quelle = $dir . '/quelle.pdf';
$ziel = $dir . '/Vertrag Müller.pdf';
try {
    file_put_contents($quelle, random_bytes(65536));
    file_put_contents($ziel, 'Vorhandene PDF');
    if (eablage_export_kopieren($dir . '/fehlt.pdf', $ziel) || file_get_contents($ziel) !== 'Vorhandene PDF') {
        throw new RuntimeException('Vorhandene Exportdatei bei Lesefehler verändert.');
    }
    if (!eablage_export_kopieren($quelle, $ziel) || hash_file('sha256', $quelle) !== hash_file('sha256', $ziel)) {
        throw new RuntimeException('Exportdatei nicht vollständig ersetzt.');
    }
    if (eablage_export_kopieren($quelle, $dir . '/.') || glob($dir . '/.eablage-export-*.part')) {
        throw new RuntimeException('Temporäre Datei nach fehlgeschlagenem Umbenennen übrig.');
    }
} finally {
    unlink($quelle);
    unlink($ziel);
    rmdir($dir);
}
echo "PDF-Gesamtexport: OK\n";
