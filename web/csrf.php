<?php

/** Ein sitzungsgebundener Token für alle schreibenden Web-Formulare. */
function eablage_aktion_token(): string
{
    $openedSession = session_status() !== PHP_SESSION_ACTIVE;
    if ($openedSession) session_start();
    if (empty($_SESSION['eablage_aktion_token'])) {
        $_SESSION['eablage_aktion_token'] = bin2hex(random_bytes(32));
    }
    $token = $_SESSION['eablage_aktion_token'];
    if ($openedSession) session_write_close();
    return $token;
}

function eablage_aktion_pruefen($submitted): void
{
    if (!is_string($submitted) || !hash_equals(eablage_aktion_token(), $submitted)) {
        throw new RuntimeException('Sitzung ungültig. Bitte Seite neu laden.');
    }
}
