<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$roles = ['系统管理员', '运营主管', '财务专员', '仓储主管'];
$user = current_user();
$isAdmin = is_system_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isAdmin) {
        flash('error', '仅系统管理员可维护内部账号。');
        redirect('/users.php');
    }

    $action = input('action');
    if ($action === 'create') {
        $username = strtolower(input('username'));
        $realName = input('real_name');
        $role = input('role');
        $department = input('department');
        $phone = input('phone');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{2,19}$/', $username)) {
            $errors[] = '账号需为 3-20 位小写字母、数字或下划线，并以字母开头。';
        }
        if ($realName === '' || mb_strlen($realName) > 30) {
            $errors[] = '请填写姓名（30 字以内）。';
        }
        if (!in_array($role, $roles, true)) {
            $errors[] = '角色不合法。';
        }
        if ($password === '' || strlen($password) < 8) {
            $errors[] = '密码长度至少 8 位。';
        }
        if ($password !== $confirm) {
            $errors[] = '两次输入的密码不一致。';
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $dup->execute([$username]);
        if ((int) $dup->fetchColumn() > 0) {
            $errors[] = '该账号已存在。';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/users.php');
        }

        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, real_name, role, department, phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $realName, $role, $department, $phone, '启用', date('Y-m-d H:i:s')]);
        audit_log('新增内部账号', $username, $realName . ' · ' . $role);
        flash('success', '内部账号 ' . $username . ' 已创建。');
        redirect('/users.php');
    }

    if ($action === 'toggle') {
        $id = (int) input('id', '0');
        if ($id === (int) $user['id']) {
            flash('error', '不能停用当前登录账号。');
            redirect('/users.php');
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash('error', '账号不存在。');
            redirect('/users.php');
        }
        if ($target['status'] === '启用' && $target['role'] === '系统管理员') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = '系统管理员' AND status = '启用'")->fetchColumn();
            if ($adminCount <= 1) {
                flash('error', '至少需要保留一个启用状态的系统管理员账号。');
                redirect('/users.php');
            }
        }
        $newStatus = $target['status'] === '启用' ? '停用' : '启用';
        $update = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $update->execute([$newStatus, $id]);
        audit_log('账号状态调整', $target['username'], $target['real_name'] . ' 调整为' . $newStatus);
        flash('success', '账号 ' . $target['username'] . ' 已' . ($newStatus === '启用' ? '启用' : '停用') . '。');
        redirect('/users.php');
    }
}

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '启用' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN role = '系统管理员' THEN 1 ELSE 0 END) AS admins FROM users")->fetch() ?: [];
$todayLogCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE substr(created_at, 1, 10) = '" . date('Y-m-d') . "'")->fetchColumn();
$users = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
$logs = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 20')->fetchAll();

$pageTitle = '账号与权限';
$pageSubtitle = '内部账号、登录状态与关键操作审计';
$active = 'users';
$pageActions = $isAdmin ? '<a class="btn btn-primary" href="#userForm" data-open-user>+ 新增账号</a>' : '';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>内部账号 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>启用中 <strong><?= (int) ($summary['active'] ?? 0) ?></strong></span>
    <span>系统管理员 <strong><?= (int) ($summary['admins'] ?? 0) ?></strong></span>
    <span>今日操作 <strong><?= $todayLogCount ?></strong></span>
</section>

<?php if ($isAdmin): ?>
    <details class="create-panel" id="userForm">
        <summary>新增内部账号</summary>
        <div class="create-body">
            <form method="post" action="/users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-grid three">
                    <div class="field">
                        <label for="username">登录账号<span class="required">*</span></label>
                        <input id="username" name="username" type="text" placeholder="例如：ops02" maxlength="20" required>
                    </div>
                    <div class="field">
                        <label for="real_name">姓名<span class="required">*</span></label>
                        <input id="real_name" name="real_name" type="text" placeholder="请输入真实姓名" maxlength="30" required>
                    </div>
                    <div class="field">
                        <label for="role">角色</label>
                        <select id="role" name="role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role) ?>"><?= e($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="department">所属部门</label>
                        <input id="department" name="department" type="text" placeholder="运营中心">
                    </div>
                    <div class="field">
                        <label for="phone">联系电话</label>
                        <input id="phone" name="phone" type="text" placeholder="手机号或办公电话">
                    </div>
                    <div class="field">
                        <label for="password">初始密码<span class="required">*</span></label>
                        <input id="password" name="password" type="password" placeholder="至少 8 位" required>
                    </div>
                    <div class="field">
                        <label for="password_confirm">确认密码<span class="required">*</span></label>
                        <input id="password_confirm" name="password_confirm" type="password" placeholder="再次输入密码" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">创建账号</button>
                    <span class="form-hint">新账号默认启用，密码使用安全哈希存储。</span>
                </div>
            </form>
        </div>
    </details>
<?php else: ?>
    <div class="alert alert-info" style="margin-bottom: 18px;">当前账号为运营角色，仅可查看账号列表与审计日志；账号维护请联系系统管理员。</div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <div class="card-title">内部账号</div>
            <div class="card-subtitle">共 <?= count($users) ?> 个账号，包含系统管理员与业务角色</div>
        </div>
    </div>
    <div class="table-wrap" style="margin-top: 12px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>账号</th>
                    <th>姓名</th>
                    <th>角色</th>
                    <th>部门</th>
                    <th>联系电话</th>
                    <th>最近登录</th>
                    <th>状态</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td>
                            <span class="cell-main mono"><?= e($row['username']) ?></span>
                            <?php if ((int) $row['id'] === (int) $user['id']): ?><span class="cell-sub">当前登录账号</span><?php endif; ?>
                        </td>
                        <td><?= e($row['real_name']) ?></td>
                        <td><span class="role-tag"><?= e($row['role']) ?></span></td>
                        <td><?= e($row['department'] ?: '—') ?></td>
                        <td><?= e($row['phone'] ?: '—') ?></td>
                        <td class="muted nowrap"><?= e(fmt_datetime($row['last_login_at'])) ?></td>
                        <td><span class="badge <?= status_class($row['status']) ?>"><?= e($row['status']) ?></span></td>
                        <td class="text-right">
                            <?php if ($isAdmin && (int) $row['id'] !== (int) $user['id']): ?>
                                <form class="inline-form" method="post" action="/users.php" data-confirm="确认<?= $row['status'] === '启用' ? '停用' : '启用' ?>该账号？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= $row['status'] === '启用' ? '停用' : '启用' ?></button>
                                </form>
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top: 18px;">
    <div class="card-head">
        <div>
            <div class="card-title">操作审计</div>
            <div class="card-subtitle">最近 20 条关键操作记录</div>
        </div>
        <span class="offline-note">记录保留 180 天</span>
    </div>
    <div class="table-wrap" style="margin-top: 12px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>操作人</th>
                    <th>操作</th>
                    <th>对象</th>
                    <th>详情</th>
                    <th>来源 IP</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="6"><div class="data-empty"><strong>暂无审计记录</strong>系统尚未记录关键操作。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e($log['username']) ?></td>
                        <td><span class="cell-main"><?= e($log['action']) ?></span></td>
                        <td class="mono"><?= e($log['target']) ?></td>
                        <td style="max-width: 320px;"><?= e($log['detail']) ?></td>
                        <td class="mono muted"><?= e($log['ip']) ?></td>
                        <td class="muted nowrap"><?= e(fmt_datetime($log['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-open-user]');
    if (!trigger) { return; }
    var panel = document.getElementById('userForm');
    if (panel) {
        panel.setAttribute('open', 'open');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
