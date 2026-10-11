<?php
function eablage_archiv_pdf_dateiname(int $dokId): string
{
    if ($dokId < 1) throw new InvalidArgumentException('Ungültige Dokument-ID.');
    return $dokId . 'dok.pdf';
}

// Resolve the DokID-assigned PDF inside the configured archive.
function eablage_archiv_pdf_pfad(string $archiveRoot, int $dokId): ?string
{
    if ($dokId < 1) return null;
    $name = eablage_archiv_pdf_dateiname($dokId);
    $root = realpath($archiveRoot);
    if ($root === false || !is_dir($root)) return null;
    $path = realpath($root . DIRECTORY_SEPARATOR . $name);
    if ($path === false || dirname($path) !== $root || !is_file($path) || !is_readable($path)) return null;
    return $path;
}
