<?php
/**
 * TinyBlog Widget backend - PHP 8+ and SQLite, built for shared hosting.
 * Quick deploy: upload this file, tinyblog-widget.js, .htaccess, and uploads/.
 * First run: open /admin and create the first admin; no default password ships.
 * Database: data/tinyblog.db is auto-created on first request if writable.
 * Widget: paste the README embed script and set endpoint to your /api URL.
 * Security: prepared statements, CSRF, session timeout, CORS allowlist, safe uploads.
 * Privacy: no third-party trackers; subscribers store only email and opt-in time.
 * Allow the websites that embed your posts in Admin -> Add to site.
 * Repository: https://github.com/tanzir71/tinyblog
 */

declare(strict_types=1);

// Local development with `php -S localhost:8080 tinyblog.php`: serve real files
// (widget, assets, uploads) directly, but never data/, .env, or dotfiles.
if (PHP_SAPI === 'cli-server') {
    $tbStaticPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    $tbStaticFile = realpath(__DIR__ . $tbStaticPath);
    if ($tbStaticPath !== '/' && $tbStaticFile !== false && is_file($tbStaticFile) && str_starts_with($tbStaticFile, __DIR__ . DIRECTORY_SEPARATOR)) {
        $tbRelative = str_replace('\\', '/', substr($tbStaticFile, strlen(__DIR__) + 1));
        if ($tbRelative === 'tinyblog.php') {
            unset($tbStaticPath, $tbStaticFile, $tbRelative);
        } elseif (preg_match('#(^|/)\.|^data/|^tests/|\.(php|db|sqlite|log|md)$#i', $tbRelative)) {
            http_response_code(404);
            exit;
        } else {
            return false;
        }
    }
}

const TB_VERSION = '0.2.0';
const TB_REPO_URL = 'https://github.com/tanzir71/tinyblog';
const TB_BRAND_ACCENT = '#c2410c';

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
$GLOBALS['TB_CONFIG']['data_dir'] = dirname((string) $GLOBALS['TB_CONFIG']['db_path']);

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
    return $scheme . '://' . $host . script_base_dir();
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
            width INTEGER,
            height INTEGER,
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
        CREATE TABLE IF NOT EXISTS embed_seen (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            origin TEXT NOT NULL,
            allowed INTEGER NOT NULL DEFAULT 0,
            hits INTEGER NOT NULL DEFAULT 1,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            UNIQUE(site, origin)
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
        'accent_color' => TB_BRAND_ACCENT,
        'canonical_base' => base_url(),
        'timezone' => 'UTC',
        'mail_from' => '',
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
    // Additive migrations run once per schema version, not on every request.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 4) {
        return;
    }
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
    if (!column_exists($pdo, 'media', 'width')) {
        $pdo->exec('ALTER TABLE media ADD COLUMN width INTEGER');
    }
    if (!column_exists($pdo, 'media', 'height')) {
        $pdo->exec('ALTER TABLE media ADD COLUMN height INTEGER');
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
    // v0.2 palette: installs still on the old default blue accent move to ember.
    $pdo->exec("UPDATE settings SET value = '" . TB_BRAND_ACCENT . "' WHERE key = 'accent_color' AND lower(value) = '#2436d4'");
    $pdo->exec('PRAGMA user_version = 4');
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
    $allowed = ['dashboard', 'posts', 'edit', 'media', 'account', 'users', 'subscribers', 'embed', 'settings'];
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

function script_base_dir(): string
{
    // The PHP dev server reports the requested path as SCRIPT_NAME; the app lives at the root there.
    if (PHP_SAPI === 'cli-server') {
        return '';
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

function route_path(): string
{
    if (isset($_GET['route']) && is_string($_GET['route'])) {
        return '/' . trim($_GET['route'], '/');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = script_base_dir();
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
    return script_base_dir() . '/' . ltrim($path, '/');
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

function media_dimensions_by_url(PDO $pdo): array
{
    $rows = $pdo->query('SELECT url, width, height FROM media WHERE width IS NOT NULL AND height IS NOT NULL')->fetchAll();
    $dimensions = [];
    foreach ($rows as $row) {
        $url = (string) ($row['url'] ?? '');
        $width = (int) ($row['width'] ?? 0);
        $height = (int) ($row['height'] ?? 0);
        if ($url === '' || $width <= 0 || $height <= 0) {
            continue;
        }
        $dimensions[$url] = ['width' => $width, 'height' => $height];
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $dimensions[$path] = ['width' => $width, 'height' => $height];
        }
    }
    return $dimensions;
}

function image_dimension_attrs(array $dimensions, string $src): string
{
    $key = $src;
    if (!isset($dimensions[$key])) {
        $path = parse_url($src, PHP_URL_PATH);
        $key = is_string($path) ? $path : $src;
    }
    if (!isset($dimensions[$key])) {
        return '';
    }
    return ' width="' . (int) $dimensions[$key]['width'] . '" height="' . (int) $dimensions[$key]['height'] . '"';
}

function add_media_dimensions_to_html(string $html, array $dimensions): string
{
    if ($html === '' || !$dimensions) {
        return $html;
    }
    return preg_replace_callback('/<img\b([^>]*)>/i', function (array $m) use ($dimensions): string {
        $attrs = (string) $m[1];
        if (!preg_match('/\ssrc="([^"]+)"/i', $attrs, $srcMatch)) {
            return $m[0];
        }
        $next = $attrs;
        if (!preg_match('/\sloading=/i', $next)) {
            $next .= ' loading="lazy"';
        }
        if (!preg_match('/\swidth=/i', $next) && !preg_match('/\sheight=/i', $next)) {
            $next .= image_dimension_attrs($dimensions, html_entity_decode($srcMatch[1], ENT_QUOTES, 'UTF-8'));
        }
        return '<img' . $next . '>';
    }, $html) ?? $html;
}

function render_post_content_html(PDO $pdo, string $html): string
{
    return add_media_dimensions_to_html($html, media_dimensions_by_url($pdo));
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
    if ($origin === $sameOrigin) {
        send_cors_headers($pdo, $origin);
        return;
    }
    if (in_array($origin, $allowed, true)) {
        record_embed_seen($pdo, $origin, true);
        send_cors_headers($pdo, $origin);
        return;
    }
    record_embed_seen($pdo, $origin, false);
    // Send CORS headers on the refusal so the widget can read the reason and explain it.
    if (preg_match('#^https?://[a-z0-9.-]+(?::[0-9]+)?$#i', $origin)) {
        send_cors_headers($pdo, $origin);
    }
    json_response(['error' => 'Origin is not allowed for this TinyBlog site.', 'code' => 'origin_not_allowed', 'origin' => $origin, 'help' => 'Allow this website in Admin -> Add to site.'], 403);
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
    // Scheduled posts change what readers see when their time arrives, and
    // deletes change it too, so both count as modifications for caching.
    $stmt = $pdo->prepare("SELECT MAX(CASE WHEN status = 'published' AND publish_at IS NOT NULL AND publish_at <= :now AND publish_at > updated_at THEN publish_at ELSE updated_at END) FROM posts WHERE site = :site");
    $stmt->execute([':site' => $site, ':now' => now_utc()]);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $changed = setting($pdo, 'content_changed_at', '');
    $latest = max($last, $changed);
    return $latest !== '' ? $latest : now_utc();
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
        json_response(['error' => 'Unknown site.', 'code' => 'unknown_site'], 404);
    }
    if (setting($pdo, 'require_site_key', '0') === '1') {
        $provided = $_SERVER['HTTP_X_TINYBLOG_SITE_KEY'] ?? ($_GET['siteKey'] ?? '');
        if (!is_string($provided) || !hash_equals(setting($pdo, 'public_site_key', ''), $provided)) {
            json_response(['error' => 'Missing or invalid siteKey.', 'code' => 'invalid_site_key'], 401);
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
        $payload['content_html'] = render_post_content_html($pdo, (string) $post['content_html']);
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
            @mail($email, 'Confirm your ' . str_replace(["\r", "\n"], '', setting($pdo, 'blog_title', 'TinyBlog')) . ' subscription', "Confirm your subscription:\n\n" . $confirmUrl . "\n\nIf you did not ask for this, ignore this email.\n", mail_headers($pdo));
        }
        json_response(['ok' => true, 'message' => 'Almost done. Check your email to confirm your subscription.']);
    }

    if ($path === '/api/feed.xml' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        render_rss($pdo);
    }

    json_response(['error' => 'API route not found.'], 404);
}

function render_embed_feed(PDO $pdo): void
{
    check_cors_or_fail($pdo);
    $site = (string) ($_GET['site'] ?? setting($pdo, 'site_key', 'store-1'));
    validate_site($pdo, $site);
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 5)));
    $withExcerpt = ($_GET['excerpt'] ?? '1') !== '0';
    $withCss = ($_GET['css'] ?? '1') !== '0';
    $lastModified = content_last_modified($pdo, $site);
    maybe_not_modified(etag_for('embed-feed:' . $site . ':' . $limit . ':' . (int) $withExcerpt . (int) $withCss, $lastModified), $lastModified);
    $stmt = $pdo->prepare('SELECT title, slug, excerpt, publish_at, published_at, created_at, reading_minutes, body_markdown FROM posts WHERE ' . visible_post_where() . ' ORDER BY pinned DESC, datetime(publish_at) DESC, id DESC LIMIT :limit');
    foreach (visible_post_params($site) as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $html = '';
    if ($withCss) {
        $html .= '<style>.tinyblog-feed{display:grid;gap:18px;font:inherit;color:inherit}.tinyblog-item h3{margin:0 0 4px;font-size:1.15em;line-height:1.3}.tinyblog-item a{color:inherit}.tinyblog-meta{margin:0 0 6px;font-size:.85em;opacity:.7}.tinyblog-excerpt{margin:0;line-height:1.6}.tinyblog-more{font-weight:600}</style>';
    }
    $html .= '<section class="tinyblog-feed" data-tinyblog-feed aria-label="' . htmlEscape(setting($pdo, 'blog_title', 'Blog')) . '">';
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $html .= '<p class="tinyblog-empty">No posts yet.</p>';
    }
    foreach ($rows as $post) {
        $url = canonical_url($pdo, '/post/' . rawurlencode((string) $post['slug']));
        $html .= '<article class="tinyblog-item"><h3><a href="' . htmlEscape($url) . '">' . htmlEscape((string) $post['title']) . '</a></h3>';
        $html .= '<p class="tinyblog-meta">' . post_date_html($post) . ' &middot; ' . htmlEscape(reading_time_label(post_reading_minutes($post))) . '</p>';
        if ($withExcerpt && trim((string) $post['excerpt']) !== '') {
            $html .= '<p class="tinyblog-excerpt">' . htmlEscape((string) $post['excerpt']) . '</p>';
        }
        $html .= '</article>';
    }
    $html .= '<p><a class="tinyblog-more" href="' . htmlEscape(canonical_url($pdo, '/')) . '">More posts</a></p></section>';
    security_headers('fragment');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo $html;
    exit;
}

function app_icon_head_tags(): string
{
    if (!is_file(__DIR__ . '/assets/logo.svg')) {
        return '';
    }
    return '<link rel="icon" href="' . htmlEscape(url_for('/assets/logo.svg')) . '"><meta name="theme-color" content="#f7f5f0" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#15130f" media="(prefers-color-scheme: dark)">';
}

function render_page(PDO $pdo, string $title, string $body, array $meta = []): void
{
    $blogTitle = setting($pdo, 'blog_title', 'TinyBlog Widget');
    $accent = setting($pdo, 'accent_color', TB_BRAND_ACCENT);
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
    echo css_base($accent) . css_public();
    echo '</style></head><body><a class="skip" href="#main">Skip to content</a><div class="site">';
    echo '<header class="topbar"><a class="brand" href="' . htmlEscape(url_for('/')) . '">' . htmlEscape($blogTitle) . '</a><nav><a href="' . htmlEscape(url_for('/archive')) . '">Archive</a><a href="' . htmlEscape(url_for('/about')) . '">About</a><a href="' . htmlEscape(url_for('/feed.xml')) . '">RSS</a></nav></header>';
    echo '<div id="main">' . $body . '</div>';
    echo '<footer class="footer"><p>Privacy: this blog stores subscriber emails only for opt-in updates and uses no third-party trackers.</p><p><a href="' . htmlEscape(url_for('/feed.xml')) . '">RSS</a> · <a href="' . htmlEscape(url_for('/feed.json')) . '">JSON Feed</a> · <a href="' . htmlEscape(url_for('/sitemap.xml')) . '">Sitemap</a> · <a href="' . htmlEscape(url_for('/admin')) . '">Admin</a> · Powered by <a href="' . TB_REPO_URL . '">TinyBlog</a></p></footer>';
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
    $safeAccent = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? strtolower($accent) : TB_BRAND_ACCENT;
    $darkAccent = $safeAccent === TB_BRAND_ACCENT ? '#f97316' : $safeAccent;
    $darkOnAccent = $safeAccent === TB_BRAND_ACCENT ? '#15130f' : '#ffffff';
    return "
        :root{color-scheme:light;--paper:#f7f5f0;--panel:#ffffff;--paper-deep:#efebe2;--ink:#15130f;--ink-soft:#4a463d;--muted:#6b665b;--line:#e6e1d6;--line-strong:#cec7b8;--accent:{$safeAccent};--accent-strong:#9a3412;--accent-soft:#fdebdd;--accent-tint:#fff6ef;--ok:#1f7a4c;--ok-soft:#e3f2e9;--info:#2f5bd0;--info-soft:#e8eefb;--warn:#9a5b06;--warn-soft:#fdf1dc;--danger:#b42318;--danger-soft:#fdecea;--on-accent:#ffffff;--bg:var(--paper);--text:var(--ink);--soft:var(--paper-deep);--radius:10px;--radius-sm:7px;--shadow:0 1px 2px rgba(21,19,15,.05),0 1px 1px rgba(21,19,15,.03);--max:1120px;--measure:720px;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,\"Liberation Mono\",monospace}
        @media (prefers-color-scheme: dark){:root{color-scheme:dark;--paper:#15130f;--panel:#1d1a15;--paper-deep:#110f0b;--ink:#f4f1ea;--ink-soft:#d6d0c4;--muted:#b5aea0;--line:#38332a;--line-strong:#4d463b;--accent:{$darkAccent};--accent-strong:#fdba74;--accent-soft:#3a2214;--accent-tint:#24180f;--ok:#5cc28c;--ok-soft:#173326;--info:#8fb0ff;--info-soft:#1b2540;--warn:#f0b44c;--warn-soft:#352612;--danger:#ff8a80;--danger-soft:#3a1916;--on-accent:{$darkOnAccent};--bg:var(--paper);--text:var(--ink);--soft:var(--paper-deep);--shadow:none}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;letter-spacing:0;-webkit-font-smoothing:antialiased}
        a{color:inherit;text-decoration-thickness:1px;text-underline-offset:3px}
        a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        img{max-width:100%;height:auto}
        input,textarea,select,button{font:inherit}
        code,pre,.mono{font-family:var(--mono)}
        .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        .skip{position:absolute;left:12px;top:-60px;z-index:20;background:var(--ink);color:var(--paper);padding:10px 14px;border-radius:var(--radius-sm);text-decoration:none}
        .skip:focus{top:12px}
        .muted,.meta{color:var(--muted)}
        .meta,.muted{font-size:14px;line-height:1.5}
        .small{font-size:13px}
        .button,button{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--ink);background:var(--ink);color:var(--paper);text-decoration:none;padding:9px 14px;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;font-size:14px;line-height:1.2;white-space:nowrap}
        .button:hover,button:hover{background:var(--ink-soft);border-color:var(--ink-soft)}
        .button.accent,button.accent{background:var(--accent);border-color:var(--accent);color:var(--on-accent)}
        .button.accent:hover,button.accent:hover{background:var(--accent-strong);border-color:var(--accent-strong)}
        .button.secondary,button.secondary{background:var(--panel);color:var(--ink);border-color:var(--line-strong)}
        .button.secondary:hover,button.secondary:hover{background:var(--paper-deep);border-color:var(--ink-soft)}
        .button.ghost,button.ghost{background:transparent;color:var(--ink);border-color:transparent}
        .button.ghost:hover,button.ghost:hover{background:var(--paper-deep)}
        .button.small,button.small{padding:6px 10px;font-size:13px}
        .button.wide,button.wide{width:100%}
        .link-button{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;color:inherit;padding:0;font:inherit;font-weight:600;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
        .link-button:hover{background:transparent;color:var(--accent)}
        .link-button.muted{color:var(--muted)}
        label{display:grid;gap:6px;font-size:13px;font-weight:600;margin:0 0 14px}
        input,textarea,select{width:100%;border:1px solid var(--line-strong);border-radius:var(--radius-sm);padding:9px 11px;background:var(--panel);color:var(--text);font-weight:400}
        input:hover,textarea:hover,select:hover{border-color:var(--muted)}
        input[type=checkbox],input[type=radio]{width:auto;accent-color:var(--accent)}
        input[type=color]{padding:2px;height:38px;width:52px;cursor:pointer}
        textarea{min-height:96px;line-height:1.55}
        .hint{font-size:12.5px;font-weight:400;color:var(--muted);line-height:1.45;margin:0}
        table{width:100%;border-collapse:collapse;font-size:14px}
        caption{text-align:left;font-weight:700;margin:18px 0 8px}
        th,td{text-align:left;border-bottom:1px solid var(--line);padding:11px 12px;vertical-align:middle}
        th{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
        .notice{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--panel);margin:0 0 18px;font-size:14px;line-height:1.5}
        .notice .ic{flex:none;margin-top:2px}
        .notice.success{border-color:color-mix(in srgb,var(--ok) 35%,transparent);background:var(--ok-soft);color:var(--ink)}
        .notice.info{border-color:color-mix(in srgb,var(--info) 30%,transparent);background:var(--info-soft)}
        .notice.error{border-color:color-mix(in srgb,var(--danger) 40%,transparent);background:var(--danger-soft)}
        .content{font-size:18px;line-height:1.75}
        .content h1,.content h2,.content h3{line-height:1.2;letter-spacing:-.01em}
        .content p,.content ul,.content ol,.content blockquote{margin:0 0 1.15em}
        .content blockquote{border-left:3px solid var(--accent);padding-left:16px;color:var(--ink-soft)}
        .content code{background:var(--paper-deep);border:1px solid var(--line);padding:1px 5px;border-radius:5px;font-size:.88em}
        .content pre{position:relative;overflow:auto;background:var(--paper-deep);border:1px solid var(--line);border-radius:var(--radius-sm);padding:44px 14px 14px}
        .content pre code{display:block;border:0;padding:0;background:transparent;white-space:pre;font-size:14px}
        .content .tok-key{font-weight:700;color:var(--accent-strong)}
        .content img{border-radius:var(--radius-sm)}
    ";
}

function css_public(): string
{
    return "
        .site{width:min(var(--max),calc(100% - 32px));margin:0 auto}
        .topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 0;border-bottom:1px solid var(--line)}
        .brand{font-weight:700;font-size:17px;text-decoration:none}
        nav{display:flex;gap:18px;align-items:center;font-size:14px}
        nav a{text-decoration:none;color:var(--muted)}
        nav a:hover{color:var(--text)}
        .hero{padding:56px 0 36px;border-bottom:1px solid var(--line)}
        .hero h1{font-size:clamp(38px,7vw,72px);line-height:1;margin:0 0 16px;letter-spacing:-.025em;max-width:900px}
        .hero p{font-size:clamp(18px,2.6vw,22px);line-height:1.5;color:var(--muted);max-width:720px;margin:0}
        .grid{display:grid;grid-template-columns:1fr;gap:32px;padding:36px 0}
        .post-list{display:grid;gap:24px;align-content:start}
        .post-row{padding-bottom:24px;border-bottom:1px solid var(--line)}
        .post-row h2{font-size:clamp(24px,4.4vw,34px);line-height:1.12;letter-spacing:-.015em;margin:4px 0 10px}
        .post-row h2 a{text-decoration:none}
        .post-row h2 a:hover{color:var(--accent)}
        .excerpt{font-size:17px;line-height:1.65;color:var(--ink-soft);max-width:var(--measure);margin:0}
        .article{max-width:var(--measure);padding:44px 0}
        .article h1{font-size:clamp(36px,6.5vw,56px);line-height:1.05;letter-spacing:-.025em;margin:6px 0 20px}
        .article > img{display:block;margin:0 0 28px;border-radius:var(--radius)}
        [data-copy-code]{position:absolute;top:9px;right:9px;border:1px solid var(--line-strong);background:var(--panel);color:var(--text);padding:5px 9px;font-size:12px;font-weight:600}
        [data-copy-code]:hover{background:var(--paper-deep);border-color:var(--ink-soft)}
        .tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
        .tag{border:1px solid var(--line);background:var(--panel);padding:4px 10px;border-radius:999px;text-decoration:none;font-size:13px;color:var(--ink-soft)}
        .tag:hover{border-color:var(--accent);color:var(--accent)}
        .pagination{display:flex;gap:10px;margin:8px 0 0}
        .pagination a{border:1px solid var(--line-strong);border-radius:var(--radius-sm);background:var(--panel);padding:8px 13px;text-decoration:none;font-weight:600;font-size:14px}
        .panel{border:1px solid var(--line);border-radius:var(--radius);padding:18px;background:var(--panel);align-self:start}
        .panel h2{font-size:14px;margin:0 0 10px}
        .panel form{display:grid;gap:8px;margin:0 0 16px}
        .panel form label{margin:0}
        .follow{display:flex;flex-wrap:wrap;gap:12px;font-size:14px}
        .follow a{color:var(--accent);font-weight:600;text-decoration:none}
        .related{margin-top:44px;padding-top:24px;border-top:1px solid var(--line)}
        .related h2{font-size:15px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:0 0 18px}
        .error-page form{display:flex;gap:8px;align-items:end;max-width:480px}
        .error-page form label{flex:1;margin:0}
        .footer{border-top:1px solid var(--line);padding:28px 0 42px;color:var(--muted);font-size:13px;line-height:1.6}
        .footer a{color:var(--ink-soft)}
        @media(min-width:820px){.grid{grid-template-columns:minmax(0,1fr) 280px}}
    ";
}

function css_admin(): string
{
    return "
        :root{--sidebar:248px}
        body.admin-body{background:var(--paper);font-size:15px}
        .ic{flex:none;vertical-align:middle}
        h1,h2,h3{letter-spacing:-.01em}
        .eyebrow{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:0 0 12px}
        .text-link{color:var(--accent);font-weight:600;font-size:14px;text-decoration:none}
        .text-link:hover{text-decoration:underline}
        .grow{flex:1;min-width:0}
        .truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
        .admin-shell{display:grid;grid-template-columns:1fr;min-height:100vh}
        .admin-sidebar{display:flex;flex-wrap:wrap;align-items:center;gap:6px;background:var(--panel);border-bottom:1px solid var(--line);padding:10px 12px;min-width:0}
        .admin-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex:1;min-width:0}
        .admin-brand{display:flex;align-items:center;gap:10px;text-decoration:none;min-width:0;padding:4px 6px;border-radius:var(--radius-sm)}
        .admin-brand-mark{display:inline-flex;flex:none}
        .admin-brand-mark svg,.auth-mark svg{display:block;border-radius:8px}
        @media (prefers-color-scheme: dark){.admin-brand-mark svg,.auth-mark svg{box-shadow:0 0 0 1px var(--line-strong)}}
        .admin-brand-text{display:grid;min-width:0;line-height:1.2}
        .admin-brand-name{font-weight:700;font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .admin-brand-host{font-size:12px;color:var(--muted);font-family:var(--mono);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .admin-nav{display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;margin:2px -4px 0;order:3;flex-basis:100%}
        .admin-nav-link{display:flex;align-items:center;gap:10px;color:var(--ink-soft);text-decoration:none;font-size:14px;font-weight:550;padding:8px 10px;border-radius:var(--radius-sm);white-space:nowrap}
        .admin-nav-link .ic{color:var(--muted)}
        .admin-nav-link:hover{background:var(--paper-deep);color:var(--ink)}
        .admin-nav-link[aria-current=\"page\"]{background:var(--accent-tint);color:var(--ink);box-shadow:inset 0 0 0 1px var(--accent-soft)}
        .admin-nav-link[aria-current=\"page\"] .ic{color:var(--accent)}
        .nav-count{margin-left:auto;font-size:12px;font-family:var(--mono);color:var(--muted);background:var(--paper-deep);padding:1px 7px;border-radius:999px}
        .admin-sidebar-foot{display:flex;align-items:center;gap:2px;order:2}
        .admin-sidebar-foot .admin-topbar-link span,.admin-sidebar-foot .who{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
        .admin-topbar-link{position:relative;display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13.5px;font-weight:550;text-decoration:none;padding:8px 10px;border-radius:var(--radius-sm)}
        .admin-topbar-link:hover{color:var(--ink);background:var(--paper-deep)}
        .admin-account{display:flex;align-items:center;gap:2px;position:relative}
        .admin-account-link{display:flex;align-items:center;gap:10px;flex:1;min-width:0;text-decoration:none;padding:4px 6px;border-radius:var(--radius-sm)}
        .admin-account-link:hover{background:var(--paper-deep)}
        .avatar{display:inline-grid;place-items:center;flex:none;width:30px;height:30px;border-radius:999px;background:var(--accent-soft);color:var(--accent-strong);font-weight:700;font-size:13px}
        .who{display:grid;min-width:0;line-height:1.25}
        .who strong{font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .who small{font-size:12px;color:var(--muted)}
        .admin-logout{border:0;background:transparent;color:var(--muted);padding:7px;border-radius:var(--radius-sm)}
        .admin-logout:hover{background:var(--paper-deep);color:var(--ink)}
        .admin-content{width:100%;max-width:1160px;min-width:0;padding:22px 16px 64px;margin:0 auto}
        .page-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px 20px;margin:0 0 22px}
        .page-title{min-width:0}
        .page-head h1{font-size:26px;line-height:1.15;margin:0}
        .page-sub{margin:6px 0 0;color:var(--muted);font-size:14.5px;line-height:1.5;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
        .page-sub a{color:var(--ink)}
        .page-actions{display:flex;flex-wrap:wrap;gap:8px}
        .back-link{display:inline-flex;align-items:center;gap:5px;color:var(--muted);font-size:13px;font-weight:550;text-decoration:none;margin:0 0 6px}
        .back-link:hover{color:var(--ink)}
        .card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow);margin:0 0 18px;min-width:0}
        .card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
        .card-head h2,.card-title{font-size:16px;margin:0}
        .card-title{margin:0 0 14px}
        .card-foot{border-top:1px solid var(--line);margin:16px -20px -20px;padding:10px 20px}
        .home-grid{display:grid;gap:18px;align-items:start}
        .home-main,.home-side{min-width:0}
        .progress{height:6px;border-radius:99px;background:var(--paper-deep);overflow:hidden;margin:0 0 6px}
        .progress span{display:block;height:100%;background:var(--accent);border-radius:99px}
        .steps{list-style:none;margin:0;padding:0}
        .step{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}
        .step:last-child{border-bottom:0}
        .step form{margin:0}
        .step-dot{display:inline-grid;place-items:center;flex:none;width:24px;height:24px;border-radius:999px;border:1.5px solid var(--line-strong);background:var(--panel)}
        .step.done .step-dot{background:var(--ok);border-color:var(--ok);color:#fff}
        .step.next .step-dot{border-color:var(--accent);box-shadow:0 0 0 4px var(--accent-soft)}
        .step-text{display:grid;gap:2px;flex:1;min-width:0}
        .step-text strong{font-size:14.5px;font-weight:600}
        .step-text span{font-size:13.5px;color:var(--muted);line-height:1.45}
        .step.done .step-text strong{color:var(--muted);text-decoration:line-through;text-decoration-color:var(--line-strong)}
        .empty-state{text-align:left;padding:28px}
        .empty-state h2{margin:0 0 6px;font-size:18px}
        .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0}
        .toolbar form{margin:0}
        .mini-list{list-style:none;margin:0;padding:0}
        .mini-list li{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--line);font-size:14.5px}
        .mini-list li:last-child{border-bottom:0}
        .mini-list a{font-weight:550;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .mini-list a:hover{color:var(--accent)}
        .mini-list .muted{white-space:nowrap}
        .status-badge{display:inline-flex;align-items:center;gap:6px;flex:none;border-radius:999px;padding:2px 9px 2px 8px;font-size:12px;font-weight:600;text-transform:capitalize;line-height:1.5;background:var(--paper-deep);color:var(--ink-soft)}
        .status-badge::before{content:\"\";width:6px;height:6px;border-radius:99px;background:currentColor;opacity:.8}
        .status-badge.published{background:var(--ok-soft);color:var(--ok)}
        .status-badge.scheduled{background:var(--info-soft);color:var(--info)}
        .status-badge.draft{background:var(--paper-deep);color:var(--muted)}
        .stat-strip{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--radius-sm);overflow:hidden}
        .stat-cell{padding:14px;background:var(--panel);min-width:0}
        .stat-value{font-size:24px;line-height:1;font-weight:700;margin:0 0 6px;font-variant-numeric:tabular-nums}
        .stat-label{font-size:12.5px;color:var(--muted);margin:0}
        .bars{list-style:none;margin:0 0 12px;padding:0;display:grid;grid-template-columns:minmax(0,1fr);gap:12px}
        .bars li{min-width:0}
        .bar-label{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;margin:0 0 5px}
        .bar-label a{text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .bar{display:block;height:6px;border-radius:99px;background:var(--paper-deep)}
        .bar span{display:block;height:100%;border-radius:99px;background:var(--accent)}
        .list-tools{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
        .tabs{display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;border-bottom:1px solid var(--line);flex:1;min-width:0}
        .tab{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;margin-bottom:-1px;border-bottom:2px solid transparent;color:var(--muted);font-size:14px;font-weight:550;text-decoration:none;white-space:nowrap}
        .tab:hover{color:var(--ink)}
        .tab[aria-current=\"page\"]{color:var(--ink);border-color:var(--accent)}
        .tab .count{font-size:12px;font-family:var(--mono);color:var(--muted)}
        .settings-tabs{margin:0 0 20px}
        .search{position:relative;margin:0;width:min(100%,260px)}
        .search input{padding-left:34px}
        .search-ic{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);display:flex}
        .table-card{position:relative;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);overflow-x:auto;box-shadow:var(--shadow);margin:0 0 18px}
        .table-card table{min-width:560px}
        .table-card tr:last-child td{border-bottom:0}
        .table-card tbody tr:hover td{background:var(--accent-tint)}
        .num{text-align:right;font-variant-numeric:tabular-nums}
        .row-title{font-weight:600;text-decoration:none;font-size:15px}
        .row-title:hover{color:var(--accent)}
        .pin{display:inline-flex;align-items:center;gap:3px;font-size:12px;color:var(--accent);font-weight:600}
        .row-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:4px;font-size:13px;color:var(--muted)}
        .row-actions a,.row-actions .link-button{color:var(--muted);text-decoration:none;font-weight:550}
        .row-actions a:hover,.row-actions .link-button:hover{color:var(--accent)}
        .row-actions form{margin:0}
        td.row-actions{margin:0;justify-content:flex-end}
        td.end{text-align:right}
        .pager{display:flex;gap:8px;justify-content:flex-end}
        .restore-banner{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-radius:var(--radius-sm);background:var(--warn-soft);border:1px solid color-mix(in srgb,var(--warn) 30%,transparent);margin:0 0 16px;font-size:14px}
        .restore-banner > span{display:flex;align-items:center;gap:8px}
        .restore-banner[hidden]{display:none}
        .restore-actions{display:flex;align-items:center;gap:12px}
        .editor-form{margin:0}
        .editor-grid{display:grid;gap:18px;align-items:start}
        .editor-main,.editor-side{min-width:0}
        .editor-main{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:22px 22px 12px;box-shadow:var(--shadow)}
        .title-input{border:0;padding:0;font-size:30px;font-weight:700;letter-spacing:-.02em;line-height:1.2;background:transparent;border-radius:0}
        .title-input:focus-visible{outline:none;box-shadow:0 2px 0 var(--accent)}
        .slug-row{display:flex;align-items:center;gap:2px;margin:8px 0 16px;font-size:13px;min-width:0}
        .slug-row .mono{flex:none;max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .slug-input{border:0;border-bottom:1px dashed var(--line-strong);border-radius:0;padding:2px 0;font-family:var(--mono);font-size:13px;background:transparent;color:var(--ink-soft)}
        .editor-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin:0 0 14px}
        .markdown-toolbar{display:flex;flex-wrap:wrap;gap:2px}
        .tool{background:transparent;color:var(--ink-soft);border:0;padding:6px 9px;min-width:32px;font-size:13.5px;font-weight:600;border-radius:6px}
        .tool:hover{background:var(--paper-deep);color:var(--ink)}
        .tool-media{color:var(--accent)}
        .segmented{display:inline-flex;padding:3px;gap:2px;background:var(--paper-deep);border-radius:9px}
        .segmented button,.seg-option span{background:transparent;border:0;color:var(--muted);padding:5px 11px;font-size:13px;font-weight:600;border-radius:7px;cursor:pointer}
        .segmented button:hover{background:transparent;color:var(--ink)}
        .segmented button[aria-pressed=\"true\"],.seg-option input:checked + span{background:var(--panel);color:var(--ink);box-shadow:0 1px 2px rgba(21,19,15,.12)}
        .segmented.wide{display:flex;flex-wrap:wrap;width:100%}
        .segmented.wide .seg-option{flex:1 1 auto}
        .seg-option{display:flex;margin:0;position:relative}
        .seg-option input{position:absolute;opacity:0;pointer-events:none}
        .seg-option span{flex:1;text-align:center;white-space:nowrap}
        .seg-option input:focus-visible + span{outline:2px solid var(--accent);outline-offset:1px}
        .split-only{display:none}
        .write-area{display:grid;gap:16px}
        .write-area textarea{min-height:52vh;border:0;padding:0;font-family:var(--mono);font-size:14.5px;line-height:1.7;resize:vertical;background:transparent}
        .write-area textarea:focus-visible{outline:none}
        .editor-preview{display:none;min-height:52vh;padding:4px 2px;font-size:17px}
        .editor-preview h1{font-size:30px;line-height:1.15;margin:0 0 16px}
        .editor-preview[aria-busy=\"true\"]{opacity:.6}
        .write-area[data-view-mode=\"preview\"] textarea{display:none}
        .write-area[data-view-mode=\"preview\"] .editor-preview{display:block}
        .write-area[data-view-mode=\"split\"] .editor-preview{display:block;border-left:1px solid var(--line);padding-left:18px;max-height:70vh;overflow:auto}
        .editor-status{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px;border-top:1px solid var(--line);margin:12px 0 0;padding:10px 0 0}
        .editor-side{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
        .rail-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin:0;padding:16px 18px 4px}
        .rail-section{padding:14px 18px;border-bottom:1px solid var(--line)}
        .rail-section:last-child{border-bottom:0}
        .rail-section h3{font-size:13.5px;font-weight:600;margin:0 0 10px}
        .rail-section h3 label{margin:0;font-size:inherit}
        .rail-section > .hint{margin-top:8px}
        .rail-section textarea{min-height:76px}
        .schedule-fields{margin-top:12px}
        .schedule-fields label{margin:0 0 6px}
        .schedule-fields[hidden]{display:none}
        .counter.over{color:var(--danger);font-weight:600}
        .hero-pick{aspect-ratio:16/9;border-radius:var(--radius-sm);border:1px dashed var(--line-strong);background:var(--paper-deep);overflow:hidden;display:grid;place-items:center;margin:0 0 10px}
        .hero-pick img{width:100%;height:100%;object-fit:cover}
        .hero-empty{display:grid;justify-items:center;gap:4px;color:var(--muted);font-size:13px}
        .row-buttons{display:flex;align-items:center;gap:12px}
        .url-details{margin-top:10px;font-size:13px}
        .url-details summary{cursor:pointer;color:var(--muted)}
        .url-details input{margin-top:8px}
        .serp{display:grid;gap:3px;padding:12px;border-radius:var(--radius-sm);background:var(--paper);border:1px solid var(--line);font-family:Arial,Helvetica,sans-serif}
        .serp-url{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .serp-title{font-size:16px;color:#1a0dab;line-height:1.3}
        .serp-desc{font-size:13px;color:var(--ink-soft);line-height:1.45}
        @media (prefers-color-scheme: dark){.serp-title{color:#8ab4f8}}
        label.check{display:flex;align-items:center;gap:9px;font-weight:500;font-size:14px}
        .danger-link{color:var(--danger)}
        .danger-link:hover{color:var(--danger)}
        dialog.media-picker{width:min(760px,calc(100vw - 32px));max-height:min(640px,calc(100vh - 48px));border:1px solid var(--line);border-radius:var(--radius);padding:0;background:var(--panel);color:var(--ink);box-shadow:0 24px 60px rgba(21,19,15,.25)}
        dialog.media-picker::backdrop{background:rgba(21,19,15,.45)}
        .media-picker > *{margin-left:20px;margin-right:20px}
        .picker-head{display:flex;justify-content:space-between;align-items:center;margin-top:16px;margin-bottom:12px}
        .picker-head h2{font-size:17px;margin:0}
        .picker-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:12px}
        .picker-item{display:grid;gap:6px;padding:6px;background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-sm);color:var(--ink);text-align:left;white-space:normal}
        .picker-item:hover{border-color:var(--accent);background:var(--accent-tint)}
        .picker-item img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:5px}
        .picker-item span{font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .media-picker > p:last-child{margin-bottom:16px}
        .upload-dropzone{display:grid;gap:14px;border:1.5px dashed var(--line-strong);background:var(--panel)}
        .upload-dropzone.is-dragover{background:var(--accent-tint);border-color:var(--accent)}
        .drop-copy{display:flex;align-items:center;gap:12px;color:var(--ink-soft)}
        .drop-copy > div{display:grid;gap:2px}
        .drop-fields{display:grid;gap:10px;align-items:end}
        .drop-fields label{margin:0}
        .media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}
        .media-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;min-width:0;box-shadow:var(--shadow)}
        .media-thumb{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:var(--paper-deep);border-bottom:1px solid var(--line)}
        .media-card-body{padding:12px;display:grid;gap:5px;font-size:13.5px}
        .media-card-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px}
        .media-card-actions form{margin:0}
        .warn-text{display:inline-flex;align-items:center;gap:4px;color:var(--warn);font-weight:600}
        .embed-grid{display:grid;gap:18px;align-items:start}
        .embed-steps{min-width:0}
        .step-head{display:flex;gap:14px;align-items:flex-start;margin:0 0 16px}
        .step-head h2{font-size:16.5px;margin:2px 0 3px}
        .step-head p{margin:0}
        .step-num{display:inline-grid;place-items:center;flex:none;width:28px;height:28px;border-radius:999px;background:var(--ink);color:var(--paper);font-size:13px;font-weight:700}
        .origin-list{list-style:none;margin:0 0 14px;padding:0;border:1px solid var(--line);border-radius:var(--radius-sm)}
        .origin-list li{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);font-size:14px;flex-wrap:wrap}
        .origin-list li:last-child{border-bottom:0}
        .origin-list form{margin:0 0 0 auto}
        .origin{display:inline-flex;align-items:center;gap:7px;font-family:var(--mono);font-size:13.5px;min-width:0;overflow-wrap:anywhere}
        .pill{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--paper-deep);color:var(--muted)}
        .pill.ok{background:var(--ok-soft);color:var(--ok)}
        .pill.warn{background:var(--warn-soft);color:var(--warn)}
        .inline-form{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0}
        .inline-form label{margin:0}
        .refused{margin-top:16px;padding-top:14px;border-top:1px dashed var(--line-strong)}
        .refused p{margin:0 0 10px}
        .embed-form{margin:0}
        .field-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end;margin-top:16px}
        .field-row label{margin:0}
        .field-row label.check{min-height:40px}
        [hidden]{display:none !important}
        .chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px}
        .chip-link{padding:6px 11px;border-radius:999px;border:1px solid var(--line-strong);font-size:13px;font-weight:550;text-decoration:none;color:var(--ink-soft);background:var(--panel)}
        .chip-link:hover{border-color:var(--ink-soft);color:var(--ink)}
        .chip-link[aria-current=\"page\"]{background:var(--ink);border-color:var(--ink);color:var(--paper)}
        .howto{margin:0 0 14px;padding-left:20px;font-size:14px;line-height:1.6;color:var(--ink-soft)}
        .code-block{border-radius:var(--radius-sm);overflow:hidden;background:#110f0b;color:#efe9dc;border:1px solid #2a251d;margin:0 0 10px}
        .code-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 8px 8px 14px;border-bottom:1px solid #2a251d;font-size:12px;font-family:var(--mono);color:#b5aea0}
        .code-block pre{margin:0;padding:14px;overflow-x:auto;font-size:13px;line-height:1.6;white-space:pre-wrap;word-break:break-all}
        .embed-preview{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow);min-width:0}
        .embed-preview .card-head{margin-bottom:12px}
        .embed-preview .eyebrow{margin:0}
        .preview-stage{border-radius:var(--radius-sm);border:1px solid var(--line);background:repeating-linear-gradient(45deg,var(--paper) 0 10px,var(--paper-deep) 10px 11px);padding:16px;min-height:220px;margin:0 0 10px}
        .preview-stage.theme-dark{background:#1b1915}
        .form-card{max-width:760px}
        .form-card form{margin:0}
        .color-field{display:flex;align-items:center;gap:10px}
        .color-field code{font-size:13px;color:var(--muted)}
        details.advanced > summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between}
        details.advanced > summary::after{content:\"Show\";font-size:13px;color:var(--muted);font-weight:550}
        details.advanced[open] > summary::after{content:\"Hide\"}
        details.advanced > summary .card-title{margin:0}
        details.advanced[open] > summary{margin:0 0 16px}
        .danger-zone{margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}
        .danger-zone h3{font-size:14.5px;margin:0 0 6px}
        .danger-zone p{margin:0 0 12px}
        .health-panel table{margin-top:4px}
        .health-dot{display:inline-block;width:8px;height:8px;border-radius:99px;margin-right:9px;vertical-align:middle}
        .health-ok .health-dot{background:var(--ok)}
        .health-warn .health-dot{background:var(--warn)}
        .health-warn td:last-child{color:var(--warn);font-weight:600}
        .auth-shell{min-height:100vh;display:grid;place-items:center;padding:40px 16px}
        .auth-card{width:min(100%,420px);border:1px solid var(--line);border-radius:14px;background:var(--panel);padding:28px;box-shadow:0 10px 30px rgba(21,19,15,.06)}
        .auth-card h1{font-size:24px;margin:0 0 6px}
        .auth-card > .muted{margin:0 0 20px}
        .auth-brand{display:inline-flex;align-items:center;gap:10px;margin:0 0 26px;text-decoration:none;font-weight:700}
        .auth-mark{display:inline-flex}
        .auth-foot{margin:18px 0 0;text-align:center;font-size:13px}
        @media(min-width:720px){.drop-fields{grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) auto}.stat-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(min-width:900px){
            .admin-shell{grid-template-columns:var(--sidebar) minmax(0,1fr)}
            .admin-sidebar{position:sticky;top:0;height:100vh;flex-direction:column;flex-wrap:nowrap;align-items:stretch;border-bottom:0;border-right:1px solid var(--line);padding:16px 12px}
            .admin-topbar{flex:none;margin:0 0 10px}
            .admin-nav{flex-direction:column;overflow:visible;margin:0;order:0;flex-basis:auto}
            .admin-sidebar-foot{display:grid;gap:4px;margin-top:auto;order:0}
            .admin-sidebar-foot .admin-topbar-link span,.admin-sidebar-foot .who{position:static;width:auto;height:auto;overflow:visible;clip:auto}
            .admin-sidebar-foot .who{display:grid;overflow:hidden}
            .admin-account{gap:6px;border-top:1px solid var(--line);padding-top:12px;margin-top:6px}
            .admin-content{padding:30px 36px 72px}
            .home-grid{grid-template-columns:minmax(0,1fr) 320px}
            .embed-grid{grid-template-columns:minmax(0,1fr) minmax(300px,380px)}
            .embed-preview{position:sticky;top:24px}
        }
        @media(min-width:1100px){
            .editor-grid{grid-template-columns:minmax(0,1fr) 320px}
            .editor-side{position:sticky;top:24px;max-height:calc(100vh - 48px);overflow:auto}
            .split-only{display:inline-flex}
            .write-area[data-view-mode=\"split\"]{grid-template-columns:1fr 1fr}
        }
        @media(max-width:560px){.page-head h1{font-size:23px}.title-input{font-size:24px}.editor-main{padding:16px}.card{padding:16px}.card-foot{margin:14px -16px -16px;padding:10px 16px}.page-actions{width:100%}.page-actions .button,.page-actions form,.page-actions form button{flex:1}.search{width:100%}.step{flex-wrap:wrap;row-gap:8px}.step-text{flex-basis:calc(100% - 38px)}.step .button{margin-left:38px}.title-input{font-size:22px}}
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
        $body .= '<p class="muted">No posts yet. Check back soon.</p>';
    }
    foreach ($posts as $post) {
        $body .= post_row($post);
    }
    $body .= pagination_links('/archive', $page, $hasMore);
    $body .= '</section><aside class="panel"><form method="get" action="' . htmlEscape(url_for('/search')) . '"><label>Search<input name="q" placeholder="Search posts"></label><button class="secondary">Search</button></form><h2>Follow</h2><p class="follow"><a href="' . htmlEscape(url_for('/feed.xml')) . '">RSS</a><a href="' . htmlEscape(url_for('/feed.json')) . '">JSON Feed</a><a href="' . htmlEscape(url_for('/archive')) . '">Archive</a></p></aside></main>';
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

function post_date_html(array $post): string
{
    $raw = post_publish_at($post);
    $time = strtotime($raw . ' UTC');
    if ($time === false) {
        return htmlEscape($raw);
    }
    return '<time datetime="' . htmlEscape(gmdate('c', $time)) . '">' . htmlEscape(gmdate('M j, Y', $time)) . '</time>';
}

function post_row(array $post): string
{
    $tags = split_tags($post['tags']);
    $reading = reading_time_label(post_reading_minutes($post));
    $meta = post_date_html($post) . ($reading !== '' ? ' &middot; ' . htmlEscape($reading) : '');
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
        render_not_found($pdo, 'That post does not exist or is not published.');
    }
    track_post_view($pdo, (int) $post['id']);
    $reading = reading_time_label(post_reading_minutes($post));
    $meta = post_date_html($post) . ($reading !== '' ? ' &middot; ' . htmlEscape($reading) : '');
    $imageDimensions = media_dimensions_by_url($pdo);
    $body = '<main class="article"><p class="meta">' . $meta . '</p><h1>' . htmlEscape($post['title']) . '</h1>';
    if (!empty($post['hero_image_url'])) {
        $body .= '<img src="' . htmlEscape($post['hero_image_url']) . '" alt="" loading="lazy"' . image_dimension_attrs($imageDimensions, (string) $post['hero_image_url']) . '>';
    }
    $body .= '<div class="content">' . add_media_dimensions_to_html((string) $post['content_html'], $imageDimensions) . '</div>';
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

function recent_posts_for_error(PDO $pdo, int $limit = 3): array
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE ' . visible_post_where() . ' ORDER BY datetime(publish_at) DESC, id DESC LIMIT :limit');
    foreach (visible_post_params($site) as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', max(1, min(6, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function error_page_body(PDO $pdo, string $heading, string $message): string
{
    $body = '<main class="article error-page"><h1>' . htmlEscape($heading) . '</h1><p class="muted">' . htmlEscape($message) . '</p>';
    $body .= '<form method="get" action="' . htmlEscape(url_for('/search')) . '"><label>Search<input name="q" placeholder="Search posts"></label><button>Search</button></form>';
    $recent = recent_posts_for_error($pdo);
    if ($recent) {
        $body .= '<section class="related"><h2>Recent posts</h2>';
        foreach ($recent as $post) {
            $body .= post_row($post);
        }
        $body .= '</section>';
    }
    return $body . '</main>';
}

function render_not_found(PDO $pdo, string $message = 'That page does not exist or may have moved.'): void
{
    http_response_code(404);
    render_page($pdo, 'Not found', error_page_body($pdo, 'Page not found', $message), [
        'description' => 'The requested TinyBlog page was not found.',
        'canonical' => canonical_url($pdo, '/'),
    ]);
}

function render_error_page(PDO $pdo, string $message): void
{
    render_page($pdo, 'Server error', error_page_body($pdo, 'Server error', $message), [
        'description' => 'TinyBlog server error.',
        'canonical' => canonical_url($pdo, '/'),
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

// ---------------------------------------------------------------------------
// Admin UI (v2): jobs-first navigation — Home, Posts, Media, Subscribers,
// Add to site, Settings. Plain HTML forms first; small inline scripts add
// convenience. Every write is CSRF-protected and uses prepared statements.
// ---------------------------------------------------------------------------

function tb_mark_svg(int $size = 28): string
{
    return '<svg class="tb-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><rect width="32" height="32" rx="8" fill="#15130F"/><rect x="21.28" y="20.23" width="4.74" height="4.74" rx=".76" fill="#F97316"/><path fill="#F7F5F0" d="M6.58 25.02V7.8h4.11v6.52h.09c.17-.4.4-.77.71-1.14.32-.36.71-.66 1.2-.89.48-.23 1.07-.35 1.75-.35.9 0 1.73.24 2.51.71.79.47 1.42 1.2 1.9 2.18.49.98.73 2.23.73 3.75 0 1.46-.24 2.69-.7 3.68-.46.98-1.08 1.72-1.87 2.22-.78.49-1.64.74-2.6.74-.65 0-1.21-.11-1.69-.32a3.6 3.6 0 0 1-1.2-.84 4.2 4.2 0 0 1-.74-1.12h-.13v2.1H6.58Zm4.03-6.46c0 .7.09 1.3.28 1.82.19.51.46.91.82 1.2.36.28.78.42 1.28.42.5 0 .93-.14 1.29-.42.35-.29.62-.69.8-1.2.18-.52.27-1.12.27-1.82 0-.69-.09-1.3-.27-1.8-.18-.51-.45-.91-.8-1.19-.35-.28-.78-.42-1.29-.42-.5 0-.93.14-1.29.41-.35.28-.62.67-.81 1.18-.19.51-.28 1.12-.28 1.82Z"/></svg>';
}

function admin_icon(string $name, int $size = 18): string
{
    static $paths = [
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .7-1.53l7-6a2 2 0 0 1 2.6 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'code' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'arrow-up-right' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'copy' => '<rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
        'eye' => '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
    ];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function admin_url(string $action = 'dashboard', array $params = []): string
{
    if ($action !== 'dashboard') {
        $params = ['action' => $action] + $params;
    }
    return url_for('/admin') . ($params ? '?' . http_build_query($params) : '');
}

function admin_flash(string $type, string $text): void
{
    secure_session_start();
    $_SESSION['tb_flash'] = ['type' => $type === 'error' ? 'error' : 'success', 'text' => $text];
}

function admin_flash_pull(): ?array
{
    secure_session_start();
    $flash = $_SESSION['tb_flash'] ?? null;
    unset($_SESSION['tb_flash']);
    return is_array($flash) ? $flash : null;
}

function admin_redirect(string $action = 'dashboard', array $params = []): void
{
    header('Location: ' . admin_url($action, $params));
    exit;
}

function admin_page_head(string $title, string $subHtml = '', string $actionsHtml = '', string $backHtml = ''): string
{
    $html = '<header class="page-head"><div class="page-title">' . $backHtml . '<h1>' . htmlEscape($title) . '</h1>';
    if ($subHtml !== '') {
        $html .= '<p class="page-sub">' . $subHtml . '</p>';
    }
    $html .= '</div>';
    if ($actionsHtml !== '') {
        $html .= '<div class="page-actions">' . $actionsHtml . '</div>';
    }
    return $html . '</header>';
}

function host_label(string $url): string
{
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
    return $host !== '' ? $host . $path : $url;
}

function human_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return (int) round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

function site_timezone(PDO $pdo): DateTimeZone
{
    try {
        return new DateTimeZone(setting($pdo, 'timezone', 'UTC') ?: 'UTC');
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

function format_local_time(PDO $pdo, ?string $utc, string $format = 'M j, Y · H:i'): string
{
    $time = strtotime((string) $utc . ' UTC');
    if ($time === false || (string) $utc === '') {
        return '';
    }
    $date = (new DateTimeImmutable('@' . $time))->setTimezone(site_timezone($pdo));
    return $date->format($format);
}

function admin_nav_counts(PDO $pdo, string $site): array
{
    $posts = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE site = :site');
    $posts->execute([':site' => $site]);
    $subs = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE site = :site AND status = 'active'");
    $subs->execute([':site' => $site]);
    return ['posts' => (int) $posts->fetchColumn(), 'subscribers' => (int) $subs->fetchColumn()];
}

function admin_sidebar(PDO $pdo, array $user, string $action, string $blogTitle): string
{
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $counts = admin_nav_counts($pdo, setting($pdo, 'site_key', 'store-1'));
    $nav = [
        'dashboard' => ['Home', 'home', 0],
        'posts' => ['Posts', 'file-text', $counts['posts']],
        'media' => ['Media', 'image', 0],
    ];
    if ($isAdmin) {
        $nav['subscribers'] = ['Subscribers', 'users', $counts['subscribers']];
        $nav['embed'] = ['Add to site', 'code', 0];
        $nav['settings'] = ['Settings', 'settings', 0];
    }
    $current = match ($action) {
        'edit' => 'posts',
        'users' => 'settings',
        default => $action,
    };
    $name = (string) ($user['name'] ?? 'Admin');
    $initial = strtoupper(substr($name !== '' ? $name : 'A', 0, 1));
    $html = '<aside class="admin-sidebar"><div class="admin-topbar"><a class="admin-brand" href="' . htmlEscape(admin_url()) . '"><span class="admin-brand-mark">' . tb_mark_svg(30) . '</span><span class="admin-brand-text"><span class="admin-brand-name">' . htmlEscape($blogTitle) . '</span><span class="admin-brand-host">' . htmlEscape(host_label(setting($pdo, 'canonical_base', base_url()))) . '</span></span></a></div>';
    $html .= '<nav class="admin-nav" aria-label="Admin sections">';
    foreach ($nav as $key => [$label, $icon, $count]) {
        $currentAttr = $key === $current ? ' aria-current="page"' : '';
        $html .= '<a class="admin-nav-link" href="' . htmlEscape(admin_url($key)) . '"' . $currentAttr . '>' . admin_icon($icon) . '<span>' . htmlEscape($label) . '</span>';
        if ($count > 0) {
            $html .= '<span class="nav-count">' . (int) $count . '</span>';
        }
        $html .= '</a>';
    }
    $html .= '</nav><div class="admin-sidebar-foot"><a class="admin-topbar-link" href="' . htmlEscape(url_for('/')) . '">' . admin_icon('arrow-up-right', 16) . '<span>View site</span></a>';
    $html .= '<div class="admin-account"><a class="admin-account-link" href="' . htmlEscape(admin_url('account')) . '"><span class="avatar" aria-hidden="true">' . htmlEscape($initial) . '</span><span class="who"><strong>' . htmlEscape($name) . '</strong><small>' . htmlEscape(ucfirst((string) ($user['role'] ?? ''))) . ' · Account</small></span></a>';
    $html .= '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="logout"><button class="admin-logout" type="submit" title="Log out" aria-label="Log out">' . admin_icon('logout', 16) . '</button></form></div></div></aside>';
    return $html;
}

function admin_page_title(string $action): string
{
    return match ($action) {
        'posts' => 'Posts',
        'edit' => 'Editor',
        'media' => 'Media',
        'subscribers' => 'Subscribers',
        'embed' => 'Add to site',
        'settings' => 'Settings',
        'users' => 'Users',
        'account' => 'Account',
        default => 'Home',
    };
}

function render_admin(PDO $pdo): void
{
    $user = current_user($pdo);
    $action = admin_route_action();
    $message = '';
    $error = '';
    $formState = null;

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
            $adminOnly = ['save_settings', 'seed_samples', 'export_data', 'import_data', 'add_user', 'delete_user', 'add_origin', 'remove_origin', 'allow_origin', 'change_site_key', 'send_test_email', 'export_subscribers', 'delete_subscriber', 'dismiss_setup', 'skip_mail_step'];
            if (in_array($postAction, $adminOnly, true)) {
                require_admin($user);
            }
            if ($postAction === 'preview_markdown') {
                $body = substr((string) ($_POST['body_markdown'] ?? ''), 0, 200000);
                json_response(['html' => render_post_content_html($pdo, sanitize_markdown($body))]);
            }
            if ($postAction === 'save_post') {
                $result = save_post($pdo, $user);
                if ($result['ok']) {
                    admin_flash('success', $result['message']);
                    admin_redirect('edit', ['id' => $result['id'], 'saved' => $result['created'] ? 'new' : '1']);
                }
                $error = $result['message'];
                $action = 'edit';
                $formState = $result['post'];
            }
            if ($postAction === 'delete_post') {
                admin_flash('success', delete_post($pdo, $user));
                admin_redirect('posts');
            }
            if ($postAction === 'toggle_post_status') {
                admin_flash('success', toggle_post_status($pdo, $user));
                $return = (string) ($_POST['return'] ?? 'posts');
                admin_redirect(in_array($return, ['dashboard', 'posts'], true) ? $return : 'posts', array_filter(['status' => (string) ($_POST['return_status'] ?? '')]));
            }
            if ($postAction === 'upload_media') {
                $result = upload_media($pdo, $user);
                admin_flash(str_starts_with($result, 'OK:') ? 'success' : 'error', str_starts_with($result, 'OK:') ? substr($result, 3) : $result);
                admin_redirect('media');
            }
            if ($postAction === 'delete_media') {
                admin_flash('success', delete_media($pdo));
                admin_redirect('media');
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
            if ($postAction === 'add_user' || $postAction === 'delete_user') {
                $result = $postAction === 'add_user' ? add_user($pdo) : delete_user($pdo, $user);
                if (str_starts_with($result, 'OK:')) {
                    $message = substr($result, 3);
                } else {
                    $error = $result;
                }
                $action = 'users';
            }
            if ($postAction === 'save_settings') {
                $tab = settings_tab();
                $result = save_settings($pdo, $tab);
                admin_flash(str_starts_with($result, 'OK:') ? 'success' : 'error', str_starts_with($result, 'OK:') ? substr($result, 3) : $result);
                admin_redirect('settings', $tab === 'general' ? [] : ['tab' => $tab]);
            }
            if ($postAction === 'change_site_key') {
                $result = change_site_key($pdo);
                admin_flash(str_starts_with($result, 'OK:') ? 'success' : 'error', str_starts_with($result, 'OK:') ? substr($result, 3) : $result);
                admin_redirect('settings');
            }
            if ($postAction === 'send_test_email') {
                $result = send_test_email($pdo, $user);
                admin_flash(str_starts_with($result, 'OK:') ? 'success' : 'error', str_starts_with($result, 'OK:') ? substr($result, 3) : $result);
                admin_redirect('settings', ['tab' => 'email']);
            }
            if (in_array($postAction, ['add_origin', 'remove_origin', 'allow_origin'], true)) {
                $result = update_allowed_origins($pdo, $postAction, (string) ($_POST['origin'] ?? ''));
                admin_flash(str_starts_with($result, 'OK:') ? 'success' : 'error', str_starts_with($result, 'OK:') ? substr($result, 3) : $result);
                admin_redirect('embed');
            }
            if ($postAction === 'export_subscribers') {
                export_subscribers_csv($pdo);
            }
            if ($postAction === 'delete_subscriber') {
                admin_flash('success', delete_subscriber($pdo));
                admin_redirect('subscribers', array_filter(['status' => (string) ($_POST['return_status'] ?? '')]));
            }
            if ($postAction === 'dismiss_setup') {
                set_setting($pdo, 'setup_dismissed', '1');
                admin_flash('success', 'Setup checklist hidden. You can bring it back from Settings.');
                admin_redirect();
            }
            if ($postAction === 'skip_mail_step') {
                set_setting($pdo, 'mail_step_skipped', '1');
                admin_redirect();
            }
            if ($postAction === 'export_data') {
                export_data($pdo);
            }
            if ($postAction === 'import_data') {
                admin_flash('success', import_data($pdo));
                admin_redirect('settings', ['tab' => 'backup']);
            }
            if ($postAction === 'seed_samples') {
                seed_samples($pdo, $user);
                admin_flash('success', 'Sample posts loaded.');
                admin_redirect();
            }
        }
    }

    if (!$user) {
        echo admin_head($pdo, user_count($pdo) === 0 ? 'Create admin' : 'Log in');
        echo '<main class="auth-shell"><section class="auth-card"><a class="auth-brand" href="' . htmlEscape(url_for('/')) . '"><span class="auth-mark">' . tb_mark_svg(32) . '</span><span>' . htmlEscape(setting($pdo, 'blog_title', 'TinyBlog Widget')) . '</span></a>';
        if ($error) {
            echo '<p class="notice error" role="alert">' . htmlEscape($error) . '</p>';
        }
        if (user_count($pdo) === 0) {
            echo '<h1>Create the first admin</h1><p class="muted">No default admin credentials ship with TinyBlog. This account can add editors later.</p>';
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="register">';
            echo '<label>Name<input name="name" required autocomplete="name"></label>';
            echo '<label>Email<input name="email" type="email" required autocomplete="email"></label>';
            echo '<label>Password<input name="password" type="password" minlength="12" required autocomplete="new-password"><span class="hint">At least 12 characters.</span></label>';
            echo '<button class="button wide">Create admin</button></form>';
        } else {
            echo '<h1>Log in</h1><p class="muted">Write, publish, and manage your blog.</p><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="login">';
            echo '<label>Email<input name="email" type="email" required autocomplete="email"></label>';
            echo '<label>Password<input name="password" type="password" required autocomplete="current-password"></label>';
            echo '<button class="button wide">Log in</button></form>';
        }
        echo '<p class="auth-foot muted">Powered by <a href="' . TB_REPO_URL . '">TinyBlog</a></p></section></main></body></html>';
        exit;
    }

    $flash = admin_flash_pull();
    $blogTitle = setting($pdo, 'blog_title', 'TinyBlog Widget');
    echo admin_head($pdo, admin_page_title($action));
    echo '<a class="skip" href="#admin-main">Skip to content</a><div class="admin-shell">';
    echo admin_sidebar($pdo, $user, $action, $blogTitle);
    echo '<main class="admin-content" id="admin-main">';
    if ($flash) {
        echo '<p class="notice ' . htmlEscape($flash['type']) . '"' . ($flash['type'] === 'error' ? ' role="alert"' : ' role="status"') . '>' . htmlEscape((string) $flash['text']) . '</p>';
    }
    if ($message) {
        echo '<p class="notice success" role="status">' . htmlEscape($message) . '</p>';
    }
    if ($error) {
        echo '<p class="notice error" role="alert">' . htmlEscape($error) . '</p>';
    }
    if ($action === 'edit') {
        render_post_form($pdo, $user, $formState);
    } elseif ($action === 'posts') {
        render_posts_admin($pdo, $user);
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
    } elseif ($action === 'embed') {
        require_admin($user);
        render_embed_admin($pdo);
    } elseif ($action === 'settings') {
        require_admin($user);
        render_settings_admin($pdo);
    } else {
        render_dashboard_admin($pdo, $user);
    }
    echo '</main></div>';
    echo admin_common_script();
    echo '</body></html>';
    exit;
}

function admin_head(PDO $pdo, string $title): string
{
    security_headers('html');
    $accent = TB_BRAND_ACCENT;
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . htmlEscape($title) . ' - TinyBlog Admin</title>' . app_icon_head_tags() . '<style>' . css_base($accent) . css_admin() . '</style></head><body class="admin-body">';
}

function admin_common_script(): string
{
    return <<<'HTML'
<script>
(function () {
  function copy(text, button) {
    var done = function () {
      var label = button.querySelector("[data-label]") || button;
      var prev = label.textContent;
      label.textContent = "Copied";
      setTimeout(function () { label.textContent = prev; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {});
      return;
    }
    var helper = document.createElement("textarea");
    helper.value = text;
    helper.setAttribute("readonly", "");
    helper.style.position = "fixed";
    helper.style.left = "-9999px";
    document.body.appendChild(helper);
    helper.select();
    document.execCommand("copy");
    document.body.removeChild(helper);
    done();
  }
  document.addEventListener("click", function (event) {
    var button = event.target.closest("[data-copy-text],[data-copy-target]");
    if (!button) return;
    var text = button.getAttribute("data-copy-text");
    if (text === null) {
      var target = document.querySelector(button.getAttribute("data-copy-target"));
      text = target ? (target.value !== undefined && target.tagName === "TEXTAREA" ? target.value : target.textContent) : "";
    }
    copy(text || "", button);
  });
  document.querySelectorAll("form[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (!window.confirm(form.getAttribute("data-confirm"))) event.preventDefault();
    });
  });
})();
</script>
HTML;
}

// ----- Home ---------------------------------------------------------------

function dashboard_stats(PDO $pdo, string $site): array
{
    $now = now_utc();
    $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $stats = [];

    $published = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE site = :site AND status = 'published' AND (publish_at IS NULL OR publish_at <= :now)");
    $published->execute([':site' => $site, ':now' => $now]);
    $stats['published'] = (int) $published->fetchColumn();

    $scheduled = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE site = :site AND status = 'published' AND publish_at > :now");
    $scheduled->execute([':site' => $site, ':now' => $now]);
    $stats['scheduled'] = (int) $scheduled->fetchColumn();

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
    $time = strtotime((string) $datetime . (preg_match('/Z|[+-]\d{2}:?\d{2}$/', (string) $datetime) ? '' : ' UTC'));
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
        $publishAt = strtotime(post_publish_at($post) . ' UTC');
        if ($publishAt !== false && $publishAt > time()) {
            return 'scheduled';
        }
        return 'published';
    }
    return 'draft';
}

function status_badge(string $status): string
{
    $status = in_array($status, ['published', 'scheduled', 'draft'], true) ? $status : 'draft';
    return '<span class="status-badge ' . $status . '">' . $status . '</span>';
}

function setup_steps(PDO $pdo, string $site): array
{
    $stats = dashboard_stats($pdo, $site);
    $origins = parse_allowed_origins(setting($pdo, 'allowed_origins', ''));
    $seen = $pdo->prepare('SELECT origin, last_seen_at FROM embed_seen WHERE site = :site AND allowed = 1 ORDER BY datetime(last_seen_at) DESC LIMIT 1');
    $seen->execute([':site' => $site]);
    $seenRow = $seen->fetch();
    $title = setting($pdo, 'blog_title', 'TinyBlog Widget');
    $named = $title !== '' && $title !== 'TinyBlog Widget';
    $mailDone = setting($pdo, 'subscribe_mail_enabled', '0') === '1' || setting($pdo, 'mail_step_skipped', '0') === '1';
    $embedText = 'Allow your website, pick a layout, and paste one snippet. About 3 minutes.';
    if ($origins && !$seenRow) {
        $embedText = 'Your site is allowed. Paste the snippet on a page and open it to finish.';
    } elseif ($seenRow) {
        $embedText = 'Seen on ' . host_label((string) $seenRow['origin']) . ' ' . relative_time((string) $seenRow['last_seen_at']) . '.';
    }
    return [
        ['done' => true, 'title' => 'Create your admin account', 'text' => 'Done during install.', 'href' => '', 'label' => ''],
        ['done' => $named, 'title' => 'Name your blog', 'text' => $named ? '“' . $title . '” is shown on your blog and in feeds.' : 'Give the blog a name readers will recognise.', 'href' => admin_url('settings'), 'label' => $named ? 'Edit' : 'Name it'],
        ['done' => $stats['published'] > 0, 'title' => 'Publish your first post', 'text' => $stats['published'] > 0 ? $stats['published'] . ' published so far.' : 'A short update is enough to start.', 'href' => admin_url('edit'), 'label' => $stats['published'] > 0 ? 'New post' : 'Write a post'],
        ['done' => $origins && $seenRow, 'title' => 'Add TinyBlog to your site', 'text' => $embedText, 'href' => admin_url('embed'), 'label' => 'Add to site'],
        ['done' => $mailDone, 'title' => 'Turn on subscriber emails', 'text' => 'Optional. Send double opt-in confirmations from your server with PHP mail().', 'href' => admin_url('settings', ['tab' => 'email']), 'label' => 'Set up', 'skip' => true],
    ];
}

function render_dashboard_admin(PDO $pdo, array $user): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $stats = dashboard_stats($pdo, $site);
    $base = setting($pdo, 'canonical_base', base_url());
    $sub = 'Your blog is live at <a href="' . htmlEscape(url_for('/')) . '">' . htmlEscape(host_label($base)) . '</a>';
    echo admin_page_head('Home', $sub, '<a class="button secondary" href="' . htmlEscape(url_for('/')) . '">View site</a><a class="button" href="' . htmlEscape(admin_url('edit')) . '">' . admin_icon('plus', 16) . 'New post</a>');

    $steps = setup_steps($pdo, $site);
    $done = count(array_filter($steps, fn (array $step): bool => (bool) $step['done']));
    $showChecklist = $isAdmin && setting($pdo, 'setup_dismissed', '0') !== '1' && $done < count($steps);

    echo '<div class="home-grid"><div class="home-main">';
    if ($showChecklist) {
        $nextIndex = null;
        foreach ($steps as $i => $step) {
            if (!$step['done']) {
                $nextIndex = $i;
                break;
            }
        }
        echo '<section class="card checklist" aria-labelledby="setup-title"><div class="card-head"><h2 id="setup-title">Finish setting up</h2><span class="mono muted">' . $done . ' of ' . count($steps) . '</span></div>';
        echo '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="' . count($steps) . '" aria-valuenow="' . $done . '"><span style="width:' . (int) round($done / count($steps) * 100) . '%"></span></div><ol class="steps">';
        foreach ($steps as $i => $step) {
            $state = $step['done'] ? 'done' : ($i === $nextIndex ? 'next' : 'todo');
            echo '<li class="step ' . $state . '"><span class="step-dot" aria-hidden="true">' . ($step['done'] ? admin_icon('check', 14) : '') . '</span><div class="step-text"><strong>' . htmlEscape($step['title']) . '</strong><span>' . htmlEscape($step['text']) . '</span></div>';
            if ($step['href'] !== '' && $step['label'] !== '') {
                echo '<a class="button ' . ($state === 'next' ? 'accent' : 'ghost') . ' small" href="' . htmlEscape($step['href']) . '">' . htmlEscape($step['label']) . '</a>';
            }
            if (!empty($step['skip']) && !$step['done']) {
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="skip_mail_step"><button class="link-button muted" type="submit">Skip</button></form>';
            }
            echo '</li>';
        }
        echo '</ol><div class="card-foot"><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="dismiss_setup"><button class="link-button muted" type="submit">Hide this checklist</button></form></div></section>';
    }

    $countAll = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE site = :site');
    $countAll->execute([':site' => $site]);
    if ((int) $countAll->fetchColumn() === 0) {
        echo '<section class="card empty-state"><h2>Write your first post</h2><p class="muted">Start with a short update, or load three sample posts to explore the admin and the widget.</p><div class="toolbar"><a class="button" href="' . htmlEscape(admin_url('edit')) . '">Write your first post</a>';
        if ($isAdmin) {
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="seed_samples"><button class="secondary">Load sample posts</button></form>';
        }
        echo '</div></section>';
    } else {
        $now = now_utc();
        $upcoming = $pdo->prepare("SELECT * FROM posts WHERE site = :site AND (status = 'draft' OR publish_at > :now) ORDER BY CASE WHEN status = 'published' THEN 0 ELSE 1 END, datetime(updated_at) DESC LIMIT 6");
        $upcoming->execute([':site' => $site, ':now' => $now]);
        $rows = $upcoming->fetchAll();
        echo '<section class="card"><div class="card-head"><h2>Drafts &amp; scheduled</h2><a class="text-link" href="' . htmlEscape(admin_url('posts')) . '">All posts</a></div>';
        if (!$rows) {
            echo '<p class="muted">Nothing waiting. Everything you have written is live.</p>';
        } else {
            echo '<ul class="mini-list">';
            foreach ($rows as $post) {
                $status = dashboard_status_label($post);
                $when = $status === 'scheduled' ? 'Goes live ' . format_local_time($pdo, post_publish_at($post)) : 'Edited ' . relative_time((string) $post['updated_at']);
                echo '<li>' . status_badge($status) . '<a class="grow" href="' . htmlEscape(admin_url('edit', ['id' => (int) $post['id']])) . '">' . htmlEscape($post['title']) . '</a><span class="muted small">' . htmlEscape($when) . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }
    echo '</div><div class="home-side">';
    echo '<section class="card"><p class="eyebrow">At a glance</p><div class="stat-strip" aria-label="Dashboard stats">';
    foreach ([
        'views_30d' => 'Post views, 30 days',
        'subscribers' => 'Confirmed subscribers',
        'published' => 'Published',
        'scheduled' => 'Scheduled',
    ] as $key => $label) {
        echo '<div class="stat-cell"><p class="stat-value">' . number_format((int) ($stats[$key] ?? 0)) . '</p><p class="stat-label">' . htmlEscape($label) . '</p></div>';
    }
    echo '</div></section>';
    $top = $pdo->prepare('SELECT id, title, slug, view_count FROM posts WHERE site = :site AND view_count > 0 ORDER BY view_count DESC, datetime(updated_at) DESC LIMIT 5');
    $top->execute([':site' => $site]);
    $topRows = $top->fetchAll();
    echo '<section class="card"><h2 class="card-title">Top posts</h2>';
    if (!$topRows) {
        echo '<p class="muted small">Views appear here once readers open your posts.</p>';
    } else {
        $max = max(1, (int) $topRows[0]['view_count']);
        echo '<ul class="bars">';
        foreach ($topRows as $post) {
            echo '<li><div class="bar-label"><a href="' . htmlEscape(url_for('/post/' . rawurlencode((string) $post['slug']))) . '">' . htmlEscape($post['title']) . '</a><span class="mono muted">' . number_format((int) $post['view_count']) . '</span></div><span class="bar"><span style="width:' . max(3, (int) round((int) $post['view_count'] / $max * 100)) . '%"></span></span></li>';
        }
        echo '</ul>';
    }
    echo '<p class="muted small">Counted on your server: one view per visitor per day, no cookies, no raw IPs stored.</p></section>';
    echo '</div></div>';
}

// ----- Posts list -----------------------------------------------------------

function admin_post_filter(): string
{
    $status = (string) ($_GET['status'] ?? 'all');
    return in_array($status, ['all', 'published', 'scheduled', 'draft'], true) ? $status : 'all';
}

function admin_post_filter_sql(string $status): string
{
    return match ($status) {
        'published' => " AND status = 'published' AND (publish_at IS NULL OR publish_at <= :now)",
        'scheduled' => " AND status = 'published' AND publish_at > :now",
        'draft' => " AND status = 'draft'",
        default => '',
    };
}

function render_posts_admin(PDO $pdo, array $user): void
{
    unset($user);
    $site = setting($pdo, 'site_key', 'store-1');
    $status = admin_post_filter();
    $q = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;
    $now = now_utc();
    echo admin_page_head('Posts', 'Write, schedule, and manage everything on your blog.', '<a class="button" href="' . htmlEscape(admin_url('edit')) . '">' . admin_icon('plus', 16) . 'New post</a>');

    $counts = [];
    foreach (['all', 'published', 'scheduled', 'draft'] as $key) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE site = :site' . admin_post_filter_sql($key));
        $params = [':site' => $site];
        if (in_array($key, ['published', 'scheduled'], true)) {
            $params[':now'] = $now;
        }
        $stmt->execute($params);
        $counts[$key] = (int) $stmt->fetchColumn();
    }
    echo '<div class="list-tools"><nav class="tabs" aria-label="Filter posts">';
    foreach (['all' => 'All', 'published' => 'Published', 'scheduled' => 'Scheduled', 'draft' => 'Drafts'] as $key => $label) {
        $params = $key === 'all' ? [] : ['status' => $key];
        if ($q !== '') {
            $params['q'] = $q;
        }
        echo '<a class="tab" href="' . htmlEscape(admin_url('posts', $params)) . '"' . ($status === $key ? ' aria-current="page"' : '') . '>' . htmlEscape($label) . ' <span class="count">' . $counts[$key] . '</span></a>';
    }
    echo '</nav><form class="search" method="get" action="' . htmlEscape(url_for('/admin')) . '" role="search"><input type="hidden" name="action" value="posts">';
    if ($status !== 'all') {
        echo '<input type="hidden" name="status" value="' . htmlEscape($status) . '">';
    }
    echo '<label class="sr-only" for="post-search">Search posts</label><span class="search-ic">' . admin_icon('search', 16) . '</span><input id="post-search" type="search" name="q" value="' . htmlEscape($q) . '" placeholder="Search titles"></form></div>';

    $sql = 'SELECT * FROM posts WHERE site = :site' . admin_post_filter_sql($status);
    if ($q !== '') {
        $sql .= " AND title LIKE :q ESCAPE '\\'";
    }
    $sql .= ' ORDER BY datetime(updated_at) DESC, id DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':site', $site);
    if (in_array($status, ['published', 'scheduled'], true)) {
        $stmt->bindValue(':now', $now);
    }
    if ($q !== '') {
        $stmt->bindValue(':q', '%' . addcslashes($q, '%_\\') . '%');
    }
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $perPage;
    $rows = array_slice($rows, 0, $perPage);

    if (!$rows) {
        echo '<section class="card empty-state"><h2>' . ($q !== '' ? 'No posts match “' . htmlEscape($q) . '”' : 'No posts here yet') . '</h2><p class="muted">' . ($counts['all'] === 0 ? 'Write your first post to get started.' : 'Try another filter or search.') . '</p><a class="button" href="' . htmlEscape(admin_url('edit')) . '">Write your first post</a></section>';
        return;
    }
    echo '<div class="table-card"><table><caption class="sr-only">Posts</caption><thead><tr><th scope="col">Title</th><th scope="col">Status</th><th scope="col" class="num">Views</th><th scope="col">Updated</th></tr></thead><tbody>';
    foreach ($rows as $post) {
        $rowStatus = dashboard_status_label($post);
        $editUrl = admin_url('edit', ['id' => (int) $post['id']]);
        $viewUrl = url_for('/post/' . rawurlencode((string) $post['slug']));
        $toggleLabel = ($post['status'] ?? '') === 'published' ? 'Unpublish' : 'Publish now';
        echo '<tr><td><a class="row-title" href="' . htmlEscape($editUrl) . '">' . htmlEscape($post['title']) . '</a>';
        if ((int) ($post['pinned'] ?? 0) === 1) {
            echo ' <span class="pin" title="Pinned to the top of the blog">' . admin_icon('pin', 13) . 'Pinned</span>';
        }
        echo '<div class="row-actions"><a href="' . htmlEscape($editUrl) . '">Edit</a>';
        if ($rowStatus === 'published') {
            echo '<a href="' . htmlEscape($viewUrl) . '">View</a>';
        }
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="toggle_post_status"><input type="hidden" name="return" value="posts"><input type="hidden" name="return_status" value="' . htmlEscape($status === 'all' ? '' : $status) . '"><input type="hidden" name="id" value="' . (int) $post['id'] . '"><button class="link-button" type="submit">' . htmlEscape($toggleLabel) . '</button></form></div></td>';
        $when = $rowStatus === 'scheduled' ? 'Goes live ' . format_local_time($pdo, post_publish_at($post), 'M j, H:i') : relative_time((string) $post['updated_at']);
        echo '<td>' . status_badge($rowStatus) . '</td><td class="num">' . number_format((int) $post['view_count']) . '</td><td><time datetime="' . htmlEscape((string) $post['updated_at']) . '" title="' . htmlEscape(format_local_time($pdo, (string) $post['updated_at'])) . '">' . htmlEscape($when) . '</time></td></tr>';
    }
    echo '</tbody></table></div>';
    if ($page > 1 || $hasMore) {
        $base = array_filter(['status' => $status === 'all' ? '' : $status, 'q' => $q]);
        echo '<nav class="pager" aria-label="Pagination">';
        if ($page > 1) {
            echo '<a class="button secondary small" href="' . htmlEscape(admin_url('posts', $base + ['page' => $page - 1])) . '">Previous</a>';
        }
        if ($hasMore) {
            echo '<a class="button secondary small" href="' . htmlEscape(admin_url('posts', $base + ['page' => $page + 1])) . '">Next</a>';
        }
        echo '</nav>';
    }
}

// ----- Editor ---------------------------------------------------------------

function utc_to_input(?string $utc): string
{
    $time = strtotime((string) $utc . ' UTC');
    return ($time === false || (string) $utc === '') ? '' : gmdate('Y-m-d\TH:i', $time);
}

function local_input_to_utc(PDO $pdo, string $value, ?string $offsetMinutes): ?string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value)) {
        return null;
    }
    $value = str_replace(' ', 'T', substr($value, 0, 16));
    if ($offsetMinutes !== null && preg_match('/^-?\d{1,4}$/', $offsetMinutes)) {
        $time = strtotime($value . ':00 UTC');
        return $time === false ? null : gmdate('Y-m-d H:i:s', $time + ((int) $offsetMinutes * 60));
    }
    try {
        $date = new DateTimeImmutable($value, site_timezone($pdo));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function unique_post_slug(PDO $pdo, string $site, string $slug, int $ignoreId): string
{
    $candidate = $slug;
    $n = 2;
    $stmt = $pdo->prepare('SELECT id FROM posts WHERE site = :site AND slug = :slug AND id != :id LIMIT 1');
    while (true) {
        $stmt->execute([':site' => $site, ':slug' => $candidate, ':id' => $ignoreId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = substr($slug, 0, 90) . '-' . $n;
        $n++;
    }
}

function render_post_form(PDO $pdo, array $user, ?array $state = null): void
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
        'publish_at' => '',
        'pinned' => 0,
        'updated_at' => '',
    ];
    $id = (int) ($state['id'] ?? ($_GET['id'] ?? 0));
    if ($id > 0) {
        if (!can_manage_post($pdo, $user, $id)) {
            http_response_code(403);
            echo '<h1>Forbidden</h1><p class="muted">You cannot edit that post.</p>';
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id AND site = :site LIMIT 1');
        $stmt->execute([':id' => $id, ':site' => setting($pdo, 'site_key', 'store-1')]);
        $found = $stmt->fetch();
        if ($found) {
            $post = array_merge($post, $found);
        }
    }
    if ($state) {
        $post = array_merge($post, $state);
    }
    $isNew = empty($post['id']);
    $current = $isNew ? 'draft' : dashboard_status_label($post);
    $mode = (string) ($state['publish_mode'] ?? ($current === 'scheduled' ? 'schedule' : ($current === 'published' ? 'publish' : 'draft')));
    $publishInput = ($current === 'scheduled' || isset($state['publish_at'])) ? utc_to_input($post['publish_at'] ?: null) : '';
    $base = rtrim(setting($pdo, 'canonical_base', base_url()), '/');
    $postUrl = $base . '/post/';
    $statusLine = match ($current) {
        'published' => 'Live since ' . format_local_time($pdo, post_publish_at($post)),
        'scheduled' => 'Goes live ' . format_local_time($pdo, post_publish_at($post)),
        default => $isNew ? 'Not saved yet' : 'Draft · edited ' . relative_time((string) $post['updated_at']),
    };
    $back = '<a class="back-link" href="' . htmlEscape(admin_url('posts')) . '">' . admin_icon('arrow-left', 14) . 'Posts</a>';
    $actions = '';
    if (!$isNew && $current === 'published') {
        $actions .= '<a class="button ghost" href="' . htmlEscape(url_for('/post/' . rawurlencode((string) $post['slug']))) . '">' . admin_icon('eye', 16) . 'View</a>';
    }
    $actions .= '<button class="button secondary" type="submit" form="postForm" name="intent" value="draft">' . ($current === 'draft' ? 'Save draft' : 'Switch to draft') . '</button>';
    $actions .= '<button class="button accent" type="submit" form="postForm" name="intent" value="primary" id="primaryAction" data-new="' . ($isNew || $current === 'draft' ? '1' : '0') . '">' . htmlEscape($mode === 'schedule' ? 'Schedule' : ($mode === 'publish' ? ($current === 'published' ? 'Update' : 'Publish') : 'Save draft')) . '</button>';
    echo admin_page_head($isNew ? 'New post' : 'Edit post', status_badge($current) . ' <span class="muted">' . htmlEscape($statusLine) . '</span>', $actions, $back);

    echo '<div class="restore-banner" id="restoreBanner" hidden role="status"><span>' . admin_icon('alert', 16) . '<span id="restoreText">You have unsaved changes from an earlier session.</span></span><span class="restore-actions"><button type="button" class="button small" id="restoreYes">Restore</button><button type="button" class="link-button" id="restoreNo">Discard</button></span></div>';
    echo '<form method="post" id="postForm" class="editor-form">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_post"><input type="hidden" name="id" value="' . htmlEscape((string) $post['id']) . '"><input type="hidden" name="tz_offset" id="tzOffset" value="0">';
    echo '<div class="editor-grid"><section class="editor-main">';
    echo '<label class="sr-only" for="title">Title</label><input class="title-input" name="title" id="title" value="' . htmlEscape($post['title']) . '" placeholder="Post title" required autocomplete="off">';
    echo '<div class="slug-row"><span class="mono muted">' . htmlEscape(host_label($postUrl)) . '/</span><label class="sr-only" for="slug">URL slug</label><input class="slug-input" name="slug" id="slug" value="' . htmlEscape($post['slug']) . '" placeholder="generated-from-title"></div>';
    echo '<div class="editor-toolbar"><div class="markdown-toolbar" aria-label="Markdown toolbar">';
    foreach ([
        ['**', '**', 'Bold', '<strong>B</strong>'],
        ['*', '*', 'Italic', '<em>I</em>'],
        ["\n## ", '', 'Heading', 'H2'],
        ['[', '](https://)', 'Link', 'Link'],
        ["\n> ", '', 'Quote', 'Quote'],
        ["\n- ", '', 'List', 'List'],
        ['`', '`', 'Code', 'Code'],
    ] as [$prefix, $suffix, $title, $label]) {
        echo '<button type="button" class="tool" data-wrap-prefix="' . htmlEscape($prefix) . '" data-wrap-suffix="' . htmlEscape($suffix) . '" title="' . htmlEscape($title) . '">' . $label . '</button>';
    }
    echo '<button type="button" class="tool tool-media" data-open-media="body">' . admin_icon('image', 15) . 'Image from media</button></div>';
    echo '<div class="segmented" role="group" aria-label="Editor view"><button type="button" data-view="write" aria-pressed="true">Write</button><button type="button" data-view="preview" aria-pressed="false" id="previewToggle">Preview</button><button type="button" data-view="split" aria-pressed="false" class="split-only">Split</button></div></div>';
    echo '<div class="write-area" data-view-mode="write"><label class="sr-only" for="body_markdown">Markdown body</label><textarea name="body_markdown" id="body_markdown" required placeholder="Write in Markdown. **bold**, *italic*, ## headings, - lists, > quotes, ```code```">' . htmlEscape($post['body_markdown']) . '</textarea>';
    echo '<div class="editor-preview content" id="preview" aria-live="polite" aria-busy="false"></div></div>';
    echo '<p class="editor-status mono muted"><span id="wordCount"></span><span>Markdown · Ctrl/Cmd + S saves</span></p>';
    echo '</section><aside class="editor-side" aria-label="Post settings"><h2 class="rail-title">Post settings</h2>';

    echo '<section class="rail-section"><h3>Publishing</h3><div class="segmented wide" role="radiogroup" aria-label="Publishing">';
    foreach (['draft' => 'Draft', 'publish' => $current === 'published' ? 'Published' : 'Publish now', 'schedule' => 'Schedule'] as $key => $label) {
        echo '<label class="seg-option"><input type="radio" name="publish_mode" value="' . $key . '"' . ($mode === $key ? ' checked' : '') . '><span>' . htmlEscape($label) . '</span></label>';
    }
    echo '</div><div class="schedule-fields" id="scheduleFields"' . ($mode === 'schedule' ? '' : ' hidden') . '><label>Goes live at<input type="datetime-local" name="publish_local" id="publishLocal" value="' . htmlEscape($publishInput) . '" data-utc="' . htmlEscape($publishInput) . '"></label><p class="hint" id="tzHint">Times are UTC until your browser converts them.</p></div>';
    echo '<p class="hint">Scheduled posts appear on the blog, feeds, and widget at that moment. No cron needed.</p></section>';

    $hero = (string) $post['hero_image_url'];
    echo '<section class="rail-section"><h3>Featured image</h3><div class="hero-pick" id="heroPick">' . ($hero !== '' ? '<img src="' . htmlEscape($hero) . '" alt="" loading="lazy">' : '<span class="hero-empty">' . admin_icon('image', 22) . '<span>No image</span></span>') . '</div>';
    echo '<div class="row-buttons"><button type="button" class="button secondary small" data-open-media="hero">Choose from media</button><button type="button" class="link-button muted" id="heroRemove"' . ($hero === '' ? ' hidden' : '') . '>Remove</button></div>';
    echo '<details class="url-details"><summary>Use an image URL</summary><label class="sr-only" for="heroUrl">Featured image URL</label><input name="hero_image_url" id="heroUrl" value="' . htmlEscape($hero) . '" placeholder="https://… or /uploads/…"></details></section>';

    echo '<section class="rail-section"><h3><label for="excerpt">Excerpt</label></h3><textarea name="excerpt" id="excerpt" rows="3" maxlength="300" placeholder="Leave empty to use the first lines of the post.">' . htmlEscape($post['excerpt']) . '</textarea><p class="hint counter"><span id="excerptCount">0</span> / 160 for search results</p></section>';
    echo '<section class="rail-section"><h3><label for="tags">Tags</label></h3><input name="tags" id="tags" value="' . htmlEscape($post['tags']) . '" placeholder="updates, product, notes"><p class="hint">Comma-separated. Related posts share tags.</p></section>';
    echo '<section class="rail-section"><h3>Search &amp; social preview</h3><div class="serp"><span class="serp-url" id="serpUrl">' . htmlEscape(host_label($base)) . ' › post</span><span class="serp-title" id="serpTitle"></span><span class="serp-desc" id="serpDesc"></span></div></section>';
    echo '<section class="rail-section"><h3>Options</h3><label class="check"><input type="checkbox" name="pinned" value="1" ' . ((int) ($post['pinned'] ?? 0) === 1 ? 'checked' : '') . '> Pin to the top of the blog</label></section>';
    if (!$isNew) {
        echo '<section class="rail-section danger"><button class="link-button danger-link" type="submit" form="deleteForm">' . admin_icon('x', 14) . 'Delete post</button></section>';
    }
    echo '</aside></div></form>';
    if (!$isNew) {
        echo '<form method="post" id="deleteForm" data-confirm="Delete this post permanently? This cannot be undone.">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_post"><input type="hidden" name="id" value="' . (int) $post['id'] . '"></form>';
    }
    render_media_picker($pdo);
    $config = [
        'key' => 'tinyblog-draft-' . ($post['id'] ?: 'new'),
        'csrf' => csrf_token(),
        'previewUrl' => url_for('/admin'),
        'blogTitle' => setting($pdo, 'blog_title', 'TinyBlog Widget'),
        'current' => $current,
    ];
    echo '<script>window.TB_EDITOR = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    echo editor_script();
}

function render_media_picker(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT url, alt_text, original_name FROM media ORDER BY datetime(created_at) DESC LIMIT 60');
    $rows = $stmt->fetchAll();
    echo '<dialog class="media-picker" id="mediaPicker" aria-labelledby="pickerTitle"><div class="picker-head"><h2 id="pickerTitle">Choose an image</h2><button type="button" class="link-button" data-close-picker aria-label="Close">' . admin_icon('x', 18) . '</button></div>';
    if (!$rows) {
        echo '<p class="muted">No images yet. <a href="' . htmlEscape(admin_url('media')) . '" target="_blank" rel="noopener">Upload one in Media</a>, then reopen this picker.</p>';
    } else {
        echo '<div class="picker-grid">';
        foreach ($rows as $media) {
            $alt = trim((string) ($media['alt_text'] ?? ''));
            echo '<button type="button" class="picker-item" data-url="' . htmlEscape((string) $media['url']) . '" data-alt="' . htmlEscape($alt) . '"><img src="' . htmlEscape((string) $media['url']) . '" alt="" loading="lazy"><span>' . htmlEscape($alt !== '' ? $alt : (string) $media['original_name']) . '</span></button>';
        }
        echo '</div><p class="muted small">Need another image? <a href="' . htmlEscape(admin_url('media')) . '" target="_blank" rel="noopener">Upload in Media</a>.</p>';
    }
    echo '</dialog>';
}

function editor_script(): string
{
    return <<<'HTML'
<script>
(function () {
  var cfg = window.TB_EDITOR || {};
  var postForm = document.getElementById("postForm");
  if (!postForm) return;
  var title = document.getElementById("title");
  var slug = document.getElementById("slug");
  var body = document.getElementById("body_markdown");
  var excerpt = document.getElementById("excerpt");
  var preview = document.getElementById("preview");
  var area = document.querySelector(".write-area");
  var primary = document.getElementById("primaryAction");
  var key = cfg.key;
  var store = { get: function () { try { return JSON.parse(localStorage.getItem(key) || "null"); } catch (e) { return null; } }, set: function (v) { try { localStorage.setItem(key, JSON.stringify(v)); } catch (e) {} }, clear: function () { try { localStorage.removeItem(key); } catch (e) {} } };

  // Local time for scheduling: show UTC as browser-local, submit the offset.
  var tz = document.getElementById("tzOffset");
  var local = document.getElementById("publishLocal");
  var hint = document.getElementById("tzHint");
  var offset = new Date().getTimezoneOffset();
  tz.value = String(offset);
  if (local && local.dataset.utc) {
    var d = new Date(local.dataset.utc + ":00Z");
    if (!isNaN(d)) {
      var pad = function (n) { return String(n).padStart(2, "0"); };
      local.value = d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + "T" + pad(d.getHours()) + ":" + pad(d.getMinutes());
    }
  }
  try { if (hint) hint.textContent = "Your time zone: " + Intl.DateTimeFormat().resolvedOptions().timeZone + "."; } catch (e) {}

  // Publishing mode drives the primary button label.
  var scheduleFields = document.getElementById("scheduleFields");
  function syncMode() {
    var mode = (postForm.querySelector('input[name="publish_mode"]:checked') || {}).value || "draft";
    scheduleFields.hidden = mode !== "schedule";
    if (mode === "schedule" && local && !local.value) {
      var t = new Date(Date.now() + 3600000);
      t.setMinutes(0);
      var p = function (n) { return String(n).padStart(2, "0"); };
      local.value = t.getFullYear() + "-" + p(t.getMonth() + 1) + "-" + p(t.getDate()) + "T" + p(t.getHours()) + ":00";
    }
    primary.textContent = mode === "schedule" ? "Schedule" : mode === "publish" ? (cfg.current === "published" ? "Update" : "Publish") : "Save draft";
  }
  postForm.querySelectorAll('input[name="publish_mode"]').forEach(function (r) { r.addEventListener("change", syncMode); });
  syncMode();

  // Slug follows the title until edited.
  if (!slug.value) slug.dataset.auto = "1";
  title.addEventListener("input", function () { if (slug.dataset.auto) slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); updateSerp(); });
  slug.addEventListener("input", function () { delete slug.dataset.auto; updateSerp(); });

  // Server-rendered preview: the same sanitizer the blog and widget use.
  var previewTimer = null;
  function renderLivePreview() {
    if (area.dataset.viewMode === "write") return;
    preview.setAttribute("aria-busy", "true");
    var data = new FormData();
    data.append("admin_action", "preview_markdown");
    data.append("csrf_token", cfg.csrf);
    data.append("body_markdown", body.value);
    fetch(cfg.previewUrl, { method: "POST", body: data, credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (json) { preview.innerHTML = "<h1>" + escapeHtml(title.value || "Untitled") + "</h1>" + (json.html || ""); })
      .catch(function () { preview.innerHTML = "<p class=\"muted\">Preview unavailable. Your text is safe; save to see it on the blog.</p>"; })
      .then(function () { preview.setAttribute("aria-busy", "false"); });
  }
  function schedulePreview() { window.clearTimeout(previewTimer); previewTimer = setTimeout(renderLivePreview, 300); }
  function escapeHtml(v) { return String(v).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[c]; }); }
  document.querySelectorAll("[data-view]").forEach(function (button) {
    button.addEventListener("click", function () {
      area.dataset.viewMode = button.dataset.view;
      document.querySelectorAll("[data-view]").forEach(function (b) { b.setAttribute("aria-pressed", b === button ? "true" : "false"); });
      renderLivePreview();
    });
  });

  // Toolbar wraps the selection in Markdown.
  function insertAround(prefix, suffix, fallback) {
    var start = body.selectionStart || 0;
    var end = body.selectionEnd || start;
    var selected = body.value.slice(start, end) || fallback;
    body.value = body.value.slice(0, start) + prefix + selected + suffix + body.value.slice(end);
    var cursor = start + prefix.length + selected.length;
    body.focus();
    body.setSelectionRange(cursor, cursor);
    body.dispatchEvent(new Event("input", { bubbles: true }));
  }
  document.querySelectorAll(".markdown-toolbar [data-wrap-prefix]").forEach(function (button) {
    button.addEventListener("click", function () {
      var prefix = button.dataset.wrapPrefix || "";
      insertAround(prefix, button.dataset.wrapSuffix || "", prefix === "[" ? "link text" : "text");
    });
  });

  // Media picker: insert into the body or set the featured image.
  var picker = document.getElementById("mediaPicker");
  var pickTarget = "body";
  var heroUrl = document.getElementById("heroUrl");
  var heroPick = document.getElementById("heroPick");
  var heroRemove = document.getElementById("heroRemove");
  function setHero(url) {
    heroUrl.value = url;
    heroPick.innerHTML = url ? "<img alt=\"\" src=\"" + escapeHtml(url) + "\">" : "<span class=\"hero-empty\">No image</span>";
    heroRemove.hidden = !url;
  }
  document.querySelectorAll("[data-open-media]").forEach(function (button) {
    button.addEventListener("click", function () { pickTarget = button.dataset.openMedia; if (picker.showModal) picker.showModal(); else picker.setAttribute("open", ""); });
  });
  picker.addEventListener("click", function (event) {
    if (event.target.closest("[data-close-picker]") || event.target === picker) { picker.close ? picker.close() : picker.removeAttribute("open"); return; }
    var item = event.target.closest(".picker-item");
    if (!item) return;
    if (pickTarget === "hero") setHero(item.dataset.url);
    else insertAround("![" + (item.dataset.alt || "image") + "](" + item.dataset.url + ")", "", "");
    picker.close ? picker.close() : picker.removeAttribute("open");
  });
  heroRemove.addEventListener("click", function () { setHero(""); });
  heroUrl.addEventListener("change", function () { setHero(heroUrl.value.trim()); });

  // Excerpt counter and search preview.
  var count = document.getElementById("excerptCount");
  function updateSerp() {
    var t = (title.value || "Untitled") + " · " + (cfg.blogTitle || "");
    document.getElementById("serpTitle").textContent = t;
    var text = excerpt.value.trim() || body.value.replace(/[#>*_`\[\]()!-]/g, " ").replace(/\s+/g, " ").trim().slice(0, 160);
    document.getElementById("serpDesc").textContent = text.length > 160 ? text.slice(0, 157) + "…" : text;
    document.getElementById("serpUrl").textContent = document.getElementById("serpUrl").textContent.replace(/ › post.*$/, "") + " › post › " + (slug.value || "…");
    count.textContent = String(excerpt.value.length);
    count.parentElement.classList.toggle("over", excerpt.value.length > 160);
    var words = body.value.trim() ? body.value.trim().split(/\s+/).length : 0;
    document.getElementById("wordCount").textContent = words + " words · " + Math.max(1, Math.round(words / 220)) + " min read";
  }
  excerpt.addEventListener("input", updateSerp);

  // Unsaved work: kept locally, offered back instead of silently restored.
  function snapshot() { return { title: title.value, body: body.value, excerpt: excerpt.value, at: Date.now() }; }
  var initial = JSON.stringify([title.value, body.value, excerpt.value]);
  var params = new URLSearchParams(location.search);
  if (params.get("saved")) {
    store.clear();
    if (params.get("saved") === "new") { try { localStorage.removeItem("tinyblog-draft-new"); } catch (e) {} }
    params.delete("saved");
    history.replaceState(null, "", location.pathname + "?" + params.toString());
  }
  var saved = store.get();
  if (saved && JSON.stringify([saved.title, saved.body, saved.excerpt]) !== initial && (saved.body || saved.title)) {
    var banner = document.getElementById("restoreBanner");
    var when = new Date(saved.at);
    document.getElementById("restoreText").textContent = "Unsaved changes from " + when.toLocaleString([], { dateStyle: "medium", timeStyle: "short" }) + ".";
    banner.hidden = false;
    document.getElementById("restoreYes").addEventListener("click", function () { title.value = saved.title || ""; body.value = saved.body || ""; excerpt.value = saved.excerpt || ""; banner.hidden = true; updateSerp(); schedulePreview(); });
    document.getElementById("restoreNo").addEventListener("click", function () { store.clear(); banner.hidden = true; });
  }
  [title, body, excerpt].forEach(function (el) { el.addEventListener("input", function () { store.set(snapshot()); if (el === body) { updateSerp(); schedulePreview(); } }); });
  postForm.addEventListener("submit", function () { window.TB_SUBMITTING = true; });

  document.addEventListener("keydown", function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
      event.preventDefault();
      postForm.requestSubmit(primary);
    }
  });
  updateSerp();
})();
</script>
HTML;
}

function save_post(PDO $pdo, array $user): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $site = setting($pdo, 'site_key', 'store-1');
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = (string) ($_POST['body_markdown'] ?? '');
    $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
    $hero = trim((string) ($_POST['hero_image_url'] ?? ''));
    $pinned = isset($_POST['pinned']) ? 1 : 0;
    $tags = tags_to_string((string) ($_POST['tags'] ?? ''));
    $intent = (string) ($_POST['intent'] ?? 'primary');
    $mode = (string) ($_POST['publish_mode'] ?? '');
    if (!in_array($mode, ['draft', 'publish', 'schedule'], true)) {
        // Backward compatible with the old status/publish_at fields.
        $legacyStatus = (string) ($_POST['status'] ?? 'draft');
        $mode = $legacyStatus === 'published' ? 'publish' : 'draft';
    }
    if ($intent === 'draft') {
        $mode = 'draft';
    }
    $offset = isset($_POST['tz_offset']) ? (string) $_POST['tz_offset'] : null;
    $scheduledAt = local_input_to_utc($pdo, (string) ($_POST['publish_local'] ?? ''), $offset);
    if (!isset($_POST['publish_mode']) && isset($_POST['publish_at'])) {
        $scheduledAt = normalize_publish_at((string) $_POST['publish_at'], $mode === 'draft' ? 'draft' : 'published');
        if ($mode === 'publish' && $scheduledAt !== null && strtotime($scheduledAt . ' UTC') > time()) {
            $mode = 'schedule';
        }
    }
    $state = [
        'id' => $id ?: '',
        'title' => $title,
        'slug' => (string) ($_POST['slug'] ?? ''),
        'body_markdown' => $body,
        'excerpt' => $excerpt,
        'hero_image_url' => $hero,
        'tags' => $tags,
        'pinned' => $pinned,
        'publish_mode' => $mode,
    ];
    if ($scheduledAt !== null) {
        $state['publish_at'] = $scheduledAt;
    }
    if ($title === '' || trim($body) === '') {
        return ['ok' => false, 'id' => $id, 'created' => false, 'message' => 'Add a title and some text before saving.', 'post' => $state];
    }
    if ($id > 0 && !can_manage_post($pdo, $user, $id)) {
        http_response_code(403);
        exit('You cannot edit that post.');
    }
    $existing = null;
    if ($id > 0) {
        $find = $pdo->prepare('SELECT * FROM posts WHERE id = :id AND site = :site LIMIT 1');
        $find->execute([':id' => $id, ':site' => $site]);
        $existing = $find->fetch() ?: null;
    }
    $now = now_utc();
    $wasLiveAt = $existing && ($existing['status'] ?? '') === 'published' && strtotime(post_publish_at($existing) . ' UTC') <= time() ? post_publish_at($existing) : null;
    if ($mode === 'schedule') {
        if ($scheduledAt === null) {
            return ['ok' => false, 'id' => $id, 'created' => false, 'message' => 'Pick a date and time to schedule this post.', 'post' => $state];
        }
        if (strtotime($scheduledAt . ' UTC') <= time()) {
            $mode = 'publish';
        }
    }
    $status = $mode === 'draft' ? 'draft' : 'published';
    $publishAt = match ($mode) {
        'schedule' => $scheduledAt,
        'publish' => $wasLiveAt ?? $now,
        default => $existing ? (($existing['publish_at'] ?? null) ?: null) : null,
    };
    $slug = unique_post_slug($pdo, $site, slugify((string) (($_POST['slug'] ?? '') !== '' ? $_POST['slug'] : $title)), $id);
    $contentHtml = sanitize_markdown($body);
    $readingMinutes = reading_minutes_from_markdown($body);
    if ($excerpt === '') {
        $excerpt = excerpt_from_markdown($body);
    }
    $hero = $hero !== '' && safe_url($hero) ? $hero : '';
    $params = [
        ':title' => $title,
        ':slug' => $slug,
        ':body_markdown' => $body,
        ':content_html' => $contentHtml,
        ':excerpt' => $excerpt,
        ':hero_image_url' => $hero,
        ':tags' => $tags,
        ':status' => $status,
        ':published_at' => $publishAt,
        ':publish_at' => $publishAt,
        ':pinned' => $pinned,
        ':reading_minutes' => $readingMinutes,
        ':updated_at' => $now,
        ':site' => $site,
    ];
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE posts SET title = :title, slug = :slug, body_markdown = :body_markdown, content_html = :content_html, excerpt = :excerpt, hero_image_url = :hero_image_url, tags = :tags, status = :status, published_at = :published_at, publish_at = :publish_at, pinned = :pinned, reading_minutes = :reading_minutes, updated_at = :updated_at WHERE id = :id AND site = :site');
        $stmt->execute($params + [':id' => $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO posts (site, title, slug, body_markdown, content_html, excerpt, hero_image_url, tags, status, published_at, publish_at, pinned, reading_minutes, created_at, updated_at)
            VALUES (:site, :title, :slug, :body_markdown, :content_html, :excerpt, :hero_image_url, :tags, :status, :published_at, :publish_at, :pinned, :reading_minutes, :created_at, :updated_at)');
        $stmt->execute($params + [':created_at' => $now]);
        $id = (int) $pdo->lastInsertId();
    }
    $message = match ($mode) {
        'schedule' => 'Scheduled. It goes live ' . format_local_time($pdo, $publishAt) . ' (' . site_timezone($pdo)->getName() . ').',
        'publish' => $wasLiveAt ? 'Changes saved. They are live now.' : 'Published. It is live on your blog, feeds, and widget.',
        default => 'Draft saved. Readers will not see it until you publish.',
    };
    return ['ok' => true, 'id' => $id, 'created' => $existing === null, 'message' => $message, 'post' => $state];
}

// ----- Media ----------------------------------------------------------------

function render_media_admin(PDO $pdo): void
{
    echo admin_page_head('Media', 'Images live on your host and are served from <code>/uploads</code>. Alt text is required so every image is accessible.');
    echo '<form class="upload-dropzone card" id="uploadDropzone" method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="admin_action" value="upload_media"><div class="drop-copy">' . admin_icon('image', 22) . '<div><strong>Drop an image here or choose a file</strong><span class="muted small">PNG, JPG, GIF, or WebP up to ' . human_bytes((int) $GLOBALS['TB_CONFIG']['max_upload_bytes']) . '.</span></div></div><div class="drop-fields"><label>Image<input type="file" name="media" accept="image/jpeg,image/png,image/gif,image/webp" required></label><label>Alt text<input name="alt_text" maxlength="180" required placeholder="Describe the image for people who can’t see it"></label><button>Upload</button></div></form>';
    $stmt = $pdo->prepare('SELECT * FROM media ORDER BY datetime(created_at) DESC LIMIT 60');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo '<section class="card empty-state"><h2>No media yet</h2><p class="muted">Upload an image to use it in posts or as a featured image.</p></section>';
    } else {
        echo '<section class="media-grid" aria-label="Uploaded media">';
        foreach ($rows as $media) {
            $alt = trim((string) ($media['alt_text'] ?? ''));
            $label = $alt !== '' ? $alt : (string) ($media['original_name'] ?? 'image');
            $markdown = '![' . $label . '](' . (string) $media['url'] . ')';
            $imageDimensions = '';
            $dims = '';
            if ((int) ($media['width'] ?? 0) > 0 && (int) ($media['height'] ?? 0) > 0) {
                $imageDimensions = ' width="' . (int) $media['width'] . '" height="' . (int) $media['height'] . '"';
                $dims = (int) $media['width'] . '×' . (int) $media['height'] . ' · ';
            }
            echo '<article class="media-card"><img class="media-thumb" src="' . htmlEscape($media['url']) . '" alt="' . htmlEscape($alt) . '" loading="lazy"' . $imageDimensions . '><div class="media-card-body"><strong class="truncate" title="' . htmlEscape($media['original_name']) . '">' . htmlEscape($media['original_name']) . '</strong><span class="muted small">' . htmlEscape($dims . human_bytes((int) $media['size'])) . '</span>';
            if ($alt === '') {
                echo '<span class="warn-text small">' . admin_icon('alert', 13) . 'No alt text</span>';
            } else {
                echo '<span class="muted small truncate" title="' . htmlEscape($alt) . '">' . htmlEscape($alt) . '</span>';
            }
            echo '<div class="media-card-actions"><button type="button" class="secondary small" data-markdown="' . htmlEscape($markdown) . '" data-copy-text="' . htmlEscape($markdown) . '"><span data-label>Copy Markdown</span></button><form method="post" data-confirm="Delete this image? Posts that use it will show a broken image.">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_media"><input type="hidden" name="media_id" value="' . (int) $media['id'] . '"><button class="link-button muted" type="submit">Delete</button></form></div></div></article>';
        }
        echo '</section>';
    }
    echo '<script>
        const dropzone = document.getElementById("uploadDropzone");
        if (dropzone) {
          ["dragenter", "dragover"].forEach((eventName) => dropzone.addEventListener(eventName, (event) => { event.preventDefault(); dropzone.classList.add("is-dragover"); }));
          ["dragleave", "drop"].forEach((eventName) => dropzone.addEventListener(eventName, () => dropzone.classList.remove("is-dragover")));
          dropzone.addEventListener("drop", (event) => { const input = dropzone.querySelector("input[type=file]"); if (event.dataTransfer && event.dataTransfer.files.length && input) { input.files = event.dataTransfer.files; } });
        }
        const copyMarkdown = (button) => button.dataset.markdown || "";
    </script>';
}

// ----- Subscribers ------------------------------------------------------------

function subscriber_filter(): string
{
    $status = (string) ($_GET['status'] ?? 'all');
    return in_array($status, ['all', 'active', 'unconfirmed', 'unsubscribed'], true) ? $status : 'all';
}

function render_subscribers_admin(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $status = subscriber_filter();
    $exportForm = '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="export_subscribers"><button class="secondary" type="submit">' . admin_icon('download', 16) . 'Export CSV</button></form>';
    echo admin_page_head('Subscribers', 'People who asked for updates by email. Stored on your server for opt-in updates only; export carefully and delete on request.', $exportForm);
    $counts = ['all' => 0, 'active' => 0, 'unconfirmed' => 0, 'unsubscribed' => 0];
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM subscribers WHERE site = :site GROUP BY status');
    $stmt->execute([':site' => $site]);
    foreach ($stmt->fetchAll() as $row) {
        $key = (string) $row['status'];
        if (isset($counts[$key])) {
            $counts[$key] = (int) $row['n'];
        }
        $counts['all'] += (int) $row['n'];
    }
    $mailOn = setting($pdo, 'subscribe_mail_enabled', '0') === '1';
    if (!$mailOn) {
        echo '<p class="notice info">' . admin_icon('mail', 16) . '<span>Confirmation emails are off, so new subscribers wait as “unconfirmed”. Copy their confirm link and send it yourself, or <a href="' . htmlEscape(admin_url('settings', ['tab' => 'email'])) . '">turn on emails</a>.</span></p>';
    }
    echo '<div class="list-tools"><nav class="tabs" aria-label="Filter subscribers">';
    foreach (['all' => 'All', 'active' => 'Confirmed', 'unconfirmed' => 'Waiting', 'unsubscribed' => 'Unsubscribed'] as $key => $label) {
        echo '<a class="tab" href="' . htmlEscape(admin_url('subscribers', $key === 'all' ? [] : ['status' => $key])) . '"' . ($status === $key ? ' aria-current="page"' : '') . '>' . htmlEscape($label) . ' <span class="count">' . $counts[$key] . '</span></a>';
    }
    echo '</nav></div>';
    $sql = 'SELECT id, email, status, consent_at, confirmed_at, confirm_token FROM subscribers WHERE site = :site' . ($status !== 'all' ? ' AND status = :status' : '') . ' ORDER BY datetime(consent_at) DESC LIMIT 300';
    $list = $pdo->prepare($sql);
    $list->execute($status !== 'all' ? [':site' => $site, ':status' => $status] : [':site' => $site]);
    $rows = $list->fetchAll();
    if (!$rows) {
        echo '<section class="card empty-state"><h2>No subscribers yet</h2><p class="muted">Add a subscribe box with <a href="' . htmlEscape(admin_url('embed', ['type' => 'subscribe'])) . '">Add to site</a> and people can sign up from your website.</p></section>';
        return;
    }
    echo '<div class="table-card"><table><caption class="sr-only">Subscriber list</caption><thead><tr><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Signed up</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $label = match ((string) $row['status']) {
            'active' => '<span class="status-badge published">confirmed</span>',
            'unsubscribed' => '<span class="status-badge draft">unsubscribed</span>',
            default => '<span class="status-badge scheduled">waiting</span>',
        };
        echo '<tr><td>' . htmlEscape($row['email']) . '</td><td>' . $label . '</td><td><time title="' . htmlEscape(format_local_time($pdo, (string) $row['consent_at'])) . '">' . htmlEscape(relative_time((string) $row['consent_at'])) . '</time></td><td class="row-actions end">';
        if (!empty($row['confirm_token'])) {
            echo '<button type="button" class="link-button" data-copy-text="' . htmlEscape(canonical_url($pdo, '/subscribe/confirm/' . rawurlencode((string) $row['confirm_token']))) . '">' . admin_icon('copy', 13) . '<span data-label>Copy confirm link</span></button>';
        }
        echo '<form method="post" data-confirm="Delete ' . htmlEscape($row['email']) . ' from your list?">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_subscriber"><input type="hidden" name="subscriber_id" value="' . (int) $row['id'] . '"><input type="hidden" name="return_status" value="' . htmlEscape($status === 'all' ? '' : $status) . '"><button class="link-button muted" type="submit">Delete</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}

function export_subscribers_csv(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $stmt = $pdo->prepare("SELECT email, consent_at, confirmed_at FROM subscribers WHERE site = :site AND status = 'active' ORDER BY datetime(confirmed_at) DESC");
    $stmt->execute([':site' => $site]);
    security_headers('json');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tinyblog-subscribers-' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'consent_at_utc', 'confirmed_at_utc']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = (string) $row['email'];
        if (preg_match('/^[=+\-@]/', $email)) {
            $email = "'" . $email;
        }
        fputcsv($out, [$email, (string) $row['consent_at'], (string) $row['confirmed_at']]);
    }
    fclose($out);
    exit;
}

function delete_subscriber(PDO $pdo): string
{
    $id = (int) ($_POST['subscriber_id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM subscribers WHERE id = :id AND site = :site');
    $stmt->execute([':id' => $id, ':site' => setting($pdo, 'site_key', 'store-1')]);
    return $stmt->rowCount() > 0 ? 'Subscriber deleted.' : 'Subscriber not found.';
}

// ----- Add to site (embed builder) ------------------------------------------

function record_embed_seen(PDO $pdo, string $origin, bool $allowed): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $now = now_utc();
    try {
        $find = $pdo->prepare('SELECT id, last_seen_at, allowed FROM embed_seen WHERE site = :site AND origin = :origin LIMIT 1');
        $find->execute([':site' => $site, ':origin' => $origin]);
        $row = $find->fetch();
        if ($row) {
            $stale = (strtotime((string) $row['last_seen_at'] . ' UTC') ?: 0) < time() - 600;
            if ($stale || (int) $row['allowed'] !== ($allowed ? 1 : 0)) {
                $update = $pdo->prepare('UPDATE embed_seen SET last_seen_at = :now, allowed = :allowed, hits = hits + 1 WHERE id = :id');
                $update->execute([':now' => $now, ':allowed' => $allowed ? 1 : 0, ':id' => (int) $row['id']]);
            }
            return;
        }
        if (!$allowed) {
            $blocked = $pdo->prepare('SELECT COUNT(*) FROM embed_seen WHERE site = :site AND allowed = 0');
            $blocked->execute([':site' => $site]);
            if ((int) $blocked->fetchColumn() >= 20) {
                return;
            }
        }
        $insert = $pdo->prepare('INSERT OR IGNORE INTO embed_seen (site, origin, allowed, hits, first_seen_at, last_seen_at) VALUES (:site, :origin, :allowed, 1, :now, :now)');
        $insert->execute([':site' => $site, ':origin' => $origin, ':allowed' => $allowed ? 1 : 0, ':now' => $now]);
    } catch (Throwable) {
        // Observability must never break the API.
    }
}

function update_allowed_origins(PDO $pdo, string $op, string $input): string
{
    $origin = origin_from_url(trim($input));
    if ($origin === '' && preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?$#i', trim($input))) {
        $origin = origin_from_url('https://' . trim($input));
    }
    if ($origin === '') {
        return 'Enter a full website address, like https://your-store.example.';
    }
    $origins = parse_allowed_origins(setting($pdo, 'allowed_origins', ''));
    if ($op === 'remove_origin') {
        $origins = array_values(array_filter($origins, fn (string $o): bool => $o !== $origin));
        set_setting($pdo, 'allowed_origins', implode("\n", $origins));
        return 'OK:' . $origin . ' can no longer load your posts.';
    }
    if (!in_array($origin, $origins, true)) {
        $origins[] = $origin;
    }
    set_setting($pdo, 'allowed_origins', implode("\n", $origins));
    $mark = $pdo->prepare('UPDATE embed_seen SET allowed = 1 WHERE site = :site AND origin = :origin');
    $mark->execute([':site' => setting($pdo, 'site_key', 'store-1'), ':origin' => $origin]);
    return 'OK:' . $origin . ' is allowed. Paste the snippet there and open the page.';
}

function embed_config_from_request(PDO $pdo): array
{
    $type = (string) ($_GET['type'] ?? 'feed');
    $type = in_array($type, ['feed', 'post', 'subscribe', 'badge'], true) ? $type : 'feed';
    $theme = (string) ($_GET['theme'] ?? 'light');
    $theme = in_array($theme, ['light', 'dark', 'card', 'inherit'], true) ? $theme : 'light';
    $accent = (string) ($_GET['accent'] ?? '');
    $config = [
        'site' => setting($pdo, 'site_key', 'store-1'),
        'endpoint' => rtrim(setting($pdo, 'canonical_base', base_url()), '/') . '/api',
        'widgetType' => $type,
    ];
    if ($type === 'feed' || $type === 'badge') {
        $config['maxItems'] = max(1, min(50, (int) ($_GET['maxItems'] ?? 5)));
        if ($type === 'feed') {
            $config['showExcerpt'] = !isset($_GET['type']) || isset($_GET['showExcerpt']);
        }
    }
    if ($type === 'post') {
        $config['slug'] = slugify((string) ($_GET['slug'] ?? ''));
    }
    if ($theme !== 'light') {
        $config['theme'] = $theme;
    }
    if (preg_match('/^#[0-9a-f]{6}$/i', $accent) && strtolower($accent) !== '#15130f') {
        $config['accent'] = strtolower($accent);
    }
    if (setting($pdo, 'require_site_key', '0') === '1') {
        $config['siteKey'] = setting($pdo, 'public_site_key', '');
    }
    return $config;
}

function embed_snippet(PDO $pdo, array $config): string
{
    $base = rtrim(setting($pdo, 'canonical_base', base_url()), '/');
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
    return '<script src="' . $base . '/tinyblog-widget.js"' . "\n  data-tb-config='" . $json . "'></script>\n<noscript><a href=\"" . $base . '/">Read ' . str_replace(['<', '>', '"'], '', setting($pdo, 'blog_title', 'our blog')) . "</a></noscript>";
}

function embed_include_snippet(PDO $pdo, array $config): string
{
    $base = rtrim(setting($pdo, 'canonical_base', base_url()), '/');
    $query = http_build_query(['site' => $config['site'], 'limit' => (int) ($config['maxItems'] ?? 5)]);
    return "<?php\n// Server-side include: posts arrive in the page HTML, no JavaScript needed.\n\$tinyblog = @file_get_contents('" . $base . '/embed/feed.html?' . $query . "');\necho \$tinyblog !== false ? \$tinyblog : '';\n?>";
}

function platform_steps(): array
{
    return [
        'html' => ['Any website', ['Open the page’s HTML where the posts should appear.', 'Paste the snippet, then save and publish the page.']],
        'wordpress' => ['WordPress', ['Edit the page and add a Custom HTML block where the posts should appear.', 'Paste the snippet and click Update.', 'If the block shows nothing, your plan or a security plugin may strip scripts; use a code-snippet plugin instead.']],
        'shopify' => ['Shopify', ['In Shopify admin, open Online Store → Themes → Customize.', 'Open the page template, then Add section → Custom Liquid.', 'Paste the snippet and Save.']],
        'squarespace' => ['Squarespace', ['Edit the page and add a Code block where the posts should appear.', 'Paste the snippet and save.', 'Squarespace runs custom scripts on Core, Plus, and Advanced plans, not Basic.']],
        'webflow' => ['Webflow', ['Drag an Embed element onto the page.', 'Paste the snippet, Save & Close, then publish the site.']],
        'wix' => ['Wix', ['Add → Embed Code → Embed HTML, then choose Code.', 'Paste the snippet and click Update.', 'Wix shows embeds from its own address. If the box stays empty, allow the address listed under “Refused requests” on this page.']],
        'php' => ['PHP include', ['For PHP sites that want posts in the page HTML itself (no JavaScript).', 'Paste this where the posts should appear. It reads the server-rendered /embed/feed.html fragment.']],
    ];
}

function render_embed_admin(PDO $pdo): void
{
    $site = setting($pdo, 'site_key', 'store-1');
    $config = embed_config_from_request($pdo);
    $type = (string) $config['widgetType'];
    $platform = (string) ($_GET['platform'] ?? 'html');
    $steps = platform_steps();
    if (!isset($steps[$platform])) {
        $platform = 'html';
    }
    $origins = parse_allowed_origins(setting($pdo, 'allowed_origins', ''));
    $seenStmt = $pdo->prepare('SELECT origin, allowed, last_seen_at, hits FROM embed_seen WHERE site = :site ORDER BY datetime(last_seen_at) DESC');
    $seenStmt->execute([':site' => $site]);
    $seen = [];
    $blocked = [];
    foreach ($seenStmt->fetchAll() as $row) {
        if ((int) $row['allowed'] === 1 || in_array($row['origin'], $origins, true)) {
            $seen[$row['origin']] = $row;
        } else {
            $blocked[] = $row;
        }
    }

    echo admin_page_head('Add to site', 'Show your posts on any page where you can paste a script tag. Changes here only affect the snippet; your posts stay the same.');
    echo '<div class="embed-grid"><div class="embed-steps">';

    // Step 1: allowed sites.
    echo '<section class="card step-card"><div class="step-head"><span class="step-num">1</span><div><h2>Where will it appear?</h2><p class="muted small">Requests from other websites are refused, so add each site that shows your posts.</p></div></div>';
    if ($origins) {
        echo '<ul class="origin-list">';
        foreach ($origins as $origin) {
            $row = $seen[$origin] ?? null;
            echo '<li><span class="origin">' . admin_icon('globe', 15) . htmlEscape($origin) . '</span>';
            echo $row ? '<span class="pill ok">' . admin_icon('check', 13) . 'Seen ' . htmlEscape(relative_time((string) $row['last_seen_at'])) . '</span>' : '<span class="pill">Not seen yet</span>';
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="remove_origin"><input type="hidden" name="origin" value="' . htmlEscape($origin) . '"><button class="link-button muted" type="submit">Remove</button></form></li>';
        }
        echo '</ul>';
    }
    echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="admin_action" value="add_origin"><label class="grow"><span class="sr-only">Website address</span><input name="origin" type="url" required placeholder="https://your-store.example" inputmode="url"></label><button type="submit">' . admin_icon('plus', 16) . ($origins ? 'Add another site' : 'Allow this site') . '</button></form>';
    if ($blocked) {
        echo '<div class="refused"><p class="small"><strong>Refused requests</strong> <span class="muted">These sites tried to load your posts but are not allowed.</span></p><ul class="origin-list">';
        foreach ($blocked as $row) {
            echo '<li><span class="origin">' . htmlEscape((string) $row['origin']) . '</span><span class="pill warn">refused ' . htmlEscape(relative_time((string) $row['last_seen_at'])) . '</span><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="allow_origin"><input type="hidden" name="origin" value="' . htmlEscape((string) $row['origin']) . '"><button class="button small" type="submit">Allow</button></form></li>';
        }
        echo '</ul></div>';
    }
    echo '</section>';

    // Step 2: layout options (GET form: builds the snippet, changes nothing).
    $posts = $pdo->prepare('SELECT slug, title FROM posts WHERE ' . visible_post_where() . ' ORDER BY datetime(publish_at) DESC LIMIT 50');
    $posts->execute(visible_post_params($site));
    $postOptions = $posts->fetchAll();
    echo '<section class="card step-card"><div class="step-head"><span class="step-num">2</span><div><h2>What should it show?</h2></div></div>';
    echo '<form method="get" action="' . htmlEscape(url_for('/admin')) . '" id="embedForm" class="embed-form"><input type="hidden" name="action" value="embed"><input type="hidden" name="platform" value="' . htmlEscape($platform) . '">';
    echo '<div class="segmented wide" role="radiogroup" aria-label="Widget type">';
    foreach (['feed' => 'Post feed', 'post' => 'Single post', 'subscribe' => 'Subscribe box', 'badge' => 'What’s new badge'] as $key => $label) {
        echo '<label class="seg-option"><input type="radio" name="type" value="' . $key . '"' . ($type === $key ? ' checked' : '') . '><span>' . htmlEscape($label) . '</span></label>';
    }
    echo '</div><div class="field-row">';
    echo '<label data-show-for="feed badge">Posts to show<input type="number" name="maxItems" min="1" max="50" value="' . (int) ($config['maxItems'] ?? 5) . '"></label>';
    echo '<label class="check" data-show-for="feed"><input type="checkbox" name="showExcerpt" value="1"' . (!empty($config['showExcerpt']) ? ' checked' : '') . '> Show excerpts</label>';
    echo '<label data-show-for="post">Post<select name="slug">';
    foreach ($postOptions as $option) {
        echo '<option value="' . htmlEscape((string) $option['slug']) . '"' . (($config['slug'] ?? '') === $option['slug'] ? ' selected' : '') . '>' . htmlEscape((string) $option['title']) . '</option>';
    }
    if (!$postOptions) {
        echo '<option value="">Publish a post first</option>';
    }
    echo '</select></label>';
    $theme = (string) ($config['theme'] ?? 'light');
    echo '<label>Theme<select name="theme">';
    foreach (['light' => 'Light', 'dark' => 'Dark', 'card' => 'Card', 'inherit' => 'Match my site'] as $key => $label) {
        echo '<option value="' . $key . '"' . ($theme === $key ? ' selected' : '') . '>' . htmlEscape($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Accent<input type="color" name="accent" value="' . htmlEscape((string) ($config['accent'] ?? '#15130f')) . '"></label>';
    echo '</div><noscript><button class="secondary" type="submit">Update snippet</button></noscript></form></section>';

    // Step 3: snippet + platform steps.
    $snippet = embed_snippet($pdo, $config);
    $include = embed_include_snippet($pdo, $config);
    echo '<section class="card step-card"><div class="step-head"><span class="step-num">3</span><div><h2>Paste the snippet</h2><p class="muted small">Pick where your site is built for exact steps.</p></div></div>';
    echo '<nav class="chips" aria-label="Platform">';
    foreach ($steps as $key => [$label]) {
        $params = $_GET;
        unset($params['action']);
        $params['platform'] = $key;
        echo '<a class="chip-link" href="' . htmlEscape(admin_url('embed', $params)) . '"' . ($platform === $key ? ' aria-current="page"' : '') . '>' . htmlEscape($label) . '</a>';
    }
    echo '</nav><ol class="howto">';
    foreach ($steps[$platform][1] as $line) {
        echo '<li>' . htmlEscape($line) . '</li>';
    }
    echo '</ol>';
    $code = $platform === 'php' ? $include : $snippet;
    echo '<div class="code-block"><div class="code-bar"><span>' . htmlEscape($platform === 'php' ? 'PHP include' : ($platform === 'shopify' ? 'Custom Liquid' : 'HTML')) . '</span><button type="button" class="button accent small" data-copy-target="#embedSnippet">' . admin_icon('copy', 14) . '<span data-label>Copy snippet</span></button></div><pre><code id="embedSnippet">' . htmlEscape($code) . '</code></pre></div>';
    echo '<p class="hint">The <code>&lt;noscript&gt;</code> link gives crawlers and no-JavaScript readers a path to your full blog.</p></section>';

    // Step 4: verification.
    echo '<section class="card step-card"><div class="step-head"><span class="step-num">4</span><div><h2>Check it’s working</h2></div></div>';
    $live = array_filter($seen, fn (array $row): bool => in_array($row['origin'], $origins, true));
    if ($live) {
        foreach ($live as $row) {
            echo '<p class="notice success">' . admin_icon('check', 16) . '<span>Seen on <strong>' . htmlEscape(host_label((string) $row['origin'])) . '</strong> ' . htmlEscape(relative_time((string) $row['last_seen_at'])) . '.</span></p>';
        }
    } else {
        echo '<p class="notice info">' . admin_icon('alert', 16) . '<span>Not seen yet. Paste the snippet, open that page in a browser, then recheck.</span></p>';
    }
    echo '<a class="button secondary small" href="' . htmlEscape(admin_url('embed', array_diff_key($_GET, ['action' => 1]))) . '">Recheck</a></section>';
    echo '</div>';

    // Live preview using the real widget against this blog.
    $previewConfig = $config;
    $previewConfig['endpoint'] = url_for('/api');
    $previewConfig['container'] = '#embedPreview';
    unset($previewConfig['siteKey']);
    echo '<aside class="embed-preview"><div class="card-head"><span class="eyebrow">Live preview</span><span class="pill">your real posts</span></div><div class="preview-stage" id="previewStage"><div id="embedPreview"></div></div><p class="muted small">Readers only see posts that are published and past their publish time.</p></aside></div>';
    echo '<script src="' . htmlEscape(url_for('/tinyblog-widget.js')) . '"></script>';
    echo '<script>window.TB_EMBED = ' . json_encode(['config' => $previewConfig, 'snippetBase' => rtrim(setting($pdo, 'canonical_base', base_url()), '/'), 'blogTitle' => setting($pdo, 'blog_title', 'our blog'), 'siteKey' => setting($pdo, 'require_site_key', '0') === '1' ? setting($pdo, 'public_site_key', '') : '', 'platform' => $platform], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    echo <<<'HTML'
<script>
(function () {
  var cfg = window.TB_EMBED;
  var form = document.getElementById("embedForm");
  var stage = document.getElementById("embedPreview");
  var snippet = document.getElementById("embedSnippet");
  if (!form || !stage) return;
  function read() {
    var data = new FormData(form);
    var type = data.get("type") || "feed";
    var c = { site: cfg.config.site, endpoint: cfg.snippetBase + "/api", widgetType: type };
    if (type === "feed" || type === "badge") c.maxItems = Math.max(1, Math.min(50, parseInt(data.get("maxItems"), 10) || 5));
    if (type === "feed") c.showExcerpt = data.get("showExcerpt") === "1";
    if (type === "post") c.slug = data.get("slug") || "";
    if (data.get("theme") && data.get("theme") !== "light") c.theme = data.get("theme");
    var accent = (data.get("accent") || "").toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(accent) && accent !== "#15130f") c.accent = accent;
    if (cfg.siteKey) c.siteKey = cfg.siteKey;
    return c;
  }
  function sync() {
    var c = read();
    form.querySelectorAll("[data-show-for]").forEach(function (el) { el.hidden = el.getAttribute("data-show-for").split(" ").indexOf(c.widgetType) < 0; });
    if (cfg.platform !== "php") {
      var json = JSON.stringify(c).replace(/'/g, "\\u0027").replace(/</g, "\\u003c").replace(/>/g, "\\u003e").replace(/&/g, "\\u0026");
      snippet.textContent = '<script src="' + cfg.snippetBase + '/tinyblog-widget.js"\n  data-tb-config=\'' + json + '\'></scr' + 'ipt>\n<noscript><a href="' + cfg.snippetBase + '/">Read ' + cfg.blogTitle.replace(/[<>"]/g, "") + '</a></noscript>';
    }
    var preview = Object.assign({}, c, { endpoint: cfg.config.endpoint, container: "#embedPreview" });
    delete preview.siteKey;
    stage.innerHTML = "";
    document.getElementById("previewStage").className = "preview-stage theme-" + (c.theme || "light");
    if (window.TinyBlogWidget) window.TinyBlogWidget.init(preview);
    var url = new URL(location.href);
    new FormData(form).forEach(function (v, k) { url.searchParams.set(k, v); });
    if (!form.querySelector('[name="showExcerpt"]').checked) url.searchParams.delete("showExcerpt");
    history.replaceState(null, "", url);
    document.querySelectorAll(".chips a").forEach(function (a) {
      var u = new URL(a.href);
      url.searchParams.forEach(function (v, k) { if (k !== "platform") u.searchParams.set(k, v); });
      a.href = u.toString();
    });
  }
  form.addEventListener("input", sync);
  form.addEventListener("change", sync);
  sync();
})();
</script>
HTML;
}

// ----- Settings ---------------------------------------------------------------

function settings_tab(): string
{
    $tab = (string) ($_POST['tab'] ?? ($_GET['tab'] ?? 'general'));
    return in_array($tab, ['general', 'email', 'backup'], true) ? $tab : 'general';
}

function settings_tabs_nav(string $current): string
{
    $tabs = [
        'general' => ['General', admin_url('settings')],
        'email' => ['Email', admin_url('settings', ['tab' => 'email'])],
        'users' => ['Users', admin_url('users')],
        'embed' => ['Embed & sites', admin_url('embed')],
        'backup' => ['Backup & health', admin_url('settings', ['tab' => 'backup'])],
    ];
    $html = '<nav class="tabs settings-tabs" aria-label="Settings sections">';
    foreach ($tabs as $key => [$label, $href]) {
        $html .= '<a class="tab" href="' . htmlEscape($href) . '"' . ($current === $key ? ' aria-current="page"' : '') . '>' . htmlEscape($label) . '</a>';
    }
    return $html . '</nav>';
}

function settings_health_checks(PDO $pdo): array
{
    try {
        $sqliteVersion = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
    } catch (Throwable) {
        $sqliteVersion = 'unknown';
    }
    $dataWritable = is_writable((string) $GLOBALS['TB_CONFIG']['data_dir']);
    $uploadsWritable = is_writable((string) $GLOBALS['TB_CONFIG']['upload_dir']);
    $https = is_https();
    $envPresent = is_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $mailAvailable = function_exists('mail');
    return [
        ['label' => 'PHP version', 'value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.0.0', '>=')],
        ['label' => 'SQLite version', 'value' => $sqliteVersion, 'ok' => $sqliteVersion !== 'unknown'],
        ['label' => 'FTS5 available', 'value' => fts5_available($pdo) ? 'Available' : 'Unavailable', 'ok' => fts5_available($pdo)],
        ['label' => 'data/ writable', 'value' => $dataWritable ? 'Writable' : 'Not writable', 'ok' => $dataWritable],
        ['label' => 'uploads/ writable', 'value' => $uploadsWritable ? 'Writable' : 'Not writable', 'ok' => $uploadsWritable],
        ['label' => 'HTTPS on', 'value' => $https ? 'On' : 'Off', 'ok' => $https],
        ['label' => '.env present', 'value' => $envPresent ? 'Present' : 'Not found', 'ok' => $envPresent],
        ['label' => 'mail() available', 'value' => $mailAvailable ? 'Available' : 'Unavailable', 'ok' => $mailAvailable],
    ];
}

function render_settings_admin(PDO $pdo): void
{
    $tab = settings_tab();
    echo admin_page_head('Settings', 'How your blog looks, sends email, and is backed up.');
    echo settings_tabs_nav($tab);
    if ($tab === 'email') {
        $mailOn = setting($pdo, 'subscribe_mail_enabled', '0') === '1';
        echo '<form method="post" class="card form-card">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_settings"><input type="hidden" name="tab" value="email">';
        echo '<h2 class="card-title">Subscriber emails</h2><label class="check"><input type="checkbox" name="subscribe_mail_enabled" value="1"' . ($mailOn ? ' checked' : '') . '> Send subscription confirmation emails with PHP mail()</label>';
        echo '<p class="hint">Double opt-in: new subscribers get a confirm link and stay “waiting” until they click it. Newsletters are out of scope; export confirmed subscribers to your email tool.</p>';
        echo '<label>From address <span class="muted">(optional)</span><input type="email" name="mail_from" value="' . htmlEscape(setting($pdo, 'mail_from', '')) . '" placeholder="news@your-store.example"><span class="hint">Leave empty to use your host’s default sender.</span></label>';
        echo '<button>Save email settings</button></form>';
        echo '<section class="card form-card"><h2 class="card-title">Test it</h2><p class="muted small">Sends a sample confirmation email to your own address. Shared hosts sometimes block mail(); this tells you before a subscriber finds out.</p><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="send_test_email"><button class="secondary" type="submit">' . admin_icon('mail', 16) . 'Send a test confirmation to me</button></form></section>';
        return;
    }
    if ($tab === 'backup') {
        echo '<section class="card form-card"><h2 class="card-title">Backup</h2><p class="muted small">Export posts, settings, confirmed subscribers, and media references as one JSON file. Import it on a fresh install to move hosts.</p><div class="toolbar"><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="export_data"><button class="secondary">' . admin_icon('download', 16) . 'Export JSON</button></form></div>';
        echo '<form method="post" enctype="multipart/form-data" class="inline-form">' . csrf_field() . '<input type="hidden" name="admin_action" value="import_data"><label class="grow">Import JSON<input type="file" name="backup" accept="application/json,.json" required></label><button class="secondary">Import</button></form></section>';
        echo '<section class="card health-panel"><h2 class="card-title">Hosting health</h2><p class="muted small">Read-only checks for common shared-hosting setup issues.</p><table><caption class="sr-only">Settings health checks</caption><thead><tr><th scope="col">Check</th><th scope="col">Status</th></tr></thead><tbody>';
        foreach (settings_health_checks($pdo) as $check) {
            $class = !empty($check['ok']) ? 'health-ok' : 'health-warn';
            echo '<tr class="' . $class . '"><td><span class="health-dot" aria-hidden="true"></span>' . htmlEscape((string) $check['label']) . '</td><td>' . htmlEscape((string) $check['value']) . '</td></tr>';
        }
        echo '</tbody></table></section>';
        return;
    }
    $accent = setting($pdo, 'accent_color', '#c2410c');
    echo '<form method="post" class="card form-card">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_settings"><input type="hidden" name="tab" value="general">';
    echo '<h2 class="card-title">Your blog</h2>';
    echo '<label>Blog name<input name="blog_title" value="' . htmlEscape(setting($pdo, 'blog_title', '')) . '" required><span class="hint">Shown on the blog, in feeds, and in the admin.</span></label>';
    echo '<label>Home page heading<input name="home_heading" value="' . htmlEscape(setting($pdo, 'home_heading', '')) . '"><span class="hint">Leave empty to use the blog name on the home page.</span></label>';
    echo '<label>Home page intro<textarea name="home_intro" rows="3" placeholder="Optional short intro below the home heading">' . htmlEscape(setting($pdo, 'home_intro', '')) . '</textarea><span class="hint">Leave empty to hide the intro paragraph.</span></label>';
    echo '<label>About page text<textarea name="about_text" rows="4">' . htmlEscape(setting($pdo, 'about_text', '')) . '</textarea></label>';
    echo '<div class="field-row"><label>Posts per page<input type="number" name="posts_per_page" min="1" max="50" value="' . htmlEscape(setting($pdo, 'posts_per_page', '10')) . '"></label>';
    echo '<label>Accent color<span class="color-field"><input type="color" name="accent_color" value="' . htmlEscape($accent) . '"><code>' . htmlEscape($accent) . '</code></span></label>';
    echo '<label>Time zone<input name="timezone" list="tzList" value="' . htmlEscape(setting($pdo, 'timezone', 'UTC')) . '"><span class="hint">Used for schedule times and dates in the admin.</span></label></div><datalist id="tzList">';
    foreach (DateTimeZone::listIdentifiers() as $zone) {
        echo '<option value="' . htmlEscape($zone) . '">';
    }
    echo '</datalist>';
    echo '<label>Blog address<input name="canonical_base" type="url" value="' . htmlEscape(setting($pdo, 'canonical_base', base_url())) . '"><span class="hint">The public URL of this blog (canonical base URL). Used in feeds, the sitemap, and embed snippets.</span></label>';
    echo '<button>Save settings</button></form>';

    $siteKey = setting($pdo, 'site_key', 'store-1');
    echo '<details class="card form-card advanced"><summary><h2 class="card-title">Advanced</h2></summary>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="save_settings"><input type="hidden" name="tab" value="general"><input type="hidden" name="advanced" value="1">';
    echo '<label class="check"><input type="checkbox" name="require_site_key" value="1" ' . (setting($pdo, 'require_site_key', '0') === '1' ? 'checked' : '') . '> Require a public siteKey for API reads</label>';
    echo '<p class="hint">Public siteKey: <code>' . htmlEscape(setting($pdo, 'public_site_key', '')) . '</code>. Add to site includes it in snippets automatically.</p>';
    echo '<label class="check"><input type="checkbox" name="setup_dismissed" value="1" ' . (setting($pdo, 'setup_dismissed', '0') === '1' ? 'checked' : '') . '> Hide the setup checklist on Home</label>';
    echo '<button class="secondary">Save advanced settings</button></form>';
    echo '<form method="post" class="danger-zone" data-confirm="Change the site id? Embeds using the old id will stop loading until you update their snippets.">' . csrf_field() . '<input type="hidden" name="admin_action" value="change_site_key"><h3>Site id</h3><p class="muted small">Current id: <code>' . htmlEscape($siteKey) . '</code>. Every post, subscriber, and embed belongs to a site id. Changing it moves your content to the new id; existing snippets must be updated.</p><div class="inline-form"><label class="grow"><span class="sr-only">New site id</span><input name="new_site_key" pattern="[a-z0-9-]+" placeholder="new-site-id" required></label><button class="secondary" type="submit">Change site id</button></div></form>';
    echo '</details>';
}

function save_settings(PDO $pdo, string $tab = 'general'): string
{
    if ($tab === 'email') {
        $from = strtolower(trim((string) ($_POST['mail_from'] ?? '')));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid From address, or leave it empty.';
        }
        set_setting($pdo, 'subscribe_mail_enabled', isset($_POST['subscribe_mail_enabled']) ? '1' : '0');
        set_setting($pdo, 'mail_from', $from);
        return 'OK:Email settings saved.';
    }
    if (isset($_POST['advanced'])) {
        set_setting($pdo, 'require_site_key', isset($_POST['require_site_key']) ? '1' : '0');
        set_setting($pdo, 'setup_dismissed', isset($_POST['setup_dismissed']) ? '1' : '0');
        return 'OK:Advanced settings saved.';
    }
    $fields = ['blog_title', 'home_heading', 'home_intro', 'canonical_base', 'accent_color', 'posts_per_page', 'about_text', 'timezone', 'allowed_origins', 'site_key'];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $_POST)) {
            continue;
        }
        $value = trim((string) $_POST[$field]);
        if ($field === 'accent_color' && !preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            $value = '#c2410c';
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
        if ($field === 'timezone') {
            $value = in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : 'UTC';
        }
        if ($field === 'allowed_origins') {
            $value = implode("\n", parse_allowed_origins($value));
        }
        set_setting($pdo, $field, $value);
    }
    if (array_key_exists('require_site_key', $_POST) || array_key_exists('subscribe_mail_enabled', $_POST)) {
        set_setting($pdo, 'require_site_key', isset($_POST['require_site_key']) ? '1' : '0');
        set_setting($pdo, 'subscribe_mail_enabled', isset($_POST['subscribe_mail_enabled']) ? '1' : '0');
    }
    return 'OK:Settings saved.';
}

function change_site_key(PDO $pdo): string
{
    $new = slugify((string) ($_POST['new_site_key'] ?? ''));
    $old = setting($pdo, 'site_key', 'store-1');
    if ($new === '' || str_starts_with($new, 'post-')) {
        return 'Use lowercase letters, numbers, and dashes for the site id.';
    }
    if ($new === $old) {
        return 'That is already the site id.';
    }
    $pdo->beginTransaction();
    try {
        foreach (['posts', 'subscribers', 'embed_seen'] as $table) {
            $stmt = $pdo->prepare('UPDATE ' . $table . ' SET site = :new WHERE site = :old');
            $stmt->execute([':new' => $new, ':old' => $old]);
        }
        set_setting($pdo, 'site_key', $new);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        write_server_log('error', 'Site id change failed: ' . $e->getMessage());
        return 'Could not change the site id. Another site may already use it.';
    }
    return 'OK:Site id changed to ' . $new . '. Update the snippet on every page that embeds your posts.';
}

function send_test_email(PDO $pdo, array $user): string
{
    if (!function_exists('mail')) {
        return 'PHP mail() is not available on this host. Ask your host to enable it, or leave emails off and send confirm links yourself.';
    }
    if (!rate_limit($pdo, 'test_email_' . (int) $user['id'], 5, 3600)) {
        return 'Too many test emails. Try again in an hour.';
    }
    $to = (string) ($user['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'Your account has no valid email address.';
    }
    $sent = @mail($to, 'Test: confirm your ' . setting($pdo, 'blog_title', 'TinyBlog') . ' subscription', "This is a test of TinyBlog confirmation emails.\n\nSubscribers will receive a link like:\n" . canonical_url($pdo, '/subscribe/confirm/example-token') . "\n", mail_headers($pdo));
    return $sent ? 'OK:Test email handed to the server for ' . $to . '. If it does not arrive in a few minutes, check spam or ask your host about mail().' : 'The server refused to send the email. Your host may block mail(); leave emails off and copy confirm links instead.';
}

function mail_headers(PDO $pdo): string
{
    $from = setting($pdo, 'mail_from', '');
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    $name = str_replace(["\r", "\n", '"'], '', setting($pdo, 'blog_title', 'TinyBlog'));
    return 'From: "' . $name . '" <' . $from . '>';
}

// ----- Account & users ----------------------------------------------------------

function render_account_admin(PDO $pdo, array $user): void
{
    unset($pdo);
    echo admin_page_head('Account', htmlEscape((string) $user['name']) . ' · ' . htmlEscape((string) $user['email']) . ' · ' . htmlEscape(ucfirst((string) $user['role'])));
    echo '<section class="card form-card"><h2 class="card-title">Change password</h2><p class="muted small">Use a new password with at least 12 characters.</p><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="change_password">';
    echo '<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>';
    echo '<label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label>';
    echo '<label>Confirm new password<input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label>';
    echo '<button>Update password</button></form></section>';
}

function render_users_admin(PDO $pdo, array $currentUser): void
{
    echo admin_page_head('Users', 'Admins manage everything. Editors write and publish posts and use media.');
    echo settings_tabs_nav('users');
    $stmt = $pdo->query('SELECT id, email, name, role, created_at FROM users ORDER BY datetime(created_at) DESC, id DESC');
    echo '<div class="table-card"><table><caption class="sr-only">Users</caption><thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Added</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) {
        echo '<tr><td>' . htmlEscape($row['name']) . '</td><td>' . htmlEscape($row['email']) . '</td><td><span class="status-badge ' . ($row['role'] === 'admin' ? 'published' : 'draft') . '">' . htmlEscape($row['role']) . '</span></td><td>' . htmlEscape(relative_time((string) $row['created_at'])) . '</td><td class="row-actions end">';
        if ((int) $row['id'] === (int) $currentUser['id']) {
            echo '<span class="muted small">You</span>';
        } else {
            echo '<form method="post" data-confirm="Delete this user?">' . csrf_field() . '<input type="hidden" name="admin_action" value="delete_user"><input type="hidden" name="user_id" value="' . (int) $row['id'] . '"><button class="link-button muted">Delete</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<section class="card form-card"><h2 class="card-title">Add user</h2><form method="post">' . csrf_field() . '<input type="hidden" name="admin_action" value="add_user">';
    echo '<div class="field-row"><label>Name<input name="name" autocomplete="name"></label><label>Email<input name="email" type="email" autocomplete="email" required></label><label>Role<select name="role"><option value="editor">Editor</option><option value="admin">Admin</option></select></label></div>';
    echo '<label>Temporary password<input name="temporary_password" type="password" minlength="12" autocomplete="new-password" required><span class="hint">Share it privately; they can change it under Account.</span></label><button>Add user</button></form></section>';
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
    set_setting($pdo, 'content_changed_at', now_utc());
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
        return 'Image is too large. Limit is ' . human_bytes((int) $GLOBALS['TB_CONFIG']['max_upload_bytes']) . '.';
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
    if ($altText === '') {
        return 'Add alt text that describes the image. Screen readers read it aloud.';
    }
    $safeBase = preg_replace('/[^a-z0-9._-]+/i', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'image';
    $filename = strtolower(substr($safeBase, 0, 48)) . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $GLOBALS['TB_CONFIG']['upload_dir'] . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        return 'Could not store upload.';
    }
    chmod($target, 0644);
    $imageInfo = getimagesize((string) $target);
    $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
    $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;
    $url = rtrim(setting($pdo, 'canonical_base', base_url()), '/') . '/uploads/' . rawurlencode($filename);
    $stmt = $pdo->prepare('INSERT INTO media (filename, original_name, mime, size, url, alt_text, width, height, user_id, created_at) VALUES (:filename, :original_name, :mime, :size, :url, :alt_text, :width, :height, :user_id, :created_at)');
    $stmt->execute([
        ':filename' => $filename,
        ':original_name' => $original,
        ':mime' => $mime,
        ':size' => (int) $file['size'],
        ':url' => $url,
        ':alt_text' => $altText,
        ':width' => $width > 0 ? $width : null,
        ':height' => $height > 0 ? $height : null,
        ':user_id' => (int) $user['id'],
        ':created_at' => now_utc(),
    ]);
    return 'OK:Image uploaded. Use “Copy Markdown” or pick it from the editor.';
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
    stream_json_rows($pdo->query('SELECT filename, original_name, mime, size, url, alt_text, width, height, created_at FROM media ORDER BY id'));
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
        $mediaStmt = $pdo->prepare('INSERT OR IGNORE INTO media (filename, original_name, mime, size, url, alt_text, width, height, created_at) VALUES (:filename, :original_name, :mime, :size, :url, :alt_text, :width, :height, :created_at)');
        foreach (($data['media'] ?? []) as $media) {
            if (!empty($media['filename']) && !empty($media['url'])) {
                $mediaStmt->execute([
                    ':filename' => basename((string) $media['filename']),
                    ':original_name' => basename((string) ($media['original_name'] ?? $media['filename'])),
                    ':mime' => (string) ($media['mime'] ?? 'image/jpeg'),
                    ':size' => (int) ($media['size'] ?? 0),
                    ':url' => (string) $media['url'],
                    ':alt_text' => (string) ($media['alt_text'] ?? ''),
                    ':width' => isset($media['width']) ? (int) $media['width'] : null,
                    ':height' => isset($media['height']) ? (int) $media['height'] : null,
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
    if ($path === '/embed/feed.html') {
        render_embed_feed($pdo);
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
    if ($path === '/') {
        render_home($pdo);
    }
    render_not_found($pdo);
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
    if (isset($pdo) && $pdo instanceof PDO) {
        render_error_page($pdo, 'A server error occurred. Check the server log.');
    }
    security_headers('html');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TinyBlog error</title><body style="font-family:system-ui,sans-serif;margin:3rem;background:#f4f3ee;color:#0a0a0a"><main style="max-width:42rem"><h1>Server error</h1><p>A server error occurred. Check the server log.</p></main></body></html>';
}
