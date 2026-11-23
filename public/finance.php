<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$reconStatuses = ['已平账', '待核对', '差异处理'];
$channels = ['微信小程序', '天猫旗舰店', '抖音小店', '京东旗舰店', '线下门店'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');
    $reconId = (int) input('recon_id', '0');

    if (in_array($action, ['confirm', 'adjust'], true)) {
        $stmt = $pdo->prepare('SELECT * FROM reconciliations WHERE id = ?');
        $stmt->execute([$reconId]);
        $recon = $stmt->fetch();
        if (!$recon) {
            flash('error', '对账记录不存在。');
            redirect('/finance.php');
        }
        if ($recon['status'] === '已平账') {
            flash('error', '该账期已经完成平账。');
            redirect('/finance.php');
        }

        if ($action === 'confirm') {
            if (abs((float) $recon['difference_amount']) >= 0.005) {
                flash('error', '该账期存在差异 ¥' . money($recon['difference_amount']) . '，请先完成差异处理。');
                redirect('/finance.php');
            }
            $update = $pdo->prepare("UPDATE reconciliations SET status = '已平账', confirmed_at = ? WHERE id = ?");
            $update->execute([date('Y-m-d H:i:s'), $reconId]);
            audit_log('对账确认', $recon['recon_no'], $recon['channel'] . ' ' . $recon['period'] . ' 确认平账');
            flash('success', '对账记录 ' . $recon['recon_no'] . ' 已确认平账。');
        } else {
            $newNotes = trim((string) $recon['notes'] . '；差异 ¥' . money($recon['difference_amount']) . ' 已按渠道账单调整');
            $update = $pdo->prepare("UPDATE reconciliations SET system_amount = statement_amount, difference_amount = 0, matched_count = total_count, status = '已平账', notes = ?, confirmed_at = ? WHERE id = ?");
            $update->execute([$newNotes, date('Y-m-d H:i:s'), $reconId]);
            audit_log('对账差异调整', $recon['recon_no'], $recon['channel'] . ' ' . $recon['period'] . ' 差异 ¥' . money($recon['difference_amount']) . ' 已调整');
            flash('success', '差异已调整，对账记录 ' . $recon['recon_no'] . ' 已平账。');
        }
        redirect('/finance.php');
    }
}

$reconStatusFilter = q('recon_status');
$channelFilter = q('channel');

$where = [];
$params = [];
if (in_array($reconStatusFilter, $reconStatuses, true)) {
    $where[] = 'status = ?';
    $params[] = $reconStatusFilter;
}
if (in_array($channelFilter, $channels, true)) {
    $where[] = 'channel = ?';
    $params[] = $channelFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$reconStmt = $pdo->prepare('SELECT * FROM reconciliations' . $whereSql . ' ORDER BY period DESC, id DESC LIMIT 30');
$reconStmt->execute($params);
$reconciliations = $reconStmt->fetchAll();

$monthStart = date('Y-m-01') . ' 00:00:00';
$payStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(fee), 0) AS fee, COUNT(*) AS cnt FROM payments WHERE paid_at >= ?');
$payStmt->execute([$monthStart]);
$payStats = $payStmt->fetch() ?: [];
$pendingSettle = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = '待结算'")->fetchColumn();
$refundStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE status = '已退款' AND handled_at >= ?");
$refundStmt->execute([$monthStart]);
$monthRefund = (float) $refundStmt->fetchColumn();
$reconSummary = $pdo->query("SELECT SUM(CASE WHEN status <> '已平账' THEN 1 ELSE 0 END) AS pending, COALESCE(SUM(ABS(difference_amount)), 0) AS diff FROM reconciliations")->fetch() ?: [];

$payments = $pdo->query('SELECT * FROM payments ORDER BY paid_at DESC LIMIT 12')->fetchAll();
$refundRows = $pdo->query("SELECT r.refund_no, r.amount, r.status, r.handled_at, o.order_no FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.status IN ('已退款', '已通过') ORDER BY r.handled_at DESC LIMIT 6")->fetchAll();

$pageTitle = '财务对账';
$pageSubtitle = '渠道账单核对、支付流水与退款结算';
$active = 'finance';
$pageActions = '<a class="btn btn-secondary" href="/refunds.php">退款售后</a><a class="btn btn-primary" href="/reports.php">经营报表</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="finance-summary">
    <article class="finance-tile highlight">
        <span class="tile-label">本月收款</span>
        <span class="tile-value">¥<?= money($payStats['amount'] ?? 0) ?></span>
        <span class="tile-foot"><?= (int) ($payStats['cnt'] ?? 0) ?> 笔支付 · 手续费 ¥<?= money($payStats['fee'] ?? 0) ?></span>
    </article>
    <article class="finance-tile">
        <span class="tile-label">待结算金额</span>
        <span class="tile-value">¥<?= money($pendingSettle) ?></span>
        <span class="tile-foot">按渠道结算周期自动汇总</span>
    </article>
    <article class="finance-tile">
        <span class="tile-label">本月退款</span>
        <span class="tile-value">¥<?= money($monthRefund) ?></span>
        <span class="tile-foot">已完成退款金额</span>
    </article>
    <article class="finance-tile">
        <span class="tile-label">待核对 / 差异</span>
        <span class="tile-value"><?= (int) ($reconSummary['pending'] ?? 0) ?> 笔</span>
        <span class="tile-foot">差异合计 ¥<?= money($reconSummary['diff'] ?? 0) ?></span>
    </article>
</section>

<section class="card">
    <form class="filter-bar" method="get" action="/finance.php">
        <div class="filter-fields">
            <div class="filter-field">
                <label for="reconStatusFilter">对账状态</label>
                <select id="reconStatusFilter" name="recon_status">
                    <option value="">全部状态</option>
                    <?php foreach ($reconStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $reconStatusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="channelFilter">销售渠道</label>
                <select id="channelFilter" name="channel">
                    <option value="">全部渠道</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= e($channel) ?>" <?= $channelFilter === $channel ? 'selected' : '' ?>><?= e($channel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/finance.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="card-head" style="padding-bottom: 0;">
        <div>
            <div class="card-title">渠道对账单</div>
            <div class="card-subtitle">系统流水与渠道账单逐日核对，差异需登记后平账</div>
        </div>
        <span class="offline-note">差异 = 账单金额 - 系统金额</span>
    </div>
    <div class="table-wrap" style="margin-top: 14px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>对账单号</th>
                    <th>账期</th>
                    <th>渠道</th>
                    <th class="text-right">渠道账单</th>
                    <th class="text-right">系统金额</th>
                    <th class="text-right">差异</th>
                    <th>匹配进度</th>
                    <th>状态</th>
                    <th>负责人</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$reconciliations): ?>
                    <tr><td colspan="10"><div class="data-empty"><div class="data-empty-mark">▥</div><strong>暂无对账记录</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($reconciliations as $recon): ?>
                    <?php
                    $diff = (float) $recon['difference_amount'];
                    $diffClass = abs($diff) < 0.005 ? 'diff-zero' : ($diff > 0 ? 'diff-positive' : 'diff-negative');
                    $matchRate = (int) $recon['total_count'] > 0 ? min(100, ((int) $recon['matched_count'] / (int) $recon['total_count']) * 100) : 0;
                    ?>
                    <tr>
                        <td class="mono"><?= e($recon['recon_no']) ?></td>
                        <td class="nowrap"><?= e(fmt_date($recon['period'])) ?></td>
                        <td><?= e($recon['channel']) ?></td>
                        <td class="text-right">¥<?= money($recon['statement_amount']) ?></td>
                        <td class="text-right">¥<?= money($recon['system_amount']) ?></td>
                        <td class="text-right <?= $diffClass ?>"><?= $diff > 0 ? '+' : '' ?>¥<?= money($diff) ?></td>
                        <td>
                            <div class="usage-cell">
                                <div class="progress"><div class="progress-fill <?= $matchRate < 100 ? 'warn' : '' ?>" style="width: <?= round($matchRate, 1) ?>%"></div></div>
                                <span><?= (int) $recon['matched_count'] ?>/<?= (int) $recon['total_count'] ?></span>
                            </div>
                        </td>
                        <td><span class="badge <?= status_class($recon['status']) ?>"><?= e($recon['status']) ?></span></td>
                        <td><?= e($recon['owner']) ?><span class="cell-sub"><?= e(fmt_date($recon['confirmed_at'])) ?></span></td>
                        <td class="text-right">
                            <?php if ($recon['status'] === '已平账'): ?>
                                <span class="muted small">已确认</span>
                            <?php elseif (abs($diff) < 0.005): ?>
                                <form class="inline-form" method="post" action="/finance.php" data-confirm="确认该账期已核对无误并平账？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="recon_id" value="<?= (int) $recon['id'] ?>">
                                    <button class="btn btn-secondary btn-sm" type="submit">确认平账</button>
                                </form>
                            <?php else: ?>
                                <form class="inline-form" method="post" action="/finance.php" data-confirm="确认按渠道账单调整差异并完成平账？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="adjust">
                                    <input type="hidden" name="recon_id" value="<?= (int) $recon['id'] ?>">
                                    <button class="btn btn-orange btn-sm" type="submit">差异调整</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-foot">
        <span>对账周期：按渠道日结单逐日核对</span>
        <span>最近同步 <?= e(date('Y-m-d H:i')) ?></span>
    </div>
</section>

<div class="grid grid-2" style="margin-top: 18px;">
    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">支付流水</div>
                <div class="card-subtitle">最近 12 笔渠道收款记录</div>
            </div>
            <a class="btn-link" href="/reports.php">成交报表</a>
        </div>
        <div class="table-wrap" style="margin-top: 14px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>支付单号</th>
                        <th>订单号</th>
                        <th>渠道</th>
                        <th class="text-right">金额</th>
                        <th>状态</th>
                        <th>支付时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="mono"><?= e($payment['payment_no']) ?><span class="cell-sub">手续费 ¥<?= money($payment['fee']) ?></span></td>
                            <td><a class="mono" href="/orders.php?q=<?= rawurlencode($payment['order_no']) ?>"><?= e($payment['order_no']) ?></a></td>
                            <td><?= e($payment['channel']) ?></td>
                            <td class="text-right amount">¥<?= money($payment['amount']) ?></td>
                            <td><span class="badge <?= status_class($payment['status']) ?>"><?= e($payment['status']) ?></span></td>
                            <td class="muted nowrap"><?= e(fmt_month_day($payment['paid_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <div class="card-title">退款结算</div>
                <div class="card-subtitle">已通过或已完成的退款单</div>
            </div>
            <a class="btn-link" href="/refunds.php">售后管理</a>
        </div>
        <div class="table-wrap" style="margin-top: 14px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>退款单号</th>
                        <th>订单号</th>
                        <th class="text-right">退款金额</th>
                        <th>状态</th>
                        <th>处理时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$refundRows): ?>
                        <tr><td colspan="5"><div class="data-empty" style="padding: 26px 10px;"><strong>暂无退款结算</strong>当前没有已处理的退款。</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($refundRows as $refund): ?>
                        <tr>
                            <td class="mono"><?= e($refund['refund_no']) ?></td>
                            <td class="mono"><?= e($refund['order_no']) ?></td>
                            <td class="text-right amount">¥<?= money($refund['amount']) ?></td>
                            <td><span class="badge <?= status_class($refund['status']) ?>"><?= e($refund['status']) ?></span></td>
                            <td class="muted nowrap"><?= e(fmt_month_day($refund['handled_at'])) ?></td>
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
            <div class="card-title">对账规则</div>
            <div class="card-subtitle">渠道账单与系统流水的核对口径</div>
        </div>
    </div>
    <div class="card-body">
        <div class="two-col-note">
            <div class="note-box">
                <strong>自动核对</strong><br>
                系统按渠道日结单逐笔匹配支付流水，匹配成功计入已核对笔数；退款、平台补贴等单独列示。
            </div>
            <div class="note-box">
                <strong>差异处理</strong><br>
                差异不为零的账期需核实原因，按渠道账单调整系统金额后方可平账；所有调整记录审计日志。
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
