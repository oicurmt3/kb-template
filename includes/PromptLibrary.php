<?php
declare(strict_types=1);

/**
 * Load ASCII prompt templates from includes/prompts/.
 */
class PromptLibrary {
    public static function dir(): string {
        return __DIR__ . '/prompts';
    }

    public static function load(string $id): string {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
        if ($id === '') {
            return '';
        }
        $path = self::dir() . '/' . $id . '.txt';
        if (!is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * Load a chassis prompt and inject domain rules at {{DOMAIN_RULES}}.
     *
     * @param 'general'|'legislation'|'medical' $domain
     */
    public static function withDomain(string $gId, string $domain = 'general'): string {
        $base = self::load($gId);
        if ($base === '') {
            return '';
        }
        $domain = strtolower(trim($domain));
        if (!in_array($domain, ['general', 'legislation', 'medical'], true)) {
            $domain = 'general';
        }
        $block = '';
        if ($domain === 'legislation') {
            $map = [
                'G2_parse' => 'L2_parse',
                'G3_adapt' => 'L3_adapt',
                'G4_chunk' => 'L4_chunk',
                'G5_enrich' => 'L5_enrich',
                'G6_audit' => 'L6_audit',
                'G8_eval' => 'L8_eval',
            ];
            $bid = $map[$gId] ?? '';
            if ($bid !== '') {
                $block = self::load($bid);
            }
        } elseif ($domain === 'medical') {
            $map = [
                'G2_parse' => 'M2_parse',
                'G3_adapt' => 'M3_adapt',
                'G4_chunk' => 'M4_chunk',
                'G5_enrich' => 'M5_enrich',
                'G6_audit' => 'M6_audit',
                'G8_eval' => 'M8_eval',
            ];
            $bid = $map[$gId] ?? '';
            if ($bid !== '') {
                $block = self::load($bid);
            }
        }
        if ($block !== '') {
            $block = "DOMAIN_RULES\n" . $block;
        }
        if (str_contains($base, '{{DOMAIN_RULES}}')) {
            return str_replace('{{DOMAIN_RULES}}', $block, $base);
        }
        return $block !== '' ? ($base . "\n\n" . $block) : $base;
    }

    /** Answering system prompt for a domain (L7/M7 replace G7). */
    public static function answerPrompt(string $domain): string {
        $domain = strtolower(trim($domain));
        if ($domain === 'legislation') {
            $p = self::load('L7_answer');
            if ($p !== '') return $p;
        }
        if ($domain === 'medical') {
            $p = self::load('M7_answer');
            if ($p !== '') return $p;
        }
        return self::load('G7_answer');
    }
}
