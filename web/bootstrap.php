<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/private/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing configuration.';
    exit;
}

$config = require $configPath;
$turnstileOverridePath = dirname(__DIR__) . '/private/turnstile.php';
if (is_file($turnstileOverridePath)) {
    $turnstileOverride = require $turnstileOverridePath;
    if (is_array($turnstileOverride)) {
        $config['turnstile'] = array_merge($config['turnstile'] ?? [], array_intersect_key($turnstileOverride, [
            'enabled' => true,
            'site_key' => true,
            'secret_key' => true,
        ]));
    }
}
$marketOverridePath = dirname(__DIR__) . '/private/market.php';
if (is_file($marketOverridePath)) {
    $marketOverride = require $marketOverridePath;
    if (is_array($marketOverride)) {
        $config['market'] = array_merge($config['market'] ?? [], array_intersect_key($marketOverride, [
            'ticker' => true,
        ]));
    }
}
date_default_timezone_set((string)($config['timezone'] ?? 'UTC'));

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_cache_limiter('');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if (!headers_sent() && !str_ends_with($scriptName, '/image.php') && !str_ends_with($scriptName, '/attachment.php')) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-transform, max-age=0');
}

function config_value(string $key, mixed $default = null): mixed
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = config_value('db');
    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_url(string $path = ''): string
{
    $base = rtrim((string)config_value('base_url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset_version(): string
{
    return (string)config_value('asset_version', 'open-7');
}

function site_theme(): string
{
    $theme = $_COOKIE['site_theme'] ?? $_SESSION['site_theme'] ?? 'dark';
    return $theme === 'light' ? 'light' : 'dark';
}

function set_site_theme(string $theme): void
{
    $theme = $theme === 'light' ? 'light' : 'dark';
    $_SESSION['site_theme'] = $theme;
    setcookie('site_theme', $theme, [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function theme_class(): string
{
    return 'theme-' . site_theme();
}

function opposite_theme(): string
{
    return site_theme() === 'light' ? 'dark' : 'light';
}

function theme_toggle_url(): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    $params = $_GET;
    $params['theme'] = opposite_theme();
    $query = http_build_query($params);
    return $path . ($query !== '' ? '?' . $query : '');
}

function turnstile_settings_path(): string
{
    return dirname(__DIR__) . '/private/turnstile.php';
}

function save_turnstile_settings(bool $enabled, string $siteKey, string $secretKey): void
{
    $settings = [
        'enabled' => $enabled,
        'site_key' => $siteKey,
        'secret_key' => $secretKey,
    ];
    $body = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return " . var_export($settings, true) . ";\n";
    $path = turnstile_settings_path();
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        throw new RuntimeException('Turnstile 设置保存失败。');
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Turnstile 设置保存失败。');
    }
}

function market_settings_path(): string
{
    return dirname(__DIR__) . '/private/market.php';
}

function market_cache_path(string $ticker): string
{
    $safe = preg_replace('/[^A-Z0-9._=-]+/', '-', strtoupper($ticker)) ?: 'NVDA';
    return dirname(__DIR__) . '/private/market-cache-' . $safe . '.json';
}

function normalize_ticker(string $ticker): string
{
    $ticker = strtoupper(trim($ticker));
    $ticker = preg_replace('/[^A-Z0-9.^=_-]+/', '', $ticker) ?? '';
    return $ticker !== '' ? mb_substr($ticker, 0, 24) : 'NVDA';
}

function save_market_settings(string $ticker): void
{
    $settings = ['ticker' => normalize_ticker($ticker)];
    $body = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return " . var_export($settings, true) . ";\n";
    $path = market_settings_path();
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        throw new RuntimeException('Market settings could not be saved.');
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Market settings could not be saved.');
    }
}

function current_market_ticker(): string
{
    return normalize_ticker((string)config_value('market.ticker', 'NVDA'));
}

function current_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? parse_url(site_url(), PHP_URL_HOST);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme . '://' . $host . $uri;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        echo 'CSRF token mismatch.';
        exit;
    }
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function cloudflare_ip_ranges(): array
{
    return [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$range, $prefix] = array_pad(explode('/', $cidr, 2), 2, '');
    $ipPacked = @inet_pton($ip);
    $rangePacked = @inet_pton($range);
    if ($ipPacked === false || $rangePacked === false || strlen($ipPacked) !== strlen($rangePacked)) {
        return false;
    }

    $prefixLength = max(0, min((int)$prefix, strlen($ipPacked) * 8));
    $fullBytes = intdiv($prefixLength, 8);
    $remainingBits = $prefixLength % 8;
    if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($rangePacked, 0, $fullBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($ipPacked[$fullBytes]) & $mask) === (ord($rangePacked[$fullBytes]) & $mask);
}

function is_cloudflare_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    foreach (cloudflare_ip_ranges() as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

function client_ip_address(): string
{
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $cfConnectingIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));

    if (
        $cfConnectingIp !== ''
        && filter_var($cfConnectingIp, FILTER_VALIDATE_IP)
        && ($remoteAddr === '' || is_cloudflare_ip($remoteAddr))
    ) {
        return $cfConnectingIp;
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '';
}

function client_ip_binary(): ?string
{
    $ip = client_ip_address();
    $packed = @inet_pton($ip);
    return $packed === false ? null : $packed;
}

function ip_binary_to_text(mixed $binary): string
{
    if (!is_string($binary) || $binary === '') {
        return '';
    }
    $ip = @inet_ntop($binary);
    return is_string($ip) ? $ip : '';
}

function request_user_agent(): string
{
    return clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
}

function request_browser_language(): string
{
    $value = clean_text($_POST['browser_language'] ?? '', 120);
    return $value !== '' ? $value : clean_text($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 120);
}

function request_client_timezone(): string
{
    return clean_text($_POST['client_timezone'] ?? '', 80);
}

function clean_text(?string $value, int $max): string
{
    $value = trim((string)$value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }
    return $value;
}

function clean_body(?string $value): string
{
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if (mb_strlen($value) > 12000) {
        $value = mb_substr($value, 0, 12000);
    }
    return $value;
}

function reserved_names(): array
{
    $names = config_value('reserved_names', []);
    return is_array($names) ? array_values(array_filter(array_map('strval', $names))) : [];
}

function normalized_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\s_-]+/u', '', $name) ?? $name;
    return mb_strtolower($name);
}

function is_reserved_name(string $name): bool
{
    $normalized = normalized_name($name);
    if ($normalized === '') {
        return false;
    }
    foreach (reserved_names() as $reserved) {
        if ($normalized === normalized_name($reserved)) {
            return true;
        }
    }
    return false;
}

function public_author_name(string $name): string
{
    $name = clean_text($name, 80);
    return is_reserved_name($name) ? clean_text(config_value('reserved_name_replacement', 'ReservedName'), 80) : $name;
}

function display_name(?string $name, string $fallback = 'Anonymous'): string
{
    $name = public_author_name((string)$name);
    if ($name === '') {
        return $fallback;
    }
    return $name;
}

function display_initial(?string $name): string
{
    return mb_substr(display_name($name), 0, 1);
}

function render_body(string $body): string
{
    $escaped = h($body);
    $escaped = preg_replace_callback('/(^|[\s>])#([\p{L}\p{N}_\x{4e00}-\x{9fff}-]{1,40})/u', function (array $m): string {
        $tag = $m[2];
        return $m[1] . '<a class="tag inline" href="/?tag=' . rawurlencode($tag) . '">#' . h($tag) . '</a>';
    }, $escaped) ?? $escaped;
    return nl2br($escaped);
}

function extract_tags(string $body, string $manualTags): array
{
    $tags = [];
    if (preg_match_all('/#([\p{L}\p{N}_\x{4e00}-\x{9fff}-]{1,40})/u', $body . ' ' . $manualTags, $matches)) {
        foreach ($matches[1] as $tag) {
            $tag = trim($tag, "# \t\n\r\0\x0B");
            if ($tag !== '') {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }
    }
    foreach (preg_split('/[,，\s]+/u', $manualTags) ?: [] as $tag) {
        $tag = trim($tag, "# \t\n\r\0\x0B");
        if ($tag !== '') {
            $tags[mb_strtolower($tag)] = $tag;
        }
    }
    return array_slice(array_values($tags), 0, 12);
}

function tag_slug(string $tag): string
{
    $tag = mb_strtolower($tag);
    $tag = preg_replace('/[^\p{L}\p{N}_\x{4e00}-\x{9fff}-]+/u', '-', $tag) ?? $tag;
    return trim($tag, '-');
}

function upsert_tags(int $postId, array $tags): void
{
    $pdo = db();
    $insertTag = $pdo->prepare('INSERT INTO hashtags (tag, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE tag = VALUES(tag)');
    $selectTag = $pdo->prepare('SELECT id FROM hashtags WHERE slug = ?');
    $linkTag = $pdo->prepare('INSERT IGNORE INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)');
    foreach ($tags as $tag) {
        $slug = tag_slug($tag);
        if ($slug === '') {
            continue;
        }
        $insertTag->execute([$tag, $slug]);
        $selectTag->execute([$slug]);
        $id = (int)$selectTag->fetchColumn();
        if ($id > 0) {
            $linkTag->execute([$postId, $id]);
        }
    }
}

function make_bot_challenge(): string
{
    $token = bin2hex(random_bytes(24));
    $_SESSION['bot_challenge'] = [
        'hash' => hash('sha256', $token),
        'created_at' => time(),
    ];
    return $token;
}

function require_bot_challenge(): bool
{
    $challenge = $_SESSION['bot_challenge'] ?? null;
    unset($_SESSION['bot_challenge']);
    $sent = trim((string)($_POST['bot_token'] ?? ''));
    $verified = (string)($_POST['bot_verified'] ?? '') === '1';
    if (!is_array($challenge) || $sent === '' || !$verified) {
        return false;
    }
    $age = time() - (int)($challenge['created_at'] ?? 0);
    if ($age < 1 || $age > 1800) {
        return false;
    }
    return hash_equals((string)$challenge['hash'], hash('sha256', $sent));
}

function turnstile_enabled(): bool
{
    return (bool)config_value('turnstile.enabled', false)
        && (string)config_value('turnstile.site_key', '') !== ''
        && (string)config_value('turnstile.secret_key', '') !== '';
}

function turnstile_site_key(): string
{
    return (string)config_value('turnstile.site_key', '');
}

function require_human_verification(): bool
{
    if (!turnstile_enabled()) {
        return require_bot_challenge();
    }

    $token = trim((string)($_POST['cf-turnstile-response'] ?? ''));
    if ($token === '') {
        return false;
    }

    $payload = [
        'secret' => (string)config_value('turnstile.secret_key', ''),
        'response' => $token,
        'remoteip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
                'timeout' => 6,
            ],
        ]);
        $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    }

    if (!is_string($response) || $response === '') {
        return false;
    }
    $result = json_decode($response, true);
    return is_array($result) && !empty($result['success']);
}

function http_json(string $url, int $timeout = 6): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 OpenMessageBoard/1.0', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status >= 400) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 OpenMessageBoard/1.0\r\nAccept: application/json\r\n",
                'timeout' => $timeout,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            return null;
        }
    }

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function build_sparkline_points(array $values): string
{
    $values = array_values(array_filter($values, static fn($value) => is_numeric($value)));
    $count = count($values);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return '0,50 100,50';
    }
    $min = min($values);
    $max = max($values);
    $range = max(0.0001, $max - $min);
    $points = [];
    foreach ($values as $index => $value) {
        $x = ($index / ($count - 1)) * 100;
        $y = 92 - ((($value - $min) / $range) * 76);
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    return implode(' ', $points);
}

function yahoo_market_quote(?string $ticker = null): array
{
    $ticker = normalize_ticker($ticker ?: current_market_ticker());
    $cacheSeconds = max(60, (int)config_value('market.cache_seconds', 300));
    $cachePath = market_cache_path($ticker);
    if (is_file($cachePath) && time() - filemtime($cachePath) < $cacheSeconds) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached + ['stale' => false];
        }
    }

    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($ticker) . '?range=1mo&interval=1d';
    $json = http_json($url);
    $result = $json['chart']['result'][0] ?? null;
    if (is_array($result)) {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $closes = array_values(array_filter($quote['close'] ?? [], static fn($value) => is_numeric($value)));
        $price = isset($meta['regularMarketPrice']) ? (float)$meta['regularMarketPrice'] : (float)($closes[count($closes) - 1] ?? 0);
        $previous = isset($meta['previousClose']) ? (float)$meta['previousClose'] : (isset($meta['chartPreviousClose']) ? (float)$meta['chartPreviousClose'] : null);
        if ($previous === null && count($closes) > 1) {
            $previous = (float)$closes[count($closes) - 2];
        }
        $change = $previous && $previous > 0 ? $price - $previous : 0.0;
        $payload = [
            'ticker' => $ticker,
            'price' => $price,
            'currency' => (string)($meta['currency'] ?? 'USD'),
            'exchange' => (string)($meta['exchangeName'] ?? ''),
            'change' => $change,
            'change_percent' => $previous && $previous > 0 ? ($change / $previous) * 100 : 0.0,
            'sparkline' => build_sparkline_points($closes),
            'as_of' => isset($meta['regularMarketTime']) ? date('Y-m-d H:i', (int)$meta['regularMarketTime']) : date('Y-m-d H:i'),
            'error' => '',
            'stale' => false,
        ];
        @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $payload;
    }

    if (is_file($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) {
            $cached['stale'] = true;
            $cached['error'] = 'Yahoo Finance is temporarily unavailable; showing cached data.';
            return $cached;
        }
    }

    return [
        'ticker' => $ticker,
        'price' => 0.0,
        'currency' => 'USD',
        'exchange' => '',
        'change' => 0.0,
        'change_percent' => 0.0,
        'sparkline' => '',
        'as_of' => '',
        'error' => 'Yahoo Finance is temporarily unavailable.',
        'stale' => false,
    ];
}

function keywords_for(?array $post = null, array $tags = []): string
{
    $keywords = config_value('default_keywords', []);
    if ($post && !empty($post['seo_keywords'])) {
        foreach (preg_split('/[,，\n]+/u', (string)$post['seo_keywords']) ?: [] as $kw) {
            $kw = trim($kw);
            if ($kw !== '') {
                array_unshift($keywords, $kw);
            }
        }
    }
    foreach ($tags as $tag) {
        $tag = trim((string)$tag);
        if ($tag === '') {
            continue;
        }
        $keywords[] = '#' . $tag;
        $keywords[] = $tag;
    }
    $keywords = array_values(array_unique(array_filter($keywords)));
    return h(implode(', ', array_slice($keywords, 0, 32)));
}

function send_mail_message(string $to, string $subject, string $body): bool
{
    $from = (string)config_value('mail.from', 'noreply@example.com');
    $fromName = mb_encode_mimeheader((string)config_value('mail.from_name', 'Open Message Board'), 'UTF-8');
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $from . '>',
    ];
    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}

function email_link_notice(string $email, string $verifyToken, string $deleteToken, int $postId): void
{
    $verifyUrl = site_url('/verify.php?post=' . $postId . '&token=' . rawurlencode($verifyToken));
    $deleteUrl = site_url('/delete.php?post=' . $postId . '&token=' . rawurlencode($deleteToken));
    $body = "你在“" . config_value('site_name') . "”发布了一条留言。\n\n"
        . "验证邮箱：{$verifyUrl}\n\n"
        . "邮箱验证后，如需自行删除留言，可以使用这个链接：{$deleteUrl}\n\n"
        . "如果不是你提交的，可以忽略这封邮件。";
    if (!send_mail_message($email, '验证你的留言邮箱 - ' . config_value('site_name'), $body)) {
        error_log('Mail send failed for post ' . $postId . ' to ' . $email);
    }
}

function load_post(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM posts WHERE id = ? AND status = 'published'");
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    return $post ?: null;
}

function load_post_tags(int $id): array
{
    $stmt = db()->prepare('SELECT h.tag FROM hashtags h INNER JOIN post_hashtags ph ON ph.hashtag_id = h.id WHERE ph.post_id = ? ORDER BY h.tag');
    $stmt->execute([$id]);
    return array_map(static fn($row) => $row['tag'], $stmt->fetchAll());
}

function load_post_images(int $id): array
{
    $stmt = db()->prepare('SELECT id, original_name, mime_type, width, height FROM images WHERE post_id = ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function load_post_attachments(int $postId): array
{
    $stmt = db()->prepare("SELECT id, kind, original_name, mime_type, byte_size, width, height
        FROM attachments
        WHERE post_id = ? AND comment_id IS NULL
        ORDER BY id");
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

function load_comment_attachments(array $commentIds): array
{
    $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds))));
    if (!$commentIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $stmt = db()->prepare("SELECT id, post_id, comment_id, kind, original_name, mime_type, byte_size, width, height
        FROM attachments
        WHERE comment_id IN ($placeholders)
        ORDER BY id");
    $stmt->execute($commentIds);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int)$row['comment_id']][] = $row;
    }
    return $grouped;
}

function load_comments(int $postId): array
{
    $stmt = db()->prepare("SELECT c.*, parent.author_name AS parent_author_name
        FROM comments c
        LEFT JOIN comments parent ON parent.id = c.parent_id AND parent.status = 'published'
        WHERE c.post_id = ? AND c.status = 'published'
        ORDER BY c.created_at ASC, c.id ASC");
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

function create_comment(int $postId, ?int $parentId, string $body, string $authorName, string $authorEmail): int
{
    $post = load_post($postId);
    if (!$post) {
        throw new RuntimeException('留言不存在。');
    }
    if ($parentId !== null && $parentId > 0) {
        $parent = db()->prepare("SELECT id FROM comments WHERE id = ? AND post_id = ? AND status = 'published'");
        $parent->execute([$parentId, $postId]);
        if (!$parent->fetchColumn()) {
            $parentId = null;
        }
    } else {
        $parentId = null;
    }

    $stmt = db()->prepare('INSERT INTO comments (post_id, parent_id, body, author_name, author_email, created_ip, user_agent, client_timezone, browser_language) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $postId,
        $parentId,
        $body,
        $authorName ?: null,
        $authorEmail ?: null,
        client_ip_binary(),
        request_user_agent(),
        request_client_timezone() ?: null,
        request_browser_language() ?: null,
    ]);
    return (int)db()->lastInsertId();
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_ok']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('/admin.php');
    }
}

function admin_check(string $username, string $password): bool
{
    $expectedUser = (string)config_value('admin.username', 'admin');
    $hash = (string)config_value('admin.password_hash', '');
    return hash_equals($expectedUser, $username) && $hash !== '' && password_verify($password, $hash);
}

function delete_post(int $postId, string $actor): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE posts SET status = 'deleted', deleted_at = NOW() WHERE id = ? AND status = 'published'");
        $stmt->execute([$postId]);
        if ($stmt->rowCount() > 0) {
            $log = $pdo->prepare('INSERT INTO moderation_log (post_id, action, actor, ip) VALUES (?, ?, ?, ?)');
            $log->execute([$postId, 'delete', $actor, client_ip_binary()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_comment(int $commentId, string $actor): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare("SELECT post_id FROM comments WHERE id = ? AND status = 'published'");
        $lookup->execute([$commentId]);
        $postId = (int)$lookup->fetchColumn();
        if ($postId > 0) {
            $stmt = $pdo->prepare("UPDATE comments SET status = 'deleted', deleted_at = NOW() WHERE id = ? AND status = 'published'");
            $stmt->execute([$commentId]);
            if ($stmt->rowCount() > 0) {
                $log = $pdo->prepare('INSERT INTO moderation_log (post_id, action, actor, ip) VALUES (?, ?, ?, ?)');
                $log->execute([$postId, 'comment-delete', $actor, client_ip_binary()]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function save_images(int $postId, array $files): void
{
    $uploadDir = (string)config_value('uploads_dir');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) {
        throw new RuntimeException('Cannot create upload directory.');
    }

    $allowed = config_value('allowed_image_mimes', []);
    $maxBytes = (int)config_value('max_image_bytes', 5242880);
    $maxImages = (int)config_value('max_images', 8);
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    $count = min($count, $maxImages);
    $insert = db()->prepare('INSERT INTO images (post_id, stored_name, original_name, mime_type, byte_size, width, height) VALUES (?, ?, ?, ?, ?, ?, ?)');

    for ($i = 0; $i < $count; $i++) {
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('有图片上传失败，请压缩后重试。');
        }
        $tmp = $files['tmp_name'][$i] ?? '';
        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0 || $size > $maxBytes || !is_uploaded_file($tmp)) {
            throw new RuntimeException('图片大小超过限制，单张最多 5MB。');
        }
        $dimensions = @getimagesize($tmp);
        if ($dimensions === false) {
            throw new RuntimeException('图片文件无法识别。');
        }
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = mime_content_type($tmp) ?: '';
        }
        if ($mime === '' && !empty($dimensions['mime'])) {
            $mime = (string)$dimensions['mime'];
        }
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('只允许上传 JPG、PNG、GIF、WebP 图片。');
        }
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $stored = $postId . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $uploadDir . '/' . $stored;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('图片保存失败。');
        }
        @chmod($target, 0640);
        $original = clean_text((string)($files['name'][$i] ?? 'image'), 180);
        $insert->execute([$postId, $stored, $original, $mime, $size, (int)$dimensions[0], (int)$dimensions[1]]);
    }
}

function normalized_uploads(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        return [[
            'name' => $files['name'] ?? '',
            'type' => $files['type'] ?? '',
            'tmp_name' => $files['tmp_name'] ?? '',
            'size' => $files['size'] ?? 0,
            'error' => $files['error'] ?? UPLOAD_ERR_NO_FILE,
        ]];
    }
    $items = [];
    foreach ($names as $index => $name) {
        $items[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'size' => $files['size'][$index] ?? 0,
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        ];
    }
    return $items;
}

function has_uploads(mixed $files): bool
{
    if (!is_array($files)) {
        return false;
    }
    foreach (normalized_uploads($files) as $item) {
        if ((int)($item['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }
    return false;
}

function split_uploads_by_kind(array $files): array
{
    $groups = ['image' => [], 'pdf' => []];
    foreach (normalized_uploads($files) as $item) {
        $error = (int)($item['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $name = strtolower((string)($item['name'] ?? ''));
        $type = strtolower((string)($item['type'] ?? ''));
        $kind = null;
        if ($type === 'application/pdf' || str_ends_with($name, '.pdf')) {
            $kind = 'pdf';
        } elseif (str_starts_with($type, 'image/') || preg_match('/\.(jpe?g|png|gif|webp)$/', $name)) {
            $kind = 'image';
        }
        if ($kind !== null) {
            $groups[$kind][] = $item;
        }
    }

    $build = static function (array $items): array {
        $result = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($items as $item) {
            foreach ($result as $key => $_) {
                $result[$key][] = $item[$key] ?? ($key === 'error' ? UPLOAD_ERR_NO_FILE : '');
            }
        }
        return $result;
    };

    return [
        'images' => $build($groups['image']),
        'pdfs' => $build($groups['pdf']),
        'has_images' => count($groups['image']) > 0,
        'has_pdfs' => count($groups['pdf']) > 0,
    ];
}

function detect_upload_mime(string $tmp): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if ($mime !== '') {
            return $mime;
        }
    }
    if (function_exists('mime_content_type')) {
        return mime_content_type($tmp) ?: '';
    }
    return '';
}

function save_attachments(int $postId, ?int $commentId, array $files, string $kind): void
{
    $uploadDir = (string)config_value('uploads_dir');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) {
        throw new RuntimeException('Cannot create upload directory.');
    }

    $isPdf = $kind === 'pdf';
    $allowed = $isPdf ? config_value('allowed_pdf_mimes', ['application/pdf']) : config_value('allowed_image_mimes', []);
    $maxBytes = $isPdf ? (int)config_value('max_pdf_bytes', 15728640) : (int)config_value('max_image_bytes', 5242880);
    $maxFiles = $isPdf ? (int)config_value('max_pdfs', 4) : (int)config_value('max_images', 8);
    $items = array_slice(normalized_uploads($files), 0, $maxFiles);
    $insert = db()->prepare('INSERT INTO attachments (post_id, comment_id, kind, stored_name, original_name, mime_type, byte_size, width, height) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    foreach ($items as $item) {
        $error = (int)($item['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($isPdf ? 'PDF 上传失败，请压缩后重试。' : '图片上传失败，请压缩后重试。');
        }
        $tmp = (string)($item['tmp_name'] ?? '');
        $size = (int)($item['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes || !is_uploaded_file($tmp)) {
            throw new RuntimeException($isPdf ? 'PDF 大小超过限制，单个最大 15MB。' : '图片大小超过限制，单张最大 5MB。');
        }

        $width = null;
        $height = null;
        $dimensions = null;
        if (!$isPdf) {
            $dimensions = @getimagesize($tmp);
            if ($dimensions === false) {
                throw new RuntimeException('图片文件无法识别。');
            }
            $width = (int)$dimensions[0];
            $height = (int)$dimensions[1];
        }

        $mime = detect_upload_mime($tmp);
        if ($mime === '' && !$isPdf && !empty($dimensions['mime'])) {
            $mime = (string)$dimensions['mime'];
        }
        if ($isPdf && ($mime === 'application/x-pdf' || $mime === 'application/acrobat')) {
            $mime = 'application/pdf';
        }
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException($isPdf ? '只允许上传 PDF 文件。' : '只允许上传 JPG、PNG、GIF、WebP 图片。');
        }

        $extension = $isPdf ? 'pdf' : match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $prefix = $commentId ? $postId . '-c' . $commentId : (string)$postId;
        $stored = $prefix . '-' . $kind . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
        $target = rtrim($uploadDir, '/') . '/' . $stored;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException($isPdf ? 'PDF 保存失败。' : '图片保存失败。');
        }
        @chmod($target, 0640);
        $original = clean_text((string)($item['name'] ?? ($isPdf ? 'document.pdf' : 'image')), 180);
        $insert->execute([$postId, $commentId, $kind, $stored, $original, $mime, $size, $width, $height]);
    }
}
