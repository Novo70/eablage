<?php
function eablage_inbox_lock_name(string $filename): string
{
    return 'eablage.inbox.' . substr(hash('sha256', MYSQL_DATENBANK . "\n" . $filename), 0, 40);
}
function eablage_inbox_lock(PDO $db, string $filename): string
{
    $name = eablage_inbox_lock_name($filename);
    $stmt = $db->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Dieses Dokument wird gerade verarbeitet. Bitte kurz warten und erneut versuchen.');
    return $name;
}
function eablage_inbox_unlock(PDO $db, ?string $name): void
{
    if ($name === null) return;
    $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$name]);
}
