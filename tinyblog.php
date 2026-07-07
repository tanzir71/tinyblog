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
            status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft','published')),
            published_at TEXT,
            publish_at TEXT,
            pinned INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            reading_minutes INTEGER NOT NULL DEFAULT 0,
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
            alt_text TEXT NOT NULL DEFAULT '',
            user_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'unconfirmed',
            consent_at TEXT NOT NULL,
            confirm_token TEXT,
            confirmed_at TEXT,
            unsub_token TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            UNIQUE(site, email)
        );
        CREATE TABLE IF NOT EXISTS post_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL,
            viewed_at TEXT NOT NULL,
            UNIQUE(post_id, token_hash),
            FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE
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
        CREATE INDEX IF NOT EXISTS idx_posts_publish_at ON posts(status, publish_at, pinned);
        CREATE INDEX IF NOT EXISTS idx_posts_site_slug ON posts(site, slug);
        CREATE INDEX IF NOT EXISTS idx_post_views_token ON post_views(post_id, token_hash);
        CREATE INDEX IF NOT EXISTS idx_rate_limits_reset ON rate_limits(reset_at);
    ");
    migrate_schema($pdo);
    setup_fts($pdo);

    $defaults = [
        'blog_title' => 'TinyBlog Widget',
        'site_key' => 'store-1',
        'public_site_key' => bin2hex(random_bytes(16)),
        'require_site_key' => '0',
        'posts_per_page' => '10',
        'subscribe_mail_enabled' => '0',
        'allowed_origins' => '',
        'accent_color' => '#2436d4',
        'canonical_base' => base_url(),
        'home_heading' => '',
        'home_intro' => '',
        'about_text' => 'TinyBlog Widget is a small privacy-friendly publishing feed. No third-party trackers are enabled by default.',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
    sync_configured_admin($pdo);
}

function migrate_schema(PDO $pdo): void
{
    if (!column_exists($pdo, 'posts', 'status')) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN status TEXT NOT NULL DEFAULT 'published'");
    }
    if (!column_exists($pdo, 'posts', 'published_at')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN published_at TEXT');
    }
    if (!column_exists($pdo, 'posts', 'publish_at')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN publish_at TEXT');
    }
    if (!column_exists($pdo, 'posts', 'pinned')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0');
    }
    if (!column_exists($pdo, 'posts', 'reading_minutes')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN reading_minutes INTEGER NOT NULL DEFAULT 0');
    }
    if (!column_exists($pdo, 'posts', 'view_count')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0');
    }
    if (!column_exists($pdo, 'media', 'alt_text')) {
        $pdo->exec("ALTER TABLE media ADD COLUMN alt_text TEXT NOT NULL DEFAULT ''");
    }
    if (!column_exists($pdo, 'subscribers', 'confirm_token')) {
        $pdo->exec('ALTER TABLE subscribers ADD COLUMN confirm_token TEXT');
    }
    if (!column_exists($pdo, 'subscribers', 'confirmed_at')) {
        $pdo->exec('ALTER TABLE subscribers ADD COLUMN confirmed_at TEXT');
    }
    if (!column_exists($pdo, 'subscribers', 'unsub_token')) {
        $pdo->exec('ALTER TABLE subscribers ADD COLUMN unsub_token TEXT');
    }

    $now = now_utc();
    $stmt = $pdo->prepare("
        UPDATE posts
        SET status = COALESCE(NULLIF(status, ''), 'published'),
            published_at = COALESCE(NULLIF(published_at, ''), created_at, updated_at, :now),
            publish_at = COALESCE(NULLIF(publish_at, ''), NULLIF(published_at, ''), created_at, updated_at, :now)
    ");
    $stmt->execute([':now' => $now]);
    $subscribers = $pdo->query("SELECT id FROM subscribers WHERE unsub_token IS NULL OR unsub_token = ''")->fetchAll();
    $updateSubscriber = $pdo->prepare('UPDATE subscribers SET unsub_token = :unsub_token, confirmed_at = COALESCE(confirmed_at, CASE WHEN status = :active THEN consent_at ELSE NULL END) WHERE id = :id');
    foreach ($subscribers as $subscriber) {
        $updateSubscriber->execute([
            ':unsub_token' => random_token(),
            ':active' => 'active',
            ':id' => (int) $subscriber['id'],
        ]);
    }
    $pdo->exec('PRAGMA user_version = 3');
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function random_token(): string
{
    return bin2hex(random_bytes(32));
}

function configured_admin_credentials(): ?array
{
    $email = strtolower(trim(config_value('TB_ADMIN_EMAIL', '')));
    $name = trim(config_value('TB_ADMIN_NAME', 'Admin'));
    $password = (string) config_value('TB_ADMIN_PASSWORD', '');
    $passwordHash = trim(config_value('TB_ADMIN_PASSWORD_HASH', ''));
    if ($email === '' && $password === '' && $passwordHash === '') {
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        write_server_log('warning', 'Ignoring configured admin credentials: TB_ADMIN_EMAIL is missing or invalid.');
        return null;
    }
    if ($password !== '') {
        if (strlen($password) < 12) {
            write_server_log('warning', 'Ignoring configured admin credentials: TB_ADMIN_PASSWORD must be at least 12 characters.');
            return null;
        }
        return [
            'email' => $email,
            'name' => $name !== '' ? $name : 'Admin',
            'password' => $password,
            'password_hash' => null,
        ];
    }
    $hashInfo = password_get_info($passwordHash);
    if (($hashInfo['algoName'] ?? 'unknown') === 'unknown') {
        write_server_log('warning', 'Ignoring configured admin credentials: TB_ADMIN_PASSWORD_HASH is not a valid password_hash() value.');
        return null;
    }
    return [
        'email' => $email,
        'name' => $name !== '' ? $name : 'Admin',
        'password' => null,
        'password_hash' => $passwordHash,
    ];
}

function sync_configured_admin(PDO $pdo): void
{
    $credentials = configured_admin_credentials();
    if ($credentials === null) {
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $credentials['email']]);
    $existing = $stmt->fetch();
    $passwordHash = (string) ($credentials['password_hash'] ?? '');
    if (($credentials['password'] ?? null) !== null) {
        $currentHash = $existing ? (string) $existing['password_hash'] : '';
        $passwordHash = $currentHash !== '' && password_verify((string) $credentials['password'], $currentHash) && !password_needs_rehash($currentHash, PASSWORD_DEFAULT)
            ? $currentHash
            : password_hash((string) $credentials['password'], PASSWORD_DEFAULT);
    }
    if ($existing) {
        $update = $pdo->prepare('UPDATE users SET name = :name, password_hash = :password_hash, role = :role WHERE id = :id');
        $update->execute([
            ':name' => $credentials['name'],
            ':password_hash' => $passwordHash,
            ':role' => 'admin',
            ':id' => (int) $existing['id'],
        ]);
        return;
    }
    $insert = $pdo->prepare('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (:email, :name, :password_hash, :role, :created_at)');
    $insert->execute([
        ':email' => $credentials['email'],
        ':name' => $credentials['name'],
        ':password_hash' => $passwordHash,
        ':role' => 'admin',
        ':created_at' => now_utc(),
    ]);
}

function fts5_available(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $options = $pdo->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($options as $option) {
            if (stripos((string) $option, 'ENABLE_FTS5') !== false) {
                return $available = true;
            }
        }
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS temp.tinyblog_fts_probe USING fts5(value)");
        $pdo->exec('DROP TABLE temp.tinyblog_fts_probe');
        return $available = true;
    } catch (Throwable) {
        return $available = false;
    }
}

function setup_fts(PDO $pdo): void
{
    if (!fts5_available($pdo)) {
        return;
    }
    $pdo->exec("
        CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(post_id UNINDEXED, site UNINDEXED, title, excerpt, body);
        CREATE TRIGGER IF NOT EXISTS posts_fts_ai AFTER INSERT ON posts BEGIN
            INSERT INTO posts_fts(rowid, post_id, site, title, excerpt, body)
            VALUES (new.id, new.id, new.site, new.title, new.excerpt, new.body_markdown);
        END;
        CREATE TRIGGER IF NOT EXISTS posts_fts_ad AFTER DELETE ON posts BEGIN
            DELETE FROM posts_fts WHERE rowid = old.id;
        END;
        CREATE TRIGGER IF NOT EXISTS posts_fts_au AFTER UPDATE OF title, excerpt, body_markdown, site ON posts BEGIN
            DELETE FROM posts_fts WHERE rowid = old.id;
            INSERT INTO posts_fts(rowid, post_id, site, title, excerpt, body)
            VALUES (new.id, new.id, new.site, new.title, new.excerpt, new.body_markdown);
        END;
    ");
    $pdo->exec('DELETE FROM posts_fts');
    $pdo->exec('INSERT INTO posts_fts(rowid, post_id, site, title, excerpt, body) SELECT id, id, site, title, excerpt, body_markdown FROM posts');
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
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self'");
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
    $allowed = ['dashboard', 'edit', 'media', 'account', 'users', 'subscribers', 'settings'];
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

function is_probable_bot(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|mediapartners|monitoring/i', $ua) === 1;
}

function track_post_view(PDO $pdo, int $postId): void
{
    if ($postId <= 0 || (string) ($_SERVER['HTTP_DNT'] ?? '') === '1' || is_probable_bot()) {
        return;
    }
    $token = hash('sha256', client_ip_hash() . ':' . gmdate('Y-m-d') . ':' . $postId);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO post_views (post_id, token_hash, viewed_at) VALUES (:post_id, :token_hash, :viewed_at)');
    $stmt->execute([
        ':post_id' => $postId,
        ':token_hash' => $token,
        ':viewed_at' => now_utc(),
    ]);
    if ($stmt->rowCount() > 0) {
        $update = $pdo->prepare('UPDATE posts SET view_count = view_count + 1 WHERE id = :id');
        $update->execute([':id' => $postId]);
    }
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

function highlight_code(string $code, string $language): string
{
    $escaped = htmlEscape($code);
    $language = strtolower($language);
    if (in_array($language, ['php', 'js', 'javascript', 'ts', 'typescript'], true)) {
        return preg_replace('/\b(function|return|const|let|var|if|else|foreach|for|while|class|public|private|new|try|catch|throw|true|false|null)\b/', '<span class="tok-key">$1</span>', $escaped) ?? $escaped;
    }
    if (in_array($language, ['css', 'scss'], true)) {
        return preg_replace('/\b(display|grid|flex|color|background|border|padding|margin|font|width|height)\b/', '<span class="tok-key">$1</span>', $escaped) ?? $escaped;
    }
    if (in_array($language, ['html', 'xml'], true)) {
        return preg_replace('/(&lt;\/?)([a-z0-9-]+)/i', '$1<span class="tok-key">$2</span>', $escaped) ?? $escaped;
    }
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
    $codeLanguage = '';

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
    $flushCode = function () use (&$html, &$code, &$codeLanguage): void {
        if ($code) {
            $class = $codeLanguage !== '' ? ' class="language-' . htmlEscape($codeLanguage) . '"' : '';
            $html[] = '<pre><code' . $class . '>' . highlight_code(implode("\n", $code), $codeLanguage) . '</code></pre>';
            $code = [];
            $codeLanguage = '';
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
                $codeLanguage = preg_replace('/[^a-z0-9_-]+/i', '', trim(substr($trimmed, 3))) ?: '';
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

function markdown_word_count(string $markdown): int
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags(sanitize_markdown($markdown))) ?? '');
    if ($plain === '') {
        return 0;
    }
    if (preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}'-]*/u", $plain, $matches)) {
        return count($matches[0]);
    }
    return count(preg_split('/\s+/', $plain) ?: []);
}

function reading_minutes_from_markdown(string $markdown): int
{
    $words = markdown_word_count($markdown);
    if ($words < 100) {
        return 0;
    }
    return max(1, (int) ceil($words / 220));
}

function post_reading_minutes(array $post): int
{
    $stored = (int) ($post['reading_minutes'] ?? 0);
    if ($stored > 0) {
        return $stored;
    }
    return reading_minutes_from_markdown((string) ($post['body_markdown'] ?? ''));
}

function reading_time_label(int $minutes): string
{
    return $minutes > 0 ? '~' . $minutes . ' min read' : '';
}

function post_publish_at(array $post): string
{
    return (string) (($post['publish_at'] ?? '') ?: ($post['published_at'] ?? '') ?: ($post['created_at'] ?? ''));
}

function normalize_publish_at(string $value, string $status): ?string
{
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') {
        return $status === 'published' ? now_utc() : null;
    }
    $time = strtotime($value . (preg_match('/Z|[+-]\d{2}:?\d{2}$/', $value) ? '' : ' UTC'));
    return $time ? gmdate('Y-m-d H:i:s', $time) : now_utc();
}

function visible_post_where(string $prefix = ''): string
{
    $p = $prefix !== '' ? rtrim($prefix, '.') . '.' : '';
    return $p . 'site = :site AND ' . $p . "status = :status AND (" . $p . 'publish_at IS NULL OR ' . $p . 'publish_at <= :now)';
}

function visible_post_params(string $site): array
{
    return [
        ':site' => $site,
        ':status' => 'published',
        ':now' => now_utc(),
    ];
}

function posts_per_page(PDO $pdo): int
{
    return max(1, min(50, (int) setting($pdo, 'posts_per_page', '10') ?: 10));
}

function page_url(string $path, int $page, array $params = []): string
{
    $params['page'] = max(1, $page);
    return url_for($path) . '?' . http_build_query($params);
}

function pagination_links(string $path, int $page, bool $hasMore, array $params = []): string
{
    if ($page <= 1 && !$hasMore) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Pagination">';
    if ($page > 1) {
        $html .= '<a rel="prev" href="' . htmlEscape(page_url($path, $page - 1, $params)) . '">Previous</a>';
    }
    if ($hasMore) {
        $html .= '<a rel="next" href="' . htmlEscape(page_url($path, $page + 1, $params)) . '">Next</a>';
    }
    return $html . '</nav>';
}

function fts_query(string $query): string
{
    if (!preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_-]*/u', $query, $matches)) {
        return '';
    }
    return implode(' ', array_slice(array_map(fn (string $term): string => '"' . str_replace('"', '""', $term) . '"', $matches[0]), 0, 8));
}

function search_posts(PDO $pdo, string $site, string $query, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    $rows = [];
    $match = fts_query($query);
    if ($match !== '' && fts5_available($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT p.* FROM posts_fts JOIN posts p ON p.id = posts_fts.post_id WHERE posts_fts MATCH :match AND ' . visible_post_where('p') . ' ORDER BY bm25(posts_fts), datetime(p.publish_at) DESC LIMIT :limit OFFSET :offset');
            $stmt->bindValue(':match', $match, PDO::PARAM_STR);
            foreach (visible_post_params($site) as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return ['rows' => array_slice($rows, 0, $perPage), 'hasMore' => count($rows) > $perPage, 'mode' => 'fts5'];
        } catch (Throwable) {
            $rows = [];
        }
    }

    $like = '%' . $query . '%';
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' AND (title LIKE :q OR excerpt LIKE :q OR body_markdown LIKE :q) ORDER BY datetime(publish_at) DESC LIMIT :limit OFFSET :offset');
    foreach (visible_post_params($site) as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':q', $like, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return ['rows' => array_slice($rows, 0, $perPage), 'hasMore' => count($rows) > $perPage, 'mode' => 'like'];
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

function content_last_modified(PDO $pdo, string $site): string
{
    $stmt = $pdo->prepare('SELECT MAX(updated_at) FROM posts WHERE site = :site');
    $stmt->execute([':site' => $site]);
    return (string) ($stmt->fetchColumn() ?: now_utc());
}

function etag_for(string $scope, string $lastModified): string
{
    return '"' . hash('sha256', TB_VERSION . ':' . $scope . ':' . $lastModified) . '"';
}

function maybe_not_modified(string $etag, string $lastModified): void
{
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate(DATE_RFC7231, strtotime($lastModified) ?: time()));
    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
        http_response_code(304);
        exit;
    }
    $ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    $last = strtotime($lastModified) ?: time();
    if ($ifModifiedSince && $ifModifiedSince >= $last) {
        http_response_code(304);
        exit;
    }
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
    $readingMinutes = post_reading_minutes($post);
    $payload = [
        'id' => (int) $post['id'],
        'slug' => $post['slug'],
        'title' => $post['title'],
        'excerpt' => $post['excerpt'],
        'published_at' => post_publish_at($post),
        'publish_at' => post_publish_at($post),
        'reading_minutes' => $readingMinutes,
        'reading_time' => reading_time_label($readingMinutes),
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
        $lastModified = content_last_modified($pdo, $site);
        maybe_not_modified(etag_for('api-posts:' . $site, $lastModified), $lastModified);
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' ORDER BY pinned DESC, datetime(publish_at) DESC, id DESC LIMIT :limit OFFSET :offset');
        foreach (visible_post_params($site) as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $posts = array_map(fn (array $post): array => post_to_api($pdo, $post, true), $rows);
        json_response([
            'site' => $site,
            'title' => setting($pdo, 'blog_title', 'TinyBlog Widget'),
            'items' => $posts,
            'posts' => $posts,
            'page' => $page,
            'hasMore' => $hasMore,
            'generated_at' => now_utc(),
        ]);
    }

    if (preg_match('#^/api/posts/([^/]+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $site = (string) ($_GET['site'] ?? setting($pdo, 'site_key', 'store-1'));
        validate_site($pdo, $site);
        $slug = rawurldecode($m[1]);
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' AND slug = :slug LIMIT 1');
        $stmt->execute(visible_post_params($site) + [':slug' => $slug]);
        $post = $stmt->fetch();
        if (!$post) {
            json_response(['error' => 'Post not found.'], 404);
        }
        maybe_not_modified(etag_for('api-post:' . $site . ':' . $slug, (string) $post['updated_at']), (string) $post['updated_at']);
        track_post_view($pdo, (int) $post['id']);
        $views = $pdo->prepare('SELECT view_count FROM posts WHERE id = :id');
        $views->execute([':id' => (int) $post['id']]);
        $post['view_count'] = (int) $views->fetchColumn();
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
        $existing = $pdo->prepare('SELECT status, unsub_token FROM subscribers WHERE site = :site AND email = :email LIMIT 1');
        $existing->execute([':site' => $site, ':email' => $email]);
        $subscriber = $existing->fetch();
        if ($subscriber && ($subscriber['status'] ?? '') === 'active') {
            json_response(['ok' => true, 'message' => 'Already subscribed.']);
        }
        $confirmToken = random_token();
        $unsubToken = (string) (($subscriber['unsub_token'] ?? '') ?: random_token());
        $stmt = $pdo->prepare('INSERT INTO subscribers (site, email, status, consent_at, confirm_token, confirmed_at, unsub_token, ip_hash, user_agent)
            VALUES (:site, :email, :status, :consent_at, :confirm_token, NULL, :unsub_token, :ip_hash, :user_agent)
            ON CONFLICT(site, email) DO UPDATE SET status = :status, consent_at = excluded.consent_at, confirm_token = excluded.confirm_token, confirmed_at = NULL, unsub_token = excluded.unsub_token');
        $stmt->execute([
            ':site' => $site,
            ':email' => $email,
            ':status' => 'unconfirmed',
            ':consent_at' => now_utc(),
            ':confirm_token' => $confirmToken,
            ':unsub_token' => $unsubToken,
            ':ip_hash' => client_ip_hash(),
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
        ]);
        $confirmUrl = canonical_url($pdo, '/subscribe/confirm/' . rawurlencode($confirmToken));
        if (setting($pdo, 'subscribe_mail_enabled', '0') === '1') {
            @mail($email, 'Confirm your TinyBlog subscription', "Confirm your subscription:\n\n" . $confirmUrl);
        }
        json_response(['ok' => true, 'message' => 'Confirm your subscription to finish.', 'confirm_url' => $confirmUrl]);
    }

    if ($path === '/api/feed.xml' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        render_rss($pdo);
    }

    json_response(['error' => 'API route not found.'], 404);
}

function app_icon_head_tags(): string
{
    if (!is_file(__DIR__ . '/assets/logo.svg')) {
        return '';
    }
    return '<link rel="icon" href="/assets/logo.svg"><meta name="theme-color" content="#f4f3ee" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#131210" media="(prefers-color-scheme: dark)">';
}

function render_page(PDO $pdo, string $title, string $body, array $meta = []): void
{
    $blogTitle = setting($pdo, 'blog_title', 'TinyBlog Widget');
    $accent = setting($pdo, 'accent_color', '#2436d4');
    $description = $meta['description'] ?? 'A tiny privacy-friendly embeddable blog feed.';
    $canonical = $meta['canonical'] ?? canonical_url($pdo, route_path());
    $ogImage = $meta['og_image'] ?? '';
    $prevUrl = $meta['prev'] ?? '';
    $nextUrl = $meta['next'] ?? '';
    $jsonLd = $meta['json_ld'] ?? null;
    security_headers('html');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlEscape($title . ' - ' . $blogTitle) . '</title>';
    echo '<meta name="description" content="' . htmlEscape($description) . '">';
    echo '<link rel="canonical" href="' . htmlEscape($canonical) . '">';
    if ($prevUrl !== '') {
        echo '<link rel="prev" href="' . htmlEscape($prevUrl) . '">';
    }
    if ($nextUrl !== '') {
        echo '<link rel="next" href="' . htmlEscape($nextUrl) . '">';
    }
    echo '<meta property="og:title" content="' . htmlEscape($title) . '">';
    echo '<meta property="og:description" content="' . htmlEscape($description) . '">';
    if ($ogImage !== '') {
        echo '<meta property="og:image" content="' . htmlEscape($ogImage) . '">';
    }
    if (is_array($jsonLd)) {
        echo '<script type="application/ld+json">' . (json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') . '</script>';
    }
    echo '<link rel="alternate" type="application/rss+xml" title="' . htmlEscape($blogTitle) . '" href="' . htmlEscape(url_for('/feed.xml')) . '">';
    echo '<link rel="alternate" type="application/feed+json" title="' . htmlEscape($blogTitle) . '" href="' . htmlEscape(url_for('/feed.json')) . '">';
    echo app_icon_head_tags();
    echo '<style>';
    echo css_base($accent);
    echo '</style></head><body><div class="site">';
    echo '<header class="topbar"><a class="brand" href="' . htmlEscape(url_for('/')) . '">' . htmlEscape($blogTitle) . '</a><nav><a href="' . htmlEscape(url_for('/archive')) . '">Archive</a><a href="' . htmlEscape(url_for('/about')) . '">About</a><a href="' . htmlEscape(url_for('/admin')) . '">Admin</a></nav></header>';
    echo $body;
    echo '<footer class="footer"><p>Privacy: this site stores subscriber emails only for opt-in delivery. No third-party trackers are enabled by default. Enable HTTPS and keep file permissions tight.</p><p><a href="' . TB_REPO_URL . '">GitHub</a> / <a href="' . htmlEscape(url_for('/SETUP.md')) . '">Docs</a> / <a href="' . htmlEscape(url_for('/SECURITY.md')) . '">Security</a> / <a href="' . htmlEscape(url_for('/feed.xml')) . '">RSS</a> / <a href="' . htmlEscape(url_for('/sitemap.xml')) . '">Sitemap</a></p></footer>';
    echo '</div><script>
        document.querySelectorAll("pre > code").forEach(function (code) {
            var pre = code.parentElement;
            if (!pre || pre.querySelector("[data-copy-code]")) return;
            var button = document.createElement("button");
            button.type = "button";
            button.textContent = "Copy code";
            button.setAttribute("data-copy-code", "");
            pre.insertBefore(button, code);
            button.addEventListener("click", function () {
                var done = function () {
                    button.textContent = "Copied";
                    setTimeout(function () { button.textContent = "Copy code"; }, 1000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code.textContent || "").then(done).catch(function () {});
                    return;
                }
                var helper = document.createElement("textarea");
                helper.value = code.textContent || "";
                helper.setAttribute("readonly", "");
                helper.style.position = "fixed";
                helper.style.left = "-9999px";
                document.body.appendChild(helper);
                helper.select();
                document.execCommand("copy");
                document.body.removeChild(helper);
                done();
            });
        });
    </script></body></html>';
    exit;
}

function css_base(string $accent): string
{
    $safeAccent = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#2436d4';
    return "
        :root{color-scheme:light;--paper:#f4f3ee;--panel:#faf9f5;--ink:#0a0a0a;--ink-soft:#2b2a27;--muted:#6c6a62;--line:#dcd9d0;--line-strong:#0a0a0a;--accent:{$safeAccent};--accent-soft:#eceaf9;--bg:var(--paper);--text:var(--ink);--soft:var(--panel);--max:1120px;--measure:760px}
        @media (prefers-color-scheme: dark){:root{color-scheme:dark;--paper:#131210;--panel:#1a1917;--ink:#f2f1ec;--ink-soft:#d7d5cd;--muted:#9d9a90;--line:#2c2a26;--line-strong:#f2f1ec;--accent-soft:#1e2033;--bg:var(--paper);--text:var(--ink);--soft:var(--panel)}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;letter-spacing:0}
        a{color:inherit;text-decoration-thickness:1px;text-underline-offset:3px}
        a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
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
        .excerpt{font-size:17px;line-height:1.65;color:var(--ink-soft);max-width:var(--measure)}
        .article{max-width:var(--measure);padding:42px 0}
        .article h1{font-size:clamp(40px,8vw,72px);line-height:1;margin:0 0 14px}
        .content{font-size:18px;line-height:1.78}
        .content p,.content ul,.content ol,.content blockquote{margin:0 0 1.2em}
        .content blockquote{border-left:2px solid var(--text);padding-left:16px;color:var(--ink-soft)}
        .content code{background:var(--soft);border:1px solid var(--line);padding:2px 5px;border-radius:4px}
        .content pre{position:relative;overflow:auto;background:var(--soft);border:1px solid var(--line);padding:46px 14px 14px}
        .content pre code{display:block;border:0;padding:0;background:transparent;white-space:pre}
        .content .tok-key{font-weight:800;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}
        [data-copy-code]{position:absolute;top:10px;right:10px;border:1px solid var(--text);background:var(--panel);color:var(--text);padding:6px 9px;font:inherit;font-size:12px;font-weight:650;cursor:pointer}
        .tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
        .tag{border:1px solid var(--line);padding:5px 9px;border-radius:999px;text-decoration:none;font-size:13px}
        .pagination{display:flex;gap:10px;margin:24px 0 0}
        .pagination a{border:1px solid var(--text);padding:9px 12px;text-decoration:none;font-weight:650}
        .panel{border:1px solid var(--line);padding:18px;background:var(--panel)}
        .admin-shell{padding:0 0 40px}
        .admin-topbar{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:18px 0;border-bottom:1px solid var(--line)}
        .admin-brand{display:inline-flex;align-items:center;gap:10px;text-decoration:none;font-weight:750;min-width:0}
        .admin-brand-mark{display:inline-grid;place-items:center;width:28px;height:28px;background:var(--text);color:var(--paper);font-size:12px;font-weight:800;letter-spacing:0}
        .admin-brand-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .admin-topbar-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:flex-end}
        .admin-topbar-link,.admin-logout{color:var(--muted);font-size:14px;font-weight:650;text-decoration:none}
        .admin-topbar-link:hover,.admin-logout:hover{color:var(--text)}
        .auth-shell{min-height:100vh;display:grid;place-items:center;padding:40px 0}
        .auth-card{width:min(100%,440px);border:1px solid var(--line);background:var(--panel);padding:24px}
        .auth-brand{display:inline-flex;align-items:center;gap:10px;margin:0 0 22px;text-decoration:none;font-weight:800}
        .auth-mark{display:inline-grid;place-items:center;width:28px;height:28px;background:var(--text);color:var(--paper);font-size:12px;font-weight:800}
        .admin-layout{display:grid;grid-template-columns:1fr;gap:20px;padding:24px 0}
        .admin-nav{display:flex;gap:16px;overflow-x:auto;padding:0 0 10px;border-bottom:1px solid var(--line)}
        .admin-nav-link{color:var(--muted);text-decoration:none;font-size:14px;font-weight:700;padding:8px 0 9px;border-bottom:2px solid transparent;white-space:nowrap}
        .admin-nav-link:hover{color:var(--text)}
        .admin-nav-link[aria-current=\"page\"]{color:var(--text);border-color:var(--accent)}
        .admin-content{width:100%;max-width:920px;min-width:0}
        .stat-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));border:1px solid var(--line);background:var(--panel);margin:0 0 20px}
        .stat-cell{padding:16px;border-right:1px solid var(--line);min-width:0}
        .stat-cell:last-child{border-right:0}
        .stat-value{font-size:28px;line-height:1;font-weight:800;letter-spacing:0;margin:0 0 8px}
        .stat-label{font-size:12px;font-weight:750;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin:0}
        .status-badge{display:inline-flex;align-items:center;border:1px solid var(--line-strong);padding:3px 8px;font-size:12px;font-weight:750;text-transform:uppercase;letter-spacing:.08em}
        .status-badge.published{background:var(--text);color:var(--paper)}
        .status-badge.draft{background:transparent;color:var(--text)}
        .status-badge.scheduled{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
        tr:hover td{background:var(--accent-soft)}
        .row-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;font-size:13px}
        .link-button{border:0;background:transparent;color:inherit;padding:0;font:inherit;font-weight:650;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
        .empty-state{border:1px solid var(--line);background:var(--panel);padding:22px;margin:18px 0}
        .editor-grid{display:grid;gap:18px;align-items:start}
        .editor-main,.editor-side{min-width:0}
        .editor-main textarea{min-height:460px}
        .markdown-toolbar{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
        .markdown-toolbar button{min-width:34px;padding:7px 9px;background:var(--panel);color:var(--text)}
        .editor-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px}
        .editor-preview{background:var(--panel)}
        .editor-preview.is-collapsed{display:none}
        .editor-preview[aria-busy=\"true\"]{opacity:.72}
        .post-settings{border:1px solid var(--line);background:var(--panel);padding:14px;margin-top:16px}
        .post-settings summary{cursor:pointer;font-weight:800;margin:-2px 0 12px}
        .upload-dropzone{border:1px dashed var(--line-strong);background:var(--panel);padding:16px;margin:0 0 20px}
        .upload-dropzone.is-dragover{background:var(--accent-soft);border-color:var(--accent)}
        .media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin:20px 0 0}
        .media-card{border:1px solid var(--line);background:var(--panel);min-width:0}
        .media-thumb{display:block;width:100%;aspect-ratio:1;object-fit:cover;border-bottom:1px solid var(--line)}
        .media-card-body{padding:12px;display:grid;gap:8px}
        .media-card-body code{font-size:12px;word-break:break-all}
        .media-card-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .button,button{border:1px solid var(--text);background:var(--text);color:var(--paper);text-decoration:none;padding:10px 13px;border-radius:0;cursor:pointer;font-weight:650;font-size:14px}
        .button:hover,button:hover{background:transparent;color:var(--text)}
        .button.secondary,button.secondary{background:var(--panel);color:var(--text)}
        .button.secondary:hover,button.secondary:hover{background:var(--text);color:var(--paper)}
        .admin-logout{border:0;background:transparent;padding:0}
        label{display:grid;gap:7px;font-size:13px;font-weight:650;margin:0 0 14px}
        input,textarea,select{width:100%;border:1px solid var(--line);padding:11px 12px;background:var(--panel);color:var(--text)}
        textarea{min-height:230px;line-height:1.55}
        table{width:100%;border-collapse:collapse;font-size:14px}
        caption{text-align:left;font-weight:800;margin:18px 0 8px}
        th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px 8px;vertical-align:top}
        .notice{padding:12px 14px;border:1px solid var(--line);background:var(--soft);margin:0 0 18px}
        .notice.success{border-color:var(--accent);background:var(--accent-soft)}
        .notice.error,.error{border-color:var(--line-strong);background:var(--panel)}
        .footer{border-top:1px solid var(--line);padding:28px 0 42px;color:var(--muted);font-size:13px;line-height:1.6}
        @media(min-width:820px){.grid{grid-template-columns:minmax(0,1fr) 280px}.admin-layout{grid-template-columns:180px minmax(0,1fr);align-items:start}.admin-nav{display:grid;gap:4px;align-content:start;border-bottom:0;border-right:1px solid var(--line);padding:0 18px 0 0;overflow:visible}.admin-nav-link{padding:9px 0}.admin-content{max-width:980px}}
        @media(min-width:1000px){.editor-grid{grid-template-columns:minmax(0,1fr) minmax(320px,.8fr)}.editor-preview.is-collapsed{display:block}.editor-preview{position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}}
    ";
}

function render_home(PDO $pdo, int $page = 1): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $page = max(1, (int) ($_GET['page'] ?? $page));
    $perPage = posts_per_page($pdo);
    $offset = max(0, ($page - 1) * $perPage);
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' ORDER BY pinned DESC, datetime(publish_at) DESC, id DESC LIMIT :limit OFFSET :offset');
    foreach (visible_post_params($site) as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $perPage;
    $posts = array_slice($rows, 0, $perPage);
    $homeHeading = trim(setting($pdo, 'home_heading', ''));
    if ($homeHeading === '') {
        $homeHeading = setting($pdo, 'blog_title', 'TinyBlog Widget');
    }
    $homeIntro = trim(setting($pdo, 'home_intro', ''));
    $body = '<section class="hero"><h1>' . htmlEscape($homeHeading) . '</h1>';
    if ($homeIntro !== '') {
        $body .= '<p>' . htmlEscape($homeIntro) . '</p>';
    }
    $body .= '</section><main class="grid"><section class="post-list">';
    if (!$posts) {
        $body .= '<p class="muted">No published posts yet. Visit admin to create the first one.</p>';
    }
    foreach ($posts as $post) {
        $body .= post_row($post);
    }
    $body .= pagination_links('/archive', $page, $hasMore);
    $body .= '</section><aside class="panel"><form method="get" action="' . htmlEscape(url_for('/search')) . '"><label>Search<input name="q" placeholder="Search posts"></label><button>Search</button></form><p class="muted">Widget API endpoint: <code>' . htmlEscape(canonical_url($pdo, '/api')) . '</code></p></aside></main>';
    render_page($pdo, 'Home', $body, [
        'description' => 'A privacy-friendly embeddable blog widget and tiny backend.',
        'prev' => $page > 1 ? canonical_url($pdo, page_url('/archive', $page - 1)) : '',
        'next' => $hasMore ? canonical_url($pdo, page_url('/archive', $page + 1)) : '',
    ]);
}

function valid_archive_month(string $month): bool
{
    return $month === '' || preg_match('/^\d{4}-\d{2}$/', $month) === 1;
}

function archive_months(PDO $pdo, string $site): array
{
    $stmt = $pdo->prepare("SELECT strftime('%Y-%m', publish_at) AS month, COUNT(*) AS count FROM posts WHERE " . visible_post_where() . " AND publish_at IS NOT NULL GROUP BY month ORDER BY month DESC LIMIT 60");
    $stmt->execute(visible_post_params($site));
    return array_values(array_filter($stmt->fetchAll(), fn (array $row): bool => !empty($row['month'])));
}

function render_archive(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $month = trim((string) ($_GET['month'] ?? ''));
    if (!valid_archive_month($month)) {
        $month = '';
    }
    $perPage = posts_per_page($pdo);
    $offset = max(0, ($page - 1) * $perPage);
    $where = visible_post_where();
    if ($month !== '') {
        $where .= " AND strftime('%Y-%m', publish_at) = :month";
    }
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . $where . ' ORDER BY pinned DESC, datetime(publish_at) DESC, id DESC LIMIT :limit OFFSET :offset');
    foreach (visible_post_params($site) as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    if ($month !== '') {
        $stmt->bindValue(':month', $month, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $perPage;
    $posts = array_slice($rows, 0, $perPage);
    $title = $month !== '' ? 'Archive for ' . $month : 'Archive';
    $body = '<main class="grid"><section class="post-list"><h1>' . htmlEscape($title) . '</h1>';
    if (!$posts) {
        $body .= '<p class="muted">No published posts found for this archive view.</p>';
    }
    foreach ($posts as $post) {
        $body .= post_row($post);
    }
    if ($month !== '') {
        $body .= pagination_links('/archive', $page, $hasMore, ['month' => $month]);
    } else {
        $body .= pagination_links('/archive', $page, $hasMore);
    }
    $body .= '</section><aside class="panel archive-months"><h2>By month</h2><p><a href="' . htmlEscape(url_for('/archive')) . '">All posts</a></p>';
    foreach (archive_months($pdo, $site) as $row) {
        $label = (string) $row['month'];
        $body .= '<p><a href="' . htmlEscape(url_for('/archive') . '?month=' . rawurlencode($label)) . '">' . htmlEscape($label) . '</a> <span class="muted">(' . (int) $row['count'] . ')</span></p>';
    }
    $body .= '</aside></main>';
    $prev = '';
    $next = '';
    if ($page > 1) {
        $prev = $month !== '' ? canonical_url($pdo, page_url('/archive', $page - 1, ['month' => $month])) : canonical_url($pdo, page_url('/archive', $page - 1));
    }
    if ($hasMore) {
        $next = $month !== '' ? canonical_url($pdo, page_url('/archive', $page + 1, ['month' => $month])) : canonical_url($pdo, page_url('/archive', $page + 1));
    }
    render_page($pdo, $title, $body, [
        'description' => 'Browse published posts by month.',
        'prev' => $prev,
        'next' => $next,
    ]);
}

function post_row(array $post): string
{
    $tags = split_tags($post['tags']);
    $reading = reading_time_label(post_reading_minutes($post));
    $meta = htmlEscape(post_publish_at($post)) . ($reading !== '' ? ' &middot; ' . htmlEscape($reading) : '');
    $html = '<article class="post-row"><p class="meta">' . $meta . '</p>';
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

function related_posts(PDO $pdo, array $post, int $limit = 3): array
{
    $tags = split_tags((string) ($post['tags'] ?? ''));
    if (!$tags) {
        return [];
    }
    $site = (string) ($post['site'] ?? setting($pdo, 'site_key', 'store-1'));
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' AND id != :id ORDER BY datetime(publish_at) DESC LIMIT 80');
    $params = visible_post_params($site) + [':id' => (int) $post['id']];
    $stmt->execute($params);
    $scored = [];
    foreach ($stmt->fetchAll() as $candidate) {
        $score = count(array_intersect($tags, split_tags((string) $candidate['tags'])));
        if ($score > 0) {
            $candidate['_score'] = $score;
            $scored[] = $candidate;
        }
    }
    usort($scored, fn (array $a, array $b): int => ((int) $b['_score'] <=> (int) $a['_score']) ?: strcmp((string) $b['publish_at'], (string) $a['publish_at']));
    return array_slice($scored, 0, $limit);
}

function render_post(PDO $pdo, string $slug): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' AND slug = :slug LIMIT 1');
    $stmt->execute(visible_post_params($site) + [':slug' => $slug]);
    $post = $stmt->fetch();
    if (!$post) {
        http_response_code(404);
        render_page($pdo, 'Not found', '<main class="article"><h1>Not found</h1><p class="muted">That post does not exist or is not published.</p></main>');
    }
    track_post_view($pdo, (int) $post['id']);
    $reading = reading_time_label(post_reading_minutes($post));
    $meta = htmlEscape(post_publish_at($post)) . ($reading !== '' ? ' &middot; ' . htmlEscape($reading) : '');
    $body = '<main class="article"><p class="meta">' . $meta . '</p><h1>' . htmlEscape($post['title']) . '</h1>';
    if (!empty($post['hero_image_url'])) {
        $body .= '<img src="' . htmlEscape($post['hero_image_url']) . '" alt="" loading="lazy">';
    }
    $body .= '<div class="content">' . $post['content_html'] . '</div>';
    $body .= '<div class="tags">';
    foreach (split_tags($post['tags']) as $tag) {
        $body .= '<a class="tag" href="' . htmlEscape(url_for('/tag/' . rawurlencode($tag))) . '">#' . htmlEscape($tag) . '</a>';
    }
    $body .= '</div>';
    $related = related_posts($pdo, $post);
    if ($related) {
        $body .= '<section class="related"><h2>Related posts</h2>';
        foreach ($related as $candidate) {
            $body .= post_row($candidate);
        }
        $body .= '</section>';
    }
    $body .= '</main>';
    $canonical = canonical_url($pdo, '/post/' . rawurlencode($post['slug']));
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'],
        'description' => $post['excerpt'],
        'datePublished' => post_publish_at($post),
        'dateModified' => (string) $post['updated_at'],
        'author' => [
            '@type' => 'Organization',
            'name' => setting($pdo, 'blog_title', 'TinyBlog Widget'),
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $canonical,
        ],
    ];
    if (!empty($post['hero_image_url'])) {
        $jsonLd['image'] = (string) $post['hero_image_url'];
    }
    render_page($pdo, $post['title'], $body, [
        'description' => $post['excerpt'],
        'canonical' => $canonical,
        'og_image' => (string) ($post['hero_image_url'] ?? ''),
        'json_ld' => $jsonLd,
    ]);
}

function render_tag(PDO $pdo, string $tag): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = posts_per_page($pdo);
    $offset = ($page - 1) * $perPage;
    $needle = '%' . $tag . '%';
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' AND tags LIKE :tag ORDER BY datetime(publish_at) DESC LIMIT :limit OFFSET :offset');
    foreach (visible_post_params($site) as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':tag', $needle, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $perPage;
    $body = '<main class="article"><h1>#' . htmlEscape($tag) . '</h1>';
    foreach (array_slice($rows, 0, $perPage) as $post) {
        $body .= post_row($post);
    }
    $body .= pagination_links('/tag/' . rawurlencode($tag), $page, $hasMore) . '</main>';
    render_page($pdo, 'Tag ' . $tag, $body, [
        'prev' => $page > 1 ? canonical_url($pdo, page_url('/tag/' . rawurlencode($tag), $page - 1)) : '',
        'next' => $hasMore ? canonical_url($pdo, page_url('/tag/' . rawurlencode($tag), $page + 1)) : '',
    ]);
}

function render_search(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $q = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = posts_per_page($pdo);
    $offset = ($page - 1) * $perPage;
    $hasMore = false;
    $body = '<main class="article"><h1>Search</h1><form method="get"><label>Query<input name="q" value="' . htmlEscape($q) . '"></label><button>Search</button></form>';
    if ($q !== '') {
        $result = search_posts($pdo, $site, $q, $page, $perPage);
        $hasMore = (bool) $result['hasMore'];
        foreach ($result['rows'] as $post) {
            $body .= post_row($post);
        }
        $body .= pagination_links('/search', $page, $hasMore, ['q' => $q]);
    }
    $body .= '</main>';
    render_page($pdo, 'Search', $body, [
        'prev' => $q !== '' && $page > 1 ? canonical_url($pdo, page_url('/search', $page - 1, ['q' => $q])) : '',
        'next' => $q !== '' && $hasMore ? canonical_url($pdo, page_url('/search', $page + 1, ['q' => $q])) : '',
    ]);
}

function render_about(PDO $pdo): void
{
    $body = '<main class="article"><h1>About</h1><div class="content"><p>' . htmlEscape(setting($pdo, 'about_text', 'A tiny embeddable blog.')) . '</p><p>Privacy: subscriber emails are used for opt-in updates. No third-party trackers are enabled by default.</p></div></main>';
    render_page($pdo, 'About', $body);
}

function render_subscribe_confirm(PDO $pdo, string $token): void
{
    if (!rate_limit($pdo, 'subscribe_confirm', 60, 3600)) {
        http_response_code(429);
        render_page($pdo, 'Too many attempts', '<main class="article"><h1>Too many attempts</h1><p class="muted">Try again later.</p></main>');
    }
    $stmt = $pdo->prepare('UPDATE subscribers SET status = :status, confirmed_at = :confirmed_at, confirm_token = NULL WHERE confirm_token = :confirm_token AND confirm_token IS NOT NULL');
    $stmt->execute([
        ':status' => 'active',
        ':confirmed_at' => now_utc(),
        ':confirm_token' => $token,
    ]);
    $body = '<main class="article"><h1>Subscription confirmed</h1><p class="muted">You are subscribed. Every email should include a one-click unsubscribe link.</p></main>';
    render_page($pdo, 'Subscription confirmed', $body);
}

function render_unsubscribe(PDO $pdo, string $token): void
{
    if (!rate_limit($pdo, 'unsubscribe', 60, 3600)) {
        http_response_code(429);
        render_page($pdo, 'Too many attempts', '<main class="article"><h1>Too many attempts</h1><p class="muted">Try again later.</p></main>');
    }
    $stmt = $pdo->prepare('UPDATE subscribers SET status = :status, confirm_token = NULL WHERE unsub_token = :unsub_token');
    $stmt->execute([
        ':status' => 'unsubscribed',
        ':unsub_token' => $token,
    ]);
    $body = '<main class="article"><h1>Unsubscribed</h1><p class="muted">That address is unsubscribed if it was on this list.</p></main>';
    render_page($pdo, 'Unsubscribed', $body);
}

function render_rss(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $lastModified = content_last_modified($pdo, $site);
    maybe_not_modified(etag_for('rss:' . $site, $lastModified), $lastModified);
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' ORDER BY datetime(publish_at) DESC LIMIT 30');
    $stmt->execute(visible_post_params($site));
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
        echo '<pubDate>' . htmlEscape(gmdate(DATE_RSS, strtotime(post_publish_at($post)) ?: time())) . '</pubDate>';
        echo '<description>' . htmlEscape($post['excerpt']) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

function render_json_feed(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $lastModified = content_last_modified($pdo, $site);
    maybe_not_modified(etag_for('json-feed:' . $site, $lastModified), $lastModified);
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' ORDER BY datetime(publish_at) DESC LIMIT 30');
    $stmt->execute(visible_post_params($site));
    $items = [];
    foreach ($stmt->fetchAll() as $post) {
        $item = [
            'id' => canonical_url($pdo, '/post/' . rawurlencode($post['slug'])),
            'url' => canonical_url($pdo, '/post/' . rawurlencode($post['slug'])),
            'title' => $post['title'],
            'content_html' => $post['content_html'],
            'summary' => $post['excerpt'],
            'date_published' => gmdate(DATE_ATOM, strtotime(post_publish_at($post)) ?: time()),
            'date_modified' => gmdate(DATE_ATOM, strtotime((string) $post['updated_at']) ?: time()),
            'tags' => split_tags((string) $post['tags']),
        ];
        if (!empty($post['hero_image_url'])) {
            $item['image'] = (string) $post['hero_image_url'];
        }
        $items[] = $item;
    }
    security_headers('json');
    header('Content-Type: application/feed+json; charset=utf-8');
    echo json_encode([
        'version' => 'https://jsonfeed.org/version/1.1',
        'title' => setting($pdo, 'blog_title', 'TinyBlog Widget'),
        'home_page_url' => canonical_url($pdo, '/'),
        'feed_url' => canonical_url($pdo, '/feed.json'),
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function llms_text(string $value): string
{
    return trim((string) preg_replace('/\s+/', ' ', $value));
}

function render_llms_txt(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $title = llms_text(setting($pdo, 'blog_title', 'TinyBlog Widget'));
    $description = llms_text(setting($pdo, 'home_intro', ''));
    if ($description === '') {
        $description = llms_text(setting($pdo, 'about_text', 'A tiny embeddable blog.'));
    }
    $stmt = $pdo->prepare('SELECT title, slug, excerpt FROM posts WHERE ' . visible_post_where() . ' ORDER BY datetime(publish_at) DESC, id DESC LIMIT 200');
    $stmt->execute(visible_post_params($site));
    security_headers('text');
    header('Content-Type: text/plain; charset=utf-8');
    echo '# ' . $title . "\n\n";
    echo '> ' . $description . "\n\n";
    echo 'Home: ' . canonical_url($pdo, '/') . "\n\n";
    echo "## Posts\n\n";
    foreach ($stmt->fetchAll() as $post) {
        $url = canonical_url($pdo, '/post/' . rawurlencode($post['slug']));
        $line = '- [' . llms_text((string) $post['title']) . '](' . $url . ')';
        $excerpt = llms_text((string) ($post['excerpt'] ?? ''));
        if ($excerpt !== '') {
            $line .= ': ' . $excerpt;
        }
        echo $line . "\n";
    }
    exit;
}

function render_sitemap(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT slug, updated_at FROM posts WHERE ' . visible_post_where() . ' ORDER BY datetime(updated_at) DESC LIMIT 500');
    $stmt->execute(visible_post_params($site));
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
            if (in_array($postAction, ['save_settings', 'seed_samples', 'export_data', 'import_data', 'add_user', 'delete_user'], true)) {
                require_admin($user);
            }
            if ($postAction === 'save_post') {
                save_post($pdo, $user);
                redirect('/admin');
            }
            if ($postAction === 'delete_post') {
                $message = delete_post($pdo, $user);
                $action = 'dashboard';
            }
            if ($postAction === 'toggle_post_status') {
                $message = toggle_post_status($pdo, $user);
                $action = 'dashboard';
            }
            if ($postAction === 'upload_media') {
                $message = upload_media($pdo, $user);
                $action = 'media';
            }
            if ($postAction === 'delete_media') {
                $message = delete_media($pdo);
                $action = 'media';
            }
            if ($postAction === 'change_password') {
                $result = change_password($pdo, $user);
                if (str_starts_with($result, 'OK:')) {
                    $message = substr($result, 3);
                } else {
                    $error = $result;
                }
                $action = 'account';
            }
            if ($postAction === 'add_user') {
                $result = add_user($pdo);
                if (str_starts_with($result, 'OK:')) {
                    $message = substr($result, 3);
                } else {
                    $error = $result;
                }
                $action = 'users';
            }
            if ($postAction === 'delete_user') {
                $result = delete_user($pdo, $user);
                if (str_starts_with($result, 'OK:')) {
                    $message = substr($result, 3);
                } else {
                    $error = $result;
                }
                $action = 'users';
            }
            if ($postAction === 'save_settings') {
                save_settings($pdo);
                $message = 'Settings saved.';
                $action = 'settings';
            }
            if ($postAction === 'export_data') {
                export_data($pdo);
            }
            if ($postAction === 'import_data') {
                $message = import_data($pdo);
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
        echo '<main class="site auth-shell"><section class="auth-card"><a class="auth-brand" href="' . htmlEscape(url_for('/')) . '"><span class="auth-mark">TB</span><span>TinyBlog</span></a>';
        if ($error) {
            echo '<p class="notice error" role="alert">' . htmlEscape($error) . '</p>';
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
        echo '</section></main></body></html>';
        exit;
    }

    $blogTitle = setting($pdo, 'blog_title', 'TinyBlog Widget');
    echo admin_head($pdo, 'Admin');
    echo '<div class="site admin-shell"><header class="admin-topbar"><a class="admin-brand" href="' . htmlEscape(url_for('/admin')) . '"><span class="admin-brand-mark">TB</span><span class="admin-brand-name">' . htmlEscape($blogTitle) . '</span></a><div class="admin-topbar-actions"><a class="admin-topbar-link" href="' . htmlEscape(url_for('/')) . '">View site</a><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="logout"><button class="admin-logout" type="submit">Logout</button></form></div></header><div class="admin-layout"><nav class="admin-nav" aria-label="Admin sections">';
    $nav = ['dashboard' => 'Dashboard', 'edit' => 'New post', 'media' => 'Media', 'account' => 'Account'];
    if (($user['role'] ?? '') === 'admin') {
        $nav['users'] = 'Users';
        $nav['subscribers'] = 'Subscribers';
        $nav['settings'] = 'Settings';
    }
    foreach ($nav as $key => $label) {
        $href = $key === 'dashboard' ? url_for('/admin') : url_for('/admin?action=' . $key);
        $current = $key === $action ? ' aria-current="page"' : '';
        echo '<a class="admin-nav-link" href="' . htmlEscape($href) . '"' . $current . '>' . htmlEscape($label) . '</a>';
    }
    echo '</nav><main class="admin-content">';
    if ($message) {
        echo '<p class="notice success">' . htmlEscape($message) . '</p>';
    }
    if ($error) {
        echo '<p class="notice error" role="alert">' . htmlEscape($error) . '</p>';
    }
    if ($action === 'edit') {
        render_post_form($pdo, $user);
    } elseif ($action === 'media') {
        render_media_admin($pdo);
    } elseif ($action === 'account') {
        render_account_admin($pdo, $user);
    } elseif ($action === 'users') {
        require_admin($user);
        render_users_admin($pdo, $user);
    } elseif ($action === 'subscribers') {
        require_admin($user);
        render_subscribers_admin($pdo);
    } elseif ($action === 'settings') {
        require_admin($user);
        render_settings_admin($pdo);
    } else {
        render_dashboard_admin($pdo, $user);
    }
    echo '</main></div></div></body></html>';
    exit;
}

function admin_head(PDO $pdo, string $title): string
{
    security_headers('html');
    $accent = setting($pdo, 'accent_color', '#2436d4');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlEscape($title) . ' - TinyBlog Admin</title>' . app_icon_head_tags() . '<style>' . css_base($accent) . '.editor-preview{border:1px solid var(--line);padding:14px;min-height:180px}.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px}</style></head><body>';
}

function dashboard_stats(PDO $pdo, string $site): array
{
    $now = now_utc();
    $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $stats = [];

    $published = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE site = :site AND status = 'published' AND (publish_at IS NULL OR publish_at <= :now)");
    $published->execute([':site' => $site, ':now' => $now]);
    $stats['published'] = (int) $published->fetchColumn();

    $drafts = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE site = :site AND status = 'draft'");
    $drafts->execute([':site' => $site]);
    $stats['drafts'] = (int) $drafts->fetchColumn();

    $subscribers = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE site = :site AND status = 'active'");
    $subscribers->execute([':site' => $site]);
    $stats['subscribers'] = (int) $subscribers->fetchColumn();

    $views = $pdo->prepare('SELECT COUNT(pv.id) FROM post_views pv JOIN posts p ON p.id = pv.post_id WHERE p.site = :site AND pv.viewed_at >= :since');
    $views->execute([':site' => $site, ':since' => $since]);
    $stats['views_30d'] = (int) $views->fetchColumn();

    return $stats;
}

function relative_time(?string $datetime): string
{
    $time = strtotime((string) $datetime);
    if ($time === false) {
        return 'unknown';
    }
    $delta = time() - $time;
    $future = $delta < 0;
    $delta = abs($delta);
    $units = [
        'y' => 31536000,
        'mo' => 2592000,
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
    ];
    foreach ($units as $label => $seconds) {
        if ($delta >= $seconds) {
            $value = max(1, (int) floor($delta / $seconds));
            return $future ? 'in ' . $value . $label : $value . $label . ' ago';
        }
    }
    return $future ? 'soon' : 'just now';
}

function dashboard_status_label(array $post): string
{
    if (($post['status'] ?? '') === 'published') {
        $publishAt = strtotime(post_publish_at($post));
        if ($publishAt !== false && $publishAt > time()) {
            return 'scheduled';
        }
        return 'published';
    }
    return 'draft';
}

function render_dashboard_admin(PDO $pdo, array $user): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stats = dashboard_stats($pdo, $site);
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE site = :site ORDER BY datetime(updated_at) DESC LIMIT 20');
    $stmt->execute([':site' => $site]);
    $posts = $stmt->fetchAll();
    echo '<h1>Dashboard</h1><div class="toolbar"><a class="button" href="' . htmlEscape(url_for('/admin?action=edit')) . '">Write post</a>';
    if (($user['role'] ?? '') === 'admin') {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="seed_samples"><button class="secondary">Load sample posts</button></form>';
    }
    echo '</div>';

    echo '<section class="stat-strip" aria-label="Dashboard stats">';
    foreach ([
        'published' => 'Published',
        'drafts' => 'Drafts',
        'subscribers' => 'Confirmed subscribers',
        'views_30d' => 'Views 30d',
    ] as $key => $label) {
        echo '<div class="stat-cell"><p class="stat-value">' . (int) ($stats[$key] ?? 0) . '</p><p class="stat-label">' . htmlEscape($label) . '</p></div>';
    }
    echo '</section>';

    if (!$posts) {
        echo '<section class="empty-state"><h2>Write your first post</h2><p class="muted">Start with a short update, or load the sample posts to explore the admin.</p><div class="toolbar"><a class="button" href="' . htmlEscape(url_for('/admin?action=edit')) . '">Write your first post</a>';
        if (($user['role'] ?? '') === 'admin') {
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="seed_samples"><button class="secondary">Load sample posts</button></form>';
        }
        echo '</div></section>';
    } else {
        echo '<table aria-label="Recent posts"><caption>Recent posts</caption><thead><tr><th>Title</th><th>Status</th><th>Views</th><th>Updated</th></tr></thead><tbody>';
        foreach ($posts as $post) {
            $status = dashboard_status_label($post);
            $statusClass = preg_replace('/[^a-z-]/', '', $status) ?: 'draft';
            $updatedAt = (string) ($post['updated_at'] ?? '');
            $viewUrl = url_for('/post/' . rawurlencode((string) $post['slug']));
            $editUrl = url_for('/admin?action=edit&id=' . (int) $post['id']);
            $toggleLabel = ($post['status'] ?? '') === 'published' ? 'Unpublish' : 'Publish';
            echo '<tr><td><a href="' . htmlEscape($editUrl) . '">' . htmlEscape($post['title']) . '</a>';
            if ((int) ($post['pinned'] ?? 0) === 1) {
                echo ' <span class="muted" title="Pinned">Pinned</span>';
            }
            echo '<br><span class="muted">/' . htmlEscape($post['slug']) . '</span><div class="row-actions"><a href="' . htmlEscape($editUrl) . '">Edit</a>';
            if ($status !== 'draft') {
                echo '<a href="' . htmlEscape($viewUrl) . '">View</a>';
            }
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="toggle_post_status"><input type="hidden" name="id" value="' . (int) $post['id'] . '"><button class="link-button" type="submit">' . htmlEscape($toggleLabel) . '</button></form></div></td>';
            echo '<td><span class="status-badge ' . htmlEscape($statusClass) . '">' . htmlEscape($status) . '</span></td><td>' . (int) $post['view_count'] . '</td><td><time datetime="' . htmlEscape($updatedAt) . '" title="' . htmlEscape($updatedAt) . '">' . htmlEscape(relative_time($updatedAt)) . '</time></td></tr>';
        }
        echo '</tbody></table>';
    }
    $top = $pdo->prepare('SELECT title, slug, view_count FROM posts WHERE site = :site AND view_count > 0 ORDER BY view_count DESC, datetime(updated_at) DESC LIMIT 5');
    $top->execute([':site' => $site]);
    echo '<h2>Top posts</h2><table><caption>Top posts by views</caption><thead><tr><th>Post</th><th>Views</th></tr></thead><tbody>';
    foreach ($top->fetchAll() as $post) {
        echo '<tr><td><a href="' . htmlEscape(url_for('/post/' . rawurlencode($post['slug']))) . '">' . htmlEscape($post['title']) . '</a></td><td>' . (int) $post['view_count'] . '</td></tr>';
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
        'publish_at' => now_utc(),
        'pinned' => 0,
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
    echo '<div class="editor-grid"><section class="editor-main">';
    echo '<label>Title<input name="title" id="title" value="' . htmlEscape($post['title']) . '" required></label>';
    echo '<label>Slug<input name="slug" id="slug" value="' . htmlEscape($post['slug']) . '" placeholder="auto-generated"></label>';
    echo '<div class="markdown-toolbar" aria-label="Markdown toolbar"><button type="button" class="secondary" data-wrap-prefix="**" data-wrap-suffix="**" title="Bold"><strong>B</strong></button><button type="button" class="secondary" data-wrap-prefix="*" data-wrap-suffix="*" title="Italic"><em>I</em></button><button type="button" class="secondary" data-wrap-prefix="`" data-wrap-suffix="`" title="Code">code</button><button type="button" class="secondary" data-wrap-prefix="[" data-wrap-suffix="](https://)" title="Link">link</button><button type="button" class="secondary" data-wrap-prefix="![" data-wrap-suffix="](/uploads/image.jpg)" title="Image">img</button></div>';
    echo '<label>Markdown body<textarea name="body_markdown" id="body_markdown" required>' . htmlEscape($post['body_markdown']) . '</textarea></label>';
    echo '</section><aside class="editor-side"><div class="editor-actions"><button type="button" class="secondary" id="previewToggle">Preview</button><span class="muted" id="autosaveStatus">Autosave ready</span></div><div class="editor-preview content" id="preview" aria-live="polite" aria-busy="false"></div>';
    echo '<details class="post-settings" open><summary>Post settings</summary>';
    echo '<label>Excerpt<textarea name="excerpt" style="min-height:90px">' . htmlEscape($post['excerpt']) . '</textarea></label>';
    echo '<label>Featured image URL<input name="hero_image_url" value="' . htmlEscape((string) $post['hero_image_url']) . '" placeholder="https://... or uploaded media URL"></label>';
    echo '<label>Tags<input name="tags" value="' . htmlEscape($post['tags']) . '" placeholder="updates, product, notes"></label>';
    echo '<label>Publish date<input name="publish_at" value="' . htmlEscape(post_publish_at($post)) . '"></label>';
    echo '<label>Status<select name="status"><option value="draft"' . ($post['status'] === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . ($post['status'] === 'published' ? ' selected' : '') . '>Published</option></select></label>';
    echo '<label><input type="checkbox" name="pinned" value="1" ' . ((int) ($post['pinned'] ?? 0) === 1 ? 'checked' : '') . '> Pin to top of home listing</label>';
    echo '</details></aside></div><button>Save</button></form>';
    if (!empty($post['id'])) {
        echo '<form method="post" onsubmit="return confirm(\'Delete this post permanently?\')" style="margin-top:12px">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_post"><input type="hidden" name="id" value="' . (int) $post['id'] . '"><button class="secondary">Delete post</button></form>';
    }
    echo '<script>
        const postForm = document.getElementById("postForm");
        const title = document.getElementById("title");
        const slug = document.getElementById("slug");
        const body = document.getElementById("body_markdown");
        const preview = document.getElementById("preview");
        const status = document.getElementById("autosaveStatus");
        const key = "tinyblog-draft-" + (' . json_encode((string) $post['id']) . ' || "new");
        if (!body.value && localStorage.getItem(key)) body.value = localStorage.getItem(key);
        title.addEventListener("input", () => { if (!slug.dataset.touched) slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-|-$/g,""); });
        slug.addEventListener("input", () => slug.dataset.touched = "1");
        body.addEventListener("input", () => { localStorage.setItem(key, body.value); status.textContent = "Draft saved locally"; schedulePreview(); });
        document.querySelectorAll(".markdown-toolbar [data-wrap-prefix]").forEach((button) => {
          button.addEventListener("click", () => {
            const start = body.selectionStart || 0;
            const end = body.selectionEnd || start;
            const selected = body.value.slice(start, end);
            const prefix = button.dataset.wrapPrefix || "";
            const suffix = button.dataset.wrapSuffix || "";
            const fallback = selected || (prefix.startsWith("![") ? "alt text" : prefix === "[" ? "link text" : "text");
            body.value = body.value.slice(0, start) + prefix + fallback + suffix + body.value.slice(end);
            const cursor = start + prefix.length + fallback.length;
            body.focus();
            body.setSelectionRange(cursor, cursor);
            body.dispatchEvent(new Event("input", { bubbles: true }));
          });
        });
        const escapePreview = (value) => value.replace(/[&<>"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[char]));
        const safePreviewUrl = (value) => {
          if (value.startsWith("/uploads/") || value.startsWith("./uploads/")) return true;
          if (!/^https?:\/\//i.test(value)) return false;
          try {
            const parsed = new URL(value, window.location.href);
            return parsed.protocol === "http:" || parsed.protocol === "https:";
          } catch (error) { return false; }
        };
        const inlinePreview = (value) => escapePreview(value)
          .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (match, alt, src) => safePreviewUrl(src) ? "<img src=\"" + escapePreview(src) + "\" alt=\"" + escapePreview(alt) + "\" loading=\"lazy\">" : escapePreview(alt))
          .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (match, label, href) => safePreviewUrl(href) ? "<a href=\"" + escapePreview(href) + "\" rel=\"nofollow noopener\" target=\"_blank\">" + label + "</a>" : label)
          .replace(/`([^`]+)`/g, "<code>$1</code>")
          .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
          .replace(/\*([^*]+)\*/g, "<em>$1</em>");
        const renderPreview = (markdown) => {
          const lines = markdown.slice(0, 4000).replace(/\r\n?/g, "\n").split("\n");
          let html = "", paragraph = [], list = [], ordered = false, code = [], inCode = false, language = "";
          const flushParagraph = () => { if (paragraph.length) { html += "<p>" + inlinePreview(paragraph.join(" ").trim()) + "</p>"; paragraph = []; } };
          const flushList = () => { if (list.length) { const tag = ordered ? "ol" : "ul"; html += "<" + tag + ">" + list.map((item) => "<li>" + inlinePreview(item) + "</li>").join("") + "</" + tag + ">"; list = []; } };
          const flushCode = () => { if (code.length) { html += "<pre><code" + (language ? " class=\"language-" + escapePreview(language) + "\"" : "") + ">" + escapePreview(code.join("\n")) + "</code></pre>"; code = []; language = ""; } };
          lines.forEach((line) => {
            const trimmed = line.trim();
            if (trimmed.startsWith("```")) {
              if (inCode) { flushCode(); inCode = false; } else { flushParagraph(); flushList(); language = trimmed.slice(3).replace(/[^a-z0-9_-]/gi, ""); inCode = true; }
              return;
            }
            if (inCode) { code.push(line); return; }
            if (!trimmed) { flushParagraph(); flushList(); return; }
            if (/^[-*]\s+/.test(trimmed)) { flushParagraph(); if (list.length && ordered) flushList(); ordered = false; list.push(trimmed.replace(/^[-*]\s+/, "")); return; }
            if (/^\d+\.\s+/.test(trimmed)) { flushParagraph(); if (list.length && !ordered) flushList(); ordered = true; list.push(trimmed.replace(/^\d+\.\s+/, "")); return; }
            if (/^#{1,3}\s+/.test(trimmed)) { flushParagraph(); flushList(); html += "<p><strong>" + inlinePreview(trimmed.replace(/^#{1,3}\s+/, "")) + "</strong></p>"; return; }
            if (/^>\s?/.test(trimmed)) { flushParagraph(); flushList(); html += "<blockquote><p>" + inlinePreview(trimmed.replace(/^>\s?/, "")) + "</p></blockquote>"; return; }
            paragraph.push(trimmed);
          });
          flushParagraph(); flushList(); flushCode();
          return html;
        };
        let previewTimer = null;
        const renderLivePreview = () => {
          preview.setAttribute("aria-busy", "true");
          preview.innerHTML = renderPreview(body.value);
          preview.setAttribute("aria-busy", "false");
        };
        const schedulePreview = () => {
          window.clearTimeout(previewTimer);
          preview.setAttribute("aria-busy", "true");
          previewTimer = setTimeout(renderLivePreview, 300);
        };
        document.getElementById("previewToggle").addEventListener("click", () => {
          preview.classList.toggle("is-collapsed");
          renderLivePreview();
        });
        document.addEventListener("keydown", (event) => {
          if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
            event.preventDefault();
            postForm.requestSubmit();
          }
        });
        renderLivePreview();
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
    $pinned = isset($_POST['pinned']) ? 1 : 0;
    $publishedAt = normalize_publish_at((string) ($_POST['publish_at'] ?? ($_POST['published_at'] ?? '')), $status);
    $contentHtml = sanitize_markdown($body);
    $readingMinutes = reading_minutes_from_markdown($body);
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
        $stmt = $pdo->prepare('UPDATE posts SET title = :title, slug = :slug, body_markdown = :body_markdown, content_html = :content_html, excerpt = :excerpt, hero_image_url = :hero_image_url, tags = :tags, status = :status, published_at = :published_at, publish_at = :publish_at, pinned = :pinned, reading_minutes = :reading_minutes, updated_at = :updated_at WHERE id = :id AND site = :site');
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
            ':publish_at' => $publishedAt,
            ':pinned' => $pinned,
            ':reading_minutes' => $readingMinutes,
            ':updated_at' => now_utc(),
            ':id' => $id,
            ':site' => $site,
        ]);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO posts (site, title, slug, body_markdown, content_html, excerpt, hero_image_url, tags, status, published_at, publish_at, pinned, reading_minutes, created_at, updated_at)
        VALUES (:site, :title, :slug, :body_markdown, :content_html, :excerpt, :hero_image_url, :tags, :status, :published_at, :publish_at, :pinned, :reading_minutes, :created_at, :updated_at)');
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
        ':publish_at' => $publishedAt,
        ':pinned' => $pinned,
        ':reading_minutes' => $readingMinutes,
        ':created_at' => now_utc(),
        ':updated_at' => now_utc(),
    ]);
}

function delete_post(PDO $pdo, array $user): string
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        return 'Choose a post to delete.';
    }
    if (!can_manage_post($pdo, $user, $id)) {
        http_response_code(403);
        exit('You cannot delete that post.');
    }
    $stmt = $pdo->prepare('DELETE FROM posts WHERE id = :id AND site = :site');
    $stmt->execute([
        ':id' => $id,
        ':site' => setting($pdo, 'site_key', 'store-1'),
    ]);
    return $stmt->rowCount() > 0 ? 'Post deleted.' : 'Post not found.';
}

function toggle_post_status(PDO $pdo, array $user): string
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        return 'Choose a post to update.';
    }
    if (!can_manage_post($pdo, $user, $id)) {
        http_response_code(403);
        exit('You cannot update that post.');
    }
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT status FROM posts WHERE id = :id AND site = :site LIMIT 1');
    $stmt->execute([':id' => $id, ':site' => $site]);
    $current = (string) ($stmt->fetchColumn() ?: '');
    if ($current === '') {
        return 'Post not found.';
    }
    $next = $current === 'published' ? 'draft' : 'published';
    $now = now_utc();
    $update = $pdo->prepare('UPDATE posts SET status = :status, published_at = :published_at, publish_at = :publish_at, updated_at = :updated_at WHERE id = :id AND site = :site');
    $update->execute([
        ':status' => $next,
        ':published_at' => $next === 'published' ? $now : null,
        ':publish_at' => $next === 'published' ? $now : null,
        ':updated_at' => $now,
        ':id' => $id,
        ':site' => $site,
    ]);
    return $next === 'published' ? 'Post published.' : 'Post moved to drafts.';
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
    $altText = substr(trim((string) ($_POST['alt_text'] ?? '')), 0, 180);
    $safeBase = preg_replace('/[^a-z0-9._-]+/i', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'image';
    $filename = strtolower(substr($safeBase, 0, 48)) . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $GLOBALS['TB_CONFIG']['upload_dir'] . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        return 'Could not store upload.';
    }
    chmod($target, 0644);
    $url = rtrim(setting($pdo, 'canonical_base', base_url()), '/') . '/uploads/' . rawurlencode($filename);
    $stmt = $pdo->prepare('INSERT INTO media (filename, original_name, mime, size, url, alt_text, user_id, created_at) VALUES (:filename, :original_name, :mime, :size, :url, :alt_text, :user_id, :created_at)');
    $stmt->execute([
        ':filename' => $filename,
        ':original_name' => $original,
        ':mime' => $mime,
        ':size' => (int) $file['size'],
        ':url' => $url,
        ':alt_text' => $altText,
        ':user_id' => (int) $user['id'],
        ':created_at' => now_utc(),
    ]);
    return 'Image uploaded: ' . $url;
}

function delete_media(PDO $pdo): string
{
    $id = (int) ($_POST['media_id'] ?? 0);
    if ($id <= 0) {
        return 'Choose media to delete.';
    }
    $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $media = $stmt->fetch();
    if (!$media) {
        return 'Media not found.';
    }
    $uploadDir = realpath((string) $GLOBALS['TB_CONFIG']['upload_dir']);
    $target = realpath((string) $GLOBALS['TB_CONFIG']['upload_dir'] . DIRECTORY_SEPARATOR . (string) $media['filename']);
    if ($uploadDir && $target && str_starts_with($target, $uploadDir) && is_file($target)) {
        unlink($target);
    }
    $delete = $pdo->prepare('DELETE FROM media WHERE id = :id');
    $delete->execute([':id' => $id]);
    return 'Media deleted.';
}

function change_password(PDO $pdo, array $user): string
{
    if (!rate_limit($pdo, 'password_change_' . (int) $user['id'], 5, 900)) {
        return 'Too many password change attempts. Try again later.';
    }
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if (strlen($newPassword) < 12) {
        return 'New password must be at least 12 characters.';
    }
    if ($newPassword !== $confirmPassword) {
        return 'New passwords do not match.';
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $user['id']]);
    $hash = (string) ($stmt->fetchColumn() ?: '');
    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        return 'Current password is incorrect.';
    }
    $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $update->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => (int) $user['id'],
    ]);
    session_regenerate_id(true);
    return 'OK:Password updated.';
}

function add_user(PDO $pdo): string
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    $postedRole = (string) ($_POST['role'] ?? 'editor');
    $role = in_array($postedRole, ['admin', 'editor'], true) ? $postedRole : 'editor';
    $password = (string) ($_POST['temporary_password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }
    if (strlen($password) < 12) {
        return 'Temporary password must be at least 12 characters.';
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (:email, :name, :password_hash, :role, :created_at)');
        $stmt->execute([
            ':email' => $email,
            ':name' => $name !== '' ? $name : $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':created_at' => now_utc(),
        ]);
    } catch (PDOException $exception) {
        return 'A user with that email already exists.';
    }
    return 'OK:User added.';
}

function delete_user(PDO $pdo, array $currentUser): string
{
    $id = (int) ($_POST['user_id'] ?? 0);
    if ($id <= 0) {
        return 'Choose a user to delete.';
    }
    if ($id === (int) $currentUser['id']) {
        return 'You can not self delete; choose another user.';
    }
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $target = $stmt->fetch();
    if (!$target) {
        return 'User not found.';
    }
    if (($target['role'] ?? '') === 'admin') {
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($admins <= 1) {
            return 'Cannot delete the last admin.';
        }
    }
    $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $delete->execute([':id' => $id]);
    return 'OK:User deleted.';
}

function render_account_admin(PDO $pdo, array $user): void
{
    echo '<h1>Account</h1><section class="panel"><h2>Change password</h2><p class="muted">Use a new password with at least 12 characters.</p><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="change_password">';
    echo '<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>';
    echo '<label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label>';
    echo '<label>Confirm new password<input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label>';
    echo '<button>Update password</button></form></section>';
}

function render_users_admin(PDO $pdo, array $currentUser): void
{
    echo '<h1>Users</h1><section class="panel"><h2>Add user</h2><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="add_user">';
    echo '<label>Name<input name="name" autocomplete="name"></label><label>Email<input name="email" type="email" autocomplete="email" required></label>';
    echo '<label>Role<select name="role"><option value="editor">Editor</option><option value="admin">Admin</option></select></label>';
    echo '<label>Temporary password<input name="temporary_password" type="password" minlength="12" autocomplete="new-password" required></label><button>Add user</button></form></section>';
    $stmt = $pdo->query('SELECT id, email, name, role, created_at FROM users ORDER BY datetime(created_at) DESC, id DESC');
    echo '<table><caption>Users</caption><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Action</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) {
        echo '<tr><td>' . htmlEscape($row['name']) . '</td><td>' . htmlEscape($row['email']) . '</td><td>' . htmlEscape($row['role']) . '</td><td>' . htmlEscape($row['created_at']) . '</td><td>';
        if ((int) $row['id'] === (int) $currentUser['id']) {
            echo '<span class="muted">Current user</span>';
        } else {
            echo '<form method="post" onsubmit="return confirm(\'Delete this user?\')">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_user"><input type="hidden" name="user_id" value="' . (int) $row['id'] . '"><button class="secondary">Delete</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

function render_media_admin(PDO $pdo): void
{
    echo '<h1>Media</h1><form class="upload-dropzone" id="uploadDropzone" method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="admin_action" value="upload_media"><label>Image<input type="file" name="media" accept="image/jpeg,image/png,image/gif,image/webp" required></label><label>Alt text<input name="alt_text" maxlength="180" placeholder="Describe the image"></label><button>Upload</button></form>';
    $stmt = $pdo->prepare('SELECT * FROM media ORDER BY datetime(created_at) DESC LIMIT 60');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo '<section class="empty-state"><h2>No media yet</h2><p class="muted">Upload an image to reuse it in Markdown posts.</p></section>';
    } else {
        echo '<section class="media-grid" aria-label="Uploaded media">';
        foreach ($rows as $media) {
            $alt = trim((string) ($media['alt_text'] ?? ''));
            $label = $alt !== '' ? $alt : (string) ($media['original_name'] ?? 'image');
            $markdown = '![' . $label . '](' . (string) $media['url'] . ')';
            echo '<article class="media-card"><img class="media-thumb" src="' . htmlEscape($media['url']) . '" alt="' . htmlEscape($alt) . '" loading="lazy"><div class="media-card-body"><strong>' . htmlEscape($media['original_name']) . '</strong><span class="muted">' . (int) $media['size'] . ' bytes</span><code>' . htmlEscape($media['url']) . '</code>';
            if ($alt !== '') {
                echo '<span class="muted">' . htmlEscape($alt) . '</span>';
            }
            echo '<div class="media-card-actions"><button type="button" class="secondary" data-markdown="' . htmlEscape($markdown) . '">Copy Markdown</button><form method="post" onsubmit="return confirm(\'Delete this media file?\')">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_media"><input type="hidden" name="media_id" value="' . (int) $media['id'] . '"><button class="secondary">Delete</button></form></div></div></article>';
        }
        echo '</section>';
    }
    echo '<script>
        const dropzone = document.getElementById("uploadDropzone");
        if (dropzone) {
          ["dragenter", "dragover"].forEach((eventName) => dropzone.addEventListener(eventName, (event) => { event.preventDefault(); dropzone.classList.add("is-dragover"); }));
          ["dragleave", "drop"].forEach((eventName) => dropzone.addEventListener(eventName, () => dropzone.classList.remove("is-dragover")));
        }
        const copyMarkdown = (button) => {
          const value = button.dataset.markdown || "";
          const done = () => { button.textContent = "Copied"; setTimeout(() => { button.textContent = "Copy Markdown"; }, 1200); };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(() => {});
          } else {
            const helper = document.createElement("textarea");
            helper.value = value;
            helper.setAttribute("readonly", "");
            helper.style.position = "fixed";
            helper.style.left = "-9999px";
            document.body.appendChild(helper);
            helper.select();
            document.execCommand("copy");
            document.body.removeChild(helper);
            done();
          }
        };
        document.querySelectorAll("[data-markdown]").forEach((button) => button.addEventListener("click", () => copyMarkdown(button)));
    </script>';
}

function render_subscribers_admin(PDO $pdo): void
{
    echo '<h1>Subscribers</h1><p class="muted">Emails are stored for opt-in updates only. Export carefully and delete on request.</p>';
    $count = $pdo->prepare('SELECT COUNT(*) FROM subscribers WHERE site = :site AND status = :status');
    $count->execute([':site' => setting($pdo, 'site_key', 'store-1'), ':status' => 'active']);
    echo '<p class="notice">Confirmed subscribers: ' . (int) $count->fetchColumn() . '</p>';
    $stmt = $pdo->prepare('SELECT email, status, consent_at, confirm_token, unsub_token FROM subscribers WHERE site = :site ORDER BY datetime(consent_at) DESC LIMIT 200');
    $stmt->execute([':site' => setting($pdo, 'site_key', 'store-1')]);
    echo '<table><caption>Subscriber list</caption><thead><tr><th>Email</th><th>Status</th><th>Consent</th><th>Links</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) {
        $links = '';
        if (!empty($row['confirm_token'])) {
            $links .= '<code>' . htmlEscape(canonical_url($pdo, '/subscribe/confirm/' . rawurlencode((string) $row['confirm_token']))) . '</code><br>';
        }
        if (!empty($row['unsub_token'])) {
            $links .= '<code>' . htmlEscape(canonical_url($pdo, '/unsubscribe/' . rawurlencode((string) $row['unsub_token']))) . '</code>';
        }
        echo '<tr><td>' . htmlEscape($row['email']) . '</td><td>' . htmlEscape($row['status']) . '</td><td>' . htmlEscape($row['consent_at']) . '</td><td>' . $links . '</td></tr>';
    }
    echo '</tbody></table>';
}

function render_settings_admin(PDO $pdo): void
{
    echo '<h1>Settings</h1><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_settings">';
    foreach (['blog_title' => 'Blog title', 'home_heading' => 'Home heading', 'site_key' => 'Site id', 'canonical_base' => 'Canonical base URL', 'accent_color' => 'Accent color', 'posts_per_page' => 'Posts per page'] as $key => $label) {
        echo '<label>' . htmlEscape($label) . '<input name="' . htmlEscape($key) . '" value="' . htmlEscape(setting($pdo, $key, '')) . '"></label>';
        if ($key === 'home_heading') {
            echo '<p class="muted">Leave empty to use the blog title on the home page.</p>';
        }
    }
    echo '<label>Home intro<textarea name="home_intro" placeholder="Optional short intro below the home heading">' . htmlEscape(setting($pdo, 'home_intro', '')) . '</textarea></label>';
    echo '<p class="muted">Leave empty to hide the intro paragraph.</p>';
    echo '<label>Allowed widget origins<textarea name="allowed_origins" placeholder="https://example.com">' . htmlEscape(setting($pdo, 'allowed_origins', '')) . '</textarea></label>';
    echo '<label>About text<textarea name="about_text">' . htmlEscape(setting($pdo, 'about_text', '')) . '</textarea></label>';
    echo '<label><input type="checkbox" name="require_site_key" value="1" ' . (setting($pdo, 'require_site_key', '0') === '1' ? 'checked' : '') . '> Require public siteKey for API reads</label>';
    echo '<label><input type="checkbox" name="subscribe_mail_enabled" value="1" ' . (setting($pdo, 'subscribe_mail_enabled', '0') === '1' ? 'checked' : '') . '> Send subscription confirmation emails with PHP mail()</label>';
    echo '<p class="muted">Public siteKey: <code>' . htmlEscape(setting($pdo, 'public_site_key', '')) . '</code></p><button>Save settings</button></form>';
    echo '<h2>Backup</h2><div class="toolbar"><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="export_data"><button class="secondary">Export JSON</button></form></div>';
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="admin_action" value="import_data"><label>Import JSON<input type="file" name="backup" accept="application/json,.json" required></label><button class="secondary">Import</button></form>';
}

function save_settings(PDO $pdo): void
{
    $fields = ['blog_title', 'home_heading', 'home_intro', 'site_key', 'canonical_base', 'accent_color', 'posts_per_page', 'allowed_origins', 'about_text'];
    foreach ($fields as $field) {
        $value = trim((string) ($_POST[$field] ?? ''));
        if ($field === 'accent_color' && !preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            $value = '#2436d4';
        }
        if ($field === 'canonical_base') {
            $value = rtrim($value ?: base_url(), '/');
        }
        if ($field === 'site_key') {
            $value = slugify($value ?: 'store-1');
        }
        if ($field === 'posts_per_page') {
            $value = (string) max(1, min(50, (int) $value ?: 10));
        }
        set_setting($pdo, $field, $value);
    }
    set_setting($pdo, 'require_site_key', isset($_POST['require_site_key']) ? '1' : '0');
    set_setting($pdo, 'subscribe_mail_enabled', isset($_POST['subscribe_mail_enabled']) ? '1' : '0');
}

function export_data(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    security_headers('json');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="tinyblog-export-' . gmdate('Ymd-His') . '.json"');
    echo '{"version":' . json_encode(TB_VERSION) . ',"exported_at":' . json_encode(now_utc()) . ',"settings":';
    stream_json_rows($pdo->query('SELECT key, value FROM settings ORDER BY key'));
    echo ',"posts":';
    stream_json_rows($pdo->query('SELECT * FROM posts ORDER BY id'));
    echo ',"media":';
    stream_json_rows($pdo->query('SELECT filename, original_name, mime, size, url, alt_text, created_at FROM media ORDER BY id'));
    echo ',"subscribers":';
    $stmt = $pdo->prepare('SELECT site, email, status, consent_at, confirmed_at, unsub_token FROM subscribers WHERE site = :site AND status = :status ORDER BY id');
    $stmt->execute([':site' => $site, ':status' => 'active']);
    stream_json_rows($stmt);
    echo '}';
    exit;
}

function stream_json_rows(PDOStatement $stmt): void
{
    echo '[';
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $first ? '' : ',';
        echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $first = false;
    }
    echo ']';
}

function import_data(PDO $pdo): string
{
    if (empty($_FILES['backup']) || !is_array($_FILES['backup']) || ($_FILES['backup']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Choose a JSON backup to import.';
    }
    $raw = file_get_contents((string) $_FILES['backup']['tmp_name']);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return 'Backup JSON is invalid.';
    }
    $pdo->beginTransaction();
    try {
        $settingStmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach (($data['settings'] ?? []) as $setting) {
            if (isset($setting['key'], $setting['value'])) {
                $settingStmt->execute([':key' => (string) $setting['key'], ':value' => (string) $setting['value']]);
            }
        }
        $postStmt = $pdo->prepare('INSERT INTO posts (site, title, slug, body_markdown, content_html, excerpt, hero_image_url, tags, status, published_at, publish_at, pinned, reading_minutes, created_at, updated_at)
            VALUES (:site, :title, :slug, :body_markdown, :content_html, :excerpt, :hero_image_url, :tags, :status, :published_at, :publish_at, :pinned, :reading_minutes, :created_at, :updated_at)
            ON CONFLICT(site, slug) DO UPDATE SET title = excluded.title, body_markdown = excluded.body_markdown, content_html = excluded.content_html, excerpt = excluded.excerpt, hero_image_url = excluded.hero_image_url, tags = excluded.tags, status = excluded.status, published_at = excluded.published_at, publish_at = excluded.publish_at, pinned = excluded.pinned, reading_minutes = excluded.reading_minutes, updated_at = excluded.updated_at');
        foreach (($data['posts'] ?? []) as $post) {
            if (empty($post['site']) || empty($post['title']) || empty($post['slug'])) {
                continue;
            }
            $body = (string) ($post['body_markdown'] ?? '');
            $postStmt->execute([
                ':site' => (string) $post['site'],
                ':title' => (string) $post['title'],
                ':slug' => slugify((string) $post['slug']),
                ':body_markdown' => $body,
                ':content_html' => sanitize_markdown($body),
                ':excerpt' => (string) ($post['excerpt'] ?? excerpt_from_markdown($body)),
                ':hero_image_url' => safe_url((string) ($post['hero_image_url'] ?? '')) ?: '',
                ':tags' => tags_to_string((string) ($post['tags'] ?? '')),
                ':status' => in_array(($post['status'] ?? 'draft'), ['draft', 'published'], true) ? (string) $post['status'] : 'draft',
                ':published_at' => (string) ($post['published_at'] ?? $post['publish_at'] ?? now_utc()),
                ':publish_at' => (string) ($post['publish_at'] ?? $post['published_at'] ?? now_utc()),
                ':pinned' => (int) ($post['pinned'] ?? 0),
                ':reading_minutes' => (int) ($post['reading_minutes'] ?? reading_minutes_from_markdown($body)),
                ':created_at' => (string) ($post['created_at'] ?? now_utc()),
                ':updated_at' => (string) ($post['updated_at'] ?? now_utc()),
            ]);
        }
        $mediaStmt = $pdo->prepare('INSERT OR IGNORE INTO media (filename, original_name, mime, size, url, alt_text, created_at) VALUES (:filename, :original_name, :mime, :size, :url, :alt_text, :created_at)');
        foreach (($data['media'] ?? []) as $media) {
            if (!empty($media['filename']) && !empty($media['url'])) {
                $mediaStmt->execute([
                    ':filename' => basename((string) $media['filename']),
                    ':original_name' => basename((string) ($media['original_name'] ?? $media['filename'])),
                    ':mime' => (string) ($media['mime'] ?? 'image/jpeg'),
                    ':size' => (int) ($media['size'] ?? 0),
                    ':url' => (string) $media['url'],
                    ':alt_text' => (string) ($media['alt_text'] ?? ''),
                    ':created_at' => (string) ($media['created_at'] ?? now_utc()),
                ]);
            }
        }
        $subscriberStmt = $pdo->prepare('INSERT INTO subscribers (site, email, status, consent_at, confirmed_at, unsub_token) VALUES (:site, :email, :status, :consent_at, :confirmed_at, :unsub_token) ON CONFLICT(site, email) DO UPDATE SET status = excluded.status, confirmed_at = excluded.confirmed_at, unsub_token = excluded.unsub_token');
        foreach (($data['subscribers'] ?? []) as $subscriber) {
            if (!empty($subscriber['site']) && filter_var((string) ($subscriber['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                $subscriberStmt->execute([
                    ':site' => (string) $subscriber['site'],
                    ':email' => strtolower((string) $subscriber['email']),
                    ':status' => 'active',
                    ':consent_at' => (string) ($subscriber['consent_at'] ?? now_utc()),
                    ':confirmed_at' => (string) ($subscriber['confirmed_at'] ?? now_utc()),
                    ':unsub_token' => (string) ($subscriber['unsub_token'] ?? random_token()),
                ]);
            }
        }
        $pdo->commit();
        return 'Backup imported.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        write_server_log('error', 'Import failed: ' . $e->getMessage());
        return 'Import failed. Check the server log.';
    }
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
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO posts (site, title, slug, body_markdown, content_html, excerpt, hero_image_url, tags, status, published_at, publish_at, reading_minutes, created_at, updated_at)
        VALUES (:site, :title, :slug, :body_markdown, :content_html, :excerpt, :hero_image_url, :tags, :status, :published_at, :publish_at, :reading_minutes, :created_at, :updated_at)');
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
            ':publish_at' => $published,
            ':reading_minutes' => reading_minutes_from_markdown($sample['body']),
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
    if ($path === '/feed.json') {
        render_json_feed($pdo);
    }
    if ($path === '/sitemap.xml') {
        render_sitemap($pdo);
    }
    if ($path === '/llms.txt') {
        render_llms_txt($pdo);
    }
    if ($path === '/admin') {
        render_admin($pdo);
    }
    if (preg_match('#^/post/([^/]+)$#', $path, $m)) {
        render_post($pdo, rawurldecode($m[1]));
    }
    if (preg_match('#^/subscribe/confirm/([^/]+)$#', $path, $m)) {
        render_subscribe_confirm($pdo, rawurldecode($m[1]));
    }
    if (preg_match('#^/unsubscribe/([^/]+)$#', $path, $m)) {
        render_unsubscribe($pdo, rawurldecode($m[1]));
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
        render_archive($pdo);
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
