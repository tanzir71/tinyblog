$ErrorActionPreference = "Stop"

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$required = @(
  "tinyblog.php",
  "tinyblog-widget.js",
  "index.html",
  "docs.html",
  "_config.yml",
  "README.md",
  "SETUP.md",
  "SECURITY.md",
  "CHANGELOG.md",
  ".gitignore",
  ".env.example",
  ".htaccess",
  "data/.htaccess",
  "uploads/.htaccess",
  "assets/og.png"
)

$failures = New-Object System.Collections.Generic.List[string]

foreach ($file in $required) {
  if (-not (Test-Path -LiteralPath (Join-Path $root $file) -PathType Leaf)) {
    $failures.Add("Missing required file: $file")
  }
}

$phpPath = Join-Path $root "tinyblog.php"
if (Test-Path -LiteralPath $phpPath) {
  $php = Get-Content -LiteralPath $phpPath -Raw
  foreach ($needle in @("password_hash", "hash_equals", "PDO", "csrf_token", "sanitize_markdown", "check_cors_or_fail", "security_headers", "Content-Security-Policy", "require_admin", "can_manage_post")) {
    if (-not $php.Contains($needle)) {
      $failures.Add("tinyblog.php missing expected safeguard: $needle")
    }
  }
  foreach ($needle in @("function visible_post_where", "publish_at <= :now", "reading_minutes", "'hasMore'", "application/ld+json", "BlogPosting", "rel=`"next`"", "rel=`"prev`"")) {
    if (-not $php.Contains($needle)) {
      $failures.Add("tinyblog.php missing expected feature hook: $needle")
    }
  }
  foreach ($needle in @(
    "confirm_token",
    "unsub_token",
    "/subscribe/confirm/",
    "/unsubscribe/",
    "function fts5_available",
    "posts_fts",
    "bm25",
    "function related_posts",
    "pinned",
    "alt_text",
    "delete_media",
    "function track_post_view",
    "post_views",
    "function render_json_feed",
    "/feed.json",
    "function export_data",
    "function import_data",
    "language-",
    "data-copy-code",
    "function maybe_not_modified",
    "ETag"
  )) {
    if (-not $php.Contains($needle)) {
      $failures.Add("tinyblog.php missing expected backlog hook: $needle")
    }
  }
}

$jsPath = Join-Path $root "tinyblog-widget.js"
if (Test-Path -LiteralPath $jsPath) {
  $js = Get-Content -LiteralPath $jsPath -Raw
  foreach ($needle in @("TinyBlogWidget", "sanitizeHtml", "escapeHtml", "widgetType", "feed", "post", "subscribe")) {
    if (-not $js.Contains($needle)) {
      $failures.Add("tinyblog-widget.js missing expected feature: $needle")
    }
  }
  foreach ($needle in @("No posts yet", "Couldn't load posts", "role=`"status`"", "reading_minutes", "data-theme='dark'")) {
    if (-not $js.Contains($needle)) {
      $failures.Add("tinyblog-widget.js missing expected polish: $needle")
    }
  }
}

$landingPath = Join-Path $root "index.html"
if (Test-Path -LiteralPath $landingPath) {
  $landing = Get-Content -LiteralPath $landingPath -Raw
  foreach ($needle in @("<link rel=`"icon`" href=`"assets/logo.svg`">", "rel=`"apple-touch-icon`"", "assets/og.png", "twitter:card", "twitter:image", "class=`"skip`"", "navigator.clipboard", "href=`"docs.html`"")) {
    if (-not $landing.Contains($needle)) {
      $failures.Add("index.html missing expected landing polish: $needle")
    }
  }
  if ($landing -match 'href="[^"]+\.md(?:#[^"]*)?"') {
    $failures.Add("index.html should link to docs.html instead of raw Markdown docs.")
  }
}

$docsPath = Join-Path $root "docs.html"
if (Test-Path -LiteralPath $docsPath) {
  $docs = Get-Content -LiteralPath $docsPath -Raw
  foreach ($needle in @("class=`"docs-toc`"", "id=`"quick-start`"", "id=`"deployment`"", "id=`"embed`"", "id=`"security`"", "id=`"changelog`"", "data-copy=`"#snippet-feed`"")) {
    if (-not $docs.Contains($needle)) {
      $failures.Add("docs.html missing expected docs surface: $needle")
    }
  }
  if ($docs -match 'href="[^"]+\.md(?:#[^"]*)?"') {
    $failures.Add("docs.html should not link to raw Markdown docs.")
  }
}

$cssPath = Join-Path $root "assets/site.css"
if (Test-Path -LiteralPath $cssPath) {
  $css = Get-Content -LiteralPath $cssPath -Raw
  foreach ($needle in @("prefers-color-scheme", ".docs-shell", ".docs-toc", ".docs-table")) {
    if (-not $css.Contains($needle)) {
      $failures.Add("assets/site.css missing expected site styling: $needle")
    }
  }
}

$pagesConfigPath = Join-Path $root "_config.yml"
if (Test-Path -LiteralPath $pagesConfigPath) {
  $pagesConfig = Get-Content -LiteralPath $pagesConfigPath -Raw
  foreach ($needle in @("exclude:", "README.md", "tinyblog.php", "tests/", "data/", "uploads/")) {
    if (-not $pagesConfig.Contains($needle)) {
      $failures.Add("_config.yml missing Pages exclude: $needle")
    }
  }
}

$ogPath = Join-Path $root "assets/og.png"
if (Test-Path -LiteralPath $ogPath) {
  Add-Type -AssemblyName System.Drawing
  $image = [System.Drawing.Image]::FromFile($ogPath)
  try {
    if ($image.Width -ne 1200 -or $image.Height -ne 630) {
      $failures.Add("assets/og.png must be a 1200x630 PNG.")
    }
  } finally {
    $image.Dispose()
  }
}

foreach ($doc in @("README.md", "SETUP.md", "SECURITY.md", "CHANGELOG.md", "index.html")) {
  $path = Join-Path $root $doc
  if (Test-Path -LiteralPath $path) {
    $text = Get-Content -LiteralPath $path -Raw
    if (-not $text.Contains("https://github.com/tanzir71/tinyblog")) {
      $failures.Add("$doc missing canonical GitHub link")
    }
  }
}

if ($failures.Count -gt 0) {
  $failures | ForEach-Object { Write-Error $_ }
  exit 1
}

Write-Host "TinyBlog Widget smoke checks passed."
