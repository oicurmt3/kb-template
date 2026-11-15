<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Chunker.php';
require_once __DIR__ . '/../includes/Ingest.php';

admin_require();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $doc = DB::one(
            'SELECT id, filename, mime, size_bytes, title, department, tags, source_url, status, error_message,
                    ingest_mode, deepseek_file_id, parse_purpose, created_at, updated_at
             FROM documents WHERE id = ?',
            [$id]
        );
        if (!$doc) json_error('Not found', 404);
        $count = DB::one('SELECT COUNT(*) AS n FROM chunks WHERE document_id = ?', [$id]);
        $doc['chunk_count'] = (int)($count['n'] ?? 0);
        $doc['tag_list'] = normalize_document_tags($doc['tags'] ?? '');
        json_out(['document' => $doc]);
    }

    if ($method === 'GET') {
        $rows = DB::all(
            'SELECT d.id, d.filename, d.title, d.department, d.tags, d.source_url, d.status, d.size_bytes, d.error_message,
                    d.ingest_mode, d.deepseek_file_id, d.parse_purpose, d.created_at,
                    (SELECT COUNT(*) FROM chunks c WHERE c.document_id = d.id) AS chunk_count
             FROM documents d
             ORDER BY d.created_at DESC
             LIMIT 200'
        );
        foreach ($rows as &$row) {
            $row['tag_list'] = normalize_document_tags($row['tags'] ?? '');
        }
        unset($row);
        json_out(['documents' => $rows]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        csrf_check();
        $id = (int)$_GET['id'];
        $ingest = new Ingest($config);
        $ingest->deleteDocument($id);
        json_out(['ok' => true]);
    }

    if ($method === 'POST') {
        csrf_check();
        $body = read_json_body();
        $action = $body['action'] ?? '';
        if ($action === 'ingest_text') {
            if (!rate_limit_check('admin_ingest_text', 20, 60)) {
                json_error('Too many requests', 429);
            }
            $text = isset($body['text']) && is_string($body['text']) ? $body['text'] : '';
            $title = isset($body['title']) ? trim((string)$body['title']) : null;
            $department = isset($body['department']) ? trim((string)$body['department']) : null;
            $tags = isset($body['tags']) ? document_tags_string($body['tags']) : null;
            $sourceUrl = isset($body['source_url']) ? normalize_source_url((string)$body['source_url']) : null;
            if (isset($body['source_url']) && trim((string)$body['source_url']) !== '' && $sourceUrl === null) {
                json_error('Invalid source URL (use http:// or https://)');
            }
            $format = isset($body['format']) ? trim((string)$body['format']) : 'txt';
            if ($title === '') $title = null;
            if ($department === '') $department = null;
            $ingest = new Ingest($config);
            $doc = $ingest->ingestPastedText($text, $title, $department, $tags, $sourceUrl, $format);
            json_out(['ok' => true, 'document' => $doc]);
        }
        if ($action === 'rechunk' && isset($body['id'])) {
            $ingest = new Ingest($config);
            $result = $ingest->rechunkDocument((int)$body['id']);
            json_out(['ok' => true, 'result' => $result]);
        }
        if ($action === 'update_meta' && isset($body['id'])) {
            $id = (int)$body['id'];
            $doc = DB::one('SELECT id FROM documents WHERE id = ?', [$id]);
            if (!$doc) json_error('Not found', 404);

            $sets = [];
            $args = [];
            if (array_key_exists('title', $body)) {
                $title = trim((string)$body['title']);
                $sets[] = 'title = ?';
                $args[] = $title !== '' ? mb_substr($title, 0, 255) : null;
            }
            if (array_key_exists('department', $body)) {
                $dept = trim((string)$body['department']);
                $sets[] = 'department = ?';
                $args[] = $dept !== '' ? mb_substr($dept, 0, 100) : null;
            }
            if (array_key_exists('tags', $body) || array_key_exists('tags_text', $body)) {
                $rawTags = $body['tags'] ?? ($body['tags_text'] ?? '');
                $tagStr = document_tags_string($rawTags);
                $sets[] = 'tags = ?';
                $args[] = $tagStr;
            }
            if (array_key_exists('source_url', $body)) {
                $url = normalize_source_url(
                    is_string($body['source_url']) ? $body['source_url'] : null
                );
                // Empty string clears; invalid non-empty rejects
                $rawUrl = trim((string)($body['source_url'] ?? ''));
                if ($rawUrl !== '' && $url === null) {
                    json_error('Invalid source URL (use http:// or https://)');
                }
                $sets[] = 'source_url = ?';
                $args[] = $url;
            }
            if ($sets === []) {
                json_error('Nothing to update');
            }
            $sets[] = 'updated_at = NOW()';
            $args[] = $id;
            DB::run('UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);

            // Keep DeepSeek stub search_text in sync with new tags/title
            $updated = DB::one(
                'SELECT id, title, filename, department, tags, source_url, ingest_mode, parse_purpose FROM documents WHERE id = ?',
                [$id]
            );
            if ($updated && ($updated['ingest_mode'] ?? '') === 'deepseek') {
                $search = mb_strtolower(trim(
                    (string)($updated['title'] ?? '') . ' ' .
                    (string)($updated['filename'] ?? '') . ' ' .
                    (string)($updated['department'] ?? '') . ' ' .
                    (string)($updated['tags'] ?? '')
                ));
                $search = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $search) ?? $search;
                $search = trim(preg_replace('/\s+/u', ' ', $search) ?? $search);
                DB::run(
                    'UPDATE chunks SET search_text = ?, updated_at = NOW() WHERE document_id = ? AND chunk_key = ?',
                    [$search !== '' ? $search : 'document', $id, 'deepseek-stub']
                );
            }

            $count = DB::one('SELECT COUNT(*) AS n FROM chunks WHERE document_id = ?', [$id]);
            $updated['chunk_count'] = (int)($count['n'] ?? 0);
            $updated['tag_list'] = normalize_document_tags($updated['tags'] ?? '');
            json_out(['ok' => true, 'document' => $updated]);
        }
        json_error('Unsupported action', 400);
    }

    json_error('Unsupported', 405);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'source_url') !== false && stripos($msg, 'Unknown column') !== false) {
        json_error('Column source_url missing — run sql/alter_documents_source_url.sql', 500);
    }
    json_error($msg, 500);
}
