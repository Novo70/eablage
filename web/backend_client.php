<?php
/** Shared JSON transport. No automatic retries for potentially writing requests. */
function eablage_backend_url(): string
{
    return rtrim(trim((string)getenv('BACKEND_URL')), '/');
}

function eablage_backend_request(string $method, string $endpoint, ?array $payload = null, int $timeout = 5, int $jsonFlags = 0): array
{
    $result = ['ok'=>false, 'data'=>null, 'error'=>'', 'http_status'=>null];
    $baseUrl = eablage_backend_url();
    if ($baseUrl === '') {
        $result['error'] = 'Das Python-Backend ist nicht konfiguriert.';
        return $result;
    }
    $options = ['method'=>$method, 'header'=>"Accept: application/json\r\n", 'timeout'=>max(1, $timeout),
        'ignore_errors'=>true, 'follow_location'=>0];
    if ($payload !== null) {
        try {
            $options['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | $jsonFlags);
        } catch (JsonException $e) {
            $result['error'] = 'Die Backend-Anfrage konnte nicht als gültiges JSON erzeugt werden.';
            return $result;
        }
        $options['header'] .= "Content-Type: application/json\r\n";
    }
    $raw = @file_get_contents($baseUrl . '/' . ltrim($endpoint, '/'), false, stream_context_create(['http'=>$options]));
    if (preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match)) $result['http_status'] = (int)$match[1];
    if ($raw === false) {
        $result['error'] = 'Das Python-Backend ist nicht erreichbar oder das Zeitlimit wurde überschritten.';
        return $result;
    }
    $data = json_decode($raw, true);
    $result['data'] = is_array($data) ? $data : null;
    if ($result['http_status'] === null || $result['http_status'] < 200 || $result['http_status'] >= 300) {
        $detail = is_array($data) ? ($data['detail'] ?? '') : '';
        if (is_array($detail)) $detail = $detail['message'] ?? '';
        $result['error'] = 'Das Python-Backend meldet einen HTTP-Fehler (' . ($result['http_status'] ?? 'unbekannt') . ').'
            . (is_string($detail) && $detail !== '' ? ' ' . $detail : '');
        return $result;
    }
    if (!is_array($data)) {
        $result['error'] = 'Das Python-Backend hat keine gültige JSON-Antwort geliefert.';
        return $result;
    }
    $result['ok'] = true;
    return $result;
}
