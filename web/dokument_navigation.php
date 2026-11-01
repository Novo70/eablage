<?php
/** Only local application destinations are accepted as return links. */
function valid_return_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url) || str_contains($url, chr(92))) return 'dokumente.php';
    $parts = parse_url($url);
    if (
        $parts === false || isset($parts['scheme']) || isset($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
    ) return 'dokumente.php';
    $path = (string)($parts['path'] ?? '');
    if (!in_array($path, ['dokumente.php', 'verarbeiten.php', 'bearbeiten.php', 'ansehen.php', 'explorer.php', 'vorgaenge.php'], true)) return 'dokumente.php';
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
