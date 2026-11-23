<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$channelOptions = ['微信小程序', '天猫旗舰店', '抖音小店', '京东旗舰店', '线下门店'];

$normalizeRange = static function (string $start, string $end): array {
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false) {
        $startTs = strtotime(date('Y-m-01'));
    }
    if ($endTs === false) {
        $endTs = strtotime(date('Y-m-d'));
    }
    if ($startTs > $endTs) {
        [$startTs, $endTs] = [$endTs, $startTs];
    }
    if (($endTs - $startTs) / 86400 > 92) {
        $startTs = $endTs - 92 * 86400;
    }
    return [date('Y-m-d', $startTs), date('Y-m-d', $endTs)];
};

$buildReport = static function (PDO $pdo, string $start, string $end, string $channel) use ($channelOptions): array {
    $conditions = ["o.payment_status = '已支付'", 'o.paid_at >= ?', 'o.paid_at < ?'];
    $params = [$start . ' 00:00:00', date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00'];
    if (in_array($channel, $channelOptions, true)) {
        $conditions[] = 'o.sales_channel = ?';
        $params[] = $channel;
    }
    $where = implode(' AND ', $conditions);

    $totalsStmt = $pdo->prepare("SELECT COALESCE(SUM(o.payable_amount), 0) AS revenue, COUNT(*) AS order_count, COUNT(DISTINCT o.customer_id) AS customer_count, COALESCE(AVG(o.payable_amount), 0) AS avg_amount FROM orders o WHERE $where");
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch() ?: ['revenue' => 0, 'order_count' => 0, 'customer_count' => 0, 'avg_amount' => 0];

    $itemsStmt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty), 0) AS qty FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $where");
    $itemsStmt->execute($params);
    $totals['items'] = (int) $itemsStmt->fetchColumn();

    $refundConditions = ["r.status IN ('已退款', '已通过')", 'substr(r.handled_at, 1, 10) >= ?', 'substr(r.handled_at, 1, 10) <= ?'];
    $refundParams = [$start, $end];
    if (in_array($channel, $channelOptions, true)) {
        $refundConditions[] = 'o.sales_channel = ?';
        $refundParams[] = $channel;
    }
    $refundStmt = $pdo->prepare('SELECT COALESCE(SUM(r.amount), 0) FROM refunds r JOIN orders o ON o.id = r.order_id WHERE ' . implode(' AND ', $refundConditions));
    $refundStmt->execute($refundParams);
    $refundAmount = (float) $refundStmt->fetchColumn();

    $dailyStmt = $pdo->prepare("SELECT substr(o.paid_at, 1, 10) AS d, COUNT(*) AS cnt, COALESCE(SUM(o.payable_amount), 0) AS amount FROM orders o WHERE $where GROUP BY substr(o.paid_at, 1, 10) ORDER BY d");
    $dailyStmt->execute($params);
    $dailyMap = [];
    foreach ($dailyStmt->fetchAll() as $row) {
        $dailyMap[$row['d']] = ['cnt' => (int) $row['cnt'], 'amount' => (float) $row['amount']];
    }
    $daily = [];
    $cursor = strtotime($start);
    $endTs = strtotime($end);
    while ($cursor !== false && $cursor <= $endTs) {
        $day = date('Y-m-d', $cursor);
        $daily[] = [
            'date' => $day,
            'label' => date('m-d', $cursor),
            'weekday' => '周' . ['日', '一', '二', '三', '四', '五', '六'][(int) date('w', $cursor)],
            'cnt' => $dailyMap[$day]['cnt'] ?? 0,
            'amount' => $dailyMap[$day]['amount'] ?? 0,
        ];
        $cursor = strtotime('+1 day', $cursor);
    }

    $channelStmt = $pdo->prepare("SELECT o.sales_channel AS channel, COUNT(*) AS cnt, COALESCE(SUM(o.payable_amount), 0) AS amount FROM orders o WHERE $where GROUP BY o.sales_channel ORDER BY amount DESC");
    $channelStmt->execute($params);
    $channels = $channelStmt->fetchAll();

    $categoryStmt = $pdo->prepare("SELECT c.name AS category, COALESCE(SUM(oi.subtotal), 0) AS amount, COALESCE(SUM(oi.qty), 0) AS qty FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id JOIN categories c ON c.id = p.category_id WHERE $where GROUP BY c.id, c.name ORDER BY amount DESC");
    $categoryStmt->execute($params);
    $categories = $categoryStmt->fetchAll();

    $topStmt = $pdo->prepare("SELECT oi.product_id, oi.product_name, oi.sku, COALESCE(SUM(oi.qty), 0) AS qty, COALESCE(SUM(oi.subtotal), 0) AS amount FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $where GROUP BY oi.product_id, oi.product_name, oi.sku ORDER BY amount DESC LIMIT 10");
    $topStmt->execute($params);
    $topProducts = $topStmt->fetchAll();

    return compact('totals', 'refundAmount', 'daily', 'channels', 'categories', 'topProducts');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'export') {
        [$start, $end] = $normalizeRange(input('start'), input('end'));
        $channel = in_array(input('channel'), $channelOptions, true) ? input('channel') : '';
        $report = $buildReport($pdo, $start, $end, $channel);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wenchuan-sales-report-' . str_replace('-', '', $start) . '-' . str_replace('-', '', $end) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['闻川商城销售经营报表']);
        fputcsv($out, ['统计期间', $start . ' 至 ' . $end]);
        fputcsv($out, ['销售渠道', $channel !== '' ? $channel : '全部渠道']);
        fputcsv($out, ['成交额', money($report['totals']['revenue'])]);
        fputcsv($out, ['支付订单数', (int) $report['totals']['order_count']]);
        fputcsv($out, ['成交客户数', (int) $report['totals']['customer_count']]);
        fputcsv($out, ['客单价', money($report['totals']['avg_amount'])]);
        fputcsv($out, ['销售件数', (int) $report['totals']['items']]);
        fputcsv($out, ['退款金额', money($report['refundAmount'])]);
        fputcsv($out, []);
        fputcsv($out, ['按日明细']);
        fputcsv($out, ['日期', '订单数', '成交额']);
        foreach ($report['daily'] as $day) {
            fputcsv($out, [$day['date'], $day['cnt'], money($day['amount'])]);
        }
        fputcsv($out, []);
        fputcsv($out, ['渠道明细']);
        fputcsv($out, ['渠道', '订单数', '成交额']);
        foreach ($report['channels'] as $row) {
            fputcsv($out, [$row['channel'], $row['cnt'], money($row['amount'])]);
        }
        fputcsv($out, []);
        fputcsv($out, ['分类明细']);
        fputcsv($out, ['分类', '销售件数', '成交额']);
        foreach ($report['categories'] as $row) {
            fputcsv($out, [$row['category'], $row['qty'], money($row['amount'])]);
        }
        fputcsv($out, []);
        fputcsv($out, ['热销商品']);
        fputcsv($out, ['SKU', '商品', '销售件数', '成交额']);
        foreach ($report['topProducts'] as $row) {
            fputcsv($out, [$row['sku'], $row['product_name'], $row['qty'], money($row['amount'])]);
        }
        fclose($out);
        exit;
    }
}

[$startDate, $endDate] = $normalizeRange(q('start', date('Y-m-01')), q('end', date('Y-m-d')));
$channelFilter = in_array(q('channel'), $channelOptions, true) ? q('channel') : '';
$report = $buildReport($pdo, $startDate, $endDate, $channelFilter);

$dailyMax = max(1.0, max(array_merge([0], array_column($report['daily'], 'amount'))));
$channelMax = max(1.0, max(array_merge([0], array_map(static fn(array $row): float => (float) $row['amount'], $report['channels']))));
$categoryMax = max(1.0, max(array_merge([0], array_map(static fn(array $row): float => (float) $row['amount'], $report['categories']))));
$dailyTotal = array_sum(array_column($report['daily'], 'amount'));

$pageTitle = '报表中心';
$pageSubtitle = '销售经营分析 · ' . $startDate . ' 至 ' . $endDate;
$active = 'reports';
$pageActions = '<form class="inline-form" method="post" action="/reports.php">' . csrf_field()
    . '<input type="hidden" name="action" value="export">'
    . '<input type="hidden" name="start" value="' . e($startDate) . '">'
    . '<input type="hidden" name="end" value="' . e($endDate) . '">'
    . '<input type="hidden" name="channel" value="' . e($channelFilter) . '">'
    . '<button class="btn btn-primary" type="submit">导出 CSV</button></form>';
require __DIR__ . '/partials/header.php';
?>

<section class="report-hero">
    <article class="report-stat">
        <span>成交金额</span>
        <strong>¥<?= money($report['totals']['revenue']) ?></strong>
        <em>统计区间内已支付订单</em>
    </article>
    <article class="report-stat">
        <span>支付订单</span>
        <strong><?= (int) $report['totals']['order_count'] ?> 笔</strong>
        <em><?= (int) $report['totals']['customer_count'] ?> 位成交客户</em>
    </article>
    <article class="report-stat">
        <span>客单价</span>
        <strong>¥<?= money($report['totals']['avg_amount']) ?></strong>
        <em>按支付订单平均计算</em>
    </article>
    <article class="report-stat">
        <span>销售件数</span>
        <strong><?= number_format((int) $report['totals']['items']) ?> 件</strong>
        <em>退款金额 ¥<?= money($report['refundAmount']) ?></em>
    </article>
</section>

<section class="card" style="margin-bottom: 18px;">
    <form class="filter-bar" method="get" action="/reports.php" style="border-bottom: 0; background: #fff;">
        <div class="filter-fields">
            <div class="filter-field">
                <label for="start">开始日期</label>
                <input id="start" name="start" type="date" value="<?= e($startDate) ?>" max="<?= e($endDate) ?>">
            </div>
            <div class="filter-field">
                <label for="end">结束日期</label>
                <input id="end" name="end" type="date" value="<?= e($endDate) ?>" min="<?= e($startDate) ?>">
            </div>
            <div class="filter-field">
                <label for="channelFilter">销售渠道</label>
                <select id="channelFilter" name="channel">
                    <option value="">全部渠道</option>
                    <?php foreach ($channelOptions as $channel): ?>
                        <option value="<?= e($channel) ?>" <?= $channelFilter === $channel ? 'selected' : '' ?>><?= e($channel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <span class="muted small" style="margin-right: 8px;">区间最长 92 天</span>
            <a class="btn btn-ghost btn-sm" href="/reports.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">生成报表</button>
        </div>
    </form>
</section>

<div class="dashboard-main">
    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">按日成交趋势</div>
                <div class="card-subtitle">已支付订单成交额 · 共 <?= count($report['daily']) ?> 天</div>
            </div>
            <div class="chart-head-meta">
                <span>区间合计<strong>¥<?= money($dailyTotal) ?></strong></span>
            </div>
        </div>
        <div class="card-body">
            <div class="chart">
                <?php foreach ($report['daily'] as $day): ?>
                    <?php
                    $height = $day['amount'] > 0 ? max(4, round(($day['amount'] / $dailyMax) * 100, 1)) : 2;
                    ?>
                    <div class="chart-col<?= $day['amount'] <= 0 ? ' is-zero' : '' ?>" title="<?= e($day['date']) ?> · <?= (int) $day['cnt'] ?> 笔 · ¥<?= money($day['amount']) ?>">
                        <span class="chart-value">¥<?= money($day['amount']) ?></span>
                        <div class="chart-bar" style="height: <?= $height ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-labels">
                <?php foreach ($report['daily'] as $index => $day): ?>
                    <div class="chart-label"><?= (count($report['daily']) > 14 && $index % 5 !== 0) ? '' : e($day['label']) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">渠道构成</div>
                <div class="card-subtitle">各渠道成交金额与订单量</div>
            </div>
        </div>
        <div class="card-body">
            <div class="report-bars">
                <?php if (!$report['channels']): ?>
                    <p class="muted small">统计区间内暂无成交。</p>
                <?php endif; ?>
                <?php foreach ($report['channels'] as $row): ?>
                    <div class="report-bar-row">
                        <span class="channel-name"><?= e($row['channel']) ?></span>
                        <div class="progress"><div class="progress-fill" style="width: <?= max(2, round(((float) $row['amount'] / $channelMax) * 100, 1)) ?>%"></div></div>
                        <span class="report-bar-amount">¥<?= money($row['amount']) ?><br><small class="muted"><?= (int) $row['cnt'] ?> 笔</small></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<div class="grid grid-2" style="margin-top: 18px;">
    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">分类销售表现</div>
                <div class="card-subtitle">按商品分类汇总成交额与件数</div>
            </div>
        </div>
        <div class="card-body">
            <div class="report-bars">
                <?php if (!$report['categories']): ?>
                    <p class="muted small">统计区间内暂无分类销售数据。</p>
                <?php endif; ?>
                <?php foreach ($report['categories'] as $row): ?>
                    <div class="report-bar-row">
                        <span class="channel-name"><?= e($row['category']) ?></span>
                        <div class="progress"><div class="progress-fill" style="width: <?= max(2, round(((float) $row['amount'] / $categoryMax) * 100, 1)) ?>%"></div></div>
                        <span class="report-bar-amount">¥<?= money($row['amount']) ?><br><small class="muted"><?= (int) $row['qty'] ?> 件</small></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">热销商品 TOP 10</div>
                <div class="card-subtitle">按成交金额排序</div>
            </div>
        </div>
        <div class="table-wrap" style="margin-top: 12px;">
            <table class="data-table">
                <thead><tr><th>排名</th><th>商品</th><th class="text-center">件数</th><th class="text-right">成交额</th></tr></thead>
                <tbody>
                    <?php if (!$report['topProducts']): ?>
                        <tr><td colspan="4"><div class="data-empty" style="padding: 26px 10px;"><strong>暂无成交</strong>统计区间内没有已支付订单。</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($report['topProducts'] as $index => $product): ?>
                        <tr>
                            <td><span class="top-product-rank"><?= $index + 1 ?></span></td>
                            <td><span class="cell-main"><?= e($product['product_name']) ?></span><span class="cell-sub mono"><?= e($product['sku']) ?></span></td>
                            <td class="text-center"><?= (int) $product['qty'] ?></td>
                            <td class="text-right amount">¥<?= money($product['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="card" style="margin-top: 18px;">
    <div class="card-head">
        <div>
            <div class="card-title">按日明细</div>
            <div class="card-subtitle">统计区间内每日成交与订单量</div>
        </div>
        <span class="muted small"><?= $startDate ?> 至 <?= $endDate ?></span>
    </div>
    <div class="table-wrap" style="margin-top: 12px;">
        <table class="data-table">
            <thead><tr><th>日期</th><th>星期</th><th class="text-right">支付订单</th><th class="text-right">成交金额</th><th class="text-right">日均占比</th></tr></thead>
            <tbody>
                <?php foreach ($report['daily'] as $day): ?>
                    <tr>
                        <td class="mono"><?= e($day['date']) ?></td>
                        <td class="muted"><?= e($day['weekday']) ?></td>
                        <td class="text-right"><?= (int) $day['cnt'] ?></td>
                        <td class="text-right amount">¥<?= money($day['amount']) ?></td>
                        <td class="text-right muted"><?= $dailyTotal > 0 ? number_format(($day['amount'] / $dailyTotal) * 100, 1) : '0.0' ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
