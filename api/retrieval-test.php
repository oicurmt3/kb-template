<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Retrieval.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

csrf_check();

try {
    $body = read_json_body();
    $query = trim((string)($body['query'] ?? ''));
    if ($query === '') json_error('query required');
    $docId = isset($body['document_id']) ? (int)$body['document_id'] : null;
    if ($docId === 0) $docId = null;
    $focusTags = normalize_document_tags($body['tags'] ?? ($body['focus_tags'] ?? []));

    $ragCfg = $config['rag'] ?? [];
    $topK = (int)($ragCfg['top_k'] ?? 14);
    if (isset($body['top_k'])) $topK = (int)$body['top_k'];
    $maxPerDoc = (int)($ragCfg['max_per_document'] ?? 5);
    $adaptiveOn = AppSettings::adaptiveRetrievalEnabled($ragCfg);
    if (array_key_exists('adaptive', $body)) {
        $adaptiveOn = !empty($body['adaptive']);
    }

    $availableTags = [];
    try {
        $tagRows = DB::all(
            'SELECT tags FROM documents WHERE status = "ready" AND tags IS NOT NULL AND tags != ""'
        );
        foreach ($tagRows as $tr) {
            foreach (normalize_document_tags($tr['tags'] ?? '') as $t) {
                $availableTags[] = $t;
            }
        }
        $availableTags = normalize_document_tags($availableTags);
    } catch (Throwable $e) {
        $availableTags = [];
    }

    $prep = Retrieval::prepareRetrievalQuery($query, [], $availableTags, $config['deepseek'] ?? []);
    $searchQuery = $prep['query'];
    if ($focusTags === [] && !empty($prep['inferred_tags'])) {
        $focusTags = $prep['inferred_tags'];
    }

    $retrieval = new Retrieval($topK, $maxPerDoc, Retrieval::optsFromRagConfig($ragCfg, $adaptiveOn));
    $result = $retrieval->searchWithMeta(
        $searchQuery,
        $docId,
        Retrieval::expandQuerySynonyms($query),
        $focusTags
    );
    $hits = $result['hits'];
    $quality = Retrieval::hitQuality($hits, $searchQuery);
    $result['meta']['quality'] = $quality;
    $built = Retrieval::buildContext($hits);

    json_out([
        'ok' => true,
        'query' => $query,
        'search_query' => $searchQuery,
        'rewritten' => !empty($prep['rewritten']),
        'focus_tags' => $focusTags,
        'inferred_tags' => $prep['inferred_tags'] ?? [],
        'hits' => $hits,
        'hit_count' => count($hits),
        'quality' => $quality,
        'citations' => $built['citations'],
        'context_preview' => mb_substr($built['context'], 0, 4000),
        'meta' => $result['meta'],
    ]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
