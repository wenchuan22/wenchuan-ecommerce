<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$phone = trim((string) ($_GET['phone'] ?? ''));

if ($phone === '') {
    json_out(['ok' => false, 'bridge' => 'member-summary', 'error' => 'parameter phone is required'], 400);
}

try {
    $sql = "SELECT c.name AS member_name, COUNT(o.id) AS order_count, COALESCE(SUM(o.payable_amount), 0) AS total_spent FROM customers c LEFT JOIN orders o ON o.customer_id = c.id WHERE c.phone = '$phone' GROUP BY c.id";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'member-summary',
        'version' => 'legacy-2019.4',
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'member-summary',
        'error' => $e->getMessage(),
        'query' => $sql,
    ], 500);
}
