<?php

const EABLAGE_EREIGNISSE = [
    'archiviert' => 'Archiviert',
    'metadaten_geaendert' => 'Dokument bearbeitet',
    'papierkorb' => 'In den Papierkorb verschoben',
    'wiederhergestellt' => 'Aus dem Papierkorb wiederhergestellt',
    'endgueltig_geloescht' => 'Endgültig gelöscht',
];

/** Read the policy afresh; the purge transaction locks it until commit. */
function eablage_protokoll_loeschdaten_behalten(PDO $db, bool $lock = false): bool
{
    if ($lock && !$db->inTransaction()) throw new LogicException('Parametersperre benötigt eine Transaktion.');
    $stmt = $db->prepare("SELECT param_value FROM parameter WHERE param_key = 'protokollLoeschdatenBehalten'" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute();
    $value = $stmt->fetchColumn();
    if ($value === false) return true; // Compatibility with installations before the parameter migration.
    $value = strtolower(trim((string)$value));
    if (!in_array($value, ['ja', 'nein'], true)) throw new RuntimeException('Ungültige Protokolleinstellung. Bitte protokollLoeschdatenBehalten auf ja oder nein setzen.');
    return $value === 'ja';
}

/** Clear every detail, including old field values, in the document transaction. */
function eablage_protokoll_details_bereinigen(PDO $db, int $dokId): void
{
    if (!$db->inTransaction()) throw new LogicException('Protokollbereinigung benötigt eine Transaktion.');
    if ($dokId < 1) throw new InvalidArgumentException('Ungültige Dokument-ID.');
    $stmt = $db->prepare("UPDATE ereignisprotokoll SET Details = '{}' WHERE DokID = ?");
    $stmt->execute([$dokId]);
}

/** Always use the caller's document transaction; never commit independently. */
function eablage_protokoll(PDO $db, int $dokId, string $event, array $details = [], ?string $zeitpunkt = null): void
{
    if (!$db->inTransaction()) throw new LogicException('Ereignisprotokoll benötigt eine laufende Transaktion.');
    if ($dokId < 1 || !isset(EABLAGE_EREIGNISSE[$event])) throw new InvalidArgumentException('Ungültiges Dokumentereignis.');
    $json = json_encode((object)$details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $db->prepare('INSERT INTO ereignisprotokoll (DokID, Zeitpunkt, Ereignistyp, Quelle, Details) VALUES (?, COALESCE(?, NOW()), ?, ?, ?)');
    $stmt->execute([$dokId, $zeitpunkt, $event, 'Web', $json]);
}

/** Convert the existing recognition summary to queryable rule references. */
function eablage_protokoll_klassifizierung(string $summary): array
{
    $methods = [];
    if (str_contains($summary, 'AutoKat')) $methods[] = 'AutoKat';
    if (str_contains($summary, 'Textvergleich')) $methods[] = 'Textvergleich';
    $result = ['erkennung' => $methods ? implode(' + ', $methods) : 'Manuell', 'regeln' => []];
    foreach (['E', 'K'] as $type) {
        if (!preg_match('/\b' . $type . '\[([^\]]*)\]/', $summary, $group)) continue;
        preg_match_all('/\b(R\d{4,})(?:\/v(\d+))?(?:\/r(-?\d+))?\b/i', $group[1], $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result['regeln'][] = ['typ' => $type, 'regel_id' => strtoupper($match[1]),
                'version' => isset($match[2]) && $match[2] !== '' ? (int)$match[2] : null,
                'rang' => isset($match[3]) && $match[3] !== '' ? (int)$match[3] : null];
        }
    }
    if (preg_match('/Textvergleich:(\d+)/', $summary, $match)) $result['vergleich_dokid'] = (int)$match[1];
    return $result;
}

function eablage_protokoll_aenderungen(array $before, array $after): array
{
    $changes = [];
    foreach (['Datum', 'kategorie_id', 'kategorie2_id', 'doktyp_id', 'Partner', 'Betreff', 'Nummer', 'Tags'] as $field) {
        $old = $before[$field] ?? null;
        $new = $after[$field] ?? null;
        if ((string)$old !== (string)$new) $changes[$field] = ['vorher' => $old, 'nachher' => $new];
    }
    return $changes;
}

/** Keep only identifying data from the locked document row, before deletion. */
function eablage_protokoll_dokument(array $row): array
{
    $snapshot = [];
    foreach (['Datum', 'Partner', 'Betreff', 'Nummer', 'Originaldateiname'] as $field) {
        if (isset($row[$field]) && (string)$row[$field] !== '') $snapshot[$field] = $row[$field];
    }
    foreach (['kategorie_id'=>'kategorie_bezeichnung', 'kategorie2_id'=>'kategorie2_bezeichnung', 'doktyp_id'=>'doktyp_bezeichnung'] as $id => $label) {
        if (isset($row[$id]) && (string)$row[$id] !== '') {
            $snapshot[$id] = ['id'=>$row[$id], 'bezeichnung'=>$row[$label] ?? null];
        }
    }
    return $snapshot;
}

/** Older events retain their stored payload; normalize redundant display details. */
function eablage_protokoll_anzeige(array $details, string $event): array
{
    if ($event === 'archiviert') unset($details['originaldateiname']);
    if (isset($details['klassifizierung']['erkennung']) && is_string($details['klassifizierung']['erkennung'])) {
        $normalized = eablage_protokoll_klassifizierung($details['klassifizierung']['erkennung']);
        $details['klassifizierung']['erkennung'] = $normalized['erkennung'];
    }
    return $details;
}
