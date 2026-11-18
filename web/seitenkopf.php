<?php

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function eablage_seitenkopf(string $titel): void
{
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=h($titel)?> – eAblage</title>
    <link rel="stylesheet" href="app.css?v=<?=filemtime(__DIR__ . '/app.css')?>">
    <link rel="icon" href="ea_icon4.png" type="image/png">
    <?php
}
