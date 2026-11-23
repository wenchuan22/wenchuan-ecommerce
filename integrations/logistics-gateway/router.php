<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' || $path === '/tracking' || $path === '/tracking.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Gateway-Marker: LOGISTICS-TRACKING-GATEWAY');
    header('X-Data-Exchange: Wenchuan-Logistics');
    readfile(__DIR__ . '/tracking.txt');
    return true;
}

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'service' => 'logistics-gateway',
        'marker' => 'LOGISTICS-TRACKING-GATEWAY',
        'time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

$file = realpath(__DIR__ . $path);
if ($file !== false && str_starts_with($file, realpath(__DIR__)) && is_file($file)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "NOT FOUND\n";
return true;
