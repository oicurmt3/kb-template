<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

admin_require();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (!rate_limit_check('admin_export', 10, 60)) {
            json_error('Rate limit exceeded', 429);
        }

        $type = strtolower(trim((string)($_GET['type'] ?? '')));
        $stamp = gmdate('Ymd\THis\Z');

        if ($type === 'settings') {
            $webCfg = $config['web'] ?? [];
            $ragCfg = $config['rag'] ?? [];
            $appCfg = $config['app'] ?? [];

            $rawRows = [];
            try {
                $rawRows = DB::all('SELECT k, v, updated_at FROM settings ORDER BY k');
            } catch (Throwable $e) {
                $rawRows = [];
            }

            $payload = [
                'export' => 'settings',
                'exported_at' => gmdate('c'),
                'app' => (string)($appCfg['title'] ?? 'WANDA'),
                'effective' => [
                    'allowed_hosts' => AppSettings::allowedHostsFromConfig($webCfg),
                    'simple_explain_age' => AppSettings::simpleExplainAge(),
                    'answer_length' => AppSettings::answerLength(),
                    'adaptive_retrieval' => AppSettings::adaptiveRetrievalEnabled($ragCfg),
                    'rag_web_mix' => AppSettings::ragWebMix(),
                    'deep_thinking_policy' => AppSettings::deepThinkingPolicy(),
                    'faithfulness' => AppSettings::faithfulness(),
                    'ui' => AppSettings::uiConfig(),
                    'chat_limits' => AppSettings::chatLimits($appCfg),
                ],
                'raw' => $rawRows,
            ];
            json_download($payload, 'wanda-settings-' . $stamp . '.json', true);
        }

        if ($type === 'chunks') {
            $docs = DB::all(
                'SELECT id, filename, mime, size_bytes, title, department, tags, source_url,
                        status, content_hash, ingest_mode, deepseek_file_id, parse_purpose,
                        created_at, updated_at
                 FROM documents
                 ORDER BY id'
            );
            $chunks = DB::all(
                'SELECT c.id, c.document_id, c.chunk_index, c.chunk_key, c.text, c.header_path,
                        c.token_count, c.metadata, c.created_at, c.updated_at,
                        d.title AS document_title, d.filename AS document_filename,
                        d.tags AS document_tags, d.status AS document_status,
                        d.ingest_mode AS document_ingest_mode, d.source_url AS document_source_url
                 FROM chunks c
                 INNER JOIN documents d ON d.id = c.document_id
                 ORDER BY c.document_id, c.chunk_index'
            );

            $payload = [
                'export' => 'chunks',
                'exported_at' => gmdate('c'),
                'app' => (string)(($config['app']['title'] ?? 'WANDA')),
                'document_count' => count($docs),
                'chunk_count' => count($chunks),
                'documents' => $docs,
                'chunks' => $chunks,
            ];
            json_download($payload, 'wanda-chunks-' . $stamp . '.json', false);
        }

        json_error('type must be settings or chunks');
    }

    if ($method === 'POST') {
        csrf_check();
        if (!rate_limit_check('admin_import', 10, 60)) {
            json_error('Rate limit exceeded', 429);
        }

        $body = read_json_body();
        $type = strtolower(trim((string)($body['type'] ?? 'settings')));
        if ($type !== 'settings') {
            json_error('Only settings import is supported (type=settings)');
        }

        $payload = $body['data'] ?? $body['payload'] ?? null;
        if ($payload === null && isset($body['export'])) {
            $payload = $body;
        }
        if (!is_array($payload)) {
            json_error('Provide data as the parsed settings JSON object');
        }
        if (isset($payload['export']) && (string)$payload['export'] === 'chunks') {
            json_error('This file is a chunks export — settings import only');
        }

        $result = AppSettings::importSettingsExport($payload, $config['app'] ?? []);
        json_out([
            'ok' => true,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'effective' => $result['effective'],
        ]);
    }

    json_error('Method not allowed', 405);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
