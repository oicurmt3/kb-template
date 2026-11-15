<?php
declare(strict_types=1);

class DeepSeekClient {
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(array $cfg) {
        $this->apiKey  = $cfg['api_key'];
        $this->baseUrl = rtrim($cfg['base_url'], '/');
        $this->model   = $cfg['model'];
    }

    /**
     * Streams a chat completion. Calls $onEvent for 'delta', 'error', 'title'.
     * Does NOT emit 'done' — caller sends it after persisting.
     * @param array $opts Extra body fields (tools, thinking, title, model override, …)
     * @return array{content:string,usage:mixed,finish_reason:mixed,title:?string}
     */
    public function chatStream(array $messages, array $opts, callable $onEvent): array {
        $model = $this->model;
        if (!empty($opts['model']) && is_string($opts['model'])) {
            $model = $opts['model'];
            unset($opts['model']);
        }

        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => true,
            'stream_options' => ['include_usage' => true],
        ], $opts);

        $accumulated  = '';
        $usage        = null;
        $finishReason = null;
        $title        = null;
        $buffer       = '';

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (
                &$buffer, &$accumulated, &$usage, &$finishReason, &$title, $onEvent
            ) {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || str_starts_with($line, ':')) continue;
                    if (!str_starts_with($line, 'data: ')) continue;
                    $json = substr($line, 6);
                    if ($json === '[DONE]') return strlen($data);

                    $evt = json_decode($json, true);
                    if (!is_array($evt)) continue;

                    if (isset($evt['error'])) {
                        $msg = is_array($evt['error'])
                            ? ($evt['error']['message'] ?? 'API error')
                            : (string)$evt['error'];
                        $onEvent(['type' => 'error', 'message' => $msg]);
                        return strlen($data);
                    }
                    if (!empty($evt['usage'])) $usage = $evt['usage'];

                    $choice = $evt['choices'][0] ?? null;
                    if (!$choice) continue;
                    $delta = $choice['delta'] ?? [];
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $accumulated .= $delta['content'];
                        $onEvent(['type' => 'delta', 'content' => $delta['content']]);
                    }
                    // Conversation title (last chunk) — https://api-docs.deepseek.com/guides/conversation_title/
                    if (!empty($delta['title']) && is_string($delta['title'])) {
                        $title = trim($delta['title']);
                        if ($title !== '') {
                            $onEvent(['type' => 'title', 'title' => $title]);
                        }
                    }
                    if (!empty($choice['message']['title']) && is_string($choice['message']['title'])) {
                        $title = trim($choice['message']['title']);
                        if ($title !== '') {
                            $onEvent(['type' => 'title', 'title' => $title]);
                        }
                    }
                    if (!empty($choice['finish_reason'])) {
                        $finishReason = $choice['finish_reason'];
                    }
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        if (curl_errno($ch)) {
            $onEvent(['type' => 'error', 'message' => 'Network error: ' . curl_error($ch)]);
        }
        curl_close($ch);

        return [
            'content'       => $accumulated,
            'usage'         => $usage,
            'finish_reason' => $finishReason,
            'title'         => $title,
        ];
    }

    public function ping(): array {
        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'    => $this->model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => json_decode($body ?: '{}', true)];
    }

    /**
     * Non-streaming chat completion (short titles / helpers).
     * @param array<string,mixed> $opts
     * @return array{ok:bool,content:string,error:?string}
     */
    public function chatComplete(array $messages, array $opts = []): array {
        if ($this->apiKey === '' || str_contains($this->apiKey, 'REPLACE')) {
            return ['ok' => false, 'content' => '', 'error' => 'DeepSeek API key not configured'];
        }
        $model = $this->model;
        if (!empty($opts['model']) && is_string($opts['model'])) {
            $model = $opts['model'];
            unset($opts['model']);
        }
        $payload = array_merge([
            'model'      => $model,
            'messages'   => $messages,
            'stream'     => false,
            'max_tokens' => 64,
            'temperature'=> 0.2,
        ], $opts);

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno ? curl_error($ch) : null;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'content' => '', 'error' => 'Network error: ' . $err];
        }
        $data = json_decode($body ?: '{}', true);
        if (!is_array($data)) {
            return ['ok' => false, 'content' => '', 'error' => 'Invalid API response'];
        }
        if ($code >= 400 || isset($data['error'])) {
            $msg = is_array($data['error'] ?? null)
                ? (string)($data['error']['message'] ?? 'API error')
                : (string)($data['error'] ?? ('HTTP ' . $code));
            return ['ok' => false, 'content' => '', 'error' => $msg];
        }
        $content = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        return ['ok' => true, 'content' => $content, 'error' => null];
    }

    /**
     * Upload + parse via DeepSeek Files API.
     * @see https://api-docs.deepseek.com/guides/document_parsing/
     * @return array{ok:bool,file_id:?string,filename:?string,purpose:?string,bytes:?int,error:?string,status:int}
     */
    public function uploadFile(string $absPath, string $filename, string $purpose, ?string $mime = null): array {
        if ($this->apiKey === '' || str_contains($this->apiKey, 'REPLACE')) {
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => 'DeepSeek API key not configured', 'status' => 0];
        }
        if (!is_file($absPath)) {
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => 'File missing for upload', 'status' => 0];
        }
        $purpose = trim($purpose);
        if (!in_array($purpose, ['text_recognition', 'rich_layout_recognition', 'user_data'], true)) {
            $purpose = 'text_recognition';
        }
        if (!class_exists('CURLFile')) {
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => 'CURLFile required', 'status' => 0];
        }

        $cfile = $mime
            ? new CURLFile($absPath, $mime, $filename)
            : new CURLFile($absPath, mime_content_type($absPath) ?: 'application/octet-stream', $filename);

        // purpose must be sent before file (DeepSeek requirement)
        $ch = curl_init($this->baseUrl . '/files');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => [
                'purpose' => $purpose,
                'file'    => $cfile,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => 'Network error: ' . $err, 'status' => $status];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => 'Invalid JSON from Files API (HTTP ' . $status . ')', 'status' => $status];
        }
        if ($status >= 400) {
            $msg = $data['error']['message'] ?? ($data['message'] ?? ('Files API HTTP ' . $status));
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => (string)$msg, 'status' => $status];
        }
        $fileId = (string)($data['id'] ?? '');
        if ($fileId === '') {
            return ['ok' => false, 'file_id' => null, 'filename' => null, 'purpose' => null, 'bytes' => null, 'error' => 'Files API returned no file id', 'status' => $status];
        }
        return [
            'ok' => true,
            'file_id' => $fileId,
            'filename' => isset($data['filename']) ? (string)$data['filename'] : $filename,
            'purpose' => isset($data['purpose']) ? (string)$data['purpose'] : $purpose,
            'bytes' => isset($data['bytes']) ? (int)$data['bytes'] : null,
            'error' => null,
            'status' => $status,
        ];
    }

    /**
     * OCR via DeepSeek — returns page text (file is not stored in Files API).
     * @see https://api-docs.deepseek.com/guides/ocr
     * @return array{ok:bool,id:?string,purpose:?string,page_count:int,pages:list<array{index:int,text:string,finish_reason:?string}>,markdown:string,usage:mixed,error:?string,status:int}
     */
    public function ocrFile(string $absPath, string $filename, string $purpose = 'text_recognition', ?string $mime = null): array {
        $empty = [
            'ok' => false, 'id' => null, 'purpose' => null, 'page_count' => 0,
            'pages' => [], 'markdown' => '', 'usage' => null, 'error' => null, 'status' => 0,
        ];
        if ($this->apiKey === '' || str_contains($this->apiKey, 'REPLACE')) {
            $empty['error'] = 'DeepSeek API key not configured';
            return $empty;
        }
        if (!is_file($absPath)) {
            $empty['error'] = 'File missing for OCR';
            return $empty;
        }
        $purpose = trim($purpose);
        if (!in_array($purpose, ['text_recognition', 'rich_layout_recognition'], true)) {
            $purpose = 'text_recognition';
        }
        if (!class_exists('CURLFile')) {
            $empty['error'] = 'CURLFile required';
            return $empty;
        }

        $mimeType = $mime ?: (@mime_content_type($absPath) ?: 'application/octet-stream');
        $cfile = new CURLFile($absPath, $mimeType, $filename);

        $ch = curl_init($this->baseUrl . '/v1/tools/ocr');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 200,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            // purpose before file
            CURLOPT_POSTFIELDS     => [
                'purpose' => $purpose,
                'file'    => $cfile,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $empty['error'] = 'Network error: ' . $err;
            $empty['status'] = $status;
            return $empty;
        }

        // Leading keep-alive newlines are valid JSON whitespace
        $data = json_decode(ltrim((string)$body), true);
        if (!is_array($data)) {
            $empty['error'] = 'Invalid JSON from OCR API (HTTP ' . $status . ')';
            $empty['status'] = $status;
            return $empty;
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error'])
                ? (string)($data['error']['message'] ?? 'OCR error')
                : (string)$data['error'];
            $empty['error'] = $msg;
            $empty['status'] = $status >= 400 ? $status : 200;
            return $empty;
        }
        if ($status >= 400) {
            $empty['error'] = (string)($data['message'] ?? ('OCR HTTP ' . $status));
            $empty['status'] = $status;
            return $empty;
        }

        $pagesIn = $data['pages'] ?? [];
        $pages = [];
        $mdParts = [];
        if (is_array($pagesIn)) {
            foreach ($pagesIn as $p) {
                if (!is_array($p)) continue;
                $idx = (int)($p['index'] ?? count($pages));
                $text = self::cleanOcrPageText((string)($p['text'] ?? ''));
                $pages[] = [
                    'index' => $idx,
                    'text' => $text,
                    'finish_reason' => isset($p['finish_reason']) ? (string)$p['finish_reason'] : null,
                ];
                if (trim($text) === '') continue;
                $pageNo = $idx + 1;
                $mdParts[] = "## Page {$pageNo}\n\n" . trim($text);
            }
        }

        $markdown = implode("\n\n", $mdParts);
        if (trim($markdown) === '') {
            $empty['error'] = 'OCR returned no text';
            $empty['status'] = $status;
            $empty['id'] = isset($data['id']) ? (string)$data['id'] : null;
            $empty['page_count'] = (int)($data['page_count'] ?? count($pages));
            $empty['pages'] = $pages;
            return $empty;
        }

        return [
            'ok' => true,
            'id' => isset($data['id']) ? (string)$data['id'] : null,
            'purpose' => isset($data['purpose']) ? (string)$data['purpose'] : $purpose,
            'page_count' => (int)($data['page_count'] ?? count($pages)),
            'pages' => $pages,
            'markdown' => $markdown,
            'usage' => $data['usage'] ?? null,
            'error' => null,
            'status' => $status,
        ];
    }

    /** Strip OCR layout tokens (ref/box) for cleaner RAG text. */
    public static function cleanOcrPageText(string $text): string {
        // DeepSeek OCR: <｜ref｜>…<｜/ref｜> and <｜box｜>[[x1,y1,x2,y2]]<｜/box｜>
        $text = preg_replace('/<｜box｜>.*?<｜\/box｜>/us', '', $text) ?? $text;
        $text = preg_replace('/<｜\/?ref｜>/u', '', $text) ?? $text;
        $text = preg_replace('/<｜[^｜]*｜>/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** @return array{ok:bool,error:?string,status:int} */
    public function deleteFile(string $fileId): array {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return ['ok' => true, 'error' => null, 'status' => 0];
        }
        if ($this->apiKey === '' || str_contains($this->apiKey, 'REPLACE')) {
            return ['ok' => false, 'error' => 'DeepSeek API key not configured', 'status' => 0];
        }
        $ch = curl_init($this->baseUrl . '/files/' . rawurlencode($fileId));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Network error: ' . $err, 'status' => $status];
        }
        // 404 = already gone
        if ($status >= 400 && $status !== 404) {
            $data = json_decode($body ?: '{}', true);
            $msg = is_array($data) ? ($data['error']['message'] ?? ('Delete HTTP ' . $status)) : ('Delete HTTP ' . $status);
            return ['ok' => false, 'error' => (string)$msg, 'status' => $status];
        }
        return ['ok' => true, 'error' => null, 'status' => $status];
    }
}
