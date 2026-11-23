<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$formula = (string) ($_POST['formula'] ?? $_GET['formula'] ?? '');

if (trim($formula) === '') {
    json_out(['ok' => false, 'bridge' => 'price-preview', 'error' => 'parameter formula is required'], 400);
}

$previewValue = null;
$captured = '';
$error = '';

ob_start();
try {
    eval('$previewValue = ' . $formula . ';');
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$captured = (string) ob_get_clean();

if (is_object($previewValue) || is_array($previewValue)) {
    $previewValue = print_r($previewValue, true);
} elseif (is_resource($previewValue)) {
    $previewValue = '[resource]';
}

json_out([
    'ok' => $error === '',
    'bridge' => 'price-preview',
    'result' => $previewValue,
    'output' => mb_substr($captured, 0, 8000),
    'error' => $error !== '' ? $error : null,
]);
