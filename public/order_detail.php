<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$orderId = (int) q('id', '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$ok, $message] = apply_order_action((int) input('order_id', '0'), input('action'));
    flash($ok ? 'success' : 'error', $message);
    redirect('/order_detail.php?id=' . $orderId);
}

$stmt = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.member_no, c.phone AS customer_phone, c.email, c.level, c.city, c.registered_at, c.total_orders, c.total_spent FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageTitle = '订单不存在';
    $pageSubtitle = '';
    $active = 'orders';
    require __DIR__ . '/partials/header.php';
    echo '<section class="card"><div class="data-empty"><div class="data-empty-mark">▦</div><strong>未找到该订单</strong>订单可能已被删除，或链接中的编号不正确。<div style="margin-top:16px;"><a class="btn btn-secondary" href="/orders.php">返回订单列表</a></div></div></section>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$itemStmt = $pdo->prepare('SELECT oi.*, p.category_id FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id');
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

$refundStmt = $pdo->prepare('SELECT * FROM refunds WHERE order_id = ? ORDER BY applied_at DESC');
$refundStmt->execute([$orderId]);
$refunds = $refundStmt->fetchAll();

$paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY paid_at DESC');
$paymentStmt->execute([$orderId]);
$payments = $paymentStmt->fetchAll();

$auditStmt = $pdo->prepare('SELECT * FROM audit_logs WHERE target = ? ORDER BY created_at DESC LIMIT 6');
$auditStmt->execute([$order['order_no']]);
$auditRows = $auditStmt->fetchAll();

$timeline = [
    ['订单创建', $order['created_at'], true, '客户通过 ' . $order['sales_channel'] . ' 提交订单'],
    ['支付完成', $order['paid_at'], $order['paid_at'] !== null, $order['payment_method'] ?: '—'],
    ['商品出库', $order['shipped_at'], $order['shipped_at'] !== null, $order['logistics_company'] ? $order['logistics_company'] . ' ' . $order['logistics_no'] : '待安排出库'],
    ['交易完成', $order['completed_at'], $order['completed_at'] !== null, $order['completed_at'] ? '客户已确认收货' : '等待签收'],
];

$pageTitle = '订单详情';
$pageSubtitle = $order['order_no'] . ' · ' . $order['sales_channel'];
$active = 'orders';

$pageActions = '<a class="btn btn-secondary" href="/orders.php">返回订单列表</a>';
if ($order['status'] === '待发货') {
    $pageActions .= '<form class="inline-form" method="post" action="/order_detail.php?id=' . $orderId . '" data-confirm="确认为该订单发货？">' . csrf_field() . '<input type="hidden" name="action" value="ship"><input type="hidden" name="order_id" value="' . $orderId . '"><button class="btn btn-orange" type="submit">确认发货</button></form>';
} elseif ($order['status'] === '已发货') {
    $pageActions .= '<form class="inline-form" method="post" action="/order_detail.php?id=' . $orderId . '" data-confirm="确认订单已完成？">' . csrf_field() . '<input type="hidden" name="action" value="complete"><input type="hidden" name="order_id" value="' . $orderId . '"><button class="btn btn-primary" type="submit">确认完成</button></form>';
} elseif ($order['status'] === '待付款') {
    $pageActions .= '<form class="inline-form" method="post" action="/order_detail.php?id=' . $orderId . '" data-confirm="确认关闭该待付款订单？">' . csrf_field() . '<input type="hidden" name="action" value="close"><input type="hidden" name="order_id" value="' . $orderId . '"><button class="btn btn-ghost" type="submit">关闭订单</button></form>';
}

require __DIR__ . '/partials/header.php';
?>

<div class="detail-head">
    <div>
        <div class="detail-title">
            <h2><?= e($order['order_no']) ?></h2>
            <span class="badge <?= status_class($order['status']) ?>"><?= e($order['status']) ?></span>
            <span class="badge <?= status_class($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span>
        </div>
        <div class="detail-meta">
            <span>下单时间 <?= e(fmt_datetime($order['created_at'])) ?></span>
            <span>销售渠道 <?= e($order['sales_channel']) ?></span>
            <span>支付方式 <?= e($order['payment_method']) ?></span>
            <span>客户会员号 <?= e($order['member_no']) ?></span>
        </div>
    </div>
</div>

<div class="detail-grid">
    <div class="stack">
        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">商品明细</div>
                    <div class="card-subtitle">共 <?= count($items) ?> 个商品行，<?= array_sum(array_map(static fn(array $item): int => (int) $item['qty'], $items)) ?> 件商品</div>
                </div>
            </div>
            <div class="table-wrap" style="margin-top: 14px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>商品</th>
                            <th>单价</th>
                            <th class="text-center">数量</th>
                            <th class="text-right">小计</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div class="product-cell">
                                        <span class="product-avatar <?= product_tone((int) $item['product_id']) ?>"><?= e(initial_char($item['product_name'])) ?></span>
                                        <span class="product-cell-text">
                                            <strong><?= e($item['product_name']) ?></strong>
                                            <span><?= e($item['sku']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td>¥<?= money($item['unit_price']) ?></td>
                                <td class="text-center">× <?= (int) $item['qty'] ?></td>
                                <td class="text-right amount">¥<?= money($item['subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="order-summary">
                <div class="summary-row"><span>商品总额</span><strong>¥<?= money($order['total_amount']) ?></strong></div>
                <div class="summary-row"><span>优惠减免</span><strong>- ¥<?= money($order['discount_amount']) ?></strong></div>
                <div class="summary-row"><span>运费</span><strong>¥<?= money($order['shipping_fee']) ?></strong></div>
                <div class="summary-row summary-total"><span>实付金额</span><strong>¥<?= money($order['payable_amount']) ?></strong></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">收货与物流</div>
                    <div class="card-subtitle">收件信息与履约记录</div>
                </div>
            </div>
            <div class="card-body">
                <div class="kv-list">
                    <div class="kv-row"><span class="kv-label">收件人</span><span class="kv-value strong"><?= e($order['receiver_name']) ?> · <?= e($order['receiver_phone']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">收货地址</span><span class="kv-value"><?= e($order['receiver_address']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">物流公司</span><span class="kv-value"><?= e($order['logistics_company'] ?: '尚未安排') ?></span></div>
                    <div class="kv-row"><span class="kv-label">物流单号</span><span class="kv-value mono"><?= e($order['logistics_no'] ?: '—') ?></span></div>
                    <div class="kv-row"><span class="kv-label">客户备注</span><span class="kv-value"><?= e($order['remark'] ?: '无') ?></span></div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">售后记录</div>
                    <div class="card-subtitle">该订单关联的退款与售后申请</div>
                </div>
                <a class="btn-link" href="/refunds.php?q=<?= rawurlencode($order['order_no']) ?>">全部售后</a>
            </div>
            <div class="table-wrap" style="margin-top: 12px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>退款单号</th>
                            <th>申请金额</th>
                            <th>原因</th>
                            <th>状态</th>
                            <th>申请时间</th>
                            <th class="text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$refunds): ?>
                            <tr><td colspan="6"><div class="data-empty" style="padding:26px 10px;"><strong>暂无售后记录</strong>该订单没有发起退款申请。</div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($refunds as $refund): ?>
                            <tr>
                                <td class="mono"><?= e($refund['refund_no']) ?></td>
                                <td class="amount">¥<?= money($refund['amount']) ?></td>
                                <td><?= e($refund['reason']) ?><span class="cell-sub">处理人：<?= e($refund['handler'] ?: '待分配') ?></span></td>
                                <td><span class="badge <?= status_class($refund['status']) ?>"><?= e($refund['status']) ?></span></td>
                                <td class="muted nowrap"><?= e(fmt_month_day($refund['applied_at'])) ?></td>
                                <td class="text-right"><a class="btn btn-ghost btn-sm" href="/refunds.php?q=<?= rawurlencode($refund['refund_no']) ?>">查看</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">客户信息</div>
                    <div class="card-subtitle">下单会员资料</div>
                </div>
                <a class="btn-link" href="/customers.php?id=<?= (int) $order['customer_id'] ?>">客户档案</a>
            </div>
            <div class="card-body">
                <span class="customer-cell" style="margin-bottom: 16px;">
                    <span class="customer-avatar" style="width:44px;height:44px;flex-basis:44px;font-size:17px;"><?= e(initial_char($order['customer_name'])) ?></span>
                    <span class="customer-cell-text">
                        <strong style="font-size:15px;"><?= e($order['customer_name']) ?></strong>
                        <span><?= e($order['member_no']) ?> · <?= e($order['level']) ?>会员</span>
                    </span>
                </span>
                <div class="kv-list">
                    <div class="kv-row"><span class="kv-label">手机号</span><span class="kv-value"><?= e($order['customer_phone']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">所在城市</span><span class="kv-value"><?= e($order['city']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">历史订单</span><span class="kv-value"><?= (int) $order['total_orders'] ?> 笔</span></div>
                    <div class="kv-row"><span class="kv-label">累计消费</span><span class="kv-value strong">¥<?= money($order['total_spent']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">注册时间</span><span class="kv-value"><?= e(fmt_date($order['registered_at'])) ?></span></div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">订单进度</div>
                    <div class="card-subtitle">关键履约节点</div>
                </div>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <?php foreach ($timeline as [$title, $time, $done, $note]): ?>
                        <div class="timeline-item <?= $done ? 'is-done' : '' ?>">
                            <span class="timeline-dot"></span>
                            <div class="timeline-content">
                                <strong><?= e($title) ?></strong>
                                <span><?= $time ? e(fmt_datetime($time)) : '待处理' ?> · <?= e($note) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">支付记录</div>
                    <div class="card-subtitle">渠道收款与结算状态</div>
                </div>
            </div>
            <div class="table-wrap" style="margin-top: 10px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>支付单号</th>
                            <th>金额</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$payments): ?>
                            <tr><td colspan="3"><div class="data-empty" style="padding:22px 10px;"><strong>暂无支付记录</strong>订单尚未支付。</div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="mono"><?= e($payment['payment_no']) ?><span class="cell-sub"><?= e($payment['channel']) ?> · <?= e(fmt_month_day($payment['paid_at'])) ?></span></td>
                                <td class="amount">¥<?= money($payment['amount']) ?></td>
                                <td><span class="badge <?= status_class($payment['status']) ?>"><?= e($payment['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">操作记录</div>
                    <div class="card-subtitle">该订单近期操作审计</div>
                </div>
            </div>
            <div class="feed">
                <?php if (!$auditRows): ?>
                    <p class="muted small">暂无操作记录。</p>
                <?php endif; ?>
                <?php foreach ($auditRows as $log): ?>
                    <div class="feed-item">
                        <span class="feed-dot"><?= e(initial_char($log['username'])) ?></span>
                        <div class="feed-body">
                            <strong><?= e($log['action']) ?></strong>
                            <p><?= e($log['detail']) ?></p>
                        </div>
                        <span class="feed-time"><?= e(fmt_month_day($log['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
