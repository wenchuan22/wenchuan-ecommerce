<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', '闻川商城管理系统');
define('APP_BRAND', '闻川商城');
define('APP_VERSION', '3.2.0');
define('DB_PATH', ROOT_PATH . '/database/wenchuan-mall.sqlite');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOAD_BASE', ROOT_PATH . '/public/wc-uploads');

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!is_dir(STORAGE_PATH)) {
    @mkdir(STORAGE_PATH, 0777, true);
}
if (is_dir(STORAGE_PATH)) {
    ini_set('error_log', STORAGE_PATH . '/php-error.log');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('WCMALL_SESSION');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
