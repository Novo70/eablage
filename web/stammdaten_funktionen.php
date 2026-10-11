<?php
/**
 * Read helpers and assignment guards for existing PHP write operations.
 * Master-data CRUD itself is handled exclusively by Python.
 */
function eablage_stammdaten_liste(PDO $db, string $art, bool $nurAktiv = false, array $behalten = []): array
{
    $definition = ['kategorie' => 'katBez', 'doktyp' => 'doktypBez'];
    if (!isset($definition[$art])) throw new InvalidArgumentException('Ungültige Stammdatenart.');
    $label = $definition[$art];
    $stmt = $db->query("SELECT id, $label, Aktiv FROM $art ORDER BY $label, id");
    $werte = ['0' => ''];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['id'];
        if ($nurAktiv && !(int)$row['Aktiv'] && !in_array($id, array_map('strval', $behalten), true)) continue;
        $werte[$id] = (string)$row[$label] . ((int)$row['Aktiv'] ? '' : ' (inaktiv)');
    }
    return $werte;
}

/** Keep unavailable cached proposals visible; the transactional guard rejects them. */
function eablage_stammdaten_vorschlag(PDO $db, string $art, array &$optionen, string $vorschlag): bool
{
    if ($vorschlag === '' || array_key_exists($vorschlag, $optionen)) return false;
    $alle = eablage_stammdaten_liste($db, $art);
    $optionen[$vorschlag] = ($alle[$vorschlag] ?? 'ID ' . $vorschlag . ' (gelöscht)') . ' – bitte neu wählen';
    return true;
}

function eablage_pruefe_zuordnungen(PDO $db, array $werte, array $bisher = []): void
{
    if (!$db->inTransaction()) throw new LogicException('Zuordnungsprüfung benötigt eine Transaktion.');
    foreach (['kategorie_id' => 'kategorie', 'kategorie2_id' => 'kategorie', 'doktyp_id' => 'doktyp'] as $feld => $tabelle) {
        $id = trim((string)($werte[$feld] ?? ''));
        if ($id === '' || $id === '0') continue;
        $stmt = $db->prepare("SELECT Aktiv FROM $tabelle WHERE id = ? LOCK IN SHARE MODE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $unveraendert = $id === trim((string)($bisher[$feld] ?? ''));
        if (!$row || (!(int)$row['Aktiv'] && !$unveraendert)) {
            throw new RuntimeException('Kategorie oder Dokumententyp wurde deaktiviert oder gelöscht. Bitte Zuordnung prüfen.');
        }
    }
}
