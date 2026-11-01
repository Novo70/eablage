<?php
require_once __DIR__ . '/protokoll_funktionen.php';

function eablage_vorgang_id($raw): int
{
    if ((!is_int($raw) && !is_string($raw)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$raw) || (int)$raw > 2147483647) {
        throw new RuntimeException('Ungültige Vorgangs- oder Dokument-ID.');
    }
    return (int)$raw;
}

function eablage_vorgang_text($raw, string $field, int $max, bool $required = false): string
{
    if (!is_string($raw) || !preg_match('//u', $raw) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) throw new RuntimeException('Ungültige Eingabe: ' . $field . '.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", $raw));
    if (($required && $value === '') || preg_match_all('/./us', $value) > $max) throw new RuntimeException($field . ' muss ' . ($required ? '1 bis ' : 'höchstens ') . $max . ' Zeichen enthalten.');
    if ($field === 'Bezeichnung' && preg_match('/[\n\t]/', $value)) throw new RuntimeException('Die Bezeichnung muss einzeilig sein.');
    return $value;
}

function eablage_vorgaenge_laden(PDO $db, int $dokId): array
{
    $stmt = $db->prepare('SELECT VorgangID FROM dokument_vorgang WHERE DokumentID=? ORDER BY VorgangID');
    $stmt->execute([$dokId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function eablage_vorgaenge_pruefen(PDO $db, $raw): array
{
    if (!is_string($raw) || strlen($raw) > 20000) throw new RuntimeException('Ungültige Vorgangsliste.');
    $ids = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000) throw new RuntimeException('Ungültige Vorgangsliste.');
    $ids = array_map('eablage_vorgang_id', $ids);
    if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('Ein Vorgang darf nur einmal zugeordnet sein.');
    sort($ids, SORT_NUMERIC);
    if ($ids) {
        $stmt = $db->prepare('SELECT ID FROM vorgang WHERE ID IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ID' . ($db->inTransaction() ? ' LOCK IN SHARE MODE' : ''));
        $stmt->execute($ids);
        if (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) !== $ids) throw new RuntimeException('Ein gewählter Vorgang wurde gelöscht. Bitte die Zuordnung prüfen.');
    }
    return $ids;
}

/** Caller holds the document row lock (or inserted the new document) and owns the transaction. */
function eablage_vorgaenge_speichern(PDO $db, int $dokId, array $ids): void
{
    if (!$db->inTransaction()) throw new LogicException('Vorgangszuordnung benötigt die Dokumenttransaktion.');
    $ids = eablage_vorgaenge_pruefen($db, json_encode($ids, JSON_THROW_ON_ERROR));
    $before = eablage_vorgaenge_laden($db, $dokId);
    if ($before === $ids) return;
    $stmt = $db->prepare('DELETE FROM dokument_vorgang WHERE DokumentID=?');
    $stmt->execute([$dokId]);
    $stmt = $db->prepare('INSERT INTO dokument_vorgang (DokumentID,VorgangID) VALUES (?,?)');
    foreach ($ids as $id) $stmt->execute([$dokId, $id]);
    eablage_protokoll($db, $dokId, 'vorgaenge_geaendert', ['vorgaenge_vorher'=>$before, 'vorgaenge_nachher'=>$ids]);
}

function eablage_vorgang_anlegen(PDO $db, $name, $description = ''): int
{
    $name = eablage_vorgang_text($name, 'Bezeichnung', 255, true);
    $description = eablage_vorgang_text($description, 'Beschreibung', 10000);
    $stmt = $db->prepare('INSERT INTO vorgang (Bezeichnung,Beschreibung) VALUES (?,?)');
    $stmt->execute([$name, $description]);
    return (int)$db->lastInsertId();
}

function eablage_vorgang_revision(array $row): string
{
    return hash('sha256', json_encode([$row['Bezeichnung'], $row['Beschreibung']], JSON_THROW_ON_ERROR));
}

function eablage_vorgaenge_editor(PDO $db, array $ids): void
{
    $items = $db->query('SELECT ID,Bezeichnung FROM vorgang ORDER BY Bezeichnung,ID')->fetchAll(PDO::FETCH_ASSOC);
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
    <div class="form-group ea-vorgaenge-editor" data-vorgaenge-editor>
        <div class="ea-vorgang-label">
            <label for="vorgaenge-auswahl">Vorgänge</label>
            <button type="button" class="ea-vorgang-help" aria-label="Hinweis zu Vorgängen" aria-describedby="vorgaenge-hinweis">?</button>
            <span class="ea-vorgang-help-text" id="vorgaenge-hinweis" role="tooltip">Die Zuordnung wird mit dem Dokument gespeichert. Ein neu angelegter Vorgang bleibt auch beim Abbrechen bestehen.</span>
        </div>
        <input type="hidden" name="vorgaenge_json" value="<?=$h(json_encode($ids))?>" data-vorgaenge-value>
        <div data-vorgaenge-rows></div>
        <div class="ea-vorgang-add">
            <select id="vorgaenge-auswahl" class="form-control" data-vorgaenge-select aria-label="Vorgang auswählen">
                <option value="">Vorgang auswählen …</option>
                <?php foreach ($items as $item): ?><option value="<?=$h($item['ID'])?>"><?=$h($item['Bezeichnung'])?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-default btn-sm" data-vorgaenge-add>Zuordnen</button>
        </div>
        <details class="ea-vorgang-new"><summary>Neuen Vorgang anlegen</summary>
            <label for="vorgang-neu-name">Bezeichnung</label><input id="vorgang-neu-name" class="form-control" maxlength="255" data-vorgaenge-name>
            <label for="vorgang-neu-beschreibung">Beschreibung (optional)</label><textarea id="vorgang-neu-beschreibung" class="form-control" rows="2" maxlength="10000" data-vorgaenge-description></textarea>
            <button type="button" class="btn btn-default btn-sm" data-vorgaenge-create>Anlegen und zuordnen</button>
        </details>
        <p role="alert" class="text-danger" data-vorgaenge-error hidden></p>
        <noscript>Für die Vorgangsauswahl bitte JavaScript aktivieren. Bestehende Zuordnungen bleiben erhalten.</noscript>
    </div>
    <script src="vorgaenge.js?v=<?=filemtime(__DIR__ . '/vorgaenge.js')?>" defer></script>
    <?php
}
