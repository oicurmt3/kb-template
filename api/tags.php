<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Public list of document tags for chat filter pills.
 */
ensure_session_cookie();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Method not allowed', 405);
    }

    $rows = DB::all(
        'SELECT tags FROM documents WHERE status = "ready" AND tags IS NOT NULL AND tags != ""'
    );
    $counts = [];
    foreach ($rows as $row) {
        foreach (normalize_document_tags($row['tags'] ?? '') as $tag) {
            $key = mb_strtolower($tag);
            if (!isset($counts[$key])) {
                $counts[$key] = ['tag' => $tag, 'count' => 0];
            }
            $counts[$key]['count']++;
            // Prefer first-seen casing
        }
    }
    $list = array_values($counts);
    usort($list, static function ($a, $b) {
        if ($b['count'] === $a['count']) {
            return strcasecmp($a['tag'], $b['tag']);
        }
        return $b['count'] <=> $a['count'];
    });

    json_out([
        'tags' => array_map(static fn($t) => $t['tag'], $list),
        'tag_counts' => $list,
    ]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
