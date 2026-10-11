<?php
require_once __DIR__ . '/../web/funktionen.php';
require_once __DIR__ . '/../web/datenbank_sicherung.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
putenv('BACKEND_URL=http://backend:8000/');
check(eablage_backend_url() === 'http://backend:8000', 'Backend URL must come from the environment.');
putenv('BACKEND_URL=');
check(eablage_backend_url() === '', 'Missing backend URL must remain empty.');
$stream = fopen('php://temp', 'w+');
eablage_backup_write($stream, 'ä test');
rewind($stream);
check(stream_get_contents($stream) === 'ä test', 'Backup bytes must be preserved.');
fclose($stream);
// Unbuffered full-device stream must cause a handled exception, never success.
if (file_exists('/dev/full')) {
    $stream = fopen('/dev/full', 'wb');
    stream_set_write_buffer($stream, 0);
    $failed = false;
    try { @eablage_backup_write($stream, 'test'); } catch (RuntimeException $e) { $failed = true; }
    fclose($stream);
    check($failed, 'Full disk must fail the backup write.');
}
echo "PHP helper regressions: PASS\n";
