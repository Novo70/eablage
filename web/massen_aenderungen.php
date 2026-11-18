<?php
require_once __DIR__ . '/dokument_speichern.php';

function eablage_massen_felder(): array
{
    return ['kategorie_id'=>'Hauptkategorie', 'kategorie2_id'=>'Zusatzkategorie', 'doktyp_id'=>'Dokumententyp', 'Partner'=>'Partner', 'Betreff'=>'Betreff', 'Tags'=>'Stichworte', 'numbers'=>'Nummern'];
}

function eablage_massen_laden(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM ' . DB_TABELLE_ARCHIV . ' WHERE DokID=? AND GeloeschtAm IS NULL');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Dokument nicht mehr vorhanden.');
    $row['numbers'] = eablage_nummern_laden($db, $id);
    $row['vorgaenge'] = eablage_vorgaenge_laden($db, $id);
    return $row;
}

function eablage_massen_operationen($raw): array
{
    if (!is_array($raw)) throw new RuntimeException('Ungültige Änderungen.');
    $result = [];
    foreach (eablage_massen_felder() as $field=>$label) {
        $entry = $raw[$field] ?? [];
        if (!is_array($entry)) throw new RuntimeException('Ungültiges Feld.');
        $mode = $entry['mode'] ?? '';
        if ($mode === '') continue;
        $allowed = match ($field) {
            'Partner', 'Betreff' => ['set','replace','clear'],
            'Tags' => ['set','add','remove','clear'],
            'numbers' => ['set','move','remove'],
            default => ['set','clear'],
        };
        if (!in_array($mode, $allowed, true)) throw new RuntimeException('Ungültige Aktion.');
        $op = ['mode'=>$mode];
        foreach (['value','find','kind','target'] as $key) {
            $op[$key] = eablage_vorgang_text($entry[$key] ?? '', $label, 255);
        }
        if ($mode !== 'clear' && $op['value'] === '' && $mode !== 'replace') throw new RuntimeException($label . ' benötigt einen Wert.');
        if ($mode === 'replace' && $op['find'] === '') throw new RuntimeException('Bitte den zu ersetzenden Text eingeben.');
        if ($field === 'numbers' && ($op['kind'] === '' || ($mode === 'move' && ($op['target'] === '' || $op['kind'] === $op['target'])))) throw new RuntimeException('Bitte gültige Nummernarten wählen.');
        $result[$field] = $op;
    }
    if (!$result) throw new RuntimeException('Bitte mindestens ein Feld aktivieren.');
    return $result;
}

function eablage_massen_anwenden(array $document, array $operations): array
{
    foreach ($operations as $field=>$op) {
        $mode = $op['mode'];
        $value = $op['value'];
        if ($field === 'numbers') {
            $numbers = $document['numbers'];
            $index = null;
            foreach ($numbers as $i=>$number) {
                if ((string)$number['kind_id'] === $op['kind']) $index = $i;
                if ($mode === 'move' && (string)$number['kind_id'] === $op['target']) throw new RuntimeException('Ziel-Nummernart ist bereits belegt.');
            }
            if ($mode === 'set') {
                $number = ['kind_id'=>$op['kind'], 'value'=>$value];
                if ($index === null) $numbers[] = $number;
                else $numbers[$index] = $number;
            } else {
                if ($index === null || $numbers[$index]['value'] !== $value) throw new RuntimeException('Ausgangsnummer stimmt nicht exakt überein.');
                if ($mode === 'move') $numbers[$index]['kind_id'] = $op['target'];
                else unset($numbers[$index]);
            }
            $document['numbers'] = array_values($numbers);
        } elseif ($field === 'Tags' && in_array($mode, ['add','remove'], true)) {
            $old = array_values(array_filter(array_map('trim', explode(',', (string)$document[$field])), fn($s)=>$s !== ''));
            $tags = array_values(array_filter(array_map('trim', explode(',', $value)), fn($s)=>$s !== ''));
            $document[$field] = implode(', ', $mode === 'add' ? array_unique(array_merge($old, $tags)) : array_diff($old, $tags));
        } else {
            $document[$field] = match ($mode) {
                'clear' => in_array($field, ['kategorie_id','kategorie2_id','doktyp_id'], true) ? '0' : '',
                'replace' => str_replace($op['find'], $value, (string)$document[$field]),
                default => $value,
            };
        }
        if ($field !== 'numbers') eablage_vorgang_text($document[$field], $field, 255);
    }
    return $document;
}
