<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WebFetch.php';

/**
 * Turn-only extra context: upload a local text-like file, DeepSeek OCR / Files parse for binaries, or fetch a URL.
 * POST multipart file=…  OR  JSON { "url": "https://..." }
 */
ensure_session_cookie();

$webCfg = AppSettings::mergeWebConfig($config['web'] ?? []);
$maxChars = (int)($webCfg['max_context_chars'] ?? 12000);
$maxUpload = (int)($config['rag']['max_upload_bytes'] ?? 5_000_000);
$maxParser = (int)($config['rag']['max_parser_bytes'] ?? 25_000_000);
$localExt = array_map('strtolower', $config['rag']['allowed_ext'] ?? ['md', 'txt', 'csv', 'json', 'html', 'htm']);
$ocrExt = array_map('strtolower', $config['rag']['ocr_ext'] ?? [
    'pdf', 'doc', 'docx', 'ppt', 'pptx',
    'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff',
]);
$parserExt = array_map('strtolower', $config['rag']['parser_ext'] ?? [
    'pdf', 'doc', 'docx', 'ppt', 'pptx',
    'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff',
    'xls', 'xlsx', 'epub',
]);
$allExt = array_values(array_unique(array_merge($localExt, $ocrExt, $parserExt)));

/**
 * @return 'local'|'ocr'|'deepseek'
 */
function context_resolve_mode(string $ext, array $localExt, array $ocrExt, array $parserExt, array $ragCfg): string {
    if (in_array($ext, $localExt, true)) {
        return 'local';
    }
    if (in_array($ext, $ocrExt, true)) {
        $defaultBinary = strtolower((string)($ragCfg['binary_ingest'] ?? 'ocr'));
        return $defaultBinary === 'deepseek' ? 'deepseek' : 'ocr';
    }
    if (in_array($ext, $parserExt, true)) {
        return 'deepseek';
    }
    return 'local';
}

function context_parse_purpose(string $ext, array $ragCfg, string $mode): string {
    if ($mode === 'deepseek' && in_array($ext, ['xls', 'xlsx', 'epub', 'mobi'], true)) {
        return 'user_data';
    }
    $cfg = strtolower((string)($ragCfg['parse_purpose'] ?? 'text_recognition'));
    return in_array($cfg, ['text_recognition', 'rich_layout_recognition'], true)
        ? $cfg
        : 'text_recognition';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_error('Upload failed');
        }
        $name = (string)($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allExt, true)) {
            json_error('Unsupported file type');
        }

        $mode = context_resolve_mode($ext, $localExt, $ocrExt, $parserExt, $config['rag'] ?? []);
        $size = (int)($file['size'] ?? 0);
        $sizeCap = ($mode === 'local') ? $maxUpload : $maxParser;
        if ($size > $sizeCap) {
            json_error('File too large');
        }

        $tmp = (string)$file['tmp_name'];
        if ($tmp === '' || !is_file($tmp)) {
            json_error('Could not read file');
        }

        if ($mode === 'local') {
            $raw = file_get_contents($tmp);
            if ($raw === false) {
                json_error('Could not read file');
            }
            if (in_array($ext, ['html', 'htm'], true)) {
                $raw = html_entity_decode(strip_tags(
                    preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $raw) ?? $raw
                ));
            }
            $text = trim($raw);
            if (mb_strlen($text) > $maxChars) {
                $text = mb_substr($text, 0, $maxChars) . "\n…";
            }
            json_out([
                'ok' => true,
                'source' => 'file',
                'mode' => 'local',
                'name' => $name,
                'text' => $text,
                'chars' => mb_strlen($text),
                'file_id' => null,
            ]);
        }

        if (!rate_limit_check('context_parse', 8, 60)) {
            json_error('Rate limit exceeded', 429);
        }

        $client = new DeepSeekClient($config['deepseek'] ?? []);
        $mime = @mime_content_type($tmp) ?: 'application/octet-stream';
        $purpose = context_parse_purpose($ext, $config['rag'] ?? [], $mode);

        if ($mode === 'ocr') {
            $ocr = $client->ocrFile($tmp, $name, $purpose, $mime);
            if (!$ocr['ok'] || trim((string)($ocr['markdown'] ?? '')) === '') {
                json_error($ocr['error'] ?? 'OCR failed', 400);
            }
            $text = trim((string)$ocr['markdown']);
            if (mb_strlen($text) > $maxChars) {
                $text = mb_substr($text, 0, $maxChars) . "\n…";
            }
            json_out([
                'ok' => true,
                'source' => 'file',
                'mode' => 'ocr',
                'name' => $name,
                'text' => $text,
                'chars' => mb_strlen($text),
                'file_id' => null,
                'page_count' => (int)($ocr['page_count'] ?? 0),
            ]);
        }

        // DeepSeek Files parse (spreadsheets / ebooks / optional binary_ingest=deepseek)
        $up = $client->uploadFile($tmp, $name, $purpose, $mime);
        if (!$up['ok'] || empty($up['file_id'])) {
            json_error($up['error'] ?? 'DeepSeek Files parse failed', 400);
        }
        json_out([
            'ok' => true,
            'source' => 'file',
            'mode' => 'deepseek',
            'name' => $name,
            'text' => '',
            'chars' => 0,
            'file_id' => (string)$up['file_id'],
            'purpose' => $up['purpose'] ?? $purpose,
        ]);
    }

    $body = read_json_body();
    $url = trim((string)($body['url'] ?? ''));
    if ($url === '') {
        json_error('Provide a file upload or url');
    }
    if (empty($webCfg['enabled'])) {
        json_error('Web fetch disabled', 403);
    }
    if (!rate_limit_check('web_fetch', 20, 60)) {
        json_error('Rate limit exceeded', 429);
    }
    $wf = new WebFetch($webCfg, $config['deepseek'] ?? []);
    $r = $wf->fetchUrl($url);
    if (!$r['ok']) {
        json_error($r['error'] ?? 'Fetch failed', 400);
    }
    json_out([
        'ok' => true,
        'source' => 'url',
        'name' => $r['title'] ?: $url,
        'url' => $r['url'],
        'text' => $r['text'],
        'chars' => mb_strlen($r['text']),
    ]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
