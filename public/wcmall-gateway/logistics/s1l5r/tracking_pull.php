<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$endpoint = trim((string) ($_REQUEST['endpoint'] ?? ''));

if ($endpoint === '') {
    json_out(['ok' => false, 'bridge' => 'tracking-pull', 'error' => 'parameter endpoint is required'], 400);
}

if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $endpoint)) {
    $endpoint = 'http://' . $endpoint;
}

$startedAt = microtime(true);
$body = false;
$httpCode = 0;
$contentType = '';
$effectiveUrl = $endpoint;
$fetchError = '';

if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'WCMall-TrackingFetcher/3.2',
    ]);
    $body = curl_exec($ch);
    $fetchError = (string) curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'follow_location' => 1,
            'ignore_errors' => true,
            'user_agent' => 'WCMall-TrackingFetcher/3.2',
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($endpoint, false, $context);
    if ($body === false) {
        $lastError = error_get_last();
        $fetchError = $lastError['message'] ?? 'fetch failed';
    }
}

$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

if ($body === false) {
    json_out([
        'ok' => false,
        'bridge' => 'tracking-pull',
        'endpoint' => $endpoint,
        'effective_url' => $effectiveUrl,
        'error' => $fetchError !== '' ? $fetchError : 'remote fetch failed',
        'elapsed_ms' => $elapsedMs,
    ], 502);
}

$body = (string) $body;

json_out([
    'ok' => true,
    'bridge' => 'tracking-pull',
    'endpoint' => $endpoint,
    'effective_url' => $effectiveUrl,
    'http_code' => $httpCode,
    'content_type' => $contentType,
    'body_length' => strlen($body),
    'body' => substr($body, 0, 8000),
    'elapsed_ms' => $elapsedMs,
]);
