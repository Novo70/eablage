<?php
require_once __DIR__ . '/web_fixture.php';
$directory = sys_get_temp_dir() . '/eablage-fixture-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    $source = $directory . '/source'; $target = $directory . '/target';
    mkdir($source); mkdir($source . '/nested');
    file_put_contents($source . '/new_helper.php', '<?php // automatically included');
    file_put_contents($source . '/nested/module.mjs', 'export const value = 1;');
    foreach (['konfiguration.php', 'konfiguration_test.php', 'Konfiguration_Private.php'] as $name) file_put_contents($source . '/' . $name, 'LOCAL SECRET');
    symlink($source . '/konfiguration.php', $source . '/linked.php');
    eablage_test_copy_web($target, $source);
    if (file_get_contents($target . '/nested/module.mjs') !== 'export const value = 1;') throw new RuntimeException('Nested module missing');
    if (!is_file($target . '/new_helper.php')) throw new RuntimeException('New dependency missing');
    foreach (['konfiguration.php', 'konfiguration_test.php', 'Konfiguration_Private.php', 'linked.php'] as $name) {
        if (file_exists($target . '/' . $name)) throw new RuntimeException('Local configuration copied: ' . $name);
    }
    echo "Web fixture isolation: PASS\n";
} finally {
    eablage_test_remove_tree($directory);
}
