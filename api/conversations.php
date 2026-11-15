<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$sid    = ensure_session_cookie();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && isset($_GET['id'])) {
        if (!AppSettings::chatHistoryEnabled()) {
            json_error('Chat history is disabled', 403);
        }
        $id = (int)$_GET['id'];
        $conv = DB::one('SELECT id, title, created_at FROM conversations WHERE id = ? AND session_id = ?', [$id, $sid]);
        if (!$conv) json_error('Not found', 404);
        $msgs = DB::all(
            'SELECT id, role, content, blocks, created_at FROM messages WHERE conversation_id = ? ORDER BY id',
            [$id]
        );
        json_out(['conversation' => $conv, 'messages' => $msgs]);
    }

    if ($method === 'GET') {
        if (!AppSettings::chatHistoryEnabled()) {
            json_out(['conversations' => [], 'chat_history' => false]);
        }
        $limits = AppSettings::chatLimits($config['app'] ?? []);
        $listLimit = (int)$limits['list_limit'];
        $rows = DB::all(
            'SELECT id, title, updated_at FROM conversations WHERE session_id = ? ORDER BY updated_at DESC LIMIT ' . $listLimit,
            [$sid]
        );
        json_out(['conversations' => $rows, 'chat_history' => true]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        DB::run('DELETE FROM conversations WHERE id = ? AND session_id = ?', [(int)$_GET['id'], $sid]);
        json_out(['ok' => true]);
    }

    json_error('Unsupported', 405);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
