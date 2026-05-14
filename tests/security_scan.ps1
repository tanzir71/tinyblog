$ErrorActionPreference = "Stop"

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = Join-Path $root "tinyblog.php"
$text = Get-Content -LiteralPath $php -Raw

$patterns = @(
  'SELECT\s+.*\.\s*\$_(GET|POST|REQUEST)',
  'INSERT\s+.*\.\s*\$_(GET|POST|REQUEST)',
  'UPDATE\s+.*\.\s*\$_(GET|POST|REQUEST)',
  'DELETE\s+.*\.\s*\$_(GET|POST|REQUEST)',
  '\$_(GET|POST|REQUEST)\[[^\]]+\]\s*\.'
)

$findings = New-Object System.Collections.Generic.List[string]
foreach ($pattern in $patterns) {
  if ([regex]::IsMatch($text, $pattern)) {
    $findings.Add("Potential unsafe concatenation matched: $pattern")
  }
}

if ($findings.Count -gt 0) {
  $findings | ForEach-Object { Write-Error $_ }
  exit 1
}

Write-Host "No common PHP superglobal-to-SQL concatenation patterns found."
