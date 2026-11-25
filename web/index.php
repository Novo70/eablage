<?php
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/seitenkopf.php';
// Modernisierte Startseite für e_ablage:web
// - Formular sendet per GET, damit Suchparameter in der Ergebnisliste/Pagination erhalten bleiben
// - Werte werden vorbelegt, falls index.php mit Parametern aufgerufen wird

require_once 'konfiguration.php';
require_once 'funktionen.php';


function request_value(string $name, string $default = ''): string
{
    $value = $_REQUEST[$name] ?? null;
    return is_string($value) ? trim($value) : $default;
}

function selected_value(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$suchbegriff   = request_value('Suchbegriff');
$volltextsuche = isset($_REQUEST['Volltextsuche']);
$feldsuche = [
    'Partner' => request_value('Partner'),
    'Betreff' => request_value('Betreff'),
    'Nummer' => request_value('Nummer'),
];
$datumvon      = request_value('Datumvon');
$datumbis      = request_value('Datumbis');
$kategorie     = request_value('Kategorie', '0');
$dokumenttyp   = request_value('Dokumententyp', '0');

$kategorien = eablage_lade_kategorien($db_config);
$dokumenttypen = eablage_lade_dokumenttypen($db_config);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <?php eablage_seitenkopf('Suche'); ?>
</head>
<body>
<div id="app">
    <?php include "navbar.php"; ?>

    <div class="container">
        <div class="row">
            <div class="col-md-6 col-md-offset-3">
                <div class="panel panel-primary">
                    <div class="panel-heading"><h1 class="ea-panel-title"><?=eablage_icon('search')?>Suche</h1></div>
                    <div class="panel-body">
                        <form method="GET" action="dokumente.php" accept-charset="UTF-8" id="eingabeformular">
                            <div class="form-group">
                                <label for="Suchbegriff">Suchbegriff</label>
                                <input class="form-control" id="Suchbegriff" name="Suchbegriff" type="text" value="<?= h($suchbegriff) ?>">
                            </div>

                            <div class="form-group ea-form-check">
                                <input id="Volltextsuche" name="Volltextsuche" value="1" type="checkbox"<?= $volltextsuche ? ' checked' : '' ?>>
                                <label for="Volltextsuche">Volltextsuche</label>
                            </div>

                            <div class="row">
                                <?php foreach ($feldsuche as $feld => $wert): ?>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="<?= h($feld) ?>"><?= h($feld) ?></label>
                                            <input class="form-control" id="<?= h($feld) ?>" name="<?= h($feld) ?>" type="text" value="<?= h($wert) ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="row">
                                <div class="col-md-6 ea-search-date">
                                    <div class="form-group">
                                        <label for="Datumvon">Datum von</label>
                                        <input class="form-control" id="Datumvon" name="Datumvon" type="date" value="<?= h($datumvon) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6 ea-search-date">
                                    <div class="form-group">
                                        <label for="Datumbis">Datum bis</label>
                                        <input class="form-control" id="Datumbis" name="Datumbis" type="date" value="<?= h($datumbis) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="Kategorie">Kategorie</label>
                                        <select class="form-control" id="Kategorie" name="Kategorie">
                                            <?php foreach ($kategorien as $value => $label): ?>
                                                <option value="<?= h($value) ?>"<?= selected_value($kategorie, $value) ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="Dokumententyp">Dokumententyp</label>
                                        <select class="form-control" id="Dokumententyp" name="Dokumententyp">
                                            <?php foreach ($dokumenttypen as $value => $label): ?>
                                                <option value="<?= h($value) ?>"<?= selected_value($dokumenttyp, $value) ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="ea-form-actions">
                               <a class="btn btn-default" href="index.php">Zurücksetzen</a>
                               <button class="btn btn-primary" type="submit"><?=eablage_icon('search')?>Suchen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
