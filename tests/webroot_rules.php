<?php
// Validate the actual FilesMatch expression using PHP's PCRE engine.
$config = file_get_contents(__DIR__ . '/../web/apache-security.conf');
if (!preg_match('/<FilesMatch "([^"]+)">/', $config, $match)) throw new RuntimeException('FilesMatch rule missing');
$pattern = '~' . $match[1] . '~';
foreach (['nummern_schema.sql', 'vorgaenge_schema.sql', 'Dockerfile', '.dockerignore', 'uploads.ini', 'apache-security.conf', '.user.ini', 'OTHER.CONF', 'UPPER.SQL', 'CONFIG.INI'] as $name) {
    if (preg_match($pattern, $name) !== 1) throw new RuntimeException('Unprotected local resource: ' . $name);
}
foreach (['index.php', 'ansehen.php', 'app.css', 'icons.svg', 'meldungen.js', 'ea_logo4.png', 'document.pdf'] as $name) {
    if (preg_match($pattern, $name) !== 0) throw new RuntimeException('Application asset blocked: ' . $name);
}
echo "Webroot FilesMatch rules: PASS\n";
