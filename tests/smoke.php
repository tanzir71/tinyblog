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
    foreach ([
        '--paper:#f4f3ee',
        '--panel:#faf9f5',
        '--ink:#0a0a0a',
        '--ink-soft:#2b2a27',
        '--muted:#6c6a62',
        '--line:#dcd9d0',
        '--line-strong:#0a0a0a',
        '--accent-soft:#eceaf9',
    ] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing shared design token: {$needle}";
        }
    }
    foreach ([
        '@media (prefers-color-scheme: dark){:root{',
        '--paper:#131210',
        '--panel:#1a1917',
        '--ink:#f2f1ec',
        '--muted:#9d9a90',
        '--line:#2c2a26',
        '--accent-soft:#1e2033',
    ] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing dark-mode design token: {$needle}";
        }
    }
    foreach (['function visible_post_where', 'publish_at <= :now', 'reading_minutes', "'hasMore'", 'application/ld+json', 'BlogPosting', 'rel="next"', 'rel="prev"'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing expected feature hook: {$needle}";
        }
    }
    foreach (['home_heading', 'home_intro', "setting(\$pdo, 'home_heading'", "setting(\$pdo, 'home_intro'", "'home_heading', 'home_intro'"] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing configurable home hero hook: {$needle}";
        }
    }
    if (str_contains($php, 'Small posts, clean embeds.')) {
        $failures[] = 'tinyblog.php should not hardcode the old home hero tagline.';
    }
    foreach (['function app_icon_head_tags', "is_file(__DIR__ . '/assets/logo.svg')", '<link rel="icon" href="/assets/logo.svg">', 'name="theme-color" content="#f4f3ee"', 'name="theme-color" content="#131210"', 'echo app_icon_head_tags();', "app_icon_head_tags() . '<style>' . css_base(\$accent)"] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing favicon/theme-color head parity hook: {$needle}";
        }
    }
    foreach (['admin-shell', 'admin-topbar', 'admin-brand-mark', 'View site', 'admin-nav-link', 'aria-current="page"', 'admin-content', 'admin-logout'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing admin shell hook: {$needle}";
        }
    }
    if (str_contains($php, '<div class="site admin-layout"><aside class="admin-nav">')) {
        $failures[] = 'tinyblog.php should use the refined admin shell instead of the old button sidebar.';
    }
    foreach (['function dashboard_stats', 'function relative_time', 'function dashboard_status_label', 'toggle_post_status', 'admin_action" value="toggle_post_status"', 'stat-strip', 'status-badge', 'views_30d', 'Write your first post', 'row-actions'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing dashboard refinement hook: {$needle}";
        }
    }
    foreach (['editor-grid', 'markdown-toolbar', 'data-wrap-prefix', 'Post settings', 'renderLivePreview', 'setTimeout(renderLivePreview, 300)', 'aria-busy', 'postForm.requestSubmit', 'event.ctrlKey || event.metaKey'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing editor ergonomics hook: {$needle}";
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
    foreach ([
        'accent: "#0a0a0a"',
        '--tbw-paper:#f4f3ee',
        '--tbw-panel:#faf9f5',
        '--tbw-ink:#0a0a0a',
        '--tbw-ink-soft:#2b2a27',
        '--tbw-muted:#6c6a62',
        '--tbw-line:#dcd9d0',
        '--tbw-line-strong:#0a0a0a',
        '--tbw-accent-soft:#eceaf9',
        "--tbw-paper:#131210",
        "--tbw-panel:#1a1917",
        "--tbw-ink:#f2f1ec",
        "--tbw-muted:#9d9a90",
        "--tbw-line:#2c2a26",
        "--tbw-accent:#9aa6ff",
        "--tbw-accent-soft:#1e2033",
        'hasCustomAccent',
        'if (config.hasCustomAccent) frame.style.setProperty("--tbw-accent", config.accent);',
    ] as $needle) {
        if (!str_contains($js, $needle)) {
            $failures[] = "tinyblog-widget.js missing shared design token/default: {$needle}";
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
    foreach ([
        '--paper: #f4f3ee',
        '--panel: #faf9f5',
        '--ink: #0a0a0a',
        '--ink-soft: #2b2a27',
        '--muted: #6c6a62',
        '--line: #dcd9d0',
        '--line-strong: #0a0a0a',
        '--accent: #2436d4',
        '--accent-soft: #eceaf9',
    ] as $needle) {
        if (!str_contains($css, $needle)) {
            $failures[] = "assets/site.css missing canonical design token: {$needle}";
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
