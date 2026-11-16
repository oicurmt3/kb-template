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
    /** 0 = off; default ~current brake strength */
    public const DEFAULT_FAITHFULNESS_DEGREE = 70;
    /** When true, fetch document Source URLs as live web on RAG hits (allowlisted hosts). */
    public const KEY_DOC_SOURCE_URL_WEB = 'doc_source_url_web';
    public const DEFAULT_DOC_SOURCE_URL_WEB = true;

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
            // Below mid: soft OK; at/above 50: OK only when fully sourced
            'prefer_ok' => $degree > 0 && $degree < 50,
            'orphan_scan' => $degree >= 40,
            'strip_fallback' => $degree >= 60,
            // Higher strictness → allow more aggressive cuts of inventions
            'shrink_min_ratio' => $degree >= 80 ? 0.30 : ($degree >= 55 ? 0.40 : 0.50),
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
            'intro' => "Hi — I'm WANDA, your Western Australian workers compensation assistant. Ask a question in everyday language; turn on WorkCover for answers grounded in the Act, Regulations, and guides.",
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
            return 'Answer in high-quality mode within the SHORT length limit: precise legal '
                . 'wording when the excerpts support it; name section/regulation numbers when '
                . 'relevant. Prefer accuracy over coverage — do not expand into exceptions, '
                . 'related topics, or definitions unless needed for the direct answer. '
                . 'Plain text only (no Markdown). Do not invent rules.';
        }
        if ($len === 'long') {
            return 'Answer in high-quality mode: be precise and thorough. Use correct legal '
                . 'and technical terms when the excerpts support them, name section/regulation '
                . 'numbers clearly, and cover important related points, exceptions, and '
                . 'definitions. Still write plain text only (no Markdown). Do not invent rules.';
        }
        return 'Answer in high-quality mode: be precise. Use correct legal and technical terms '
            . 'when the excerpts support them; name section/regulation numbers clearly. '
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
        $degree = isset($mix['degree']) ? (int)$mix['degree'] : self::DEFAULT_RAG_WEB_DEGREE;
        $degree = max(0, min(100, $degree));
        $strength = $degree <= 25 ? 'lightly' : ($degree <= 60 ? 'moderately' : 'strongly');

        return match ($mode) {
            'docs_first' => "RAG↔Web mix: DOCS FIRST (degree {$degree}/100). "
                . "Lead with uploaded document excerpts for every answer. "
                . "Use allowlisted web {$strength} only to fill gaps when excerpts are weak, silent, or outdated on process details. "
                . "Do not let web override Act/Regulations text. "
                . "Keep web-supported facts that fill those gaps — do not drop them.",
            'web_first' => "RAG↔Web mix: WEB FIRST (degree {$degree}/100). "
                . "Lead with allowlisted live web for current guidance and public process pages. "
                . "Use uploaded Act/Regulations excerpts {$strength} for statutory rules, section numbers, and legal duties. "
                . "When both apply, state the legal rule from excerpts and how the website describes it. "
                . "Do not drop either side's supported claims.",
            'compete' => "RAG↔Web mix: COMPETE / PICK WINNER (degree {$degree}/100). "
                . "Do not blend sources into a soft merge. Choose the clearer, more on-topic side for the user's question "
                . "(degree high → prefer web when both are plausible; degree low → prefer documents). "
                . "For WA workers compensation statute questions, uploaded Act/Regulations still win if they clearly answer. "
                . "You may briefly note the other side was weaker, but answer primarily from the winner. "
                . "Still do not invent beyond the winning sources.",
            default => "RAG↔Web mix: COMPLEMENT (degree {$degree}/100). "
                . "Combine document excerpts and allowlisted web into one answer. "
                . "Enrich {$strength} with web for currency and omitted process detail; "
                . "prefer Act/Regulations excerpts for WA workers compensation law. "
                . "Never discard relevant web content that the excerpts omit.",
        };
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
                . 'Do not invent. OK only when every legal claim is sourced; rewrite to drop inventions. Higher degree → allow more web gap-fill; lower degree → stick closer to docs.',
            'web_first' => "Prefer web-supported claims {$lean}; keep Act/Regs excerpt claims for legal duties and section numbers. "
                . 'Do not invent. OK only when every legal claim is sourced; rewrite to drop inventions. Higher degree → lean harder on web for process; lower degree → still protect statute text.',
            'compete' => "If the draft already picked a clear winner per compete rules and all claims are sourced, OK is fine. "
                . "Drop the weaker side when it invents or contradicts SOURCE MATERIAL. "
                . "Higher degree → tolerate web-leaning winners more; lower degree → tolerate docs-leaning winners more. Do not invent.",
            default => 'Keep every claim supported by docs OR allowlisted web; never strip web-backed enrichment present in SOURCE MATERIAL. '
                . "Enrichment strength was {$lean} in the first pass — do not undo that blend. "
                . 'OK only when fully sourced; rewrite inventions. Do not invent.',
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
