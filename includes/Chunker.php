<?php
declare(strict_types=1);

/**
 * Structure-first Markdown chunker (header split → size) with atomic tables/lists/code.
 * Optional RAG:CHUNK markers override automated splitting.
 */
class Chunker {
    private int $maxChars;
    private int $overlap;
    private int $markedMaxChars;
    private int $targetTokens;
    private int $maxTokens;
    /** @var 'general'|'legislation'|'medical' */
    private string $domain;

    public function __construct(
        int $maxChars = 3200,
        int $overlap = 200,
        int $markedMaxChars = 8000,
        int $targetTokens = 800,
        int $maxTokens = 2000,
        string $domain = 'general'
    ) {
        $this->maxChars = max(500, $maxChars);
        $domain = strtolower(trim($domain));
        if (!in_array($domain, ['general', 'legislation', 'medical'], true)) {
            $domain = 'general';
        }
        $this->domain = $domain;
        // Legislation: zero overlap to avoid penalty/exception bleed
        $ov = $domain === 'legislation' ? 0 : $overlap;
        $this->overlap = max(0, min($ov, (int)floor($this->maxChars / 4)));
        $this->markedMaxChars = max($this->maxChars, $markedMaxChars);
        $this->targetTokens = max(200, $targetTokens);
        $this->maxTokens = max($this->targetTokens, $maxTokens);
    }

    /**
     * @param array{
     *   title?:string,issuer?:?string,jurisdiction?:?string,doc_version?:?string,
     *   as_at_or_review_date?:?string,status_text?:?string,domain_set?:string
     * } $docMeta
     * @return list<array{
     *   chunk_key:string,chunk_index:int,text:string,header_path:?string,token_count:int,
     *   search_text:string,metadata:?array,content_type:?string,flags:?array,extra:?array,
     *   start_line:?int,end_line:?int
     * }>
     */
    public function chunkMarkdown(string $markdown, string $docTitle = '', array $docMeta = []): array {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = trim($markdown);
        if ($markdown === '') return [];

        if (!empty($docMeta['domain_set'])) {
            $d = strtolower((string)$docMeta['domain_set']);
            if (in_array($d, ['general', 'legislation', 'medical'], true)) {
                $this->domain = $d;
                if ($d === 'legislation') {
                    $this->overlap = 0;
                }
            }
        }

        $marked = $this->extractMarkedChunks($markdown, $docTitle, $docMeta);
        if ($marked !== []) {
            return $marked;
        }

        $body = $this->stripFrontMatter($markdown);
        return $this->chunkByHeaders($body, $docTitle, $docMeta);
    }

    /**
     * Apply G4-style line ranges to numbered markdown (L0001| lines).
     *
     * @param list<array{chunk_id?:string,start_line:int,end_line:int,section_path?:string,content_type?:string,table_part?:?string,flags?:array,extra?:array}> $ranges
     * @return list<array>
     */
    public function applyLineRanges(string $numberedMarkdown, array $ranges, string $docTitle = '', array $docMeta = []): array {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $numberedMarkdown));
        $byNum = [];
        foreach ($lines as $line) {
            if (preg_match('/^L(\d+)\|\s?(.*)$/', $line, $m)) {
                $byNum[(int)$m[1]] = $m[2];
            }
        }
        if ($byNum === []) {
            return [];
        }

        $out = [];
        $index = 0;
        foreach ($ranges as $r) {
            $start = (int)($r['start_line'] ?? 0);
            $end = (int)($r['end_line'] ?? 0);
            if ($start < 1 || $end < $start) continue;
            $slice = [];
            for ($i = $start; $i <= $end; $i++) {
                if (!isset($byNum[$i])) continue;
                $t = $byNum[$i];
                if (preg_match('/^<!--\s*page:\s*\d+\s*-->\s*$/', trim($t))) {
                    continue;
                }
                $slice[] = $t;
            }
            $text = trim(implode("\n", $slice));
            if ($text === '') continue;

            $tablePart = $r['table_part'] ?? null;
            if (is_string($tablePart) && $tablePart !== '' && preg_match('/^(\d+)\/(\d+)$/', $tablePart, $tm) && (int)$tm[1] > 1) {
                $header = $this->extractTableHeaderRow($text);
                if ($header !== '' && !str_starts_with($text, $header)) {
                    $text = $header . "\n" . $text;
                }
            }

            $path = trim((string)($r['section_path'] ?? ''));
            $flags = is_array($r['flags'] ?? null) ? $r['flags'] : [];
            $extra = is_array($r['extra'] ?? null) ? $r['extra'] : [];
            $ctype = isset($r['content_type']) ? (string)$r['content_type'] : 'text';
            $key = (string)($r['chunk_id'] ?? ('c' . $index));

            $search = self::buildSearchText($text, $path, $docMeta + ['title' => $docTitle]);
            $out[] = [
                'chunk_key'   => $key,
                'chunk_index' => $index,
                'text'        => $text,
                'header_path' => $path !== '' ? $path : ($docTitle !== '' ? $docTitle : null),
                'token_count' => $this->estimateTokens($text),
                'search_text' => $search,
                'metadata'    => ['source' => 'line_range'],
                'content_type'=> $ctype,
                'flags'       => $flags,
                'extra'       => $extra,
                'start_line'  => $start,
                'end_line'    => $end,
            ];
            $index++;
        }
        return $out;
    }

    /** Prefix lines as L0001| for G4. */
    public static function numberLines(string $markdown): string {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $out = [];
        $n = 1;
        foreach ($lines as $line) {
            $out[] = sprintf('L%04d| %s', $n, $line);
            $n++;
        }
        return implode("\n", $out);
    }

    /**
     * Contextual search_text (lexical "embed"). Verbatim display text stays separate.
     *
     * @param array<string,mixed> $docMeta
     * @param list<string> $extraTerms summary/questions/keywords
     */
    public static function buildSearchText(
        string $body,
        string $headerPath = '',
        array $docMeta = [],
        array $extraTerms = []
    ): string {
        $domain = strtolower((string)($docMeta['domain_set'] ?? 'general'));
        $title = trim((string)($docMeta['title'] ?? $docMeta['doc_title'] ?? ''));
        $issuer = trim((string)($docMeta['issuer'] ?? ''));
        $version = trim((string)($docMeta['doc_version'] ?? $docMeta['version'] ?? ''));
        $asAt = trim((string)($docMeta['as_at_or_review_date'] ?? ''));
        $jurisdiction = trim((string)($docMeta['jurisdiction'] ?? ''));
        $instrument = trim((string)($docMeta['instrument'] ?? $title));

        if ($domain === 'legislation') {
            $prefix = trim(sprintf(
                '%s (%s), %s, as at %s',
                $instrument !== '' ? $instrument : 'Instrument',
                $jurisdiction !== '' ? $jurisdiction : 'WA',
                $headerPath !== '' ? $headerPath : 'section',
                $asAt !== '' ? $asAt : ($version !== '' ? $version : 'unknown')
            ));
        } elseif ($domain === 'medical') {
            $prefix = trim(sprintf(
                'Guideline: %s. Issuer: %s. Version: %s. Section: %s.',
                $title !== '' ? $title : 'Untitled',
                $issuer !== '' ? $issuer : 'unknown',
                $version !== '' ? $version : 'unknown',
                $headerPath !== '' ? $headerPath : 'n/a'
            ));
        } else {
            $bits = array_filter([
                $title,
                $issuer !== '' ? '(' . $issuer . ')' : '',
                $version !== '' ? 'version ' . $version : '',
                $headerPath,
            ]);
            $prefix = implode(', ', $bits);
            if ($prefix !== '' && $body !== '') {
                $prefix .= ':';
            }
        }

        $raw = trim($prefix . "\n" . $body);
        if ($extraTerms !== []) {
            $raw .= "\n" . implode(' ', $extraTerms);
        }
        return self::normalizeSearchText($raw);
    }

    public static function normalizeSearchText(string $raw): string {
        $raw = mb_strtolower($raw);
        $raw = preg_replace('/<!--.*?-->/s', ' ', $raw) ?? $raw;
        // Keep letters, numbers, spaces, underscore, hyphen — preserves 12a / s-12 style tokens
        $raw = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $raw) ?? $raw;
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        return trim($raw);
    }

    /** @return list<array> */
    private function extractMarkedChunks(string $markdown, string $docTitle, array $docMeta): array {
        $pattern = '/<!--\s*RAG:CHUNK\b([^>]*)-->(.*?)<!--\s*\/RAG:CHUNK\s*-->/is';
        if (!preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        $index = 0;
        foreach ($matches as $m) {
            $attrs = $this->parseChunkAttrs($m[1]);
            $body = trim($m[2]);
            if ($body === '') continue;

            $context = '';
            if (preg_match('/^Context:\s*(.+)$/mi', $body, $cm)) {
                $context = trim($cm[1]);
            }

            $part = trim((string)($attrs['part'] ?? ''));
            $division = trim((string)($attrs['division'] ?? ''));
            $title = trim((string)($attrs['title'] ?? ''));
            $ref = trim((string)($attrs['ref'] ?? ''));
            $id = trim((string)($attrs['id'] ?? ''));

            $headerPath = $context;
            if ($headerPath === '') {
                $bits = array_filter([$part, $division, $ref !== '' && $title !== '' ? "{$ref}. {$title}" : $title]);
                $headerPath = implode(' > ', $bits);
            }
            if ($headerPath === '' && $docTitle !== '') {
                $headerPath = $docTitle;
            }

            $pieces = mb_strlen($body) > $this->markedMaxChars
                ? $this->splitLargeAtomic($body, $this->markedMaxChars)
                : [$body];

            foreach ($pieces as $pi => $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                $key = $id !== '' ? $id : ('c' . $index);
                if (count($pieces) > 1) {
                    $key .= '_p' . $pi;
                }
                $meta = array_filter([
                    'id'       => $id !== '' ? $id : null,
                    'doc'      => $attrs['doc'] ?? null,
                    'type'     => $attrs['type'] ?? null,
                    'part'     => $part !== '' ? $part : null,
                    'division' => $division !== '' ? $division : null,
                    'ref'      => $ref !== '' ? $ref : null,
                    'title'    => $title !== '' ? $title : null,
                    'source'   => 'rag_marker',
                ], static fn($v) => $v !== null && $v !== '');

                $out[] = [
                    'chunk_key'   => $key,
                    'chunk_index' => $index,
                    'text'        => $piece,
                    'header_path' => $headerPath !== '' ? $headerPath : null,
                    'token_count' => $this->estimateTokens($piece),
                    'search_text' => self::buildSearchText($piece, $headerPath, $docMeta + ['title' => $docTitle]),
                    'metadata'    => $meta !== [] ? $meta : null,
                    'content_type'=> isset($attrs['type']) ? (string)$attrs['type'] : 'text',
                    'flags'       => [],
                    'extra'       => [],
                    'start_line'  => null,
                    'end_line'    => null,
                ];
                $index++;
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function parseChunkAttrs(string $attrBlob): array {
        $attrs = [];
        if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $attrBlob, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) {
                $attrs[$a[1]] = html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        if (preg_match_all('/(\w+)\s*=\s*([^\s"\'>]+)/', $attrBlob, $mm2, PREG_SET_ORDER)) {
            foreach ($mm2 as $a) {
                if (!isset($attrs[$a[1]])) {
                    $attrs[$a[1]] = $a[2];
                }
            }
        }
        return $attrs;
    }

    private function stripFrontMatter(string $markdown): string {
        if (str_starts_with($markdown, '---')) {
            $end = strpos($markdown, "\n---", 3);
            if ($end !== false) {
                $after = substr($markdown, $end + 4);
                return ltrim($after, "\r\n");
            }
        }
        return $markdown;
    }

    /** @return list<array> */
    private function chunkByHeaders(string $markdown, string $docTitle, array $docMeta): array {
        $sections = $this->splitByHeaders($markdown);
        $out = [];
        $index = 0;
        $charLimit = max($this->maxChars, $this->maxTokens * 4);

        foreach ($sections as $sec) {
            $headerPath = $sec['path'];
            $body = trim($sec['body']);
            $body = trim(preg_replace('/<!--.*?-->/s', '', $body) ?? $body);
            if ($body === '') continue;

            $pieces = $this->splitLargeAtomic($body, $charLimit);
            foreach ($pieces as $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                // Keep Markdown structure in stored text; prepend path as ATX only when missing
                $text = $piece;
                if ($headerPath !== '' && !preg_match('/^#{1,6}\s+/m', $piece)) {
                    $text = "## {$headerPath}\n\n{$piece}";
                } elseif ($docTitle !== '' && $headerPath === '' && !preg_match('/^#\s+/m', $piece)) {
                    $text = "# {$docTitle}\n\n{$piece}";
                }
                $out[] = [
                    'chunk_key'   => 'c' . $index,
                    'chunk_index' => $index,
                    'text'        => $text,
                    'header_path' => $headerPath !== '' ? $headerPath : ($docTitle !== '' ? $docTitle : null),
                    'token_count' => $this->estimateTokens($text),
                    'search_text' => self::buildSearchText($piece, $headerPath, $docMeta + ['title' => $docTitle]),
                    'metadata'    => ['source' => 'header_split'],
                    'content_type'=> $this->guessContentType($piece),
                    'flags'       => $this->estimateTokens($text) > $this->maxTokens ? ['oversize'] : [],
                    'extra'       => [],
                    'start_line'  => null,
                    'end_line'    => null,
                ];
                $index++;
            }
        }

        return $out;
    }

    /** @return list<array{path:string,body:string}> */
    private function splitByHeaders(string $markdown): array {
        $lines = explode("\n", $markdown);
        $sections = [];
        $stack = [];
        $buf = [];
        $flush = function () use (&$sections, &$buf, &$stack) {
            $body = trim(implode("\n", $buf));
            $buf = [];
            if ($body === '' && $sections === []) return;
            $path = implode(' > ', array_values(array_filter($stack, static fn($s) => $s !== '')));
            $sections[] = ['path' => $path, 'body' => $body];
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $flush();
                $level = strlen($m[1]);
                $title = trim($m[2]);
                while (count($stack) >= $level) {
                    array_pop($stack);
                }
                while (count($stack) < $level - 1) {
                    $stack[] = '';
                }
                $stack[] = $title;
                continue;
            }
            $buf[] = $line;
        }
        $flush();

        if ($sections === []) {
            return [['path' => '', 'body' => $markdown]];
        }
        return $sections;
    }

    /**
     * Size-split without cutting GFM tables, fenced code, or contiguous list runs mid-unit.
     * @return list<string>
     */
    private function splitLargeAtomic(string $text, ?int $maxChars = null): array {
        $limit = $maxChars ?? $this->maxChars;
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $blocks = $this->splitAtomicBlocks($text);
        $chunks = [];
        $current = '';

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;

            if (mb_strlen($block) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                if ($this->isTableBlock($block)) {
                    foreach ($this->splitTableByRows($block, $limit) as $part) {
                        $chunks[] = $part;
                    }
                } else {
                    foreach ($this->splitByParagraphs($block, $limit) as $part) {
                        $chunks[] = $part;
                    }
                }
                continue;
            }

            $candidate = $current === '' ? $block : $current . "\n\n" . $block;
            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;
            } else {
                $chunks[] = $current;
                if ($this->overlap > 0 && $current !== '') {
                    $tail = mb_substr($current, -$this->overlap);
                    $current = trim($tail) . "\n\n" . $block;
                    if (mb_strlen($current) > $limit) {
                        $current = $block;
                    }
                } else {
                    $current = $block;
                }
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    /** @return list<string> */
    private function splitAtomicBlocks(string $text): array {
        $lines = explode("\n", $text);
        $blocks = [];
        $buf = [];
        $mode = 'prose'; // prose|table|fence|list

        $flush = function () use (&$blocks, &$buf) {
            if ($buf === []) return;
            $blocks[] = implode("\n", $buf);
            $buf = [];
        };

        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);

            if ($mode === 'fence') {
                $buf[] = $line;
                if (preg_match('/^```/', $trim)) {
                    $flush();
                    $mode = 'prose';
                }
                $i++;
                continue;
            }

            if ($mode === 'table') {
                if ($this->isTableLine($line) || $trim === '') {
                    $buf[] = $line;
                    $i++;
                    if ($i < $n && !$this->isTableLine($lines[$i]) && trim($lines[$i]) !== '' && !preg_match('/^Table notes:/i', trim($lines[$i]))) {
                        $flush();
                        $mode = 'prose';
                    }
                    continue;
                }
                if (preg_match('/^Table notes:/i', $trim)) {
                    $buf[] = $line;
                    $i++;
                    while ($i < $n && trim($lines[$i]) !== '' && !$this->isTableLine($lines[$i]) && !preg_match('/^#{1,6}\s+/', $lines[$i])) {
                        $buf[] = $lines[$i];
                        $i++;
                    }
                    $flush();
                    $mode = 'prose';
                    continue;
                }
                $flush();
                $mode = 'prose';
                continue;
            }

            if ($mode === 'list') {
                if ($this->isListLine($line) || ($trim === '' && $i + 1 < $n && $this->isListLine($lines[$i + 1]))) {
                    $buf[] = $line;
                    $i++;
                    continue;
                }
                $flush();
                $mode = 'prose';
                continue;
            }

            // prose
            if (preg_match('/^```/', $trim)) {
                $flush();
                $mode = 'fence';
                $buf[] = $line;
                $i++;
                continue;
            }
            if ($this->isTableLine($line) || preg_match('/^Table\s+\d+/i', $trim)) {
                $flush();
                $mode = 'table';
                $buf[] = $line;
                $i++;
                continue;
            }
            if ($this->isListLine($line)) {
                $flush();
                $mode = 'list';
                $buf[] = $line;
                $i++;
                continue;
            }
            if ($trim === '') {
                $flush();
                $i++;
                continue;
            }
            $buf[] = $line;
            $i++;
        }
        $flush();
        return $blocks;
    }

    private function isTableLine(string $line): bool {
        $t = trim($line);
        return $t !== '' && str_contains($t, '|') && preg_match('/^\|?.+\|/', $t);
    }

    private function isListLine(string $line): bool {
        return (bool)preg_match('/^\s*(?:[-*+]|\d+[.)]|[a-z][.)]|\([a-z0-9]+\))\s+/i', $line);
    }

    private function isTableBlock(string $block): bool {
        $lines = explode("\n", $block);
        $pipe = 0;
        foreach ($lines as $l) {
            if ($this->isTableLine($l)) $pipe++;
        }
        return $pipe >= 2;
    }

    /** @return list<string> */
    private function splitTableByRows(string $block, int $limit): array {
        $lines = explode("\n", $block);
        $caption = [];
        $header = [];
        $rows = [];
        $notes = [];
        $phase = 'pre';
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($phase === 'pre') {
                if ($this->isTableLine($line)) {
                    $phase = 'header';
                    $header[] = $line;
                } else {
                    $caption[] = $line;
                }
                continue;
            }
            if ($phase === 'header') {
                $header[] = $line;
                if (preg_match('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/', $trim) || count($header) >= 2) {
                    $phase = 'body';
                }
                continue;
            }
            if (preg_match('/^Table notes:/i', $trim)) {
                $phase = 'notes';
                $notes[] = $line;
                continue;
            }
            if ($phase === 'notes') {
                $notes[] = $line;
                continue;
            }
            $rows[] = $line;
        }

        $headBlock = trim(implode("\n", array_merge($caption, $header)));
        $noteBlock = trim(implode("\n", $notes));
        if ($rows === []) {
            return [trim($block)];
        }

        $parts = [];
        $buf = [];
        $partNum = 1;
        $flush = function () use (&$parts, &$buf, &$partNum, $headBlock, $noteBlock) {
            if ($buf === []) return;
            $body = $headBlock . "\n" . implode("\n", $buf);
            if ($noteBlock !== '') {
                $body .= "\n" . $noteBlock;
            }
            $parts[] = $body;
            $buf = [];
            $partNum++;
        };

        foreach ($rows as $row) {
            $candidate = $headBlock . "\n" . implode("\n", array_merge($buf, [$row]));
            if (mb_strlen($candidate) > $limit && $buf !== []) {
                $flush();
            }
            $buf[] = $row;
        }
        $flush();
        return $parts !== [] ? $parts : [trim($block)];
    }

    /** @return list<string> */
    private function splitByParagraphs(string $text, int $limit): array {
        $paras = preg_split("/\n{2,}/", $text) ?: [$text];
        $chunks = [];
        $current = '';
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (mb_strlen($p) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->splitBySentences($p, $limit) as $s) {
                    $chunks[] = $s;
                }
                continue;
            }
            $candidate = $current === '' ? $p : $current . "\n\n" . $p;
            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;
            } else {
                $chunks[] = $current;
                $current = $p;
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    /** @return list<string> */
    private function splitBySentences(string $text, int $limit): array {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        $chunks = [];
        $current = '';
        foreach ($parts as $s) {
            $s = trim($s);
            if ($s === '') continue;
            $candidate = $current === '' ? $s : $current . ' ' . $s;
            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;
            } else {
                if ($current !== '') $chunks[] = $current;
                if (mb_strlen($s) <= $limit) {
                    $current = $s;
                } else {
                    $len = mb_strlen($s);
                    for ($i = 0; $i < $len; $i += $limit) {
                        $chunks[] = mb_substr($s, $i, $limit);
                    }
                    $current = '';
                }
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    private function extractTableHeaderRow(string $text): string {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($this->isTableLine($line)) {
                $out = [$line];
                if (isset($lines[$i + 1]) && preg_match('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/', trim($lines[$i + 1]))) {
                    $out[] = $lines[$i + 1];
                }
                return implode("\n", $out);
            }
        }
        return '';
    }

    private function guessContentType(string $text): string {
        if ($this->isTableBlock($text)) return 'table';
        if (preg_match('/^\s*(?:[-*+]|\d+[.)])/m', $text)) return 'list';
        if (preg_match('/\bmeans\b/i', $text) && preg_match('/^#{0,6}\s*.{0,80}$/m', $text)) return 'definition';
        return 'text';
    }

    private function estimateTokens(string $text): int {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $n = is_array($words) ? count($words) : 0;
        return (int)max(1, (int)ceil($n * 1.3));
    }
}
