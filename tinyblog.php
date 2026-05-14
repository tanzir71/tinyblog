<?php
/**
 * TinyBlog Widget backend - PHP 8+ and SQLite, built for shared hosting.
 * Quick deploy: upload this file, tinyblog-widget.js, .htaccess, and uploads/.
 * First run: open /admin and create the first admin; no default password ships.
 * Database: data/tinyblog.db is auto-created on first request if writable.
 * Widget: paste the README embed script and set endpoint to your /api URL.
 * Security: prepared statements, CSRF, session timeout, CORS allowlist, safe uploads.
 * Privacy: no third-party trackers; subscribers store only email and opt-in time.
 * Configure allowed widget origins in Admin -> Settings before embedding elsewhere.
 * Repository: https://github.com/tanzir71/tinyblog
 */

declare(strict_types=1);

const TB_VERSION = '0.1.0';
const TB_REPO_URL = 'https://github.com/tanzir71/tinyblog';

load_env_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');

$GLOBALS['TB_CONFIG'] = [
    'db_path' => config_path('TB_DB_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tinyblog.db'),
    'upload_dir' => config_path('TB_UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads'),
    'log_path' => config_path('TB_LOG_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tinyblog.log'),
    'session_timeout' => (int) config_value('TB_SESSION_TIMEOUT', '1800'),
    'login_rate_limit' => (int) config_value('TB_LOGIN_RATE_LIMIT', '10'),
    'subscribe_rate_limit' => (int) config_value('TB_SUBSCRIBE_RATE_LIMIT', '5'),
    'max_upload_bytes' => (int) config_value('TB_MAX_UPLOAD_BYTES', (string) (2 * 1024 * 1024)),
    'allowed_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'allowed_mime' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ],
];

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (preg_match('/^[A-Z0-9_]+$/', $key)) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

function config_value(string $key, string $fallback): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $fallback;
    }
    return (string) $value;
}

function config_path(string $key, string $fallback): string
{
    $value = config_value($key, $fallback);
    if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $value)) {
        return $value;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . ltrim($value, '.\\/');
}

function htmlEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function base_url(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = $dir === '/' ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

function origin_from_url(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return strtolower($parts['scheme'] . '://' . $parts['host'] . $port);
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ensure_dirs(): void
{
    $dataDir = dirname($GLOBALS['TB_CONFIG']['db_path']);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    if (!is_dir($GLOBALS['TB_CONFIG']['upload_dir'])) {
        mkdir($GLOBALS['TB_CONFIG']['upload_dir'], 0755, true);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    ensure_dirs();
    $pdo = new PDO('sqlite:' . $GLOBALS['TB_CONFIG']['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','editor')),
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            body_markdown TEXT NOT NULL,
            content_html TEXT NOT NULL,
            excerpt TEXT NOT NULL,
            hero_image_url TEXT,
            tags TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL CHECK(status IN ('draft','published')),
            published_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            view_count INTEGER NOT NULL DEFAULT 0,
            UNIQUE(site, slug)
        );
        CREATE TABLE IF NOT EXISTS media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL UNIQUE,
            original_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            url TEXT NOT NULL,
            user_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            consent_at TEXT NOT NULL,
            ip_hash TEXT,
            user_agent TEXT,
            UNIQUE(site, email)
        );
        CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            identity_hash TEXT NOT NULL,
            hits INTEGER NOT NULL DEFAULT 1,
            reset_at INTEGER NOT NULL,
            UNIQUE(type, identity_hash)
        );
        CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL,
            message TEXT NOT NULL,
            context TEXT,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_posts_status_date ON posts(status, published_at);
        CREATE INDEX IF NOT EXISTS idx_posts_site_slug ON posts(site, slug);
        CREATE INDEX IF NOT EXISTS idx_rate_limits_reset ON rate_limits(reset_at);
    ");

    $defaults = [
        'blog_title' => 'TinyBlog Widget',
        'site_key' => 'store-1',
        'public_site_key' => bin2hex(random_bytes(16)),
        'require_site_key' => '0',
        'allowed_origins' => '',
        'accent_color' => '#000000',
        'canonical_base' => base_url(),
        'about_text' => 'TinyBlog Widget is a small privacy-friendly publishing feed. No third-party trackers are enabled by default.',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
}

function setting(PDO $pdo, string $key, ?string $fallback = null): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? (string) $fallback : (string) $value;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function log_event(PDO $pdo, string $level, string $message, array $context = []): void
{
    $stmt = $pdo->prepare('INSERT INTO logs (level, message, context, created_at) VALUES (:level, :message, :context, :created_at)');
    $stmt->execute([
        ':level' => $level,
        ':message' => $message,
        ':context' => $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : null,
        ':created_at' => now_utc(),
    ]);
}

function write_server_log(string $level, string $message, array $context = []): void
{
    $path = (string) ($GLOBALS['TB_CONFIG']['log_path'] ?? '');
    if ($path === '') {
        error_log('[TinyBlog] ' . $message);
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $entry = [
        'time' => now_utc(),
        'level' => $level,
        'message' => $message,
        'context' => $context,
    ];
    error_log(json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, $path);
}

function security_headers(string $context = 'html'): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    // Customize CSP carefully if you add third-party scripts, analytics, or remote media.
    if ($context === 'html') {
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'");
    }
}

function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('tinyblog_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $timeout = (int) $GLOBALS['TB_CONFIG']['session_timeout'];
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $timeout) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function csrf_token(): string
{
    secure_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlEscape(csrf_token()) . '">';
}

function require_csrf(): void
{
    secure_session_start();
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $posted)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function current_user(PDO $pdo): ?array
{
    secure_session_start();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, email, name, role FROM users WHERE id = :id');
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_user(PDO $pdo): array
{
    $user = current_user($pdo);
    if (!$user) {
        redirect('/admin');
    }
    return $user;
}

function require_admin(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        security_headers('html');
        exit('Admin role required.');
    }
}

function can_manage_post(PDO $pdo, array $user, int $postId): bool
{
    if (!in_array((string) ($user['role'] ?? ''), ['admin', 'editor'], true)) {
        return false;
    }
    if ($postId <= 0) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT id FROM posts WHERE id = :id AND site = :site LIMIT 1');
    $stmt->execute([
        ':id' => $postId,
        ':site' => setting($pdo, 'site_key', 'store-1'),
    ]);
    return (bool) $stmt->fetch();
}

function admin_route_action(): string
{
    $action = (string) ($_GET['action'] ?? 'dashboard');
    $allowed = ['dashboard', 'edit', 'media', 'subscribers', 'settings'];
    return in_array($action, $allowed, true) ? $action : 'dashboard';
}

function user_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', 'tinyblog-ip:' . $ip);
}

function rate_limit(PDO $pdo, string $type, int $limit, int $windowSeconds): bool
{
    $identity = client_ip_hash();
    $now = time();
    $resetAt = $now + $windowSeconds;
    $stmt = $pdo->prepare('SELECT id, hits, reset_at FROM rate_limits WHERE type = :type AND identity_hash = :identity');
    $stmt->execute([':type' => $type, ':identity' => $identity]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['reset_at'] <= $now) {
        $insert = $pdo->prepare('INSERT INTO rate_limits (type, identity_hash, hits, reset_at)
            VALUES (:type, :identity, 1, :reset_at)
            ON CONFLICT(type, identity_hash) DO UPDATE SET hits = 1, reset_at = excluded.reset_at');
        $insert->execute([':type' => $type, ':identity' => $identity, ':reset_at' => $resetAt]);
        return true;
    }
    if ((int) $row['hits'] >= $limit) {
        return false;
    }
    $update = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE id = :id');
    $update->execute([':id' => (int) $row['id']]);
    return true;
}

function redirect(string $path): void
{
    header('Location: ' . url_for($path));
    exit;
}

function route_path(): string
{
    if (isset($_GET['route']) && is_string($_GET['route'])) {
        return '/' . trim($_GET['route'], '/');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
    if ($scriptDir && str_starts_with($uri, $scriptDir)) {
        $uri = substr($uri, strlen($scriptDir)) ?: '/';
    }
    if (str_ends_with($uri, '/tinyblog.php')) {
        return '/';
    }
    $uri = '/' . trim($uri, '/');
    return $uri === '//' ? '/' : $uri;
}

function url_for(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = $dir === '/' ? '' : rtrim($dir, '/');
    return $dir . $path;
}

function canonical_url(PDO $pdo, string $path): string
{
    return rtrim(setting($pdo, 'canonical_base', base_url()), '/') . '/' . ltrim($path, '/');
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?: '';
    $text = trim($text, '-');
    return $text !== '' ? substr($text, 0, 96) : 'post-' . date('YmdHis');
}

function safe_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, '/uploads/') || str_starts_with($url, './uploads/')) {
        return $url;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function render_inline_markdown(string $text): string
{
    $escaped = htmlEscape($text);

    $escaped = preg_replace_callback('/`([^`]+)`/', function (array $m): string {
        return '<code>' . htmlEscape(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) . '</code>';
    }, $escaped) ?? $escaped;

    $escaped = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function (array $m): string {
        $src = safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if (!$src) {
            return htmlEscape(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return '<img src="' . htmlEscape($src) . '" alt="' . htmlEscape(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) . '" loading="lazy">';
    }, $escaped) ?? $escaped;

    $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m): string {
        $href = safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        $label = $m[1];
        if (!$href) {
            return $label;
        }
        return '<a href="' . htmlEscape($href) . '" rel="nofollow noopener" target="_blank">' . $label . '</a>';
    }, $escaped) ?? $escaped;

    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;
    return $escaped;
}

function sanitize_markdown(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $lines = explode("\n", $markdown);
    $html = [];
    $paragraph = [];
    $list = [];
    $ordered = false;
    $quote = [];
    $code = [];
    $inCode = false;

    $flushParagraph = function () use (&$html, &$paragraph): void {
        if ($paragraph) {
            $html[] = '<p>' . render_inline_markdown(trim(implode(' ', $paragraph))) . '</p>';
            $paragraph = [];
        }
    };
    $flushList = function () use (&$html, &$list, &$ordered): void {
        if ($list) {
            $tag = $ordered ? 'ol' : 'ul';
            $items = array_map(fn (string $item): string => '<li>' . render_inline_markdown($item) . '</li>', $list);
            $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
            $list = [];
        }
    };
    $flushQuote = function () use (&$html, &$quote): void {
        if ($quote) {
            $html[] = '<blockquote><p>' . render_inline_markdown(trim(implode(' ', $quote))) . '</p></blockquote>';
            $quote = [];
        }
    };
    $flushCode = function () use (&$html, &$code): void {
        if ($code) {
            $html[] = '<p><code>' . nl2br(htmlEscape(implode("\n", $code)), false) . '</code></p>';
            $code = [];
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '```')) {
            if ($inCode) {
                $flushCode();
                $inCode = false;
            } else {
                $flushParagraph();
                $flushList();
                $flushQuote();
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $code[] = rtrim($line);
            continue;
        }
        if ($trimmed === '') {
            $flushParagraph();
            $flushList();
            $flushQuote();
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
            $flushParagraph();
            $flushList();
            $quote[] = $m[1];
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            $flushQuote();
            if ($list && $ordered) {
                $flushList();
            }
            $ordered = false;
            $list[] = $m[1];
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            $flushQuote();
            if ($list && !$ordered) {
                $flushList();
            }
            $ordered = true;
            $list[] = $m[1];
            continue;
        }
        if (preg_match('/^#{1,3}\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            $flushList();
            $flushQuote();
            $html[] = '<p><strong>' . render_inline_markdown($m[1]) . '</strong></p>';
            continue;
        }
        $paragraph[] = $trimmed;
    }

    $flushParagraph();
    $flushList();
    $flushQuote();
    $flushCode();

    return implode("\n", $html);
}

function excerpt_from_markdown(string $markdown, int $max = 180): string
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags(sanitize_markdown($markdown))) ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);
    if ($length <= $max) {
        return $plain;
    }
    $snippet = function_exists('mb_substr') ? mb_substr($plain, 0, $max - 1) : substr($plain, 0, $max - 1);
    return rtrim($snippet) . '...';
}

function split_tags(string $tags): array
{
    $parts = preg_split('/[,#]+/', $tags) ?: [];
    $clean = [];
    foreach ($parts as $tag) {
        $tag = trim($tag);
        if ($tag !== '') {
            $clean[] = substr($tag, 0, 32);
        }
    }
    return array_values(array_unique($clean));
}

function tags_to_string(string $tags): string
{
    return implode(', ', split_tags($tags));
}

function parse_allowed_origins(string $value): array
{
    $origins = preg_split('/[\r\n,]+/', $value) ?: [];
    $clean = [];
    foreach ($origins as $origin) {
        $origin = rtrim(strtolower(trim($origin)), '/');
        if ($origin !== '' && preg_match('#^https?://[a-z0-9.-]+(?::[0-9]+)?$#i', $origin)) {
            $clean[] = $origin;
        }
    }
    return array_values(array_unique($clean));
}

function send_cors_headers(PDO $pdo, string $origin): void
{
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-TinyBlog-Site-Key');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

function check_cors_or_fail(PDO $pdo): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $origin = rtrim(strtolower($origin), '/');
    $allowed = parse_allowed_origins(setting($pdo, 'allowed_origins', ''));
    $sameOrigin = origin_from_url(base_url());
    if ($origin === $sameOrigin || in_array($origin, $allowed, true)) {
        send_cors_headers($pdo, $origin);
        return;
    }
    json_response(['error' => 'Origin is not allowed for this TinyBlog site.'], 403);
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    security_headers('json');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function validate_site(PDO $pdo, string $site): void
{
    if (!hash_equals(setting($pdo, 'site_key', 'store-1'), $site)) {
        json_response(['error' => 'Unknown site.'], 404);
    }
    if (setting($pdo, 'require_site_key', '0') === '1') {
        $provided = $_SERVER['HTTP_X_TINYBLOG_SITE_KEY'] ?? ($_GET['siteKey'] ?? '');
        if (!is_string($provided) || !hash_equals(setting($pdo, 'public_site_key', ''), $provided)) {
            json_response(['error' => 'Missing or invalid siteKey.'], 401);
        }
    }
}

function get_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function post_to_api(PDO $pdo, array $post, bool $includeContent): array
{
    $payload = [
        'id' => (int) $post['id'],
        'slug' => $post['slug'],
        'title' => $post['title'],
        'excerpt' => $post['excerpt'],
        'published_at' => $post['published_at'],
        'hero_image_url' => $post['hero_image_url'],
        'tags' => split_tags($post['tags']),
        'canonical_url' => canonical_url($pdo, '/post/' . rawurlencode($post['slug'])),
        'view_count' => (int) $post['view_count'],
    ];
    if ($includeContent) {
        $payload['content_html'] = $post['content_html'];
    }
    return $payload;
}

function handle_api(PDO $pdo, string $path): void
{
    check_cors_or_fail($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        security_headers('json');
        http_response_code(204);
        exit;
    }

    if ($path === '/api/posts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $site = (string) ($_GET['site'] ?? setting($pdo, 'site_key', 'store-1'));
        validate_site($pdo, $site);
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND status = :status ORDER BY datetime(published_at) DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':site', $site, PDO::PARAM_STR);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $posts = array_map(fn (array $post): array => post_to_api($pdo, $post, true), $stmt->fetchAll());
        json_response([
            'site' => $site,
            'title' => setting($pdo, 'blog_title', 'TinyBlog Widget'),
            'posts' => $posts,
            'generated_at' => now_utc(),
        ]);
    }

    if (preg_match('#^/api/posts/([^/]+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $site = (string) ($_GET['site'] ?? setting($pdo, 'site_key', 'store-1'));
        validate_site($pdo, $site);
        $slug = rawurldecode($m[1]);
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND slug = :slug AND status = :status LIMIT 1');
        $stmt->execute([':site' => $site, ':slug' => $slug, ':status' => 'published']);
        $post = $stmt->fetch();
        if (!$post) {
            json_response(['error' => 'Post not found.'], 404);
        }
        $update = $pdo->prepare('UPDATE posts SET view_count = view_count + 1 WHERE id = :id');
        $update->execute([':id' => (int) $post['id']]);
        $post['view_count'] = (int) $post['view_count'] + 1;
        json_response(['post' => post_to_api($pdo, $post, true)]);
    }

    if ($path === '/api/subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rate_limit($pdo, 'subscribe', (int) $GLOBALS['TB_CONFIG']['subscribe_rate_limit'], 3600)) {
            json_response(['error' => 'Too many subscribe attempts. Try again later.'], 429);
        }
        $data = get_json_body();
        $site = (string) ($data['site'] ?? setting($pdo, 'site_key', 'store-1'));
        validate_site($pdo, $site);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            json_response(['error' => 'Enter a valid email address.'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO subscribers (site, email, status, consent_at, ip_hash, user_agent)
            VALUES (:site, :email, :status, :consent_at, :ip_hash, :user_agent)
            ON CONFLICT(site, email) DO UPDATE SET status = :status, consent_at = excluded.consent_at');
        $stmt->execute([
            ':site' => $site,
            ':email' => $email,
            ':status' => 'active',
            ':consent_at' => now_utc(),
            ':ip_hash' => client_ip_hash(),
            ':user_agent' => null,
        ]);
        json_response(['ok' => true, 'message' => 'Subscribed. Check your inbox if confirmation is enabled later.']);
    }

    if ($path === '/api/feed.xml' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        render_rss($pdo);
    }

    json_response(['error' => 'API route not found.'], 404);
}

function render_page(PDO $pdo, string $title, string $body, array $meta = []): void
{
    $blogTitle = setting($pdo, 'blog_title', 'TinyBlog Widget');
    $accent = setting($pdo, 'accent_color', '#000000');
    $description = $meta['description'] ?? 'A tiny privacy-friendly embeddable blog feed.';
    $canonical = $meta['canonical'] ?? canonical_url($pdo, route_path());
    $ogImage = $meta['og_image'] ?? '';
    security_headers('html');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlEscape($title . ' - ' . $blogTitle) . '</title>';
    echo '<meta name="description" content="' . htmlEscape($description) . '">';
    echo '<link rel="canonical" href="' . htmlEscape($canonical) . '">';
    echo '<meta property="og:title" content="' . htmlEscape($title) . '">';
    echo '<meta property="og:description" content="' . htmlEscape($description) . '">';
    if ($ogImage !== '') {
        echo '<meta property="og:image" content="' . htmlEscape($ogImage) . '">';
    }
    echo '<link rel="alternate" type="application/rss+xml" title="' . htmlEscape($blogTitle) . '" href="' . htmlEscape(url_for('/feed.xml')) . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<style>';
    echo css_base($accent);
    echo '</style></head><body><div class="site">';
    echo '<header class="topbar"><a class="brand" href="' . htmlEscape(url_for('/')) . '">' . htmlEscape($blogTitle) . '</a><nav><a href="' . htmlEscape(url_for('/archive')) . '">Archive</a><a href="' . htmlEscape(url_for('/about')) . '">About</a><a href="' . htmlEscape(url_for('/admin')) . '">Admin</a></nav></header>';
    echo $body;
    echo '<footer class="footer"><p>Privacy: this site stores subscriber emails only for opt-in delivery. No third-party trackers are enabled by default. Enable HTTPS and keep file permissions tight.</p><p><a href="' . TB_REPO_URL . '">GitHub</a> · <a href="' . htmlEscape(url_for('/SETUP.md')) . '">Docs</a> · <a href="' . htmlEscape(url_for('/SECURITY.md')) . '">Security</a> · <a href="' . htmlEscape(url_for('/feed.xml')) . '">RSS</a> · <a href="' . htmlEscape(url_for('/sitemap.xml')) . '">Sitemap</a></p></footer>';
    echo '</div></body></html>';
    exit;
}

function css_base(string $accent): string
{
    $safeAccent = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#000000';
    return "
        :root{--bg:#fff;--text:#050505;--muted:#5f5f5f;--line:#e6e6e6;--soft:#f7f7f7;--accent:{$safeAccent};--max:1120px;--measure:760px}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;letter-spacing:0}
        a{color:inherit;text-decoration-thickness:1px;text-underline-offset:3px}
        img{max-width:100%;height:auto}
        input,textarea,select,button{font:inherit}
        .site{width:min(var(--max),calc(100% - 32px));margin:0 auto}
        .topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 0;border-bottom:1px solid var(--line)}
        .brand{font-weight:800;text-decoration:none;letter-spacing:0}
        nav{display:flex;gap:14px;align-items:center;font-size:14px}
        nav a{text-decoration:none;color:var(--muted)}
        .hero{padding:54px 0 34px;border-bottom:1px solid var(--line)}
        .hero h1{font-size:clamp(42px,8vw,88px);line-height:.95;margin:0 0 18px;letter-spacing:0;max-width:920px}
        .hero p{font-size:clamp(18px,3vw,24px);line-height:1.45;color:var(--muted);max-width:760px;margin:0}
        .grid{display:grid;grid-template-columns:1fr;gap:26px;padding:34px 0}
        .post-list{display:grid;gap:22px}
        .post-row{padding-bottom:22px;border-bottom:1px solid var(--line)}
        .post-row h2{font-size:clamp(28px,6vw,46px);line-height:1.05;margin:0 0 10px}
        .post-row h2 a{text-decoration:none}
        .meta,.muted{color:var(--muted);font-size:14px;line-height:1.5}
        .excerpt{font-size:17px;line-height:1.65;color:#202020;max-width:var(--measure)}
        .article{max-width:var(--measure);padding:42px 0}
        .article h1{font-size:clamp(40px,8vw,72px);line-height:1;margin:0 0 14px}
        .content{font-size:18px;line-height:1.78}
        .content p,.content ul,.content ol,.content blockquote{margin:0 0 1.2em}
        .content blockquote{border-left:2px solid var(--text);padding-left:16px;color:#222}
        .content code{background:var(--soft);border:1px solid var(--line);padding:2px 5px;border-radius:4px}
        .tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
        .tag{border:1px solid var(--line);padding:5px 9px;border-radius:999px;text-decoration:none;font-size:13px}
        .panel{border:1px solid var(--line);padding:18px;background:#fff}
        .admin-layout{display:grid;grid-template-columns:1fr;gap:20px;padding:28px 0}
        .admin-nav{display:flex;flex-wrap:wrap;gap:8px}
        .admin-nav a,.button,button{border:1px solid var(--text);background:var(--text);color:#fff;text-decoration:none;padding:10px 13px;border-radius:0;cursor:pointer;font-weight:650;font-size:14px}
        .button.secondary,button.secondary{background:#fff;color:var(--text)}
        label{display:grid;gap:7px;font-size:13px;font-weight:650;margin:0 0 14px}
        input,textarea,select{width:100%;border:1px solid var(--line);padding:11px 12px;background:#fff;color:var(--text)}
        textarea{min-height:230px;line-height:1.55}
        table{width:100%;border-collapse:collapse;font-size:14px}
        th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px 8px;vertical-align:top}
        .notice{padding:12px 14px;border:1px solid var(--line);background:var(--soft);margin:0 0 18px}
        .error{border-color:#111;background:#fff}
        .footer{border-top:1px solid var(--line);padding:28px 0 42px;color:var(--muted);font-size:13px;line-height:1.6}
        @media(min-width:820px){.grid{grid-template-columns:minmax(0,1fr) 280px}.admin-layout{grid-template-columns:190px minmax(0,1fr)}.admin-nav{display:grid;align-content:start}}
    ";
}

function render_home(PDO $pdo, int $page = 1): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $perPage = 8;
    $offset = max(0, ($page - 1) * $perPage);
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND status = :status ORDER BY datetime(published_at) DESC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':site', $site, PDO::PARAM_STR);
    $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
    $body = '<section class="hero"><h1>Small posts, clean embeds.</h1><p>Canonical TinyBlog pages for search, RSS, and readers who open widget links.</p></section><main class="grid"><section class="post-list">';
    if (!$posts) {
        $body .= '<p class="muted">No published posts yet. Visit admin to create the first one.</p>';
    }
    foreach ($posts as $post) {
        $body .= post_row($post);
    }
    $body .= '</section><aside class="panel"><form method="get" action="' . htmlEscape(url_for('/search')) . '"><label>Search<input name="q" placeholder="Search posts"></label><button>Search</button></form><p class="muted">Widget API endpoint: <code>' . htmlEscape(canonical_url($pdo, '/api')) . '</code></p></aside></main>';
    render_page($pdo, 'Home', $body, ['description' => 'A privacy-friendly embeddable blog widget and tiny backend.']);
}

function post_row(array $post): string
{
    $tags = split_tags($post['tags']);
    $html = '<article class="post-row"><p class="meta">' . htmlEscape((string) $post['published_at']) . '</p>';
    $html .= '<h2><a href="' . htmlEscape(url_for('/post/' . rawurlencode($post['slug']))) . '">' . htmlEscape($post['title']) . '</a></h2>';
    $html .= '<p class="excerpt">' . htmlEscape($post['excerpt']) . '</p>';
    if ($tags) {
        $html .= '<div class="tags">';
        foreach ($tags as $tag) {
            $html .= '<a class="tag" href="' . htmlEscape(url_for('/tag/' . rawurlencode($tag))) . '">#' . htmlEscape($tag) . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '</article>';
}

function render_post(PDO $pdo, string $slug): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND slug = :slug AND status = :status LIMIT 1');
    $stmt->execute([':site' => $site, ':slug' => $slug, ':status' => 'published']);
    $post = $stmt->fetch();
    if (!$post) {
        http_response_code(404);
        render_page($pdo, 'Not found', '<main class="article"><h1>Not found</h1><p class="muted">That post does not exist or is not published.</p></main>');
    }
    $update = $pdo->prepare('UPDATE posts SET view_count = view_count + 1 WHERE id = :id');
    $update->execute([':id' => (int) $post['id']]);
    $body = '<main class="article"><p class="meta">' . htmlEscape((string) $post['published_at']) . '</p><h1>' . htmlEscape($post['title']) . '</h1>';
    if (!empty($post['hero_image_url'])) {
        $body .= '<img src="' . htmlEscape($post['hero_image_url']) . '" alt="" loading="lazy">';
    }
    $body .= '<div class="content">' . $post['content_html'] . '</div>';
    $body .= '<div class="tags">';
    foreach (split_tags($post['tags']) as $tag) {
        $body .= '<a class="tag" href="' . htmlEscape(url_for('/tag/' . rawurlencode($tag))) . '">#' . htmlEscape($tag) . '</a>';
    }
    $body .= '</div></main>';
    render_page($pdo, $post['title'], $body, [
        'description' => $post['excerpt'],
        'canonical' => canonical_url($pdo, '/post/' . rawurlencode($post['slug'])),
        'og_image' => (string) ($post['hero_image_url'] ?? ''),
    ]);
}

function render_tag(PDO $pdo, string $tag): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $needle = '%' . $tag . '%';
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND status = :status AND tags LIKE :tag ORDER BY datetime(published_at) DESC');
    $stmt->execute([':site' => $site, ':status' => 'published', ':tag' => $needle]);
    $body = '<main class="article"><h1>#' . htmlEscape($tag) . '</h1>';
    foreach ($stmt->fetchAll() as $post) {
        $body .= post_row($post);
    }
    $body .= '</main>';
    render_page($pdo, 'Tag ' . $tag, $body);
}

function render_search(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $q = trim((string) ($_GET['q'] ?? ''));
    $body = '<main class="article"><h1>Search</h1><form method="get"><label>Query<input name="q" value="' . htmlEscape($q) . '"></label><button>Search</button></form>';
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND status = :status AND (title LIKE :q OR excerpt LIKE :q OR body_markdown LIKE :q) ORDER BY datetime(published_at) DESC LIMIT 30');
        $stmt->execute([':site' => $site, ':status' => 'published', ':q' => $like]);
        foreach ($stmt->fetchAll() as $post) {
            $body .= post_row($post);
        }
    }
    $body .= '</main>';
    render_page($pdo, 'Search', $body);
}

function render_about(PDO $pdo): void
{
    $body = '<main class="article"><h1>About</h1><div class="content"><p>' . htmlEscape(setting($pdo, 'about_text', 'A tiny embeddable blog.')) . '</p><p>Privacy: subscriber emails are used for opt-in updates. No third-party trackers are enabled by default.</p></div></main>';
    render_page($pdo, 'About', $body);
}

function render_rss(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site AND status = :status ORDER BY datetime(published_at) DESC LIMIT 30');
    $stmt->execute([':site' => $site, ':status' => 'published']);
    security_headers('xml');
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8" ?>';
    echo '<rss version="2.0"><channel>';
    echo '<title>' . htmlEscape(setting($pdo, 'blog_title', 'TinyBlog Widget')) . '</title>';
    echo '<link>' . htmlEscape(canonical_url($pdo, '/')) . '</link>';
    echo '<description>Recent TinyBlog posts</description>';
    foreach ($stmt->fetchAll() as $post) {
        echo '<item><title>' . htmlEscape($post['title']) . '</title>';
        echo '<link>' . htmlEscape(canonical_url($pdo, '/post/' . rawurlencode($post['slug']))) . '</link>';
        echo '<guid>' . htmlEscape(canonical_url($pdo, '/post/' . rawurlencode($post['slug']))) . '</guid>';
        echo '<pubDate>' . htmlEscape(gmdate(DATE_RSS, strtotime((string) $post['published_at']) ?: time())) . '</pubDate>';
        echo '<description>' . htmlEscape($post['excerpt']) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

function render_sitemap(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT slug, updated_at FROM posts WHERE site = :site AND status = :status ORDER BY datetime(updated_at) DESC LIMIT 500');
    $stmt->execute([':site' => $site, ':status' => 'published']);
    security_headers('xml');
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8" ?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (['/', '/archive', '/about'] as $path) {
        echo '<url><loc>' . htmlEscape(canonical_url($pdo, $path)) . '</loc></url>';
    }
    foreach ($stmt->fetchAll() as $post) {
        echo '<url><loc>' . htmlEscape(canonical_url($pdo, '/post/' . rawurlencode($post['slug']))) . '</loc><lastmod>' . htmlEscape(substr((string) $post['updated_at'], 0, 10)) . '</lastmod></url>';
    }
    echo '</urlset>';
    exit;
}

function render_admin(PDO $pdo): void
{
    $user = current_user($pdo);
    $action = admin_route_action();
    $message = '';
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = (string) ($_POST['admin_action'] ?? '');
        if ($postAction === 'register') {
            require_csrf();
            if (user_count($pdo) > 0) {
                $error = 'Registration is closed. Ask an admin to add users.';
            } else {
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $password = (string) ($_POST['password'] ?? '');
                $name = trim((string) ($_POST['name'] ?? 'Admin'));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                    $error = 'Use a valid email and a password with at least 12 characters.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (:email, :name, :password_hash, :role, :created_at)');
                    $stmt->execute([
                        ':email' => $email,
                        ':name' => $name ?: 'Admin',
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':role' => 'admin',
                        ':created_at' => now_utc(),
                    ]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $pdo->lastInsertId();
                    redirect('/admin');
                }
            }
        } elseif ($postAction === 'login') {
            require_csrf();
            if (!rate_limit($pdo, 'admin_login', (int) $GLOBALS['TB_CONFIG']['login_rate_limit'], 900)) {
                $error = 'Too many login attempts. Try again later.';
            } else {
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $candidate = $stmt->fetch();
                if ($candidate && password_verify((string) ($_POST['password'] ?? ''), $candidate['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $candidate['id'];
                    redirect('/admin');
                }
                $error = 'Invalid email or password.';
            }
        } elseif ($postAction === 'logout') {
            require_csrf();
            $_SESSION = [];
            session_destroy();
            redirect('/admin');
        } else {
            $user = require_user($pdo);
            require_csrf();
            if (in_array($postAction, ['save_settings', 'seed_samples'], true)) {
                require_admin($user);
            }
            if ($postAction === 'save_post') {
                save_post($pdo, $user);
                redirect('/admin');
            }
            if ($postAction === 'upload_media') {
                $message = upload_media($pdo, $user);
                $action = 'media';
            }
            if ($postAction === 'save_settings') {
                save_settings($pdo);
                $message = 'Settings saved.';
                $action = 'settings';
            }
            if ($postAction === 'seed_samples') {
                seed_samples($pdo, $user);
                $message = 'Sample posts loaded.';
                $action = 'dashboard';
            }
        }
    }

    if (!$user) {
        echo admin_head($pdo, user_count($pdo) === 0 ? 'Create admin' : 'Login');
        echo '<main class="site article">';
        if ($error) {
            echo '<p class="notice error">' . htmlEscape($error) . '</p>';
        }
        if (user_count($pdo) === 0) {
            echo '<h1>Create first admin</h1><p class="muted">No default admin credentials ship with TinyBlog Widget.</p>';
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="register">';
            echo '<label>Name<input name="name" required autocomplete="name"></label>';
            echo '<label>Email<input name="email" type="email" required autocomplete="email"></label>';
            echo '<label>Password<input name="password" type="password" minlength="12" required autocomplete="new-password"></label>';
            echo '<button>Create admin</button></form>';
        } else {
            echo '<h1>Admin login</h1><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="login">';
            echo '<label>Email<input name="email" type="email" required autocomplete="email"></label>';
            echo '<label>Password<input name="password" type="password" required autocomplete="current-password"></label>';
            echo '<button>Login</button></form>';
        }
        echo '</main></body></html>';
        exit;
    }

    echo admin_head($pdo, 'Admin');
    echo '<div class="site admin-layout"><aside class="admin-nav">';
    $nav = ['dashboard' => 'Dashboard', 'edit' => 'New post', 'media' => 'Media'];
    if (($user['role'] ?? '') === 'admin') {
        $nav['subscribers'] = 'Subscribers';
        $nav['settings'] = 'Settings';
    }
    foreach ($nav as $key => $label) {
        echo '<a class="button secondary" href="' . htmlEscape(url_for('/admin?action=' . $key)) . '">' . htmlEscape($label) . '</a>';
    }
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="logout"><button class="secondary">Logout</button></form>';
    echo '</aside><main>';
    if ($message) {
        echo '<p class="notice">' . htmlEscape($message) . '</p>';
    }
    if ($action === 'edit') {
        render_post_form($pdo, $user);
    } elseif ($action === 'media') {
        render_media_admin($pdo);
    } elseif ($action === 'subscribers') {
        require_admin($user);
        render_subscribers_admin($pdo);
    } elseif ($action === 'settings') {
        require_admin($user);
        render_settings_admin($pdo);
    } else {
        render_dashboard_admin($pdo, $user);
    }
    echo '</main></div></body></html>';
    exit;
}

function admin_head(PDO $pdo, string $title): string
{
    security_headers('html');
    $accent = setting($pdo, 'accent_color', '#000000');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlEscape($title) . ' - TinyBlog Admin</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><style>' . css_base($accent) . '.editor-preview{border:1px solid var(--line);padding:14px;min-height:180px}.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px}</style></head><body>';
}

function render_dashboard_admin(PDO $pdo, array $user): void
{
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site ORDER BY datetime(updated_at) DESC LIMIT 20');
    $stmt->execute([':site' => setting($pdo, 'site_key', 'store-1')]);
    echo '<h1>Dashboard</h1><div class="toolbar"><a class="button" href="' . htmlEscape(url_for('/admin?action=edit')) . '">Write post</a>';
    if (($user['role'] ?? '') === 'admin') {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="seed_samples"><button class="secondary">Load sample posts</button></form>';
    }
    echo '</div>';
    echo '<table><thead><tr><th>Title</th><th>Status</th><th>Views</th><th>Updated</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $post) {
        echo '<tr><td><a href="' . htmlEscape(url_for('/admin?action=edit&id=' . (int) $post['id'])) . '">' . htmlEscape($post['title']) . '</a><br><span class="muted">/' . htmlEscape($post['slug']) . '</span></td><td>' . htmlEscape($post['status']) . '</td><td>' . (int) $post['view_count'] . '</td><td>' . htmlEscape($post['updated_at']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function render_post_form(PDO $pdo, array $user): void
{
    $post = [
        'id' => '',
        'title' => '',
        'slug' => '',
        'body_markdown' => '',
        'excerpt' => '',
        'hero_image_url' => '',
        'tags' => '',
        'status' => 'draft',
        'published_at' => now_utc(),
    ];
    if (!empty($_GET['id'])) {
        $postId = (int) $_GET['id'];
        if (!can_manage_post($pdo, $user, $postId)) {
            http_response_code(403);
            echo '<h1>Forbidden</h1><p class="muted">You cannot edit that post.</p>';
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id AND site = :site LIMIT 1');
        $stmt->execute([':id' => $postId, ':site' => setting($pdo, 'site_key', 'store-1')]);
        $found = $stmt->fetch();
        if ($found) {
            $post = array_merge($post, $found);
        }
    }
    echo '<h1>' . ($post['id'] ? 'Edit post' : 'New post') . '</h1>';
    echo '<form method="post" id="postForm">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_post"><input type="hidden" name="id" value="' . htmlEscape((string) $post['id']) . '">';
    echo '<label>Title<input name="title" id="title" value="' . htmlEscape($post['title']) . '" required></label>';
    echo '<label>Slug<input name="slug" id="slug" value="' . htmlEscape($post['slug']) . '" placeholder="auto-generated"></label>';
    echo '<label>Markdown body<textarea name="body_markdown" id="body_markdown" required>' . htmlEscape($post['body_markdown']) . '</textarea></label>';
    echo '<div class="toolbar"><button type="button" class="secondary" id="previewToggle">Preview</button><span class="muted" id="autosaveStatus">Autosave ready</span></div><div class="editor-preview content" id="preview" hidden></div>';
    echo '<label>Excerpt<textarea name="excerpt" style="min-height:90px">' . htmlEscape($post['excerpt']) . '</textarea></label>';
    echo '<label>Featured image URL<input name="hero_image_url" value="' . htmlEscape((string) $post['hero_image_url']) . '" placeholder="https://... or uploaded media URL"></label>';
    echo '<label>Tags<input name="tags" value="' . htmlEscape($post['tags']) . '" placeholder="updates, product, notes"></label>';
    echo '<label>Publish date<input name="published_at" value="' . htmlEscape((string) $post['published_at']) . '"></label>';
    echo '<label>Status<select name="status"><option value="draft"' . ($post['status'] === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . ($post['status'] === 'published' ? ' selected' : '') . '>Published</option></select></label>';
    echo '<button>Save</button></form>';
    echo '<script>
        const title = document.getElementById("title");
        const slug = document.getElementById("slug");
        const body = document.getElementById("body_markdown");
        const preview = document.getElementById("preview");
        const status = document.getElementById("autosaveStatus");
        const key = "tinyblog-draft-" + (' . json_encode((string) $post['id']) . ' || "new");
        if (!body.value && localStorage.getItem(key)) body.value = localStorage.getItem(key);
        title.addEventListener("input", () => { if (!slug.dataset.touched) slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-|-$/g,""); });
        slug.addEventListener("input", () => slug.dataset.touched = "1");
        body.addEventListener("input", () => { localStorage.setItem(key, body.value); status.textContent = "Draft saved locally"; });
        document.getElementById("previewToggle").addEventListener("click", () => {
          preview.hidden = !preview.hidden;
          preview.textContent = body.value.slice(0, 4000);
        });
    </script>';
}

function save_post(PDO $pdo, array $user): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $site = setting($pdo, 'site_key', 'store-1');
    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = slugify((string) ($_POST['slug'] ?? $title));
    $body = (string) ($_POST['body_markdown'] ?? '');
    $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
    $hero = trim((string) ($_POST['hero_image_url'] ?? ''));
    $status = in_array($_POST['status'] ?? 'draft', ['draft', 'published'], true) ? (string) $_POST['status'] : 'draft';
    $publishedAt = trim((string) ($_POST['published_at'] ?? now_utc())) ?: now_utc();
    $contentHtml = sanitize_markdown($body);
    if ($excerpt === '') {
        $excerpt = excerpt_from_markdown($body);
    }
    $tags = tags_to_string((string) ($_POST['tags'] ?? ''));
    $hero = $hero !== '' && safe_url($hero) ? $hero : '';
    if ($title === '' || $body === '') {
        throw new RuntimeException('Title and body are required.');
    }
    if ($id > 0) {
        if (!can_manage_post($pdo, $user, $id)) {
            http_response_code(403);
            exit('You cannot edit that post.');
        }
        $stmt = $pdo->prepare('UPDATE posts SET title = :title, slug = :slug, body_markdown = :body_markdown, content_html = :content_html, excerpt = :excerpt, hero_image_url = :hero_image_url, tags = :tags, status = :status, published_at = :published_at, updated_at = :updated_at WHERE id = :id AND site = :site');
        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':body_markdown' => $body,
            ':content_html' => $contentHtml,
            ':excerpt' => $excerpt,
            ':hero_image_url' => $hero,
            ':tags' => $tags,
            ':status' => $status,
            ':published_at' => $publishedAt,
            ':updated_at' => now_utc(),
            ':id' => $id,
            ':site' => $site,
        ]);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO posts (site, title, slug, body_markdown, content_html, excerpt, hero_image_url, tags, status, published_at, created_at, updated_at)
        VALUES (:site, :title, :slug, :body_markdown, :content_html, :excerpt, :hero_image_url, :tags, :status, :published_at, :created_at, :updated_at)');
    $stmt->execute([
        ':site' => $site,
        ':title' => $title,
        ':slug' => $slug,
        ':body_markdown' => $body,
        ':content_html' => $contentHtml,
        ':excerpt' => $excerpt,
        ':hero_image_url' => $hero,
        ':tags' => $tags,
        ':status' => $status,
        ':published_at' => $publishedAt,
        ':created_at' => now_utc(),
        ':updated_at' => now_utc(),
    ]);
}

function upload_media(PDO $pdo, array $user): string
{
    if (empty($_FILES['media']) || !is_array($_FILES['media'])) {
        return 'Choose an image to upload.';
    }
    $file = $_FILES['media'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Upload failed.';
    }
    if ((int) $file['size'] > (int) $GLOBALS['TB_CONFIG']['max_upload_bytes']) {
        return 'Image is too large. Limit is 2 MB.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']) ?: '';
    $allowed = $GLOBALS['TB_CONFIG']['allowed_mime'];
    if (!isset($allowed[$mime])) {
        return 'Only jpg, png, gif, and webp images are allowed.';
    }
    $original = basename((string) $file['name']);
    $originalExt = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($originalExt, $GLOBALS['TB_CONFIG']['allowed_ext'], true)) {
        return 'Only jpg, png, gif, and webp image extensions are allowed.';
    }
    $safeBase = preg_replace('/[^a-z0-9._-]+/i', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'image';
    $filename = strtolower(substr($safeBase, 0, 48)) . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $GLOBALS['TB_CONFIG']['upload_dir'] . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        return 'Could not store upload.';
    }
    chmod($target, 0644);
    $url = rtrim(setting($pdo, 'canonical_base', base_url()), '/') . '/uploads/' . rawurlencode($filename);
    $stmt = $pdo->prepare('INSERT INTO media (filename, original_name, mime, size, url, user_id, created_at) VALUES (:filename, :original_name, :mime, :size, :url, :user_id, :created_at)');
    $stmt->execute([
        ':filename' => $filename,
        ':original_name' => $original,
        ':mime' => $mime,
        ':size' => (int) $file['size'],
        ':url' => $url,
        ':user_id' => (int) $user['id'],
        ':created_at' => now_utc(),
    ]);
    return 'Image uploaded: ' . $url;
}

function render_media_admin(PDO $pdo): void
{
    echo '<h1>Media</h1><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="admin_action" value="upload_media"><label>Image<input type="file" name="media" accept="image/jpeg,image/png,image/gif,image/webp" required></label><button>Upload</button></form>';
    $stmt = $pdo->prepare('SELECT * FROM media ORDER BY datetime(created_at) DESC LIMIT 60');
    $stmt->execute();
    echo '<table><thead><tr><th>Preview</th><th>URL</th><th>Size</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $media) {
        echo '<tr><td><img src="' . htmlEscape($media['url']) . '" alt="" style="width:86px"></td><td><code>' . htmlEscape($media['url']) . '</code><br><span class="muted">' . htmlEscape($media['original_name']) . '</span></td><td>' . (int) $media['size'] . '</td></tr>';
    }
    echo '</tbody></table>';
}

function render_subscribers_admin(PDO $pdo): void
{
    echo '<h1>Subscribers</h1><p class="muted">Emails are stored for opt-in updates only. Export carefully and delete on request.</p>';
    $stmt = $pdo->prepare('SELECT email, status, consent_at FROM subscribers WHERE site = :site ORDER BY datetime(consent_at) DESC LIMIT 200');
    $stmt->execute([':site' => setting($pdo, 'site_key', 'store-1')]);
    echo '<table><thead><tr><th>Email</th><th>Status</th><th>Consent</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) {
        echo '<tr><td>' . htmlEscape($row['email']) . '</td><td>' . htmlEscape($row['status']) . '</td><td>' . htmlEscape($row['consent_at']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function render_settings_admin(PDO $pdo): void
{
    echo '<h1>Settings</h1><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_settings">';
    foreach (['blog_title' => 'Blog title', 'site_key' => 'Site id', 'canonical_base' => 'Canonical base URL', 'accent_color' => 'Accent color'] as $key => $label) {
        echo '<label>' . htmlEscape($label) . '<input name="' . htmlEscape($key) . '" value="' . htmlEscape(setting($pdo, $key, '')) . '"></label>';
    }
    echo '<label>Allowed widget origins<textarea name="allowed_origins" placeholder="https://example.com">' . htmlEscape(setting($pdo, 'allowed_origins', '')) . '</textarea></label>';
    echo '<label>About text<textarea name="about_text">' . htmlEscape(setting($pdo, 'about_text', '')) . '</textarea></label>';
    echo '<label><input type="checkbox" name="require_site_key" value="1" ' . (setting($pdo, 'require_site_key', '0') === '1' ? 'checked' : '') . '> Require public siteKey for API reads</label>';
    echo '<p class="muted">Public siteKey: <code>' . htmlEscape(setting($pdo, 'public_site_key', '')) . '</code></p><button>Save settings</button></form>';
}

function save_settings(PDO $pdo): void
{
    $fields = ['blog_title', 'site_key', 'canonical_base', 'accent_color', 'allowed_origins', 'about_text'];
    foreach ($fields as $field) {
        $value = trim((string) ($_POST[$field] ?? ''));
        if ($field === 'accent_color' && !preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            $value = '#000000';
        }
        if ($field === 'canonical_base') {
            $value = rtrim($value ?: base_url(), '/');
        }
        if ($field === 'site_key') {
            $value = slugify($value ?: 'store-1');
        }
        set_setting($pdo, $field, $value);
    }
    set_setting($pdo, 'require_site_key', isset($_POST['require_site_key']) ? '1' : '0');
}

function seed_samples(PDO $pdo, array $user): void
{
    unset($user);
    $samples = [
        [
            'title' => 'Why TinyBlog Widget exists',
            'slug' => 'why-tinyblog-widget-exists',
            'body' => "TinyBlog Widget is for small teams that want owned publishing without a heavy CMS.\n\n- Paste one script tag\n- Write short posts in Markdown\n- Keep analytics and subscriber data on your server\n\n> Small tools are easier to understand, secure, and move.",
            'excerpt' => 'A tiny publishing feed for stores, portfolios, docs, and product updates.',
            'tags' => 'intro, product',
        ],
        [
            'title' => 'A secure default for shared hosting',
            'slug' => 'secure-default-shared-hosting',
            'body' => "The backend uses PDO prepared statements, a CORS origin allowlist, CSRF tokens for admin actions, and moderated upload rules.\n\nScripts are denied in `uploads/`, Markdown is escaped before rendering, and no third-party trackers are included.",
            'excerpt' => 'Shared hosting can still have sane security defaults with a small careful stack.',
            'tags' => 'security, hosting',
        ],
        [
            'title' => 'Embedding a feed on any page',
            'slug' => 'embedding-a-feed-on-any-page',
            'body' => "Add the widget script and set your endpoint.\n\n`<script src=\"https://blog.example.com/tinyblog-widget.js\" data-tb-config='{\"site\":\"store-1\",\"endpoint\":\"https://blog.example.com/api\"}'></script>`\n\nThe widget renders feed, post, and subscribe modes.",
            'excerpt' => 'The one-line embed supports feed, post, and subscribe widgets.',
            'tags' => 'embed, docs',
        ],
    ];
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO posts (site, title, slug, body_markdown, content_html, excerpt, hero_image_url, tags, status, published_at, created_at, updated_at)
        VALUES (:site, :title, :slug, :body_markdown, :content_html, :excerpt, :hero_image_url, :tags, :status, :published_at, :created_at, :updated_at)');
    foreach ($samples as $i => $sample) {
        $published = gmdate('Y-m-d H:i:s', time() - ($i * 86400));
        $stmt->execute([
            ':site' => $site,
            ':title' => $sample['title'],
            ':slug' => $sample['slug'],
            ':body_markdown' => $sample['body'],
            ':content_html' => sanitize_markdown($sample['body']),
            ':excerpt' => $sample['excerpt'],
            ':hero_image_url' => '',
            ':tags' => $sample['tags'],
            ':status' => 'published',
            ':published_at' => $published,
            ':created_at' => now_utc(),
            ':updated_at' => now_utc(),
        ]);
    }
}

try {
    secure_session_start();
    $pdo = db();
    $path = route_path();
    if (str_starts_with($path, '/api/')) {
        handle_api($pdo, $path);
    }
    if ($path === '/feed.xml') {
        render_rss($pdo);
    }
    if ($path === '/sitemap.xml') {
        render_sitemap($pdo);
    }
    if ($path === '/admin') {
        render_admin($pdo);
    }
    if (preg_match('#^/post/([^/]+)$#', $path, $m)) {
        render_post($pdo, rawurldecode($m[1]));
    }
    if (preg_match('#^/tag/([^/]+)$#', $path, $m)) {
        render_tag($pdo, rawurldecode($m[1]));
    }
    if ($path === '/search') {
        render_search($pdo);
    }
    if ($path === '/about') {
        render_about($pdo);
    }
    if ($path === '/archive') {
        render_home($pdo, max(1, (int) ($_GET['page'] ?? 1)));
    }
    render_home($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    write_server_log('error', $e->getMessage(), ['path' => route_path()]);
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            log_event($pdo, 'error', $e->getMessage(), ['path' => route_path()]);
        }
    } catch (Throwable) {
    }
    if (str_starts_with(route_path(), '/api/')) {
        json_response(['error' => 'A server error occurred.'], 500);
    }
    security_headers('html');
    echo '<!doctype html><meta charset="utf-8"><title>TinyBlog error</title><p>A server error occurred. Check the server log.</p>';
}
