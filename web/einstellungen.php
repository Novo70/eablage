<?php
// einstellungen.php - Konfigurationszentrale für eAblage
// - Zeigt nur Parameter mit anzeige=ja nach Gruppierung an
// - Alle Kategorien sind beim Laden standardmäßig eingeklappt
// - Ermöglicht direktes Bearbeiten und Speichern in der MariaDB

require_once 'konfiguration.php';
require_once 'meldungen.php';
require_once 'csrf.php';
$aktionToken = eablage_aktion_token();

if (!isset($db) && isset($db_config)) {
    $db = $db_config;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_return_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') return 'dokumente.php';

    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return 'dokumente.php';
    }

    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($path === '' || str_contains($path, '..') || !preg_match('/^[A-Za-z0-9_-]+\.php$/', $path)) {
        return 'dokumente.php';
    }

    $returnUrl = $path;
    if (!empty($parts['query'])) {
        $returnUrl .= '?' . $parts['query'];
    }
    return $returnUrl;
}

$exportDirectoryOptions = [
    '(keine)' => 'Keine Ebene',
    'Kategorie' => 'Kategorie',
    'Kategorie2' => 'Kategorie2',
    'Dokumententyp' => 'Dokumententyp',
    'Partner' => 'Partner',
    'Nummer' => 'Nummer',
    'Jahr' => 'Jahr',
    'Monat' => 'Monat',
];

$returnUrl = valid_return_url($_REQUEST['return'] ?? 'dokumente.php');
$meldung = '';
$meldungTyp = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['params'])) {
    try {
        eablage_aktion_pruefen($_POST['aktion_token'] ?? null);
        $db->beginTransaction();
        if (!is_array($_POST['params'])) throw new InvalidArgumentException('Ungültige Parameter.');
        $textValues = ['ollamaTextAnfang'=>'9000', 'ollamaTextEnde'=>'3000'];
        foreach ($db->query("SELECT param_key,param_value FROM parameter WHERE param_key IN ('ollamaTextAnfang','ollamaTextEnde')")->fetchAll(PDO::FETCH_ASSOC) as $stored) {
            if (array_key_exists($stored['param_key'], $textValues)) $textValues[$stored['param_key']] = $stored['param_value'];
        }
        foreach ($textValues as $textKey => $textValue) {
            if (array_key_exists($textKey, $_POST['params'])) $textValue = $_POST['params'][$textKey];
            if (!is_string($textValue) || !preg_match('/^[0-9]{1,5}$/D', trim($textValue))) {
                throw new InvalidArgumentException('KI-Textauswahl: Anfang mindestens 1, Ende mindestens 0; zusammen höchstens 12000 Zeichen.');
            }
            $textValues[$textKey] = (int)trim($textValue);
        }
        if ($textValues['ollamaTextAnfang'] < 1 || $textValues['ollamaTextEnde'] < 0 || array_sum($textValues) > 12000) {
            throw new InvalidArgumentException('KI-Textauswahl: Anfang mindestens 1, Ende mindestens 0; zusammen höchstens 12000 Zeichen.');
        }

        // Nur Parameter speichern, die auch für die Web-Einstellungsoberfläche freigegeben sind.
        $sqlUpd = "UPDATE `parameter`
                   SET `param_value` = :value
                   WHERE `param_key` = :key AND LOWER(COALESCE(`anzeige`, 'ja')) = 'ja'";
        $stmtUpd = $db->prepare($sqlUpd);

        foreach ($_POST['params'] as $key => $value) {
            if (array_key_exists($key, $textValues)) $value = (string)$textValues[$key];
            if ($key === 'ollamaAnweisung') {
                $length = is_string($value) ? preg_match_all('/./us', $value) : false;
                if ($length === false || $length > 4000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                    throw new InvalidArgumentException('Die KI-Anweisung darf höchstens 4000 Zeichen und keine unzulässigen Steuerzeichen enthalten.');
                }
                $value = str_replace("\r\n", "\n", $value);
            }
            if (in_array($key, ['ollamaAktiv', 'ollamaDenkmodus', 'ollamaDiagnoseAnzeigen'], true) && !in_array($value, ['ja', 'nein'], true)) {
                throw new InvalidArgumentException('Ungültige KI-Einstellung.');
            }
            if ($key === 'ollamaHintergrund' && !in_array($value, ['aus', 'fehlende', 'alle'], true)) {
                throw new InvalidArgumentException('Ungültige Einstellung für KI im Hintergrund.');
            }
            if ($key === 'protokollLoeschdatenBehalten' && !in_array($value, ['ja', 'nein'], true)) {
                throw new InvalidArgumentException('Ungültige Protokolleinstellung.');
            }
            if (in_array($key, ['1_expDateinameModus', '2_expDateinameModus'], true)
                && !in_array($value, ['generiert', 'original'], true)) {
                throw new InvalidArgumentException('Ungültiger Export-Dateinamensmodus.');
            }
            if (preg_match('/^1_expVerzeichnisEbene[1-5]$/', (string)$key)
                && !array_key_exists((string)$value, $exportDirectoryOptions)) {
                throw new InvalidArgumentException('Ungültige Export-Verzeichnisebene.');
            }
            $stmtUpd->bindValue(':value', trim((string)$value));
            $stmtUpd->bindValue(':key', $key);
            $stmtUpd->execute();
        }

        $db->commit();
        $meldung = 'Alle Parameter wurden erfolgreich in der MariaDB aktualisiert!';
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('e_ablage Einstellungen: ' . $e->getMessage());
        if ($e instanceof RuntimeException && $e->getMessage() === 'Sitzung ungültig. Bitte Seite neu laden.') {
            http_response_code(403);
            $meldung = $e->getMessage();
        } else {
            $meldung = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Die Einstellungen konnten nicht gespeichert werden. Details wurden protokolliert.';
        }
        $meldungTyp = 'danger';
    }
}

$sql = "SELECT `param_key`, `param_value`, `beschreibung`, `gruppierung`, `rang`
        FROM `parameter`
        WHERE LOWER(COALESCE(`anzeige`, 'ja')) = 'ja'
        ORDER BY `gruppierung` ASC, `rang` ASC, `param_key` ASC";
$stmt = $db->query($sql);
$alleParameter = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gruppierteParameter = [];
foreach ($alleParameter as $p) {
    if ($meldungTyp === 'danger' && $p['param_key'] === 'ollamaAnweisung' && is_string($_POST['params']['ollamaAnweisung'] ?? null)) $p['param_value'] = $_POST['params']['ollamaAnweisung'];
    if ($meldungTyp === 'danger' && in_array($p['param_key'], ['ollamaTextAnfang','ollamaTextEnde'], true) && is_string($_POST['params'][$p['param_key']] ?? null)) $p['param_value'] = $_POST['params'][$p['param_key']];
    $grp = !empty($p['gruppierung']) ? $p['gruppierung'] : 'Nicht gruppiert';
    $gruppierteParameter[$grp][] = $p;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e_ablage - Parameter-Einstellungen</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="shortcut icon" href="ea_icon4.png" type="image/png">
</head>
<body>

<?php require_once 'navbar.php'; ?>

<div class="container ea-settings-container">
    <?php if ($meldung !== ''): ?>
        <?=eablage_render_message($meldungTyp, $meldung)?>
    <?php endif; ?>

    <div class="panel panel-primary ea-settings-panel">
        <div class="panel-heading">⚙️ Einstellungen</div>
        <div class="panel-body">
            <div class="ea-settings-toolbar">
                <div>
                    <strong>Parameter</strong>
                    <div class="ea-settings-hint">Konfiguration der Web-Anwendung und der angebundenen Dienste.</div>
                </div>
                <div class="ea-settings-toolbar__actions">
                    <button type="button" class="btn btn-default btn-sm" onclick="toggleAllGroups(true)">📂 Alle aufklappen</button>
                    <button type="button" class="btn btn-default btn-sm" onclick="toggleAllGroups(false)">📁 Alle zuklappen</button>
                </div>
            </div>

            <form method="POST" action="einstellungen.php" id="settings-form">
                <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                <input type="hidden" name="return" value="<?=h($returnUrl)?>">
                <?php foreach ($gruppierteParameter as $gruppenName => $parameterListe): ?>
                    <details class="ea-group-details">
                        <summary class="ea-group-summary">
                            <span>📁 <?=h($gruppenName)?></span>
                            <span class="ea-settings-count"><?=count($parameterListe)?> Parameter</span>
                        </summary>

                        <div class="ea-group-body">
                            <?php foreach ($parameterListe as $param): ?>
                                <div class="row ea-param-row">
                                    <div class="col-md-5">
                                        <div class="ea-param-label"><?=h($param['param_key'] === 'ollamaDiagnoseAnzeigen' ? 'KI-Diagnose anzeigen' : ($param['param_key'] === 'ollamaHintergrund' ? 'KI im Hintergrund' : $param['param_key']))?></div>
                                        <?php if (!empty($param['beschreibung'])): ?>
                                            <div class="ea-param-desc"><?=h($param['beschreibung'])?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-7">
                                        <?php if ($param['param_key'] === 'ollamaAnweisung'): ?>
                                        <textarea class="form-control" name="params[ollamaAnweisung]" rows="10" maxlength="4000" aria-label="Fachliche KI-Anweisung"><?=h($param['param_value'])?></textarea>
                                        <p class="small">Leer verwendet die Standardvorgaben. Nach dem Speichern gilt der Text für neue KI-Anfragen. Antwortformat und technische Prüfungen bleiben fest vorgegeben.</p>
                                        <?php elseif ($param['param_key'] === 'ollamaAktiv'): ?>
                                        <select class="form-control" name="params[ollamaAktiv]">
                                            <option value="nein" <?=($param['param_value'] !== 'ja' ? 'selected' : '')?>>Nein</option>
                                            <option value="ja" <?=($param['param_value'] === 'ja' ? 'selected' : '')?>>Ja – manuelle KI-Vorschläge aktivieren</option>
                                        </select>
                                        <?php elseif (in_array($param['param_key'], ['ollamaTextAnfang','ollamaTextEnde'], true)): ?>
                                        <input type="number" class="form-control" name="params[<?=h($param['param_key'])?>]" value="<?=h($param['param_value'])?>" min="<?= $param['param_key'] === 'ollamaTextAnfang' ? '1' : '0' ?>" max="12000" step="1" required>
                                        <p class="small">Anfang und Ende zusammen höchstens 12000 Zeichen. Ende 0 lässt den zusätzlichen Schlussteil weg. Kürzere Dokumente werden vollständig ausgewertet. Änderungen gelten nach Speichern für neue Anfragen und veralten gespeicherte Hintergrundvorschläge.</p>
                                        <?php elseif ($param['param_key'] === 'ollamaHintergrund'): ?>
                                        <select class="form-control" name="params[ollamaHintergrund]" aria-label="KI im Hintergrund">
                                            <?php if (!in_array($param['param_value'], ['aus', 'fehlende', 'alle'], true)): ?><option value="" selected disabled>Ungültiger Wert – bitte auswählen</option><?php endif; ?>
                                            <option value="aus" <?=($param['param_value'] === 'aus' ? 'selected' : '')?>>Aus</option>
                                            <option value="fehlende" <?=($param['param_value'] === 'fehlende' ? 'selected' : '')?>>Nur ohne automatische Klassifizierung</option>
                                            <option value="alle" <?=($param['param_value'] === 'alle' ? 'selected' : '')?>>Für alle Dokumente</option>
                                        </select>
                                        <p class="small">Benötigt aktive lokale KI und einen laufenden Worker. Eine AutoKat-Klassifikationsregel oder eine Kategorie durch Textvergleich verhindert im zweiten Modus den KI-Aufruf. Ergänzungsregeln allein verhindern ihn nicht. Vorhandene Angaben bleiben erhalten.</p>
                                        <?php elseif ($param['param_key'] === 'ollamaDiagnoseAnzeigen'): ?>
                                        <select class="form-control" name="params[ollamaDiagnoseAnzeigen]" aria-label="KI-Diagnose anzeigen">
                                            <?php if (!in_array($param['param_value'], ['ja', 'nein'], true)): ?><option value="" selected disabled>Ungültiger Wert – bitte auswählen</option><?php endif; ?>
                                            <option value="nein" <?=($param['param_value'] === 'nein' ? 'selected' : '')?>>Nein</option>
                                            <option value="ja" <?=($param['param_value'] === 'ja' ? 'selected' : '')?>>Ja</option>
                                        </select>
                                        <p class="small">Zeigt „KI-Anfrage anzeigen“ im Drei-Punkte-Menü der Klassifizierung. Nach dem Speichern die Klassifizierungsseite neu öffnen. Ungespeicherte Eingaben vorher sichern.</p>
                                        <?php elseif ($param['param_key'] === 'ollamaDenkmodus'): ?>
                                        <select class="form-control" name="params[ollamaDenkmodus]" aria-label="Denkmodus">
                                            <?php if (!in_array($param['param_value'], ['ja', 'nein'], true)): ?><option value="" selected disabled>Ungültiger Wert – bitte auswählen</option><?php endif; ?>
                                            <option value="nein" <?=($param['param_value'] === 'nein' ? 'selected' : '')?>>Nein</option>
                                            <option value="ja" <?=($param['param_value'] === 'ja' ? 'selected' : '')?>>Ja</option>
                                        </select>
                                        <p class="small">Kann die Zuordnung verbessern und die Antwortzeit verlängern. Erfordert ein Modell mit Denkmodus. Gilt nach dem Speichern für neue KI-Anfragen.</p>
                                        <?php elseif ($param['param_key'] === 'protokollLoeschdatenBehalten'): ?>
                                        <select class="form-control" name="params[<?=h($param['param_key'])?>]">
                                            <?php if (!in_array($param['param_value'], ['ja', 'nein'], true)): ?><option value="" selected disabled>Ungültiger Wert – bitte auswählen</option><?php endif; ?>
                                            <option value="ja" <?=($param['param_value'] === 'ja' ? 'selected' : '')?>>Ja – Dokumentdaten im Protokoll erhalten</option>
                                            <option value="nein" <?=($param['param_value'] === 'nein' ? 'selected' : '')?>>Nein – alle Protokolldetails bei endgültiger Löschung entfernen</option>
                                        </select>
                                        <?php elseif (in_array($param['param_key'], ['1_expDateinameModus', '2_expDateinameModus'], true)): ?>
                                        <select class="form-control" name="params[<?=h($param['param_key'])?>]">
                                            <option value="generiert" <?=($param['param_value'] === 'generiert' ? 'selected' : '')?>>Gemäß Exportparametern</option>
                                            <option value="original" <?=($param['param_value'] === 'original' ? 'selected' : '')?>>Originaldateiname (falls vorhanden)</option>
                                        </select>
                                        <?php elseif (preg_match('/^1_expVerzeichnisEbene[1-5]$/', (string)$param['param_key'])): ?>
                                        <select class="form-control" name="params[<?=h($param['param_key'])?>]">
                                            <?php $currentLevel = (string)($param['param_value'] ?? ''); ?>
                                            <?php if ($currentLevel !== '' && $currentLevel !== 'nein' && !array_key_exists($currentLevel, $exportDirectoryOptions)): ?>
                                                <option value="" selected disabled>Unbekannter Wert: <?=h($currentLevel)?> – bitte auswählen</option>
                                            <?php endif; ?>
                                            <?php foreach ($exportDirectoryOptions as $value => $label): ?>
                                                <option value="<?=h($value)?>" <?=($currentLevel === $value || ($value === '(keine)' && ($currentLevel === '' || $currentLevel === 'nein'))) ? 'selected' : ''?>><?=h($label)?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php else: ?>
                                        <input
                                            type="text"
                                            class="form-control"
                                            name="params[<?=h($param['param_key'])?>]"
                                            value="<?=h($param['param_value'])?>"
                                        >
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (in_array('ollamaURL', array_column($parameterListe, 'param_key'), true)): ?>
                                <p class="small">Änderungen zuerst speichern. Der Test verwendet die gespeicherten Einstellungen.</p>
                                <button type="button" id="ollama-check" class="btn btn-default">Verbindung testen</button>
                                <p id="ollama-check-status" role="status" aria-live="polite"></p>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <div class="ea-sticky-bar">
                    <div class="ea-settings-version">Version 4.4.0 / 26.10.2026</div>
                    <div class="ea-settings-save-actions ea-form-actions">
                        <a href="<?=h($returnUrl)?>" class="btn btn-default" id="settings-exit"><?=($meldung !== '' && $meldungTyp === 'success') ? 'Zurück' : 'Abbrechen'?></a>
                        <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var settingsExit = document.getElementById('settings-exit');
if (settingsExit.textContent === 'Zurück') {
    var settingsForm = document.getElementById('settings-form');
    ['input', 'change'].forEach(function(eventName) {
        settingsForm.addEventListener(eventName, function() { settingsExit.textContent = 'Abbrechen'; });
    });
}
function toggleAllGroups(openState)
{
    document.querySelectorAll('.ea-group-details').forEach(function(el) {
        if (openState) {
            el.setAttribute('open', '');
        } else {
            el.removeAttribute('open');
        }
    });
}
</script>
<script src="ollama.js?v=<?=filemtime(__DIR__ . '/ollama.js')?>" defer></script>
</body>
</html>

