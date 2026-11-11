<?php

require_once __DIR__ . '/release.php';

function eablage_pdfjs_version(string $source): ?string
{
    return preg_match('/^\s*\* pdfjsVersion = (\d+\.\d+\.\d+)\s*$/m', $source, $match)
        ? $match[1] : null;
}

function eablage_pdfjs_installed_version(): ?string
{
    $source = @file_get_contents(__DIR__ . '/vendor/pdfjs/build/pdf.mjs', false, null, 0, 8192);
    return is_string($source) ? eablage_pdfjs_version($source) : null;
}

/** Only called with fixed service URLs or a validated release tag. */
function eablage_pdfjs_http(string $url, ?array $payload = null, int $limit = 524288): array
{
    $options = [
        'method' => $payload === null ? 'GET' : 'POST',
        'header' => "Accept: application/json\r\nUser-Agent: eAblage-PDFjs-Updatecheck\r\n",
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 4,
    ];
    if ($payload !== null) {
        $options['header'] .= "Content-Type: application/json\r\n";
        $options['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $options]), 0, $limit);
    preg_match('/^HTTP\/\S+ (\d{3})\b/', $http_response_header[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => is_string($body) ? $body : ''];
}

function eablage_pdfjs_release_tag(array $response): ?string
{
    if ($response['status'] !== 200) return null;
    $release = json_decode($response['body']);
    $tag = $release->tag_name ?? null;
    return is_string($tag) && preg_match('/^v?\d+\.\d+\.\d+$/D', $tag)
        && ($release->draft ?? true) === false && ($release->prerelease ?? true) === false ? $tag : null;
}

function eablage_pdfjs_advisories(array $response): ?array
{
    if ($response['status'] !== 200) return null;
    $data = json_decode($response['body']);
    if (!is_object($data) || array_diff(array_keys(get_object_vars($data)), ['vulns', 'next_page_token'])
        || (property_exists($data, 'vulns') && !is_array($data->vulns))) return null;
    $ids = [];
    foreach ($data->vulns ?? [] as $advisory) {
        if (!empty($advisory->withdrawn)) continue;
        $id = $advisory->id ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) return null;
        $ids[] = $id;
    }
    if (!$ids && !empty($data->next_page_token)) return null;
    return array_values(array_unique($ids));
}

function eablage_pdfjs_check(?string $installed, ?callable $request = null, bool $includeEablage = true): array
{
    $request ??= 'eablage_pdfjs_http';
    $tag = eablage_pdfjs_release_tag($request('https://api.github.com/repos/mozilla/pdf.js/releases/latest'));
    $advisories = $installed === null ? null : eablage_pdfjs_advisories($request('https://api.osv.dev/v1/query', [
        'package' => ['ecosystem' => 'npm', 'name' => 'pdfjs-dist'],
        'version' => $installed,
    ]));
    $result = [
        'installed' => $installed,
        'upstream' => $tag === null ? null : ltrim($tag, 'v'),
        'advisories' => $advisories,
        'eablage_installed' => EABLAGE_WEB_VERSION,
        'eablage_release' => null,
        'eablage_pdfjs' => null,
    ];
    if ($includeEablage) {
        $release = eablage_pdfjs_release_tag($request('https://api.github.com/repos/Novo70/eablage/releases/latest'));
        if ($release !== null) {
            $source = $request("https://raw.githubusercontent.com/Novo70/eablage/$release/web/vendor/pdfjs/build/pdf.mjs", null, 8192);
            $result['eablage_release'] = $release;
            if ($source['status'] === 200) $result['eablage_pdfjs'] = eablage_pdfjs_version($source['body']);
            if ($source['status'] === 404) $result['eablage_pdfjs'] = '';
        }
    }
    $result['upstream_newer'] = $installed !== null && $result['upstream'] !== null && version_compare($result['upstream'], $installed, '>');
    $result['eablage_update_available'] = $result['eablage_release'] !== null
        && version_compare(ltrim($result['eablage_release'], 'v'), EABLAGE_WEB_VERSION, '>');
    return $result;
}
