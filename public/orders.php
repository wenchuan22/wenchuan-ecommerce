<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');
    $orderId = (int) input('order_id', '0');
    [$ok, $message] = apply_order_action($orderId, $action);
    flash($ok ? 'success' : 'error', $message);
    redirect($ok ? '/order_detail.php?id=' . $orderId : '/orders.php');
}

$keyword = q('q');
$statusFilter = q('status');
$paymentFilter = q('payment');
$channelFilter = q('channel');
$startDate = q('start');
$endDate = q('end');
$page = max(1, (int) q('page', '1'));

$statusOptions = ['待付款', '待发货', '已发货', '已完成', '已关闭', '退款中'];
$paymentOptions = ['未支付', '已支付', '已退款', '部分退款'];
$channelOptions = ['微信小程序', '天猫旗舰店', '抖音小店', '京东旗舰店', '线下门店'];

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(o.order_no LIKE ? OR o.receiver_name LIKE ? OR o.receiver_phone LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($statusFilter, $statusOptions, true)) {
    $where[] = 'o.status = ?';
    $params[] = $statusFilter;
}
if (in_array($paymentFilter, $paymentOptions, true)) {
    $where[] = 'o.payment_status = ?';
    $params[] = $paymentFilter;
}
if (in_array($channelFilter, $channelOptions, true)) {
    $where[] = 'o.sales_channel = ?';
    $params[] = $channelFilter;
}
$validDate = static function (string $value): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
};
if ($validDate($startDate)) {
    $where[] = 'substr(o.created_at, 1, 10) >= ?';
    $params[] = $startDate;
}
if ($validDate($endDate)) {
    $where[] = 'substr(o.created_at, 1, 10) <= ?';
    $params[] = $endDate;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM orders o' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 12, $page);

$listSql = 'SELECT o.*, c.name AS customer_name, c.level AS customer_level FROM orders o JOIN customers c ON c.id = o.customer_id' . $whereSql . ' ORDER BY o.created_at DESC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$orders = $listStmt->fetchAll();

$statusSummary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '待付款' THEN 1 ELSE 0 END) AS pending_pay, SUM(CASE WHEN status = '待发货' THEN 1 ELSE 0 END) AS pending_ship, SUM(CASE WHEN status = '已发货' THEN 1 ELSE 0 END) AS shipped, SUM(CASE WHEN status = '退款中' THEN 1 ELSE 0 END) AS refunding FROM orders")->fetch() ?: [];
$filteredAmount = 0.0;
if ($orders) {
    $amountStmt = $pdo->prepare('SELECT COALESCE(SUM(o.payable_amount), 0) FROM orders o' . $whereSql);
    $amountStmt->execute($params);
    $filteredAmount = (float) $amountStmt->fetchColumn();
}

$pageTitle = '订单管理';
$pageSubtitle = '统一处理全渠道订单、发货与状态流转';
$active = 'orders';
$pageActions = '<a class="btn btn-secondary" href="/refunds.php">退款售后</a><a class="btn btn-primary" href="/reports.php">导出经营报表</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>全部订单 <strong><?= (int) ($statusSummary['total'] ?? 0) ?></strong></span>
    <span>待付款 <strong><?= (int) ($statusSummary['pending_pay'] ?? 0) ?></strong></span>
    <span>待发货 <strong><?= (int) ($statusSummary['pending_ship'] ?? 0) ?></strong></span>
    <span>已发货 <strong><?= (int) ($statusSummary['shipped'] ?? 0) ?></strong></span>
    <span>退款中 <strong><?= (int) ($statusSummary['refunding'] ?? 0) ?></strong></span>
</section>

<section class="card">
    <form class="filter-bar" method="get" action="/orders.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">搜索订单</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="订单号 / 收件人 / 手机号">
            </div>
            <div class="filter-field">
                <label for="statusFilter">订单状态</label>
                <select id="statusFilter" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="paymentFilter">支付状态</label>
                <select id="paymentFilter" name="payment">
                    <option value="">全部</option>
                    <?php foreach ($paymentOptions as $option): ?>
                        <option value="<?= e($option) ?>" <?= $paymentFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="channelFilter">销售渠道</label>
                <select id="channelFilter" name="channel">
                    <option value="">全部渠道</option>
                    <?php foreach ($channelOptions as $option): ?>
                        <option value="<?= e($option) ?>" <?= $channelFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="start">开始日期</label>
                <input id="start" name="start" type="date" value="<?= e($startDate) ?>">
            </div>
            <div class="filter-field">
                <label for="end">结束日期</label>
                <input id="end" name="end" type="date" value="<?= e($endDate) ?>">
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/orders.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 笔订单，合计 ¥<?= money($filteredAmount) ?></div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>订单号</th>
                    <th>客户</th>
                    <th>成交金额</th>
                    <th>订单状态</th>
                    <th>支付</th>
                    <th>下单时间</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="7"><div class="data-empty"><div class="data-empty-mark">▦</div><strong>暂无匹配订单</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <a class="mono" href="/order_detail.php?id=<?= (int) $order['id'] ?>"><?= e($order['order_no']) ?></a>
                            <span class="cell-sub"><?= e($order['sales_channel']) ?> · <?= e($order['payment_method']) ?></span>
                        </td>
                        <td>
                            <span class="customer-cell">
                                <span class="customer-avatar"><?= e(initial_char($order['customer_name'])) ?></span>
                                <span class="customer-cell-text"><strong><?= e($order['customer_name']) ?></strong><span><?= e($order['customer_level']) ?>会员</span></span>
                            </span>
                        </td>
                        <td><span class="amount">¥<?= money($order['payable_amount']) ?></span><span class="cell-sub">原价 ¥<?= money($order['total_amount']) ?></span></td>
                        <td><span class="badge <?= status_class($order['status']) ?>"><?= e($order['status']) ?></span></td>
                        <td><span class="badge <?= status_class($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td>
                        <td class="muted nowrap"><?= e(fmt_month_day($order['created_at'])) ?></td>
                        <td class="text-right actions">
                            <a class="btn btn-ghost btn-sm" href="/order_detail.php?id=<?= (int) $order['id'] ?>">详情</a>
                            <?php if ($order['status'] === '待发货'): ?>
                                <form class="inline-form" method="post" action="/orders.php" data-confirm="确认为该订单发货？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="ship">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-orange btn-sm" type="submit">发货</button>
                                </form>
                            <?php elseif ($order['status'] === '已发货'): ?>
                                <form class="inline-form" method="post" action="/orders.php" data-confirm="确认订单已完成？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-secondary btn-sm" type="submit">确认完成</button>
                                </form>
                            <?php elseif ($order['status'] === '待付款'): ?>
                                <form class="inline-form" method="post" action="/orders.php" data-confirm="确认关闭该待付款订单？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="close">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">关闭</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/orders.php', ['q' => $keyword, 'status' => $statusFilter, 'payment' => $paymentFilter, 'channel' => $channelFilter, 'start' => $startDate, 'end' => $endDate]) ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
