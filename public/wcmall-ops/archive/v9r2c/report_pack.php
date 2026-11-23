<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$bizDate = trim((string) ($_REQUEST['biz_date'] ?? ''));

if ($bizDate === '') {
    json_out(['ok' => false, 'bridge' => 'report-pack', 'error' => 'parameter biz_date is required'], 400);
}

$archiveDir = STORAGE_PATH . '/archives';
$reportDir = STORAGE_PATH . '/reports';
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0777, true);
}
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}

$tarBinary = PHP_OS_FAMILY === 'Windows' ? 'C:\Windows\System32\tar.exe' : 'tar';
$archivePath = $archiveDir . '/report-pack-' . $bizDate . '.tar';
$command = $tarBinary . ' -cf ' . $archivePath . ' -C ' . $reportDir . ' .';

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
    'bridge' => 'report-pack',
    'biz_date' => $bizDate,
    'command' => $command,
    'archive' => $archivePath,
    'exit_code' => $exitCode,
    'output' => mb_substr($outputText, 0, 6000),
]);
