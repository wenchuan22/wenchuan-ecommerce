<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$types = ['满减', '折扣', '优惠券', '新客礼', '积分', '第二件半价', '秒杀'];
$statusOptions = ['未开始', '进行中', '已结束', '已停用'];
$channels = ['全渠道', '微信小程序', '天猫旗舰店', '抖音小店', '京东旗舰店', '线下门店'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    if ($action === 'create') {
        $name = input('name');
        $type = input('type');
        $code = strtoupper(input('code'));
        $ruleDesc = input('rule_desc');
        $discountValue = input('discount_value', '0');
        $minAmount = input('min_amount', '0');
        $startAt = input('start_at');
        $endAt = input('end_at');
        $status = input('status', '未开始');
        $budget = input('budget', '0');
        $channel = input('channel', '全渠道');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = '请填写活动名称（80 字以内）。';
        }
        if (!in_array($type, $types, true)) {
            $errors[] = '活动类型不合法。';
        }
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $code)) {
            $errors[] = '活动编码需为 3-20 位大写字母或数字。';
        }
        if (!is_numeric($discountValue) || (float) $discountValue < 0 || !is_numeric($minAmount) || (float) $minAmount < 0 || !is_numeric($budget) || (float) $budget < 0) {
            $errors[] = '优惠值、门槛金额与预算需为非负数字。';
        }
        if (strtotime($startAt) === false || strtotime($endAt) === false) {
            $errors[] = '请填写有效的活动起止时间。';
        } elseif (strtotime($endAt) <= strtotime($startAt)) {
            $errors[] = '结束时间需晚于开始时间。';
        }
        if (!in_array($status, $statusOptions, true)) {
            $errors[] = '活动状态不合法。';
        }
        if (!in_array($channel, $channels, true)) {
            $errors[] = '投放渠道不合法。';
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM promotions WHERE code = ?');
        $dup->execute([$code]);
        if ((int) $dup->fetchColumn() > 0) {
            $errors[] = '活动编码已存在。';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/promotions.php');
        }

        $stmt = $pdo->prepare('INSERT INTO promotions (name, type, code, rule_desc, discount_value, min_amount, start_at, end_at, status, used_count, budget, channel, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)');
        $stmt->execute([$name, $type, $code, $ruleDesc, round((float) $discountValue, 2), round((float) $minAmount, 2), date('Y-m-d H:i:s', strtotime($startAt)), date('Y-m-d H:i:s', strtotime($endAt)), $status, round((float) $budget, 2), $channel, date('Y-m-d H:i:s')]);
        audit_log('创建营销活动', $code, $name . '，预算 ¥' . money($budget));
        flash('success', '营销活动「' . $name . '」已创建。');
        redirect('/promotions.php');
    }

    if ($action === 'toggle') {
        $id = (int) input('id', '0');
        $stmt = $pdo->prepare('SELECT * FROM promotions WHERE id = ?');
        $stmt->execute([$id]);
        $promotion = $stmt->fetch();
        if (!$promotion) {
            flash('error', '活动不存在。');
            redirect('/promotions.php');
        }
        if ($promotion['status'] === '已结束') {
            flash('error', '已结束的活动不能重新启用。');
            redirect('/promotions.php');
        }
        $newStatus = $promotion['status'] === '进行中' ? '已停用' : '进行中';
        $update = $pdo->prepare('UPDATE promotions SET status = ? WHERE id = ?');
        $update->execute([$newStatus, $id]);
        audit_log('营销活动状态调整', $promotion['code'], $promotion['name'] . ' 调整为' . $newStatus);
        flash('success', '活动「' . $promotion['name'] . '」已' . ($newStatus === '进行中' ? '启用' : '停用') . '。');
        redirect('/promotions.php');
    }
}

$keyword = q('q');
$statusFilter = q('status');
$typeFilter = q('type');
$page = max(1, (int) q('page', '1'));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(name LIKE ? OR code LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like);
}
if (in_array($statusFilter, $statusOptions, true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if (in_array($typeFilter, $types, true)) {
    $where[] = 'type = ?';
    $params[] = $typeFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM promotions' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = $pdo->prepare('SELECT * FROM promotions' . $whereSql . ' ORDER BY CASE status WHEN "进行中" THEN 0 WHEN "未开始" THEN 1 WHEN "已停用" THEN 2 ELSE 3 END, start_at DESC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset']);
$listStmt->execute($params);
$promotions = $listStmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '进行中' THEN 1 ELSE 0 END) AS active, COALESCE(SUM(used_count), 0) AS used, COALESCE(SUM(budget), 0) AS budget FROM promotions")->fetch() ?: [];

$pageTitle = '营销活动';
$pageSubtitle = '管理满减、折扣、优惠券与会员权益活动';
$active = 'promotions';
$pageActions = '<a class="btn btn-primary" href="#promotionForm" data-open-promotion>+ 创建活动</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>活动总数 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>进行中 <strong><?= (int) ($summary['active'] ?? 0) ?></strong></span>
    <span>累计参与 <strong><?= number_format((int) ($summary['used'] ?? 0)) ?> 人次</strong></span>
    <span>活动总预算 <strong>¥<?= money($summary['budget'] ?? 0) ?></strong></span>
</section>

<details class="create-panel" id="promotionForm">
    <summary>创建营销活动</summary>
    <div class="create-body">
        <form method="post" action="/promotions.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid three">
                <div class="field">
                    <label for="name">活动名称<span class="required">*</span></label>
                    <input id="name" name="name" type="text" placeholder="例如：双十二满减专场" maxlength="80" required>
                </div>
                <div class="field">
                    <label for="type">活动类型</label>
                    <select id="type" name="type">
                        <?php foreach ($types as $type): ?>
                            <option value="<?= e($type) ?>"><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="code">活动编码<span class="required">*</span></label>
                    <input id="code" name="code" type="text" placeholder="WCDOUBLE12" maxlength="20" required>
                </div>
                <div class="field">
                    <label for="discount_value">优惠值</label>
                    <input id="discount_value" name="discount_value" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label for="min_amount">使用门槛（元）</label>
                    <input id="min_amount" name="min_amount" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label for="budget">活动预算（元）</label>
                    <input id="budget" name="budget" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label for="start_at">开始时间<span class="required">*</span></label>
                    <input id="start_at" name="start_at" type="datetime-local" required>
                </div>
                <div class="field">
                    <label for="end_at">结束时间<span class="required">*</span></label>
                    <input id="end_at" name="end_at" type="datetime-local" required>
                </div>
                <div class="field">
                    <label for="status">初始状态</label>
                    <select id="status" name="status">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?= e($status) ?>" <?= $status === '未开始' ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="channel">投放渠道</label>
                    <select id="channel" name="channel">
                        <?php foreach ($channels as $channel): ?>
                            <option value="<?= e($channel) ?>"><?= e($channel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field full">
                    <label for="rule_desc">活动规则</label>
                    <textarea id="rule_desc" name="rule_desc" placeholder="描述优惠门槛、叠加规则与使用限制"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">创建活动</button>
                <span class="form-hint">活动编码需唯一，创建后可在列表中启用或停用。</span>
            </div>
        </form>
    </div>
</details>

<section class="card">
    <form class="filter-bar" method="get" action="/promotions.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">活动搜索</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="活动名称 / 编码">
            </div>
            <div class="filter-field">
                <label for="statusFilter">活动状态</label>
                <select id="statusFilter" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="typeFilter">活动类型</label>
                <select id="typeFilter" name="type">
                    <option value="">全部类型</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/promotions.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 个活动，进行中优先展示</div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>活动</th>
                    <th>类型</th>
                    <th>活动规则</th>
                    <th>活动周期</th>
                    <th>参与 / 预算</th>
                    <th>状态</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$promotions): ?>
                    <tr><td colspan="7"><div class="data-empty"><div class="data-empty-mark">◈</div><strong>暂无匹配活动</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($promotions as $promotion): ?>
                    <?php
                    $estimated = min((float) $promotion['budget'], (float) $promotion['used_count'] * (float) $promotion['discount_value']);
                    $usage = (float) $promotion['budget'] > 0 ? min(100, ($estimated / (float) $promotion['budget']) * 100) : 0;
                    $barClass = $usage >= 85 ? 'danger' : ($usage >= 60 ? 'warn' : '');
                    ?>
                    <tr>
                        <td><span class="cell-main"><?= e($promotion['name']) ?></span><span class="cell-sub mono"><?= e($promotion['code']) ?> · <?= e($promotion['channel']) ?></span></td>
                        <td><span class="role-tag"><?= e($promotion['type']) ?></span></td>
                        <td style="max-width: 270px;"><?= e($promotion['rule_desc'] ?: '—') ?></td>
                        <td class="nowrap"><span class="muted"><?= e(fmt_date($promotion['start_at'])) ?></span><span class="cell-sub">至 <?= e(fmt_date($promotion['end_at'])) ?></span></td>
                        <td>
                            <span class="cell-main"><?= number_format((int) $promotion['used_count']) ?> 人次</span>
                            <div class="usage-cell">
                                <div class="progress"><div class="progress-fill <?= $barClass ?>" style="width: <?= round($usage, 1) ?>%"></div></div>
                                <span>¥<?= money($estimated) ?> / ¥<?= money($promotion['budget']) ?></span>
                            </div>
                        </td>
                        <td><span class="badge <?= status_class($promotion['status']) ?>"><?= e($promotion['status']) ?></span></td>
                        <td class="text-right actions">
                            <?php if ($promotion['status'] !== '已结束'): ?>
                                <form class="inline-form" method="post" action="/promotions.php" data-confirm="确认调整该活动状态？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $promotion['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= $promotion['status'] === '进行中' ? '停用' : '启用' ?></button>
                                </form>
                            <?php else: ?>
                                <span class="muted small">已归档</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/promotions.php', ['q' => $keyword, 'status' => $statusFilter, 'type' => $typeFilter]) ?>
</section>

<script>
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-open-promotion]');
    if (!trigger) { return; }
    var panel = document.getElementById('promotionForm');
    if (panel) {
        panel.setAttribute('open', 'open');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
