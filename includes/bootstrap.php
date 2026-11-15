<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing includes/config.php. Copy includes/config.example.php and edit credentials.";
    exit;
}

$config = require $configFile;

$required = [
    'helpers.php',
    'db.php',
    'auth.php',
    'DeepSeek.php',
    'Chunker.php',
    'Ingest.php',
    'Retrieval.php',
    'WebFetch.php',
    'Settings.php',
];
foreach ($required as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing includes/{$file}. Re-upload the includes folder.";
        exit;
    }
    require_once $path;
}

try {
    DB::init($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database connection failed. Check includes/config.php db settings.";
    exit;
}