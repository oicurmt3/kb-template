<?php
declare(strict_types=1);

/**
 * Lightweight diagnostics for 500 debugging (no secrets).
 */
header('Content-Type: application/json; charset=utf-8');

$out = [
    'ok' => true,
    'php' => PHP_VERSION,
    'app' => null,
    'db' => null,
    'includes' => [],
    'schema_hints' => [],
];

$root = dirname(__DIR__);
$includes = [
    'helpers.php',
    'db.php',
    'auth.php',
    'DeepSeek.php',
    'Chunker.php',
    'Ingest.php',
    'Retrieval.php',
    'WebFetch.php',
    'Settings.php',
    'config.php',
];
foreach ($includes as $f) {
    $out['includes'][$f] = is_file($root . '/includes/' . $f);
}

try {
    require_once $root . '/includes/bootstrap.php';
    $out['app'] = (string)($config['app']['title'] ?? '');
    $out['db'] = 'connected';
    try {
        $cols = DB::all('SHOW COLUMNS FROM documents');
        $names = array_map(static fn($c) => (string)($c['Field'] ?? ''), $cols);
        foreach (['tags', 'ingest_mode', 'deepseek_file_id', 'parse_purpose', 'source_url'] as $need) {
            if (!in_array($need, $names, true)) {
                $hint = $need === 'source_url'
                    ? 'run sql/alter_documents_source_url.sql'
                    : 'run sql/alter_documents_deepseek.sql / alter_documents_ocr.sql';
                $out['schema_hints'][] = "documents.{$need} missing — {$hint}";
                $out['ok'] = false;
            }
        }
        $settings = DB::one("SHOW TABLES LIKE 'settings'");
        if (!$settings) {
            $out['schema_hints'][] = 'settings table missing — run sql/schema.sql settings create';
            $out['ok'] = false;
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['schema_hints'][] = 'Could not inspect schema: ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['db'] = 'failed';
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
