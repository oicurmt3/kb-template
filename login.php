<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_form();
    $rl = $config['app']['rate_limit'] ?? [];
    $loginMax = (int)($rl['login_max'] ?? 10);
    $loginWindow = (int)($rl['login_window'] ?? 600);
    if (!rate_limit_check('login', $loginMax, $loginWindow)) {
        $error = 'Too many attempts. Try again later.';
    } else {
        $pw = $_POST['password'] ?? '';
        if (admin_login($pw)) {
            header('Location: ' . url('admin.php'));
            exit;
        }
        $error = 'Invalid password.';
    }
}
$token = csrf_token();
$appTitle = AppSettings::appDisplayTitle($config['app'] ?? []);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($appTitle) ?> — Admin login</title>
<link rel="stylesheet" href="<?= htmlspecialchars(url('assets/style.css')) ?>">
</head>
<body class="auth-page">
  <form method="post" class="auth-card">
    <p class="app-brand auth-brand"><?= htmlspecialchars($appTitle) ?></p>
    <h1>Admin login</h1>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
    <label>Password
      <input type="password" name="password" required autofocus>
    </label>
    <button type="submit" class="btn btn-primary">Sign in</button>
    <p class="hint"><a href="<?= htmlspecialchars(url()) ?>">Back to chat</a></p>
  </form>
</body>
</html>
