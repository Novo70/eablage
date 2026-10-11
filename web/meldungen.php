<?php

/**
 * Zentrale Meldungsfunktionen für e_ablage:web.
 *
 * Unterstützte Typen:
 * - success: erfolgreiche Aktion
 * - info:    allgemeiner Hinweis
 * - warning: Warnung / Prüfung erforderlich
 * - danger:  Fehler
 */

function eablage_message_type(string $type): string
{
    return in_array($type, ['success', 'info', 'warning', 'danger'], true)
        ? $type
        : 'info';
}

function eablage_message_icon(string $type): string
{
    return match (eablage_message_type($type)) {
        'success' => '✅',
        'warning' => '⚠️',
        'danger' => '❌',
        default => 'ℹ️',
    };
}

function eablage_render_message(string $type, string $message): string
{
    $type = eablage_message_type($type);
    $icon = eablage_message_icon($type);

    return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '" role="alert">'
        . $icon . ' '
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</div>';
}

function eablage_flash_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function eablage_flash_set(string $type, string $message): void
{
    eablage_flash_start();

    $_SESSION['eablage_flash_message'] = [
        'type' => eablage_message_type($type),
        'message' => $message,
    ];
}

function eablage_flash_get(): ?array
{
    eablage_flash_start();

    $flash = $_SESSION['eablage_flash_message'] ?? null;
    unset($_SESSION['eablage_flash_message']);

    if (!is_array($flash) || trim((string)($flash['message'] ?? '')) === '') {
        return null;
    }

    return [
        'type' => eablage_message_type((string)($flash['type'] ?? 'info')),
        'message' => (string)$flash['message'],
    ];
}

function eablage_render_flash(?array $flash): string
{
    if ($flash === null) {
        return '';
    }

    return eablage_render_message(
        (string)($flash['type'] ?? 'info'),
        (string)($flash['message'] ?? '')
    );
}
