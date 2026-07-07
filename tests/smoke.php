<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'tinyblog.php',
    'tinyblog-widget.js',
    'index.html',
    'docs.html',
    'tinyblog-vs-dropinblog.html',
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

function hex_channel(float $channel): float
{
    $channel /= 255;
    return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
}

function hex_luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return 0.2126 * hex_channel((float) $r) + 0.7152 * hex_channel((float) $g) + 0.0722 * hex_channel((float) $b);
}

function contrast_ratio(string $a, string $b): float
{
    $l1 = hex_luminance($a);
    $l2 = hex_luminance($b);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
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
    foreach (['function render_llms_txt', "'/llms.txt'", 'Content-Type: text/plain; charset=utf-8', 'visible_post_where()', 'canonical_url($pdo, \'/post/\' . rawurlencode($post[\'slug\']))'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing llms.txt hook: {$needle}";
        }
    }
    foreach (['function valid_archive_month', 'function archive_months', 'function render_archive', "strftime('%Y-%m', publish_at)", "preg_match('/^\\d{4}-\\d{2}$/', \$month)", "pagination_links('/archive', \$page, \$hasMore, ['month' => \$month])", "render_archive(\$pdo)", "canonical_url(\$pdo, page_url('/archive', \$page - 1, ['month' => \$month]))"] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing archive-by-month hook: {$needle}";
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
    foreach (['media-grid', 'media-card', 'Copy Markdown', 'data-markdown', 'upload-dropzone', 'dragover', 'copyMarkdown'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing media grid hook: {$needle}";
        }
    }
    foreach ([':focus-visible', 'notice success', 'role="alert"', 'auth-card', 'auth-mark', '<caption>', 'button:hover'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing form/focus polish hook: {$needle}";
        }
    }
    foreach (['render_account_admin', 'render_users_admin', 'change_password', 'add_user', 'delete_user', "password_verify(\$currentPassword", 'session_regenerate_id(true)', 'not self', 'last admin', "'account', 'users'", 'admin_action" value="change_password"', 'admin_action" value="add_user"', 'admin_action" value="delete_user"'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing account/users hook: {$needle}";
        }
    }
    foreach (['function settings_health_checks', 'health-panel', 'health-dot', 'health-ok', 'health-warn', 'PHP_VERSION', 'SQLite version', 'fts5_available($pdo)', "is_writable((string) \$GLOBALS['TB_CONFIG']['data_dir'])", "is_writable((string) \$GLOBALS['TB_CONFIG']['upload_dir'])", 'is_https()', "is_file(__DIR__ . DIRECTORY_SEPARATOR . '.env')", "function_exists('mail')"] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing settings health panel hook: {$needle}";
        }
    }
    foreach (['width INTEGER', 'height INTEGER', 'ALTER TABLE media ADD COLUMN width INTEGER', 'ALTER TABLE media ADD COLUMN height INTEGER', 'getimagesize((string) $target)', 'function media_dimensions_by_url', 'function add_media_dimensions_to_html', 'function render_post_content_html', "render_post_content_html(\$pdo, (string) \$post['content_html'])", "width=\"' . (int) \$media['width'] . '\" height=\"' . (int) \$media['height'] . '\"", 'SELECT filename, original_name, mime, size, url, alt_text, width, height, created_at FROM media ORDER BY id', 'INSERT OR IGNORE INTO media (filename, original_name, mime, size, url, alt_text, width, height, created_at)'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing image nicety hook: {$needle}";
        }
    }
    foreach (['function recent_posts_for_error', 'function render_not_found', 'function render_error_page', 'http_response_code(404);', 'render_not_found($pdo)', 'name="q" placeholder="Search posts"', 'Recent posts', "render_error_page(\$pdo, 'A server error occurred. Check the server log.')"] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing styled error-page hook: {$needle}";
        }
    }
    if (str_contains($php, '<!doctype html><meta charset="utf-8"><title>TinyBlog error</title><p>A server error occurred. Check the server log.</p>')) {
        $failures[] = 'tinyblog.php should use the styled error page instead of the bare error fallback.';
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
    foreach (['["light", "dark", "card"]', "data-theme='card'", 'config.theme === "card" ? "card"', ".tbw[data-theme='card']"] as $needle) {
        if (!str_contains($js, $needle)) {
            $failures[] = "tinyblog-widget.js missing card theme hook: {$needle}";
        }
    }
    if (!str_contains($js, 'img: ["src", "alt", "width", "height"]')) {
        $failures[] = 'tinyblog-widget.js should preserve safe image dimensions in sanitized content.';
    }
    if (filesize($root . '/tinyblog-widget.js') > 19149) {
        $failures[] = 'tinyblog-widget.js card theme should add less than 1 KB.';
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
    foreach (['id="live-demo"', 'tinyblog-widget.js', 'TinyBlogWidget.init', 'endpoint: "demo"', 'This is the actual widget, rendering the actual JSON.', '<noscript>'] as $needle) {
        if (!str_contains($landing, $needle)) {
            $failures[] = "index.html missing live widget demo hook: {$needle}";
        }
    }
    foreach (['tinyblog-vs-dropinblog.html', 'vs DropInBlog', 'from $49/mo'] as $needle) {
        if (!str_contains($landing, $needle)) {
            $failures[] = "index.html missing DropInBlog comparison hook: {$needle}";
        }
    }
    foreach (['How it works', 'Setup', 'Features', 'Compare', 'Scope', 'Quick start', '2 files to deploy', '~140 KB, no deps', '0 external requests on your blog', '5-min cPanel install', 'Read the source', 'github.com/tanzir71/tinyblog/blob/main/tinyblog.php'] as $needle) {
        if (!str_contains($landing, $needle)) {
            $failures[] = "index.html missing honest proof copy: {$needle}";
        }
    }
    foreach (['What the admin looks like', 'assets/shot-admin-dashboard.png', 'assets/shot-admin-editor.png', 'assets/shot-admin-media.png', 'loading="lazy"', 'width="720"', 'height="450"'] as $needle) {
        if (!str_contains($landing, $needle)) {
            $failures[] = "index.html missing admin screenshot proof: {$needle}";
        }
    }
    foreach (['SYS', 'MIT · Commercial ok'] as $needle) {
        if (str_contains($landing, $needle)) {
            $failures[] = "index.html should not keep filler/duplicated proof copy: {$needle}";
        }
    }
}

if (is_file($root . '/demo/posts')) {
    $demo = file_get_contents($root . '/demo/posts');
    foreach (['TinyBlog demo', 'Why TinyBlog exists', 'Secure defaults on cheap hosting', 'Embed a feed on any page'] as $needle) {
        if (!str_contains($demo, $needle)) {
            $failures[] = "demo/posts missing expected demo data: {$needle}";
        }
    }
} else {
    $failures[] = 'Missing static demo/posts widget JSON file.';
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

if (is_file($root . '/compare.html')) {
    $compare = file_get_contents($root . '/compare.html');
    foreach (['tinyblog-vs-dropinblog.html', 'TinyBlog vs DropInBlog', 'from $49/mo'] as $needle) {
        if (!str_contains($compare, $needle)) {
            $failures[] = "compare.html missing DropInBlog comparison hook: {$needle}";
        }
    }
}

if (is_file($root . '/tinyblog-vs-dropinblog.html')) {
    $dropinblog = file_get_contents($root . '/tinyblog-vs-dropinblog.html');
    foreach (['TinyBlog vs DropInBlog', 'from $49/mo', '$0 self-hosted', 'managed convenience', 'no trackers'] as $needle) {
        if (!str_contains($dropinblog, $needle)) {
            $failures[] = "tinyblog-vs-dropinblog.html missing expected copy: {$needle}";
        }
    }
}

if (is_file($root . '/tinyblog-vs-disqus-embed.html')) {
    $embeds = file_get_contents($root . '/tinyblog-vs-disqus-embed.html');
    if (!str_contains($embeds, 'third-party embed widgets')) {
        $failures[] = 'tinyblog-vs-disqus-embed.html should be framed as third-party embed widgets.';
    }
}

$staticPages = [
    'index.html',
    'docs.html',
    'compare.html',
    'tinyblog-vs-dropinblog.html',
    'tinyblog-vs-substack.html',
    'tinyblog-vs-ghost.html',
    'tinyblog-vs-wordpress.html',
    'tinyblog-vs-disqus-embed.html',
];

foreach ($staticPages as $doc) {
    $path = $root . '/' . $doc;
    if (!is_file($path)) {
        $failures[] = "Missing static page for metadata/a11y sweep: {$doc}";
        continue;
    }
    $html = file_get_contents($path);
    if (preg_match_all('/<h1\b/i', $html) !== 1) {
        $failures[] = "{$doc} should contain exactly one h1.";
    }
    $canonical = $doc === 'index.html'
        ? 'https://tanzir71.github.io/tinyblog/'
        : 'https://tanzir71.github.io/tinyblog/' . $doc;
    if (!str_contains($html, '<link rel="canonical" href="' . $canonical . '">')) {
        $failures[] = "{$doc} should use the canonical GitHub Pages URL.";
    }
    if (!str_contains($html, '<meta property="og:image" content="https://tanzir71.github.io/tinyblog/assets/og.png">')) {
        $failures[] = "{$doc} should use an absolute Open Graph image URL.";
    }
    if (str_contains($html, 'content="assets/og.png"')) {
        $failures[] = "{$doc} should not use a relative social image URL.";
    }
    if (str_contains($html, 'name="twitter:image"') && !str_contains($html, '<meta name="twitter:image" content="https://tanzir71.github.io/tinyblog/assets/og.png">')) {
        $failures[] = "{$doc} should use an absolute Twitter image URL.";
    }
}

if (is_file($root . '/index.html')) {
    $landing = file_get_contents($root . '/index.html');
    if (!str_contains($landing, '<figure class="live-demo-card" role="region" aria-label="Live TinyBlog widget demo">')) {
        $failures[] = 'index.html live widget demo should be an explicitly labeled region.';
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
    if (preg_match('/--paper:\s*(#[0-9a-f]{6})/i', $css, $paper) && preg_match('/--muted:\s*(#[0-9a-f]{6})/i', $css, $muted)) {
        if (contrast_ratio($paper[1], $muted[1]) < 4.5) {
            $failures[] = 'assets/site.css muted text contrast on paper should be at least 4.5:1.';
        }
    } else {
        $failures[] = 'assets/site.css should expose paper and muted colors for contrast checking.';
    }
    foreach (['a:focus-visible', 'button:focus-visible', 'input:focus-visible', 'textarea:focus-visible', 'select:focus-visible'] as $needle) {
        if (!str_contains($css, $needle)) {
            $failures[] = "assets/site.css missing explicit focus-visible selector: {$needle}";
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

foreach (['dashboard', 'editor', 'media'] as $shot) {
    $file = $root . "/assets/shot-admin-{$shot}.png";
    if (!is_file($file)) {
        $failures[] = "Missing admin screenshot asset: assets/shot-admin-{$shot}.png";
        continue;
    }
    $info = getimagesize($file);
    if ($info === false || (int) $info[0] !== 1440 || (int) $info[1] !== 900) {
        $failures[] = "assets/shot-admin-{$shot}.png must be a 1440x900 PNG.";
    }
    if (filesize($file) > 150 * 1024) {
        $failures[] = "assets/shot-admin-{$shot}.png must stay under 150 KB.";
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

if (is_file($root . '/README.md')) {
    $readme = file_get_contents($root . '/README.md');
    foreach (['Admin -> Account', 'Admin -> Users'] as $needle) {
        if (!str_contains($readme, $needle)) {
            $failures[] = "README.md missing account/users admin guidance: {$needle}";
        }
    }
    if (!str_contains($readme, 'GET /llms.txt')) {
        $failures[] = 'README.md missing llms.txt route documentation.';
    }
    if (!str_contains($readme, 'GET /archive?month=2026-07')) {
        $failures[] = 'README.md missing archive month route documentation.';
    }
    if (!str_contains($readme, '`light`, `dark`, or `card`')) {
        $failures[] = 'README.md missing card widget theme documentation.';
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "TinyBlog Widget smoke checks passed." . PHP_EOL;
