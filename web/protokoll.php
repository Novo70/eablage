<?php
require_once 'konfiguration.php';
require_once 'protokoll_funktionen.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function history_details($value): string
{
    if (!is_array($value)) return h(is_bool($value) ? ($value ? 'ja' : 'nein') : ($value ?? '–'));
    $labels = [
        'klassifizierung' => 'Klassifizierung',
        'erkennung' => 'Erkennung',
        'regeln' => 'Regeltreffer',
        'typ' => 'Typ',
        'regel_id' => 'Regel',
        'version' => 'Version',
        'rang' => 'Rang',
        'vergleich_dokid' => 'Vergleichsdokument',
        'originaldateiname' => 'Originaldateiname',
        'felder' => 'Geänderte Felder',
        'nummern_vorher' => 'Nummern vorher', 'nummern_nachher' => 'Nummern nachher',
        'kind_id' => 'Nummernart-ID', 'label' => 'Nummernart', 'value' => 'Wert', 'position' => 'Reihenfolge',
        'vorher' => 'Vorher',
        'nachher' => 'Nachher',
        'dokumenttext_geaendert' => 'Dokumenttext geändert',
        'kategorie_id' => 'Kategorie',
        'kategorie2_id' => 'Zweite Kategorie',
        'doktyp_id' => 'Dokumenttyp',
        'dokument' => 'Gelöschtes Dokument',
        'Originaldateiname' => 'Originaldateiname',
        'id' => 'ID',
        'bezeichnung' => 'Bezeichnung'
    ];
    if (!$value) return '–';
    $html = '<ul style="padding-left:20px;margin-bottom:4px">';
    foreach ($value as $key => $entry) {
        $label = is_int($key) ? (string)($key + 1) : ($labels[$key] ?? $key);
        $html .= '<li><strong>' . h($label) . ':</strong> ' . history_details($entry) . '</li>';
    }
    return $html . '</ul>';
}

function history_return_url($url): string
{
    if (!is_string($url) || preg_match('/[\\x00-\\x1f\\x7f]/', $url) || str_contains($url, chr(92))) return 'dokumente.php';
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['port']) || ($parts['path'] ?? '') !== 'bearbeiten.php') return 'dokumente.php';
    parse_str($parts['query'] ?? '', $query);
    $id = $query['dokid'] ?? '';
    if (!is_string($id) || !ctype_digit($id) || (int)$id < 1 || (int)$id > 2147483647) return 'dokumente.php';
    return 'bearbeiten.php?' . http_build_query($query);
}

$returnUrl = history_return_url($_GET['return'] ?? null);
$hasReturn = $returnUrl !== 'dokumente.php';
$resetUrl = 'protokoll.php' . ($hasReturn ? '?' . http_build_query(['return' => $returnUrl]) : '');
$dokId = trim((string)($_GET['dokid'] ?? ''));
$event = trim((string)($_GET['ereignis'] ?? ''));
$regel = strtoupper(trim((string)($_GET['regel'] ?? '')));
$page = max(1, min(1000000, (int)($_GET['seite'] ?? 1)));
$rows = [];
$total = 0;
$error = '';
try {
    $where = [];
    $params = [];
    if ($dokId !== '') {
        if (!ctype_digit($dokId) || (int)$dokId < 1 || (int)$dokId > 2147483647) throw new InvalidArgumentException('Bitte eine gültige DokID eingeben.');
        $where[] = 'p.DokID = ?';
        $params[] = (int)$dokId;
    }
    if ($event !== '') {
        if (!isset(EABLAGE_EREIGNISSE[$event])) throw new InvalidArgumentException('Unbekannter Ereignistyp.');
        $where[] = 'p.Ereignistyp = ?';
        $params[] = $event;
    }
    if ($regel !== '') {
        if (!preg_match('/^R\d{4,}$/D', $regel)) throw new InvalidArgumentException('Bitte eine RegelID wie R0042 eingeben.');
        $where[] = "JSON_SEARCH(p.Details, 'one', ?, NULL, '$.klassifizierung.regeln[*].regel_id') IS NOT NULL";
        $params[] = $regel;
    }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db_config->prepare('SELECT COUNT(*) FROM ereignisprotokoll p' . $filter);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / 50)));
    $offset = ($page - 1) * 50;
    $stmt = $db_config->prepare('SELECT p.*, d.DokID AS vorhanden, d.GeloeschtAm FROM ereignisprotokoll p LEFT JOIN dokumente d ON d.DokID=p.DokID' . $filter . ' ORDER BY p.Zeitpunkt DESC, p.ID DESC LIMIT 50 OFFSET ' . $offset);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('e_ablage Ereignisprotokoll: ' . $e->getMessage());
    $error = 'Dokumenthistorie konnte nicht geladen werden. Bitte Datenbankupdate und Verbindung prüfen.';
}
function history_page(int $page): string
{
    global $dokId, $event, $regel, $returnUrl, $hasReturn;
    return 'protokoll.php?' . http_build_query(['dokid' => $dokId, 'ereignis' => $event, 'regel' => $regel, 'seite' => $page] + ($hasReturn ? ['return' => $returnUrl] : []));
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage – Ereignisprotokoll / Dokumenthistorie</title>
    <link rel="stylesheet" href="app.css">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png" />
    <link rel="icon" href="ea_icon4.png" type="image/png" />
</head>

<body>
    <?php include 'navbar.php'; ?>
    <main class="container">
        <div class="panel panel-primary">
            <div class="panel-heading">
                <div class="panel-title">📋 Ereignisprotokoll / Dokumenthistorie</div>
            </div>
            <div class="panel-body">
                <p>Gespeicherte Dokumentereignisse, neueste zuerst. Frühere Info-Texte bleiben beim Dokument unter „Technische Informationen“ erhalten.</p>
                <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:15px">
                    <?php if ($hasReturn): ?><input type="hidden" name="return" value="<?= h($returnUrl) ?>"><?php endif; ?>
                    <label>DokID <input class="form-control" name="dokid" inputmode="numeric" value="<?= h($dokId) ?>"></label>
                    <label>Ereignis <select class="form-control" name="ereignis">
                            <option value="">Alle Ereignisse</option>
                            <?php foreach (EABLAGE_EREIGNISSE as $key => $label): ?><option value="<?= h($key) ?>" <?= $event === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        </select></label>
                    <label>RegelID <input class="form-control" name="regel" placeholder="R0042" value="<?= h($regel) ?>"></label>
                    <button class="btn btn-primary" type="submit">Filtern</button> <a href="<?= h($resetUrl) ?>" class="btn btn-default">Zurücksetzen</a>
                </form>
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div>
                <?php else: ?><p><?= h($total) ?> Ereignisse · Seite <?= h($page) ?> von <?= max(1, (int)ceil($total / 50)) ?></p>
                    <?php if (!$rows): ?><p>Keine Ereignisse für diese Auswahl vorhanden.</p><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <details class="ea-group-details">
                            <summary class="ea-group-summary">
                                <span class="ea-inbox-file">
                                    <span class="ea-result-meta-row ea-inbox-filename">
                                        <span class="ea-result-date"><?= h($row['Zeitpunkt']) ?> · <?= h(EABLAGE_EREIGNISSE[$row['Ereignistyp']] ?? $row['Ereignistyp']) ?></span>
                                        <span class="ea-result-meta">DokID <?= h($row['DokID']) ?> · <?= h($row['Quelle']) ?></span>
                                        <?php if ($row['vorhanden'] !== null && $row['GeloeschtAm'] === null): ?><a class="ea-result-meta" href="bearbeiten.php?dokid=<?= (int)$row['DokID'] ?>">Dokument öffnen</a><?php endif; ?>
                                    </span>
                                </span>
                            </summary>
                            <div class="ea-group-body ea-inbox-filename">
                                <?php try {
                                    echo history_details(eablage_protokoll_anzeige(json_decode($row['Details'], true, 32, JSON_THROW_ON_ERROR), $row['Ereignistyp']));
                                } catch (Throwable $e) {
                                    echo 'Details nicht lesbar.';
                                } ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                    <?php if ($page > 1): ?><a class="btn btn-default" href="<?= h(history_page($page - 1)) ?>">Neuere Ereignisse</a><?php endif; ?>
                    <?php if ($page * 50 < $total): ?><a class="btn btn-default" href="<?= h(history_page($page + 1)) ?>">Ältere Ereignisse</a><?php endif; ?>
                <?php endif; ?>
                <div class="ea-form-actions"><a href="<?= h($returnUrl) ?>" class="btn btn-default">Zurück</a></div>
            </div>
        </div>
    </main>
</body>

</html>
