<?php
declare(strict_types=1);

class Ingest {
    private array $config;
    private Chunker $chunker;
    private string $uploadDir;
    private string $markdownDir;

    public function __construct(array $config) {
        $this->config = $config;
        $rag = $config['rag'] ?? [];
        $this->chunker = new Chunker(
            (int)($rag['chunk_max_chars'] ?? 3200),
            (int)($rag['chunk_overlap'] ?? 200)
        );
        $root = dirname(__DIR__) . '/storage';
        $this->uploadDir = $root . '/uploads';
        $this->markdownDir = $root . '/markdown';
        foreach ([$this->uploadDir, $this->markdownDir] as $d) {
            if (!is_dir($d)) {
                if (!@mkdir($d, 0750, true) && !is_dir($d)) {
                    throw new RuntimeException('Cannot create storage directory');
                }
            }
        }
    }

    /**
     * @param array $file $_FILES element
     * @return array document row summary
     */
    public function ingestUpload(
        array $file,
        ?string $title = null,
        ?string $department = null,
        ?string $tags = null,
        ?string $parsePurpose = null,
        ?string $ingestMethod = null,
        ?string $sourceUrl = null,
        bool $autoTitle = false
    ): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (code ' . (int)($file['error'] ?? 0) . ')');
        }

        $origName = (string)($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $mode = $this->resolveIngestMode($ext, $ingestMethod);
        $maxBytes = in_array($mode, ['deepseek', 'ocr'], true)
            ? (int)($this->config['rag']['max_parser_bytes'] ?? ($this->config['rag']['max_upload_bytes'] ?? 25_000_000))
            : (int)($this->config['rag']['max_upload_bytes'] ?? 5_000_000);
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('File too large or empty (max ' . $maxBytes . ' bytes)');
        }

        $allowed = $this->allAllowedExt();
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Unsupported file type: .' . $ext);
        }

        $tmp = (string)$file['tmp_name'];
        $raw = file_get_contents($tmp);
        if ($raw === false) {
            throw new RuntimeException('Could not read upload');
        }

        $hash = hash('sha256', $raw);
        $existing = DB::one('SELECT id, status FROM documents WHERE content_hash = ?', [$hash]);
        if ($existing && $existing['status'] === 'ready') {
            throw new RuntimeException('Duplicate document already uploaded (id ' . $existing['id'] . ')');
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME)) ?: 'doc';
        $safeBase = substr($safeBase, 0, 80);
        $uid = bin2hex(random_bytes(6));
        $storedName = $safeBase . '_' . $uid . '.' . $ext;
        $origPath = $this->uploadDir . '/' . $storedName;
        if (!move_uploaded_file($tmp, $origPath)) {
            if (@file_put_contents($origPath, $raw) === false) {
                throw new RuntimeException('Could not store upload');
            }
        }

        $mime = $this->detectMime($origPath, $ext, (string)($file['type'] ?? ''));
        if ($title !== null && trim($title) !== '') {
            $docTitle = trim($title);
        } elseif ($autoTitle && $mode === 'local' && in_array($ext, ['txt', 'md', 'html', 'htm'], true)) {
            $docTitle = $this->suggestDocumentTitle($raw, $this->guessTitle($origName, $raw, $ext));
        } else {
            $docTitle = $mode === 'local'
                ? $this->guessTitle($origName, $raw, $ext)
                : (pathinfo($origName, PATHINFO_FILENAME) ?: 'Untitled');
        }

        $purpose = $this->resolveParsePurpose($ext, $parsePurpose, $mode);
        $tags = function_exists('document_tags_string') ? document_tags_string($tags) : $tags;
        $department = $department !== null && trim($department) !== '' ? mb_substr(trim($department), 0, 100) : null;
        $sourceUrl = function_exists('normalize_source_url') ? normalize_source_url($sourceUrl) : $sourceUrl;

        DB::run(
            'INSERT INTO documents (filename, mime, size_bytes, original_path, title, department, tags, source_url, status, content_hash, ingest_mode, parse_purpose)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "processing", ?, ?, ?)',
            [
                $origName,
                $mime,
                $size,
                'storage/uploads/' . $storedName,
                $docTitle,
                $department,
                $tags,
                $sourceUrl,
                $hash,
                $mode,
                in_array($mode, ['deepseek', 'ocr'], true) ? $purpose : null,
            ]
        );
        $docId = DB::lastId();

        try {
            if ($mode === 'ocr') {
                $this->finishOcrIngest($docId, $origPath, $origName, $mime, $purpose, $docTitle, $safeBase, $uid);
            } elseif ($mode === 'deepseek') {
                $this->finishDeepseekIngest($docId, $origPath, $origName, $mime, $purpose, $docTitle, $department, $tags);
            } else {
                $this->finishLocalIngest($docId, $raw, $ext, $docTitle, $safeBase, $uid);
            }
        } catch (Throwable $e) {
            DB::run(
                'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                [$e->getMessage(), $docId]
            );
            throw $e;
        }

        return $this->documentSummary($docId);
    }

    /**
     * Ingest pasted/plain text as a local RAG document (chunked like .txt / .md uploads).
     *
     * @param 'txt'|'md' $format
     * @return array document row summary
     */
    public function ingestPastedText(
        string $text,
        ?string $title = null,
        ?string $department = null,
        ?string $tags = null,
        ?string $sourceUrl = null,
        string $format = 'txt'
    ): array {
        $text = trim(str_replace("\0", '', $text));
        if ($text === '') {
            throw new RuntimeException('Paste some text to ingest');
        }

        $format = strtolower(trim($format));
        if (!in_array($format, ['txt', 'md'], true)) {
            $format = 'txt';
        }

        $maxBytes = (int)($this->config['rag']['max_upload_bytes'] ?? 5_000_000);
        $size = strlen($text);
        if ($size > $maxBytes) {
            throw new RuntimeException('Text too large (max ' . $maxBytes . ' bytes)');
        }

        $hash = hash('sha256', $text);
        $existing = DB::one('SELECT id, status FROM documents WHERE content_hash = ?', [$hash]);
        if ($existing && $existing['status'] === 'ready') {
            throw new RuntimeException('Duplicate document already uploaded (id ' . $existing['id'] . ')');
        }

        if ($title !== null && trim($title) !== '') {
            $docTitle = trim($title);
        } else {
            $docTitle = $this->suggestDocumentTitle(
                $text,
                $this->guessTitle('pasted.' . $format, $text, $format)
            );
        }
        if ($docTitle === '' || strcasecmp($docTitle, 'pasted') === 0) {
            $docTitle = 'Pasted text';
        }
        $docTitle = mb_substr($docTitle, 0, 255);

        $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $docTitle) ?: 'pasted';
        $safeBase = substr($safeBase, 0, 80);
        $uid = bin2hex(random_bytes(6));
        $storedName = $safeBase . '_' . $uid . '.' . $format;
        $origPath = $this->uploadDir . '/' . $storedName;
        if (file_put_contents($origPath, $text) === false) {
            throw new RuntimeException('Could not store pasted text');
        }

        $tags = function_exists('document_tags_string') ? document_tags_string($tags) : $tags;
        $department = $department !== null && trim($department) !== '' ? mb_substr(trim($department), 0, 100) : null;
        $sourceUrl = function_exists('normalize_source_url') ? normalize_source_url($sourceUrl) : $sourceUrl;
        $mime = $format === 'md' ? 'text/markdown' : 'text/plain';
        $origName = mb_substr($docTitle, 0, 250) . '.' . $format;

        // Reuse a failed row with the same hash instead of leaving orphans
        if ($existing && in_array((string)$existing['status'], ['failed', 'processing', 'pending'], true)) {
            $docId = (int)$existing['id'];
            DB::run(
                'UPDATE documents SET filename = ?, mime = ?, size_bytes = ?, original_path = ?, title = ?, department = ?,
                 tags = ?, source_url = ?, status = "processing", error_message = NULL, content_hash = ?,
                 ingest_mode = "local", parse_purpose = NULL, deepseek_file_id = NULL, updated_at = NOW() WHERE id = ?',
                [
                    $origName,
                    $mime,
                    $size,
                    'storage/uploads/' . $storedName,
                    $docTitle,
                    $department,
                    $tags,
                    $sourceUrl,
                    $hash,
                    $docId,
                ]
            );
            try {
                $this->finishLocalIngest($docId, $text, $format, $docTitle, $safeBase, $uid);
            } catch (Throwable $e) {
                DB::run(
                    'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                    [$e->getMessage(), $docId]
                );
                throw $e;
            }
            return $this->documentSummary($docId);
        }

        DB::run(
            'INSERT INTO documents (filename, mime, size_bytes, original_path, title, department, tags, source_url, status, content_hash, ingest_mode, parse_purpose)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "processing", ?, "local", NULL)',
            [
                $origName,
                $mime,
                $size,
                'storage/uploads/' . $storedName,
                $docTitle,
                $department,
                $tags,
                $sourceUrl,
                $hash,
            ]
        );
        $docId = DB::lastId();

        try {
            $this->finishLocalIngest($docId, $text, $format, $docTitle, $safeBase, $uid);
        } catch (Throwable $e) {
            DB::run(
                'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                [$e->getMessage(), $docId]
            );
            throw $e;
        }

        return $this->documentSummary($docId);
    }

    private function finishLocalIngest(int $docId, string $raw, string $ext, string $docTitle, string $safeBase, string $uid): void {
        $markdown = $this->toMarkdown($raw, $ext, $docTitle);
        $mdName = $safeBase . '_' . $uid . '.md';
        $mdPath = $this->markdownDir . '/' . $mdName;
        if (file_put_contents($mdPath, $markdown) === false) {
            throw new RuntimeException('Could not write markdown');
        }

        $chunks = $this->chunker->chunkMarkdown($markdown, $docTitle);
        if ($chunks === []) {
            throw new RuntimeException('No content to chunk');
        }

        DB::run('DELETE FROM chunks WHERE document_id = ?', [$docId]);
        $this->insertChunks($docId, $chunks);

        DB::run(
            'UPDATE documents SET markdown_path = ?, status = "ready", error_message = NULL, ingest_mode = "local",
             deepseek_file_id = NULL, parse_purpose = NULL, updated_at = NOW() WHERE id = ?',
            ['storage/markdown/' . $mdName, $docId]
        );
    }

    private function finishOcrIngest(
        int $docId,
        string $absPath,
        string $origName,
        string $mime,
        string $purpose,
        string $docTitle,
        string $safeBase,
        string $uid
    ): void {
        if (!in_array($purpose, ['text_recognition', 'rich_layout_recognition'], true)) {
            $purpose = 'text_recognition';
        }
        $client = new DeepSeekClient($this->config['deepseek'] ?? []);
        $ocr = $client->ocrFile($absPath, $origName, $purpose, $mime);
        if (!$ocr['ok'] || $ocr['markdown'] === '') {
            throw new RuntimeException('DeepSeek OCR failed: ' . ($ocr['error'] ?? 'unknown'));
        }

        $markdown = ($docTitle !== '' ? "# {$docTitle}\n\n" : '') . $ocr['markdown'];
        $mdName = $safeBase . '_' . $uid . '.md';
        $mdPath = $this->markdownDir . '/' . $mdName;
        if (file_put_contents($mdPath, $markdown) === false) {
            throw new RuntimeException('Could not write OCR markdown');
        }

        $chunks = $this->chunker->chunkMarkdown($markdown, $docTitle);
        if ($chunks === []) {
            throw new RuntimeException('OCR produced no chunkable content');
        }

        DB::run('DELETE FROM chunks WHERE document_id = ?', [$docId]);
        $this->insertChunks($docId, $chunks);

        DB::run(
            'UPDATE documents SET markdown_path = ?, status = "ready", error_message = NULL, ingest_mode = "ocr",
             deepseek_file_id = NULL, parse_purpose = ?, updated_at = NOW() WHERE id = ?',
            ['storage/markdown/' . $mdName, $purpose, $docId]
        );
    }

    private function finishDeepseekIngest(
        int $docId,
        string $absPath,
        string $origName,
        string $mime,
        string $purpose,
        string $docTitle,
        ?string $department,
        ?string $tags
    ): void {
        $client = new DeepSeekClient($this->config['deepseek'] ?? []);
        $up = $client->uploadFile($absPath, $origName, $purpose, $mime);
        if (!$up['ok'] || empty($up['file_id'])) {
            throw new RuntimeException('DeepSeek parse failed: ' . ($up['error'] ?? 'unknown'));
        }

        DB::run('DELETE FROM chunks WHERE document_id = ?', [$docId]);
        $this->insertParserStubChunk($docId, $docTitle, $origName, $department, $tags, $purpose);

        DB::run(
            'UPDATE documents SET status = "ready", error_message = NULL, ingest_mode = "deepseek",
             deepseek_file_id = ?, parse_purpose = ?, markdown_path = NULL, updated_at = NOW() WHERE id = ?',
            [$up['file_id'], $purpose, $docId]
        );
    }

    private function insertParserStubChunk(
        int $docId,
        string $title,
        string $filename,
        ?string $department,
        ?string $tags,
        string $purpose
    ): void {
        $text = "DeepSeek-parsed document.\n"
            . "Title: {$title}\n"
            . "Filename: {$filename}\n"
            . ($department ? "Department: {$department}\n" : '')
            . ($tags ? "Tags: {$tags}\n" : '')
            . "Parse purpose: {$purpose}\n"
            . "Full content is attached via DeepSeek Files API when this document is retrieved in Docs mode.";
        $search = mb_strtolower(trim($title . ' ' . $filename . ' ' . (string)$department . ' ' . (string)$tags));
        $search = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $search) ?? $search;
        $search = trim(preg_replace('/\s+/u', ' ', $search) ?? $search);
        DB::run(
            'INSERT INTO chunks (document_id, chunk_index, chunk_key, text, header_path, token_count, metadata, search_text)
             VALUES (?, 0, ?, ?, ?, ?, ?, ?)',
            [
                $docId,
                'deepseek-stub',
                $text,
                $title !== '' ? $title : null,
                max(1, (int)ceil(mb_strlen($text) / 4)),
                json_encode(['source' => 'deepseek_files', 'parse_purpose' => $purpose], JSON_UNESCAPED_SLASHES),
                $search !== '' ? $search : 'document',
            ]
        );
    }

    /** @return array{id:int,filename:string,title:?string,status:string,size_bytes:int,created_at:string,chunk_count:int,ingest_mode?:string,deepseek_file_id?:?string,parse_purpose?:?string} */
    private function documentSummary(int $docId): array {
        $row = DB::one(
            'SELECT id, filename, title, status, size_bytes, created_at, ingest_mode, deepseek_file_id, parse_purpose, source_url
             FROM documents WHERE id = ?',
            [$docId]
        );
        if (!$row) {
            throw new RuntimeException('Document missing after ingest');
        }
        $count = DB::one('SELECT COUNT(*) AS n FROM chunks WHERE document_id = ?', [$docId]);
        $row['chunk_count'] = (int)($count['n'] ?? 0);
        return $row;
    }

    /** @return list<string> */
    private function allAllowedExt(): array {
        $local = array_map('strtolower', $this->config['rag']['allowed_ext'] ?? ['md', 'txt', 'csv', 'json', 'html', 'htm']);
        return array_values(array_unique(array_merge($local, $this->parserExt(), $this->ocrExt())));
    }

    /** Extensions DeepSeek OCR supports (PDF / Office / images). */
    private function ocrExt(): array {
        return array_map('strtolower', $this->config['rag']['ocr_ext'] ?? [
            'pdf', 'doc', 'docx', 'ppt', 'pptx',
            'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff',
        ]);
    }

    /** Extensions that use Files API when OCR is not chosen (includes spreadsheets/ebooks). */
    private function parserExt(): array {
        return array_map('strtolower', $this->config['rag']['parser_ext'] ?? [
            'pdf', 'doc', 'docx', 'ppt', 'pptx',
            'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff',
            'xls', 'xlsx', 'epub',
        ]);
    }

    /**
     * @param ?string $requested auto|ocr|deepseek|local
     */
    private function resolveIngestMode(string $ext, ?string $requested): string {
        $req = strtolower(trim((string)$requested));
        if ($req === 'local') {
            return 'local';
        }
        if ($req === 'ocr') {
            if (!in_array($ext, $this->ocrExt(), true)) {
                throw new RuntimeException('OCR is not supported for .' . $ext . ' — use Files parse or a local text format');
            }
            return 'ocr';
        }
        if ($req === 'deepseek' || $req === 'files' || $req === 'parse') {
            if (!in_array($ext, $this->parserExt(), true)) {
                throw new RuntimeException('DeepSeek Files parse is not supported for .' . $ext);
            }
            return 'deepseek';
        }

        // Auto: text-like → local; OCR-capable → ocr; other parser_ext → files
        $local = array_map('strtolower', $this->config['rag']['allowed_ext'] ?? ['md', 'txt', 'csv', 'json', 'html', 'htm']);
        if (in_array($ext, $local, true)) {
            return 'local';
        }
        if (in_array($ext, $this->ocrExt(), true)) {
            $defaultBinary = strtolower((string)($this->config['rag']['binary_ingest'] ?? 'ocr'));
            return $defaultBinary === 'deepseek' ? 'deepseek' : 'ocr';
        }
        if (in_array($ext, $this->parserExt(), true)) {
            return 'deepseek';
        }
        return 'local';
    }

    private function resolveParsePurpose(string $ext, ?string $requested, string $mode = 'deepseek'): string {
        $userDataExt = ['xls', 'xlsx', 'epub', 'mobi'];
        if ($mode === 'deepseek' && in_array($ext, $userDataExt, true)) {
            return 'user_data';
        }
        $req = strtolower(trim((string)$requested));
        if (in_array($req, ['text_recognition', 'rich_layout_recognition'], true)) {
            return $req;
        }
        $cfg = strtolower((string)($this->config['rag']['parse_purpose'] ?? 'text_recognition'));
        return in_array($cfg, ['text_recognition', 'rich_layout_recognition'], true)
            ? $cfg
            : 'text_recognition';
    }

    public function rechunkDocument(int $docId): array {
        $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) throw new RuntimeException('Document not found');

        $root = dirname(__DIR__);
        $origAbs = $root . '/' . ltrim((string)($doc['original_path'] ?? ''), '/');
        $mode = (string)($doc['ingest_mode'] ?? 'local');
        if ($mode === '' && !empty($doc['deepseek_file_id'])) {
            $mode = 'deepseek';
        }

        DB::run('UPDATE documents SET status = "processing", error_message = NULL WHERE id = ?', [$docId]);
        try {
            if ($mode === 'deepseek') {
                if (!is_file($origAbs)) {
                    throw new RuntimeException('Source file missing for re-parse');
                }
                $oldFileId = (string)($doc['deepseek_file_id'] ?? '');
                $ext = strtolower(pathinfo((string)$doc['filename'], PATHINFO_EXTENSION));
                $purpose = $this->resolveParsePurpose($ext, (string)($doc['parse_purpose'] ?? ''), 'deepseek');
                $mime = (string)($doc['mime'] ?? 'application/octet-stream');

                $client = new DeepSeekClient($this->config['deepseek'] ?? []);
                if ($oldFileId !== '') {
                    $client->deleteFile($oldFileId);
                }
                $this->finishDeepseekIngest(
                    $docId,
                    $origAbs,
                    (string)$doc['filename'],
                    $mime,
                    $purpose,
                    (string)($doc['title'] ?? ''),
                    isset($doc['department']) ? (string)$doc['department'] : null,
                    isset($doc['tags']) ? (string)$doc['tags'] : null
                );
            } elseif ($mode === 'ocr') {
                if (!is_file($origAbs)) {
                    throw new RuntimeException('Source file missing for re-OCR');
                }
                $ext = strtolower(pathinfo((string)$doc['filename'], PATHINFO_EXTENSION));
                $purpose = $this->resolveParsePurpose($ext, (string)($doc['parse_purpose'] ?? ''), 'ocr');
                $mime = (string)($doc['mime'] ?? 'application/octet-stream');
                $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$doc['filename'], PATHINFO_FILENAME)) ?: 'doc';
                $safeBase = substr((string)$safeBase, 0, 80);
                $uid = bin2hex(random_bytes(6));
                // Remove previous OCR markdown if present
                $oldMd = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
                if (!empty($doc['markdown_path']) && is_file($oldMd)) {
                    @unlink($oldMd);
                }
                $this->finishOcrIngest(
                    $docId,
                    $origAbs,
                    (string)$doc['filename'],
                    $mime,
                    $purpose,
                    (string)($doc['title'] ?? ''),
                    $safeBase,
                    $uid
                );
            } else {
                $mdAbs = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
                $markdown = null;
                if (!empty($doc['original_path']) && is_file($origAbs)) {
                    $raw = file_get_contents($origAbs);
                    if ($raw === false) throw new RuntimeException('Could not read source');
                    $ext = strtolower(pathinfo((string)$doc['filename'], PATHINFO_EXTENSION));
                    $markdown = $this->toMarkdown($raw, $ext, (string)($doc['title'] ?? ''));
                } elseif (!empty($doc['markdown_path']) && is_file($mdAbs)) {
                    $markdown = file_get_contents($mdAbs);
                    if ($markdown === false) throw new RuntimeException('Could not read markdown');
                } else {
                    throw new RuntimeException('Source file missing');
                }
                $chunks = $this->chunker->chunkMarkdown($markdown, (string)($doc['title'] ?? ''));
                DB::run('DELETE FROM chunks WHERE document_id = ?', [$docId]);
                $this->insertChunks($docId, $chunks);
                DB::run('UPDATE documents SET status = "ready", updated_at = NOW() WHERE id = ?', [$docId]);
            }
        } catch (Throwable $e) {
            DB::run(
                'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                [$e->getMessage(), $docId]
            );
            throw $e;
        }

        $count = DB::one('SELECT COUNT(*) AS n FROM chunks WHERE document_id = ?', [$docId]);
        return ['id' => $docId, 'chunk_count' => (int)($count['n'] ?? 0), 'ingest_mode' => $mode];
    }

    public function deleteDocument(int $docId): void {
        $doc = DB::one('SELECT original_path, markdown_path, deepseek_file_id FROM documents WHERE id = ?', [$docId]);
        if (!$doc) return;
        $fileId = trim((string)($doc['deepseek_file_id'] ?? ''));
        if ($fileId !== '') {
            try {
                $client = new DeepSeekClient($this->config['deepseek'] ?? []);
                $client->deleteFile($fileId);
            } catch (Throwable $e) {
                // continue local cleanup
            }
        }
        $root = dirname(__DIR__);
        foreach (['original_path', 'markdown_path'] as $k) {
            $rel = $doc[$k] ?? '';
            if ($rel) {
                $abs = $root . '/' . ltrim((string)$rel, '/');
                if (is_file($abs)) @unlink($abs);
            }
        }
        DB::run('DELETE FROM documents WHERE id = ?', [$docId]);
    }

    /** @param list<array> $chunks */
    private function insertChunks(int $docId, array $chunks): void {
        foreach ($chunks as $c) {
            $meta = $c['metadata'] ?? null;
            DB::run(
                'INSERT INTO chunks (document_id, chunk_index, chunk_key, text, header_path, token_count, metadata, search_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $docId,
                    $c['chunk_index'],
                    $c['chunk_key'],
                    $c['text'],
                    $c['header_path'],
                    $c['token_count'],
                    $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    $c['search_text'],
                ]
            );
        }
    }

    private function detectMime(string $path, string $ext, string $uploadType): string {
        if (class_exists('finfo')) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->file($path);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
        $map = [
            'md'   => 'text/markdown',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'html' => 'text/html',
            'htm'  => 'text/html',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
            'epub' => 'application/epub+zip',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if ($uploadType !== '' && $uploadType !== 'application/octet-stream') {
            return $uploadType;
        }
        return 'application/octet-stream';
    }

    /**
     * Ask DeepSeek for a short document title; falls back to heuristic / "Pasted text".
     */
    public function suggestDocumentTitle(string $text, ?string $fallback = null): string {
        $fallback = trim((string)$fallback);
        if ($fallback === '' || strcasecmp($fallback, 'pasted') === 0 || strcasecmp($fallback, 'pasted-text') === 0) {
            $fallback = 'Pasted text';
        }
        // Prefer an explicit markdown H1 when present
        if (preg_match('/^#\s+(.+)$/m', $text, $m)) {
            $h1 = trim(strip_tags($m[1]));
            if ($h1 !== '' && mb_strlen($h1) <= 120) {
                return mb_substr($h1, 0, 255);
            }
        }
        $sample = trim(preg_replace('/\s+/u', ' ', mb_substr($text, 0, 3500)) ?? '');
        if ($sample === '') {
            return $fallback;
        }
        try {
            $client = new DeepSeekClient($this->config['deepseek'] ?? []);
            $flash = (string)(($this->config['deepseek']['flash_model'] ?? '') ?: ($this->config['deepseek']['model'] ?? 'deepseek-chat'));
            $res = $client->chatComplete(
                [
                    [
                        'role' => 'system',
                        'content' => 'You name documents for a Western Australian workers compensation RAG library. '
                            . 'Reply with ONLY a short plain-text title (3–10 words). No quotes, no punctuation at the end, no markdown.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Suggest a title for this document excerpt:\n\n" . $sample,
                    ],
                ],
                ['model' => $flash, 'max_tokens' => 40]
            );
            if (!empty($res['ok']) && trim((string)$res['content']) !== '') {
                $t = trim((string)$res['content']);
                $t = preg_replace('/^["\'\s]+|["\'\s.]+$/u', '', $t) ?? $t;
                $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
                $t = trim($t);
                if ($t !== '' && mb_strlen($t) <= 120) {
                    return mb_substr($t, 0, 255);
                }
            }
        } catch (Throwable $e) {
            // fall through
        }
        return mb_substr($fallback, 0, 255);
    }

    private function guessTitle(string $filename, string $raw, string $ext): string {
        if (preg_match('/^---\s*\n(.*?)\n---/s', $raw, $fm)) {
            if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/mi', $fm[1], $tm)) {
                return trim($tm[1], " \t\"'");
            }
        }
        if (in_array($ext, ['md', 'txt', 'html', 'htm'], true)) {
            if (preg_match('/^#\s+(.+)$/m', $raw, $m)) {
                return trim(strip_tags($m[1]));
            }
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $raw, $m)) {
                return trim(html_entity_decode(strip_tags($m[1])));
            }
        }
        return pathinfo($filename, PATHINFO_FILENAME) ?: 'Untitled';
    }

    private function toMarkdown(string $raw, string $ext, string $title): string {
        return match ($ext) {
            'md' => $raw,
            'txt' => ($title !== '' ? "# {$title}\n\n" : '') . $raw,
            'html', 'htm' => $this->htmlToMarkdown($raw, $title),
            'csv' => $this->csvToMarkdown($raw, $title),
            'json' => $this->jsonToMarkdown($raw, $title),
            default => ($title !== '' ? "# {$title}\n\n" : '') . $raw,
        };
    }

    private function htmlToMarkdown(string $html, string $title): string {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', static function ($m) {
            $level = (int)$m[1];
            $t = trim(html_entity_decode(strip_tags($m[2])));
            return "\n" . str_repeat('#', $level) . ' ' . $t . "\n\n";
        }, $html) ?? $html;
        $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(div|li|tr)>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html));
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        if ($title !== '' && !str_starts_with($text, '#')) {
            $text = "# {$title}\n\n" . $text;
        }
        return $text;
    }

    private function csvToMarkdown(string $csv, string $title): string {
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        if ($lines === []) return $title !== '' ? "# {$title}\n" : '';
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            $rows[] = str_getcsv($line);
        }
        if ($rows === []) return '';
        $header = array_shift($rows);
        $md = $title !== '' ? "# {$title}\n\n" : '';
        $md .= '| ' . implode(' | ', array_map([$this, 'cell'], $header)) . " |\n";
        $md .= '| ' . implode(' | ', array_fill(0, count($header), '---')) . " |\n";
        foreach ($rows as $r) {
            while (count($r) < count($header)) $r[] = '';
            $r = array_slice($r, 0, count($header));
            $md .= '| ' . implode(' | ', array_map([$this, 'cell'], $r)) . " |\n";
        }
        return $md;
    }

    private function cell(string $v): string {
        return str_replace('|', '\\|', trim($v));
    }

    private function jsonToMarkdown(string $raw, string $title): string {
        $decoded = json_decode($raw, true);
        $pretty = json_encode($decoded ?? $raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $md = $title !== '' ? "# {$title}\n\n" : '';
        $md .= "```json\n" . ($pretty ?: $raw) . "\n```\n";
        return $md;
    }
}
