<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'tinyblog.php',
    'tinyblog-widget.js',
    'index.html',
    'README.md',
    'SETUP.md',
    'SECURITY.md',
    '.htaccess',
    'data/.htaccess',
    'uploads/.htaccess',
];

$failures = [];
foreach ($required as $file) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $file)) {
        $failures[] = "Missing required file: {$file}";
    }
}

if (is_file($root . '/tinyblog.php')) {
    $php = file_get_contents($root . '/tinyblog.php');
    foreach (['password_hash', 'hash_equals', 'PDO', 'csrf_token', 'sanitize_markdown', 'check_cors_or_fail'] as $needle) {
        if (!str_contains($php, $needle)) {
            $failures[] = "tinyblog.php missing expected safeguard: {$needle}";
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
}

foreach (['README.md', 'SETUP.md', 'SECURITY.md', 'index.html'] as $doc) {
    if (is_file($root . '/' . $doc)) {
        $text = file_get_contents($root . '/' . $doc);
        if (!str_contains($text, 'https://github.com/tanzir71/tinyblog-widget')) {
            $failures[] = "{$doc} missing canonical GitHub link";
        }
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "TinyBlog Widget smoke checks passed." . PHP_EOL;
