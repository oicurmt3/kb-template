<?php
declare(strict_types=1);

function admin_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => is_https(),
        ]);
        session_start();
    }
}

function admin_is_logged_in(): bool {
    admin_session_start();
    return !empty($_SESSION['admin']);
}

function admin_require(): void {
    if (!admin_is_logged_in()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/api/')) {
            json_error('Unauthorized', 401);
        }
        header('Location: ' . url('login.php'));
        exit;
    }
}

function admin_login(string $password): bool {
    global $config;
    admin_session_start();
    if (password_verify($password, $config['admin']['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        return true;
    }
    return false;
}

function admin_logout(): void {
    admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function csrf_token(): string {
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token = null): void {
    admin_session_start();
    if ($token === null) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    }
    if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        json_error('Invalid CSRF token', 403);
    }
}

function csrf_check_form(): void {
    admin_session_start();
    $token = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}
