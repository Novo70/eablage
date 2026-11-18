<?php

function eablage_export_kopieren(string $quelle, string $ziel): bool
{
    $eingabe = @fopen($quelle, 'rb');
    if ($eingabe === false) return false;

    $temp = dirname($ziel) . '/.eablage-export-' . bin2hex(random_bytes(12)) . '.part';
    $ausgabe = @fopen($temp, 'xb');
    if ($ausgabe === false) {
        fclose($eingabe);
        return false;
    }

    try {
        $info = fstat($eingabe);
        $bytes = @stream_copy_to_stream($eingabe, $ausgabe);
        if ($info === false || $bytes !== $info['size'] || !feof($eingabe) || !fflush($ausgabe)) return false;
        if (!fclose($ausgabe)) return false;

        // Beim Ersetzen die bisherigen Zugriffsrechte der Exportdatei übernehmen.
        if (is_file($ziel)) {
            $modus = @fileperms($ziel);
            if ($modus === false || !@chmod($temp, $modus & 0777)) return false;
        }
        if (!@rename($temp, $ziel)) return false;
        $temp = null;
        return true;
    } finally {
        fclose($eingabe);
        if (is_resource($ausgabe)) fclose($ausgabe);
        if ($temp !== null) @unlink($temp);
    }
}
