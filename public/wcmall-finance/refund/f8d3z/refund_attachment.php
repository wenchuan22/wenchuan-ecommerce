<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out([
        'ok' => false,
        'bridge' => 'refund-attachment',
        'error' => 'POST multipart field "attachment" is required',
    ], 405);
}

$file = $_FILES['attachment'] ?? null;
if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    $code = is_array($file) ? (int) ($file['error'] ?? -1) : -1;
    json_out([
        'ok' => false,
        'bridge' => 'refund-attachment',
        'error' => 'upload failed',
        'upload_error_code' => $code,
    ], 400);
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$clientType = (string) ($file['type'] ?? '');
if (!in_array($clientType, $allowedTypes, true)) {
    json_out([
        'ok' => false,
        'bridge' => 'refund-attachment',
        'error' => 'unsupported attachment type',
        'allowed_types' => $allowedTypes,
    ], 415);
}

if ((int) $file['size'] > 20 * 1024 * 1024) {
    json_out(['ok' => false, 'bridge' => 'refund-attachment', 'error' => 'file too large'], 413);
}

$targetDir = UPLOAD_BASE . '/refunds';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$originalName = basename((string) ($file['name'] ?? ''));
if ($originalName === '') {
    $originalName = 'refund_attachment_' . time() . '.bin';
}
$targetPath = $targetDir . '/' . $originalName;

if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
    json_out(['ok' => false, 'bridge' => 'refund-attachment', 'error' => 'cannot persist uploaded file'], 500);
}

json_out([
    'ok' => true,
    'bridge' => 'refund-attachment',
    'file' => $originalName,
    'size' => (int) $file['size'],
    'content_type' => $clientType,
    'url' => '/wc-uploads/refunds/' . rawurlencode($originalName),
]);
