<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function money($amount): string
{
    return number_format((float) $amount, 2);
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : '—';
}

function fmt_datetime(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('Y-m-d H:i', $ts) : '—';
}

function fmt_month_day(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('m-d H:i', $ts) : '—';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('页面已过期，请返回后重新提交。');
    }
}

function input(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function q(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

function status_class(string $status): string
{
    $map = [
        '待付款' => 'badge-warn',
        '待发货' => 'badge-orange',
        '已发货' => 'badge-info',
        '已完成' => 'badge-success',
        '已关闭' => 'badge-muted',
        '退款中' => 'badge-danger',
        '未支付' => 'badge-warn',
        '已支付' => 'badge-success',
        '已退款' => 'badge-muted',
        '部分退款' => 'badge-orange',
        '待审核' => 'badge-warn',
        '已通过' => 'badge-info',
        '已驳回' => 'badge-danger',
        '已平账' => 'badge-success',
        '待核对' => 'badge-warn',
        '差异处理' => 'badge-danger',
        '进行中' => 'badge-success',
        '未开始' => 'badge-info',
        '已结束' => 'badge-muted',
        '已停用' => 'badge-muted',
        '在售' => 'badge-success',
        '下架' => 'badge-muted',
        '预售' => 'badge-info',
        '正常' => 'badge-success',
        '停用' => 'badge-muted',
        '合作中' => 'badge-success',
        '暂停合作' => 'badge-warn',
        '已终止' => 'badge-muted',
        '启用' => 'badge-success',
        '已结算' => 'badge-success',
        '待结算' => 'badge-warn',
        '采购入库' => 'badge-info',
        '销售出库' => 'badge-orange',
        '退货入库' => 'badge-success',
        '盘点盘盈' => 'badge-info',
        '盘点盘亏' => 'badge-danger',
        '其他调整' => 'badge-muted',
    ];
    return $map[$status] ?? 'badge-muted';
}

function current_operator(): string
{
    $user = current_user();
    return $user['real_name'] ?? '系统';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function audit_log(string $action, string $target, string $detail = ''): void
{
    $user = current_user();
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, username, action, target, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $user['id'] ?? null,
        $user['username'] ?? 'system',
        $action,
        $target,
        $detail,
        client_ip(),
        date('Y-m-d H:i:s'),
    ]);
}

function next_serial(string $table, string $column, string $prefix, int $width = 4): string
{
    $row = db()->query('SELECT MAX(id) AS max_id FROM ' . $table)->fetch();
    $next = ((int) ($row['max_id'] ?? 0)) + 1;
    return $prefix . str_pad((string) $next, $width, '0', STR_PAD_LEFT);
}

function pagination(array $result, string $baseUrl, array $params = []): string
{
    $page = (int) $result['page'];
    $pages = (int) $result['pages'];
    if ($pages <= 1) {
        return '';
    }

    $makeUrl = static function (int $target) use ($baseUrl, $params): string {
        $params['page'] = $target;
        return $baseUrl . '?' . http_build_query($params);
    };

    $html = '<nav class="pagination" aria-label="分页">';
    $html .= '<span class="page-meta">共 ' . (int) $result['total'] . ' 条 · 第 ' . $page . ' / ' . $pages . ' 页</span>';
    $html .= '<div class="page-links">';
    if ($page > 1) {
        $html .= '<a class="page-link" href="' . e($makeUrl($page - 1)) . '">上一页</a>';
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        $class = $i === $page ? 'page-link is-current' : 'page-link';
        $html .= '<a class="' . $class . '" href="' . e($makeUrl($i)) . '">' . $i . '</a>';
    }
    if ($page < $pages) {
        $html .= '<a class="page-link" href="' . e($makeUrl($page + 1)) . '">下一页</a>';
    }
    $html .= '</div></nav>';
    return $html;
}

function paged_result(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return [
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'offset' => ($page - 1) * $perPage,
        'limit' => $perPage,
    ];
}

function month_label(string $date): string
{
    return date('Y年n月', strtotime($date));
}

function product_tone(int $id): string
{
    return 'tone-' . (($id % 4) + 1);
}

function initial_char(?string $name): string
{
    return mb_substr((string) $name, 0, 1);
}

function apply_order_action(int $orderId, string $action): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return [false, '订单不存在。'];
    }

    $now = date('Y-m-d H:i:s');

    if ($action === 'ship') {
        if ($order['status'] !== '待发货' || $order['payment_status'] !== '已支付') {
            return [false, '当前订单状态不支持发货操作。'];
        }
        $logisticsNo = 'SF' . date('Ymd') . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $update = $pdo->prepare("UPDATE orders SET status = '已发货', shipped_at = ?, logistics_company = '顺丰速运', logistics_no = ? WHERE id = ?");
        $update->execute([$now, $logisticsNo, $orderId]);
        audit_log('订单发货', $order['order_no'], '录入顺丰速运运单 ' . $logisticsNo);
        return [true, '订单 ' . $order['order_no'] . ' 已发货，运单号 ' . $logisticsNo . '。'];
    }

    if ($action === 'complete') {
        if ($order['status'] !== '已发货') {
            return [false, '仅已发货订单可确认完成。'];
        }
        $update = $pdo->prepare("UPDATE orders SET status = '已完成', completed_at = ? WHERE id = ?");
        $update->execute([$now, $orderId]);
        audit_log('订单完成', $order['order_no'], '确认客户已签收');
        return [true, '订单 ' . $order['order_no'] . ' 已确认完成。'];
    }

    if ($action === 'close') {
        if ($order['status'] !== '待付款') {
            return [false, '仅待付款订单可关闭。'];
        }
        $update = $pdo->prepare("UPDATE orders SET status = '已关闭' WHERE id = ?");
        $update->execute([$orderId]);
        audit_log('关闭订单', $order['order_no'], '运营人员手动关闭待付款订单');
        return [true, '订单 ' . $order['order_no'] . ' 已关闭。'];
    }

    return [false, '不支持的操作。'];
}
