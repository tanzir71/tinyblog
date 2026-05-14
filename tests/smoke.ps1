$ErrorActionPreference = "Stop"

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$required = @(
  "tinyblog.php",
  "tinyblog-widget.js",
  "index.html",
  "README.md",
  "SETUP.md",
  "SECURITY.md",
  ".htaccess",
  "data/.htaccess",
  "uploads/.htaccess"
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
  foreach ($needle in @("password_hash", "hash_equals", "PDO", "csrf_token", "sanitize_markdown", "check_cors_or_fail")) {
    if (-not $php.Contains($needle)) {
      $failures.Add("tinyblog.php missing expected safeguard: $needle")
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
}

foreach ($doc in @("README.md", "SETUP.md", "SECURITY.md", "index.html")) {
  $path = Join-Path $root $doc
  if (Test-Path -LiteralPath $path) {
    $text = Get-Content -LiteralPath $path -Raw
    if (-not $text.Contains("https://github.com/tanzir71/tinyblog-widget")) {
      $failures.Add("$doc missing canonical GitHub link")
    }
  }
}

if ($failures.Count -gt 0) {
  $failures | ForEach-Object { Write-Error $_ }
  exit 1
}

Write-Host "TinyBlog Widget smoke checks passed."
