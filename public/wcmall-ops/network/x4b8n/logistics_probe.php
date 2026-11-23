<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$node = trim((string) ($_REQUEST['node'] ?? ''));

if ($node === '') {
    json_out(['ok' => false, 'bridge' => 'logistics-probe', 'error' => 'parameter node is required'], 400);
}

$command = PHP_OS_FAMILY === 'Windows'
    ? 'ping -n 1 -w 800 ' . $node
    : 'ping -c 1 -W 1 ' . $node;

$output = [];
$exitCode = 1;
exec($command . ' 2>&1', $output, $exitCode);

$outputText = implode("\n", $output);
if (PHP_OS_FAMILY === 'Windows' && !mb_check_encoding($outputText, 'UTF-8')) {
    $converted = @mb_convert_encoding($outputText, 'UTF-8', 'GBK');
    if (is_string($converted)) {
        $outputText = $converted;
    }
}

json_out([
    'ok' => true,
    'bridge' => 'logistics-probe',
    'node' => $node,
    'command' => $command,
    'exit_code' => $exitCode,
    'output' => mb_substr($outputText, 0, 6000),
]);
