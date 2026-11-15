<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

// Public read-only UI config for the chat frontend (no secrets).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $cfg = AppSettings::uiConfig();
    json_out([
        'intro' => $cfg['intro'],
        'chat_history' => !empty($cfg['chat_history']),
        'features' => $cfg['features'],
        'simple_explain_age' => AppSettings::simpleExplainAge(),
    ]);
} catch (Throwable $e) {
    $fallback = AppSettings::defaultUiConfig();
    $fallback['simple_explain_age'] = AppSettings::DEFAULT_SIMPLE_EXPLAIN_AGE;
    json_out($fallback);
}
