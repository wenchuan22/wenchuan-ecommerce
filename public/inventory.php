<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
$adjustTypes = ['采购入库' => 1, '退货入库' => 1, '盘点盘盈' => 1, '盘点盘亏' => -1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'adjust') {
        $productId = (int) input('product_id', '0');
        $changeType = input('change_type');
        $quantity = input('quantity', '0');
        $remark = input('remark');

        if (!isset($adjustTypes[$changeType])) {
            flash('error', '请选择有效的库存变动类型。');
            redirect('/inventory.php');
        }
        if (!is_numeric($quantity) || (int) $quantity <= 0) {
            flash('error', '变动数量需为正整数。');
            redirect('/inventory.php' . ($productId > 0 ? '?product_id=' . $productId : ''));
        }

        $delta = $adjustTypes[$changeType] * (int) $quantity;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new RuntimeException('商品不存在。');
            }
            $before = (int) $product['stock'];
            $after = $before + $delta;
            if ($after < 0) {
                throw new RuntimeException('库存不足，当前可用库存为 ' . $before . ' 件。');
            }
            $update = $pdo->prepare('UPDATE products SET stock = ?, updated_at = ? WHERE id = ?');
            $update->execute([$after, date('Y-m-d H:i:s'), $productId]);
            $insert = $pdo->prepare('INSERT INTO inventory_logs (product_id, sku, product_name, change_type, quantity, before_stock, after_stock, reference_no, operator, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $productId,
                $product['sku'],
                $product['name'],
                $changeType,
                $delta,
                $before,
                $after,
                'ADJ' . date('YmdHis'),
                current_operator(),
                $remark !== '' ? $remark : $changeType,
                date('Y-m-d H:i:s'),
            ]);
            $pdo->commit();
            audit_log('库存调整', $product['sku'], $changeType . ' ' . ($delta > 0 ? '+' : '') . $delta . '，结存 ' . $after);
            flash('success', '库存已更新：' . $product['name'] . ' ' . ($delta > 0 ? '+' : '') . $delta . '，当前库存 ' . $after . ' 件。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
        }
        redirect('/inventory.php' . ($productId > 0 ? '?product_id=' . $productId : ''));
    }
}

$keyword = q('q');
$lowOnly = q('low') === '1';
$categoryFilter = (int) q('category', '0');
$productId = (int) q('product_id', '0');
$page = max(1, (int) q('page', '1'));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like);
}
if ($lowOnly) {
    $where[] = 'p.stock <= p.safety_stock';
}
if ($categoryFilter > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 12, $page);

$listStmt = $pdo->prepare('SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id' . $whereSql . ' ORDER BY (CAST(p.stock AS REAL) / CASE WHEN p.safety_stock = 0 THEN 1 ELSE p.safety_stock END) ASC, p.id ASC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset']);
$listStmt->execute($params);
$products = $listStmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(stock), 0) AS units, COALESCE(SUM(stock * cost_price), 0) AS value, SUM(CASE WHEN status = '在售' AND stock <= safety_stock THEN 1 ELSE 0 END) AS low, SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock FROM products")->fetch() ?: [];

$movementStmt = $pdo->query('SELECT * FROM inventory_logs ORDER BY created_at DESC, id DESC LIMIT 30');
$movements = $movementStmt->fetchAll();

$selectedProduct = null;
if ($productId > 0) {
    $selectedStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $selectedStmt->execute([$productId]);
    $selectedProduct = $selectedStmt->fetch() ?: null;
}

$pageTitle = '库存管理';
$pageSubtitle = '实时库存、预警与出入库流水，所有调整均记录操作人';
$active = 'inventory';
$pageActions = '<a class="btn btn-secondary" href="/products.php">商品档案</a><a class="btn btn-primary" href="#stockAdjust" data-open-adjust>库存调整</a>';
require __DIR__ . '/partials/header.php';
?>

<section class="summary-strip">
    <span>SKU 总数 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>库存件数 <strong><?= number_format((int) ($summary['units'] ?? 0)) ?></strong></span>
    <span>库存成本 <strong>¥<?= money($summary['value'] ?? 0) ?></strong></span>
    <span>库存预警 <strong><?= (int) ($summary['low'] ?? 0) ?></strong></span>
    <span>零库存 <strong><?= (int) ($summary['out_of_stock'] ?? 0) ?></strong></span>
</section>

<details class="create-panel" id="stockAdjust" <?= $selectedProduct ? 'open' : '' ?>>
    <summary>登记库存变动</summary>
    <div class="create-body">
        <form method="post" action="/inventory.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            <div class="form-grid three">
                <div class="field">
                    <label for="product_id">商品<span class="required">*</span></label>
                    <select id="product_id" name="product_id" required>
                        <option value="">请选择商品</option>
                        <?php
                        $allProducts = $pdo->query('SELECT id, sku, name, stock FROM products ORDER BY sku')->fetchAll();
                        foreach ($allProducts as $optionProduct):
                            $selected = $selectedProduct && (int) $selectedProduct['id'] === (int) $optionProduct['id'];
                        ?>
                            <option value="<?= (int) $optionProduct['id'] ?>" <?= $selected ? 'selected' : '' ?>><?= e($optionProduct['sku'] . ' · ' . $optionProduct['name'] . '（库存 ' . $optionProduct['stock'] . '）') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="change_type">变动类型<span class="required">*</span></label>
                    <select id="change_type" name="change_type" required>
                        <?php foreach (array_keys($adjustTypes) as $type): ?>
                            <option value="<?= e($type) ?>"><?= e($type) ?>（<?= $adjustTypes[$type] > 0 ? '增加' : '减少' ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="quantity">变动数量<span class="required">*</span></label>
                    <input id="quantity" name="quantity" type="number" min="1" step="1" placeholder="请输入正整数" required>
                </div>
                <div class="field full">
                    <label for="remark">调整说明</label>
                    <input id="remark" name="remark" type="text" placeholder="例如：采购单 PO20261110001 到货入库" maxlength="120">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">确认调整</button>
                <span class="form-hint">盘点盘亏等减少类操作会校验可用库存，调整后将同步写入流水。</span>
            </div>
        </form>
    </div>
</details>

<section class="card">
    <form class="filter-bar" method="get" action="/inventory.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">商品关键词</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="商品名称 / SKU">
            </div>
            <div class="filter-field">
                <label for="category">分类</label>
                <select id="category" name="category">
                    <option value="0">全部分类</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="low">库存视图</label>
                <select id="low" name="low">
                    <option value="">全部商品</option>
                    <option value="1" <?= $lowOnly ? 'selected' : '' ?>>仅看预警商品</option>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/inventory.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 款商品，按库存健康度排序</div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>商品</th>
                    <th>分类</th>
                    <th>当前库存</th>
                    <th>安全库存</th>
                    <th>库存成本</th>
                    <th>状态</th>
                    <th>最近更新</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="8"><div class="data-empty"><div class="data-empty-mark">▤</div><strong>暂无匹配库存</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <span class="product-avatar <?= product_tone((int) $product['id']) ?>"><?= e(initial_char($product['name'])) ?></span>
                                <span class="product-cell-text"><strong><?= e($product['name']) ?></strong><span><?= e($product['sku']) ?></span></span>
                            </div>
                        </td>
                        <td><?= e($product['category_name']) ?><span class="cell-sub"><?= e($product['brand']) ?></span></td>
                        <td>
                            <span class="stock-value <?= (int) $product['stock'] <= 0 ? 'out' : ((int) $product['stock'] <= (int) $product['safety_stock'] ? 'low' : '') ?>"><?= (int) $product['stock'] ?> 件</span>
                            <?php if ((int) $product['stock'] <= (int) $product['safety_stock']): ?>
                                <span class="stock-hint">预警</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= (int) $product['safety_stock'] ?> 件</td>
                        <td>¥<?= money((float) $product['stock'] * (float) $product['cost_price']) ?></td>
                        <td><span class="badge <?= status_class($product['status']) ?>"><?= e($product['status']) ?></span></td>
                        <td class="muted nowrap"><?= e(fmt_month_day($product['updated_at'])) ?></td>
                        <td class="text-right"><a class="btn btn-ghost btn-sm" href="/inventory.php?product_id=<?= (int) $product['id'] ?>#stockAdjust">调整库存</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/inventory.php', ['q' => $keyword, 'low' => $lowOnly ? '1' : '', 'category' => $categoryFilter]) ?>
</section>

<section class="card" style="margin-top: 18px;">
    <div class="card-head">
        <div>
            <div class="card-title">库存流水</div>
            <div class="card-subtitle">最近 30 条库存变动记录</div>
        </div>
        <span class="offline-note">数据来自出入库与盘点记录</span>
    </div>
    <div class="table-wrap" style="margin-top: 14px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>商品</th>
                    <th>变动类型</th>
                    <th class="text-right">数量</th>
                    <th class="text-right">变动前</th>
                    <th class="text-right">变动后</th>
                    <th>关联单号</th>
                    <th>操作人</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $movement): ?>
                    <tr>
                        <td><span class="cell-main"><?= e($movement['product_name']) ?></span><span class="cell-sub mono"><?= e($movement['sku']) ?></span></td>
                        <td><span class="badge <?= status_class($movement['change_type']) ?>"><?= e($movement['change_type']) ?></span></td>
                        <td class="text-right amount"><?= (int) $movement['quantity'] > 0 ? '+' : '' ?><?= (int) $movement['quantity'] ?></td>
                        <td class="text-right muted"><?= (int) $movement['before_stock'] ?></td>
                        <td class="text-right"><?= (int) $movement['after_stock'] ?></td>
                        <td class="mono"><?= e($movement['reference_no']) ?></td>
                        <td><?= e($movement['operator']) ?><span class="cell-sub"><?= e($movement['remark']) ?></span></td>
                        <td class="muted nowrap"><?= e(fmt_month_day($movement['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-open-adjust]');
    if (!trigger) { return; }
    var panel = document.getElementById('stockAdjust');
    if (panel) {
        panel.setAttribute('open', 'open');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
