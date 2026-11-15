<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    admin_logout();
    header('Location: ' . url('login.php'));
    exit;
}

if ($action === 'csrf') {
    json_out(['token' => csrf_token()]);
}

json_error('Unsupported', 400);
