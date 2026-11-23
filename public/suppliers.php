<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$ratings = ['A', 'B', 'C'];
$coopStatuses = ['合作中', '暂停合作', '已终止'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    if ($action === 'create') {
        $name = input('name');
        $contact = input('contact');
        $phone = input('phone');
        $email = input('email');
        $scope = input('scope');
        $rating = input('rating', 'B');
        $address = input('address');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = '请填写供应商名称（80 字以内）。';
        }
        if ($contact === '') {
            $errors[] = '请填写联系人。';
        }
        if ($phone === '' || !preg_match('/^[0-9\- ]{7,20}$/', $phone)) {
            $errors[] = '联系电话格式不正确。';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确。';
        }
        if (!in_array($rating, $ratings, true)) {
            $errors[] = '信用评级不合法。';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/suppliers.php');
        }

        $code = 'SP-' . date('Y') . '-' . str_pad((string) ((int) $pdo->query('SELECT MAX(id) FROM suppliers')->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('INSERT INTO suppliers (code, name, contact, phone, email, scope, credit_rating, cooperation_status, payable_amount, last_supply_at, address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?)');
        $stmt->execute([$code, $name, $contact, $phone, $email, $scope, $rating, '合作中', $address, date('Y-m-d H:i:s')]);
        audit_log('新增供应商', $code, $name);
        flash('success', '供应商「' . $name . '」已创建，编码 ' . $code . '。');
        redirect('/suppliers.php');
    }

    if ($action === 'pay') {
        $supplierId = (int) input('supplier_id', '0');
        $amount = input('amount', '0');
        $remark = input('remark');
        if (!is_numeric($amount) || (float) $amount <= 0) {
            flash('error', '付款金额需大于 0。');
            redirect('/suppliers.php');
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch();
            if (!$supplier) {
                throw new RuntimeException('供应商不存在。');
            }
            $payable = (float) $supplier['payable_amount'];
            if ((float) $amount > $payable + 0.001) {
                throw new RuntimeException('付款金额不能超过当前应付余额 ¥' . money($payable) . '。');
            }
            $newPayable = round($payable - (float) $amount, 2);
            $update = $pdo->prepare('UPDATE suppliers SET payable_amount = ? WHERE id = ?');
            $update->execute([$newPayable, $supplierId]);
            $pdo->commit();
            audit_log('供应商付款', $supplier['code'], '付款 ¥' . money($amount) . '，剩余应付 ¥' . money($newPayable) . ($remark !== '' ? '，' . $remark : ''));
            flash('success', '已登记对「' . $supplier['name'] . '」付款 ¥' . money($amount) . '，剩余应付 ¥' . money($newPayable) . '。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
        }
        redirect('/suppliers.php');
    }

    if ($action === 'toggle') {
        $id = (int) input('id', '0');
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $supplier = $stmt->fetch();
        if (!$supplier) {
            flash('error', '供应商不存在。');
            redirect('/suppliers.php');
        }
        $newStatus = $supplier['cooperation_status'] === '合作中' ? '暂停合作' : '合作中';
        $update = $pdo->prepare('UPDATE suppliers SET cooperation_status = ? WHERE id = ?');
        $update->execute([$newStatus, $id]);
        audit_log('供应商状态调整', $supplier['code'], $supplier['name'] . ' 调整为' . $newStatus);
        flash('success', '供应商「' . $supplier['name'] . '」状态已更新为' . $newStatus . '。');
        redirect('/suppliers.php');
    }
}

$keyword = q('q');
$statusFilter = q('status');
$ratingFilter = q('rating');
$payId = (int) q('pay', '0');
$page = max(1, (int) q('page', '1'));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.code LIKE ? OR s.contact LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($statusFilter, $coopStatuses, true)) {
    $where[] = 's.cooperation_status = ?';
    $params[] = $statusFilter;
}
if (in_array($ratingFilter, $ratings, true)) {
    $where[] = 's.credit_rating = ?';
    $params[] = $ratingFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM suppliers s' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 12, $page);

$listStmt = $pdo->prepare('SELECT s.* FROM suppliers s' . $whereSql . ' ORDER BY s.cooperation_status = "合作中" DESC, s.payable_amount DESC, s.id DESC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset']);
$listStmt->execute($params);
$suppliers = $listStmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN cooperation_status = '合作中' THEN 1 ELSE 0 END) AS active, COALESCE(SUM(payable_amount), 0) AS payable, SUM(CASE WHEN credit_rating = 'A' THEN 1 ELSE 0 END) AS grade_a FROM suppliers")->fetch() ?: [];

$paySupplier = null;
if ($payId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$payId]);
    $paySupplier = $stmt->fetch() ?: null;
}
$payableSuppliers = $pdo->query('SELECT id, code, name, payable_amount FROM suppliers WHERE payable_amount > 0 ORDER BY payable_amount DESC')->fetchAll();

$pageTitle = '供应商管理';
$pageSubtitle = '供应商档案、应付余额与付款登记';
$active = 'suppliers';
$pageActions = '<a class="btn btn-secondary" href="#supplierPay" data-open-pay>登记付款</a><a class="btn btn-primary" href="#supplierForm" data-open-supplier>+ 新增供应商</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>供应商总数 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>合作中 <strong><?= (int) ($summary['active'] ?? 0) ?></strong></span>
    <span>A 级供应商 <strong><?= (int) ($summary['grade_a'] ?? 0) ?></strong></span>
    <span>应付余额 <strong>¥<?= money($summary['payable'] ?? 0) ?></strong></span>
</section>

<details class="create-panel" id="supplierPay" <?= $paySupplier ? 'open' : '' ?>>
    <summary>登记供应商付款</summary>
    <div class="create-body">
        <form method="post" action="/suppliers.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pay">
            <div class="form-grid three">
                <div class="field">
                    <label for="supplier_id">付款供应商<span class="required">*</span></label>
                    <select id="supplier_id" name="supplier_id" required>
                        <option value="">请选择供应商</option>
                        <?php foreach ($payableSuppliers as $supplier): ?>
                            <option value="<?= (int) $supplier['id'] ?>" <?= $paySupplier && (int) $paySupplier['id'] === (int) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['name'] . ' · 应付 ¥' . money($supplier['payable_amount'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="amount">付款金额<span class="required">*</span></label>
                    <input id="amount" name="amount" type="number" min="0.01" step="0.01" placeholder="0.00" required>
                </div>
                <div class="field">
                    <label for="remark">付款说明</label>
                    <input id="remark" name="remark" type="text" placeholder="例如：11 月面料货款" maxlength="120">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">确认付款</button>
                <span class="form-hint">付款金额不得超过该供应商当前应付余额，登记后即时更新应付。</span>
            </div>
        </form>
    </div>
</details>

<details class="create-panel" id="supplierForm">
    <summary>新增供应商</summary>
    <div class="create-body">
        <form method="post" action="/suppliers.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid three">
                <div class="field">
                    <label for="name">供应商名称<span class="required">*</span></label>
                    <input id="name" name="name" type="text" placeholder="请输入企业全称" maxlength="80" required>
                </div>
                <div class="field">
                    <label for="contact">联系人<span class="required">*</span></label>
                    <input id="contact" name="contact" type="text" placeholder="联系人姓名" required>
                </div>
                <div class="field">
                    <label for="phone">联系电话<span class="required">*</span></label>
                    <input id="phone" name="phone" type="text" placeholder="0571-88886666" required>
                </div>
                <div class="field">
                    <label for="email">邮箱</label>
                    <input id="email" name="email" type="email" placeholder="contact@example.com">
                </div>
                <div class="field">
                    <label for="rating">信用评级</label>
                    <select id="rating" name="rating">
                        <?php foreach ($ratings as $rating): ?>
                            <option value="<?= e($rating) ?>" <?= $rating === 'B' ? 'selected' : '' ?>><?= e($rating) ?> 级</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="scope">供货范围</label>
                    <input id="scope" name="scope" type="text" placeholder="例如：女装面料、成衣加工">
                </div>
                <div class="field full">
                    <label for="address">企业地址</label>
                    <input id="address" name="address" type="text" placeholder="省市区、街道与门牌号">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">创建供应商</button>
                <span class="form-hint">供应商编码由系统自动生成，初始应付余额为 0。</span>
            </div>
        </form>
    </div>
</details>

<section class="card">
    <form class="filter-bar" method="get" action="/suppliers.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">供应商搜索</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="名称 / 编码 / 联系人">
            </div>
            <div class="filter-field">
                <label for="statusFilter">合作状态</label>
                <select id="statusFilter" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($coopStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="ratingFilter">信用评级</label>
                <select id="ratingFilter" name="rating">
                    <option value="">全部评级</option>
                    <?php foreach ($ratings as $rating): ?>
                        <option value="<?= e($rating) ?>" <?= $ratingFilter === $rating ? 'selected' : '' ?>><?= e($rating) ?> 级</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/suppliers.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 家供应商，合作中优先展示</div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>供应商</th>
                    <th>联系人</th>
                    <th>供货范围</th>
                    <th>信用</th>
                    <th>应付余额</th>
                    <th>最近供货</th>
                    <th>状态</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$suppliers): ?>
                    <tr><td colspan="8"><div class="data-empty"><div class="data-empty-mark">◎</div><strong>暂无匹配供应商</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td>
                            <span class="cell-main"><?= e($supplier['name']) ?></span>
                            <span class="cell-sub mono"><?= e($supplier['code']) ?></span>
                        </td>
                        <td><?= e($supplier['contact']) ?><span class="cell-sub"><?= e($supplier['phone']) ?></span></td>
                        <td style="max-width: 220px;"><?= e($supplier['scope'] ?: '—') ?></td>
                        <td><span class="role-tag"><?= e($supplier['credit_rating']) ?> 级</span></td>
                        <td><span class="amount">¥<?= money($supplier['payable_amount']) ?></span></td>
                        <td class="muted nowrap"><?= e(fmt_date($supplier['last_supply_at'])) ?></td>
                        <td><span class="badge <?= status_class($supplier['cooperation_status']) ?>"><?= e($supplier['cooperation_status']) ?></span></td>
                        <td class="text-right actions">
                            <?php if ((float) $supplier['payable_amount'] > 0): ?>
                                <a class="btn btn-ghost btn-sm" href="/suppliers.php?pay=<?= (int) $supplier['id'] ?>#supplierPay">付款</a>
                            <?php endif; ?>
                            <form class="inline-form" method="post" action="/suppliers.php" data-confirm="确认调整该供应商的合作状态？">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= $supplier['cooperation_status'] === '合作中' ? '暂停' : '恢复' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/suppliers.php', ['q' => $keyword, 'status' => $statusFilter, 'rating' => $ratingFilter]) ?>
</section>

<script>
document.addEventListener('click', function (event) {
    var openSupplier = event.target.closest('[data-open-supplier]');
    if (openSupplier) {
        var form = document.getElementById('supplierForm');
        if (form) {
            form.setAttribute('open', 'open');
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    var openPay = event.target.closest('[data-open-pay]');
    if (openPay) {
        var pay = document.getElementById('supplierPay');
        if (pay) {
            pay.setAttribute('open', 'open');
            pay.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
