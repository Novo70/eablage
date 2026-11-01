<?php
/** Decorative icon; the visible text or the control's accessible name supplies its label. */
function eablage_icon(string $name, string $class = ''): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<svg class="ea-icon' . ($class !== '' ? ' ' . $escape($class) : '') . '" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="icons.svg#' . $escape($name) . '"></use></svg>';
}
