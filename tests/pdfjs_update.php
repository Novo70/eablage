<?php
require_once __DIR__ . '/../web/pdfjs_update.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$release = static fn(string $tag, bool $prerelease = false): array => [
    'status' => 200,
    'body' => json_encode(['tag_name' => $tag, 'draft' => false, 'prerelease' => $prerelease]),
];
$upstream = 'https://api.github.com/repos/mozilla/pdf.js/releases/latest';
$public = 'https://api.github.com/repos/Novo70/eablage/releases/latest';
$osv = 'https://api.osv.dev/v1/query';
$parts = explode('.', EABLAGE_WEB_VERSION);
$parts[2] = (int) $parts[2] + 1;
$newRelease = 'v' . implode('.', $parts);
$source = "https://raw.githubusercontent.com/Novo70/eablage/$newRelease/web/vendor/pdfjs/build/pdf.mjs";
$responses = [
    $upstream => $release('v6.6.100'),
    $public => $release($newRelease),
    $source => ['status' => 200, 'body' => "/**\n * pdfjsVersion = 6.6.100\n */"],
    $osv => ['status' => 200, 'body' => '{"vulns":[{"id":"GHSA-example"},{"id":"GHSA-withdrawn","withdrawn":"2026-11-01"}]}'],
];
$request = static function (string $url, ?array $payload = null, int $limit = 524288) use (&$responses, $osv, $source): array {
    if ($url === $osv) check($payload === ['package' => ['ecosystem' => 'npm', 'name' => 'pdfjs-dist'], 'version' => '6.5.438'], 'Incorrect OSV query');
    if ($url === $source) check($limit === 8192, 'Source read must be bounded');
    if (!isset($responses[$url])) throw new RuntimeException('Unexpected service URL');
    return $responses[$url];
};
$result = eablage_pdfjs_check('6.5.438', $request);
check($result['upstream_newer'] && $result['eablage_update_available'], 'Expected both available releases');
check($result['advisories'] === ['GHSA-example'], 'Expected active advisory only');
$responses[$source]['body'] = "/**\n * pdfjsVersion = 6.5.438\n */";
check(eablage_pdfjs_check('6.5.438', $request)['eablage_update_available'], 'Newer eAblage release must be offered with the same PDF.js version');
$responses[$source] = ['status' => 404, 'body' => ''];
$responses[$public] = $release('v' . EABLAGE_WEB_VERSION);
$sameVersionSource = 'https://raw.githubusercontent.com/Novo70/eablage/v' . EABLAGE_WEB_VERSION . '/web/vendor/pdfjs/build/pdf.mjs';
$responses[$sameVersionSource] = $responses[$source];
$result = eablage_pdfjs_check('6.5.438', $request);
check($result['upstream_newer'] && !$result['eablage_update_available'] && $result['eablage_pdfjs'] === '', 'Upstream release alone must not advertise an eAblage update');
$responses[$public] = $release($newRelease);
$result = eablage_pdfjs_check(null, $request);
check($result['eablage_update_available'] && $result['advisories'] === null, 'Application check must work without installed PDF.js version');
$responses[$upstream] = $release('v6.6.100', true);
$responses[$osv] = ['status' => 200, 'body' => '{"error":"invalid response"}'];
$result = eablage_pdfjs_check('6.5.438', $request);
check($result['upstream'] === null && $result['advisories'] === null, 'Unavailable check must remain distinct from a negative result');
$responses[$osv] = ['status' => 200, 'body' => '{}'];
unset($responses[$public], $responses[$source]);
check(eablage_pdfjs_check('6.5.438', $request, false)['advisories'] === [], 'Repository check should only contact Mozilla and OSV');
check(eablage_pdfjs_release_tag($release('../bad')) === null, 'Unsafe release tag accepted');
echo "PDF.js update check: PASS\n";
