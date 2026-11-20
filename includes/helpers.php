<?php
declare(strict_types=1);

function json_out($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): never {
    json_out(['error' => $message], $status);
}

/**
 * Send JSON as a file download (admin exports).
 * @param mixed $data
 */
function json_download($data, string $filename, bool $pretty = true): never {
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.json';
    if (!str_ends_with(strtolower($filename), '.json')) {
        $filename .= '.json';
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo json_encode($data, $flags);
    exit;
}

/** App URL prefix when installed in a subdirectory (e.g. /wcima). Empty at domain root. */
function app_base(): string {
    static $base = null;
    if ($base !== null) return $base;

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($script);
    if (str_ends_with($dir, '/api')) {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        $base = '';
    } else {
        $base = rtrim($dir, '/');
    }
    return $base;
}

/** Build a site-absolute URL under the app base. */
function url(string $path = ''): string {
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return app_base() === '' ? '/' : app_base() . '/';
    }
    return app_base() . '/' . ltrim($path, '/');
}

function cookie_path(): string {
    $base = app_base();
    return $base === '' ? '/' : $base . '/';
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return preg_match('/^[a-fA-F0-9:.]+$/', $ip) ? $ip : '0.0.0.0';
}

function ensure_session_cookie(): string {
    global $config;
    $name = $config['app']['session_cookie'];
    if (!empty($_COOKIE[$name]) && preg_match('/^[a-f0-9-]{36}$/', $_COOKIE[$name])) {
        return $_COOKIE[$name];
    }
    $sid = bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-' .
           bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' .
           bin2hex(random_bytes(6));
    setcookie($name, $sid, [
        'expires'  => time() + 86400 * 365,
        'path'     => cookie_path(),
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $sid;
    return $sid;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/**
 * Split model output into visible answer + follow-up suggestion questions.
 * @return array{content:string,suggestions:list<string>}
 */
function extract_followups(string $content): array {
    $suggestions = [];
    $cleaned = $content;
    $block = null;

    if (preg_match('/<<<FOLLOWUPS>>>\s*(.*?)\s*<<<ENDFOLLOWUPS>>>/is', $content, $m)) {
        $block = $m[1];
        $cleaned = trim(preg_replace('/<<<FOLLOWUPS>>>\s*.*?\s*<<<ENDFOLLOWUPS>>>/is', '', $content) ?? $content);
    } elseif (preg_match('/<<<FOLLOWUPS>>>\s*([\s\S]*)$/i', $content, $m)) {
        // Model sometimes omits the closing marker
        $block = $m[1];
        $cleaned = trim(substr($content, 0, (int)stripos($content, '<<<FOLLOWUPS>>>')));
    } else {
        $pos = stripos($cleaned, '<<<FOLLOWUPS>>>');
        if ($pos !== false) {
            $cleaned = trim(substr($cleaned, 0, $pos));
        }
    }

    if ($block !== null) {
        $lines = preg_split('/\r\n|\n|\r/', trim($block)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '<<<')) continue;
            $line = preg_replace('/^[\-\*\d\.\)\]]+\s*/', '', $line) ?? $line;
            $line = trim($line, " \t\"'");
            if ($line === '' || mb_strlen($line) < 8) continue;
            if (mb_strlen($line) > 200) $line = mb_substr($line, 0, 200);
            $suggestions[] = $line;
            if (count($suggestions) >= 4) break;
        }
    }

    return ['content' => $cleaned, 'suggestions' => array_values(array_unique($suggestions))];
}

function followups_instruction(): string {
    if (class_exists('AppSettings')) {
        return AppSettings::resolveFollowupsInstruction();
    }
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

/**
 * DeepSeek latest_reminder content (date, location, platform, language).
 * Format: date,weekday,location,platform,language
 * @see https://api-docs.deepseek.com/guides/latest_reminder/
 */
function latest_reminder_content(?array $appCfg = null): string {
    $tzName = (string)($appCfg['timezone'] ?? 'Australia/Perth');
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Australia/Perth');
    }
    $now = new DateTimeImmutable('now', $tz);
    $date = $now->format('Y-m-d');
    $weekday = $now->format('l'); // Monday
    $location = (string)($appCfg['user_location'] ?? 'Your city, Your country');
    $platform = (string)($appCfg['user_platform'] ?? 'Web');
    $language = (string)($appCfg['user_language'] ?? 'English');
    return "{$date},{$weekday},{$location},{$platform},{$language}";
}

/**
 * Normalize document tags from free text or list (comma / semicolon / newline separated).
 * @param list<mixed>|string|null $input
 * @return list<string>
 */
function normalize_document_tags($input): array {
    if ($input === null) return [];
    if (is_array($input)) {
        $parts = $input;
    } else {
        $parts = preg_split('/[,;\n\r|]+/', (string)$input) ?: [];
    }
    $out = [];
    foreach ($parts as $p) {
        $t = trim((string)$p);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t, " \t\"'");
        if ($t === '') continue;
        if (mb_strlen($t) > 40) $t = mb_substr($t, 0, 40);
        // Keep readable casing for display; dedupe case-insensitively
        $key = mb_strtolower($t);
        if (isset($out[$key])) continue;
        $out[$key] = $t;
        if (count($out) >= 20) break;
    }
    return array_values($out);
}

/** Store tags as comma-separated string (or null if empty). */
function document_tags_string($input): ?string {
    $tags = normalize_document_tags($input);
    return $tags === [] ? null : implode(', ', $tags);
}

/**
 * Remove composer UI metadata suffixes formerly stored in user message bubbles
 * (tags, toggles, attachment labels). AI applies those via request flags / system prompt.
 */
function strip_user_message_meta(string $content): string {
    $out = preg_replace(
        '/\n\n\[(?:Attached(?: context)?:[^\]]*|Attached URL:[^\]]*|URL:[^\]]*|Web search on|Deep thinking on|Plain language:[^\]]*|Tags:[^\]]*)\]/iu',
        '',
        $content
    );
    return rtrim($out ?? $content);
}

/**
 * Validate/normalize an http(s) source URL for document citations (or null if empty/invalid).
 */
function normalize_source_url(?string $input): ?string {
    $raw = trim((string)$input);
    if ($raw === '') return null;
    if (mb_strlen($raw) > 500) {
        $raw = mb_substr($raw, 0, 500);
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . ltrim($raw, '/');
    }
    if (!filter_var($raw, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    return $raw;
}

/**
 * Simple per-IP rate limit using storage/ratelimit/.
 * Returns true if allowed; false if limited.
 */
function rate_limit_check(string $bucket, int $max, int $windowSeconds): bool {
    $dir = dirname(__DIR__) . '/storage/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $key = hash('sha256', $bucket . '|' . client_ip());
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $data = ['start' => $now, 'count' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = json_decode($raw ?: '', true);
        if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
            $data = $parsed;
        }
    }
    if (($now - (int)$data['start']) >= $windowSeconds) {
        $data = ['start' => $now, 'count' => 0];
    }
    if ((int)$data['count'] >= $max) {
        return false;
    }
    $data['count'] = (int)$data['count'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}
