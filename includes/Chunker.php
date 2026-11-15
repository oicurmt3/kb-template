<?php
declare(strict_types=1);

class Chunker {
    private int $maxChars;
    private int $overlap;
    /** Soft limit before splitting a pre-marked RAG chunk further */
    private int $markedMaxChars;

    public function __construct(int $maxChars = 3200, int $overlap = 200, int $markedMaxChars = 8000) {
        $this->maxChars = max(500, $maxChars);
        $this->overlap = max(0, min($overlap, (int)floor($this->maxChars / 4)));
        $this->markedMaxChars = max($this->maxChars, $markedMaxChars);
    }

    /**
     * Split markdown into chunks. Prefers <!-- RAG:CHUNK --> markers when present.
     * @return list<array{chunk_key:string,chunk_index:int,text:string,header_path:?string,token_count:int,search_text:string,metadata:?array}>
     */
    public function chunkMarkdown(string $markdown, string $docTitle = ''): array {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = trim($markdown);
        if ($markdown === '') return [];

        $marked = $this->extractMarkedChunks($markdown, $docTitle);
        if ($marked !== []) {
            return $marked;
        }

        // Strip YAML front matter for generic chunking
        $body = $this->stripFrontMatter($markdown);
        return $this->chunkByHeaders($body, $docTitle);
    }

    /**
     * Parse <!-- RAG:CHUNK ... --> ... <!-- /RAG:CHUNK --> blocks.
     * @return list<array>
     */
    private function extractMarkedChunks(string $markdown, string $docTitle): array {
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

            // Keep marker body as-is (includes Context line) — best for retrieval
            $pieces = mb_strlen($body) > $this->markedMaxChars
                ? $this->splitLarge($body, $this->markedMaxChars)
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

                $searchExtra = trim(implode(' ', array_filter([
                    $docTitle,
                    $headerPath,
                    $part,
                    $division,
                    $ref,
                    $title,
                    $id,
                ])));

                $out[] = [
                    'chunk_key'   => $key,
                    'chunk_index' => $index,
                    'text'        => $piece,
                    'header_path' => $headerPath !== '' ? $headerPath : null,
                    'token_count' => $this->estimateTokens($piece),
                    'search_text' => $this->searchText($searchExtra, '', $piece),
                    'metadata'    => $meta !== [] ? $meta : null,
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
        // unquoted chars=88 style
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
    private function chunkByHeaders(string $markdown, string $docTitle): array {
        $sections = $this->splitByHeaders($markdown);
        $out = [];
        $index = 0;

        foreach ($sections as $sec) {
            $headerPath = $sec['path'];
            $body = trim($sec['body']);
            // Drop leftover HTML comments
            $body = trim(preg_replace('/<!--.*?-->/s', '', $body) ?? $body);
            if ($body === '') continue;

            $pieces = $this->splitLarge($body, $this->maxChars);
            foreach ($pieces as $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                $text = $headerPath !== ''
                    ? "## {$headerPath}\n\n{$piece}"
                    : $piece;
                if ($docTitle !== '' && $headerPath === '') {
                    $text = "# {$docTitle}\n\n{$piece}";
                }
                $search = $this->searchText($docTitle, $headerPath, $piece);
                $out[] = [
                    'chunk_key'   => 'c' . $index,
                    'chunk_index' => $index,
                    'text'        => $text,
                    'header_path' => $headerPath !== '' ? $headerPath : ($docTitle !== '' ? $docTitle : null),
                    'token_count' => $this->estimateTokens($text),
                    'search_text' => $search,
                    'metadata'    => ['source' => 'header_split'],
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

    /** @return list<string> */
    private function splitLarge(string $text, ?int $maxChars = null): array {
        $limit = $maxChars ?? $this->maxChars;
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

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
                if ($this->overlap > 0 && $current !== '') {
                    $tail = mb_substr($current, -$this->overlap);
                    $current = trim($tail) . "\n\n" . $p;
                    if (mb_strlen($current) > $limit) {
                        $current = $p;
                    }
                } else {
                    $current = $p;
                }
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

    private function searchText(string $title, string $headerPath, string $body): string {
        $parts = array_filter([$title, $headerPath, $body]);
        $raw = implode("\n", $parts);
        $raw = mb_strtolower($raw);
        // Keep letters/numbers; drop HTML comment leftovers
        $raw = preg_replace('/<!--.*?-->/s', ' ', $raw) ?? $raw;
        $raw = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $raw) ?? $raw;
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        return trim($raw);
    }

    private function estimateTokens(string $text): int {
        return (int)max(1, (int)ceil(mb_strlen($text) / 4));
    }
}
