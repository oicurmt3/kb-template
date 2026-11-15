<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Retrieval.php';
require_once __DIR__ . '/../includes/WebFetch.php';

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
@set_time_limit(0);

function sse(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

try {
    $rl = $config['app']['rate_limit'] ?? [];
    $chatMax = (int)($rl['chat_max'] ?? 60);
    $chatWindow = (int)($rl['chat_window'] ?? 60);
    if (!rate_limit_check('chat', $chatMax, $chatWindow)) {
        throw new RuntimeException('Rate limit exceeded. Please wait a moment.');
    }

    $body = read_json_body();
    if (!isset($body['message']) || !is_string($body['message']) || trim($body['message']) === '') {
        throw new RuntimeException('Missing message');
    }
    $message = trim($body['message']);
    if (mb_strlen($message) > 8000) {
        throw new RuntimeException('Message too long');
    }
    $mode = strtolower(trim((string)($body['mode'] ?? 'chat')));
    if ($mode !== 'rag') $mode = 'chat';

    $answerLength = AppSettings::answerLength();

    $webCfg = AppSettings::mergeWebConfig($config['web'] ?? []);
    $webSearch = AppSettings::uiFeatureActive('web_search', !empty($body['web_search']));
    $deepThinking = AppSettings::uiFeatureActive('deep_thinking', !empty($body['deep_thinking']));
    // HQ on → high-quality answers; HQ off (default) → Basic / plain-language answers
    $hqMode = AppSettings::uiFeatureActive(
        'hq',
        !empty($body['hq_mode']) || !empty($body['hq'])
    );
    $simpleExplain = !$hqMode;
    $focusTags = AppSettings::uiFeatureActive('tags', true)
        ? normalize_document_tags($body['tags'] ?? ($body['focus_tags'] ?? []))
        : [];
    $extraContext = AppSettings::uiFeatureActive('file_context', true)
        && isset($body['extra_context']) && is_string($body['extra_context'])
        ? trim($body['extra_context']) : '';
    $contextUrl = AppSettings::uiFeatureActive('url_context', true)
        && isset($body['context_url']) && is_string($body['context_url'])
        ? trim($body['context_url']) : '';
    $contextLabel = isset($body['context_label']) && is_string($body['context_label'])
        ? trim($body['context_label']) : '';
    if ($extraContext === '') {
        $contextLabel = '';
    }

    $wcFeat = AppSettings::uiConfig()['features']['workcover'] ?? ['default' => true, 'visibility' => 'show'];
    $wcVis = (string)($wcFeat['visibility'] ?? 'show');
    if ($wcVis === 'hide') {
        // Hidden: locked to admin default
        $mode = !empty($wcFeat['default']) ? 'rag' : 'chat';
    } elseif ($mode !== 'rag') {
        $mode = 'chat';
    }

    // WorkCover/Docs mode = RAG + allowlisted live web (one combined grounded pipeline)
    if ($mode === 'rag' && !empty($webCfg['enabled'])) {
        $webSearch = true;
    }
    $ragWebMix = AppSettings::ragWebMix();
    $maxCtx = (int)($webCfg['max_context_chars'] ?? 12000);
    if (mb_strlen($extraContext) > $maxCtx) {
        $extraContext = mb_substr($extraContext, 0, $maxCtx) . "\n…";
    }

    $sid     = ensure_session_cookie();
    $convId  = isset($body['conversation_id']) ? (int)$body['conversation_id'] : 0;

    if ($convId) {
        $conv = DB::one('SELECT id FROM conversations WHERE id = ? AND session_id = ?', [$convId, $sid]);
        if (!$conv) throw new RuntimeException('Conversation not found');
    } else {
        $prefix = $mode === 'rag' ? '[WorkCover] ' : '';
        DB::run(
            'INSERT INTO conversations (session_id, title) VALUES (?, ?)',
            [$sid, mb_substr($prefix . $message, 0, 60)]
        );
        $convId = DB::lastId();
        // Enforce admin chat caps (session + global + retention)
        AppSettings::pruneChatsByRetention($config['app'] ?? []);
        AppSettings::enforceSessionChatLimit($sid, $config['app'] ?? []);
        AppSettings::enforceGlobalChatLimit($config['app'] ?? []);
        // Re-check conversation still exists (could have been pruned if caps are extreme)
        $still = DB::one('SELECT id FROM conversations WHERE id = ?', [$convId]);
        if (!$still) {
            DB::run(
                'INSERT INTO conversations (session_id, title) VALUES (?, ?)',
                [$sid, mb_substr($prefix . $message, 0, 60)]
            );
            $convId = DB::lastId();
        }
    }

    // Store the question only — toggles/tags/attachments are applied via system prompt & request flags
    DB::run(
        'INSERT INTO messages (conversation_id, role, content) VALUES (?, "user", ?)',
        [$convId, $message]
    );

    $userMsgCount = DB::one(
        'SELECT COUNT(*) AS n FROM messages WHERE conversation_id = ? AND role = "user"',
        [$convId]
    );
    $enableTitle = ((int)($userMsgCount['n'] ?? 0) === 1);

    $rows = DB::all(
        'SELECT role, content, blocks FROM messages WHERE conversation_id = ? ORDER BY id',
        [$convId]
    );
    $maxMessages = (int)($config['rag']['max_history_messages'] ?? 24);
    if (count($rows) > $maxMessages) {
        $rows = array_slice($rows, -$maxMessages);
    }

    $plainHistory = [];
    foreach ($rows as $r) {
        $role = (string)$r['role'];
        $content = (string)$r['content'];
        if ($role === 'user') {
            $content = strip_user_message_meta($content);
        }
        $plainHistory[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    $systemPrompt = $config['app']['system_prompt'];
    $citations = [];
    $webNotes = [];

    if ($mode === 'rag') {
        $systemPrompt = $config['app']['rag_system_prompt'] ?? (
            "You are WANDA, a helpful workers compensation document assistant in a multi-turn conversation. "
            . "Use the Act/Regulations excerpts with chat history. Answer follow-ups in context. "
            . "Write plain text only — no Markdown. Do not put citation numbers like [1] or [2] in the reply. "
            . "Act and Regulations are authoritative; fact sheets/guides are guidance only. "
            . "If both Act and Regulations apply, cover both and name section/regulation numbers when supported. "
            . "Also consider related sections, definitions, exceptions, and Act↔Regulations cross-references. "
            . "Only refuse when excerpts and history do not cover the question."
        );

        $ragCfg = $config['rag'] ?? [];
        $topK = (int)($ragCfg['top_k'] ?? 14);
        $maxPerDoc = (int)($ragCfg['max_per_document'] ?? 5);
        $adaptiveOn = AppSettings::adaptiveRetrievalEnabled($ragCfg);
        $retrieval = new Retrieval($topK, $maxPerDoc, Retrieval::optsFromRagConfig($ragCfg, $adaptiveOn));

        // Available corpus tags for inference / rewrite
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

        $prep = Retrieval::prepareRetrievalQuery(
            $message,
            $plainHistory,
            $availableTags,
            $config['deepseek'] ?? []
        );
        $expanded = $prep['query'];
        // Auto-apply inferred tags only when the user did not pick any (and tags feature is on)
        if ($focusTags === [] && !empty($prep['inferred_tags'])) {
            $focusTags = normalize_document_tags($prep['inferred_tags']);
            $webNotes[] = 'Auto tags: ' . implode(', ', $focusTags);
        }
        if (!empty($prep['rewritten'])) {
            $webNotes[] = 'Query rewrite: on';
        }

        // Latest message + synonym-expanded rewrite as secondary signal
        $alsoQuery = Retrieval::expandQuerySynonyms($message);
        $searchResult = $retrieval->searchWithMeta($expanded, null, $alsoQuery, $focusTags);
        $hits = $searchResult['hits'];
        $retrievalMeta = $searchResult['meta'];
        $quality = Retrieval::hitQuality($hits, $expanded);
        $retrievalMeta['quality'] = $quality;
        // Docs mode always plans for combined RAG + allowlisted web/URL enrichment
        $allowWebContext = true;
        $built = Retrieval::buildContext($hits, ['allow_web_context' => $allowWebContext]);
        $citations = $built['sources'] !== [] ? $built['sources'] : $built['citations'];
        $dsFiles = Retrieval::deepseekFilesFromHits($hits);
        if ($focusTags !== []) {
            $systemPrompt .= "\n\nUser focus tags for this question: " . implode(', ', $focusTags)
                . ". These are preferences only — still use other excerpts when the question requires them.";
            $webNotes[] = 'Focus tags: ' . implode(', ', $focusTags);
        }
        if ($adaptiveOn && in_array((string)($retrievalMeta['mode'] ?? ''), ['slim', 'slim_tagged'], true)) {
            $actTitles = $retrievalMeta['activated_titles'] ?? [];
            if (is_array($actTitles) && $actTitles !== []) {
                $systemPrompt .= "\n\nRetrieval focused on: " . implode('; ', $actTitles)
                    . ". Prefer these sources; do not invent other instruments.";
            }
            $webNotes[] = 'Adaptive RAG: ' . ($retrievalMeta['mode'] ?? 'slim')
                . ' (top_k=' . (int)($retrievalMeta['top_k'] ?? 0) . ')';
        }
        if ($built['context'] !== '') {
            $systemPrompt .= "\n\n" . $built['context'];
            if (!empty($quality['weak'])) {
                if ($allowWebContext) {
                    $systemPrompt .= "\n\nRetrieval confidence is LOW for this question in the uploaded documents. "
                        . "Prefer excerpts for any WA workers compensation law they clearly support. "
                        . "For other topics, use allowlisted web/URL context when it is provided below. "
                        . "Do not invent legal rules or facts beyond those sources.";
                } else {
                    $systemPrompt .= "\n\nRetrieval confidence is LOW for this question. "
                        . "Only state what the excerpts clearly support. If unsure, say the uploaded documents "
                        . "do not clearly answer this and suggest refining the question or focus tags. "
                        . "Do not invent legal rules.";
                }
                $webNotes[] = 'Retrieval: weak (score=' . round((float)$quality['max_score'], 2) . ')';
            } else {
                $webNotes[] = 'Retrieval: ok (score=' . round((float)$quality['max_score'], 2)
                    . ', hits=' . (int)$quality['hit_count'] . ')';
            }
        } else {
            if ($allowWebContext) {
                $systemPrompt .= "\n\nNo document excerpts matched this turn. "
                    . "Do not invent Act/Regulations content. "
                    . "If allowlisted web/URL context is provided below, you may answer from that text. "
                    . "Otherwise say the uploaded documents do not cover this and suggest different wording or focus tags.";
            } else {
                $systemPrompt .= "\n\nNo document excerpts matched this turn. "
                    . "Do not invent Act/Regulations content. "
                    . "Use chat history only if it already contains the answer; otherwise say nothing relevant "
                    . "was found in the uploaded documents and suggest trying different wording or focus tags.";
            }
            $webNotes[] = 'Retrieval: no hits';
        }
        if ($dsFiles !== []) {
            $names = array_map(static fn($f) => $f['title'], $dsFiles);
            $systemPrompt .= "\n\nDeepSeek-parsed files attached to this turn: " . implode('; ', $names)
                . ". Treat those attached file contents as authoritative for those sources.";
            $webNotes[] = 'DeepSeek files: ' . count($dsFiles);
        }
        // HQ faithfulness parts — packed later with reserved web/extra budget
        $hqDocsGrounding = $built['context'];
        $hqWebGrounding = '';
        $hqExtraGrounding = '';
        $groundingForCheck = $built['context'];
    } else {
        $dsFiles = [];
        $hqDocsGrounding = '';
        $hqWebGrounding = '';
        $hqExtraGrounding = '';
        $groundingForCheck = '';
    }

    $systemPrompt .= "\n\n" . WebFetch::answerLengthInstruction($answerLength);
    if ($hqMode) {
        $systemPrompt .= "\n\n" . AppSettings::hqInstruction();
    } elseif ($simpleExplain) {
        $systemPrompt .= "\n\n" . AppSettings::simpleExplainInstruction();
    }
    $relatedOn = AppSettings::uiFeatureEnabled('related_questions');
    if ($relatedOn) {
        $systemPrompt .= "\n\n" . followups_instruction();
    }

    $wf = new WebFetch($webCfg, $config['deepseek'] ?? []);
    $streamTools = [];
    $messageForModel = $message;

    // Optional URL fetch via DeepSeek Fetch API (+ native read_url only for allowed hosts)
    if ($contextUrl !== '') {
        if (empty($webCfg['enabled'])) {
            $webNotes[] = 'URL fetch skipped (disabled in config).';
        } elseif (!$wf->isAllowedUrl($contextUrl)) {
            $webNotes[] = 'URL rejected (allowed: ' . implode(', ', $wf->allowedHosts()) . ').';
        } elseif (!rate_limit_check('web_fetch', 20, 60)) {
            $webNotes[] = 'URL fetch rate-limited.';
        } else {
            $fetched = $wf->fetchUrl($contextUrl);
            if ($fetched['ok'] && $fetched['text'] !== '') {
                $label = $fetched['title'] ?: $contextUrl;
                $urlBlock = "USER-PROVIDED URL CONTEXT ({$label}):\n" . $fetched['text'];
                $systemPrompt .= "\n\n" . $urlBlock;
                if (!isset($groundingForCheck)) {
                    $groundingForCheck = '';
                }
                if (!isset($hqWebGrounding)) {
                    $hqWebGrounding = '';
                }
                $groundingForCheck .= ($groundingForCheck !== '' ? "\n\n" : '') . $urlBlock;
                $hqWebGrounding .= ($hqWebGrounding !== '' ? "\n\n" : '') . $urlBlock;
                $webNotes[] = 'DeepSeek Fetch: ' . $contextUrl;
                $citations[] = [
                    'n' => count($citations) + 1,
                    'document_id' => 0,
                    'title' => 'URL: ' . ($fetched['title'] ?: 'page'),
                    'filename' => $contextUrl,
                    'source_url' => $contextUrl,
                    'chunk_count' => 1,
                ];
            } else {
                $webNotes[] = 'DeepSeek Fetch failed: ' . ($fetched['error'] ?? 'unknown');
            }
            // Put URL in the user message so deepseek_read_url can see it
            $messageForModel = $message . "\n\n" . $contextUrl;
            $streamTools[] = ['type' => 'deepseek_read_url'];
        }
    }

    // Optional pasted/uploaded extra context
    if ($extraContext !== '') {
        $label = $contextLabel !== '' ? $contextLabel : 'attachment';
        $extraBlock = "USER-PROVIDED FILE/TEXT CONTEXT ({$label}):\n" . $extraContext;
        $systemPrompt .= "\n\n" . $extraBlock;
        if (!isset($hqExtraGrounding)) {
            $hqExtraGrounding = '';
        }
        $hqExtraGrounding .= ($hqExtraGrounding !== '' ? "\n\n" : '') . $extraBlock;
        if (!isset($groundingForCheck)) {
            $groundingForCheck = '';
        }
        $groundingForCheck .= ($groundingForCheck !== '' ? "\n\n" : '') . $extraBlock;
    }

    // Optional live web search — scoped to admin-allowed hosts (no open-web deepseek_web_search tool)
    if ($webSearch) {
        if (empty($webCfg['enabled'])) {
            $webNotes[] = 'Web search skipped (disabled in config).';
        } elseif (!rate_limit_check('web_search', 10, 60)) {
            $webNotes[] = 'Web search rate-limited.';
        } else {
            $searchQuery = $message;
            if ($mode === 'rag') {
                $searchQuery = Retrieval::expandQuerySynonyms(
                Retrieval::conversationalQuery($message, $plainHistory)
            );
            }
            $sr = $wf->search($searchQuery, $message);
            $hostsLabel = implode(', ', $wf->allowedHosts());
            if ($sr['ok'] && $sr['text'] !== '') {
                if ($mode === 'rag') {
                    $webBlock = "LIVE ALLOWLISTED WEB ENRICHMENT (hosts: {$hostsLabel}) — part of Docs/WorkCover mode:\n"
                        . $sr['text'];
                    $systemPrompt .= "\n\n" . $webBlock;
                    $webNotes[] = 'Web+RAG: ' . count($sr['results']) . ' results (' . $hostsLabel . ')'
                        . '; mix=' . ($ragWebMix['mode'] ?? 'complement')
                        . '@' . (int)($ragWebMix['degree'] ?? 50);
                } else {
                    $webBlock = "ALLOWED-DOMAIN WEB SEARCH (restricted to: {$hostsLabel}):\n"
                        . "Use these results as grounded sources for what those pages state. "
                        . "Do not invent or cite other websites.\n"
                        . $sr['text'];
                    $systemPrompt .= "\n\n" . $webBlock;
                    $webNotes[] = 'Web search: ' . count($sr['results']) . ' results (' . $hostsLabel . ')';
                }
                if (!isset($groundingForCheck)) {
                    $groundingForCheck = '';
                }
                if (!isset($hqWebGrounding)) {
                    $hqWebGrounding = '';
                }
                $groundingForCheck .= ($groundingForCheck !== '' ? "\n\n" : '') . $webBlock;
                $hqWebGrounding .= ($hqWebGrounding !== '' ? "\n\n" : '') . $webBlock;
                foreach ($sr['results'] as $i => $hit) {
                    $citations[] = [
                        'n' => count($citations) + 1,
                        'document_id' => 0,
                        'title' => 'Web: ' . ($hit['title'] ?: ('Result ' . ($i + 1))),
                        'filename' => $hit['url'] ?? null,
                        'source_url' => $hit['url'] ?? null,
                        'chunk_count' => 1,
                    ];
                }
            } else {
                $webNotes[] = 'Web search failed: ' . ($sr['error'] ?? 'unknown');
            }
            // Do not enable deepseek_web_search — it is not host-restricted
        }
    }

    if (!isset($dsFiles)) {
        $dsFiles = [];
    }

    // Docs mode: apply admin RAG↔Web mix policy after enrichment blocks are attached
    if ($mode === 'rag' && ($webSearch || $contextUrl !== '')) {
        $systemPrompt .= "\n\n" . AppSettings::ragWebMixInstruction($ragWebMix);
        $webNotes[] = 'RAG↔Web mix: ' . ($ragWebMix['mode'] ?? 'complement')
            . ' @' . (int)($ragWebMix['degree'] ?? 50);
    }

    $reminder = latest_reminder_content($config['app'] ?? []);
    $history = [['role' => 'system', 'content' => $systemPrompt]];
    $lastIdx = count($plainHistory) - 1;
    foreach ($plainHistory as $i => $r) {
        $role = (string)($r['role'] ?? '');
        if ($role === 'user') {
            // https://api-docs.deepseek.com/guides/latest_reminder/
            $history[] = ['role' => 'latest_reminder', 'content' => $reminder];
            $content = ($i === $lastIdx) ? $messageForModel : (string)$r['content'];
            // Attach DeepSeek-parsed files on the latest user turn (chat/completions file blocks)
            // @see https://api-docs.deepseek.com/guides/document_parsing/
            if ($i === $lastIdx && $dsFiles !== []) {
                $parts = [];
                foreach ($dsFiles as $f) {
                    $parts[] = ['type' => 'file', 'file_id' => $f['file_id']];
                }
                $parts[] = ['type' => 'text', 'text' => $content];
                $history[] = ['role' => 'user', 'content' => $parts];
            } else {
                $history[] = ['role' => 'user', 'content' => $content];
            }
        } elseif ($role === 'assistant') {
            $history[] = ['role' => 'assistant', 'content' => (string)$r['content']];
        }
    }

    if (!AppSettings::uiFeatureEnabled('sources')) {
        $citations = [];
    }

    sse('meta', [
        'conversation_id' => $convId,
        'mode' => $mode,
        'answer_length' => $answerLength,
        'deep_thinking' => $deepThinking,
        'hq_mode' => $hqMode,
        'citations' => $citations,
        'web_notes' => $webNotes,
    ]);

    $client = new DeepSeekClient($config['deepseek']);
    $streamOpts = [];
    if ($streamTools !== []) {
        $seen = [];
        $uniqueTools = [];
        foreach ($streamTools as $t) {
            $type = $t['type'] ?? '';
            if ($type === '' || isset($seen[$type])) continue;
            $seen[$type] = true;
            $uniqueTools[] = ['type' => $type];
        }
        $streamOpts['tools'] = $uniqueTools;
    }

    // Deep thinking uses injected host-scoped search results (no open-web tool)
    // @see https://api-docs.deepseek.com/guides/web_tools/
    if ($deepThinking) {
        $streamOpts['thinking'] = ['type' => 'enabled'];
        $webNotes[] = 'Deep thinking enabled';
    }

    // Flash required for Files API / web tools / titles / deep thinking
    $useFlash = $enableTitle
        || $deepThinking
        || $webSearch
        || $contextUrl !== ''
        || $dsFiles !== []
        || !empty($streamOpts['tools']);
    if ($useFlash) {
        $streamOpts['model'] = (string)($config['deepseek']['flash_model'] ?? 'deepseek-flash');
        $webNotes[] = 'Model: ' . $streamOpts['model'];
    }

    if ($enableTitle) {
        $streamOpts['title'] = ['enabled' => true];
    }

    $result = $client->chatStream($history, $streamOpts, function (array $evt) {
        if ($evt['type'] === 'delta') sse('delta', ['content' => $evt['content']]);
        elseif ($evt['type'] === 'error') sse('error', ['message' => $evt['message']]);
        elseif ($evt['type'] === 'title') sse('title', ['title' => $evt['title']]);
    });

    $suggestions = [];
    $finalContent = $result['content'];
    $sourcesOn = AppSettings::uiFeatureEnabled('sources');
    if (!$sourcesOn) {
        $citations = [];
    }
    if ($finalContent !== '') {
        if ($relatedOn) {
            $parsed = extract_followups($finalContent);
            $finalContent = $parsed['content'];
            $suggestions = $parsed['suggestions'];
        } else {
            // Strip follow-up markers if model still emitted them
            $parsed = extract_followups($finalContent);
            $finalContent = $parsed['content'];
            $suggestions = [];
        }

        // HQ faithfulness pass — brake on inventions; skip when DeepSeek Files body isn't in grounding
        if ($hqMode && $finalContent !== '' && !empty($dsFiles)) {
            $webNotes[] = 'HQ check: skipped (DeepSeek Files attached; file body not in grounding window)';
        } elseif (
            $hqMode
            && $finalContent !== ''
            && (
                (isset($hqDocsGrounding) && $hqDocsGrounding !== '')
                || (isset($hqWebGrounding) && $hqWebGrounding !== '')
                || (isset($hqExtraGrounding) && $hqExtraGrounding !== '')
                || (isset($groundingForCheck) && is_string($groundingForCheck) && $groundingForCheck !== '')
            )
        ) {
            $verified = Retrieval::verifyGroundedAnswer(
                $finalContent,
                isset($groundingForCheck) && is_string($groundingForCheck) ? $groundingForCheck : '',
                $config['deepseek'] ?? [],
                $ragWebMix,
                [
                    'docs' => $hqDocsGrounding ?? '',
                    'web' => $hqWebGrounding ?? '',
                    'extra' => $hqExtraGrounding ?? '',
                ]
            );
            if (!empty($verified['rejected']) && $verified['rejected'] === 'shrink') {
                $webNotes[] = 'HQ check: kept draft (correction too short)';
            } elseif (!empty($verified['checked'])) {
                $webNotes[] = !empty($verified['changed'])
                    ? 'HQ check: corrected'
                    : 'HQ check: ok';
                if (!empty($verified['changed']) && trim((string)$verified['content']) !== '') {
                    $finalContent = (string)$verified['content'];
                }
            }
        }

        $blocksPayload = [];
        if ($citations !== []) $blocksPayload['citations'] = $citations;
        if ($suggestions !== []) $blocksPayload['suggestions'] = $suggestions;
        $blocksJson = $blocksPayload !== []
            ? json_encode($blocksPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        DB::run(
            'INSERT INTO messages (conversation_id, role, content, blocks, token_usage) VALUES (?, "assistant", ?, ?, ?)',
            [
                $convId,
                $finalContent,
                $blocksJson,
                $result['usage'] ? json_encode($result['usage']) : null,
            ]
        );
    }
    $newTitle = null;
    if (!empty($result['title']) && is_string($result['title'])) {
        $newTitle = mb_substr(trim($result['title']), 0, 255);
        if ($newTitle !== '') {
            DB::run(
                'UPDATE conversations SET title = ?, updated_at = NOW() WHERE id = ? AND session_id = ?',
                [$newTitle, $convId, $sid]
            );
        }
    }
    DB::run('UPDATE conversations SET updated_at = NOW() WHERE id = ?', [$convId]);

    sse('done', [
        'conversation_id' => $convId,
        'finish_reason'   => $result['finish_reason'],
        'usage'           => $result['usage'],
        'mode'            => $mode,
        'citations'       => $citations,
        'web_notes'       => $webNotes,
        'suggestions'     => $suggestions,
        'content'         => $finalContent,
        'title'           => $newTitle,
    ]);
} catch (Throwable $e) {
    sse('error', ['message' => $e->getMessage()]);
}
