<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

admin_require();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $docId = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
        if (!$docId) json_error('document_id required');
        $doc = DB::one('SELECT id, title, filename, status FROM documents WHERE id = ?', [$docId]);
        if (!$doc) json_error('Document not found', 404);
        $chunks = DB::all(
            'SELECT id, chunk_index, chunk_key, text, header_path, token_count, created_at, updated_at
             FROM chunks WHERE document_id = ? ORDER BY chunk_index',
            [$docId]
        );
        json_out(['document' => $doc, 'chunks' => $chunks]);
    }

    if ($method === 'PUT' || $method === 'POST') {
        csrf_check();
        $body = read_json_body();
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_error('id required');
        $chunk = DB::one('SELECT id, document_id FROM chunks WHERE id = ?', [$id]);
        if (!$chunk) json_error('Chunk not found', 404);

        $text = isset($body['text']) ? (string)$body['text'] : null;
        $headerPath = array_key_exists('header_path', $body) ? (string)$body['header_path'] : null;
        if ($text === null) json_error('text required');
        $text = trim($text);
        if ($text === '') json_error('text cannot be empty');

        $search = mb_strtolower($text);
        $search = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $search) ?? $search;
        $search = trim(preg_replace('/\s+/u', ' ', $search) ?? $search);
        $tokens = (int)max(1, (int)ceil(mb_strlen($text) / 4));

        if ($headerPath !== null) {
            DB::run(
                'UPDATE chunks SET text = ?, header_path = ?, search_text = ?, token_count = ?, updated_at = NOW() WHERE id = ?',
                [$text, $headerPath !== '' ? $headerPath : null, $search, $tokens, $id]
            );
        } else {
            DB::run(
                'UPDATE chunks SET text = ?, search_text = ?, token_count = ?, updated_at = NOW() WHERE id = ?',
                [$text, $search, $tokens, $id]
            );
        }
        json_out(['ok' => true]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        csrf_check();
        DB::run('DELETE FROM chunks WHERE id = ?', [(int)$_GET['id']]);
        json_out(['ok' => true]);
    }

    json_error('Unsupported', 405);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
