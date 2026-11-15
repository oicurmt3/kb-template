<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WebFetch.php';

/**
 * Turn-only extra context: upload a local text-like file or fetch a URL.
 * POST multipart file=…  OR  JSON { "url": "https://..." }
 */
ensure_session_cookie();

$webCfg = AppSettings::mergeWebConfig($config['web'] ?? []);
$maxChars = (int)($webCfg['max_context_chars'] ?? 12000);
$maxUpload = (int)($config['rag']['max_upload_bytes'] ?? 5_000_000);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_error('Upload failed');
        }
        if ((int)$file['size'] > $maxUpload) {
            json_error('File too large');
        }
        $name = (string)($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $config['rag']['allowed_ext'] ?? ['md', 'txt', 'csv', 'json', 'html', 'htm'];
        if (!in_array($ext, $allowed, true)) {
            json_error('Unsupported file type');
        }
        $raw = file_get_contents((string)$file['tmp_name']);
        if ($raw === false) json_error('Could not read file');
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
            'name' => $name,
            'text' => $text,
            'chars' => mb_strlen($text),
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
