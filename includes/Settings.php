<?php
declare(strict_types=1);

/**
 * App settings stored in the `settings` table (admin-editable).
 */
class AppSettings {
    public const KEY_WEB_ALLOWED_HOSTS = 'web_allowed_hosts';
    public const KEY_CHAT = 'chat_limits';
    public const KEY_SIMPLE_EXPLAIN_AGE = 'simple_explain_age';
    public const DEFAULT_SIMPLE_EXPLAIN_AGE = 10;
    public const KEY_ANSWER_LENGTH = 'answer_length';
    public const DEFAULT_ANSWER_LENGTH = 'medium';
    public const KEY_ADAPTIVE_RETRIEVAL = 'adaptive_retrieval';
    public const KEY_RAG_WEB_MIX = 'rag_web_mix';
    public const DEFAULT_RAG_WEB_MODE = 'complement';
    public const DEFAULT_RAG_WEB_DEGREE = 50;
    public const KEY_UI = 'ui_features';
    public const KEY_DEEP_POLICY = 'deep_thinking_policy';
    /** Manual by default — auto Deep tends to lengthen answers and invent under weak grounding. */
    public const DEFAULT_DEEP_MODE = 'manual';
    public const DEFAULT_DEEP_MAX_PER_CHAT = 3;
    public const KEY_DEEP_STATS = 'deep_thinking_stats';
    public const KEY_FAITHFULNESS = 'faithfulness';
    /** 0 = off; default off — rewrites were gutting good streamed answers */
    public const DEFAULT_FAITHFULNESS_DEGREE = 0;
    /** When true, fetch document Source URLs as live web on RAG hits (allowlisted hosts). */
    public const KEY_DOC_SOURCE_URL_WEB = 'doc_source_url_web';
    public const DEFAULT_DOC_SOURCE_URL_WEB = true;
    /** Display name, prompts, and grounding copy (admin overrides config.php). */
    public const KEY_ASSISTANT_BRANDING = 'assistant_branding';

    /** @var list<string> Stored in assistant_branding JSON (export/import). */
    private const BRANDING_STRING_KEYS = [
        'app_title',
        'docs_mode_label',
        'chat_placeholder',
        'about_blurb',
        'system_prompt',
        'rag_system_prompt',
        'source_hierarchy',
        'grounding_rules',
        'grounding_rules_web',
        'followups_instruction',
        'mix_complement',
        'mix_docs_first',
        'mix_web_first',
        'mix_compete',
    ];

    /** @return list<string> */
    public static function ragWebMixModes(): array {
        return ['complement', 'docs_first', 'web_first', 'compete'];
    }

    /**
     * Admin faithfulness strictness slider (0–100).
     * @return array{degree:int,band:string,enabled:bool,source:string}
     */
    public static function faithfulness(): array {
        $degree = self::DEFAULT_FAITHFULNESS_DEGREE;
        $source = 'default';
        $raw = self::get(self::KEY_FAITHFULNESS);
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['degree']) && is_numeric($decoded['degree'])) {
                $degree = (int)$decoded['degree'];
                $source = 'admin';
            } elseif (is_numeric($raw)) {
                $degree = (int)$raw;
                $source = 'admin';
            }
        }
        $degree = max(0, min(100, $degree));
        return [
            'degree' => $degree,
            'band' => self::faithfulnessBand($degree),
            'enabled' => $degree > 0,
            'source' => $source,
        ];
    }

    /**
     * @param array{degree?:mixed}|int $input
     * @return array{degree:int,band:string,enabled:bool,source:string}
     */
    public static function saveFaithfulness(array|int $input): array {
        if (is_int($input)) {
            $degree = $input;
        } else {
            $degree = isset($input['degree']) && is_numeric($input['degree'])
                ? (int)$input['degree']
                : self::DEFAULT_FAITHFULNESS_DEGREE;
        }
        $degree = max(0, min(100, $degree));
        self::set(self::KEY_FAITHFULNESS, json_encode(['degree' => $degree], JSON_UNESCAPED_SLASHES));
        return [
            'degree' => $degree,
            'band' => self::faithfulnessBand($degree),
            'enabled' => $degree > 0,
            'source' => 'admin',
        ];
    }

    public static function faithfulnessBand(int $degree): string {
        $degree = max(0, min(100, $degree));
        if ($degree <= 0) {
            return 'off';
        }
        if ($degree <= 35) {
            return 'light';
        }
        if ($degree <= 70) {
            return 'balanced';
        }
        return 'strict';
    }

    /**
     * Runtime knobs derived from the slider (used by verifyGroundedAnswer).
     *
     * @param array{degree?:int}|null $policy
     * @return array{
     *   enabled:bool,degree:int,band:string,prefer_ok:bool,
     *   orphan_scan:bool,strip_fallback:bool,shrink_min_ratio:float
     * }
     */
    public static function faithfulnessRuntime(?array $policy = null): array {
        $degree = isset($policy['degree']) && is_numeric($policy['degree'])
            ? (int)$policy['degree']
            : (int)(self::faithfulness()['degree']);
        $degree = max(0, min(100, $degree));
        $band = self::faithfulnessBand($degree);
        return [
            'enabled' => $degree > 0,
            'degree' => $degree,
            'band' => $band,
            // Always soft OK wording — hard “when unsure rewrite” destroyed good drafts
            'prefer_ok' => true,
            // Model rewrites only at higher strictness; light/low = keep streamed answer
            'apply_rewrites' => $degree >= 55,
            'orphan_scan' => $degree >= 55,
            // Silent cite cleanup only (never inject “not clearly shown…” hedges)
            'strip_fallback' => $degree >= 75,
            // Never accept a rewrite that drops more than ~15% of the draft
            'shrink_min_ratio' => 0.85,
        ];
    }

    /** @return list<string> */
    public static function deepThinkingModes(): array {
        return ['manual', 'auto', 'always'];
    }

    /**
     * Admin Deep policy: manual (user toggle), auto (heuristics; toggle forces on), always (until cap).
     * @return array{mode:string,max_per_chat:int,source:string}
     */
    public static function deepThinkingPolicy(): array {
        $defaults = [
            'mode' => self::DEFAULT_DEEP_MODE,
            'max_per_chat' => self::DEFAULT_DEEP_MAX_PER_CHAT,
            'source' => 'default',
        ];
        $raw = self::get(self::KEY_DEEP_POLICY);
        if ($raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $mode = strtolower(trim((string)($decoded['mode'] ?? self::DEFAULT_DEEP_MODE)));
        if (!in_array($mode, self::deepThinkingModes(), true)) {
            $mode = self::DEFAULT_DEEP_MODE;
        }
        $max = isset($decoded['max_per_chat']) && is_numeric($decoded['max_per_chat'])
            ? (int)$decoded['max_per_chat']
            : self::DEFAULT_DEEP_MAX_PER_CHAT;
        $max = max(0, min(50, $max));
        return ['mode' => $mode, 'max_per_chat' => $max, 'source' => 'admin'];
    }

    /**
     * @param array{mode?:mixed,max_per_chat?:mixed} $input
     * @return array{mode:string,max_per_chat:int,source:string}
     */
    public static function saveDeepThinkingPolicy(array $input): array {
        $mode = strtolower(trim((string)($input['mode'] ?? self::DEFAULT_DEEP_MODE)));
        if (!in_array($mode, self::deepThinkingModes(), true)) {
            $mode = self::DEFAULT_DEEP_MODE;
        }
        $max = isset($input['max_per_chat']) && is_numeric($input['max_per_chat'])
            ? (int)$input['max_per_chat']
            : self::DEFAULT_DEEP_MAX_PER_CHAT;
        $max = max(0, min(50, $max));
        self::set(
            self::KEY_DEEP_POLICY,
            json_encode(['mode' => $mode, 'max_per_chat' => $max], JSON_UNESCAPED_SLASHES)
        );
        return ['mode' => $mode, 'max_per_chat' => $max, 'source' => 'admin'];
    }

    /**
     * @return array{
     *   on:int,off:int,auto_on:int,auto_off:int,manual_on:int,manual_off:int,
     *   user_force:int,always:int,capped:int,total:int
     * }
     */
    public static function deepThinkingStats(): array {
        $defaults = [
            'on' => 0,
            'off' => 0,
            'auto_on' => 0,
            'auto_off' => 0,
            'manual_on' => 0,
            'manual_off' => 0,
            'user_force' => 0,
            'always' => 0,
            'capped' => 0,
            'total' => 0,
        ];
        $raw = self::get(self::KEY_DEEP_STATS);
        if ($raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $k => $v) {
            if (isset($decoded[$k]) && is_numeric($decoded[$k])) {
                $defaults[$k] = max(0, (int)$decoded[$k]);
            }
        }
        return $defaults;
    }

    public static function recordDeepThinkingStat(bool $enabled, string $reason): void {
        $stats = self::deepThinkingStats();
        $stats['total']++;
        if ($enabled) {
            $stats['on']++;
        } else {
            $stats['off']++;
        }
        $key = match ($reason) {
            'auto_on' => 'auto_on',
            'auto_off' => 'auto_off',
            'manual_on' => 'manual_on',
            'manual_off' => 'manual_off',
            'user_force' => 'user_force',
            'always' => 'always',
            'capped' => 'capped',
            default => null,
        };
        if ($key !== null) {
            $stats[$key]++;
        }
        self::set(self::KEY_DEEP_STATS, json_encode($stats, JSON_UNESCAPED_SLASHES));
    }

    public static function resetDeepThinkingStats(): array {
        $empty = [
            'on' => 0,
            'off' => 0,
            'auto_on' => 0,
            'auto_off' => 0,
            'manual_on' => 0,
            'manual_off' => 0,
            'user_force' => 0,
            'always' => 0,
            'capped' => 0,
            'total' => 0,
        ];
        self::set(self::KEY_DEEP_STATS, json_encode($empty, JSON_UNESCAPED_SLASHES));
        return $empty;
    }

    /**
     * Heuristic: turn Deep on for hard / multi-step questions.
     *
     * @param array{weak?:bool,hit_count?:int,max_score?:float,rewritten?:bool} $ctx
     */
    public static function shouldAutoDeepThinking(string $message, string $mode, array $ctx = []): bool {
        // Conservative: do NOT auto-Deep on weak retrieval or many hits — that increases invention.
        $msg = trim($message);
        $len = mb_strlen($msg);
        $lower = mb_strtolower($msg);
        $multiPart = substr_count($lower, '?') >= 2
            || (bool)preg_match(
                '/\b(compare|difference|versus|vs\.?|both|step by step|what happens if)\b/u',
                $lower
            );
        if ($multiPart && $len >= 100) {
            return true;
        }
        if ($len >= 280) {
            return true;
        }
        return false;
    }

    /**
     * Resolve whether Deep thinking runs this turn.
     *
     * @param array{weak?:bool,hit_count?:int,max_score?:float,rewritten?:bool} $ctx
     * @return array{enabled:bool,reason:string,policy:string,used_in_chat:int,max_per_chat:int}
     */
    public static function resolveDeepThinking(
        bool $userAsked,
        string $message,
        string $mode,
        int $conversationId,
        array $ctx = []
    ): array {
        $policy = self::deepThinkingPolicy();
        $policyMode = $policy['mode'];
        $maxPerChat = (int)$policy['max_per_chat'];
        $used = self::countDeepThinkingInConversation($conversationId);
        $enabled = false;
        $reason = 'manual_off';

        if ($policyMode === 'always') {
            $enabled = true;
            $reason = 'always';
        } elseif ($policyMode === 'manual') {
            $enabled = $userAsked;
            $reason = $userAsked ? 'manual_on' : 'manual_off';
        } else {
            // auto: user toggle forces on; otherwise heuristics
            if ($userAsked) {
                $enabled = true;
                $reason = 'user_force';
            } else {
                $enabled = self::shouldAutoDeepThinking($message, $mode, $ctx);
                $reason = $enabled ? 'auto_on' : 'auto_off';
            }
        }

        if ($enabled && $maxPerChat > 0 && $used >= $maxPerChat) {
            $enabled = false;
            $reason = 'capped';
        }

        self::recordDeepThinkingStat($enabled, $reason);

        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'policy' => $policyMode,
            'used_in_chat' => $used,
            'max_per_chat' => $maxPerChat,
        ];
    }

    public static function countDeepThinkingInConversation(int $conversationId): int {
        if ($conversationId <= 0) {
            return 0;
        }
        try {
            $rows = DB::all(
                'SELECT blocks FROM messages WHERE conversation_id = ? AND role = "assistant"',
                [$conversationId]
            );
        } catch (Throwable $e) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $row) {
            $raw = $row['blocks'] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['deep_thinking'])) {
                $n++;
            }
        }
        return $n;
    }

    /** @return list<string> */
    public static function uiFeatureKeys(): array {
        return [
            'workcover',
            'hq',
            'deep_thinking',
            'web_search',
            'tags',
            'url_context',
            'file_context',
            'sources',
            'related_questions',
        ];
    }

    /** Features with Default on/off + Show/Hide (composer toggles). */
    public static function uiToggleFeatureKeys(): array {
        return ['workcover', 'hq', 'deep_thinking', 'web_search'];
    }

    /** Features that are only Show/Hide (no default) — presence in the UI. */
    public static function uiVisibilityOnlyKeys(): array {
        return ['tags', 'url_context', 'file_context', 'sources', 'related_questions'];
    }

    /**
     * Frontend feature defaults + visibility + intro + chat history.
     * @return array{
     *   intro:string,
     *   chat_history:bool,
     *   features:array<string,array{default:bool,visibility:string}>
     * }
     */
    public static function uiConfig(): array {
        $defaults = self::defaultUiConfig();
        $raw = self::get(self::KEY_UI);
        if ($raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        if (isset($decoded['intro']) && is_string($decoded['intro'])) {
            $defaults['intro'] = mb_substr(trim($decoded['intro']), 0, 2000);
        }
        if (array_key_exists('chat_history', $decoded)) {
            $defaults['chat_history'] = self::truthy($decoded['chat_history']);
        }
        if (isset($decoded['features']) && is_array($decoded['features'])) {
            // Migrate legacy "plain" → "hq" (inverted: Plain on ⇒ HQ off)
            if (!isset($decoded['features']['hq']) && isset($decoded['features']['plain']) && is_array($decoded['features']['plain'])) {
                $legacy = $decoded['features']['plain'];
                $decoded['features']['hq'] = [
                    'default' => !self::truthy($legacy['default'] ?? true),
                    'visibility' => (string)($legacy['visibility'] ?? 'show'),
                ];
            }
            foreach (self::uiFeatureKeys() as $key) {
                if (!isset($decoded['features'][$key]) || !is_array($decoded['features'][$key])) {
                    continue;
                }
                $f = $decoded['features'][$key];
                $visibilityOnly = in_array($key, self::uiVisibilityOnlyKeys(), true);
                if (!$visibilityOnly && array_key_exists('default', $f)) {
                    $defaults['features'][$key]['default'] = self::truthy($f['default']);
                }
                if (isset($f['visibility']) && is_string($f['visibility'])) {
                    $v = strtolower(trim($f['visibility']));
                    // Legacy "force" → hide (default on for toggle features)
                    if ($v === 'force') {
                        $defaults['features'][$key]['visibility'] = 'hide';
                        if (!$visibilityOnly) {
                            $defaults['features'][$key]['default'] = true;
                        }
                    } elseif (in_array($v, ['show', 'hide'], true)) {
                        $defaults['features'][$key]['visibility'] = $v;
                    }
                }
                // Visibility-only: default is unused; keep true when shown for schema stability
                if ($visibilityOnly) {
                    $defaults['features'][$key]['default'] = true;
                }
            }
        }
        return $defaults;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   intro:string,
     *   chat_history:bool,
     *   features:array<string,array{default:bool,visibility:string}>
     * }
     */
    public static function saveUiConfig(array $input): array {
        $cfg = self::defaultUiConfig();
        if (isset($input['intro']) && is_string($input['intro'])) {
            $cfg['intro'] = mb_substr(trim($input['intro']), 0, 2000);
        }
        if (array_key_exists('chat_history', $input)) {
            $cfg['chat_history'] = self::truthy($input['chat_history']);
        }
        if (isset($input['features']) && is_array($input['features'])) {
            foreach (self::uiFeatureKeys() as $key) {
                if (!isset($input['features'][$key]) || !is_array($input['features'][$key])) {
                    continue;
                }
                $f = $input['features'][$key];
                $visibilityOnly = in_array($key, self::uiVisibilityOnlyKeys(), true);
                if (!$visibilityOnly && array_key_exists('default', $f)) {
                    $cfg['features'][$key]['default'] = self::truthy($f['default']);
                }
                if (isset($f['visibility']) && is_string($f['visibility'])) {
                    $v = strtolower(trim($f['visibility']));
                    if ($v === 'force') {
                        $cfg['features'][$key]['visibility'] = 'hide';
                        if (!$visibilityOnly) {
                            $cfg['features'][$key]['default'] = true;
                        }
                    } elseif (in_array($v, ['show', 'hide'], true)) {
                        $cfg['features'][$key]['visibility'] = $v;
                    }
                }
                if ($visibilityOnly) {
                    $cfg['features'][$key]['default'] = true;
                }
            }
        }
        self::set(self::KEY_UI, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $cfg;
    }

    /** Whether a feature control is shown to the user. */
    public static function uiFeatureEnabled(string $key): bool {
        $cfg = self::uiConfig();
        $f = $cfg['features'][$key] ?? null;
        if (!is_array($f)) return true;
        return ($f['visibility'] ?? 'show') !== 'hide';
    }

    /**
     * Effective on/off for a request flag.
     * Toggle features: when hidden, use admin default; when shown, use client.
     * Visibility-only features: when hidden, off; when shown, use client / available.
     */
    public static function uiFeatureActive(string $key, bool $clientWants): bool {
        $cfg = self::uiConfig();
        $f = $cfg['features'][$key] ?? ['default' => false, 'visibility' => 'show'];
        $vis = (string)($f['visibility'] ?? 'show');
        if ($vis === 'hide') {
            if (in_array($key, self::uiVisibilityOnlyKeys(), true)) {
                return false;
            }
            return !empty($f['default']);
        }
        return $clientWants;
    }

    public static function chatHistoryEnabled(): bool {
        return !empty(self::uiConfig()['chat_history']);
    }

    /**
     * @return array{
     *   intro:string,
     *   chat_history:bool,
     *   features:array<string,array{default:bool,visibility:string}>
     * }
     */
    public static function defaultUiConfig(): array {
        $features = [];
        foreach (self::uiFeatureKeys() as $key) {
            $defaultOn = in_array($key, self::uiToggleFeatureKeys(), true)
                ? ($key === 'workcover') // WorkCover on; HQ / Deep / Web off (Basic by default)
                : true; // visibility-only: unused; kept true for schema
            $features[$key] = ['default' => $defaultOn, 'visibility' => 'show'];
        }
        return [
            'intro' => "Hi — ask a question in everyday language. Turn on Docs & Web for answers grounded in your uploaded documents and allowlisted websites.",
            'chat_history' => true,
            'features' => $features,
        ];
    }

    /** @param mixed $v */
    private static function truthy($v): bool {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function get(string $key): ?string {
        try {
            $row = DB::one('SELECT v FROM settings WHERE k = ?', [$key]);
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null) return null;
        $v = $row['v'] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function set(string $key, string $value): void {
        DB::run(
            'INSERT INTO settings (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)',
            [$key, $value]
        );
    }

    /** @return list<string> */
    public static function importableKeys(): array {
        return [
            self::KEY_WEB_ALLOWED_HOSTS,
            self::KEY_CHAT,
            self::KEY_SIMPLE_EXPLAIN_AGE,
            self::KEY_ANSWER_LENGTH,
            self::KEY_ADAPTIVE_RETRIEVAL,
            self::KEY_RAG_WEB_MIX,
            self::KEY_DEEP_POLICY,
            self::KEY_FAITHFULNESS,
            self::KEY_DOC_SOURCE_URL_WEB,
            self::KEY_UI,
            self::KEY_ASSISTANT_BRANDING,
        ];
    }

    /**
     * Restore admin settings from an export JSON payload (or effective-only object).
     * Uses validated save helpers — unknown keys ignored.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $appCfg config['app'] for chat-limit defaults
     * @return array{updated:list<string>,skipped:list<string>,effective:array<string,mixed>}
     */
    public static function importSettingsExport(array $payload, array $appCfg = []): array {
        $updated = [];
        $skipped = [];

        $effective = null;
        if (isset($payload['effective']) && is_array($payload['effective'])) {
            $effective = $payload['effective'];
        } elseif (
            isset($payload['allowed_hosts'])
            || isset($payload['hosts_text'])
            || isset($payload['ui'])
            || isset($payload['chat_limits'])
            || isset($payload['simple_explain_age'])
            || isset($payload['answer_length'])
            || array_key_exists('adaptive_retrieval', $payload)
            || isset($payload['rag_web_mix'])
            || isset($payload['faithfulness'])
            || isset($payload['deep_thinking_policy'])
            || array_key_exists('doc_source_url_web', $payload)
            || isset($payload['assistant_branding'])
        ) {
            $effective = $payload;
        } elseif (isset($payload['raw']) && is_array($payload['raw'])) {
            $effective = self::effectiveFromRawRows($payload['raw']);
        }

        if ($effective === null || $effective === []) {
            throw new InvalidArgumentException(
                'Invalid settings file — need export.effective, export.raw, or a settings object'
            );
        }

        // Prefer reconstructing from raw when present (round-trip fidelity), then overlay effective.
        if (isset($payload['raw']) && is_array($payload['raw']) && $payload['raw'] !== []) {
            $fromRaw = self::effectiveFromRawRows($payload['raw']);
            $effective = array_merge($fromRaw, $effective);
        }

        if (isset($effective['hosts_text']) && is_string($effective['hosts_text'])) {
            $hosts = self::normalizeHosts($effective['hosts_text']);
            if ($hosts === []) {
                $skipped[] = 'allowed_hosts';
            } else {
                self::set(
                    self::KEY_WEB_ALLOWED_HOSTS,
                    json_encode($hosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
                $updated[] = 'allowed_hosts';
            }
        } elseif (isset($effective['allowed_hosts'])) {
            $hosts = self::normalizeHosts($effective['allowed_hosts']);
            if ($hosts === []) {
                $skipped[] = 'allowed_hosts';
            } else {
                self::set(
                    self::KEY_WEB_ALLOWED_HOSTS,
                    json_encode($hosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
                $updated[] = 'allowed_hosts';
            }
        }

        if (array_key_exists('simple_explain_age', $effective) && is_numeric($effective['simple_explain_age'])) {
            self::saveSimpleExplainAge((int)$effective['simple_explain_age']);
            $updated[] = 'simple_explain_age';
        }

        if (isset($effective['answer_length']) && is_string($effective['answer_length'])) {
            self::saveAnswerLength($effective['answer_length']);
            $updated[] = 'answer_length';
        }

        if (array_key_exists('adaptive_retrieval', $effective)) {
            $raw = $effective['adaptive_retrieval'];
            $on = false;
            if (is_bool($raw)) {
                $on = $raw;
            } elseif (is_numeric($raw)) {
                $on = ((int)$raw) === 1;
            } else {
                $on = in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
            }
            self::saveAdaptiveRetrieval($on);
            $updated[] = 'adaptive_retrieval';
        }

        if (isset($effective['rag_web_mix']) && is_array($effective['rag_web_mix'])) {
            self::saveRagWebMix($effective['rag_web_mix']);
            $updated[] = 'rag_web_mix';
        }

        if (isset($effective['deep_thinking_policy']) && is_array($effective['deep_thinking_policy'])) {
            self::saveDeepThinkingPolicy($effective['deep_thinking_policy']);
            $updated[] = 'deep_thinking_policy';
        }

        if (isset($effective['faithfulness']) && (is_array($effective['faithfulness']) || is_numeric($effective['faithfulness']))) {
            self::saveFaithfulness(is_array($effective['faithfulness']) ? $effective['faithfulness'] : (int)$effective['faithfulness']);
            $updated[] = 'faithfulness';
        }

        if (array_key_exists('doc_source_url_web', $effective)) {
            $raw = $effective['doc_source_url_web'];
            $on = false;
            if (is_bool($raw)) {
                $on = $raw;
            } elseif (is_numeric($raw)) {
                $on = ((int)$raw) === 1;
            } else {
                $on = in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
            }
            self::saveDocSourceUrlWeb($on);
            $updated[] = 'doc_source_url_web';
        }

        if (isset($effective['ui']) && is_array($effective['ui'])) {
            self::saveUiConfig($effective['ui']);
            $updated[] = 'ui';
        }

        if (isset($effective['assistant_branding']) && is_array($effective['assistant_branding'])) {
            self::saveAssistantBranding($effective['assistant_branding']);
            $updated[] = 'assistant_branding';
        }

        if (isset($effective['chat_limits']) && is_array($effective['chat_limits'])) {
            self::saveChatLimits($effective['chat_limits']);
            $updated[] = 'chat_limits';
        }

        if ($updated === []) {
            throw new InvalidArgumentException('No recognised settings found to import');
        }

        $webCfg = [];
        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'effective' => [
                'allowed_hosts' => self::allowedHostsFromConfig($webCfg),
                'simple_explain_age' => self::simpleExplainAge(),
                'answer_length' => self::answerLength(),
                'adaptive_retrieval' => self::adaptiveRetrievalEnabled([]),
                'rag_web_mix' => self::ragWebMix(),
                'deep_thinking_policy' => self::deepThinkingPolicy(),
                'faithfulness' => self::faithfulness(),
                'doc_source_url_web' => self::docSourceUrlWebEnabled(),
                'ui' => self::uiConfig(),
                'assistant_branding' => self::assistantBranding(),
                'chat_limits' => self::chatLimits($appCfg),
            ],
        ];
    }

    /**
     * @param list<array{k?:mixed,v?:mixed}|mixed> $rows
     * @return array<string,mixed>
     */
    private static function effectiveFromRawRows(array $rows): array {
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = isset($row['k']) ? (string)$row['k'] : '';
            if ($k === '' || !in_array($k, self::importableKeys(), true)) {
                continue;
            }
            $v = $row['v'] ?? null;
            if (!is_string($v)) {
                continue;
            }
            $map[$k] = $v;
        }

        $out = [];
        if (isset($map[self::KEY_WEB_ALLOWED_HOSTS])) {
            $decoded = json_decode($map[self::KEY_WEB_ALLOWED_HOSTS], true);
            if (is_array($decoded)) {
                $out['allowed_hosts'] = $decoded;
            }
        }
        if (isset($map[self::KEY_SIMPLE_EXPLAIN_AGE]) && is_numeric($map[self::KEY_SIMPLE_EXPLAIN_AGE])) {
            $out['simple_explain_age'] = (int)$map[self::KEY_SIMPLE_EXPLAIN_AGE];
        }
        if (isset($map[self::KEY_ANSWER_LENGTH])) {
            $out['answer_length'] = $map[self::KEY_ANSWER_LENGTH];
        }
        if (isset($map[self::KEY_ADAPTIVE_RETRIEVAL])) {
            $out['adaptive_retrieval'] = in_array(
                strtolower(trim($map[self::KEY_ADAPTIVE_RETRIEVAL])),
                ['1', 'true', 'yes', 'on'],
                true
            );
        }
        if (isset($map[self::KEY_RAG_WEB_MIX])) {
            $decoded = json_decode($map[self::KEY_RAG_WEB_MIX], true);
            if (is_array($decoded)) {
                $out['rag_web_mix'] = $decoded;
            }
        }
        if (isset($map[self::KEY_DEEP_POLICY])) {
            $decoded = json_decode($map[self::KEY_DEEP_POLICY], true);
            if (is_array($decoded)) {
                $out['deep_thinking_policy'] = $decoded;
            }
        }
        if (isset($map[self::KEY_FAITHFULNESS])) {
            $decoded = json_decode($map[self::KEY_FAITHFULNESS], true);
            if (is_array($decoded)) {
                $out['faithfulness'] = $decoded;
            } elseif (is_numeric($map[self::KEY_FAITHFULNESS])) {
                $out['faithfulness'] = ['degree' => (int)$map[self::KEY_FAITHFULNESS]];
            }
        }
        if (isset($map[self::KEY_DOC_SOURCE_URL_WEB])) {
            $out['doc_source_url_web'] = in_array(
                strtolower(trim($map[self::KEY_DOC_SOURCE_URL_WEB])),
                ['1', 'true', 'yes', 'on'],
                true
            );
        }
        if (isset($map[self::KEY_UI])) {
            $decoded = json_decode($map[self::KEY_UI], true);
            if (is_array($decoded)) {
                $out['ui'] = $decoded;
            }
        }
        if (isset($map[self::KEY_ASSISTANT_BRANDING])) {
            $decoded = json_decode($map[self::KEY_ASSISTANT_BRANDING], true);
            if (is_array($decoded)) {
                $out['assistant_branding'] = $decoded;
            }
        }
        if (isset($map[self::KEY_CHAT])) {
            $decoded = json_decode($map[self::KEY_CHAT], true);
            if (is_array($decoded)) {
                $out['chat_limits'] = $decoded;
            }
        }
        return $out;
    }

    /**
     * Age used by the composer “explain simply” checkbox (plain-language answers).
     */
    public static function simpleExplainAge(): int {
        $raw = self::get(self::KEY_SIMPLE_EXPLAIN_AGE);
        $age = ($raw !== null && $raw !== '' && is_numeric($raw))
            ? (int)$raw
            : self::DEFAULT_SIMPLE_EXPLAIN_AGE;
        return max(5, min(18, $age));
    }

    public static function saveSimpleExplainAge(int $age): int {
        $age = max(5, min(18, $age));
        self::set(self::KEY_SIMPLE_EXPLAIN_AGE, (string)$age);
        return $age;
    }

    public static function simpleExplainInstruction(?int $age = null): string {
        $age = $age ?? self::simpleExplainAge();
        $age = max(5, min(18, $age));
        return "Explain it to me like a {$age} year old. Use plain, everyday words, "
            . "short sentences, and simple examples. Keep legal meaning accurate — "
            . "do not invent rules — but avoid jargon unless you briefly define it.";
    }

    /**
     * HQ mode: richer / more precise language only (no post-stream rewrite).
     * Faithfulness is a separate automatic check when grounding exists.
     * Respects global answer length so "thorough" does not override Short.
     */
    public static function hqInstruction(?string $answerLength = null): string {
        $len = $answerLength !== null
            ? strtolower(trim($answerLength))
            : self::answerLength();
        if ($len === 'short') {
            return 'Answer in high-quality mode within the SHORT length limit: precise wording '
                . 'when the excerpts support it; name section or policy references when '
                . 'relevant. Prefer accuracy over coverage — do not expand into exceptions, '
                . 'related topics, or definitions unless needed for the direct answer. '
                . 'Plain text only (no Markdown). Do not invent rules.';
        }
        if ($len === 'long') {
            return 'Answer in high-quality mode: be precise and thorough. Use correct technical '
                . 'terms when the excerpts support them, name sections or clauses clearly, and '
                . 'cover important related points, exceptions, and definitions. '
                . 'Still write plain text only (no Markdown). Do not invent rules.';
        }
        return 'Answer in high-quality mode: be precise. Use correct technical terms '
            . 'when the excerpts support them; name sections or clauses clearly. '
            . 'Include a key exception only if it directly affects the answer. Stay within '
            . 'MEDIUM length. Plain text only (no Markdown). Do not invent rules.';
    }

    /** Extra system lines when Deep thinking is on for this turn. */
    public static function deepThinkingInstruction(string $answerLength = 'medium'): string {
        $len = strtolower(trim($answerLength));
        $lengthBit = $len === 'short'
            ? 'Your visible answer must still obey SHORT (≤4 sentences).'
            : ($len === 'long'
                ? 'You may be thorough, but every claim needs source support.'
                : 'Keep the visible answer MEDIUM — thinking is private; the answer is not an essay.');
        return 'Deep thinking is enabled for reasoning only. '
            . 'The user-visible answer must stay strictly grounded in the provided excerpts/web/context. '
            . 'Do not invent section numbers, duties, time limits, or procedures. '
            . 'If sources are thin or conflicting, say what is and is not supported — do not fill gaps. '
            . $lengthBit;
    }

    /** Global chat answer length: short | medium | long */
    public static function answerLength(): string {
        $raw = strtolower(trim((string)(self::get(self::KEY_ANSWER_LENGTH) ?? '')));
        if (!in_array($raw, ['short', 'medium', 'long'], true)) {
            return self::DEFAULT_ANSWER_LENGTH;
        }
        return $raw;
    }

    public static function saveAnswerLength(string $length): string {
        $length = strtolower(trim($length));
        if (!in_array($length, ['short', 'medium', 'long'], true)) {
            $length = self::DEFAULT_ANSWER_LENGTH;
        }
        self::set(self::KEY_ANSWER_LENGTH, $length);
        return $length;
    }

    /**
     * Fetch admin document Source URLs as allowlisted live web when those docs are retrieved.
     * Default on (matches prior behaviour after Source URL web fetch shipped).
     */
    public static function docSourceUrlWebEnabled(): bool {
        $raw = self::get(self::KEY_DOC_SOURCE_URL_WEB);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_DOC_SOURCE_URL_WEB;
        }
        $v = strtolower(trim($raw));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function saveDocSourceUrlWeb(bool $enabled): bool {
        self::set(self::KEY_DOC_SOURCE_URL_WEB, $enabled ? '1' : '0');
        return $enabled;
    }

    public static function docSourceUrlWebSource(): string {
        $raw = self::get(self::KEY_DOC_SOURCE_URL_WEB);
        if ($raw !== null && $raw !== '') {
            return 'admin';
        }
        return 'default';
    }

    /**
     * @return array{
     *   app_title:string,
     *   docs_mode_label:string,
     *   chat_placeholder:string,
     *   about_blurb:string,
     *   system_prompt:string,
     *   rag_system_prompt:string,
     *   source_hierarchy:string,
     *   onboarding_dismissed:bool
     * }
     */
    public static function defaultAssistantBranding(): array {
        return [
            'app_title' => '',
            'docs_mode_label' => 'Docs & Web',
            'chat_placeholder' => 'Ask a question…',
            'about_blurb' => '',
            'system_prompt' => '',
            'rag_system_prompt' => '',
            'source_hierarchy' => '',
            'grounding_rules' => '',
            'grounding_rules_web' => '',
            'followups_instruction' => '',
            'mix_complement' => '',
            'mix_docs_first' => '',
            'mix_web_first' => '',
            'mix_compete' => '',
            'onboarding_dismissed' => false,
        ];
    }

    /** Neutral hierarchy when admin leaves source_hierarchy blank. */
    public static function defaultSourceHierarchyText(): string {
        return 'Hierarchy: treat primary authoritative documents (e.g. legislation, official policy, standards) '
            . 'ahead of guides or summaries. When both appear, state the authoritative rule first, '
            . 'then optional practical guidance.';
    }

    /**
     * @return array{
     *   app_title:string,
     *   docs_mode_label:string,
     *   chat_placeholder:string,
     *   about_blurb:string,
     *   system_prompt:string,
     *   rag_system_prompt:string,
     *   source_hierarchy:string,
     *   onboarding_dismissed:bool,
     *   source:string
     * }
     */
    public static function assistantBranding(): array {
        $defaults = self::defaultAssistantBranding();
        $raw = self::get(self::KEY_ASSISTANT_BRANDING);
        if ($raw === null || $raw === '') {
            $defaults['source'] = 'default';
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $defaults['source'] = 'default';
            return $defaults;
        }
        $out = $defaults;
        foreach (self::BRANDING_STRING_KEYS as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k])) {
                $out[$k] = mb_substr(trim($decoded[$k]), 0, $k === 'about_blurb' ? 4000 : 12000);
            }
        }
        if (array_key_exists('onboarding_dismissed', $decoded)) {
            $out['onboarding_dismissed'] = self::truthy($decoded['onboarding_dismissed']);
        }
        $out['source'] = 'admin';
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function saveAssistantBranding(array $input): array {
        $current = self::assistantBranding();
        unset($current['source']);
        foreach (self::BRANDING_STRING_KEYS as $k) {
            if (array_key_exists($k, $input) && is_string($input[$k])) {
                $current[$k] = mb_substr(trim($input[$k]), 0, $k === 'about_blurb' ? 4000 : 12000);
            }
        }
        if (array_key_exists('onboarding_dismissed', $input)) {
            $current['onboarding_dismissed'] = self::truthy($input['onboarding_dismissed']);
        }
        self::set(
            self::KEY_ASSISTANT_BRANDING,
            json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $saved = self::assistantBranding();
        return $saved;
    }

    /** Default excerpt grounding block (docs only, no live web). */
    public static function defaultGroundingRulesDocs(): string {
        $hierarchy = self::sourceHierarchyForGrounding();
        return "DOCUMENT EXCERPTS (authoritative for this answer):\n"
            . "Grounding rules (mandatory):\n"
            . "- Answer ONLY from these excerpts and any attached DeepSeek-parsed files. "
            . "Do not use outside knowledge, guesses, or general lore that is not in the excerpts.\n"
            . "- If the excerpts only partly cover the question, answer the covered part and clearly say what is missing.\n"
            . "- If nothing relevant is in the excerpts or attached files, say you cannot find it in the uploaded documents. "
            . "Do not invent section numbers, time limits, dollar amounts, or obligations.\n"
            . "- Write plain text only (no Markdown). Do not include citation numbers such as [1], [2], or [n].\n"
            . $hierarchy . "\n"
            . "Name section, clause, part, or page references in plain words when the excerpts support them. "
            . "Prefer quoting or closely paraphrasing the relevant rule or text. "
            . "Review all excerpts for related definitions, exceptions, procedures, or cross-references.\n\n";
    }

    /** Default excerpt grounding when live allowlisted web may also be used. */
    public static function defaultGroundingRulesWeb(): string {
        return "DOCUMENT EXCERPTS (part of combined Docs + live web grounding):\n"
            . "Grounding rules (mandatory):\n"
            . "- Combine these excerpts with any LIVE ALLOWLISTED WEB / URL blocks in this system prompt into one answer. "
            . "Do not discard relevant web content.\n"
            . "- Prefer authoritative document excerpts when present; "
            . "use allowlisted web pages to enrich with current guidance, process steps, and anything the excerpts omit.\n"
            . "- Do not invent beyond excerpts, attached DeepSeek files, and allowlisted web/URL text.\n"
            . "- If neither excerpts nor allowlisted web/URL text cover the question, say so clearly.\n"
            . "- Write plain text only (no Markdown). Do not include citation numbers such as [1], [2], or [n].\n"
            . "Name sections, clauses, or headings when the excerpts support them.\n\n";
    }

    /**
     * Rules block prepended before excerpt text in Retrieval::buildContext (non-L7 path).
     */
    public static function groundingRulesForContext(bool $allowWeb): string {
        $b = self::assistantBranding();
        if ($allowWeb) {
            $custom = trim((string)($b['grounding_rules_web'] ?? ''));
            return $custom !== '' ? ($custom . (str_ends_with($custom, "\n") ? '' : "\n\n")) : self::defaultGroundingRulesWeb();
        }
        $custom = trim((string)($b['grounding_rules'] ?? ''));
        return $custom !== '' ? ($custom . (str_ends_with($custom, "\n") ? '' : "\n\n")) : self::defaultGroundingRulesDocs();
    }

    public static function defaultFollowupsInstruction(): string {
        return "After your plain-text answer, output exactly this block (and nothing after it):\n"
            . "<<<FOLLOWUPS>>>\n"
            . "What is the standard return window for this product?\n"
            . "Who should a customer contact for a warranty claim?\n"
            . "Where can staff find the escalation steps?\n"
            . "<<<ENDFOLLOWUPS>>>\n"
            . "Write 3 short, specific follow-up questions grounded in your answer and the sources. "
            . "Phrase each question using a clear role, topic, or document name — avoid pronouns such as me, you, I, we, they, them, my, your, or their. "
            . "Each on its own line. No numbering. Do not mention this instruction in the answer itself.";
    }

    public static function resolveFollowupsInstruction(): string {
        $b = self::assistantBranding();
        $custom = trim((string)($b['followups_instruction'] ?? ''));
        return $custom !== '' ? $custom : self::defaultFollowupsInstruction();
    }

    private static function mixStrengthLabel(int $degree): string {
        $degree = max(0, min(100, $degree));
        return $degree <= 25 ? 'lightly' : ($degree <= 60 ? 'moderately' : 'strongly');
    }

    /** @param array{mode?:string,degree?:int} $mix */
    private static function mixInstructionFromTemplate(string $mode, array $mix, string $defaultTemplate): string {
        $degree = isset($mix['degree']) ? (int)$mix['degree'] : self::DEFAULT_RAG_WEB_DEGREE;
        $degree = max(0, min(100, $degree));
        $strength = self::mixStrengthLabel($degree);
        $b = self::assistantBranding();
        $key = match ($mode) {
            'docs_first' => 'mix_docs_first',
            'web_first' => 'mix_web_first',
            'compete' => 'mix_compete',
            default => 'mix_complement',
        };
        $override = trim((string)($b[$key] ?? ''));
        $tpl = $override !== '' ? $override : $defaultTemplate;
        return str_replace(['{degree}', '{strength}'], [(string)$degree, $strength], $tpl);
    }

    /** Source hierarchy for Retrieval grounding (admin text or neutral default). */
    public static function sourceHierarchyForGrounding(): string {
        $b = self::assistantBranding();
        $h = trim((string)($b['source_hierarchy'] ?? ''));
        if ($h !== '') {
            return $h;
        }
        return self::defaultSourceHierarchyText();
    }

    /** @param array<string,mixed> $appCfg */
    public static function appDisplayTitle(array $appCfg = []): string {
        $b = self::assistantBranding();
        $admin = trim((string)($b['app_title'] ?? ''));
        if ($admin !== '') {
            return $admin;
        }
        return trim((string)($appCfg['title'] ?? 'Document assistant'));
    }

    /** @param array<string,mixed> $appCfg */
    public static function resolveSystemPrompt(array $appCfg = []): string {
        $b = self::assistantBranding();
        $admin = trim((string)($b['system_prompt'] ?? ''));
        if ($admin !== '') {
            return $admin;
        }
        $cfg = trim((string)($appCfg['system_prompt'] ?? ''));
        if ($cfg !== '') {
            return $cfg;
        }
        return 'You are a helpful, concise assistant. Answer clearly in plain text only — no Markdown. '
            . 'If you do not know something, say so.';
    }

    /** @param array<string,mixed> $appCfg */
    public static function resolveRagSystemPrompt(array $appCfg = []): string {
        $b = self::assistantBranding();
        $admin = trim((string)($b['rag_system_prompt'] ?? ''));
        if ($admin !== '') {
            return $admin;
        }
        $cfg = trim((string)($appCfg['rag_system_prompt'] ?? ''));
        if ($cfg !== '') {
            return $cfg;
        }
        $title = self::appDisplayTitle($appCfg);
        return "You are {$title}, a helpful document assistant in a multi-turn conversation. "
            . 'Use the provided document excerpts together with chat history. Answer follow-ups in context. '
            . 'Write plain text only — no Markdown. Do not put citation numbers like [1] or [2] in the reply. '
            . 'Prefer authoritative sources over guides when both appear. '
            . 'Only refuse when excerpts and history do not cover the question.';
    }

    /**
     * Public branding fields merged into chat UI config.
     * @return array{app_title:string,docs_mode_label:string,chat_placeholder:string,about_blurb:string}
     */
    public static function publicUiBranding(array $appCfg = []): array {
        $b = self::assistantBranding();
        $about = trim((string)($b['about_blurb'] ?? ''));
        if ($about === '') {
            $title = self::appDisplayTitle($appCfg);
            $label = trim((string)($b['docs_mode_label'] ?? 'Docs & Web')) ?: 'Docs & Web';
            $about = "{$title} helps staff and customers find answers from your uploaded documents. "
                . "Turn on {$label} for grounded answers plus allowlisted live web.";
        }
        return [
            'app_title' => self::appDisplayTitle($appCfg),
            'docs_mode_label' => trim((string)($b['docs_mode_label'] ?? 'Docs & Web')) ?: 'Docs & Web',
            'chat_placeholder' => trim((string)($b['chat_placeholder'] ?? '')) ?: 'Ask a question…',
            'about_blurb' => $about,
        ];
    }

    /**
     * Adaptive RAG sizing. Admin override wins; else config rag.adaptive_retrieval (default false).
     * @param array<string,mixed> $ragCfg
     */
    public static function adaptiveRetrievalEnabled(array $ragCfg = []): bool {
        $raw = self::get(self::KEY_ADAPTIVE_RETRIEVAL);
        if ($raw !== null && $raw !== '') {
            $v = strtolower(trim($raw));
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
        return !empty($ragCfg['adaptive_retrieval']);
    }

    public static function saveAdaptiveRetrieval(bool $enabled): bool {
        self::set(self::KEY_ADAPTIVE_RETRIEVAL, $enabled ? '1' : '0');
        return $enabled;
    }

    /** @param array<string,mixed> $ragCfg */
    public static function adaptiveRetrievalSource(array $ragCfg = []): string {
        $raw = self::get(self::KEY_ADAPTIVE_RETRIEVAL);
        if ($raw !== null && $raw !== '') {
            return 'admin';
        }
        return array_key_exists('adaptive_retrieval', $ragCfg) ? 'config' : 'default';
    }

    /**
     * How Docs mode mixes uploaded RAG with allowlisted live web.
     * @return array{mode:string,degree:int,source:string}
     */
    public static function ragWebMix(): array {
        $defaults = [
            'mode' => self::DEFAULT_RAG_WEB_MODE,
            'degree' => self::DEFAULT_RAG_WEB_DEGREE,
            'source' => 'default',
        ];
        $raw = self::get(self::KEY_RAG_WEB_MIX);
        if ($raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $mode = strtolower(trim((string)($decoded['mode'] ?? self::DEFAULT_RAG_WEB_MODE)));
        if (!in_array($mode, self::ragWebMixModes(), true)) {
            $mode = self::DEFAULT_RAG_WEB_MODE;
        }
        $degree = isset($decoded['degree']) && is_numeric($decoded['degree'])
            ? (int)$decoded['degree']
            : self::DEFAULT_RAG_WEB_DEGREE;
        $degree = max(0, min(100, $degree));
        return ['mode' => $mode, 'degree' => $degree, 'source' => 'admin'];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{mode:string,degree:int,source:string}
     */
    public static function saveRagWebMix(array $input): array {
        $mode = strtolower(trim((string)($input['mode'] ?? self::DEFAULT_RAG_WEB_MODE)));
        if (!in_array($mode, self::ragWebMixModes(), true)) {
            $mode = self::DEFAULT_RAG_WEB_MODE;
        }
        $degree = isset($input['degree']) && is_numeric($input['degree'])
            ? (int)$input['degree']
            : self::DEFAULT_RAG_WEB_DEGREE;
        $degree = max(0, min(100, $degree));
        self::set(
            self::KEY_RAG_WEB_MIX,
            json_encode(['mode' => $mode, 'degree' => $degree], JSON_UNESCAPED_SLASHES)
        );
        return ['mode' => $mode, 'degree' => $degree, 'source' => 'admin'];
    }

    /**
     * System-prompt instructions for combining document excerpts with allowlisted web.
     * @param array{mode?:string,degree?:int} $mix
     */
    public static function ragWebMixInstruction(array $mix): string {
        $mode = (string)($mix['mode'] ?? self::DEFAULT_RAG_WEB_MODE);
        $mode = in_array($mode, self::ragWebMixModes(), true) ? $mode : self::DEFAULT_RAG_WEB_MODE;

        $templates = [
            'docs_first' => 'RAG↔Web mix: DOCS FIRST (degree {degree}/100). '
                . 'Lead with uploaded document excerpts for every answer. '
                . 'Use allowlisted web {strength} only to fill gaps when excerpts are weak, silent, or outdated on process details. '
                . 'Do not let web override authoritative document text. '
                . 'Keep web-supported facts that fill those gaps — do not drop them.',
            'web_first' => 'RAG↔Web mix: WEB FIRST (degree {degree}/100). '
                . 'Lead with allowlisted live web for current guidance and public process pages. '
                . 'Use uploaded document excerpts {strength} for formal rules, policy sections, and stated obligations. '
                . 'When both apply, state the rule from excerpts and how the website describes it. '
                . 'Do not drop either side\'s supported claims.',
            'compete' => 'RAG↔Web mix: COMPETE / PICK WINNER (degree {degree}/100). '
                . 'Do not blend sources into a soft merge. Choose the clearer, more on-topic side for the user\'s question '
                . '(degree high → prefer web when both are plausible; degree low → prefer documents). '
                . 'When authoritative documents clearly answer, they still win over web. '
                . 'You may briefly note the other side was weaker, but answer primarily from the winner. '
                . 'Still do not invent beyond the winning sources.',
            'complement' => 'RAG↔Web mix: COMPLEMENT (degree {degree}/100). '
                . 'Combine document excerpts and allowlisted web into one answer. '
                . 'Enrich {strength} with web for currency and omitted process detail; '
                . 'prefer authoritative excerpts for formal rules and obligations. '
                . 'Never discard relevant web content that the excerpts omit.',
        ];

        return self::mixInstructionFromTemplate($mode, $mix, $templates[$mode] ?? $templates['complement']);
    }

    /**
     * Faithfulness-check policy line — same mix mode + degree as the first-pass blend.
     * Degree scales how strongly the auditor should prefer the primary side (not inventiveness).
     * @param array{mode?:string,degree?:int} $mix
     */
    public static function ragWebMixHqHint(array $mix): string {
        $mode = (string)($mix['mode'] ?? self::DEFAULT_RAG_WEB_MODE);
        $degree = isset($mix['degree']) ? (int)$mix['degree'] : self::DEFAULT_RAG_WEB_DEGREE;
        $degree = max(0, min(100, $degree));
        $lean = $degree <= 25 ? 'lightly' : ($degree <= 60 ? 'moderately' : 'strongly');
        $prefix = "Faithfulness mix policy: mode={$mode}, degree={$degree}/100 ({$lean}). ";

        return $prefix . match ($mode) {
            'docs_first' => "Prefer doc-supported claims {$lean}; keep web-supported claims that fill gaps or current process detail already in the draft or SOURCE MATERIAL. "
                . 'Do not invent. Prefer OK; rewrite only clear inventions. Higher degree → allow more web gap-fill; lower degree → stick closer to docs.',
            'web_first' => "Prefer web-supported claims {$lean}; keep authoritative excerpt claims for formal duties and section references. "
                . 'Do not invent. Prefer OK; rewrite only clear inventions. Higher degree → lean harder on web for process; lower degree → still protect primary document text.',
            'compete' => "If the draft already picked a clear winner per compete rules and all claims are sourced, OK is fine. "
                . "Drop the weaker side when it invents or contradicts SOURCE MATERIAL. "
                . "Higher degree → tolerate web-leaning winners more; lower degree → tolerate docs-leaning winners more. Do not invent.",
            default => 'Keep every claim supported by docs OR allowlisted web; never strip web-backed enrichment present in SOURCE MATERIAL. '
                . "Enrichment strength was {$lean} in the first pass — do not undo that blend. "
                . 'Prefer OK; only rewrite clear inventions. Do not invent.',
        };
    }

    /**
     * Merge DB overrides into config web section (allowed_hosts).
     * @param array<string,mixed> $webCfg
     * @return array<string,mixed>
     */
    public static function mergeWebConfig(array $webCfg): array {
        $raw = self::get(self::KEY_WEB_ALLOWED_HOSTS);
        if ($raw === null || $raw === '') {
            return $webCfg;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $webCfg;
        }
        $hosts = self::normalizeHosts($decoded);
        if ($hosts !== []) {
            $webCfg['allowed_hosts'] = $hosts;
        }
        return $webCfg;
    }

    /**
     * @return list<string>
     */
    public static function allowedHostsFromConfig(array $webCfg): array {
        $merged = self::mergeWebConfig($webCfg);
        $hosts = self::normalizeHosts($merged['allowed_hosts'] ?? []);
        return $hosts !== [] ? $hosts : ['workcover.wa.gov.au'];
    }

    /**
     * @param list<mixed>|mixed $input
     * @return list<string>
     */
    public static function normalizeHosts($input): array {
        if (!is_array($input)) {
            if (is_string($input)) {
                $input = preg_split('/[\r\n,;]+/', $input) ?: [];
            } else {
                return [];
            }
        }
        $out = [];
        foreach ($input as $item) {
            $host = self::normalizeHostname((string)$item);
            if ($host === null) continue;
            if (!in_array($host, $out, true)) {
                $out[] = $host;
            }
            if (count($out) >= 30) break;
        }
        return $out;
    }

    public static function normalizeHostname(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '#')) {
            return null;
        }
        // Allow bare host or full URL
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }
        $host = parse_url($raw, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(rtrim($host, '.'));
        if (strlen($host) > 253) {
            return null;
        }
        // Hostname or IPv4
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host)
            && $host !== 'localhost') {
            return null;
        }
        return $host;
    }

    /** @param list<string> $hosts */
    public static function hostsToText(array $hosts): string {
        return implode("\n", $hosts);
    }

    /**
     * Chat retention / caps. 0 means unlimited (except list_limit floor).
     * @param array<string,mixed> $appCfg config['app']
     * @return array{
     *   max_per_session:int,
     *   max_global:int,
     *   retention_days:int,
     *   list_limit:int,
     *   source:string
     * }
     */
    public static function chatLimits(array $appCfg = []): array {
        $defaults = [
            'max_per_session' => (int)($appCfg['chat_max_per_session'] ?? 50),
            'max_global'      => (int)($appCfg['chat_max_global'] ?? 500),
            'retention_days'  => (int)($appCfg['chat_retention_days'] ?? 0),
            'list_limit'      => (int)($appCfg['chat_list_limit'] ?? 100),
        ];
        $raw = self::get(self::KEY_CHAT);
        $source = 'config';
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $source = 'admin';
                foreach (['max_per_session', 'max_global', 'retention_days', 'list_limit'] as $k) {
                    if (isset($decoded[$k]) && is_numeric($decoded[$k])) {
                        $defaults[$k] = (int)$decoded[$k];
                    }
                }
            }
        }
        $defaults['max_per_session'] = max(0, min(5000, $defaults['max_per_session']));
        $defaults['max_global'] = max(0, min(100000, $defaults['max_global']));
        $defaults['retention_days'] = max(0, min(3650, $defaults['retention_days']));
        $defaults['list_limit'] = max(10, min(500, $defaults['list_limit']));
        $defaults['source'] = $source;
        return $defaults;
    }

    /** @param array<string,mixed> $limits */
    public static function saveChatLimits(array $limits): array {
        $normalized = [
            'max_per_session' => max(0, min(5000, (int)($limits['max_per_session'] ?? 50))),
            'max_global'      => max(0, min(100000, (int)($limits['max_global'] ?? 500))),
            'retention_days'  => max(0, min(3650, (int)($limits['retention_days'] ?? 0))),
            'list_limit'      => max(10, min(500, (int)($limits['list_limit'] ?? 100))),
        ];
        self::set(self::KEY_CHAT, json_encode($normalized, JSON_UNESCAPED_SLASHES));
        $normalized['source'] = 'admin';
        return $normalized;
    }

    /**
     * Delete oldest conversations for a session when over the per-session cap.
     * @return int number deleted
     */
    public static function enforceSessionChatLimit(string $sessionId, array $appCfg = []): int {
        $limits = self::chatLimits($appCfg);
        $max = $limits['max_per_session'];
        if ($max <= 0 || $sessionId === '') return 0;
        $countRow = DB::one('SELECT COUNT(*) AS n FROM conversations WHERE session_id = ?', [$sessionId]);
        $n = (int)($countRow['n'] ?? 0);
        if ($n <= $max) return 0;
        $extra = $n - $max;
        $old = DB::all(
            'SELECT id FROM conversations WHERE session_id = ? ORDER BY updated_at ASC, id ASC LIMIT ' . (int)$extra,
            [$sessionId]
        );
        $deleted = 0;
        foreach ($old as $row) {
            DB::run('DELETE FROM conversations WHERE id = ?', [(int)$row['id']]);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * Delete oldest conversations globally when over the global cap.
     * @return int number deleted
     */
    public static function enforceGlobalChatLimit(array $appCfg = []): int {
        $limits = self::chatLimits($appCfg);
        $max = $limits['max_global'];
        if ($max <= 0) return 0;
        $countRow = DB::one('SELECT COUNT(*) AS n FROM conversations');
        $n = (int)($countRow['n'] ?? 0);
        if ($n <= $max) return 0;
        $extra = $n - $max;
        $old = DB::all(
            'SELECT id FROM conversations ORDER BY updated_at ASC, id ASC LIMIT ' . (int)$extra
        );
        $deleted = 0;
        foreach ($old as $row) {
            DB::run('DELETE FROM conversations WHERE id = ?', [(int)$row['id']]);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * Delete conversations older than retention_days (if > 0).
     * @return int number deleted
     */
    public static function pruneChatsByRetention(array $appCfg = []): int {
        $limits = self::chatLimits($appCfg);
        $days = $limits['retention_days'];
        if ($days <= 0) return 0;
        $st = DB::run(
            'DELETE FROM conversations WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        return $st->rowCount();
    }
}
