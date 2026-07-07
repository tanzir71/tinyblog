<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'tinyblog.php',
    'tinyblog-widget.js',
    'index.html',
    'docs.html',
    '_config.yml',
    'README.md',
    'SETUP.md',
    'SECURITY.md',
    'CHANGELOG.md',
    '.gitignore',
    '.env.example',
    '.htaccess',
    'data/.htaccess',
    'uploads/.htaccess',
    'assets/og.png',
];

$failures = [];
foreach ($required as $file) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $file)) {
        $failures[] = "Missing required file: {$file}";
    }
}

if (is_file($root . '/tinyblog.php')) {
    $php = file_get_contents($root . '/tinyblog.php');
    foreach (['password_hash', 'hash_equals', 'PDO', 'csrf_token', 'sanitize_markdown', 'check_cors_or_fail', 'security_headers', 'Content-Security-Policy', 'require_admin', 'can_manage_post', 'function sync_configured_admin', 'TB_ADMIN_EMAIL', 'TB_ADMIN_PASSWORD_HASH'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing expected safeguard: {$needle}";
        }
    }
    foreach (['fonts.googleapis.com', 'fonts.gstatic.com'] as $needle) {
        if (str_contains($php, $needle)) {
            $failures[] = "tinyblog.php should not load external font host: {$needle}";
        }
    }
    if (!str_contains($php, 'font-family:ui-sans-serif,system-ui,-apple-system,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif')) {
        $failures[] = 'tinyblog.php should use the system-first app font stack.';
    }
    foreach (['function visible_post_where', 'publish_at <= :now', 'reading_minutes', "'hasMore'", 'application/ld+json', 'BlogPosting', 'rel="next"', 'rel="prev"'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing expected feature hook: {$needle}";
        }
    }
    foreach ([
        'confirm_token',
        'unsub_token',
        '/subscribe/confirm/',
        '/unsubscribe/',
        'function fts5_available',
        'posts_fts',
        'bm25',
        'function related_posts',
        'pinned',
        'alt_text',
        'delete_media',
        'delete_post',
        'admin_action" value="delete_post"',
        'function track_post_view',
        'post_views',
        'function render_json_feed',
        '/feed.json',
        'function export_data',
        'function import_data',
        'language-',
        'data-copy-code',
        'function maybe_not_modified',
        'ETag',
    ] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing expected backlog hook: {$needle}";
        }
    }
}

if (is_file($root . '/tinyblog-widget.js')) {
    $js = file_get_contents($root . '/tinyblog-widget.js');
    foreach (['TinyBlogWidget', 'sanitizeHtml', 'escapeHtml', "widgetType", "feed", "post", "subscribe"] as $needle) {
        if (!str_contains($js, $needle)) {
            $failures[] = "tinyblog-widget.js missing expected feature: {$needle}";
        }
    }
    foreach (['No posts yet', "Couldn't load posts", 'role="status"', 'reading_minutes', "data-theme='dark'"] as $needle) {
        if (!str_contains($js, $needle)) {
            $failures[] = "tinyblog-widget.js missing expected polish: {$needle}";
        }
    }
}

if (is_file($root . '/index.html')) {
    $landing = file_get_contents($root . '/index.html');
    foreach (['<link rel="icon" href="assets/logo.svg">', 'rel="apple-touch-icon"', 'assets/og.png', 'twitter:card', 'twitter:image', 'class="skip"', 'navigator.clipboard', 'href="docs.html"', 'Edit credentials in .env', '/admin', 'force HTTPS'] as $needle) {
        if (!str_contains($landing, $needle)) {
            $failures[] = "index.html missing expected landing polish: {$needle}";
        }
    }
    if (preg_match('/href="[^"]+\.md(?:#[^"]*)?"/', $landing)) {
        $failures[] = 'index.html should link to docs.html instead of raw Markdown docs.';
    }
}

if (is_file($root . '/docs.html')) {
    $docs = file_get_contents($root . '/docs.html');
    foreach (['class="docs-toc"', 'id="quick-start"', 'id="deployment"', 'id="embed"', 'id="security"', 'id="changelog"', 'data-copy="#snippet-feed"', 'TB_ADMIN_EMAIL', 'TB_ADMIN_PASSWORD', 'Known login link', 'Auth flow caveats', 'first visitor to', 'Remove <code>TB_ADMIN_*'] as $needle) {
        if (!str_contains($docs, $needle)) {
            $failures[] = "docs.html missing expected docs surface: {$needle}";
        }
    }
    if (preg_match('/href="[^"]+\.md(?:#[^"]*)?"/', $docs)) {
        $failures[] = 'docs.html should not link to raw Markdown docs.';
    }
}

if (is_file($root . '/assets/site.css')) {
    $css = file_get_contents($root . '/assets/site.css');
    foreach (['prefers-color-scheme', '.docs-shell', '.docs-toc', '.docs-table'] as $needle) {
        if (!str_contains($css, $needle)) {
            $failures[] = "assets/site.css missing expected site styling: {$needle}";
        }
    }
}

if (is_file($root . '/_config.yml')) {
    $config = file_get_contents($root . '/_config.yml');
    foreach (['exclude:', 'README.md', 'tinyblog.php', 'tests/', 'data/', 'uploads/'] as $needle) {
        if (!str_contains($config, $needle)) {
            $failures[] = "_config.yml missing Pages exclude: {$needle}";
        }
    }
}

if (is_file($root . '/.env.example')) {
    $env = file_get_contents($root . '/.env.example');
    foreach (['TB_ADMIN_EMAIL', 'TB_ADMIN_PASSWORD', 'TB_ADMIN_PASSWORD_HASH'] as $needle) {
        if (!str_contains($env, $needle)) {
            $failures[] = ".env.example missing backend-editable admin credential key: {$needle}";
        }
    }
}

if (is_file($root . '/assets/og.png')) {
    $info = getimagesize($root . '/assets/og.png');
    if ($info === false || (int) $info[0] !== 1200 || (int) $info[1] !== 630) {
        $failures[] = 'assets/og.png must be a 1200x630 PNG.';
    }
}

foreach (['README.md', 'SETUP.md', 'SECURITY.md', 'CHANGELOG.md', 'index.html'] as $doc) {
    if (is_file($root . '/' . $doc)) {
        $text = file_get_contents($root . '/' . $doc);
        if (!str_contains($text, 'https://github.com/tanzir71/tinyblog')) {
            $failures[] = "{$doc} missing canonical GitHub link";
        }
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "TinyBlog Widget smoke checks passed." . PHP_EOL;
