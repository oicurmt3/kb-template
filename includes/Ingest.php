<?php
declare(strict_types=1);

class Ingest {
    private array $config;
    private Chunker $chunker;
    private string $uploadDir;
    private string $markdownDir;
    private string $pipelineDir;

    public function __construct(array $config) {
        $this->config = $config;
        $rag = $config['rag'] ?? [];
        $this->chunker = $this->makeChunker('general');
        $root = dirname(__DIR__) . '/storage';
        $this->uploadDir = $root . '/uploads';
        $this->markdownDir = $root . '/markdown';
        $this->pipelineDir = $root . '/pipeline';
        foreach ([$this->uploadDir, $this->markdownDir, $this->pipelineDir] as $d) {
            if (!is_dir($d)) {
                if (!@mkdir($d, 0750, true) && !is_dir($d)) {
                    throw new RuntimeException('Cannot create storage directory');
                }
            }
        }
    }

    private function makeChunker(string $domain): Chunker {
        $rag = $this->config['rag'] ?? [];
        return new Chunker(
            (int)($rag['chunk_max_chars'] ?? 3200),
            (int)($rag['chunk_overlap'] ?? 200),
            8000,
            (int)($rag['chunk_target_tokens'] ?? 800),
            (int)($rag['chunk_max_tokens'] ?? 2000),
            $domain
        );
    }

    /** @param array<string,mixed> $doc */
    private function docMetaFromRow(array $doc): array {
        return [
            'title' => (string)($doc['title'] ?? ''),
            'issuer' => $doc['issuer'] ?? null,
            'jurisdiction' => $doc['jurisdiction'] ?? null,
            'doc_version' => $doc['doc_version'] ?? null,
            'as_at_or_review_date' => $doc['as_at_or_review_date'] ?? null,
            'status_text' => $doc['status_text'] ?? null,
            'domain_set' => (string)($doc['domain_set'] ?? 'general'),
            'instrument' => (string)($doc['title'] ?? ''),
        ];
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

        $relMd = 'storage/markdown/' . $mdName;
        $this->setPipeline($docId, 'ocr_done', null, null);
        DB::run(
            'UPDATE documents SET markdown_path = ?, status = "processing", error_message = NULL, ingest_mode = "local",
             deepseek_file_id = NULL, parse_purpose = NULL, updated_at = NOW() WHERE id = ?',
            [$relMd, $docId]
        );

        $structureOn = !empty($this->config['rag']['structure_parse']);
        $force = !empty($this->config['rag']['structure_parse_force']);
        if ($structureOn && ($force || !StructureParse::hasRealHeadings($markdown))) {
            $pages = [['index' => 1, 'text' => $markdown]];
            $this->savePipelinePages($docId, $pages);
            $this->runIntakeAndStartParse($docId, pathinfo($mdName, PATHINFO_FILENAME) ?: 'document');
            $cont = $this->continuePipeline($docId);
            if (!empty($cont['need_continue'])) {
                return;
            }
            if (($cont['pipeline_stage'] ?? '') === 'parsed') {
                $this->rechunkFromMarkdown($docId);
                return;
            }
        }

        $this->chunkAndReady($docId, $markdown);
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

        $relMd = 'storage/markdown/' . $mdName;
        DB::run(
            'UPDATE documents SET markdown_path = ?, status = "processing", error_message = NULL, ingest_mode = "ocr",
             deepseek_file_id = NULL, parse_purpose = ?, updated_at = NOW() WHERE id = ?',
            [$relMd, $purpose, $docId]
        );
        $this->setPipeline($docId, 'ocr_done', null, null);

        $pages = [];
        foreach ($ocr['pages'] as $p) {
            $pages[] = [
                'index' => (int)($p['index'] ?? count($pages) + 1),
                'text' => (string)($p['text'] ?? ''),
            ];
        }
        if ($pages === []) {
            $pages[] = ['index' => 1, 'text' => $ocr['markdown']];
        }
        $this->savePipelinePages($docId, $pages);

        $structureOn = !array_key_exists('structure_parse', $this->config['rag'] ?? [])
            || !empty($this->config['rag']['structure_parse']);
        if ($structureOn) {
            $this->runIntakeAndStartParse($docId, $origName);
            $cont = $this->continuePipeline($docId);
            if (!empty($cont['need_continue'])) {
                return;
            }
            if (($cont['pipeline_stage'] ?? '') === 'parsed') {
                // New uploads: auto-chunk after parse completes in this request
                $this->rechunkFromMarkdown($docId);
                return;
            }
        }

        $this->chunkAndReady($docId, $markdown);
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

    /**
     * Re-chunk from existing markdown only (no OCR). Use after reparse completes.
     */
    public function rechunkFromMarkdown(int $docId): array {
        $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) throw new RuntimeException('Document not found');
        $root = dirname(__DIR__);
        $mdAbs = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
        if (empty($doc['markdown_path']) || !is_file($mdAbs)) {
            throw new RuntimeException('No markdown to chunk — run Re-parse first');
        }
        $markdown = file_get_contents($mdAbs);
        if ($markdown === false || trim($markdown) === '') {
            throw new RuntimeException('Markdown empty');
        }
        DB::run('UPDATE documents SET status = "processing", error_message = NULL, updated_at = NOW() WHERE id = ?', [$docId]);
        try {
            $this->chunkAndReady($docId, $markdown);
        } catch (Throwable $e) {
            DB::run(
                'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                [$e->getMessage(), $docId]
            );
            throw $e;
        }
        $count = DB::one('SELECT COUNT(*) AS n FROM chunks WHERE document_id = ?', [$docId]);
        return [
            'id' => $docId,
            'chunk_count' => (int)($count['n'] ?? 0),
            'ingest_mode' => (string)($doc['ingest_mode'] ?? 'local'),
            'pipeline_stage' => 'ready',
        ];
    }

    /**
     * Full re-OCR/reload + structure parse. Does NOT chunk — call continue until parsed, then rechunkFromMarkdown.
     * @return array{id:int,need_continue:bool,pipeline_stage:?string,message:string}
     */
    public function reparseDocument(int $docId): array {
        $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) throw new RuntimeException('Document not found');
        $root = dirname(__DIR__);
        $origAbs = $root . '/' . ltrim((string)($doc['original_path'] ?? ''), '/');
        $mode = (string)($doc['ingest_mode'] ?? 'local');
        if ($mode === '' && !empty($doc['deepseek_file_id'])) {
            $mode = 'deepseek';
        }

        DB::run('UPDATE documents SET status = "processing", error_message = NULL, updated_at = NOW() WHERE id = ?', [$docId]);
        try {
            if ($mode === 'deepseek' || $mode === 'ocr') {
                // Prefer OCR path for searchable corpus (stubs are weak for FULLTEXT)
                if (!is_file($origAbs)) {
                    throw new RuntimeException('Source file missing for re-parse');
                }
                $ext = strtolower(pathinfo((string)$doc['filename'], PATHINFO_EXTENSION));
                $ocrExt = $this->ocrExt();
                if (!in_array($ext, $ocrExt, true) && $mode === 'deepseek') {
                    // Keep files-parse stub refresh
                    $purpose = $this->resolveParsePurpose($ext, (string)($doc['parse_purpose'] ?? ''), 'deepseek');
                    $mime = (string)($doc['mime'] ?? 'application/octet-stream');
                    $client = new DeepSeekClient($this->config['deepseek'] ?? []);
                    $oldFileId = (string)($doc['deepseek_file_id'] ?? '');
                    if ($oldFileId !== '') $client->deleteFile($oldFileId);
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
                    return [
                        'id' => $docId,
                        'need_continue' => false,
                        'pipeline_stage' => 'ready',
                        'message' => 'DeepSeek Files stub refreshed (not OCR-searchable)',
                    ];
                }
                $purpose = $this->resolveParsePurpose($ext, (string)($doc['parse_purpose'] ?? ''), 'ocr');
                $mime = (string)($doc['mime'] ?? 'application/octet-stream');
                $client = new DeepSeekClient($this->config['deepseek'] ?? []);
                $ocr = $client->ocrFile($origAbs, (string)$doc['filename'], $purpose, $mime);
                if (!$ocr['ok'] || $ocr['markdown'] === '') {
                    throw new RuntimeException('DeepSeek OCR failed: ' . ($ocr['error'] ?? 'unknown'));
                }
                $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$doc['filename'], PATHINFO_FILENAME)) ?: 'doc';
                $safeBase = substr((string)$safeBase, 0, 80);
                $uid = bin2hex(random_bytes(6));
                $oldMd = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
                if (!empty($doc['markdown_path']) && is_file($oldMd)) {
                    @unlink($oldMd);
                }
                $docTitle = (string)($doc['title'] ?? '');
                $markdown = ($docTitle !== '' ? "# {$docTitle}\n\n" : '') . $ocr['markdown'];
                $mdName = $safeBase . '_' . $uid . '.md';
                if (file_put_contents($this->markdownDir . '/' . $mdName, $markdown) === false) {
                    throw new RuntimeException('Could not write OCR markdown');
                }
                DB::run(
                    'UPDATE documents SET markdown_path = ?, ingest_mode = "ocr", deepseek_file_id = NULL,
                     parse_purpose = ?, updated_at = NOW() WHERE id = ?',
                    ['storage/markdown/' . $mdName, $purpose, $docId]
                );
                $pages = [];
                foreach ($ocr['pages'] as $p) {
                    $pages[] = [
                        'index' => (int)($p['index'] ?? count($pages) + 1),
                        'text' => (string)($p['text'] ?? ''),
                    ];
                }
                if ($pages === []) {
                    $pages[] = ['index' => 1, 'text' => $ocr['markdown']];
                }
                $this->savePipelinePages($docId, $pages);
                $this->setPipeline($docId, 'ocr_done', null, null);
                $this->runIntakeAndStartParse($docId, (string)$doc['filename']);
            } else {
                // Local: rebuild pages from markdown/original
                $mdAbs = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
                $markdown = '';
                if (!empty($doc['original_path']) && is_file($origAbs)) {
                    $raw = file_get_contents($origAbs);
                    if ($raw === false) throw new RuntimeException('Could not read source');
                    $ext = strtolower(pathinfo((string)$doc['filename'], PATHINFO_EXTENSION));
                    $markdown = $this->toMarkdown($raw, $ext, (string)($doc['title'] ?? ''));
                    if (!empty($doc['markdown_path']) && is_file($mdAbs)) {
                        file_put_contents($mdAbs, $markdown);
                    }
                } elseif (is_file($mdAbs)) {
                    $markdown = (string)file_get_contents($mdAbs);
                } else {
                    throw new RuntimeException('Source file missing');
                }
                $this->savePipelinePages($docId, [['index' => 1, 'text' => $markdown]]);
                $this->setPipeline($docId, 'ocr_done', null, null);
                $this->runIntakeAndStartParse($docId, (string)$doc['filename']);
            }
        } catch (Throwable $e) {
            $this->setPipeline($docId, 'failed', null, $e->getMessage());
            DB::run(
                'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                [$e->getMessage(), $docId]
            );
            throw $e;
        }

        // One continue step; leave at parsed without chunking
        $cont = $this->continuePipeline($docId, false);
        return [
            'id' => $docId,
            'need_continue' => !empty($cont['need_continue']),
            'pipeline_stage' => $cont['pipeline_stage'] ?? null,
            'message' => !empty($cont['need_continue'])
                ? 'Re-parse in progress — click Continue'
                : 'Parse complete — click Re-chunk',
        ];
    }

    /**
     * Advance one G2 batch. When parse finishes, leave stage=parsed (no chunk unless $autoChunk).
     * @return array{id:int,need_continue:bool,pipeline_stage:?string,batch?:int,batches_total?:int}
     */
    public function continuePipeline(int $docId, bool $autoChunk = true): array {
        $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) throw new RuntimeException('Document not found');

        $stage = (string)($doc['pipeline_stage'] ?? '');
        if ($stage === 'parsed') {
            if ($autoChunk) {
                $this->rechunkFromMarkdown($docId);
                return ['id' => $docId, 'need_continue' => false, 'pipeline_stage' => 'ready'];
            }
            return ['id' => $docId, 'need_continue' => false, 'pipeline_stage' => 'parsed'];
        }
        if ($stage === 'ready') {
            return ['id' => $docId, 'need_continue' => false, 'pipeline_stage' => 'ready'];
        }

        $state = [];
        if (!empty($doc['pipeline_state'])) {
            $decoded = json_decode((string)$doc['pipeline_state'], true);
            if (is_array($decoded)) $state = $decoded;
        }
        $pages = $this->loadPipelinePages($docId);
        if ($pages === []) {
            throw new RuntimeException('No pipeline pages — run Re-parse');
        }

        $batchSize = max(1, min(8, (int)($state['pages_per_batch'] ?? ($this->config['rag']['pages_per_batch'] ?? 4))));
        $batchIndex = (int)($state['batch_index'] ?? 0);
        $offset = $batchIndex * $batchSize;
        if ($offset >= count($pages)) {
            $this->finalizeParsedMarkdown($docId, $state);
            if ($autoChunk) {
                $this->rechunkFromMarkdown($docId);
                return ['id' => $docId, 'need_continue' => false, 'pipeline_stage' => 'ready'];
            }
            return ['id' => $docId, 'need_continue' => false, 'pipeline_stage' => 'parsed'];
        }

        $batch = array_slice($pages, $offset, $batchSize);
        $domain = (string)($doc['domain_set'] ?? 'general');
        $docInfo = null;
        if (!empty($doc['intake_json'])) {
            $docInfo = json_decode((string)$doc['intake_json'], true);
        }
        $g2State = $state['g2_state'] ?? null;
        $parser = new StructureParse($this->config);
        $res = $parser->runParseBatch($batch, is_array($docInfo) ? $docInfo : null, is_array($g2State) ? $g2State : null, $domain);
        if (!$res['ok']) {
            $this->setPipeline($docId, 'failed', $state, $res['error'] ?? 'G2 failed');
            DB::run(
                'UPDATE documents SET status = "failed", error_message = ?, updated_at = NOW() WHERE id = ?',
                [$res['error'] ?? 'G2 failed', $docId]
            );
            throw new RuntimeException($res['error'] ?? 'G2 failed');
        }

        $mdParts = $state['md_parts'] ?? [];
        if (!is_array($mdParts)) $mdParts = [];
        $mdParts[] = (string)$res['markdown'];
        $sidecars = $state['sidecars'] ?? [];
        if (!is_array($sidecars)) $sidecars = [];
        if (is_array($res['sidecar'])) $sidecars[] = $res['sidecar'];

        $state['md_parts'] = $mdParts;
        $state['sidecars'] = $sidecars;
        $state['g2_state'] = $res['state'];
        $state['batch_index'] = $batchIndex + 1;
        $totalBatches = (int)ceil(count($pages) / $batchSize);

        if ($state['batch_index'] * $batchSize >= count($pages)) {
            $this->finalizeParsedMarkdown($docId, $state);
            if ($autoChunk) {
                $this->rechunkFromMarkdown($docId);
                return [
                    'id' => $docId,
                    'need_continue' => false,
                    'pipeline_stage' => 'ready',
                    'batch' => $state['batch_index'],
                    'batches_total' => $totalBatches,
                ];
            }
            return [
                'id' => $docId,
                'need_continue' => false,
                'pipeline_stage' => 'parsed',
                'batch' => $state['batch_index'],
                'batches_total' => $totalBatches,
            ];
        }

        $this->setPipeline($docId, 'parsing', $state, null);
        DB::run('UPDATE documents SET status = "processing", updated_at = NOW() WHERE id = ?', [$docId]);
        return [
            'id' => $docId,
            'need_continue' => true,
            'pipeline_stage' => 'parsing',
            'batch' => $state['batch_index'],
            'batches_total' => $totalBatches,
        ];
    }

    /** Legacy admin button: OCR modes reparse+continue; local rechunks markdown. */
    public function rechunkDocument(int $docId): array {
        $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) throw new RuntimeException('Document not found');
        $stage = (string)($doc['pipeline_stage'] ?? '');
        if ($stage === 'parsed' || $stage === 'parsing' || $stage === 'intake_done') {
            return $this->rechunkFromMarkdown($docId);
        }
        // Prefer markdown-only rechunk when MD exists
        $root = dirname(__DIR__);
        $mdAbs = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
        if (!empty($doc['markdown_path']) && is_file($mdAbs)) {
            return $this->rechunkFromMarkdown($docId);
        }
        // Fall back to full OCR re-ingest (will auto-chunk)
        $mode = (string)($doc['ingest_mode'] ?? 'local');
        $origAbs = $root . '/' . ltrim((string)($doc['original_path'] ?? ''), '/');
        if ($mode === 'ocr' || $mode === 'deepseek') {
            if (!is_file($origAbs)) {
                throw new RuntimeException('Source file missing');
            }
            $ext = strtolower(pathinfo((string)$doc['filename'], PATHINFO_EXTENSION));
            $purpose = $this->resolveParsePurpose($ext, (string)($doc['parse_purpose'] ?? ''), 'ocr');
            $mime = (string)($doc['mime'] ?? 'application/octet-stream');
            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$doc['filename'], PATHINFO_FILENAME)) ?: 'doc';
            $safeBase = substr((string)$safeBase, 0, 80);
            $uid = bin2hex(random_bytes(6));
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
            $count = DB::one('SELECT COUNT(*) AS n FROM chunks WHERE document_id = ?', [$docId]);
            $doc2 = DB::one('SELECT pipeline_stage, status FROM documents WHERE id = ?', [$docId]);
            return [
                'id' => $docId,
                'chunk_count' => (int)($count['n'] ?? 0),
                'ingest_mode' => 'ocr',
                'pipeline_stage' => $doc2['pipeline_stage'] ?? null,
                'need_continue' => ($doc2['status'] ?? '') === 'processing',
            ];
        }
        return $this->rechunkFromMarkdown($docId);
    }

    /** @param array<string,mixed> $state */
    private function finalizeParsedMarkdown(int $docId, array $state): void {
        $parts = $state['md_parts'] ?? [];
        if (!is_array($parts)) $parts = [];
        $markdown = trim(implode("\n\n", $parts));
        $sidecar = StructureParse::mergeSidecars(is_array($state['sidecars'] ?? null) ? $state['sidecars'] : []);
        $doc = DB::one('SELECT markdown_path, title FROM documents WHERE id = ?', [$docId]);
        $root = dirname(__DIR__);
        $mdAbs = $root . '/' . ltrim((string)($doc['markdown_path'] ?? ''), '/');
        if ($doc && !empty($doc['markdown_path']) && is_dir(dirname($mdAbs))) {
            file_put_contents($mdAbs, $markdown);
        } else {
            $uid = bin2hex(random_bytes(4));
            $mdName = 'parsed_' . $docId . '_' . $uid . '.md';
            file_put_contents($this->markdownDir . '/' . $mdName, $markdown);
            DB::run('UPDATE documents SET markdown_path = ? WHERE id = ?', ['storage/markdown/' . $mdName, $docId]);
        }
        $state['md_parts'] = [];
        $state['sidecars'] = [];
        $this->setPipeline($docId, 'parsed', $state, null);
        if (SchemaCompat::hasDocumentColumn('sidecar_json')) {
            DB::run(
                'UPDATE documents SET sidecar_json = ?, status = "processing", updated_at = NOW() WHERE id = ?',
                [json_encode($sidecar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $docId]
            );
        } else {
            DB::run('UPDATE documents SET status = "processing", updated_at = NOW() WHERE id = ?', [$docId]);
        }
    }

    private function runIntakeAndStartParse(int $docId, string $fileName): void {
        $pages = $this->loadPipelinePages($docId);
        $parser = new StructureParse($this->config);
        $intakeRes = $parser->runIntake($fileName, $pages);
        $cols = [];
        if ($intakeRes['ok'] && is_array($intakeRes['intake'])) {
            $cols = StructureParse::intakeToColumns($intakeRes['intake']);
            if (!empty($cols['doc_title']) && SchemaCompat::hasDocumentColumn('domain_set')) {
                $existing = DB::one('SELECT title FROM documents WHERE id = ?', [$docId]);
                if ($existing && (trim((string)$existing['title']) === '' || strcasecmp((string)$existing['title'], 'Untitled') === 0)) {
                    DB::run('UPDATE documents SET title = ? WHERE id = ?', [$cols['doc_title'], $docId]);
                }
            }
        } else {
            $cols = [
                'domain_set' => 'general',
                'suggested_pages_per_batch' => (int)($this->config['rag']['pages_per_batch'] ?? 4),
            ];
            // Heuristic: Act/Regulations in title/filename
            $doc = DB::one('SELECT title, filename, tags FROM documents WHERE id = ?', [$docId]);
            $hay = mb_strtolower((string)($doc['title'] ?? '') . ' ' . (string)($doc['filename'] ?? '') . ' ' . (string)($doc['tags'] ?? ''));
            if (preg_match('/\b(act|regulation|regs|workers\s*compensation|code of practice|legislation)\b/i', $hay)) {
                $cols['domain_set'] = 'legislation';
            }
        }

        if (SchemaCompat::hasDocumentColumn('domain_set')) {
            DB::run(
                'UPDATE documents SET domain_set = ?, issuer = ?, jurisdiction = ?, doc_version = ?,
                 publication_date = ?, as_at_or_review_date = ?, status_text = ?, layout_json = ?, intake_json = ?,
                 updated_at = NOW() WHERE id = ?',
                [
                    $cols['domain_set'] ?? 'general',
                    $cols['issuer'] ?? null,
                    $cols['jurisdiction'] ?? null,
                    $cols['doc_version'] ?? null,
                    $cols['publication_date'] ?? null,
                    $cols['as_at_or_review_date'] ?? null,
                    $cols['status_text'] ?? null,
                    $cols['layout_json'] ?? null,
                    $cols['intake_json'] ?? null,
                    $docId,
                ]
            );
        }

        $state = [
            'batch_index' => 0,
            'pages_per_batch' => (int)($cols['suggested_pages_per_batch'] ?? 4),
            'md_parts' => [],
            'sidecars' => [],
            'g2_state' => null,
        ];
        $this->setPipeline($docId, 'intake_done', $state, $intakeRes['ok'] ? null : ($intakeRes['error'] ?? null));
        // Move to parsing so continuePipeline runs G2
        $this->setPipeline($docId, 'parsing', $state, null);
    }

    private function chunkAndReady(int $docId, string $markdown): void {
        $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) throw new RuntimeException('Document missing');
        $meta = $this->docMetaFromRow($doc);
        $domain = (string)($meta['domain_set'] ?? 'general');
        $chunker = $this->makeChunker($domain);

        $useModel = !empty($this->config['rag']['model_chunker']);
        $chunks = [];
        if ($useModel && !preg_match('/<!--\s*RAG:CHUNK\b/i', $markdown)) {
            $chunks = $this->tryModelChunk($markdown, $doc, $meta, $chunker);
        }
        if ($chunks === []) {
            $chunks = $chunker->chunkMarkdown($markdown, (string)($doc['title'] ?? ''), $meta);
        }
        if ($chunks === []) {
            throw new RuntimeException('No content to chunk');
        }

        // Drop exclude_from_retrieval from default search set but still store
        DB::run('DELETE FROM chunks WHERE document_id = ?', [$docId]);
        $this->insertChunks($docId, $chunks, $doc);
        $this->setPipeline($docId, 'ready', null, null);
        if (SchemaCompat::hasDocumentColumn('pipeline_error')) {
            DB::run(
                'UPDATE documents SET status = "ready", error_message = NULL, pipeline_error = NULL, updated_at = NOW() WHERE id = ?',
                [$docId]
            );
        } else {
            DB::run(
                'UPDATE documents SET status = "ready", error_message = NULL, updated_at = NOW() WHERE id = ?',
                [$docId]
            );
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $meta
     * @return list<array>
     */
    private function tryModelChunk(string $markdown, array $doc, array $meta, Chunker $chunker): array {
        $prompt = PromptLibrary::withDomain('G4_chunk', (string)($meta['domain_set'] ?? 'general'));
        if ($prompt === '') return [];
        $numbered = Chunker::numberLines($markdown);
        $sidecar = null;
        if (!empty($doc['sidecar_json'])) {
            $sidecar = json_decode((string)$doc['sidecar_json'], true);
        }
        $limits = [
            'target_tokens' => (int)($this->config['rag']['chunk_target_tokens'] ?? 1000),
            'max_tokens' => (int)($this->config['rag']['chunk_max_tokens'] ?? 2000),
        ];
        $user = "DOC_INFO:\n" . json_encode($meta, JSON_UNESCAPED_UNICODE)
            . "\n\nSIDECAR:\n" . json_encode($sidecar ?? new stdClass(), JSON_UNESCAPED_UNICODE)
            . "\n\nLIMITS:\n" . json_encode($limits)
            . "\n\nNUMBERED_MARKDOWN:\n" . $numbered;
        $client = new DeepSeekClient($this->config['deepseek'] ?? []);
        $res = $client->chatComplete(
            [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => mb_substr($user, 0, 100000)],
            ],
            ['max_tokens' => 4096, 'temperature' => 0]
        );
        if (empty($res['ok'])) return [];
        $ranges = [];
        foreach (preg_split("/\r\n|\n|\r/", (string)$res['content']) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '{"excluded"')) continue;
            $obj = json_decode($line, true);
            if (is_array($obj) && isset($obj['start_line'], $obj['end_line'])) {
                $ranges[] = $obj;
            }
        }
        if ($ranges === []) return [];
        return $chunker->applyLineRanges($numbered, $ranges, (string)($doc['title'] ?? ''), $meta);
    }

    /** @param array<string,mixed>|null $state */
    private function setPipeline(int $docId, string $stage, ?array $state, ?string $error): void {
        if (!SchemaCompat::hasDocumentColumn('pipeline_stage')) {
            return;
        }
        DB::run(
            'UPDATE documents SET pipeline_stage = ?, pipeline_state = ?, pipeline_error = ?, updated_at = NOW() WHERE id = ?',
            [
                $stage,
                $state !== null ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $error,
                $docId,
            ]
        );
    }

    /** @param list<array{index:int,text:string}> $pages */
    private function savePipelinePages(int $docId, array $pages): void {
        $path = $this->pipelineDir . '/doc_' . $docId . '_pages.json';
        file_put_contents($path, json_encode(['pages' => $pages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array{index:int,text:string}> */
    private function loadPipelinePages(int $docId): array {
        $path = $this->pipelineDir . '/doc_' . $docId . '_pages.json';
        if (!is_file($path)) return [];
        $raw = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || empty($data['pages']) || !is_array($data['pages'])) {
            return [];
        }
        return $data['pages'];
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
        $pipe = $this->pipelineDir . '/doc_' . $docId . '_pages.json';
        if (is_file($pipe)) @unlink($pipe);
        DB::run('DELETE FROM documents WHERE id = ?', [$docId]);
    }

    /**
     * @param list<array> $chunks
     * @param array<string,mixed>|null $doc
     */
    private function insertChunks(int $docId, array $chunks, ?array $doc = null): void {
        if ($doc === null) {
            $doc = DB::one('SELECT * FROM documents WHERE id = ?', [$docId]) ?: [];
        }
        $rich = SchemaCompat::hasChunkColumn('domain_set');
        foreach ($chunks as $c) {
            $flags = $c['flags'] ?? [];
            if (!is_array($flags)) $flags = [];
            if (in_array('exclude_from_retrieval', $flags, true)) {
                // still insert; retrieval filters
            }
            $meta = $c['metadata'] ?? null;
            if (!$rich) {
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
                continue;
            }
            DB::run(
                'INSERT INTO chunks (
                    document_id, chunk_index, chunk_key, text, header_path, content_type, start_line, end_line,
                    flags, extra, plain_summary, sample_questions, keywords, self_contained,
                    doc_version, as_at_or_review_date, doc_status_text, domain_set,
                    token_count, metadata, search_text
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $docId,
                    $c['chunk_index'],
                    $c['chunk_key'],
                    $c['text'],
                    $c['header_path'],
                    $c['content_type'] ?? null,
                    $c['start_line'] ?? null,
                    $c['end_line'] ?? null,
                    json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($c['extra'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $c['plain_summary'] ?? null,
                    isset($c['sample_questions']) ? json_encode($c['sample_questions'], JSON_UNESCAPED_UNICODE) : null,
                    isset($c['keywords']) ? json_encode($c['keywords'], JSON_UNESCAPED_UNICODE) : null,
                    isset($c['self_contained']) ? ((int)(bool)$c['self_contained']) : null,
                    $doc['doc_version'] ?? null,
                    $doc['as_at_or_review_date'] ?? null,
                    $doc['status_text'] ?? null,
                    $doc['domain_set'] ?? 'general',
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
                        'content' => 'You name documents for an organisation document library used in search. '
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
