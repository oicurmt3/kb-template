<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$lockFile = __DIR__ . '/storage/install.lock';
$checks = [];
$dbReady = false;
$config = [];

if (is_file($lockFile) && !isset($_GET['force'])) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Install locked</title>';
    echo '<link rel="stylesheet" href="' . htmlspecialchars(url('assets/style.css')) . '"></head>';
    echo '<body class="admin-page"><header class="admin-header"><h1>Install locked</h1></header>';
    echo '<main><section class="card"><p>Installer is locked. Delete <code>storage/install.lock</code> or visit with <code>?force=1</code> to re-run.</p>';
    echo '<p><a href="' . htmlspecialchars(url()) . '">Back to chat</a></p></section></main></body></html>';
    exit;
}

$configFile = __DIR__ . '/includes/config.php';
if (!is_file($configFile)) {
    $checks[] = ['Config', 'Missing includes/config.php — copy from config.example.php', 'err'];
} else {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/DeepSeek.php';
    $config = require $configFile;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check_form();
    }

    try {
        DB::init($config['db']);
        DB::one('SELECT 1');
        $dbReady = true;
        $checks[] = ['Database', 'Connected', 'ok'];
    } catch (Throwable $e) {
        $checks[] = ['Database', $e->getMessage(), 'err'];
    }

    if (isset($_POST['hash_password'])) {
        $hash = password_hash((string)$_POST['hash_password'], PASSWORD_BCRYPT);
        $checks[] = ['Password hash (copy into config.php)', $hash, 'ok'];
    }

    if (isset($_POST['create_tables'])) {
        if (!$dbReady) {
            $checks[] = ['Create tables', 'Database not connected', 'err'];
        } else {
            try {
                $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
                if ($sql === false) {
                    throw new RuntimeException('Could not read schema.sql');
                }
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                    DB::pdo()->exec($stmt);
                }
                $checks[] = ['Create tables', 'Done', 'ok'];
            } catch (Throwable $e) {
                $checks[] = ['Create tables', $e->getMessage(), 'err'];
            }
        }
    }

    if (isset($_POST['test_api'])) {
        try {
            $client = new DeepSeekClient($config['deepseek']);
            $r = $client->ping();
            $checks[] = ['DeepSeek API', 'HTTP ' . $r['status'], ($r['status'] === 200 ? 'ok' : 'err')];
        } catch (Throwable $e) {
            $checks[] = ['DeepSeek API', $e->getMessage(), 'err'];
        }
    }

    if (isset($_POST['lock_install'])) {
        if (!is_dir(__DIR__ . '/storage')) {
            @mkdir(__DIR__ . '/storage', 0750, true);
        }
        file_put_contents($lockFile, date('c') . "\n");
        $checks[] = ['Install lock', 'Created storage/install.lock', 'ok'];
    }
}

$token = is_file($configFile) ? csrf_token() : '';
$hasConfig = is_file($configFile);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install</title>
<link rel="stylesheet" href="<?= htmlspecialchars(url('assets/style.css')) ?>">
</head>
<body class="admin-page">
<header class="admin-header"><h1>Install</h1></header>
<main>
  <section class="card">
    <h2>Checks</h2>
    <table class="table">
      <?php foreach ($checks as [$k, $v, $st]): ?>
        <tr>
          <td><?= htmlspecialchars($k) ?></td>
          <td class="mono"><?= htmlspecialchars((string)$v) ?></td>
          <td class="<?= $st === 'ok' ? 'ok' : 'err' ?>"><?= $st === 'ok' ? '✓' : '✗' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$checks): ?>
        <tr><td colspan="3">No checks yet — edit config.php, then use the actions below.</td></tr>
      <?php endif; ?>
    </table>
  </section>

  <?php if ($hasConfig): ?>
  <section class="card">
    <h2>Actions</h2>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <button type="submit" name="create_tables" class="btn btn-primary">Create / verify tables</button>
      <button type="submit" name="test_api" class="btn btn-secondary">Test DeepSeek API key</button>
      <button type="submit" name="lock_install" class="btn btn-destructive">Lock installer</button>
    </form>
  </section>

  <section class="card">
    <h2>Generate admin password hash</h2>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <input type="text" name="hash_password" placeholder="new admin password" required>
      <button type="submit" class="btn btn-primary">Generate hash</button>
    </form>
    <p class="hint">Paste the hash into <code>includes/config.php → admin.password_hash</code>.</p>
  </section>
  <?php else: ?>
  <section class="card">
    <h2>Fix config first</h2>
    <p>Copy <code>includes/config.example.php</code> to <code>includes/config.php</code> and fill in DB credentials.</p>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>After success</h2>
    <p>Click <strong>Lock installer</strong>, or delete/rename <code>install.php</code> on production.</p>
  </section>
</main>
</body>
</html>
