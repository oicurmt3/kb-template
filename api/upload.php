<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Chunker.php';
require_once __DIR__ . '/../includes/Ingest.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

csrf_check();

try {
    if (empty($_FILES['file'])) {
        json_error('No file uploaded');
    }
    $title = isset($_POST['title']) ? trim((string)$_POST['title']) : null;
    $department = isset($_POST['department']) ? trim((string)$_POST['department']) : null;
    $tags = isset($_POST['tags']) ? document_tags_string($_POST['tags']) : null;
    $sourceUrl = isset($_POST['source_url']) ? normalize_source_url((string)$_POST['source_url']) : null;
    if (isset($_POST['source_url']) && trim((string)$_POST['source_url']) !== '' && $sourceUrl === null) {
        json_error('Invalid source URL (use http:// or https://)');
    }
    $parsePurpose = isset($_POST['parse_purpose']) ? trim((string)$_POST['parse_purpose']) : null;
    $ingestMethod = isset($_POST['ingest_method']) ? trim((string)$_POST['ingest_method']) : null;
    $autoTitle = !empty($_POST['auto_title']);
    if ($title === '') $title = null;
    if ($department === '') $department = null;
    if ($parsePurpose === '') $parsePurpose = null;
    if ($ingestMethod === '') $ingestMethod = null;

    $ingest = new Ingest($config);
    $doc = $ingest->ingestUpload(
        $_FILES['file'],
        $title,
        $department,
        $tags,
        $parsePurpose,
        $ingestMethod,
        $sourceUrl,
        $autoTitle
    );
    json_out(['ok' => true, 'document' => $doc]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
