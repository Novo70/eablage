<?php
/** Copy the actual web tree; configurations and symlinks never enter a fixture. */
function eablage_test_copy_web(string $target, ?string $source = null): void
{
    $source ??= __DIR__ . '/../web';
    if (!is_dir($target) && !mkdir($target, 0700, true)) throw new RuntimeException('Cannot create fixture');
    foreach (new DirectoryIterator($source) as $file) {
        if ($file->isDot() || $file->isLink() || preg_match('/^konfiguration.*\.php$/i', $file->getFilename())) continue;
        $destination = $target . '/' . $file->getFilename();
        if ($file->isDir()) eablage_test_copy_web($destination, $file->getPathname());
        elseif (!copy($file->getPathname(), $destination)) throw new RuntimeException('Cannot copy fixture');
    }
}

function eablage_test_remove_tree(string $directory): void
{
    if (!is_dir($directory)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($directory);
}
