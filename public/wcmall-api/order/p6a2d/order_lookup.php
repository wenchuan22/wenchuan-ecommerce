<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$orderNo = trim((string) ($_GET['order_no'] ?? ''));

if ($orderNo === '') {
    json_out(['ok' => false, 'bridge' => 'order-lookup', 'error' => 'parameter order_no is required'], 400);
}

try {
    $sql = "SELECT o.order_no, c.name AS customer_name, o.total_amount, o.status, o.created_at FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.order_no = '$orderNo'";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'order-lookup',
        'version' => 'legacy-2019.4',
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'order-lookup',
        'error' => $e->getMessage(),
        'query' => $sql,
    ], 500);
}
