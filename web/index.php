<?php
// Modernisierte Startseite für e_ablage:web
// - Formular sendet per GET, damit Suchparameter in der Ergebnisliste/Pagination erhalten bleiben
// - Werte werden vorbelegt, falls index.php mit Parametern aufgerufen wird

require_once 'konfiguration.php';
require_once 'funktionen.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_value(string $name, string $default = ''): string
{
    return isset($_REQUEST[$name]) ? trim((string)$_REQUEST[$name]) : $default;
}

function selected_value(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$suchbegriff   = request_value('Suchbegriff');
$volltextsuche = isset($_REQUEST['Volltextsuche']);
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
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e_ablage</title>

    <link href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>" rel="stylesheet">
    <style>
        body { padding-top: 70px; }
        .checkbox-inline-fix { display: flex; align-items: center; gap: 8px; }
    </style>

    <link rel="shortcut icon" href="ea_icon4.png" type="image/png" />
    <link rel="icon" href="ea_icon4.png" type="image/png" />
</head>
<body>
<div id="app">
    <?php include "navbar.php"; ?>

    <div class="container">
        <div class="row">
            <div class="col-md-6 col-md-offset-3">
                <div class="panel panel-primary">
                    <div class="panel-heading">🔍 Suche</div>
                    <div class="panel-body">
                        <form method="GET" action="dokumente.php" accept-charset="UTF-8" id="eingabeformular">
                            <div class="form-group">
                                <label for="Suchbegriff">Suchbegriff</label>
                                <input class="form-control" id="Suchbegriff" name="Suchbegriff" type="text" value="<?= h($suchbegriff) ?>">
                            </div>

                            <div class="form-group checkbox-inline-fix">
                                <input id="Volltextsuche" name="Volltextsuche" value="1" type="checkbox"<?= $volltextsuche ? ' checked' : '' ?>>
                                <label for="Volltextsuche" style="margin-bottom:0;">Volltextsuche</label>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="Datumvon">Datum von</label>
                                        <input class="form-control" id="Datumvon" name="Datumvon" type="date" value="<?= h($datumvon) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
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
                               <button class="btn btn-primary" type="submit">Suchen</button>
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
