<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$levels = ['普通', '银卡', '金卡', '钻石'];
$statuses = ['正常', '停用'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    if ($action === 'create') {
        $name = input('name');
        $phone = input('phone');
        $email = input('email');
        $level = input('level', '普通');
        $city = input('city');
        $address = input('address');
        $birthday = input('birthday');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 30) {
            $errors[] = '请填写客户姓名（30 字以内）。';
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $errors[] = '手机号格式不正确。';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确。';
        }
        if (!in_array($level, $levels, true)) {
            $errors[] = '会员等级不合法。';
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE phone = ?');
        $dup->execute([$phone]);
        if ((int) $dup->fetchColumn() > 0) {
            $errors[] = '该手机号已注册会员。';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/customers.php');
        }

        $memberNo = next_serial('customers', 'member_no', 'WC-M', 5);
        $stmt = $pdo->prepare('INSERT INTO customers (member_no, name, phone, email, level, points, city, address, birthday, status, registered_at, total_orders, total_spent) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, 0)');
        $stmt->execute([$memberNo, $name, $phone, $email, $level, $city, $address, $birthday !== '' ? $birthday : null, '正常', date('Y-m-d H:i:s')]);
        $newCustomerId = (int) $pdo->lastInsertId();
        audit_log('新增会员', $memberNo, $name . ' / ' . $phone);
        flash('success', '会员「' . $name . '」已创建，会员号 ' . $memberNo . '。');
        redirect('/customers.php?id=' . $newCustomerId);
    }

    if ($action === 'toggle') {
        $id = (int) input('id', '0');
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $customer = $stmt->fetch();
        if (!$customer) {
            flash('error', '会员不存在。');
            redirect('/customers.php');
        }
        $newStatus = $customer['status'] === '正常' ? '停用' : '正常';
        $update = $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?');
        $update->execute([$newStatus, $id]);
        audit_log('会员状态调整', $customer['member_no'], $customer['name'] . ' 调整为' . $newStatus);
        flash('success', '会员「' . $customer['name'] . '」已' . ($newStatus === '正常' ? '启用' : '停用') . '。');
        redirect('/customers.php?id=' . $id);
    }
}

$keyword = q('q');
$levelFilter = q('level');
$statusFilter = q('status');
$page = max(1, (int) q('page', '1'));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.member_no LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($levelFilter, $levels, true)) {
    $where[] = 'c.level = ?';
    $params[] = $levelFilter;
}
if (in_array($statusFilter, $statuses, true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM customers c' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 12, $page);

$listStmt = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS recent_orders FROM customers c' . $whereSql . ' ORDER BY c.id DESC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset']);
$listStmt->execute($params);
$customers = $listStmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN level IN ('金卡', '钻石') THEN 1 ELSE 0 END) AS vip, SUM(CASE WHEN substr(registered_at, 1, 7) = '" . date('Y-m') . "' THEN 1 ELSE 0 END) AS new_month, COALESCE(SUM(total_spent), 0) AS spent FROM customers")->fetch() ?: [];

$customerDetail = null;
$customerOrders = [];
$customerRefunds = [];
$detailId = (int) q('id', '0');
if ($detailId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$detailId]);
    $customerDetail = $stmt->fetch() ?: null;
    if ($customerDetail) {
        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 6');
        $orderStmt->execute([$detailId]);
        $customerOrders = $orderStmt->fetchAll();
        $refundStmt = $pdo->prepare('SELECT r.*, o.order_no FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.customer_id = ? ORDER BY r.applied_at DESC LIMIT 5');
        $refundStmt->execute([$detailId]);
        $customerRefunds = $refundStmt->fetchAll();
    }
}

$pageTitle = '客户管理';
$pageSubtitle = '会员档案、消费画像与售后记录';
$active = 'customers';
$pageActions = '<a class="btn btn-secondary" href="/orders.php">订单查询</a><a class="btn btn-primary" href="#customerForm" data-open-customer>+ 新增会员</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>会员总数 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>金卡及以上 <strong><?= (int) ($summary['vip'] ?? 0) ?></strong></span>
    <span>本月新增 <strong><?= (int) ($summary['new_month'] ?? 0) ?></strong></span>
    <span>累计会员消费 <strong>¥<?= money($summary['spent'] ?? 0) ?></strong></span>
</section>

<?php if ($customerDetail): ?>
    <div class="grid grid-2" style="margin-bottom: 18px;">
        <section class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">会员档案</div>
                    <div class="card-subtitle"><?= e($customerDetail['member_no']) ?> · 入会 <?= e(fmt_date($customerDetail['registered_at'])) ?></div>
                </div>
                <div class="card-actions">
                    <span class="badge <?= status_class($customerDetail['status']) ?>"><?= e($customerDetail['status']) ?></span>
                    <a class="btn-link" href="/customers.php">返回列表</a>
                </div>
            </div>
            <div class="card-body">
                <span class="customer-cell" style="margin-bottom: 18px;">
                    <span class="customer-avatar" style="width: 48px; height: 48px; flex-basis: 48px; font-size: 18px;"><?= e(initial_char($customerDetail['name'])) ?></span>
                    <span class="customer-cell-text">
                        <strong style="font-size: 17px;"><?= e($customerDetail['name']) ?></strong>
                        <span><?= e($customerDetail['level']) ?>会员 · <?= e($customerDetail['city'] ?: '未填写城市') ?></span>
                    </span>
                </span>
                <div class="kv-list">
                    <div class="kv-row"><span class="kv-label">手机号</span><span class="kv-value"><?= e($customerDetail['phone']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">邮箱</span><span class="kv-value"><?= e($customerDetail['email'] ?: '—') ?></span></div>
                    <div class="kv-row"><span class="kv-label">收货地址</span><span class="kv-value"><?= e($customerDetail['address'] ?: '—') ?></span></div>
                    <div class="kv-row"><span class="kv-label">生日</span><span class="kv-value"><?= e($customerDetail['birthday'] ?: '—') ?></span></div>
                    <div class="kv-row"><span class="kv-label">历史订单</span><span class="kv-value"><?= (int) $customerDetail['total_orders'] ?> 笔 · 本系统 <?= count($customerOrders) ?> 笔</span></div>
                    <div class="kv-row"><span class="kv-label">累计消费</span><span class="kv-value strong">¥<?= money($customerDetail['total_spent']) ?></span></div>
                    <div class="kv-row"><span class="kv-label">可用积分</span><span class="kv-value"><?= number_format((int) $customerDetail['points']) ?></span></div>
                </div>
                <div class="form-actions">
                    <form method="post" action="/customers.php" data-confirm="确认<?= $customerDetail['status'] === '正常' ? '停用' : '启用' ?>该会员？">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $customerDetail['id'] ?>">
                        <button class="btn btn-secondary btn-sm" type="submit"><?= $customerDetail['status'] === '正常' ? '停用会员' : '启用会员' ?></button>
                    </form>
                    <a class="btn btn-ghost btn-sm" href="/orders.php?q=<?= rawurlencode($customerDetail['phone']) ?>">查看全部订单</a>
                </div>
            </div>
        </section>

        <div class="stack">
            <section class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title">最近订单</div>
                        <div class="card-subtitle">最近 6 笔订单记录</div>
                    </div>
                </div>
                <div class="table-wrap" style="margin-top: 12px;">
                    <table class="data-table">
                        <thead><tr><th>订单号</th><th>金额</th><th>状态</th><th>时间</th></tr></thead>
                        <tbody>
                            <?php if (!$customerOrders): ?>
                                <tr><td colspan="4"><div class="data-empty" style="padding: 24px 10px;"><strong>暂无订单</strong>该会员尚未产生订单。</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($customerOrders as $order): ?>
                                <tr>
                                    <td><a class="mono" href="/order_detail.php?id=<?= (int) $order['id'] ?>"><?= e($order['order_no']) ?></a></td>
                                    <td class="amount">¥<?= money($order['payable_amount']) ?></td>
                                    <td><span class="badge <?= status_class($order['status']) ?>"><?= e($order['status']) ?></span></td>
                                    <td class="muted nowrap"><?= e(fmt_month_day($order['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title">售后记录</div>
                        <div class="card-subtitle">该会员的退款与售后申请</div>
                    </div>
                </div>
                <div class="table-wrap" style="margin-top: 12px;">
                    <table class="data-table">
                        <thead><tr><th>退款单号</th><th>订单号</th><th>金额</th><th>状态</th></tr></thead>
                        <tbody>
                            <?php if (!$customerRefunds): ?>
                                <tr><td colspan="4"><div class="data-empty" style="padding: 22px 10px;"><strong>暂无售后</strong>该会员没有退款记录。</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($customerRefunds as $refund): ?>
                                <tr>
                                    <td class="mono"><?= e($refund['refund_no']) ?></td>
                                    <td class="mono"><?= e($refund['order_no']) ?></td>
                                    <td class="amount">¥<?= money($refund['amount']) ?></td>
                                    <td><span class="badge <?= status_class($refund['status']) ?>"><?= e($refund['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
<?php endif; ?>

<details class="create-panel" id="customerForm">
    <summary>新增会员</summary>
    <div class="create-body">
        <form method="post" action="/customers.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid three">
                <div class="field">
                    <label for="name">姓名<span class="required">*</span></label>
                    <input id="name" name="name" type="text" placeholder="请输入会员姓名" maxlength="30" required>
                </div>
                <div class="field">
                    <label for="phone">手机号<span class="required">*</span></label>
                    <input id="phone" name="phone" type="tel" placeholder="11 位手机号" maxlength="11" required>
                </div>
                <div class="field">
                    <label for="email">邮箱</label>
                    <input id="email" name="email" type="email" placeholder="name@example.com">
                </div>
                <div class="field">
                    <label for="level">会员等级</label>
                    <select id="level" name="level">
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= e($level) ?>"><?= e($level) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="city">所在城市</label>
                    <input id="city" name="city" type="text" placeholder="杭州">
                </div>
                <div class="field">
                    <label for="birthday">生日</label>
                    <input id="birthday" name="birthday" type="date">
                </div>
                <div class="field full">
                    <label for="address">收货地址</label>
                    <input id="address" name="address" type="text" placeholder="街道、门牌号">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">创建会员</button>
                <span class="form-hint">新会员默认为正常状态，会员号由系统自动生成。</span>
            </div>
        </form>
    </div>
</details>

<section class="card">
    <form class="filter-bar" method="get" action="/customers.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">会员搜索</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="姓名 / 手机号 / 会员号">
            </div>
            <div class="filter-field">
                <label for="levelFilter">会员等级</label>
                <select id="levelFilter" name="level">
                    <option value="">全部等级</option>
                    <?php foreach ($levels as $level): ?>
                        <option value="<?= e($level) ?>" <?= $levelFilter === $level ? 'selected' : '' ?>><?= e($level) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="statusFilter">会员状态</label>
                <select id="statusFilter" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/customers.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 位会员，按注册时间倒序排列</div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>会员</th>
                    <th>等级</th>
                    <th>城市</th>
                    <th>订单数</th>
                    <th>累计消费</th>
                    <th>最近下单</th>
                    <th>状态</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$customers): ?>
                    <tr><td colspan="8"><div class="data-empty"><div class="data-empty-mark">◉</div><strong>暂无匹配会员</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td>
                            <span class="customer-cell">
                                <span class="customer-avatar"><?= e(initial_char($customer['name'])) ?></span>
                                <span class="customer-cell-text">
                                    <strong><?= e($customer['name']) ?></strong>
                                    <span><?= e($customer['phone']) ?> · <?= e($customer['member_no']) ?></span>
                                </span>
                            </span>
                        </td>
                        <td><span class="role-tag"><?= e($customer['level']) ?></span></td>
                        <td><?= e($customer['city'] ?: '—') ?></td>
                        <td><?= (int) $customer['total_orders'] ?> 笔<span class="cell-sub">本期 <?= (int) $customer['recent_orders'] ?> 笔</span></td>
                        <td class="amount">¥<?= money($customer['total_spent']) ?></td>
                        <td class="muted nowrap"><?= e(fmt_date($customer['last_order_at'])) ?></td>
                        <td><span class="badge <?= status_class($customer['status']) ?>"><?= e($customer['status']) ?></span></td>
                        <td class="text-right actions">
                            <a class="btn btn-ghost btn-sm" href="/customers.php?id=<?= (int) $customer['id'] ?>">档案</a>
                            <form class="inline-form" method="post" action="/customers.php" data-confirm="确认<?= $customer['status'] === '正常' ? '停用' : '启用' ?>该会员？">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= $customer['status'] === '正常' ? '停用' : '启用' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/customers.php', ['q' => $keyword, 'level' => $levelFilter, 'status' => $statusFilter]) ?>
</section>

<script>
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-open-customer]');
    if (!trigger) { return; }
    var panel = document.getElementById('customerForm');
    if (panel) {
        panel.setAttribute('open', 'open');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
