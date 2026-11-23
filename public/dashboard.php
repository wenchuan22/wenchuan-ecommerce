<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$monthStart = date('Y-m-01');
$chartStart = date('Y-m-d', strtotime('-6 days'));
$chartEnd = date('Y-m-d', strtotime('+1 day'));

$todayStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, SUM(CASE WHEN payment_status = '已支付' THEN 1 ELSE 0 END) AS paid_cnt, COALESCE(SUM(CASE WHEN payment_status = '已支付' THEN payable_amount ELSE 0 END), 0) AS revenue FROM orders WHERE substr(created_at, 1, 10) = ?");
$todayStmt->execute([$today]);
$todayStats = $todayStmt->fetch() ?: ['cnt' => 0, 'paid_cnt' => 0, 'revenue' => 0];

$yesterdayStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_status = '已支付' THEN payable_amount ELSE 0 END), 0) AS revenue FROM orders WHERE substr(created_at, 1, 10) = ?");
$yesterdayStmt->execute([$yesterday]);
$yesterdayRevenue = (float) (($yesterdayStmt->fetch() ?: [])['revenue'] ?? 0);

$pendingShip = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = '待发货'")->fetchColumn();
$pendingRefund = (int) $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = '待审核'")->fetchColumn();
$productStats = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '在售' THEN 1 ELSE 0 END) AS on_sale, SUM(CASE WHEN status = '在售' AND stock <= safety_stock THEN 1 ELSE 0 END) AS low_stock FROM products")->fetch() ?: [];
$customerCount = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

$monthStmt = $pdo->prepare("SELECT COALESCE(SUM(payable_amount), 0) AS revenue, COUNT(*) AS cnt FROM orders WHERE payment_status = '已支付' AND paid_at >= ?");
$monthStmt->execute([$monthStart . ' 00:00:00']);
$monthStats = $monthStmt->fetch() ?: ['revenue' => 0, 'cnt' => 0];

$trendPct = null;
if ($yesterdayRevenue > 0) {
    $trendPct = (($todayStats['revenue'] - $yesterdayRevenue) / $yesterdayRevenue) * 100;
}

$chartStmt = $pdo->prepare("SELECT substr(paid_at, 1, 10) AS d, COALESCE(SUM(payable_amount), 0) AS amount FROM orders WHERE payment_status = '已支付' AND paid_at >= ? AND paid_at < ? GROUP BY substr(paid_at, 1, 10)");
$chartStmt->execute([$chartStart . ' 00:00:00', $chartEnd . ' 00:00:00']);
$salesByDay = [];
foreach ($chartStmt->fetchAll() as $row) {
    $salesByDay[$row['d']] = (float) $row['amount'];
}
$chartDays = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $chartDays[] = [
        'date' => $day,
        'label' => date('m-d', strtotime($day)),
        'weekday' => '周' . ['日', '一', '二', '三', '四', '五', '六'][(int) date('w', strtotime($day))],
        'amount' => $salesByDay[$day] ?? 0,
        'today' => $day === $today,
    ];
}
$chartMax = max(1.0, max(array_column($chartDays, 'amount')));
$chartTotal = array_sum(array_column($chartDays, 'amount'));

$channelStmt = $pdo->prepare("SELECT sales_channel AS channel, SUM(payable_amount) AS amount, COUNT(*) AS cnt FROM orders WHERE payment_status = '已支付' AND paid_at >= ? GROUP BY sales_channel ORDER BY amount DESC");
$channelStmt->execute([$monthStart . ' 00:00:00']);
$channels = $channelStmt->fetchAll();
$channelTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $channels));
$channelMax = max(1.0, max(array_merge([0], array_map(static fn(array $row): float => (float) $row['amount'], $channels))));

$recentOrders = $pdo->query("SELECT o.id, o.order_no, o.payable_amount, o.status, o.payment_status, o.sales_channel, o.created_at, c.name AS customer_name, c.level FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.created_at DESC LIMIT 6")->fetchAll();
$lowStock = $pdo->query("SELECT id, sku, name, stock, safety_stock, category_id FROM products WHERE status = '在售' AND stock <= safety_stock ORDER BY (CAST(stock AS REAL) / CASE WHEN safety_stock = 0 THEN 1 ELSE safety_stock END) ASC, stock ASC LIMIT 5")->fetchAll();
$pendingRefunds = $pdo->query("SELECT r.id, r.refund_no, r.amount, r.reason, r.applied_at, o.order_no, c.name AS customer_name FROM refunds r JOIN orders o ON o.id = r.order_id JOIN customers c ON c.id = r.customer_id WHERE r.status = '待审核' ORDER BY r.applied_at DESC LIMIT 4")->fetchAll();
$topProductsStmt = $pdo->prepare("SELECT oi.product_id, oi.product_name, oi.sku, SUM(oi.qty) AS qty, SUM(oi.subtotal) AS amount FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.payment_status = '已支付' AND o.paid_at >= ? GROUP BY oi.product_id, oi.product_name, oi.sku ORDER BY amount DESC LIMIT 5");
$topProductsStmt->execute([date('Y-m-d', strtotime('-30 days')) . ' 00:00:00']);
$topProducts = $topProductsStmt->fetchAll();
$auditRows = $pdo->query('SELECT action, target, detail, username, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 5')->fetchAll();

$pageTitle = '工作台';
$pageSubtitle = '全渠道经营概览 · 数据截至 ' . date('Y-m-d H:i');
$active = 'dashboard';
$pageActions = '<a class="btn btn-secondary" href="/reports.php">查看经营报表</a><a class="btn btn-primary" href="/products.php">+ 新增商品</a>';
require __DIR__ . '/partials/header.php';
?>

<div class="quick-bar">
    <span class="quick-bar-label">快捷操作</span>
    <a class="quick-action" href="/orders.php?status=待发货"><span>▦</span> 处理待发货</a>
    <a class="quick-action" href="/refunds.php?status=待审核"><span>↺</span> 审核退款</a>
    <a class="quick-action" href="/inventory.php?low=1"><span>▤</span> 查看库存预警</a>
    <a class="quick-action" href="/promotions.php"><span>◈</span> 配置营销活动</a>
    <a class="quick-action" href="/finance.php"><span>▥</span> 核对渠道账单</a>
</div>

<section class="kpi-grid">
    <article class="kpi-card accent">
        <div class="kpi-top">
            <span class="kpi-label">今日成交额</span>
            <span class="kpi-icon">¥</span>
        </div>
        <div class="kpi-value"><span class="unit">¥</span><?= money($todayStats['revenue']) ?></div>
        <div class="kpi-foot">
            <?php if ($trendPct === null): ?>
                <span class="trend-flat">—</span> 较昨日
            <?php else: ?>
                <span class="<?= $trendPct >= 0 ? 'trend-up' : 'trend-down' ?>"><?= $trendPct >= 0 ? '↑' : '↓' ?> <?= number_format(abs($trendPct), 1) ?>%</span> 较昨日
            <?php endif; ?>
        </div>
    </article>

    <article class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">今日订单</span>
            <span class="kpi-icon">▦</span>
        </div>
        <div class="kpi-value"><?= (int) $todayStats['cnt'] ?><span class="unit"> 笔</span></div>
        <div class="kpi-foot">已支付 <?= (int) $todayStats['paid_cnt'] ?> 笔 · 待转化 <?= max(0, (int) $todayStats['cnt'] - (int) $todayStats['paid_cnt']) ?> 笔</div>
    </article>

    <article class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">待发货订单</span>
            <span class="kpi-icon">↗</span>
        </div>
        <div class="kpi-value"><?= $pendingShip ?><span class="unit"> 单</span></div>
        <div class="kpi-foot">今日 18:00 前完成出库</div>
    </article>

    <article class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">待处理售后</span>
            <span class="kpi-icon">↺</span>
        </div>
        <div class="kpi-value"><?= $pendingRefund ?><span class="unit"> 单</span></div>
        <div class="kpi-foot">建议 24 小时内完成首次响应</div>
    </article>
</section>

<section class="summary-strip">
    <span>本月成交 <strong>¥<?= money($monthStats['revenue']) ?></strong></span>
    <span>本月支付订单 <strong><?= (int) $monthStats['cnt'] ?> 笔</strong></span>
    <span>在售商品 <strong><?= (int) ($productStats['on_sale'] ?? 0) ?> / <?= (int) ($productStats['total'] ?? 0) ?></strong></span>
    <span>会员总数 <strong><?= $customerCount ?></strong></span>
    <span>库存预警 <strong><?= (int) ($productStats['low_stock'] ?? 0) ?> 款</strong></span>
</section>

<div class="dashboard-main">
    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">近 7 日成交趋势</div>
                <div class="card-subtitle">按支付时间统计，含各渠道已支付订单</div>
            </div>
            <div class="card-actions">
                <div class="chart-head-meta">
                    <span>7 日合计<strong>¥<?= money($chartTotal) ?></strong></span>
                    <span>日均<strong>¥<?= money($chartTotal / 7) ?></strong></span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="chart">
                <?php foreach ($chartDays as $day): ?>
                    <?php
                    $height = $day['amount'] > 0 ? max(5, round(($day['amount'] / $chartMax) * 100, 1)) : 2;
                    $classes = 'chart-col' . ($day['today'] ? ' is-today' : '') . ($day['amount'] <= 0 ? ' is-zero' : '');
                    ?>
                    <div class="<?= $classes ?>" title="<?= e($day['date']) ?> 成交 ¥<?= money($day['amount']) ?>">
                        <span class="chart-value">¥<?= money($day['amount']) ?></span>
                        <div class="chart-bar" style="height: <?= $height ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-labels">
                <?php foreach ($chartDays as $day): ?>
                    <div class="chart-label"><?= e($day['label']) ?><br><span class="muted"><?= e($day['weekday']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="legend" style="margin-top: 14px;">
                <span><i></i> 已支付成交额</span>
                <span><i class="alt"></i> 今日高亮</span>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">渠道成交占比</div>
                <div class="card-subtitle">本月各销售渠道成交情况</div>
            </div>
            <a class="btn-link" href="/reports.php">明细</a>
        </div>
        <div class="card-body">
            <div class="channel-list">
                <?php if (!$channels): ?>
                    <p class="muted small">本月暂无成交数据。</p>
                <?php endif; ?>
                <?php foreach ($channels as $channel): ?>
                    <div class="channel-row">
                        <span class="channel-name" title="<?= e($channel['channel']) ?>"><?= e($channel['channel']) ?></span>
                        <div class="channel-track">
                            <div class="channel-fill" style="width: <?= max(3, round(((float) $channel['amount'] / $channelMax) * 100, 1)) ?>%"></div>
                        </div>
                        <span class="channel-amount">¥<?= money($channel['amount']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="channel-total">
                <span>本月合计 · <?= (int) $monthStats['cnt'] ?> 笔订单</span>
                <strong>¥<?= money($channelTotal) ?></strong>
            </div>
        </div>
    </section>
</div>

<div class="dashboard-bottom">
    <section class="card recent-orders">
        <div class="card-head">
            <div>
                <div class="card-title">最新订单</div>
                <div class="card-subtitle">按创建时间倒序展示最近 6 笔订单</div>
            </div>
            <a class="btn-link" href="/orders.php">全部订单</a>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>订单号</th>
                        <th>客户</th>
                        <th>金额</th>
                        <th>状态</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><a class="mono" href="/order_detail.php?id=<?= (int) $order['id'] ?>"><?= e($order['order_no']) ?></a><span class="cell-sub"><?= e($order['sales_channel']) ?></span></td>
                            <td>
                                <span class="customer-cell">
                                    <span class="customer-avatar"><?= e(initial_char($order['customer_name'])) ?></span>
                                    <span class="customer-cell-text"><strong><?= e($order['customer_name']) ?></strong><span><?= e($order['level']) ?></span></span>
                                </span>
                            </td>
                            <td class="amount">¥<?= money($order['payable_amount']) ?></td>
                            <td><span class="badge <?= status_class($order['status']) ?>"><?= e($order['status']) ?></span></td>
                            <td class="muted nowrap"><?= e(fmt_month_day($order['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-foot">
            <span>数据实时更新</span>
            <a class="btn-link" href="/orders.php">进入订单中心</a>
        </div>
    </section>

    <div class="stack">
        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">库存预警</div>
                    <div class="card-subtitle">低于安全库存的在售商品</div>
                </div>
                <a class="btn-link" href="/inventory.php?low=1">管理库存</a>
            </div>
            <div class="alert-list">
                <?php if (!$lowStock): ?>
                    <p class="muted small">当前库存状态良好。</p>
                <?php endif; ?>
                <?php foreach ($lowStock as $product): ?>
                    <a class="stock-alert" href="/inventory.php?low=1&amp;q=<?= rawurlencode($product['sku']) ?>">
                        <span class="product-avatar <?= product_tone((int) $product['id']) ?>"><?= e(initial_char($product['name'])) ?></span>
                        <span class="stock-alert-text">
                            <strong><?= e($product['name']) ?></strong>
                            <span class="mono"><?= e($product['sku']) ?></span>
                        </span>
                        <span class="stock-alert-value">
                            <strong><?= (int) $product['stock'] ?></strong>
                            <span>安全 <?= (int) $product['safety_stock'] ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">待处理售后</div>
                    <div class="card-subtitle">等待运营审核的退款申请</div>
                </div>
                <a class="btn-link" href="/refunds.php?status=待审核">处理</a>
            </div>
            <div class="feed">
                <?php if (!$pendingRefunds): ?>
                    <p class="muted small">暂无待处理售后。</p>
                <?php endif; ?>
                <?php foreach ($pendingRefunds as $refund): ?>
                    <div class="feed-item">
                        <span class="feed-dot">退</span>
                        <div class="feed-body">
                            <strong><?= e($refund['customer_name']) ?> · ¥<?= money($refund['amount']) ?></strong>
                            <p><?= e($refund['reason']) ?></p>
                        </div>
                        <span class="feed-time"><?= e(fmt_month_day($refund['applied_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<div class="dashboard-bottom" style="margin-top: 18px;">
    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">近 30 日热销商品</div>
                <div class="card-subtitle">按成交金额排序，含全部已支付订单</div>
            </div>
            <a class="btn-link" href="/reports.php">分析报表</a>
        </div>
        <div class="card-body" style="padding-top: 10px;">
            <?php if (!$topProducts): ?>
                <p class="muted small">暂无成交数据。</p>
            <?php endif; ?>
            <?php foreach ($topProducts as $index => $product): ?>
                <div class="top-product">
                    <span class="top-product-rank"><?= $index + 1 ?></span>
                    <span class="top-product-text">
                        <strong><?= e($product['product_name']) ?></strong>
                        <span><?= e($product['sku']) ?> · 售出 <?= (int) $product['qty'] ?> 件</span>
                    </span>
                    <span class="top-product-amount">¥<?= money($product['amount']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">运营动态</div>
                <div class="card-subtitle">近期关键操作记录</div>
            </div>
            <a class="btn-link" href="/users.php">审计日志</a>
        </div>
        <div class="feed">
            <?php foreach ($auditRows as $log): ?>
                <div class="feed-item">
                    <span class="feed-dot"><?= e(initial_char($log['username'])) ?></span>
                    <div class="feed-body">
                        <strong><?= e($log['action']) ?> · <?= e($log['target']) ?></strong>
                        <p><?= e($log['detail']) ?></p>
                    </div>
                    <span class="feed-time"><?= e(fmt_month_day($log['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
