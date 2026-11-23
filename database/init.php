<?php
declare(strict_types=1);

if (!defined('DB_PATH')) {
    define('DB_PATH', dirname(__DIR__) . '/database/wenchuan-mall.sqlite');
}

$isDirect = PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath((string) $_SERVER['argv'][0]) === realpath(__FILE__);
$force = $isDirect && in_array('--force', $_SERVER['argv'] ?? [], true);

if (is_file(DB_PATH) && !$force) {
    if ($isDirect) {
        fwrite(STDOUT, "数据库已存在：" . DB_PATH . PHP_EOL);
        fwrite(STDOUT, "如需重建，请追加 --force 参数，或先删除数据库文件。" . PHP_EOL);
    }
    return;
}

if ($force) {
    foreach ([DB_PATH, DB_PATH . '-wal', DB_PATH . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));

$insert = static function (PDO $pdo, string $table, array $row): void {
    $columns = array_keys($row);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));
};

$pdo->beginTransaction();

try {
    $insert($pdo, 'users', [
        'id' => 1,
        'username' => 'admin',
        'password_hash' => password_hash('Wenchuan@2026', PASSWORD_DEFAULT),
        'real_name' => '林致远',
        'role' => '系统管理员',
        'department' => '信息中心',
        'phone' => '13868001206',
        'status' => '启用',
        'last_login_at' => '2026-11-10 08:32:14',
        'created_at' => '2024-03-18 09:00:00',
    ]);
    $insert($pdo, 'users', [
        'id' => 2,
        'username' => 'ops',
        'password_hash' => password_hash('Mall@2026', PASSWORD_DEFAULT),
        'real_name' => '苏晚晴',
        'role' => '运营主管',
        'department' => '运营中心',
        'phone' => '13868001982',
        'status' => '启用',
        'last_login_at' => '2026-11-10 09:05:41',
        'created_at' => '2024-05-22 09:00:00',
    ]);

    $categories = [
        [1, 'WOMEN', '女装', '服饰鞋包', 10, '启用', '通勤、休闲与轻户外女装'],
        [2, 'MEN', '男装', '服饰鞋包', 20, '启用', '简约质感男装与基础款'],
        [3, 'SHOES', '鞋靴', '服饰鞋包', 30, '启用', '皮鞋、运动鞋与季节鞋靴'],
        [4, 'BAGS', '箱包', '服饰鞋包', 40, '启用', '通勤包、旅行包与皮具'],
        [5, 'BEAUTY', '美妆个护', '美妆生活', 50, '启用', '护肤、香氛与身体护理'],
        [6, 'HOME', '家居日用', '美妆生活', 60, '启用', '床品、餐厨与居家香氛'],
        [7, 'DIGITAL', '数码配件', '3C数码', 70, '启用', '充电、音频与移动配件'],
        [8, 'FOOD', '食品饮料', '食品生鲜', 80, '启用', '咖啡、茶饮与节令礼盒'],
    ];
    foreach ($categories as [$id, $code, $name, $parent, $sort, $status, $desc]) {
        $insert($pdo, 'categories', [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'parent_name' => $parent,
            'sort_order' => $sort,
            'status' => $status,
            'description' => $desc,
        ]);
    }

    $products = [
        [1, 'WC-WM-1001', '晨雾羊毛混纺大衣', 1, '闻川精选', '件', 386, 699, 899, 42, 10, '在售', '70% 羊毛混纺，直筒廓形，适合秋冬通勤。'],
        [2, 'WC-WM-1002', '山茶花针织连衣裙', 1, '闻川精选', '件', 218, 429, 559, 18, 10, '在售', '细密针织面料，收腰剪裁，柔韧不易起球。'],
        [3, 'WC-WM-1003', '轻氧阔腿休闲裤', 1, '知野', '条', 96, 199, 269, 56, 12, '在售', '垂感混纺面料，高腰阔腿版型。'],
        [4, 'WC-MN-2001', '苍穹精梳棉衬衫', 2, '闻川精选', '件', 108, 239, 319, 33, 10, '在售', '100% 精梳棉，微宽松剪裁，四季可穿。'],
        [5, 'WC-MN-2002', '远山轻薄羽绒夹克', 2, '行野', '件', 356, 699, 899, 12, 8, '在售', '90% 白鸭绒填充，轻量防风。'],
        [6, 'WC-MN-2003', '砚台直筒牛仔裤', 2, '知野', '条', 128, 269, 359, 0, 10, '下架', '原色丹宁，直筒版型，季节款已下架。'],
        [7, 'WC-SH-3001', '云履真皮乐福鞋', 3, '云履', '双', 218, 499, 659, 27, 8, '在售', '头层牛皮鞋面，轻质橡胶大底。'],
        [8, 'WC-SH-3002', '轻驰缓震运动鞋', 3, '轻驰', '双', 186, 399, 529, 6, 12, '在售', '回弹中底，透气织物鞋面。'],
        [9, 'WC-BG-4001', '暮川通勤托特包', 4, '暮川', '只', 236, 529, 699, 24, 8, '在售', '大容量分区设计，可放下 14 英寸电脑。'],
        [10, 'WC-BG-4002', '行野双肩旅行包', 4, '行野', '只', 168, 369, 499, 0, 8, '下架', '防泼水面料，35L 旅行容量。'],
        [11, 'WC-BT-5001', '白玉兰修护面霜', 5, '白玉兰', '瓶', 118, 259, 339, 68, 15, '在售', '神经酰胺复配，夜间修护干燥肌。'],
        [12, 'WC-BT-5002', '柑橘晨露香水 50ml', 5, '晨露', '瓶', 196, 459, 599, 21, 8, '在售', '柑橘木质调，前调佛手柑与苦橙叶。'],
        [13, 'WC-HM-6001', '岩灰天丝四件套', 6, '暖枫', '套', 328, 699, 899, 15, 6, '在售', '60 支天丝棉，1.8 米床适用。'],
        [14, 'WC-HM-6002', '暖枫陶瓷餐具 16 件套', 6, '暖枫', '套', 188, 429, 569, 8, 15, '在售', '高温釉下彩，含碗盘勺筷。'],
        [15, 'WC-HM-6003', '星野香薰蜡烛礼盒', 6, '星野', '盒', 76, 169, 229, 37, 10, '在售', '大豆蜡与木质烛芯，雪松鼠尾草香型。'],
        [16, 'WC-DG-7001', '极光 65W 氮化镓充电器', 7, '极光', '个', 89, 199, 269, 96, 20, '在售', '双 Type-C 口，支持 PD3.0 快充。'],
        [17, 'WC-DG-7002', '云听降噪蓝牙耳机', 7, '云听', '副', 219, 499, 649, 22, 10, '在售', '混合主动降噪，单次续航 8 小时。'],
        [18, 'WC-DG-7003', '拾光磁吸充电宝 10000mAh', 7, '拾光', '个', 108, 249, 329, 3, 10, '在售', '磁吸无线充电，支持 20W 有线输出。'],
        [19, 'WC-FD-8001', '山野冷萃咖啡礼盒', 8, '山野', '盒', 88, 199, 259, 44, 12, '在售', '中深烘拼配豆，含冷萃壶与滤袋。'],
        [20, 'WC-FD-8002', '桂花乌龙茶包 30 袋', 8, '山野', '盒', 46, 109, 149, 9, 10, '在售', '广西桂花与闽南乌龙拼配，独立三角包。'],
    ];
    foreach ($products as [$id, $sku, $name, $categoryId, $brand, $unit, $cost, $sale, $market, $stock, $safety, $status, $desc]) {
        $insert($pdo, 'products', [
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'category_id' => $categoryId,
            'brand' => $brand,
            'unit' => $unit,
            'cost_price' => $cost,
            'sale_price' => $sale,
            'market_price' => $market,
            'stock' => $stock,
            'safety_stock' => $safety,
            'status' => $status,
            'description' => $desc,
            'created_at' => '2026-08-18 10:00:00',
            'updated_at' => '2026-11-09 17:20:00',
        ]);
    }

    $customers = [
        [1, 'WC-M00001', '周若琳', '13800001001', 'zhou.ruolin@example.com', '金卡', 628, '杭州', '西湖区文三路 188 号 2 幢 1201', '1992-04-18', '正常', '2024-06-12 10:25:00', 8, 6280],
        [2, 'WC-M00002', '陈思远', '13800001002', 'chen.siyuan@example.com', '钻石', 1268, '上海', '浦东新区张江路 66 弄 8 号 502', '1988-09-06', '正常', '2023-11-03 15:40:00', 15, 12680],
        [3, 'WC-M00003', '林晓桐', '13800001003', 'lin.xiaotong@example.com', '银卡', 386, '杭州', '拱墅区湖墅南路 318 号 1 幢 903', '1995-12-22', '正常', '2025-02-14 09:12:00', 6, 3860],
        [4, 'WC-M00004', '许知夏', '13800001004', 'xu.zhixia@example.com', '金卡', 724, '苏州', '工业园区星湖街 178 号 12 幢 601', '1990-07-30', '正常', '2024-03-08 18:36:00', 9, 7240],
        [5, 'WC-M00005', '沈亦舟', '13800001005', 'shen.yizhou@example.com', '银卡', 294, '南京', '鼓楼区中山北路 102 号 3 单元 802', '1986-02-11', '正常', '2025-01-19 11:08:00', 5, 2940],
        [6, 'WC-M00006', '唐婉宁', '13800001006', 'tang.wanning@example.com', '金卡', 568, '成都', '武侯区科华北路 58 号 5 幢 1702', '1993-05-25', '正常', '2024-08-27 20:15:00', 7, 5680],
        [7, 'WC-M00007', '顾清川', '13800001007', 'gu.qingchuan@example.com', '普通', 128, '宁波', '鄞州区中山东路 796 号 2 幢 1103', '1997-03-09', '正常', '2025-09-03 14:50:00', 3, 1280],
        [8, 'WC-M00008', '江沐阳', '13800001008', 'jiang.muyang@example.com', '银卡', 215, '武汉', '洪山区珞喻路 152 号 6 栋 401', '1991-11-17', '正常', '2025-04-16 09:42:00', 4, 2150],
        [9, 'WC-M00009', '苏念安', '13800001009', 'su.nianan@example.com', '金卡', 698, '杭州', '滨江区江南大道 380 号 9 幢 2201', '1994-08-21', '正常', '2024-01-22 16:05:00', 8, 6980],
        [10, 'WC-M00010', '陆之遥', '13800001010', 'lu.zhiyao@example.com', '普通', 96, '嘉兴', '南湖区中环南路 1122 号 4 幢 602', '1999-01-13', '正常', '2026-03-05 13:28:00', 2, 960],
        [11, 'WC-M00011', '温书言', '13800001011', 'wen.shuyan@example.com', '钻石', 1542, '北京', '朝阳区望京东路 8 号院 5 号楼 1801', '1987-06-29', '正常', '2023-05-11 10:30:00', 18, 15420],
        [12, 'WC-M00012', '方予晴', '13800001012', 'fang.yuqing@example.com', '银卡', 412, '上海', '徐汇区宜山路 700 号 7 幢 1202', '1996-10-08', '正常', '2025-06-30 17:22:00', 6, 4120],
        [13, 'WC-M00013', '韩序', '13800001013', 'han.xu@example.com', '普通', 68, '合肥', '蜀山区长江西路 499 号 2 幢 806', '1998-04-02', '正常', '2026-08-18 11:47:00', 1, 680],
        [14, 'WC-M00014', '蒋南星', '13800001014', 'jiang.nanxing@example.com', '金卡', 536, '广州', '天河区体育西路 103 号 8 楼 802', '1992-12-30', '正常', '2024-10-09 19:10:00', 7, 5360],
        [15, 'WC-M00015', '叶徵', '13800001015', 'ye.zheng@example.com', '普通', 168, '福州', '鼓楼区五四路 128 号 3 幢 1006', '2000-07-14', '停用', '2025-12-01 10:05:00', 3, 1680],
    ];
    foreach ($customers as [$id, $memberNo, $name, $phone, $email, $level, $points, $city, $address, $birthday, $status, $registered, $ordersCount, $spent]) {
        $insert($pdo, 'customers', [
            'id' => $id,
            'member_no' => $memberNo,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'level' => $level,
            'points' => $points,
            'city' => $city,
            'address' => $address,
            'birthday' => $birthday,
            'status' => $status,
            'registered_at' => $registered,
            'last_order_at' => null,
            'total_orders' => $ordersCount,
            'total_spent' => $spent,
        ]);
    }

    $suppliers = [
        [1, 'SP-2024-001', '杭州锦纺织造有限公司', '赵岚', '0571-88231901', 'zhao.lan@jinfang-textile.example.com', '女装、男装面料与成衣加工', 'A', '合作中', 128600.00, '2026-11-06 15:30:00', '杭州市临平区崇贤街道运河路 288 号', '2024-02-18 09:00:00'],
        [2, 'SP-2024-002', '温州云履鞋业有限公司', '李崇', '0577-86572018', 'li.chong@yunlv-shoes.example.com', '真皮皮鞋、运动鞋生产', 'B', '合作中', 86400.00, '2026-11-04 11:20:00', '温州市鹿城区双屿街道鞋都路 96 号', '2024-04-09 09:00:00'],
        [3, 'SP-2024-003', '广州珀然箱包制造有限公司', '陈漫', '020-86203177', 'chen.man@poran-bag.example.com', '通勤包、旅行包与皮具', 'A', '合作中', 63200.00, '2026-10-28 14:10:00', '广州市白云区人和镇鹤龙七路 118 号', '2024-05-16 09:00:00'],
        [4, 'SP-2025-004', '上海澄研日化有限公司', '徐岚', '021-64893026', 'xu.lan@chengyan-care.example.com', '护肤、香氛与身体护理', 'A', '合作中', 94700.00, '2026-11-08 10:05:00', '上海市奉贤区望园路 2168 弄 6 号', '2025-01-08 09:00:00'],
        [5, 'SP-2025-005', '深圳极光数码科技有限公司', '何川', '0755-82961037', 'he.chuan@aurora-digital.example.com', '充电器、耳机与移动电源', 'B', '合作中', 152300.00, '2026-11-09 16:45:00', '深圳市龙华区民治街道民康路 112 号', '2025-03-12 09:00:00'],
        [6, 'SP-2025-006', '苏州暖枫家居用品有限公司', '周桐', '0512-67320819', 'zhou.tong@nuanfeng-home.example.com', '床品、餐厨与居家香氛', 'A', '合作中', 78600.00, '2026-11-07 09:30:00', '苏州市相城区元和街道春申湖西路 1888 号', '2025-06-20 09:00:00'],
        [7, 'SP-2023-007', '厦门山野食品有限公司', '罗青', '0592-5806173', 'luo.qing@shanye-food.example.com', '咖啡豆、茶饮与节令礼盒', 'B', '暂停合作', 18600.00, '2026-09-25 10:00:00', '厦门市集美区灌口镇安仁大道 1026 号', '2023-09-14 09:00:00'],
    ];
    foreach ($suppliers as [$id, $code, $name, $contact, $phone, $email, $scope, $rating, $status, $payable, $lastSupply, $address, $created]) {
        $insert($pdo, 'suppliers', [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'contact' => $contact,
            'phone' => $phone,
            'email' => $email,
            'scope' => $scope,
            'credit_rating' => $rating,
            'cooperation_status' => $status,
            'payable_amount' => $payable,
            'last_supply_at' => $lastSupply,
            'address' => $address,
            'created_at' => $created,
        ]);
    }

    $productBySku = [];
    foreach ($products as $product) {
        $productBySku[$product[1]] = [
            'id' => $product[0],
            'sku' => $product[1],
            'name' => $product[2],
            'sale' => $product[7],
            'cost' => $product[6],
            'stock' => $product[9],
        ];
    }
    $customerById = [];
    foreach ($customers as $customer) {
        $customerById[$customer[0]] = [
            'name' => $customer[2],
            'phone' => $customer[3],
            'address' => $customer[8],
        ];
    }

    $orders = [
        [
            'id' => 1, 'order_no' => 'WC202610200001', 'customer_id' => 12, 'status' => '已完成', 'payment_status' => '已支付',
            'payment_method' => '微信支付', 'sales_channel' => '微信小程序', 'discount' => 80, 'shipping' => 0,
            'created_at' => '2026-10-20 12:16:00', 'paid_at' => '2026-10-20 12:21:00', 'shipped_at' => '2026-10-21 09:38:00',
            'completed_at' => '2026-10-24 18:20:00', 'logistics_company' => '顺丰速运', 'logistics_no' => 'SF1438290517721',
            'remark' => '礼盒包装，贺卡写“秋日安好”。',
            'items' => [['WC-HM-6001', 1], ['WC-BT-5001', 1]],
        ],
        [
            'id' => 2, 'order_no' => 'WC202610280002', 'customer_id' => 11, 'status' => '已发货', 'payment_status' => '已支付',
            'payment_method' => '微信支付', 'sales_channel' => '抖音小店', 'discount' => 30, 'shipping' => 0,
            'created_at' => '2026-10-28 20:40:00', 'paid_at' => '2026-10-28 20:42:00', 'shipped_at' => '2026-10-29 10:15:00',
            'completed_at' => null, 'logistics_company' => '中通快递', 'logistics_no' => 'ZTO76231580942',
            'remark' => '请放物业前台，谢谢。',
            'items' => [['WC-SH-3001', 1], ['WC-BT-5001', 1]],
        ],
        [
            'id' => 3, 'order_no' => 'WC202611010003', 'customer_id' => 10, 'status' => '已完成', 'payment_status' => '已支付',
            'payment_method' => '支付宝', 'sales_channel' => '天猫旗舰店', 'discount' => 0, 'shipping' => 0,
            'created_at' => '2026-11-01 09:58:00', 'paid_at' => '2026-11-01 10:02:00', 'shipped_at' => '2026-11-01 16:40:00',
            'completed_at' => '2026-11-04 12:10:00', 'logistics_company' => '圆通速递', 'logistics_no' => 'YTO8834210765',
            'remark' => '',
            'items' => [['WC-FD-8001', 1], ['WC-FD-8002', 1], ['WC-DG-7001', 1]],
        ],
        [
            'id' => 4, 'order_no' => 'WC202611030004', 'customer_id' => 9, 'status' => '已完成', 'payment_status' => '已支付',
            'payment_method' => '微信支付', 'sales_channel' => '京东旗舰店', 'discount' => 50, 'shipping' => 0,
            'created_at' => '2026-11-03 14:03:00', 'paid_at' => '2026-11-03 14:06:00', 'shipped_at' => '2026-11-03 19:25:00',
            'completed_at' => '2026-11-06 11:32:00', 'logistics_company' => '顺丰速运', 'logistics_no' => 'SF1438290773106',
            'remark' => '不要放快递柜。',
            'items' => [['WC-BG-4001', 1], ['WC-BG-4002', 1]],
        ],
        [
            'id' => 5, 'order_no' => 'WC202611050005', 'customer_id' => 8, 'status' => '已关闭', 'payment_status' => '未支付',
            'payment_method' => '支付宝', 'sales_channel' => '微信小程序', 'discount' => 0, 'shipping' => 8,
            'created_at' => '2026-11-05 19:25:00', 'paid_at' => null, 'shipped_at' => null,
            'completed_at' => null, 'logistics_company' => null, 'logistics_no' => null,
            'remark' => '超时未支付，系统自动关闭。',
            'items' => [['WC-MN-2003', 1], ['WC-WM-1003', 1]],
        ],
        [
            'id' => 6, 'order_no' => 'WC202611060006', 'customer_id' => 7, 'status' => '已完成', 'payment_status' => '已支付',
            'payment_method' => '微信支付', 'sales_channel' => '微信小程序', 'discount' => 0, 'shipping' => 0,
            'created_at' => '2026-11-06 10:12:00', 'paid_at' => '2026-11-06 10:14:00', 'shipped_at' => '2026-11-06 15:50:00',
            'completed_at' => '2026-11-09 09:10:00', 'logistics_company' => '中通快递', 'logistics_no' => 'ZTO76231906318',
            'remark' => '',
            'items' => [['WC-DG-7003', 1], ['WC-DG-7001', 1]],
        ],
        [
            'id' => 7, 'order_no' => 'WC202611070007', 'customer_id' => 6, 'status' => '退款中', 'payment_status' => '已支付',
            'payment_method' => '支付宝', 'sales_channel' => '天猫旗舰店', 'discount' => 0, 'shipping' => 0,
            'created_at' => '2026-11-07 16:48:00', 'paid_at' => '2026-11-07 16:50:00', 'shipped_at' => '2026-11-08 09:20:00',
            'completed_at' => null, 'logistics_company' => '圆通速递', 'logistics_no' => 'YTO8834215580',
            'remark' => '签收后申请售后。',
            'items' => [['WC-BT-5002', 1], ['WC-HM-6003', 1]],
        ],
        [
            'id' => 8, 'order_no' => 'WC202611080008', 'customer_id' => 5, 'status' => '已发货', 'payment_status' => '已支付',
            'payment_method' => '微信支付', 'sales_channel' => '微信小程序', 'discount' => 20, 'shipping' => 10,
            'created_at' => '2026-11-08 11:20:00', 'paid_at' => '2026-11-08 11:23:00', 'shipped_at' => '2026-11-09 08:50:00',
            'completed_at' => null, 'logistics_company' => '顺丰速运', 'logistics_no' => 'SF1438291024877',
            'remark' => '周末送达。',
            'items' => [['WC-MN-2002', 1], ['WC-MN-2001', 1]],
        ],
        [
            'id' => 9, 'order_no' => 'WC202611090009', 'customer_id' => 4, 'status' => '已完成', 'payment_status' => '已支付',
            'payment_method' => '支付宝', 'sales_channel' => '京东旗舰店', 'discount' => 100, 'shipping' => 0,
            'created_at' => '2026-11-09 15:33:00', 'paid_at' => '2026-11-09 15:36:00', 'shipped_at' => '2026-11-09 20:10:00',
            'completed_at' => '2026-11-10 09:05:00', 'logistics_company' => '京东物流', 'logistics_no' => 'JDVC00318260941',
            'remark' => '公司前台代收。',
            'items' => [['WC-HM-6001', 1], ['WC-HM-6002', 1]],
        ],
        [
            'id' => 10, 'order_no' => 'WC202611090010', 'customer_id' => 3, 'status' => '已发货', 'payment_status' => '已支付',
            'payment_method' => '微信支付', 'sales_channel' => '抖音小店', 'discount' => 30, 'shipping' => 0,
            'created_at' => '2026-11-09 21:06:00', 'paid_at' => '2026-11-09 21:08:00', 'shipped_at' => '2026-11-10 08:15:00',
            'completed_at' => null, 'logistics_company' => '中通快递', 'logistics_no' => 'ZTO76232117704',
            'remark' => '',
            'items' => [['WC-WM-1002', 1], ['WC-SH-3002', 1]],
        ],
        [
            'id' => 11, 'order_no' => 'WC202611100011', 'customer_id' => 2, 'status' => '待发货', 'payment_status' => '已支付',
            'payment_method' => '支付宝', 'sales_channel' => '天猫旗舰店', 'discount' => 0, 'shipping' => 0,
            'created_at' => '2026-11-10 08:15:00', 'paid_at' => '2026-11-10 08:18:00', 'shipped_at' => null,
            'completed_at' => null, 'logistics_company' => null, 'logistics_no' => null,
            'remark' => '耳机需要开发票，抬头为个人。',
            'items' => [['WC-DG-7002', 1], ['WC-DG-7001', 2]],
        ],
        [
            'id' => 12, 'order_no' => 'WC202611100012', 'customer_id' => 1, 'status' => '待付款', 'payment_status' => '未支付',
            'payment_method' => '微信支付', 'sales_channel' => '微信小程序', 'discount' => 50, 'shipping' => 0,
            'created_at' => '2026-11-10 09:42:00', 'paid_at' => null, 'shipped_at' => null,
            'completed_at' => null, 'logistics_company' => null, 'logistics_no' => null,
            'remark' => '客户正在确认尺码。',
            'items' => [['WC-WM-1001', 1], ['WC-BT-5001', 1]],
        ],
    ];

    $payments = [];
    $paymentSeq = 1;
    $inventorySales = [];

    foreach ($orders as $order) {
        $total = 0.0;
        $lineRows = [];
        foreach ($order['items'] as [$sku, $qty]) {
            $product = $productBySku[$sku];
            $subtotal = round($product['sale'] * $qty, 2);
            $total += $subtotal;
            $lineRows[] = [$product, $qty, $subtotal];
        }
        $payable = round($total - $order['discount'] + $order['shipping'], 2);
        $customer = $customerById[$order['customer_id']];

        $insert($pdo, 'orders', [
            'id' => $order['id'],
            'order_no' => $order['order_no'],
            'customer_id' => $order['customer_id'],
            'status' => $order['status'],
            'payment_status' => $order['payment_status'],
            'payment_method' => $order['payment_method'],
            'sales_channel' => $order['sales_channel'],
            'total_amount' => $total,
            'discount_amount' => $order['discount'],
            'shipping_fee' => $order['shipping'],
            'payable_amount' => $payable,
            'receiver_name' => $customer['name'],
            'receiver_phone' => $customer['phone'],
            'receiver_address' => $customer['address'],
            'remark' => $order['remark'],
            'logistics_company' => $order['logistics_company'],
            'logistics_no' => $order['logistics_no'],
            'created_at' => $order['created_at'],
            'paid_at' => $order['paid_at'],
            'shipped_at' => $order['shipped_at'],
            'completed_at' => $order['completed_at'],
        ]);

        foreach ($lineRows as $line) {
            [$product, $qty, $subtotal] = $line;
            $insert($pdo, 'order_items', [
                'order_id' => $order['id'],
                'product_id' => $product['id'],
                'sku' => $product['sku'],
                'product_name' => $product['name'],
                'qty' => $qty,
                'unit_price' => $product['sale'],
                'cost_price' => $product['cost'],
                'subtotal' => $subtotal,
            ]);
            if ($order['payment_status'] === '已支付') {
                $inventorySales[] = [
                    'product_id' => $product['id'],
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'qty' => $qty,
                    'created_at' => $order['created_at'],
                    'order_no' => $order['order_no'],
                ];
            }
        }

        if ($order['payment_status'] === '已支付') {
            $fee = round($payable * 0.006, 2);
            $payments[] = [
                'payment_no' => 'PAY2026' . str_pad((string) $paymentSeq, 6, '0', STR_PAD_LEFT),
                'order_id' => $order['id'],
                'order_no' => $order['order_no'],
                'channel' => $order['sales_channel'],
                'amount' => $payable,
                'fee' => $fee,
                'net_amount' => round($payable - $fee, 2),
                'transaction_no' => 'TXN' . date('YmdHis', strtotime($order['paid_at'])) . str_pad((string) $paymentSeq, 3, '0', STR_PAD_LEFT),
                'status' => strtotime($order['paid_at']) < strtotime('2026-11-09 00:00:00') ? '已结算' : '待结算',
                'paid_at' => $order['paid_at'],
                'settled_at' => strtotime($order['paid_at']) < strtotime('2026-11-09 00:00:00') ? date('Y-m-d 23:00:00', strtotime($order['paid_at'])) : null,
            ];
            $paymentSeq++;
        }
    }
    foreach ($payments as $payment) {
        $insert($pdo, 'payments', $payment);
    }

    $inventoryLogs = [];
    usort($inventorySales, static function (array $a, array $b): int {
        return strcmp($b['created_at'], $a['created_at']);
    });
    $cursor = [];
    foreach ($products as $product) {
        $cursor[$product[1]] = (int) $product[9];
    }
    foreach ($inventorySales as $sale) {
        $after = $cursor[$sale['sku']];
        $before = $after + $sale['qty'];
        $inventoryLogs[] = [
            'product_id' => $sale['product_id'],
            'sku' => $sale['sku'],
            'product_name' => $sale['name'],
            'change_type' => '销售出库',
            'quantity' => -$sale['qty'],
            'before_stock' => $before,
            'after_stock' => $after,
            'reference_no' => $sale['order_no'],
            'operator' => '系统',
            'remark' => '订单支付后自动扣减库存',
            'created_at' => date('Y-m-d H:i:s', strtotime($sale['created_at']) + 240),
        ];
        $cursor[$sale['sku']] = $before;
    }
    $inbound = [
        [1, 'WC-WM-1001', '晨雾羊毛混纺大衣', 30, 12, 42, 'PO20261015001', '采购入库', '2026-10-15 09:20:00', '秋冬季首单补货'],
        [2, 'WC-MN-2002', '远山轻薄羽绒夹克', 20, 2, 22, 'PO20261015002', '采购入库', '2026-10-15 10:05:00', '羽绒品类入仓'],
        [4, 'WC-DG-7002', '云听降噪蓝牙耳机', 40, 8, 48, 'PO20261018003', '采购入库', '2026-10-18 14:30:00', '数码配件常规补货'],
        [5, 'WC-BT-5001', '白玉兰修护面霜', 60, 28, 88, 'PO20261022004', '采购入库', '2026-10-22 11:15:00', '美妆爆款补货'],
        [6, 'WC-HM-6001', '岩灰天丝四件套', 25, 6, 31, 'PO20261026005', '采购入库', '2026-10-26 16:40:00', '家纺活动备货'],
    ];
    foreach ($inbound as [$pid, $sku, $name, $qty, $before, $after, $ref, $type, $time, $remark]) {
        $inventoryLogs[] = [
            'product_id' => $pid,
            'sku' => $sku,
            'product_name' => $name,
            'change_type' => $type,
            'quantity' => $qty,
            'before_stock' => $before,
            'after_stock' => $after,
            'reference_no' => $ref,
            'operator' => '周敏',
            'remark' => $remark,
            'created_at' => $time,
        ];
    }
    $inventoryLogs[] = [
        'product_id' => 18, 'sku' => 'WC-DG-7003', 'product_name' => '拾光磁吸充电宝 10000mAh', 'change_type' => '盘点盘亏',
        'quantity' => -2, 'before_stock' => 5, 'after_stock' => 3, 'reference_no' => 'PD20261109002', 'operator' => '周敏',
        'remark' => '仓库抽盘发现样机损耗', 'created_at' => '2026-11-09 18:20:00',
    ];
    $inventoryLogs[] = [
        'product_id' => 15, 'sku' => 'WC-HM-6003', 'product_name' => '星野香薰蜡烛礼盒', 'change_type' => '盘点盘盈',
        'quantity' => 3, 'before_stock' => 34, 'after_stock' => 37, 'reference_no' => 'PD20261109001', 'operator' => '周敏',
        'remark' => '退货暂存区重新上架', 'created_at' => '2026-11-09 18:05:00',
    ];
    usort($inventoryLogs, static function (array $a, array $b): int {
        return strcmp($a['created_at'], $b['created_at']);
    });
    foreach ($inventoryLogs as $log) {
        $insert($pdo, 'inventory_logs', $log);
    }

    $refunds = [
        [1, 'RF20261108001', 7, 6, 459.00, '香水香调与预期不符，申请退款', '待审核', '2026-11-08 09:32:00', null, null, '客户已提供开箱照片，商品未拆封。', null],
        [2, 'RF20261106002', 4, 9, 199.00, '餐具运输磕碰，申请部分退款', '已退款', '2026-11-06 14:10:00', '2026-11-07 10:25:00', '苏晚晴', '已退回破损件，退款原路返回。', null],
        [3, 'RF20261102003', 3, 10, 50.00, '下单后价格调整，申请价保', '已通过', '2026-11-02 09:15:00', '2026-11-03 11:02:00', '林致远', '按活动价补差，已退回支付账户。', null],
        [4, 'RF20261029004', 2, 11, 89.00, '使用后不喜欢', '已驳回', '2026-10-29 18:40:00', '2026-10-30 10:10:00', '苏晚晴', '商品已拆封使用，不符合退款条件。', null],
        [5, 'RF20261110005', 11, 2, 199.00, '多拍一件，申请部分退款', '待审核', '2026-11-10 10:05:00', null, null, '订单尚未发货，申请取消一件。', null],
    ];
    foreach ($refunds as [$id, $no, $orderId, $customerId, $amount, $reason, $status, $applied, $handled, $handler, $remark, $attachment]) {
        $insert($pdo, 'refunds', [
            'id' => $id,
            'refund_no' => $no,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'reason' => $reason,
            'status' => $status,
            'applied_at' => $applied,
            'handled_at' => $handled,
            'handler' => $handler,
            'remark' => $remark,
            'attachment' => $attachment,
        ]);
    }

    $promotions = [
        [1, '双十一开门红满减', '满减', 'WC1111', '全场满 300 元减 50 元，满 600 元减 120 元', 120, 600, '2026-11-01 00:00:00', '2026-11-11 23:59:59', '进行中', 326, 50000, '全渠道', '2026-10-26 10:00:00'],
        [2, '新客首单礼', '新客礼', 'WCNEW20', '新注册会员首单立减 20 元', 20, 99, '2026-01-01 00:00:00', '2026-12-31 23:59:59', '进行中', 1842, 80000, '微信小程序', '2026-01-01 00:00:00'],
        [3, '秋冬女装焕新 8.8 折', '折扣', 'WCAUTUMN88', '女装类目精选款 8.8 折，不与满减叠加', 12, 0, '2026-11-01 00:00:00', '2026-11-30 23:59:59', '进行中', 768, 30000, '天猫旗舰店', '2026-10-25 14:00:00'],
        [4, '会员日双倍积分', '积分', 'WCVIP18', '每月 18 日会员消费双倍积分', 2, 0, '2026-11-18 00:00:00', '2026-11-18 23:59:59', '未开始', 0, 12000, '全渠道', '2026-11-01 09:30:00'],
        [5, '咖啡茶饮第二件半价', '第二件半价', 'WCTEA50', '山野咖啡与茶饮指定商品第二件半价', 50, 0, '2026-10-15 00:00:00', '2026-11-15 23:59:59', '进行中', 412, 18000, '微信小程序', '2026-10-10 16:20:00'],
        [6, '618 年中大促', '满减', 'WC618', '满 500 元减 80 元，叠加平台券', 80, 500, '2026-06-01 00:00:00', '2026-06-20 23:59:59', '已结束', 2156, 120000, '全渠道', '2026-05-18 10:00:00'],
        [7, '直播间限时秒杀', '秒杀', 'WCLIVE1020', '直播间专属价，限量 200 件', 30, 0, '2026-10-20 19:00:00', '2026-10-20 22:00:00', '已停用', 198, 8000, '抖音小店', '2026-10-18 15:00:00'],
    ];
    foreach ($promotions as $promotion) {
        $insert($pdo, 'promotions', [
            'id' => $promotion[0],
            'name' => $promotion[1],
            'type' => $promotion[2],
            'code' => $promotion[3],
            'rule_desc' => $promotion[4],
            'discount_value' => $promotion[5],
            'min_amount' => $promotion[6],
            'start_at' => $promotion[7],
            'end_at' => $promotion[8],
            'status' => $promotion[9],
            'used_count' => $promotion[10],
            'budget' => $promotion[11],
            'channel' => $promotion[12],
            'created_at' => $promotion[13],
        ]);
    }

    $reconciliations = [
        [1, 'RC20261110001', '2026-11-09', '微信小程序', 18420.00, 18420.00, 0.00, 186, 186, '已平账', '苏晚晴', '账单与系统流水逐笔一致。', '2026-11-10 08:05:00', '2026-11-10 08:40:00'],
        [2, 'RC20261110002', '2026-11-09', '天猫旗舰店', 24680.00, 24760.00, -80.00, 231, 232, '待核对', '陈可', '平台退款单未同步，待核对 1 笔。', '2026-11-10 08:10:00', null],
        [3, 'RC20261109003', '2026-11-08', '抖音小店', 9870.00, 9870.00, 0.00, 112, 112, '已平账', '苏晚晴', '直播间订单已全部核销。', '2026-11-09 08:20:00', '2026-11-09 09:10:00'],
        [4, 'RC20261109004', '2026-11-08', '京东旗舰店', 15260.00, 15190.00, 70.00, 98, 99, '差异处理', '陈可', '运费险补贴 70 元未入系统，待调整。', '2026-11-09 08:35:00', null],
        [5, 'RC20261108005', '2026-11-07', '微信小程序', 12980.00, 12980.00, 0.00, 143, 143, '已平账', '苏晚晴', '无差异。', '2026-11-08 08:00:00', '2026-11-08 08:45:00'],
        [6, 'RC20261031006', '2026-10-31', '线下门店', 6480.00, 6480.00, 0.00, 58, 58, '已平账', '周敏', '门店 POS 日结单已归档。', '2026-11-01 09:10:00', '2026-11-01 10:20:00'],
        [7, 'RC20261107007', '2026-11-06', '天猫旗舰店', 20340.00, 20340.00, 0.00, 198, 198, '已平账', '陈可', '含预售尾款 3 笔。', '2026-11-07 08:15:00', '2026-11-07 09:30:00'],
    ];
    foreach ($reconciliations as $recon) {
        $insert($pdo, 'reconciliations', [
            'id' => $recon[0],
            'recon_no' => $recon[1],
            'period' => $recon[2],
            'channel' => $recon[3],
            'statement_amount' => $recon[4],
            'system_amount' => $recon[5],
            'difference_amount' => $recon[6],
            'matched_count' => $recon[7],
            'total_count' => $recon[8],
            'status' => $recon[9],
            'owner' => $recon[10],
            'notes' => $recon[11],
            'created_at' => $recon[12],
            'confirmed_at' => $recon[13],
        ]);
    }

    $auditLogs = [
        [1, '苏晚晴', '订单发货', 'WC202611090010', '录入中通快递运单 ZTO76232117704', '127.0.0.1', '2026-11-10 08:15:22'],
        [1, '林致远', '对账确认', 'RC20261110001', '微信小程序 2026-11-09 账单确认平账', '127.0.0.1', '2026-11-10 08:40:37'],
        [1, '苏晚晴', '售后受理', 'RF20261110005', '受理部分退款申请，待审核', '127.0.0.1', '2026-11-10 10:06:12'],
        [2, '苏晚晴', '商品调价', 'WC-BT-5002', '活动结束后恢复日常售价 459.00', '127.0.0.1', '2026-11-09 18:02:45'],
        [2, '周敏', '库存盘点', 'PD20261109002', '拾光磁吸充电宝盘亏 2 件', '127.0.0.1', '2026-11-09 18:20:09'],
        [1, '林致远', '账号登录', 'admin', '信息中心办公网络登录', '127.0.0.1', '2026-11-10 08:32:14'],
        [2, '苏晚晴', '账号登录', 'ops', '运营中心办公网络登录', '127.0.0.1', '2026-11-10 09:05:41'],
    ];
    foreach ($auditLogs as [$userId, $username, $action, $target, $detail, $ip, $created]) {
        $insert($pdo, 'audit_logs', [
            'user_id' => $userId,
            'username' => $username,
            'action' => $action,
            'target' => $target,
            'detail' => $detail,
            'ip' => $ip,
            'created_at' => $created,
        ]);
    }

    $pdo->exec('UPDATE customers SET last_order_at = (SELECT MAX(created_at) FROM orders WHERE orders.customer_id = customers.id)');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

if ($isDirect) {
    fwrite(STDOUT, "数据库初始化完成：" . DB_PATH . PHP_EOL);
    fwrite(STDOUT, "默认账号：admin / Wenchuan@2026，ops / Mall@2026" . PHP_EOL);
}
