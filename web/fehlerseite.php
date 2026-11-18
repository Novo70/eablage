<?php
require_once __DIR__ . '/seitenkopf.php';
require_once __DIR__ . '/meldungen.php';

function eablage_fehlerseite(string $message, int $status = 500, bool $zumEingang = false): void
{
    http_response_code($status);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <?php eablage_seitenkopf('Fehler'); ?>
    </head>
    <body>
        <?php include 'navbar.php'; ?>
        <div class="container">
            <?=eablage_render_message('danger', $message)?>
            <?php if ($zumEingang): ?>
            <a href="eingang.php" class="btn btn-default">Zurück zum Eingang</a>
            <?php else: ?>
            <a href="javascript:history.back()" class="btn btn-default">Zurück zum Formular</a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
