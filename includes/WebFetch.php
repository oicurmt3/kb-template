<?php
declare(strict_types=1);

/**
 * DeepSeek Search & Fetch APIs
 * @see https://api-docs.deepseek.com/guides/search
 */
class WebFetch {
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxContextChars;
    private int $searchResults;
    /** @var list<string> */
    private array $allowedHosts;

    public function __construct(array $webCfg = [], array $deepseekCfg = []) {
        $this->apiKey = (string)($deepseekCfg['api_key'] ?? '');
        $this->baseUrl = rtrim((string)($deepseekCfg['base_url'] ?? 'https://api.deepseek.com'), '/');
        $this->timeout = max(5, (int)($webCfg['timeout'] ?? 30));
        $this->maxContextChars = max(1000, (int)($webCfg['max_context_chars'] ?? 12000));
        $this->searchResults = max(1, min(50, (int)($webCfg['search_results'] ?? 5)));
        $hosts = $webCfg['allowed_hosts'] ?? ['www.example.com'];
        $this->allowedHosts = [];
        if (is_array($hosts)) {
            foreach ($hosts as $h) {
                $h = strtolower(trim((string)$h));
                if ($h !== '') $this->allowedHosts[] = $h;
            }
        }
        if ($this->allowedHosts === []) {
            $this->allowedHosts = ['www.example.com'];
        }
    }

    /** Primary host used for site: search scoping. */
    public function primaryHost(): string {
        return $this->allowedHosts[0];
    }

    /** @return list<string> */
    public function allowedHosts(): array {
        return $this->allowedHosts;
    }

    /**
     * Allow exact host or a subdomain of an allowed host (e.g. www.workcover.wa.gov.au).
     */
    public function isAllowedUrl(string $url): bool {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) return false;
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') return false;
        foreach ($this->allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch a page via DeepSeek Fetch API (Markdown content).
     * @return array{ok:bool,url:string,title:?string,text:string,error:?string}
     */
    public function fetchUrl(string $url): array {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'url' => $url, 'title' => null, 'text' => '', 'error' => 'URL must start with http:// or https://'];
        }
        if (!$this->isAllowedUrl($url)) {
            $hosts = implode(', ', $this->allowedHosts);
            return [
                'ok' => false,
                'url' => $url,
                'title' => null,
                'text' => '',
                'error' => 'URL host not allowed. Only pages on: ' . $hosts,
            ];
        }
        if ($this->apiKey === '' || str_contains($this->apiKey, 'REPLACE')) {
            return ['ok' => false, 'url' => $url, 'title' => null, 'text' => '', 'error' => 'DeepSeek API key not configured'];
        }

        $resp = $this->apiPost('/v1/tools/fetch', ['url' => $url]);
        if (!$resp['ok']) {
            return ['ok' => false, 'url' => $url, 'title' => null, 'text' => '', 'error' => $resp['error']];
        }

        $data = $resp['data'];
        $title = trim((string)($data['title'] ?? ''));
        $text = trim((string)($data['content'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'url' => $url, 'title' => $title !== '' ? $title : null, 'text' => '', 'error' => 'Empty page content'];
        }
        if (mb_strlen($text) > $this->maxContextChars) {
            $text = mb_substr($text, 0, $this->maxContextChars) . "\n…";
        }
        return [
            'ok' => true,
            'url' => $url,
            'title' => $title !== '' ? $title : null,
            'text' => $text,
            'error' => null,
        ];
    }

    /**
     * Search the web via DeepSeek Search API, scoped to allowed hosts.
     * @return array{ok:bool,query:string,results:list<array{title:string,url:string,snippet:string}>,text:string,error:?string}
     */
    public function search(string $query, ?string $oriQuestion = null): array {
        $baseQuery = trim($query);
        if ($baseQuery === '') {
            return ['ok' => false, 'query' => '', 'results' => [], 'text' => '', 'error' => 'Empty query'];
        }
        $baseQuery = preg_replace('/\bsite:\S+\s*/i', '', $baseQuery) ?? $baseQuery;
        $baseQuery = trim($baseQuery);
        if ($this->apiKey === '' || str_contains($this->apiKey, 'REPLACE')) {
            return ['ok' => false, 'query' => $baseQuery, 'results' => [], 'text' => '', 'error' => 'DeepSeek API key not configured'];
        }

        $hostsLabel = implode(', ', $this->allowedHosts);
        $perHostCount = min(50, max(
            $this->searchResults,
            (int)ceil($this->searchResults / max(1, count($this->allowedHosts))) * 2
        ));
        $seenUrls = [];
        $results = [];
        $lastError = null;
        $scopedQuery = $baseQuery;

        foreach ($this->allowedHosts as $host) {
            if (count($results) >= $this->searchResults) break;
            $sitePrefix = 'site:' . $host . ' ';
            $room = max(1, 128 - mb_strlen($sitePrefix));
            $scopedQuery = $sitePrefix . mb_substr($baseQuery, 0, $room);

            $payload = [
                'query' => $scopedQuery,
                'count' => $perHostCount,
                'need_content' => false,
            ];
            if ($oriQuestion !== null) {
                $ori = mb_substr(trim($oriQuestion), 0, 128);
                if ($ori !== '') $payload['ori_question'] = $ori;
            }

            $resp = $this->apiPost('/v1/tools/search', $payload);
            if (!$resp['ok']) {
                $lastError = $resp['error'];
                continue;
            }

            $rawResults = $resp['data']['results'] ?? [];
            if (!is_array($rawResults)) continue;

            foreach ($rawResults as $r) {
                if (!is_array($r)) continue;
                $title = trim((string)($r['title'] ?? ''));
                $url = trim((string)($r['url'] ?? ''));
                if ($url === '' || !$this->isAllowedUrl($url)) continue;
                $key = strtolower($url);
                if (isset($seenUrls[$key])) continue;
                $seenUrls[$key] = true;
                $snippet = trim((string)($r['summary'] ?? $r['snippet'] ?? ''));
                $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
                if (count($results) >= $this->searchResults) break;
            }
        }

        if ($results === []) {
            return [
                'ok' => false,
                'query' => $scopedQuery,
                'results' => [],
                'text' => '',
                'error' => $lastError ?: ('No results on allowed domains: ' . $hostsLabel),
            ];
        }

        $lines = ["Web search (restricted to {$hostsLabel}) for: {$baseQuery}"];
        foreach ($results as $i => $hit) {
            $n = $i + 1;
            $lines[] = "{$n}. {$hit['title']}";
            $lines[] = "   URL: {$hit['url']}";
            if ($hit['snippet'] !== '') $lines[] = "   {$hit['snippet']}";
        }

        $text = implode("\n", $lines);
        if (mb_strlen($text) > $this->maxContextChars) {
            $text = mb_substr($text, 0, $this->maxContextChars) . "\n…";
        }

        return ['ok' => true, 'query' => $scopedQuery, 'results' => $results, 'text' => $text, 'error' => null];
    }

    /**
     * @return array{ok:bool,data:array,error:?string,status:int}
     */
    private function apiPost(string $path, array $payload): array {
        $url = $this->baseUrl . $path;
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'data' => [], 'error' => 'cURL required for DeepSeek Search/Fetch', 'status' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'data' => [], 'error' => 'Network error: ' . $err, 'status' => $status];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => [], 'error' => 'Invalid JSON from DeepSeek (HTTP ' . $status . ')', 'status' => $status];
        }

        if ($status >= 400) {
            $msg = $data['error']['message'] ?? ('DeepSeek API HTTP ' . $status);
            return ['ok' => false, 'data' => $data, 'error' => (string)$msg, 'status' => $status];
        }

        return ['ok' => true, 'data' => $data, 'error' => null, 'status' => $status];
    }

    public static function answerLengthInstruction(string $length): string {
        return match ($length) {
            'short' => 'Answer length: SHORT (hard limit). Reply in at most 4 plain sentences '
                . '(prefer 2–3). Lead with the direct answer. No preamble, no bullet lists, '
                . 'no background history, no unsolicited related topics or “also note” digressions. '
                . 'If unsure from the sources, say so briefly — do not pad.',
            'long' => 'Answer length: LONG. Give a thorough plain-text answer with clear structure in paragraphs. Cover related points when useful.',
            default => 'Answer length: MEDIUM. Reply in one or two short paragraphs. Be clear and complete without padding.',
        };
    }

    /** Closing reminder — place after enrichment so length still wins over HQ/mix verbosity. */
    public static function answerLengthClosingReminder(string $length): string {
        return match ($length) {
            'short' => 'FINAL LENGTH CHECK: the user-visible answer must be SHORT — at most 4 sentences. '
                . 'Ignore any earlier instruction that asked you to be thorough, cover related points, '
                . 'or expand. Completeness means accurate and sourced, not long.',
            'long' => 'FINAL LENGTH CHECK: LONG mode is allowed — keep structure clear; still do not invent.',
            default => 'FINAL LENGTH CHECK: MEDIUM — one or two short paragraphs; no padding.',
        };
    }
}
