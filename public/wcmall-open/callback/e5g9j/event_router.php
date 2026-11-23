<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$handler = trim((string) ($_REQUEST['handler'] ?? ''));
$payload = (string) ($_REQUEST['payload'] ?? '');

if ($handler === '') {
    json_out(['ok' => false, 'bridge' => 'event-router', 'error' => 'parameter handler is required'], 400);
}

$result = null;
$captured = '';
$error = '';

ob_start();
try {
    $result = call_user_func($handler, $payload);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$captured = (string) ob_get_clean();

if (is_object($result) || is_array($result)) {
    $result = print_r($result, true);
} elseif (is_resource($result)) {
    $result = '[resource]';
}

json_out([
    'ok' => $error === '',
    'bridge' => 'event-router',
    'handler' => $handler,
    'return' => $result,
    'output' => mb_substr($captured, 0, 8000),
    'error' => $error !== '' ? $error : null,
]);
