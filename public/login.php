<?php
require_once __DIR__ . '/../lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = '请输入账号和密码。';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND status = ?');
        $stmt->execute([$username, '启用']);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            login_user($user);
            $update = db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
            $update->execute([date('Y-m-d H:i:s'), $user['id']]);
            audit_log('账号登录', $user['username'], '登录闻川商城管理系统');
            redirect('/dashboard.php');
        }
        $error = '账号或密码不正确，请重试。';
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-aside">
        <div class="login-aside-top">
            <div class="brand brand-light">
                <div class="brand-mark">川</div>
                <div class="brand-text">
                    <strong>闻川商城</strong>
                    <span>WENCHUAN MALL</span>
                </div>
            </div>

            <div class="login-hero">
                <span class="login-eyebrow">Omni-channel Commerce</span>
                <h1>让每一次经营决策<br>都有数据支撑</h1>
                <p class="login-slogan">面向全渠道零售的一体化运营平台，打通商品、订单、库存、会员与结算，让日常运营更轻、更快、更清晰。</p>
                <ul class="login-features">
                    <li><span>01</span> 全渠道订单统一履约与售后处理</li>
                    <li><span>02</span> 商品与库存实时联动，预警及时触达</li>
                    <li><span>03</span> 经营报表与财务对账一体化呈现</li>
                </ul>
            </div>
        </div>

        <div class="login-aside-foot">
            <div class="login-stat">
                <strong>1,860+</strong>
                <span>合作品牌与商家</span>
            </div>
            <div class="login-stat">
                <strong>11</strong>
                <span>核心业务模块</span>
            </div>
            <div class="login-stat">
                <strong>99.95%</strong>
                <span>平台可用性</span>
            </div>
        </div>
    </section>

    <section class="login-main">
        <div class="login-panel">
            <div class="login-main-head">
                <span class="login-eyebrow">闻川电子商务有限公司</span>
                <h2>登录运营工作台</h2>
                <p>使用内部账号进入闻川商城管理中心</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="username">账号</label>
                    <input id="username" name="username" type="text" value="<?= e($username) ?>" placeholder="请输入内部账号" required autofocus>
                </div>
                <div class="field">
                    <label for="password">密码</label>
                    <div class="password-wrap">
                        <input id="password" name="password" type="password" placeholder="请输入登录密码" required>
                        <button class="password-toggle" type="button" data-password-toggle aria-label="显示密码">显示</button>
                    </div>
                </div>
                <div class="login-meta">
                    <span>登录后所有操作将记录审计日志</span>
                    <span>忘记密码请联系信息中心</span>
                </div>
                <button class="btn btn-primary btn-block btn-lg" type="submit">登录系统</button>
            </form>

            <div class="login-tip">
                <span class="login-tip-mark">i</span>
                本系统仅限闻川电子商务有限公司内部授权人员使用，操作行为将记录至审计日志。请妥善保管账号凭证，勿在公共设备上保持登录。
            </div>
            <p class="login-copy">© 2026 闻川电子商务有限公司 · 浙 ICP 备 2026 0188 号 · v<?= APP_VERSION ?></p>
        </div>
    </section>
</main>
<script src="/assets/js/app.js"></script>
</body>
</html>
