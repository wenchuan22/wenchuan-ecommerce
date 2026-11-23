<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'real_name' => $row['real_name'],
        'role' => $row['role'],
        'department' => $row['department'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function is_system_admin(): bool
{
    $user = current_user();
    return ($user['role'] ?? '') === '系统管理员';
}
