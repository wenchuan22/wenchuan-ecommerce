<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$sku = trim((string) ($_GET['sku'] ?? ''));

if ($sku === '') {
    json_out(['ok' => false, 'bridge' => 'product-trace', 'error' => 'parameter sku is required'], 400);
}

try {
    $sql = "SELECT p.sku, p.name, p.stock, p.sale_price FROM products p WHERE p.sku = '$sku'";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'product-trace',
        'version' => 'legacy-2019.4',
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'product-trace',
        'error' => $e->getMessage(),
        'query' => $sql,
    ], 500);
}
