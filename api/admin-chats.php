<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

admin_require();
$method = $_SERVER['REQUEST_METHOD'];
$appCfg = $config['app'] ?? [];

try {
    if ($method === 'GET' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $conv = DB::one(
            'SELECT id, session_id, title, created_at, updated_at FROM conversations WHERE id = ?',
            [$id]
        );
        if (!$conv) json_error('Not found', 404);
        $msgs = DB::all(
            'SELECT id, role, content, created_at FROM messages WHERE conversation_id = ? ORDER BY id',
            [$id]
        );
        json_out(['conversation' => $conv, 'messages' => $msgs]);
    }

    if ($method === 'GET') {
        $limits = AppSettings::chatLimits($appCfg);
        $listLimit = $limits['list_limit'];
        $rows = DB::all(
            'SELECT c.id, c.session_id, c.title, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS message_count
             FROM conversations c
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT ' . (int)$listLimit
        );
        $total = DB::one('SELECT COUNT(*) AS n FROM conversations');
        json_out([
            'conversations' => $rows,
            'total' => (int)($total['n'] ?? 0),
            'limits' => $limits,
        ]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        csrf_check();
        if (!rate_limit_check('admin_chats', 60, 60)) {
            json_error('Rate limit exceeded', 429);
        }
        $id = (int)$_GET['id'];
        DB::run('DELETE FROM conversations WHERE id = ?', [$id]);
        json_out(['ok' => true, 'deleted' => 1]);
    }

    if ($method === 'POST') {
        csrf_check();
        if (!rate_limit_check('admin_chats', 60, 60)) {
            json_error('Rate limit exceeded', 429);
        }
        $body = read_json_body();
        $action = (string)($body['action'] ?? '');

        if ($action === 'save_limits') {
            $saved = AppSettings::saveChatLimits($body);
            json_out(['ok' => true, 'limits' => $saved]);
        }

        if ($action === 'delete_all') {
            $st = DB::run('DELETE FROM conversations');
            json_out(['ok' => true, 'deleted' => $st->rowCount()]);
        }

        if ($action === 'delete_ids') {
            $ids = $body['ids'] ?? [];
            if (!is_array($ids) || $ids === []) {
                json_error('ids required');
            }
            $deleted = 0;
            foreach ($ids as $rawId) {
                $id = (int)$rawId;
                if ($id <= 0) continue;
                DB::run('DELETE FROM conversations WHERE id = ?', [$id]);
                $deleted++;
            }
            json_out(['ok' => true, 'deleted' => $deleted]);
        }

        if ($action === 'prune') {
            $byAge = AppSettings::pruneChatsByRetention($appCfg);
            $byGlobal = AppSettings::enforceGlobalChatLimit($appCfg);
            json_out([
                'ok' => true,
                'deleted_by_retention' => $byAge,
                'deleted_by_global_cap' => $byGlobal,
                'deleted' => $byAge + $byGlobal,
                'limits' => AppSettings::chatLimits($appCfg),
            ]);
        }

        json_error('Unsupported action', 400);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
