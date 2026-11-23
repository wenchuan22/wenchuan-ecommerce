<?php
$user = current_user();
$flashes = take_flashes();
$today = date('Y年n月j日');
$active = $active ?? '';
$pageTitle = $pageTitle ?? '工作台';
$pageSubtitle = $pageSubtitle ?? '';
$pageActions = $pageActions ?? '';

$navCounts = ['ship' => 0, 'refund' => 0];
try {
    $navCounts = db()->query("SELECT (SELECT COUNT(*) FROM orders WHERE status = '待发货') AS ship, (SELECT COUNT(*) FROM refunds WHERE status = '待审核') AS refund")->fetch() ?: $navCounts;
} catch (Throwable $e) {
    // 导航计数不影响页面渲染
}

$navGroups = [
    '概览' => [
        ['dashboard.php', '工作台', '◫', 'dashboard', null],
    ],
    '交易管理' => [
        ['orders.php', '订单管理', '▦', 'orders', (int) $navCounts['ship']],
        ['refunds.php', '退款售后', '↺', 'refunds', (int) $navCounts['refund']],
        ['customers.php', '客户管理', '◉', 'customers', null],
    ],
    '商品运营' => [
        ['products.php', '商品管理', '◧', 'products', null],
        ['inventory.php', '库存管理', '▤', 'inventory', null],
        ['promotions.php', '营销活动', '◈', 'promotions', null],
    ],
    '供应链' => [
        ['suppliers.php', '供应商管理', '◎', 'suppliers', null],
    ],
    '财务与报表' => [
        ['finance.php', '财务对账', '▥', 'finance', null],
        ['reports.php', '报表中心', '▧', 'reports', null],
    ],
    '系统设置' => [
        ['users.php', '账号与权限', '⚙', 'users', null],
    ],
];
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-mark">川</div>
            <div class="brand-text">
                <strong>闻川商城</strong>
                <span>WENCHUAN MALL</span>
            </div>
        </div>

        <div class="store-switch">
            <div class="store-dot"></div>
            <div>
                <strong>闻川商城 · 主店</strong>
                <span>全渠道运营中</span>
            </div>
            <span class="store-chevron">⌄</span>
        </div>

        <nav class="side-nav">
            <?php foreach ($navGroups as $navGroupLabel => $navItems): ?>
                <div class="nav-group">
                    <div class="nav-group-label"><?= e($navGroupLabel) ?></div>
                    <?php foreach ($navItems as [$navHref, $navLabel, $navIcon, $navKey, $navCount]): ?>
                        <a class="nav-link<?= $active === $navKey ? ' is-active' : '' ?>" href="/<?= e($navHref) ?>">
                            <span class="nav-icon" aria-hidden="true"><?= $navIcon ?></span>
                            <span class="nav-label"><?= e($navLabel) ?></span>
                            <?php if ($navCount !== null && $navCount > 0): ?>
                                <span class="nav-count"><?= $navCount > 99 ? '99+' : $navCount ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <div class="side-note">
                <span class="side-note-dot"></span>
                数据同步正常
            </div>
            <span>最近同步 <?= date('H:i') ?> · 杭州运营中心</span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="nav-toggle" id="navToggle" type="button" aria-label="打开导航">
                    <span></span><span></span><span></span>
                </button>
                <div class="crumb">
                    <span>闻川商城</span>
                    <em>/</em>
                    <strong><?= e($pageTitle) ?></strong>
                </div>
            </div>
            <div class="topbar-right">
                <form class="quick-search" method="get" action="/orders.php">
                    <span class="quick-search-icon">⌕</span>
                    <input type="search" name="q" placeholder="搜索订单号 / 收件人" aria-label="搜索订单">
                </form>
                <span class="topbar-date"><?= e($today) ?></span>
                <span class="topbar-sep"></span>
                <div class="topbar-user">
                    <span class="avatar"><?= e(mb_substr($user['real_name'] ?? '川', 0, 1)) ?></span>
                    <span class="user-meta">
                        <strong><?= e($user['real_name'] ?? '') ?></strong>
                        <small><?= e($user['role'] ?? '') ?></small>
                    </span>
                </div>
                <a class="logout-link" href="/logout.php">退出</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <h1 class="page-title"><?= e($pageTitle) ?></h1>
                    <?php if ($pageSubtitle !== ''): ?>
                        <p class="page-subtitle"><?= e($pageSubtitle) ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($pageActions !== ''): ?>
                    <div class="page-actions"><?= $pageActions ?></div>
                <?php endif; ?>
            </div>

            <?php foreach ($flashes as $flash): ?>
                <div class="flash flash-<?= e($flash['type']) ?>">
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" class="flash-close" aria-label="关闭">×</button>
                </div>
            <?php endforeach; ?>
