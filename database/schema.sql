PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    real_name TEXT NOT NULL,
    role TEXT NOT NULL,
    department TEXT,
    phone TEXT,
    status TEXT NOT NULL DEFAULT '启用',
    last_login_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    parent_name TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT '启用',
    description TEXT
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category_id INTEGER NOT NULL,
    brand TEXT,
    unit TEXT NOT NULL DEFAULT '件',
    cost_price REAL NOT NULL DEFAULT 0,
    sale_price REAL NOT NULL DEFAULT 0,
    market_price REAL NOT NULL DEFAULT 0,
    stock INTEGER NOT NULL DEFAULT 0,
    safety_stock INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT '在售',
    description TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (category_id) REFERENCES categories (id)
);

CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_no TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    phone TEXT NOT NULL UNIQUE,
    email TEXT,
    level TEXT NOT NULL DEFAULT '普通',
    points INTEGER NOT NULL DEFAULT 0,
    city TEXT,
    address TEXT,
    birthday TEXT,
    status TEXT NOT NULL DEFAULT '正常',
    registered_at TEXT NOT NULL,
    last_order_at TEXT,
    total_orders INTEGER NOT NULL DEFAULT 0,
    total_spent REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    contact TEXT,
    phone TEXT,
    email TEXT,
    scope TEXT,
    credit_rating TEXT NOT NULL DEFAULT 'B',
    cooperation_status TEXT NOT NULL DEFAULT '合作中',
    payable_amount REAL NOT NULL DEFAULT 0,
    last_supply_at TEXT,
    address TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_no TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    payment_status TEXT NOT NULL,
    payment_method TEXT,
    sales_channel TEXT,
    total_amount REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    shipping_fee REAL NOT NULL DEFAULT 0,
    payable_amount REAL NOT NULL DEFAULT 0,
    receiver_name TEXT,
    receiver_phone TEXT,
    receiver_address TEXT,
    remark TEXT,
    logistics_company TEXT,
    logistics_no TEXT,
    created_at TEXT NOT NULL,
    paid_at TEXT,
    shipped_at TEXT,
    completed_at TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers (id)
);

CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    sku TEXT NOT NULL,
    product_name TEXT NOT NULL,
    qty INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    cost_price REAL NOT NULL,
    subtotal REAL NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id)
);

CREATE TABLE IF NOT EXISTS refunds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    refund_no TEXT NOT NULL UNIQUE,
    order_id INTEGER NOT NULL,
    customer_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '待审核',
    applied_at TEXT NOT NULL,
    handled_at TEXT,
    handler TEXT,
    remark TEXT,
    attachment TEXT,
    FOREIGN KEY (order_id) REFERENCES orders (id),
    FOREIGN KEY (customer_id) REFERENCES customers (id)
);

CREATE TABLE IF NOT EXISTS inventory_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    sku TEXT NOT NULL,
    product_name TEXT NOT NULL,
    change_type TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    before_stock INTEGER NOT NULL,
    after_stock INTEGER NOT NULL,
    reference_no TEXT,
    operator TEXT,
    remark TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products (id)
);

CREATE TABLE IF NOT EXISTS promotions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    code TEXT UNIQUE,
    rule_desc TEXT,
    discount_value REAL NOT NULL DEFAULT 0,
    min_amount REAL NOT NULL DEFAULT 0,
    start_at TEXT NOT NULL,
    end_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '未开始',
    used_count INTEGER NOT NULL DEFAULT 0,
    budget REAL NOT NULL DEFAULT 0,
    channel TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_no TEXT NOT NULL UNIQUE,
    order_id INTEGER NOT NULL,
    order_no TEXT NOT NULL,
    channel TEXT NOT NULL,
    amount REAL NOT NULL,
    fee REAL NOT NULL DEFAULT 0,
    net_amount REAL NOT NULL DEFAULT 0,
    transaction_no TEXT,
    status TEXT NOT NULL DEFAULT '待结算',
    paid_at TEXT NOT NULL,
    settled_at TEXT,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);

CREATE TABLE IF NOT EXISTS reconciliations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recon_no TEXT NOT NULL UNIQUE,
    period TEXT NOT NULL,
    channel TEXT NOT NULL,
    statement_amount REAL NOT NULL DEFAULT 0,
    system_amount REAL NOT NULL DEFAULT 0,
    difference_amount REAL NOT NULL DEFAULT 0,
    matched_count INTEGER NOT NULL DEFAULT 0,
    total_count INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT '待核对',
    owner TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    confirmed_at TEXT
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username TEXT,
    action TEXT NOT NULL,
    target TEXT,
    detail TEXT,
    ip TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_products_category ON products (category_id);
CREATE INDEX IF NOT EXISTS idx_products_status ON products (status);
CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders (customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders (created_at);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);
CREATE INDEX IF NOT EXISTS idx_refunds_order ON refunds (order_id);
CREATE INDEX IF NOT EXISTS idx_inventory_product ON inventory_logs (product_id);
CREATE INDEX IF NOT EXISTS idx_payments_order ON payments (order_id);
