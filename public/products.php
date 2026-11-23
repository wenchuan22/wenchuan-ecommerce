<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
$allowedStatuses = ['在售', '下架', '预售'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    if ($action === 'create' || $action === 'update') {
        $id = (int) input('id', '0');
        $name = input('name');
        $sku = strtoupper(input('sku'));
        $categoryId = (int) input('category_id', '0');
        $brand = input('brand');
        $unit = input('unit', '件');
        $costPrice = input('cost_price', '0');
        $salePrice = input('sale_price', '0');
        $marketPrice = input('market_price', '0');
        $safetyStock = input('safety_stock', '0');
        $status = input('status', '在售');
        $description = input('description');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = '请填写商品名称（80 字以内）。';
        }
        if ($sku === '' || !preg_match('/^[A-Z0-9-]{4,32}$/', $sku)) {
            $errors[] = 'SKU 需为 4-32 位大写字母、数字或短横线。';
        }
        if ($categoryId <= 0) {
            $errors[] = '请选择商品分类。';
        }
        if (!is_numeric($costPrice) || !is_numeric($salePrice) || !is_numeric($marketPrice) || (float) $costPrice < 0 || (float) $salePrice < 0 || (float) $marketPrice < 0) {
            $errors[] = '价格需为非负数字。';
        }
        if ((float) $salePrice < (float) $costPrice) {
            $errors[] = '销售价不应低于成本价。';
        }
        if (!is_numeric($safetyStock) || (int) $safetyStock < 0) {
            $errors[] = '安全库存需为非负整数。';
        }
        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = '商品状态不合法。';
        }

        $dupSql = 'SELECT COUNT(*) FROM products WHERE sku = ?' . ($id > 0 ? ' AND id <> ?' : '');
        $dupStmt = $pdo->prepare($dupSql);
        $dupStmt->execute($id > 0 ? [$sku, $id] : [$sku]);
        if ((int) $dupStmt->fetchColumn() > 0) {
            $errors[] = '该 SKU 已存在，请使用新的编码。';
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/products.php' . ($id > 0 ? '?edit=' . $id : ''));
        }

        if ($action === 'create') {
            $stock = input('stock', '0');
            if (!is_numeric($stock) || (int) $stock < 0) {
                flash('error', '初始库存需为非负整数。');
                redirect('/products.php');
            }
            $stmt = $pdo->prepare('INSERT INTO products (sku, name, category_id, brand, unit, cost_price, sale_price, market_price, stock, safety_stock, status, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$sku, $name, $categoryId, $brand, $unit, round((float) $costPrice, 2), round((float) $salePrice, 2), round((float) $marketPrice, 2), (int) $stock, (int) $safetyStock, $status, $description, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            audit_log('新增商品', $sku, $name . '，初始库存 ' . (int) $stock);
            flash('success', '商品「' . $name . '」已创建。');
        } else {
            $stmt = $pdo->prepare('UPDATE products SET sku = ?, name = ?, category_id = ?, brand = ?, unit = ?, cost_price = ?, sale_price = ?, market_price = ?, safety_stock = ?, status = ?, description = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$sku, $name, $categoryId, $brand, $unit, round((float) $costPrice, 2), round((float) $salePrice, 2), round((float) $marketPrice, 2), (int) $safetyStock, $status, $description, date('Y-m-d H:i:s'), $id]);
            audit_log('编辑商品', $sku, $name);
            flash('success', '商品信息已更新。');
        }
        redirect('/products.php');
    }

    if ($action === 'toggle') {
        $id = (int) input('id', '0');
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) {
            flash('error', '商品不存在。');
            redirect('/products.php');
        }
        $newStatus = $product['status'] === '在售' ? '下架' : '在售';
        $update = $pdo->prepare('UPDATE products SET status = ?, updated_at = ? WHERE id = ?');
        $update->execute([$newStatus, date('Y-m-d H:i:s'), $id]);
        audit_log('商品上下架', $product['sku'], $product['name'] . ' 调整为' . $newStatus);
        flash('success', '商品「' . $product['name'] . '」已' . ($newStatus === '在售' ? '上架' : '下架') . '。');
        redirect('/products.php');
    }
}

$editProduct = null;
$editId = (int) q('edit', '0');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$editId]);
    $editProduct = $stmt->fetch() ?: null;
}

$keyword = q('q');
$categoryFilter = (int) q('category', '0');
$statusFilter = q('status');
$page = max(1, (int) q('page', '1'));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if ($categoryFilter > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryFilter;
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'p.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
$countStmt->execute($params);
$pageInfo = paged_result((int) $countStmt->fetchColumn(), 12, $page);

$listSql = 'SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id' . $whereSql . ' ORDER BY p.id DESC LIMIT ' . (int) $pageInfo['limit'] . ' OFFSET ' . (int) $pageInfo['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$products = $listStmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '在售' THEN 1 ELSE 0 END) AS on_sale, COALESCE(SUM(stock), 0) AS units, COALESCE(SUM(stock * cost_price), 0) AS stock_value, SUM(CASE WHEN status = '在售' AND stock <= safety_stock THEN 1 ELSE 0 END) AS low_stock FROM products")->fetch() ?: [];

$pageTitle = '商品管理';
$pageSubtitle = '维护商品档案、价格与上下架状态，库存数量统一在库存管理中调整';
$active = 'products';
$pageActions = '<a class="btn btn-secondary" href="/inventory.php">库存总览</a><a class="btn btn-primary" href="#productForm" data-open-form>+ 新增商品</a>';
require __DIR__ . '/partials/header.php';

$form = $editProduct ?: [
    'id' => 0,
    'name' => '',
    'sku' => '',
    'category_id' => 0,
    'brand' => '',
    'unit' => '件',
    'cost_price' => '',
    'sale_price' => '',
    'market_price' => '',
    'stock' => '0',
    'safety_stock' => '10',
    'status' => '在售',
    'description' => '',
];
?>

<section class="summary-strip">
    <span>商品总数 <strong><?= (int) ($summary['total'] ?? 0) ?></strong></span>
    <span>在售 <strong><?= (int) ($summary['on_sale'] ?? 0) ?></strong></span>
    <span>库存件数 <strong><?= number_format((int) ($summary['units'] ?? 0)) ?></strong></span>
    <span>库存成本 <strong>¥<?= money($summary['stock_value'] ?? 0) ?></strong></span>
    <span>库存预警 <strong><?= (int) ($summary['low_stock'] ?? 0) ?></strong></span>
</section>

<details class="create-panel" id="productForm" <?= $editProduct ? 'open' : '' ?>>
    <summary><?= $editProduct ? '编辑商品 · ' . e($editProduct['sku']) : '新增商品' ?></summary>
    <div class="create-body">
        <form method="post" action="/products.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editProduct ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <div class="form-grid three">
                <div class="field">
                    <label for="name">商品名称<span class="required">*</span></label>
                    <input id="name" name="name" type="text" value="<?= e($form['name']) ?>" placeholder="例如：晨雾羊毛混纺大衣" maxlength="80" required>
                </div>
                <div class="field">
                    <label for="sku">SKU 编码<span class="required">*</span></label>
                    <input id="sku" name="sku" type="text" value="<?= e($form['sku']) ?>" placeholder="WC-WM-1001" maxlength="32" required>
                </div>
                <div class="field">
                    <label for="category_id">商品分类<span class="required">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="">请选择分类</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= (int) $form['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="brand">品牌</label>
                    <input id="brand" name="brand" type="text" value="<?= e($form['brand']) ?>" placeholder="闻川精选">
                </div>
                <div class="field">
                    <label for="unit">计量单位</label>
                    <input id="unit" name="unit" type="text" value="<?= e($form['unit']) ?>" placeholder="件">
                </div>
                <div class="field">
                    <label for="status">商品状态</label>
                    <select id="status" name="status">
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="cost_price">成本价（元）<span class="required">*</span></label>
                    <input id="cost_price" name="cost_price" type="number" min="0" step="0.01" value="<?= e((string) $form['cost_price']) ?>" required>
                </div>
                <div class="field">
                    <label for="sale_price">销售价（元）<span class="required">*</span></label>
                    <input id="sale_price" name="sale_price" type="number" min="0" step="0.01" value="<?= e((string) $form['sale_price']) ?>" required>
                </div>
                <div class="field">
                    <label for="market_price">市场价（元）</label>
                    <input id="market_price" name="market_price" type="number" min="0" step="0.01" value="<?= e((string) $form['market_price']) ?>">
                </div>
                <?php if (!$editProduct): ?>
                    <div class="field">
                        <label for="stock">初始库存</label>
                        <input id="stock" name="stock" type="number" min="0" step="1" value="<?= e((string) $form['stock']) ?>">
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>当前库存</label>
                        <input type="text" value="<?= (int) $form['stock'] ?> 件（请在库存管理中调整）" disabled>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="safety_stock">安全库存</label>
                    <input id="safety_stock" name="safety_stock" type="number" min="0" step="1" value="<?= (int) $form['safety_stock'] ?>">
                </div>
                <div class="field full">
                    <label for="description">商品描述</label>
                    <textarea id="description" name="description" placeholder="简要描述材质、规格或运营备注"><?= e($form['description']) ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editProduct ? '保存修改' : '创建商品' ?></button>
                <?php if ($editProduct): ?>
                    <a class="btn btn-ghost" href="/products.php">取消编辑</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</details>

<section class="card">
    <form class="filter-bar" method="get" action="/products.php">
        <div class="filter-fields">
            <div class="filter-field search">
                <label for="q">关键词</label>
                <input id="q" name="q" type="search" value="<?= e($keyword) ?>" placeholder="商品名称 / SKU / 品牌">
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
                <label for="statusFilter">状态</label>
                <select id="statusFilter" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a class="btn btn-ghost btn-sm" href="/products.php">重置</a>
            <button class="btn btn-secondary btn-sm" type="submit">筛选</button>
        </div>
    </form>
    <div class="filter-summary">共 <strong><?= (int) $pageInfo['total'] ?></strong> 款商品，按创建时间倒序排列</div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>商品</th>
                    <th>分类 / 品牌</th>
                    <th>价格</th>
                    <th>库存</th>
                    <th>状态</th>
                    <th>更新时间</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="7"><div class="data-empty"><div class="data-empty-mark">◧</div><strong>暂无匹配商品</strong>请调整筛选条件后重试。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <div class="product-cell">
                                <span class="product-avatar <?= product_tone((int) $product['id']) ?>"><?= e(initial_char($product['name'])) ?></span>
                                <span class="product-cell-text">
                                    <strong title="<?= e($product['name']) ?>"><?= e($product['name']) ?></strong>
                                    <span><?= e($product['sku']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td><?= e($product['category_name']) ?><span class="cell-sub"><?= e($product['brand']) ?></span></td>
                        <td><span class="amount">¥<?= money($product['sale_price']) ?></span><span class="cell-sub">成本 ¥<?= money($product['cost_price']) ?></span></td>
                        <td>
                            <span class="stock-value <?= (int) $product['stock'] <= 0 ? 'out' : ((int) $product['stock'] <= (int) $product['safety_stock'] ? 'low' : '') ?>"><?= (int) $product['stock'] ?> <?= e($product['unit']) ?></span>
                            <?php if ((int) $product['stock'] <= 0): ?>
                                <span class="stock-hint">缺货</span>
                            <?php elseif ((int) $product['stock'] <= (int) $product['safety_stock']): ?>
                                <span class="stock-hint">预警</span>
                            <?php endif; ?>
                            <span class="cell-sub">安全库存 <?= (int) $product['safety_stock'] ?></span>
                        </td>
                        <td><span class="badge <?= status_class($product['status']) ?>"><?= e($product['status']) ?></span></td>
                        <td class="muted nowrap"><?= e(fmt_date($product['updated_at'])) ?></td>
                        <td class="text-right actions">
                            <a class="btn btn-ghost btn-sm" href="/products.php?edit=<?= (int) $product['id'] ?>">编辑</a>
                            <form class="inline-form" method="post" action="/products.php" data-confirm="确认<?= $product['status'] === '在售' ? '下架' : '上架' ?>该商品？">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= $product['status'] === '在售' ? '下架' : '上架' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($pageInfo, '/products.php', ['q' => $keyword, 'category' => $categoryFilter, 'status' => $statusFilter]) ?>
</section>

<script>
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-open-form]');
    if (!trigger) { return; }
    var panel = document.getElementById('productForm');
    if (panel) {
        panel.setAttribute('open', 'open');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
