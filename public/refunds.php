<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$refundStatuses = ['待审核', '已通过', '已驳回', '已退款'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'handle') {
        $refundId = (int) input('refund_id', '0');
        $decision = input('decision');
        $remark = input('remark');

        $stmt = $pdo->prepare('SELECT r.*, o.order_no, o.payable_amount AS order_amount, o.status AS order_status FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.id = ?');
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch();

        if (!$refund) {
            flash('error', '退款单不存在。');
            redirect('/refunds.php');
        }
        if ($refund['status'] !== '待审核') {
            flash('error', '该退款单已处理，不能重复操作。');
            redirect('/refunds.php');
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            flash('error', '请选择处理结果。');
            redirect('/refunds.php');
        }

        $now = date('Y-m-d H:i:s');
        $handler = current_operator();

        try {
            $pdo->beginTransaction();
            if ($decision === 'approve') {
                $update = $pdo->prepare("UPDATE refunds SET status = '已退款', handled_at = ?, handler = ?, remark = ? WHERE id = ?");
                $update->execute([$now, $handler, $remark !== '' ? $remark : $refund['remark'], $refundId]);

                $isFull = (float) $refund['amount'] >= (float) $refund['order_amount'] - 0.01;
                if ($isFull) {
                    $orderUpdate = $pdo->prepare("UPDATE orders SET payment_status = '已退款', status = '已关闭' WHERE id = ?");
                } else {
                    $orderUpdate = $pdo->prepare("UPDATE orders SET payment_status = '部分退款', status = CASE WHEN status = '退款中' AND shipped_at IS NOT NULL THEN '已发货' WHEN status = '退款中' THEN '待发货' ELSE status END WHERE id = ?");
                }
                $orderUpdate->execute([(int) $refund['order_id']]);
                $pdo->commit();
                audit_log('退款审核通过', $refund['refund_no'], '退款 ¥' . money($refund['amount']) . '，订单 ' . $refund['order_no']);
                flash('success', '退款单 ' . $refund['refund_no'] . ' 已通过，退款金额 ¥' . money($refund['amount']) . '。');
            } else {
                $update = $pdo->prepare("UPDATE refunds SET status = '已驳回', handled_at = ?, handler = ?, remark = ? WHERE id = ?");
                $update->execute([$now, $handler, $remark !== '' ? $remark : $refund['remark'], $refundId]);
                $pdo->commit();
                audit_log('退款审核驳回', $refund['refund_no'], '订单 ' . $refund['order_no'] . ' 的退款申请已驳回');
                flash('success', '退款单 ' . $refund['refund_no'] . ' 已驳回。');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', '处理失败：' . $e->getMessage());
        }
        redirect('/refunds.php');
    }
}

$keyword = q('q');
$statusFilter = q('status');
$page = max(1, (int) q('page', '1'));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(r.refund_no LIKE ? OR o.order_no LIKE ? OR c.name LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($statusFilter, $refundStatuses, true)) {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM refunds r JOIN orders o ON o.id = r.order_id JOIN customers c ON c.id = r.customer_id' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = $pdo->prepare('SELECT r.*, o.order_no, o.status AS order_status, c.name AS customer_name, c.phone AS customer_phone FROM refunds r JOIN orders o ON o.id = r.order_id JOIN customers c ON c.id = r.customer_id' . $whereSql . ' ORDER BY CASE r.status WHEN "待审核" THEN 0 ELSE 1 END, r.applied_at DESC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset']);
$listStmt->execute($params);
$refunds = $listStmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '待审核' THEN 1 ELSE 0 END) AS pending, COALESCE(SUM(CASE WHEN status = '已通过' THEN amount ELSE 0 END), 0) AS approved, COALESCE(SUM(CASE WHEN status = '已退款' THEN amount ELSE 0 END), 0) AS refunded, SUM(CASE WHEN status = '已驳回' THEN 1 ELSE 0 END) AS rejected FROM refunds")->fetch() ?: [];

$pageTitle = '退款售后';
$pageSubtitle = '审核退款申请，跟踪售后处理进度与退款金额';
$active = 'refunds';
$pageActions = '<a class="btn btn-secondary" href="/orders.php">订单中心</a><a class="btn btn-primary" href="/finance.php">财务对账</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>退款单总数 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>待审核 <strong><?= (int) ($summary['pending'] ?? 0) ?></strong></span>
    <span>已通过金额 <strong>¥<?= money($summary['approved'] ?? 0) ?></strong></span>
    <span>已退款金额 <strong>¥<?= money($summary['refunded'] ?? 0) ?></strong></span>
    <span>已驳回 <strong><?= (int) ($summary['rejected'] ?? 0) ?></strong></span>
</section>

<section class="card">
    <form class="filter-bar" method="get" action="/refunds.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">售后搜索</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="退款单号 / 订单号 / 客户">
            </div>
            <div class="filter-field">
                <label for="statusFilter">处理状态</label>
                <select id="statusFilter" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($refundStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/refunds.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 笔售后，待审核优先展示</div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>退款单</th>
                    <th>客户 / 订单</th>
                    <th>退款金额</th>
                    <th>申请原因</th>
                    <th>申请时间</th>
                    <th>状态</th>
                    <th>处理人</th>
                    <th class="text-right">审核操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$refunds): ?>
                    <tr><td colspan="8"><div class="data-empty"><div class="data-empty-mark">↺</div><strong>暂无匹配售后</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($refunds as $refund): ?>
                    <tr>
                        <td>
                            <span class="cell-main mono"><?= e($refund['refund_no']) ?></span>
                            <?php if ($refund['attachment']): ?>
                                <span class="cell-sub"><a href="/wc-uploads/refunds/<?= rawurlencode((string) $refund['attachment']) ?>" target="_blank" rel="noopener">查看凭证</a></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="customer-cell">
                                <span class="customer-avatar"><?= e(initial_char($refund['customer_name'])) ?></span>
                                <span class="customer-cell-text">
                                    <strong><?= e($refund['customer_name']) ?></strong>
                                    <span><a class="mono" href="/order_detail.php?id=<?= (int) $refund['order_id'] ?>"><?= e($refund['order_no']) ?></a></span>
                                </span>
                            </span>
                        </td>
                        <td class="amount">¥<?= money($refund['amount']) ?></td>
                        <td style="max-width: 250px;" title="<?= e($refund['reason']) ?>"><?= e($refund['reason']) ?><span class="cell-sub"><?= e($refund['remark'] ?: '暂无处理备注') ?></span></td>
                        <td class="muted nowrap"><?= e(fmt_month_day($refund['applied_at'])) ?></td>
                        <td><span class="badge <?= status_class($refund['status']) ?>"><?= e($refund['status']) ?></span></td>
                        <td><?= e($refund['handler'] ?: '—') ?><span class="cell-sub"><?= e(fmt_month_day($refund['handled_at'])) ?></span></td>
                        <td class="text-right">
                            <?php if ($refund['status'] === '待审核'): ?>
                                <form class="inline-form" method="post" action="/refunds.php" data-confirm="确认提交该售后处理结果？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="handle">
                                    <input type="hidden" name="refund_id" value="<?= (int) $refund['id'] ?>">
                                    <input type="text" name="remark" placeholder="处理备注" maxlength="80" aria-label="处理备注">
                                    <button class="btn btn-orange btn-sm" type="submit" name="decision" value="approve">通过</button>
                                    <button class="btn btn-ghost btn-sm" type="submit" name="decision" value="reject">驳回</button>
                                </form>
                            <?php else: ?>
                                <span class="muted small"><?= $refund['status'] === '已退款' ? '退款已完成' : '流程已结束' ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/refunds.php', ['q' => $keyword, 'status' => $statusFilter]) ?>
</section>

<section class="card" style="margin-top: 18px;">
    <div class="card-head">
        <div>
            <div class="card-title">售后处理说明</div>
            <div class="card-subtitle">审核操作将同步更新关联订单的支付状态</div>
        </div>
    </div>
    <div class="card-body">
        <div class="two-col-note">
            <div class="note-box">
                <strong>全额退款</strong><br>
                退款金额达到订单实付金额时，订单支付状态更新为“已退款”，订单状态更新为“已关闭”。
            </div>
            <div class="note-box">
                <strong>部分退款</strong><br>
                退款金额低于订单实付金额时，订单支付状态更新为“部分退款”，订单继续保留履约状态。
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
