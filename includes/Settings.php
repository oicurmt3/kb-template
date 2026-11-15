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

    /** @return list<string> */
    public static function ragWebMixModes(): array {
        return ['complement', 'docs_first', 'web_first', 'compete'];
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

    /** HQ mode: fuller, more precise answers (when the HQ toolbar toggle is on). */
    public static function hqInstruction(): string {
        return "Answer in high-quality mode: be precise and thorough. Use correct legal "
            . "and technical terms when the excerpts support them, name section/regulation "
            . "numbers clearly, and cover important related points, exceptions, and "
            . "definitions. Still write plain text only (no Markdown). Do not invent rules.";
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
     * Extra HQ-check system line so verification respects the same mix.
     * @param array{mode?:string,degree?:int} $mix
     */
    public static function ragWebMixHqHint(array $mix): string {
        $mode = (string)($mix['mode'] ?? self::DEFAULT_RAG_WEB_MODE);
        return match ($mode) {
            'docs_first' => 'When correcting: keep doc-supported claims; keep web-supported claims only where they fill gaps. Do not invent.',
            'web_first' => 'When correcting: keep web-supported claims; keep Act/Regs excerpt claims for legal duties. Do not invent.',
            'compete' => 'When correcting: keep claims from the stronger on-topic side; drop the weaker side rather than forcing a blend. Do not invent.',
            default => 'When correcting: keep every claim supported by docs OR allowlisted web; never strip web-backed enrichment. Do not invent.',
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
