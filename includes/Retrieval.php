<?php
declare(strict_types=1);

class Retrieval {
    private int $topK;
    /** Soft cap of chunks from a single document in the final set */
    private int $maxPerDocument;
    private bool $adaptive = false;
    private int $topKSlim = 8;
    private int $topKSlimTagged = 6;
    private int $maxPerDocumentSlim = 3;
    private int $activateDocsSlim = 3;

    /**
     * @param array{
     *   adaptive?:bool,
     *   top_k_slim?:int,
     *   top_k_slim_tagged?:int,
     *   max_per_document_slim?:int,
     *   activate_docs_slim?:int
     * } $opts
     */
    public function __construct(int $topK = 14, int $maxPerDocument = 5, array $opts = []) {
        $this->topK = max(1, min(40, $topK));
        $this->maxPerDocument = max(1, min(20, $maxPerDocument));
        $this->adaptive = !empty($opts['adaptive']);
        $this->topKSlim = max(2, min(40, (int)($opts['top_k_slim'] ?? 8)));
        $this->topKSlimTagged = max(2, min(40, (int)($opts['top_k_slim_tagged'] ?? 6)));
        $this->maxPerDocumentSlim = max(1, min(20, (int)($opts['max_per_document_slim'] ?? 3)));
        $this->activateDocsSlim = max(1, min(20, (int)($opts['activate_docs_slim'] ?? 3)));
    }

    /** @param array<string,mixed> $ragCfg */
    public static function optsFromRagConfig(array $ragCfg, bool $adaptiveEnabled): array {
        return [
            'adaptive' => $adaptiveEnabled,
            'top_k_slim' => (int)($ragCfg['top_k_slim'] ?? 8),
            'top_k_slim_tagged' => (int)($ragCfg['top_k_slim_tagged'] ?? 6),
            'max_per_document_slim' => (int)($ragCfg['max_per_document_slim'] ?? 3),
            'activate_docs_slim' => (int)($ragCfg['activate_docs_slim'] ?? 3),
        ];
    }

    /**
     * Build a retrieval query from the latest user message + recent chat turns
     * so follow-ups like "what about juniors?" still find the right docs.
     *
     * @param list<array{role:string,content:string}> $historyMessages
     */
    public static function conversationalQuery(string $latestUserMessage, array $historyMessages, int $maxUserTurns = 4): string {
        $latestUserMessage = trim($latestUserMessage);
        $userBits = [];
        $assistantBits = [];

        foreach ($historyMessages as $m) {
            $role = $m['role'] ?? '';
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '' || $role === 'system') continue;
            if ($role === 'user') {
                $userBits[] = $content;
            } elseif ($role === 'assistant') {
                // Keep a short tail of assistant text for topic words (headings, terms)
                $assistantBits[] = mb_substr($content, 0, 280);
            }
        }

        // Prefer recent user turns (includes current message if already in history)
        $userBits = array_slice($userBits, -$maxUserTurns);
        if ($userBits === [] && $latestUserMessage !== '') {
            $userBits = [$latestUserMessage];
        }

        $parts = $userBits;
        // Add last assistant snippet for continuity (e.g. previously cited section names)
        if ($assistantBits !== []) {
            $parts[] = end($assistantBits);
        }

        $combined = trim(implode("\n", $parts));
        // Cap so FULLTEXT / LIKE stays focused
        if (mb_strlen($combined) > 1200) {
            $combined = mb_substr($combined, -1200);
        }
        return $combined !== '' ? $combined : $latestUserMessage;
    }

    /**
     * Expand WA workers-comp everyday phrases into retrieval-friendly terms.
     */
    public static function expandQuerySynonyms(string $query): string {
        $q = trim($query);
        if ($q === '') return $q;
        $map = [
            'time off' => 'incapacity weekly payments',
            'time off work' => 'incapacity weekly payments',
            'sick pay' => 'weekly payments incapacity',
            'sick leave' => 'incapacity weekly payments',
            'wage' => 'weekly payments earnings',
            'wages' => 'weekly payments earnings',
            'pay' => 'weekly payments',
            'doctor' => 'medical certificate treating practitioner',
            'gp' => 'medical certificate treating practitioner',
            'injury at work' => 'injury management workers compensation claim',
            'hurt at work' => 'injury management workers compensation claim',
            'fired' => 'dismissal termination of employment',
            'sacked' => 'dismissal termination of employment',
            'return to work' => 'return to work injury management',
            'light duties' => 'return to work suitable employment',
            'rehab' => 'rehabilitation return to work',
            'insurance' => 'insurer workers compensation',
            'claim form' => 'claim for compensation',
            'medical expenses' => 'medical and health expenses',
            'journey' => 'journey claim travel to work',
            'stress' => 'psychological injury mental',
            'bullying' => 'psychological injury',
            'deadline' => 'time limit prescribed period',
            'how long' => 'time limit duration period',
            'who pays' => 'liable employer insurer',
        ];
        $lower = mb_strtolower($q);
        $extra = [];
        foreach ($map as $phrase => $expansion) {
            if (mb_strpos($lower, $phrase) !== false) {
                $extra[] = $expansion;
            }
        }
        if ($extra === []) return $q;
        $add = implode(' ', array_unique(explode(' ', implode(' ', $extra))));
        $out = $q . ' ' . $add;
        if (mb_strlen($out) > 1400) {
            $out = mb_substr($out, 0, 1400);
        }
        return $out;
    }

    /**
     * Infer focus tags from the question using known document tags (no LLM).
     * @param list<string> $availableTags
     * @return list<string>
     */
    public static function inferFocusTags(string $query, array $availableTags, int $max = 3): array {
        $availableTags = normalize_document_tags($availableTags);
        if ($availableTags === [] || trim($query) === '') return [];
        $hay = mb_strtolower($query);
        $syn = [
            'act' => ['act', 'legislation', 'statute', 'wcima'],
            'regulations' => ['regulation', 'regulations', 'regs', 'reg.'],
            'worker' => ['worker', 'employee', 'i was hurt', 'my injury', 'i injured'],
            'employer' => ['employer', 'business', 'my staff', 'my worker', 'company'],
            'insurer' => ['insurer', 'insurance', 'claim agent'],
            'return-to-work' => ['return to work', 'rtw', 'light duties', 'suitable duties'],
            'injury-management' => ['injury management', 'rehab', 'rehabilitation'],
            'medical' => ['doctor', 'medical', 'certificate', 'gp', 'treatment'],
            'weekly-payments' => ['weekly payment', 'wages', 'pay', 'incapacity', 'time off'],
            'claim' => ['claim', 'lodge', 'notify', 'notification'],
            'dispute' => ['dispute', 'conciliation', 'arbitration', 'appeal'],
        ];
        $scores = [];
        foreach ($availableTags as $tag) {
            $key = mb_strtolower($tag);
            $needles = $syn[$key] ?? [$key];
            // also match hyphen/space variants of the tag itself
            $needles[] = str_replace('-', ' ', $key);
            $needles[] = $key;
            $hit = 0;
            foreach ($needles as $n) {
                $n = trim(mb_strtolower((string)$n));
                if ($n === '' || mb_strlen($n) < 3) continue;
                if (mb_strpos($hay, $n) !== false) $hit++;
            }
            if ($hit > 0) $scores[$tag] = $hit;
        }
        if ($scores === []) return [];
        arsort($scores);
        return array_slice(array_keys($scores), 0, max(1, min(5, $max)));
    }

    /**
     * LLM rewrite of follow-ups into a standalone retrieval query (+ optional tags).
     * Falls back to conversationalQuery + synonym expansion on failure.
     *
     * @param list<array{role:string,content:string}> $historyMessages
     * @param list<string> $availableTags
     * @param array<string,mixed> $deepseekCfg
     * @return array{query:string,rewritten:bool,inferred_tags:list<string>,baseline:string}
     */
    public static function prepareRetrievalQuery(
        string $latestUserMessage,
        array $historyMessages,
        array $availableTags,
        array $deepseekCfg = []
    ): array {
        $latest = trim($latestUserMessage);
        $baseline = self::conversationalQuery($latest, $historyMessages);
        $baseline = self::expandQuerySynonyms($baseline);
        $inferred = self::inferFocusTags($latest . ' ' . $baseline, $availableTags, 3);

        $userTurns = 0;
        foreach ($historyMessages as $m) {
            if (($m['role'] ?? '') === 'user') $userTurns++;
        }
        $looksFollowUp = (bool)preg_match(
            '/^\s*(what about|and |also |how about|those|that|it\b|they\b|the same|more on|explain|why|when|who|how long|ok but)\b/i',
            $latest
        ) || mb_strlen($latest) < 48;

        $shouldRewrite = $userTurns >= 2 || $looksFollowUp;
        if (!$shouldRewrite || $deepseekCfg === []) {
            return [
                'query' => $baseline,
                'rewritten' => false,
                'inferred_tags' => $inferred,
                'baseline' => $baseline,
            ];
        }

        $historyBits = [];
        foreach (array_slice($historyMessages, -8) as $m) {
            $role = (string)($m['role'] ?? '');
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '' || ($role !== 'user' && $role !== 'assistant')) continue;
            $historyBits[] = strtoupper($role) . ': ' . mb_substr($content, 0, 400);
        }
        $tagHint = $availableTags !== []
            ? ('Known focus tags (pick 0–3 if clearly relevant): ' . implode(', ', array_slice($availableTags, 0, 20)))
            : 'No focus tags available.';

        try {
            $client = new DeepSeekClient($deepseekCfg);
            $flash = (string)(($deepseekCfg['flash_model'] ?? '') ?: ($deepseekCfg['model'] ?? 'deepseek-chat'));
            $res = $client->chatComplete(
                [
                    [
                        'role' => 'system',
                        'content' => 'You rewrite WA workers compensation chat questions for document search. '
                            . 'Reply with ONLY compact JSON: {"query":"...","tags":["..."]}. '
                            . 'query = one standalone search question with key legal terms (section/regulation names when known). '
                            . 'tags = 0–3 tags from the provided list only. No markdown.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $tagHint . "\n\nChat:\n" . implode("\n", $historyBits)
                            . "\n\nLatest question:\n" . $latest,
                    ],
                ],
                ['model' => $flash, 'max_tokens' => 180, 'temperature' => 0.1]
            );
            if (!empty($res['ok']) && trim((string)$res['content']) !== '') {
                $raw = trim((string)$res['content']);
                if (preg_match('/\{.*\}/s', $raw, $m)) {
                    $raw = $m[0];
                }
                $data = json_decode($raw, true);
                if (is_array($data) && !empty($data['query']) && is_string($data['query'])) {
                    $q = trim($data['query']);
                    if ($q !== '') {
                        $q = self::expandQuerySynonyms($q);
                        $tags = [];
                        if (!empty($data['tags']) && is_array($data['tags'])) {
                            $tags = normalize_document_tags($data['tags']);
                            // Keep only tags that exist in the corpus
                            $availLower = array_map('mb_strtolower', $availableTags);
                            $tags = array_values(array_filter(
                                $tags,
                                static fn($t) => in_array(mb_strtolower($t), $availLower, true)
                            ));
                        }
                        if ($tags === []) $tags = $inferred;
                        return [
                            'query' => mb_substr($q, 0, 1400),
                            'rewritten' => true,
                            'inferred_tags' => array_slice($tags, 0, 3),
                            'baseline' => $baseline,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // fall through
        }

        return [
            'query' => $baseline,
            'rewritten' => false,
            'inferred_tags' => $inferred,
            'baseline' => $baseline,
        ];
    }

    /**
     * Quality signal for retrieved hits (for grounding / refuse-when-weak).
     * @param list<array> $hits
     * @return array{weak:bool,max_score:float,hit_count:int,has_legal_ref:bool}
     */
    public static function hitQuality(array $hits, string $query = ''): array {
        $max = 0.0;
        foreach ($hits as $h) {
            $max = max($max, (float)($h['score'] ?? 0));
        }
        $refs = self::extractLegalRefs($query);
        $hasRefHit = false;
        if ($refs !== [] && $hits !== []) {
            foreach ($hits as $h) {
                $hay = mb_strtolower(
                    (string)($h['header_path'] ?? '') . ' ' . (string)($h['text'] ?? '')
                );
                foreach ($refs as $ref) {
                    foreach ($ref['needles'] as $needle) {
                        if ($needle !== '' && mb_strpos($hay, mb_strtolower($needle)) !== false) {
                            $hasRefHit = true;
                            break 3;
                        }
                    }
                }
            }
        }
        $count = count($hits);
        $weak = $count === 0 || ($max < 4.0 && !$hasRefHit) || ($max < 2.5);
        return [
            'weak' => $weak,
            'max_score' => $max,
            'hit_count' => $count,
            'has_legal_ref' => $hasRefHit,
        ];
    }

    /**
     * HQ faithfulness check — returns possibly corrected answer text.
     * @param array<string,mixed> $deepseekCfg
     */
    public static function verifyGroundedAnswer(
        string $answer,
        string $excerpts,
        array $deepseekCfg = []
    ): array {
        $answer = trim($answer);
        if ($answer === '' || trim($excerpts) === '' || $deepseekCfg === []) {
            return ['content' => $answer, 'checked' => false, 'changed' => false];
        }
        try {
            $client = new DeepSeekClient($deepseekCfg);
            $flash = (string)(($deepseekCfg['flash_model'] ?? '') ?: ($deepseekCfg['model'] ?? 'deepseek-chat'));
            $res = $client->chatComplete(
                [
                    [
                        'role' => 'system',
                        'content' => 'You check WA workers compensation answers for faithfulness to DOCUMENT EXCERPTS only. '
                            . 'If every substantive claim is supported, reply with exactly: OK '
                            . 'Otherwise reply with a corrected plain-text answer that only uses supported claims '
                            . 'and clearly says what is not covered by the excerpts. No markdown.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "DOCUMENT EXCERPTS:\n" . mb_substr($excerpts, 0, 10000)
                            . "\n\nDRAFT ANSWER:\n" . mb_substr($answer, 0, 6000),
                    ],
                ],
                ['model' => $flash, 'max_tokens' => 900, 'temperature' => 0.1]
            );
            if (empty($res['ok'])) {
                return ['content' => $answer, 'checked' => false, 'changed' => false];
            }
            $out = trim((string)$res['content']);
            if ($out === '' || preg_match('/^OK\b/i', $out)) {
                return ['content' => $answer, 'checked' => true, 'changed' => false];
            }
            return ['content' => $out, 'checked' => true, 'changed' => true];
        } catch (Throwable $e) {
            return ['content' => $answer, 'checked' => false, 'changed' => false];
        }
    }

    /**
     * @param list<string> $focusTags user-selected tag pills (OR within docs that have any)
     * @return list<array{id:int,document_id:int,chunk_index:int,text:string,header_path:?string,title:?string,filename:?string,score:float}>
     */
    public function search(string $query, ?int $documentId = null, ?string $alsoQuery = null, array $focusTags = []): array {
        return $this->searchWithMeta($query, $documentId, $alsoQuery, $focusTags)['hits'];
    }

    /**
     * @param list<string> $focusTags
     * @return array{
     *   hits:list<array>,
     *   meta:array{
     *     adaptive:bool,
     *     mode:string,
     *     top_k:int,
     *     max_per_document:int,
     *     activated_doc_ids:list<int>,
     *     activated_titles:list<string>,
     *     tags_agree:bool
     *   }
     * }
     */
    public function searchWithMeta(
        string $query,
        ?int $documentId = null,
        ?string $alsoQuery = null,
        array $focusTags = []
    ): array {
        $query = trim($query);
        $focusTags = normalize_document_tags($focusTags);
        $emptyMeta = [
            'adaptive' => $this->adaptive,
            'mode' => $this->adaptive ? 'full' : 'legacy',
            'top_k' => $this->topK,
            'max_per_document' => $this->maxPerDocument,
            'activated_doc_ids' => [],
            'activated_titles' => [],
            'tags_agree' => false,
        ];
        if ($query === '' && $focusTags === []) {
            return ['hits' => [], 'meta' => $emptyMeta];
        }

        // Prefer the raw latest user question first (less diluted than history expand)
        $queries = [];
        if ($alsoQuery !== null) {
            $alsoQuery = trim($alsoQuery);
            if ($alsoQuery !== '') $queries[] = $alsoQuery;
        }
        if ($query !== '' && ($queries === [] || mb_strtolower($query) !== mb_strtolower($queries[0]))) {
            $queries[] = $query;
        }
        if ($queries === [] && $focusTags !== []) {
            $queries[] = implode(' ', $focusTags);
        }

        $primary = $queries[0] ?? $query;
        $primaryRefs = self::extractLegalRefs($primary);
        $merged = [];
        foreach ($queries as $qi => $q) {
            $weightBoost = $qi === 0 ? 1.15 : 0.9;
            $terms = $this->terms($q);
            $refs = self::extractLegalRefs($q);

            foreach ($this->sectionRefSearch($refs, $documentId) as $row) {
                $this->mergeHit($merged, $row, 2.4 * $weightBoost);
            }
            foreach ($this->naturalLanguageSearch($q, $documentId) as $row) {
                $this->mergeHit($merged, $row, 1.2 * $weightBoost);
            }
            foreach ($this->booleanOrSearch($q, $documentId) as $row) {
                $this->mergeHit($merged, $row, 1.0 * $weightBoost);
            }
            foreach ($this->keywordSearch($q, $terms, $documentId, $refs) as $row) {
                $this->mergeHit($merged, $row, 1.5 * $weightBoost);
            }
        }

        if ($documentId === null) {
            foreach ($this->crossDocumentBoost($primary) as $row) {
                $this->mergeHit($merged, $row, 1.35);
            }
            foreach ($this->focusTagDocumentHits($focusTags) as $row) {
                $this->mergeHit($merged, $row, 1.8);
            }
        }

        if ($merged === []) {
            foreach ($this->broadFallback($documentId) as $row) {
                $this->mergeHit($merged, $row, 0.3);
            }
        }

        $this->boostByFocusTags($merged, $focusTags);

        $hits = array_values($merged);
        usort($hits, static function ($a, $b) {
            if ($b['score'] === $a['score']) {
                return $a['chunk_index'] <=> $b['chunk_index'];
            }
            return $b['score'] <=> $a['score'];
        });

        // --- Legacy path (adaptive off) ---
        if (!$this->adaptive || $documentId !== null) {
            if ($focusTags !== [] && $documentId === null) {
                $hits = $this->preferFocusTaggedHits($hits, $focusTags);
            }
            $picked = $this->diversifyHits($hits, $this->topK, $this->maxPerDocument);
            if ($documentId === null) {
                $picked = $this->ensureActRegsQuota($picked, $hits, $this->topK);
            }
            $picked = $this->expandNeighborChunks($picked, $this->topK);
            $meta = $emptyMeta;
            $meta['mode'] = 'legacy';
            $meta['top_k'] = $this->topK;
            $meta['max_per_document'] = $this->maxPerDocument;
            return ['hits' => $picked, 'meta' => $meta];
        }

        // --- Adaptive path ---
        $docScores = $this->scoreDocumentsForActivation($hits, $primary, $focusTags, $primaryRefs);
        $decision = $this->decideAdaptiveMode($docScores, $focusTags, $primaryRefs, $primary);
        $mode = $decision['mode'];
        $activatedIds = $decision['activated_doc_ids'];
        $tagsAgree = $decision['tags_agree'];

        $effTopK = $this->topK;
        $effMaxPer = $this->maxPerDocument;
        if ($mode === 'slim') {
            $effTopK = $this->topKSlim;
            $effMaxPer = $this->maxPerDocumentSlim;
        } elseif ($mode === 'slim_tagged') {
            $effTopK = $this->topKSlimTagged;
            $effMaxPer = $this->maxPerDocumentSlim;
        }

        if ($mode === 'slim' || $mode === 'slim_tagged') {
            $hits = $this->softFilterActivatedHits($hits, $activatedIds, $focusTags, $effTopK);
        } elseif ($focusTags !== []) {
            $hits = $this->preferFocusTaggedHits($hits, $focusTags);
        }

        $picked = $this->diversifyHits($hits, $effTopK, $effMaxPer);

        $runQuota = $this->shouldRunActRegsQuota($mode, $focusTags, $primary, $primaryRefs);
        if ($runQuota) {
            $picked = $this->ensureActRegsQuota($picked, $hits, $effTopK);
        }

        if ($mode === 'slim' || $mode === 'slim_tagged') {
            // At most one neighbor fill when slim
            $before = count($picked);
            if ($before < $effTopK) {
                $picked = $this->expandNeighborChunks($picked, min($effTopK, $before + 1));
            }
        } else {
            $picked = $this->expandNeighborChunks($picked, $effTopK);
        }

        $titles = [];
        foreach ($docScores as $ds) {
            if (in_array((int)$ds['id'], $activatedIds, true)) {
                $t = trim((string)($ds['title'] ?? ''));
                if ($t !== '') $titles[] = $t;
            }
        }

        return [
            'hits' => $picked,
            'meta' => [
                'adaptive' => true,
                'mode' => $mode,
                'top_k' => $effTopK,
                'max_per_document' => $effMaxPer,
                'activated_doc_ids' => $activatedIds,
                'activated_titles' => $titles,
                'tags_agree' => $tagsAgree,
            ],
        ];
    }

    /**
     * Aggregate chunk evidence + title/tag/ref bonuses into per-document scores.
     * @param list<array> $hits
     * @param list<string> $focusTags
     * @param list<array> $refs
     * @return list<array{id:int,title:string,tags:string,score:float,tag_hit:bool,ref_hit:bool}>
     */
    private function scoreDocumentsForActivation(
        array $hits,
        string $primary,
        array $focusTags,
        array $refs
    ): array {
        $terms = $this->terms($primary);
        if ($terms === []) $terms = $this->looseTerms($primary);
        $focusLower = array_map(static fn($t) => mb_strtolower($t), $focusTags);

        $byDoc = [];
        foreach ($hits as $h) {
            $id = (int)($h['document_id'] ?? 0);
            if ($id <= 0) continue;
            if (!isset($byDoc[$id])) {
                $byDoc[$id] = [
                    'id' => $id,
                    'title' => (string)($h['title'] ?? ''),
                    'filename' => (string)($h['filename'] ?? ''),
                    'tags' => (string)($h['tags'] ?? ''),
                    'score' => 0.0,
                    'best_chunk' => 0.0,
                    'tag_hit' => false,
                    'ref_hit' => false,
                ];
            }
            $cs = (float)($h['score'] ?? 0);
            $byDoc[$id]['best_chunk'] = max($byDoc[$id]['best_chunk'], $cs);
            $byDoc[$id]['score'] += $cs;
            if (($h['title'] ?? '') !== '' && $byDoc[$id]['title'] === '') {
                $byDoc[$id]['title'] = (string)$h['title'];
            }
            if (($h['tags'] ?? '') !== '' && $byDoc[$id]['tags'] === '') {
                $byDoc[$id]['tags'] = (string)$h['tags'];
            }
        }

        // Also consider ready docs with no chunk hits yet (title/tag only)
        try {
            $docs = DB::all(
                'SELECT id, title, filename, tags FROM documents WHERE status = "ready"'
            );
        } catch (Throwable $e) {
            $docs = [];
        }
        foreach ($docs as $d) {
            $id = (int)$d['id'];
            if (!isset($byDoc[$id])) {
                $byDoc[$id] = [
                    'id' => $id,
                    'title' => (string)($d['title'] ?? ''),
                    'filename' => (string)($d['filename'] ?? ''),
                    'tags' => (string)($d['tags'] ?? ''),
                    'score' => 0.0,
                    'best_chunk' => 0.0,
                    'tag_hit' => false,
                    'ref_hit' => false,
                ];
            }
        }

        foreach ($byDoc as &$doc) {
            $hay = mb_strtolower(
                trim($doc['title'] . ' ' . $doc['filename'] . ' ' . $doc['tags'])
            );
            foreach ($terms as $t) {
                $t = mb_strtolower($t);
                if ($t === '') continue;
                if (mb_strpos($hay, $t) !== false) {
                    if (mb_stripos($doc['title'], $t) !== false || mb_stripos($doc['filename'], $t) !== false) {
                        $doc['score'] += 5.0;
                    }
                    if (mb_stripos($doc['tags'], $t) !== false) {
                        $doc['score'] += 6.0;
                    }
                }
            }
            $tagHits = 0;
            foreach ($focusLower as $ft) {
                if ($ft !== '' && mb_strpos(mb_strtolower($doc['tags']), $ft) !== false) {
                    $tagHits++;
                }
            }
            if ($tagHits > 0) {
                $doc['tag_hit'] = true;
                $doc['score'] += 8.0 * $tagHits;
            }
            foreach ($refs as $ref) {
                foreach ($ref['needles'] as $needle) {
                    $n = mb_strtolower((string)$needle);
                    if ($n !== '' && mb_strpos($hay, $n) !== false) {
                        $doc['ref_hit'] = true;
                        $doc['score'] += 15.0;
                        break 2;
                    }
                }
            }
            // Weight in best chunk so strong single excerpts matter
            $doc['score'] += $doc['best_chunk'] * 0.25;
        }
        unset($doc);

        $list = array_values($byDoc);
        usort($list, static fn($a, $b) => $b['score'] <=> $a['score']);
        return $list;
    }

    /**
     * @param list<array{id:int,title:string,tags:string,score:float,tag_hit:bool,ref_hit:bool}> $docScores
     * @param list<string> $focusTags
     * @param list<array> $refs
     * @return array{mode:string,activated_doc_ids:list<int>,tags_agree:bool}
     */
    private function decideAdaptiveMode(
        array $docScores,
        array $focusTags,
        array $refs,
        string $primary
    ): array {
        $hasRefs = $refs !== [];
        $top = $docScores[0] ?? null;
        $second = $docScores[1] ?? null;
        $topScore = $top ? (float)$top['score'] : 0.0;
        $secondScore = $second ? (float)$second['score'] : 0.0;

        $nearTop = 0;
        if ($topScore > 0) {
            foreach ($docScores as $d) {
                if ((float)$d['score'] >= $topScore * 0.8) $nearTop++;
            }
        }

        $tagsAgree = false;
        if ($focusTags !== [] && $top) {
            foreach ($docScores as $i => $d) {
                if ($i >= $this->activateDocsSlim) break;
                if (!empty($d['tag_hit']) && (float)$d['score'] > 0) {
                    $tagsAgree = true;
                    break;
                }
            }
            // Conflict: tags set but none of the high scorers share tags
            $anyHighTag = false;
            foreach ($docScores as $i => $d) {
                if ($i >= 5) break;
                if (!empty($d['tag_hit']) && (float)$d['score'] >= max(1.0, $topScore * 0.5)) {
                    $anyHighTag = true;
                    break;
                }
            }
            if ($focusTags !== [] && !$anyHighTag && $topScore > 0) {
                return [
                    'mode' => 'full',
                    'activated_doc_ids' => [],
                    'tags_agree' => false,
                ];
            }
        }

        $sharp = $topScore > 0 && ($secondScore <= 0.01 || $topScore >= 2.0 * $secondScore);
        $clear = $hasRefs || $sharp || $tagsAgree;

        // Flat / broad: many near-top docs
        if ($nearTop >= 4 && !$hasRefs && !$tagsAgree) {
            $clear = false;
        }
        if ($topScore < 1.0 && !$hasRefs) {
            $clear = false;
        }

        if (!$clear) {
            return [
                'mode' => 'full',
                'activated_doc_ids' => [],
                'tags_agree' => $tagsAgree,
            ];
        }

        $activated = [];
        foreach ($docScores as $i => $d) {
            if (count($activated) >= $this->activateDocsSlim) break;
            if ($i > 0 && $topScore > 0 && (float)$d['score'] < $topScore * 0.35 && empty($d['ref_hit'])) {
                break;
            }
            if ((float)$d['score'] <= 0 && empty($d['ref_hit'])) continue;
            $activated[] = (int)$d['id'];
        }
        // Always include ref-hit docs
        foreach ($docScores as $d) {
            if (!empty($d['ref_hit']) && !in_array((int)$d['id'], $activated, true)) {
                $activated[] = (int)$d['id'];
            }
        }
        $activated = array_values(array_unique($activated));
        if ($activated === []) {
            return [
                'mode' => 'full',
                'activated_doc_ids' => [],
                'tags_agree' => $tagsAgree,
            ];
        }

        $mode = ($tagsAgree && $focusTags !== []) ? 'slim_tagged' : 'slim';
        return [
            'mode' => $mode,
            'activated_doc_ids' => $activated,
            'tags_agree' => $tagsAgree,
        ];
    }

    /**
     * Prefer activated docs; reserve ~25% slots for outsiders / strong leftovers.
     * @param list<array> $hits
     * @param list<int> $activatedIds
     * @param list<string> $focusTags
     * @return list<array>
     */
    private function softFilterActivatedHits(
        array $hits,
        array $activatedIds,
        array $focusTags,
        int $effTopK
    ): array {
        if ($activatedIds === [] || $hits === []) return $hits;
        $actSet = array_fill_keys($activatedIds, true);
        $inside = [];
        $outside = [];
        foreach ($hits as $h) {
            $id = (int)($h['document_id'] ?? 0);
            if (isset($actSet[$id])) $inside[] = $h;
            else $outside[] = $h;
        }
        if ($focusTags !== []) {
            $inside = $this->preferFocusTaggedHits($inside, $focusTags);
        }
        $reserve = max(1, (int)ceil($effTopK * 0.25));
        $mainSlots = max(1, $effTopK - $reserve);
        $picked = array_slice($inside, 0, $mainSlots);
        $fill = array_slice($outside, 0, $reserve);
        // If inside short, fill from outside
        if (count($picked) < $mainSlots) {
            $need = $mainSlots - count($picked);
            $picked = array_merge($picked, array_slice($outside, 0, $need));
            $fill = array_slice($outside, $need, $reserve);
        }
        $out = array_merge($picked, $fill);
        usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    /**
     * @param list<string> $focusTags
     * @param list<array> $refs
     */
    private function shouldRunActRegsQuota(
        string $mode,
        array $focusTags,
        string $primary,
        array $refs
    ): bool {
        if ($mode === 'full' || $mode === 'legacy') return true;
        if ($refs !== []) return true;
        $hay = mb_strtolower($primary . ' ' . implode(' ', $focusTags));
        if (preg_match('/\b(act|regulation|regulations|section|s\.|reg\.|part|division)\b/', $hay)) {
            return true;
        }
        foreach ($focusTags as $t) {
            $tl = mb_strtolower($t);
            if ($tl === 'act' || $tl === 'regulations' || $tl === 'regulation' || $tl === 'regs') {
                return true;
            }
        }
        // Slim guidance-only: skip forced Act/Regs
        return false;
    }

    /**
     * Pull representative chunks from documents carrying any of the focus tags.
     * @param list<string> $focusTags
     * @return list<array>
     */
    private function focusTagDocumentHits(array $focusTags): array {
        if ($focusTags === []) return [];
        try {
            $docs = DB::all(
                'SELECT id, title, filename, tags, source_url, ingest_mode, deepseek_file_id
                 FROM documents WHERE status = "ready" AND tags IS NOT NULL AND tags != ""'
            );
        } catch (Throwable $e) {
            return [];
        }
        $focusLower = array_map(static fn($t) => mb_strtolower($t), $focusTags);
        $out = [];
        foreach ($docs as $doc) {
            $docTags = array_map(static fn($t) => mb_strtolower($t), normalize_document_tags($doc['tags'] ?? ''));
            $overlap = array_intersect($focusLower, $docTags);
            if ($overlap === []) continue;
            try {
                $rows = DB::all(
                    'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                            d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id
                     FROM chunks c
                     INNER JOIN documents d ON d.id = c.document_id
                     WHERE c.document_id = ? AND d.status = "ready"
                     ORDER BY c.chunk_index ASC
                     LIMIT 3',
                    [(int)$doc['id']]
                );
            } catch (Throwable $e) {
                continue;
            }
            $boost = 6.0 + (count($overlap) * 3.0);
            foreach ($rows as $i => $r) {
                $r['score'] = $boost - ($i * 0.5);
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @param array<int,array> $merged */
    private function boostByFocusTags(array &$merged, array $focusTags): void {
        if ($focusTags === [] || $merged === []) return;
        $focusLower = array_map(static fn($t) => mb_strtolower($t), $focusTags);
        foreach ($merged as &$row) {
            $docTags = mb_strtolower((string)($row['tags'] ?? ''));
            if ($docTags === '') continue;
            $hit = 0;
            foreach ($focusLower as $t) {
                if ($t !== '' && mb_strpos($docTags, $t) !== false) $hit++;
            }
            if ($hit > 0) {
                $row['score'] = ((float)$row['score'] * (1.0 + 0.5 * $hit)) + (10 * $hit);
            }
        }
        unset($row);
    }

    /**
     * Re-order so focus-tagged hits lead; keep untagged as filler.
     * @param list<array> $hits
     * @param list<string> $focusTags
     * @return list<array>
     */
    private function preferFocusTaggedHits(array $hits, array $focusTags): array {
        $focusLower = array_map(static fn($t) => mb_strtolower($t), $focusTags);
        $tagged = [];
        $other = [];
        foreach ($hits as $h) {
            $docTags = mb_strtolower((string)($h['tags'] ?? ''));
            $ok = false;
            foreach ($focusLower as $t) {
                if ($t !== '' && mb_strpos($docTags, $t) !== false) {
                    $ok = true;
                    break;
                }
            }
            if ($ok) $tagged[] = $h;
            else $other[] = $h;
        }
        return array_merge($tagged, $other);
    }

    /**
     * Detect legal references: section 28, s.28, reg 12, part 3, division 2, etc.
     * @return list<array{type:string,num:string,needles:list<string>}>
     */
    public static function extractLegalRefs(string $query): array {
        $refs = [];
        $add = static function (string $type, string $num) use (&$refs): void {
            $num = strtoupper(trim($num));
            if ($num === '') return;
            $key = $type . ':' . $num;
            foreach ($refs as $r) {
                if (($r['type'] . ':' . $r['num']) === $key) return;
            }
            if ($type === 'section') {
                $needles = [
                    'section ' . $num,
                    's.' . $num,
                    's ' . $num,
                    'sec ' . $num,
                    'sec. ' . $num,
                ];
            } elseif ($type === 'regulation') {
                $needles = [
                    'regulation ' . $num,
                    'regulations ' . $num,
                    'reg ' . $num,
                    'reg. ' . $num,
                    'r.' . $num,
                    'r ' . $num,
                ];
            } elseif ($type === 'part') {
                $needles = [
                    'part ' . $num,
                    'pt ' . $num,
                    'pt. ' . $num,
                ];
            } elseif ($type === 'division') {
                $needles = [
                    'division ' . $num,
                    'div ' . $num,
                    'div. ' . $num,
                ];
            } elseif ($type === 'schedule') {
                $needles = [
                    'schedule ' . $num,
                    'sch ' . $num,
                    'sch. ' . $num,
                ];
            } else {
                $needles = [$type . ' ' . $num];
            }
            $refs[] = ['type' => $type, 'num' => $num, 'needles' => $needles];
        };

        if (preg_match_all('/\b(?:sections?|secs?)\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('section', (string)$num);
        }
        if (preg_match_all('/\bs\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('section', (string)$num);
        }
        if (preg_match_all('/\b(?:regulations?|regs?)\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('regulation', (string)$num);
        }
        if (preg_match_all('/\br\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('regulation', (string)$num);
        }
        if (preg_match_all('/\b(?:parts?|pts?)\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('part', (string)$num);
        }
        if (preg_match_all('/\b(?:divisions?|divs?)\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('division', (string)$num);
        }
        if (preg_match_all('/\b(?:schedules?|schs?)\.?\s*(\d+[A-Za-z]?)\b/iu', $query, $m)) {
            foreach ($m[1] as $num) $add('schedule', (string)$num);
        }
        return $refs;
    }

    /**
     * @param list<array{type:string,num:string,needles:list<string>}> $refs
     * @return list<array>
     */
    private function sectionRefSearch(array $refs, ?int $documentId): array {
        if ($refs === []) return [];
        $out = [];
        foreach ($refs as $ref) {
            foreach ($ref['needles'] as $needle) {
                $needle = trim($needle);
                if (mb_strlen($needle) < 2) continue;
                $pat = '%' . $this->escapeLike(mb_strtolower($needle)) . '%';
                $sql = 'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                               d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id
                        FROM chunks c
                        INNER JOIN documents d ON d.id = c.document_id
                        WHERE d.status = "ready"
                          AND (
                            LOWER(c.header_path) LIKE ? OR LOWER(c.search_text) LIKE ? OR LOWER(c.text) LIKE ?
                          )';
                $args = [$pat, $pat, $pat];
                if ($documentId) {
                    $sql .= ' AND c.document_id = ?';
                    $args[] = $documentId;
                }
                $sql .= ' LIMIT 30';
                try {
                    $rows = DB::all($sql, $args);
                } catch (Throwable $e) {
                    continue;
                }
                foreach ($rows as $r) {
                    $header = mb_strtolower((string)($r['header_path'] ?? ''));
                    $score = 8.0;
                    if ($header !== '' && mb_strpos($header, mb_strtolower($needle)) !== false) {
                        $score = 18.0;
                    }
                    // Prefer exact section heading patterns
                    $num = preg_quote($ref['num'], '/');
                    if ($ref['type'] === 'section' && preg_match('/\bsection\s+' . $num . '\b/i', (string)($r['header_path'] ?? ''))) {
                        $score = 24.0;
                    }
                    if ($ref['type'] === 'regulation' && preg_match('/\bregulations?\s+' . $num . '\b/i', (string)($r['header_path'] ?? '') . ' ' . (string)($r['text'] ?? ''))) {
                        $score = 22.0;
                    }
                    $r['score'] = $score;
                    $out[] = $r;
                }
            }
        }
        return $out;
    }

    /** Classify document as act | regulations | guidance | other. */
    public static function documentKind(?string $title, ?string $filename, ?string $tags = null): string {
        $hay = mb_strtolower(trim(($title ?? '') . ' ' . ($filename ?? '') . ' ' . ($tags ?? '')));
        if (preg_match('/\bregulations?\b|\bwcim[_\s-]*reg|\bregs\b/', $hay)) {
            return 'regulations';
        }
        if (preg_match('/\bwcim[_\s-]*act\b|\bact\s*2023\b|\binjury management act\b|\bworkers?\s+compensation and injury management act\b/', $hay)
            || (preg_match('/\bact\b/', $hay) && !preg_match('/fact|guide|policy|notice|employer essentials/', $hay))) {
            return 'act';
        }
        if (preg_match('/fact[_\s-]*sheet|guide|guideline|policy|employer essentials|notice|return.to.work/', $hay)) {
            return 'guidance';
        }
        return 'other';
    }

    /**
     * If both Act and Regulations exist in the corpus, ensure both appear in the hit set when relevant.
     * @param list<array> $picked
     * @param list<array> $pool sorted candidates
     * @return list<array>
     */
    private function ensureActRegsQuota(array $picked, array $pool, int $limit): array {
        $kindsPresent = $this->readyDocKinds();
        if (!isset($kindsPresent['act']) || !isset($kindsPresent['regulations'])) {
            return $picked;
        }

        $has = ['act' => false, 'regulations' => false];
        foreach ($picked as $h) {
            $k = self::documentKind($h['title'] ?? null, $h['filename'] ?? null, $h['tags'] ?? null);
            if ($k === 'act' || $k === 'regulations') $has[$k] = true;
        }

        foreach (['act', 'regulations'] as $need) {
            if ($has[$need]) continue;
            $candidate = null;
            foreach ($pool as $h) {
                $k = self::documentKind($h['title'] ?? null, $h['filename'] ?? null, $h['tags'] ?? null);
                if ($k !== $need) continue;
                // Prefer pool hits that already scored; skip duplicates
                $id = (int)$h['id'];
                $already = false;
                foreach ($picked as $p) {
                    if ((int)$p['id'] === $id) { $already = true; break; }
                }
                if ($already) continue;
                $candidate = $h;
                break;
            }
            if ($candidate === null) {
                // Fall back: best keyword-ish chunk from that kind of document
                $candidate = $this->bestChunkForKind($need);
            }
            if ($candidate === null) continue;

            if (count($picked) < $limit) {
                $picked[] = $this->normalizeRow($candidate);
            } else {
                // Replace lowest-scoring guidance/other hit if possible
                $replaceIdx = null;
                $lowest = PHP_FLOAT_MAX;
                for ($i = count($picked) - 1; $i >= 0; $i--) {
                    $k = self::documentKind($picked[$i]['title'] ?? null, $picked[$i]['filename'] ?? null, $picked[$i]['tags'] ?? null);
                    if ($k === 'act' || $k === 'regulations') continue;
                    $sc = (float)$picked[$i]['score'];
                    if ($sc <= $lowest) {
                        $lowest = $sc;
                        $replaceIdx = $i;
                    }
                }
                if ($replaceIdx === null) {
                    // Replace lowest overall that isn't the opposite required kind we're keeping
                    for ($i = count($picked) - 1; $i >= 0; $i--) {
                        $k = self::documentKind($picked[$i]['title'] ?? null, $picked[$i]['filename'] ?? null, $picked[$i]['tags'] ?? null);
                        if ($k === $need) continue;
                        $sc = (float)$picked[$i]['score'];
                        if ($sc <= $lowest) {
                            $lowest = $sc;
                            $replaceIdx = $i;
                        }
                    }
                }
                if ($replaceIdx !== null) {
                    $picked[$replaceIdx] = $this->normalizeRow($candidate);
                }
            }
            $has[$need] = true;
        }

        usort($picked, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_values($picked);
    }

    /** @return array<string,true> */
    private function readyDocKinds(): array {
        try {
            $docs = DB::all('SELECT title, filename, tags FROM documents WHERE status = "ready"');
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($docs as $d) {
            $k = self::documentKind($d['title'] ?? null, $d['filename'] ?? null, $d['tags'] ?? null);
            $out[$k] = true;
        }
        return $out;
    }

    private function bestChunkForKind(string $kind): ?array {
        try {
            $docs = DB::all('SELECT id, title, filename, tags FROM documents WHERE status = "ready"');
        } catch (Throwable $e) {
            return null;
        }
        foreach ($docs as $d) {
            if (self::documentKind($d['title'] ?? null, $d['filename'] ?? null, $d['tags'] ?? null) !== $kind) {
                continue;
            }
            try {
                $row = DB::one(
                    'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                            d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id, 3.0 AS score
                     FROM chunks c
                     INNER JOIN documents d ON d.id = c.document_id
                     WHERE c.document_id = ? AND d.status = "ready"
                     ORDER BY c.chunk_index ASC LIMIT 1',
                    [(int)$d['id']]
                );
            } catch (Throwable $e) {
                continue;
            }
            if ($row) return $row;
        }
        return null;
    }

    /**
     * Add neighbouring chunks (Â±1 index) for continuity around strong hits.
     * @param list<array> $picked
     * @return list<array>
     */
    private function expandNeighborChunks(array $picked, int $limit): array {
        if ($picked === [] || count($picked) >= $limit) {
            return $picked;
        }
        $have = [];
        foreach ($picked as $h) {
            $have[(int)$h['id']] = true;
        }
        $extras = [];
        foreach ($picked as $h) {
            $docId = (int)$h['document_id'];
            $idx = (int)$h['chunk_index'];
            foreach ([$idx - 1, $idx + 1] as $nIdx) {
                if ($nIdx < 0) continue;
                try {
                    $row = DB::one(
                        'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                                d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id
                         FROM chunks c
                         INNER JOIN documents d ON d.id = c.document_id
                         WHERE c.document_id = ? AND c.chunk_index = ? AND d.status = "ready"
                         LIMIT 1',
                        [$docId, $nIdx]
                    );
                } catch (Throwable $e) {
                    continue;
                }
                if (!$row) continue;
                $id = (int)$row['id'];
                if (isset($have[$id])) continue;
                $have[$id] = true;
                $row['score'] = max(0.5, ((float)$h['score']) * 0.72);
                $extras[] = $this->normalizeRow($row);
                if (count($picked) + count($extras) >= $limit) break 2;
            }
        }
        if ($extras === []) return $picked;
        $out = array_merge($picked, $extras);
        usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Ensure the final set is not dominated by a single document.
     * @param list<array> $hits sorted by score desc
     * @return list<array>
     */
    private function diversifyHits(array $hits, int $limit, int $maxPerDoc): array {
        if ($hits === []) return [];
        $picked = [];
        $perDoc = [];
        $deferred = [];

        foreach ($hits as $h) {
            $docId = (int)$h['document_id'];
            $count = $perDoc[$docId] ?? 0;
            if ($count < $maxPerDoc) {
                $picked[] = $h;
                $perDoc[$docId] = $count + 1;
                if (count($picked) >= $limit) {
                    return $picked;
                }
            } else {
                $deferred[] = $h;
            }
        }
        // Fill remaining slots with leftover high scores
        foreach ($deferred as $h) {
            if (count($picked) >= $limit) break;
            $picked[] = $h;
        }
        return $picked;
    }

    /**
     * For each ready document whose title/filename/tags match query terms,
     * include its best matching chunk so secondary docs (fact sheets, regs) are not missed.
     * @return list<array>
     */
    private function crossDocumentBoost(string $query): array {
        $terms = $this->terms($query);
        if ($terms === []) $terms = $this->looseTerms($query);
        if ($terms === []) return [];

        // Cap terms used in SQL
        $terms = array_slice($terms, 0, 10);
        try {
            $docs = DB::all(
                'SELECT id, title, filename, tags, department, ingest_mode, deepseek_file_id
                 FROM documents WHERE status = "ready" ORDER BY id'
            );
        } catch (Throwable $e) {
            return [];
        }
        if ($docs === []) return [];

        $out = [];
        foreach ($docs as $doc) {
            $hay = mb_strtolower(trim(
                (string)($doc['title'] ?? '') . ' ' .
                (string)($doc['filename'] ?? '') . ' ' .
                (string)($doc['tags'] ?? '') . ' ' .
                (string)($doc['department'] ?? '')
            ));
            $matched = 0;
            foreach ($terms as $t) {
                $t = mb_strtolower($t);
                if (mb_strlen($t) < 3) continue;
                if (mb_strpos($hay, $t) !== false) $matched++;
            }
            // Also search inside this document's chunks for query terms
            $likes = [];
            $args = [(int)$doc['id']];
            foreach ($terms as $t) {
                if (mb_strlen($t) < 3) continue;
                $likes[] = '(c.search_text LIKE ? OR c.text LIKE ? OR c.header_path LIKE ?)';
                $pat = '%' . $this->escapeLike(mb_strtolower($t)) . '%';
                array_push($args, $pat, $pat, $pat);
            }
            if ($likes === []) continue;

            try {
                $sql = 'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path, c.search_text,
                               d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id
                        FROM chunks c
                        INNER JOIN documents d ON d.id = c.document_id
                        WHERE c.document_id = ? AND d.status = "ready"
                          AND (' . implode(' OR ', $likes) . ')
                        LIMIT 40';
                $rows = DB::all($sql, $args);
            } catch (Throwable $e) {
                continue;
            }
            if ($rows === []) {
                // Title-only match: take first chunk so the doc is still represented
                if ($matched === 0) continue;
                try {
                    $stub = DB::one(
                        'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                                d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id, 2.5 AS score
                         FROM chunks c
                         INNER JOIN documents d ON d.id = c.document_id
                         WHERE c.document_id = ? AND d.status = "ready"
                         ORDER BY c.chunk_index ASC LIMIT 1',
                        [(int)$doc['id']]
                    );
                } catch (Throwable $e) {
                    continue;
                }
                if ($stub) $out[] = $stub;
                continue;
            }

            $best = null;
            $bestScore = -1.0;
            foreach ($rows as $r) {
                $chunkHay = mb_strtolower(
                    (string)($r['title'] ?? '') . ' ' .
                    (string)($r['header_path'] ?? '') . ' ' .
                    (string)($r['search_text'] ?? '') . ' ' .
                    mb_substr((string)($r['text'] ?? ''), 0, 4000)
                );
                $score = 0.0;
                foreach ($terms as $t) {
                    $t = mb_strtolower($t);
                    if ($t === '') continue;
                    $score += mb_substr_count($chunkHay, $t);
                    if (mb_stripos((string)($r['header_path'] ?? ''), $t) !== false) $score += 3;
                }
                $score += $matched * 2;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    unset($r['search_text']);
                    $r['score'] = max(1.0, $score);
                    $best = $r;
                }
            }
            if ($best !== null) $out[] = $best;
        }
        return $out;
    }

    /** @param array<int,array> $merged */
    private function mergeHit(array &$merged, array $row, float $weight): void {
        $id = (int)$row['id'];
        $score = (float)($row['score'] ?? 0) * $weight;
        if (isset($merged[$id])) {
            $merged[$id]['score'] = max($merged[$id]['score'], $score) + ($score * 0.15);
            return;
        }
        $row['score'] = $score;
        $merged[$id] = $this->normalizeRow($row);
    }

    /** @return list<array> */
    private function naturalLanguageSearch(string $query, ?int $documentId): array {
        try {
            $sql = 'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                           d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id,
                           MATCH(c.search_text) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
                    FROM chunks c
                    INNER JOIN documents d ON d.id = c.document_id
                    WHERE d.status = "ready"
                      AND MATCH(c.search_text) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $args = [$query, $query];
            if ($documentId) {
                $sql .= ' AND c.document_id = ?';
                $args[] = $documentId;
            }
            $sql .= ' ORDER BY score DESC LIMIT ' . (int)($this->topK * 6);
            return DB::all($sql, $args);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array> */
    private function booleanOrSearch(string $query, ?int $documentId): array {
        $boolean = $this->toBooleanOrQuery($query);
        if ($boolean === '') return [];
        try {
            $sql = 'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                           d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id,
                           MATCH(c.search_text) AGAINST (? IN BOOLEAN MODE) AS score
                    FROM chunks c
                    INNER JOIN documents d ON d.id = c.document_id
                    WHERE d.status = "ready"
                      AND MATCH(c.search_text) AGAINST (? IN BOOLEAN MODE)';
            $args = [$boolean, $boolean];
            if ($documentId) {
                $sql .= ' AND c.document_id = ?';
                $args[] = $documentId;
            }
            $sql .= ' ORDER BY score DESC LIMIT ' . (int)($this->topK * 6);
            return DB::all($sql, $args);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Primary reliable path for small corpora / shared hosting FULLTEXT quirks.
     * Scores against both search_text and raw text.
     * @param list<string> $terms
     * @return list<array>
     */
    /**
     * @param list<string> $terms
     * @param list<array{type:string,num:string,needles:list<string>}> $refs
     */
    private function keywordSearch(string $query, array $terms, ?int $documentId, array $refs = []): array {
        if ($terms === []) {
            $terms = $this->looseTerms($query);
        }
        if ($terms === []) return [];

        $sql = 'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path, c.search_text,
                       d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id
                FROM chunks c
                INNER JOIN documents d ON d.id = c.document_id
                WHERE d.status = "ready"';
        $args = [];
        if ($documentId) {
            $sql .= ' AND c.document_id = ?';
            $args[] = $documentId;
        }

        $likes = [];
        foreach ($terms as $t) {
            $likes[] = '(c.search_text LIKE ? OR c.text LIKE ? OR d.title LIKE ? OR d.tags LIKE ? OR c.header_path LIKE ?)';
            $pat = '%' . $this->escapeLike($t) . '%';
            array_push($args, $pat, $pat, $pat, $pat, $pat);
        }
        $sql .= ' AND (' . implode(' OR ', $likes) . ')';
        $sql .= ' LIMIT 500';

        try {
            $rows = DB::all($sql, $args);
        } catch (Throwable $e) {
            return [];
        }

        $qLower = mb_strtolower($query);
        $scored = [];
        foreach ($rows as $r) {
            $hay = mb_strtolower(
                trim(
                    (string)($r['title'] ?? '') . ' ' .
                    (string)($r['tags'] ?? '') . ' ' .
                    (string)($r['header_path'] ?? '') . ' ' .
                    (string)($r['search_text'] ?? '') . ' ' .
                    (string)($r['text'] ?? '')
                )
            );
            $score = 0.0;
            $matched = 0;
            foreach ($terms as $t) {
                $t = mb_strtolower($t);
                $count = mb_substr_count($hay, $t);
                if ($count > 0) {
                    $matched++;
                    $lenBoost = 1.0 + min(3.0, mb_strlen($t) / 4);
                    $score += $count * $lenBoost;
                    // title / tags / header hits matter more
                    if (mb_stripos((string)($r['title'] ?? ''), $t) !== false) $score += 4;
                    if (mb_stripos((string)($r['tags'] ?? ''), $t) !== false) $score += 6;
                    if (mb_stripos((string)($r['header_path'] ?? ''), $t) !== false) $score += 3;
                }
            }
            // Legal ref bonus when header/path contains the cited section/reg
            foreach ($refs as $ref) {
                foreach ($ref['needles'] as $needle) {
                    $n = mb_strtolower($needle);
                    if ($n !== '' && mb_strpos($hay, $n) !== false) {
                        $score += 14;
                        if (mb_stripos((string)($r['header_path'] ?? ''), $n) !== false) {
                            $score += 12;
                        }
                        break;
                    }
                }
            }
            // phrase / near-phrase bonus
            $compactQ = preg_replace('/\s+/u', ' ', $qLower) ?? $qLower;
            if (mb_strlen($compactQ) >= 6 && mb_strpos($hay, $compactQ) !== false) {
                $score += 14;
            }
            // coverage: prefer chunks matching more query terms
            if (count($terms) > 0) {
                $score += ($matched / count($terms)) * 10;
            }
            // Boost Act/Regs so they aren't crowded out by long guidance docs
            $kind = self::documentKind($r['title'] ?? null, $r['filename'] ?? null, $r['tags'] ?? null);
            if ($kind === 'act' || $kind === 'regulations') {
                $score *= 1.25;
            } elseif ($kind === 'guide' || $kind === 'factsheet') {
                $score *= 0.92;
            }
            if ($score <= 0) continue;
            unset($r['search_text']);
            $r['score'] = $score;
            $scored[] = $r;
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        // Keep a wider pool; diversifyHits trims the final set
        return array_slice($scored, 0, $this->topK * 8);
    }

    /** @return list<array> */
    private function broadFallback(?int $documentId): array {
        try {
            $countRow = DB::one(
                'SELECT COUNT(*) AS n FROM chunks c
                 INNER JOIN documents d ON d.id = c.document_id
                 WHERE d.status = "ready"' . ($documentId ? ' AND c.document_id = ?' : ''),
                $documentId ? [$documentId] : []
            );
            $n = (int)($countRow['n'] ?? 0);
            if ($n === 0 || $n > 400) return [];

            $sql = 'SELECT c.id, c.document_id, c.chunk_index, c.text, c.header_path,
                           d.title, d.filename, d.tags, d.source_url, d.ingest_mode, d.deepseek_file_id, 0.2 AS score
                    FROM chunks c
                    INNER JOIN documents d ON d.id = c.document_id
                    WHERE d.status = "ready"';
            $args = [];
            if ($documentId) {
                $sql .= ' AND c.document_id = ?';
                $args[] = $documentId;
            }
            $sql .= ' ORDER BY c.document_id, c.chunk_index LIMIT ' . (int)($this->topK * 2);
            return DB::all($sql, $args);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function normalizeRow(array $r): array {
        return [
            'id'               => (int)$r['id'],
            'document_id'      => (int)$r['document_id'],
            'chunk_index'      => (int)$r['chunk_index'],
            'text'             => (string)$r['text'],
            'header_path'      => $r['header_path'] ?? null,
            'title'            => $r['title'] ?? null,
            'filename'         => $r['filename'] ?? null,
            'tags'             => $r['tags'] ?? null,
            'source_url'       => !empty($r['source_url']) ? (string)$r['source_url'] : null,
            'ingest_mode'      => (string)($r['ingest_mode'] ?? 'local'),
            'deepseek_file_id' => !empty($r['deepseek_file_id']) ? (string)$r['deepseek_file_id'] : null,
            'doc_kind'         => self::documentKind(
                isset($r['title']) ? (string)$r['title'] : null,
                isset($r['filename']) ? (string)$r['filename'] : null,
                isset($r['tags']) ? (string)$r['tags'] : null
            ),
            'score'            => (float)($r['score'] ?? 0),
        ];
    }

    /** Soft OR boolean â€” any term can match (no +required). */
    private function toBooleanOrQuery(string $query): string {
        $terms = $this->terms($query);
        if ($terms === []) $terms = $this->looseTerms($query);
        $parts = [];
        foreach ($terms as $t) {
            $safe = preg_replace('/[^a-z0-9_-]+/i', '', $t) ?? '';
            if ($safe === '' || mb_strlen($safe) < 3) continue;
            $parts[] = $safe . '*';
        }
        return implode(' ', array_slice($parts, 0, 12));
    }

    /** @return list<string> */
    private function terms(string $query): array {
        $q = mb_strtolower($query);
        $q = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $q) ?? $q;
        $parts = preg_split('/\s+/u', trim($q)) ?: [];
        $stop = [
            'the','a','an','and','or','to','of','in','on','for','is','are','was','were','be','been','being',
            'this','that','it','with','as','at','by','from','into','about','than','then','so','if',
            'what','whats','how','why','when','where','who','which','can','could','would','should',
            'do','does','did','you','your','me','my','we','our','i','im','please','tell','explain',
            'give','show','find','need','want','know','there','their','they','them','any','some',
            'also','just','only','very','really','something','anything','everything',
        ];
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || in_array($p, $stop, true)) continue;
            if (mb_strlen($p) < 2) continue;
            $out[] = $p;
            // Light stemming so obligation/obligations, worker/workers still match
            if (mb_strlen($p) >= 5 && str_ends_with($p, 's') && !str_ends_with($p, 'ss')) {
                $stem = mb_substr($p, 0, -1);
                if (!in_array($stem, $stop, true)) $out[] = $stem;
            }
            if (mb_strlen($p) >= 6 && str_ends_with($p, 'ing')) {
                $stem = mb_substr($p, 0, -3);
                if (mb_strlen($stem) >= 3) $out[] = $stem;
            }
        }
        // keep bigrams of remaining content words for phrase-ish matching
        $words = array_values(array_unique(array_filter($out, static fn($w) => !str_contains($w, ' '))));
        for ($i = 0; $i < count($words) - 1; $i++) {
            $bi = $words[$i] . ' ' . $words[$i + 1];
            if (mb_strlen($bi) >= 5) $out[] = $bi;
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function looseTerms(string $query): array {
        $q = mb_strtolower($query);
        $q = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $q) ?? $q;
        $parts = preg_split('/\s+/u', trim($q)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) >= 3) $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private function escapeLike(string $s): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /**
     * @param list<array> $hits
     * @return array{context:string,citations:list<array>,sources:list<array>}
     */
    public static function buildContext(array $hits): array {
        if ($hits === []) {
            return ['context' => '', 'citations' => [], 'sources' => []];
        }
        $citations = [];
        $sources = [];
        $seenDocs = [];
        $chunkCounts = [];
        foreach ($hits as $h) {
            $docId = (int)$h['document_id'];
            $chunkCounts[$docId] = ($chunkCounts[$docId] ?? 0) + 1;
        }
        $blocks = [];
        $n = 1;
        $sourceN = 1;
        foreach ($hits as $h) {
            $label = trim((string)($h['title'] ?: $h['filename'] ?: 'Document'));
            $path = trim((string)($h['header_path'] ?? ''));
            $tags = trim((string)($h['tags'] ?? ''));
            $cite = $path !== '' ? "{$label} — {$path}" : $label;
            if ($tags !== '') $cite .= " [tags: {$tags}]";
            $docId = (int)$h['document_id'];
            $sourceUrl = !empty($h['source_url']) ? (string)$h['source_url'] : null;
            $docChunkCount = (int)($chunkCounts[$docId] ?? 1);
            $citations[] = [
                'n'            => $n,
                'chunk_id'     => (int)$h['id'],
                'document_id'  => $docId,
                'title'        => $label,
                'filename'     => $h['filename'] ?? null,
                'tags'         => $tags !== '' ? $tags : null,
                'source_url'   => $sourceUrl,
                'header_path'  => $path !== '' ? $path : null,
                'score'        => (float)$h['score'],
                'chunk_count'  => $docChunkCount,
            ];
            if (!isset($seenDocs[$docId])) {
                $seenDocs[$docId] = true;
                $sources[] = [
                    'n'                => $sourceN,
                    'document_id'      => $docId,
                    'title'            => $label,
                    'filename'         => $h['filename'] ?? null,
                    'tags'             => $tags !== '' ? $tags : null,
                    'source_url'       => $sourceUrl,
                    'deepseek_file_id' => $h['deepseek_file_id'] ?? null,
                    'ingest_mode'      => $h['ingest_mode'] ?? 'local',
                    'chunk_count'      => $docChunkCount,
                ];
                $sourceN++;
            }
            // DeepSeek-parsed docs are attached as file_id; stub text is not authoritative content
            if (($h['ingest_mode'] ?? '') === 'deepseek' && !empty($h['deepseek_file_id'])) {
                $blocks[] = "[{$n}] {$cite}\n"
                    . "(Full document attached via DeepSeek Files API â€” use the attached file contents for this source.)";
            } else {
                $blocks[] = "[{$n}] {$cite}\n" . trim((string)$h['text']);
            }
            $n++;
        }
        $context = "DOCUMENT EXCERPTS (authoritative for this answer):\n"
            . "Grounding rules (mandatory):\n"
            . "- Answer ONLY from these excerpts and any attached DeepSeek-parsed files. "
            . "Do not use outside legal knowledge, guesses, or general Australian/WA law that is not in the excerpts.\n"
            . "- If the excerpts only partly cover the question, answer the covered part and clearly say what is missing.\n"
            . "- If nothing relevant is in the excerpts or attached files, say you cannot find it in the uploaded documents. "
            . "Do not invent sections, time limits, dollar amounts, or duties.\n"
            . "- Write plain text only (no Markdown). Do not include citation numbers such as [1], [2], or [n].\n"
            . "Hierarchy: the Act and Regulations are the legal source; fact sheets and guides are practical guidance only — "
            . "if both appear, state the legal rule from Act/Regs first and you may note the guide as how WorkCover explains it.\n"
            . "If both Act and Regulations excerpts are present and both apply, cover both. "
            . "Name section, regulation, part, or division numbers in plain words when the excerpts support them. "
            . "Prefer quoting or closely paraphrasing the relevant rule/text. "
            . "Review all excerpts for related sections, definitions, exceptions, procedures, or Act↔Regulations cross-references.\n\n"
            . implode("\n\n---\n\n", $blocks);
        return ['context' => $context, 'citations' => $citations, 'sources' => $sources];
    }

    /**
     * Unique DeepSeek file_ids from retrieval hits (max 20 per API).
     * @param list<array> $hits
     * @return list<array{file_id:string,document_id:int,title:string}>
     */
    public static function deepseekFilesFromHits(array $hits, int $max = 20): array {
        $max = max(1, min(20, $max));
        $out = [];
        $seen = [];
        foreach ($hits as $h) {
            $fid = trim((string)($h['deepseek_file_id'] ?? ''));
            if ($fid === '' || isset($seen[$fid])) continue;
            $seen[$fid] = true;
            $out[] = [
                'file_id'     => $fid,
                'document_id' => (int)($h['document_id'] ?? 0),
                'title'       => (string)($h['title'] ?: $h['filename'] ?: 'Document'),
            ];
            if (count($out) >= $max) break;
        }
        return $out;
    }
}
