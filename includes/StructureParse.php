<?php
declare(strict_types=1);

/**
 * G1 intake + G2 Markdown/sidecar parse helpers (DeepSeek chat completions).
 */
class StructureParse {
    private array $config;
    private DeepSeekClient $client;

    public function __construct(array $config) {
        $this->config = $config;
        $this->client = new DeepSeekClient($config['deepseek'] ?? []);
    }

    public static function hasRealHeadings(string $markdown): bool {
        return (bool)preg_match('/^#{1,6}\s+\S+/m', $markdown)
            && preg_match_all('/^#{1,6}\s+\S+/m', $markdown) >= 2;
    }

    /**
     * @param list<array{index:int,text:string}> $pages
     * @return array{ok:bool,intake:?array,error:?string}
     */
    public function runIntake(string $fileName, array $pages): array {
        $prompt = PromptLibrary::load('G1_intake');
        if ($prompt === '') {
            return ['ok' => false, 'intake' => null, 'error' => 'G1_intake prompt missing'];
        }
        $first = array_slice($pages, 0, 3);
        $body = "FILE_NAME: {$fileName}\n\nFIRST_PAGES:\n";
        foreach ($first as $p) {
            $idx = (int)($p['index'] ?? 0);
            $body .= "--- page {$idx} ---\n" . trim((string)($p['text'] ?? '')) . "\n\n";
        }
        $res = $this->client->chatComplete(
            [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $body],
            ],
            ['max_tokens' => 1200, 'temperature' => 0]
        );
        if (!$res['ok']) {
            return ['ok' => false, 'intake' => null, 'error' => $res['error'] ?? 'intake failed'];
        }
        $json = self::extractJsonObject((string)($res['content'] ?? ''));
        if (!is_array($json)) {
            return ['ok' => false, 'intake' => null, 'error' => 'intake returned invalid JSON'];
        }
        return ['ok' => true, 'intake' => $json, 'error' => null];
    }

    /**
     * @param list<array{index:int,text:string}> $batchPages
     * @param array|null $docInfo
     * @param array|null $state
     * @return array{ok:bool,markdown:string,sidecar:?array,state:?array,error:?string}
     */
    public function runParseBatch(
        array $batchPages,
        ?array $docInfo,
        ?array $state,
        string $domain
    ): array {
        $prompt = PromptLibrary::withDomain('G2_parse', $domain);
        if ($prompt === '') {
            return ['ok' => false, 'markdown' => '', 'sidecar' => null, 'state' => null, 'error' => 'G2_parse prompt missing'];
        }
        $user = "DOC_INFO:\n" . json_encode($docInfo ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nSTATE:\n" . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nPAGES:\n";
        foreach ($batchPages as $p) {
            $idx = (int)($p['index'] ?? 0);
            $user .= "--- page {$idx} ---\n" . trim((string)($p['text'] ?? '')) . "\n\n";
        }
        $res = $this->client->chatComplete(
            [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $user],
            ],
            ['max_tokens' => 8192, 'temperature' => 0]
        );
        if (!$res['ok']) {
            return ['ok' => false, 'markdown' => '', 'sidecar' => null, 'state' => null, 'error' => $res['error'] ?? 'parse failed'];
        }
        $parsed = self::parseDelimitedBlocks((string)($res['content'] ?? ''));
        if ($parsed['markdown'] === '' && $parsed['sidecar'] === null) {
            return ['ok' => false, 'markdown' => '', 'sidecar' => null, 'state' => null, 'error' => 'parse missing delimiters'];
        }
        return [
            'ok' => true,
            'markdown' => $parsed['markdown'],
            'sidecar' => $parsed['sidecar'],
            'state' => $parsed['state'],
            'error' => null,
        ];
    }

    /**
     * @return array{markdown:string,sidecar:?array,state:?array}
     */
    public static function parseDelimitedBlocks(string $raw): array {
        $md = '';
        $sidecar = null;
        $state = null;
        if (preg_match('/@@@MARKDOWN\s*\n(.*?)(?=\n@@@SIDECAR|\z)/s', $raw, $m)) {
            $md = trim($m[1]);
        }
        if (preg_match('/@@@SIDECAR\s*\n(.*?)(?=\n@@@STATE|\z)/s', $raw, $m)) {
            $j = self::extractJsonObject(trim($m[1]));
            if (is_array($j)) $sidecar = $j;
        }
        if (preg_match('/@@@STATE\s*\n(.*)\z/s', $raw, $m)) {
            $j = self::extractJsonObject(trim($m[1]));
            if (is_array($j)) $state = $j;
        }
        return ['markdown' => $md, 'sidecar' => $sidecar, 'state' => $state];
    }

    /**
     * @param list<array> $sidecars
     */
    public static function mergeSidecars(array $sidecars): array {
        $out = [
            'sections' => [],
            'tables' => [],
            'figures' => [],
            'warnings' => [],
            'toc_removed' => false,
        ];
        foreach ($sidecars as $s) {
            if (!is_array($s)) continue;
            foreach (['sections', 'tables', 'figures', 'warnings'] as $k) {
                if (!empty($s[$k]) && is_array($s[$k])) {
                    foreach ($s[$k] as $item) {
                        $out[$k][] = $item;
                    }
                }
            }
            if (!empty($s['toc_removed'])) {
                $out['toc_removed'] = true;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function extractJsonObject(string $text): ?array {
        $text = trim($text);
        if ($text === '') return null;
        if (preg_match('/^```(?:json)?\s*(\{.*\})\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $slice = substr($text, $start, $end - $start + 1);
        $data = json_decode($slice, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Map intake JSON onto document column values.
     * @return array<string,mixed>
     */
    public static function intakeToColumns(array $intake): array {
        $domain = strtolower((string)($intake['domain_set'] ?? 'general'));
        if (!in_array($domain, ['general', 'legislation', 'medical'], true)) {
            $domain = 'general';
        }
        return [
            'domain_set' => $domain,
            'issuer' => self::nullStr($intake['issuer'] ?? null),
            'jurisdiction' => self::nullStr($intake['jurisdiction'] ?? null),
            'doc_version' => self::nullStr($intake['version'] ?? null),
            'publication_date' => self::nullStr($intake['publication_date'] ?? null),
            'as_at_or_review_date' => self::nullStr($intake['as_at_or_review_date'] ?? null),
            'status_text' => self::nullStr($intake['status_text'] ?? null),
            'layout_json' => isset($intake['layout']) ? json_encode($intake['layout'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'intake_json' => json_encode($intake, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'suggested_pages_per_batch' => max(1, min(8, (int)($intake['suggested_pages_per_batch'] ?? 4))),
            'doc_title' => self::nullStr($intake['doc_title'] ?? null),
        ];
    }

    private static function nullStr(mixed $v): ?string {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s !== '' && strtolower($s) !== 'null' ? mb_substr($s, 0, 255) : null;
    }
}
