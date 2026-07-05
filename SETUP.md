# TinyBlog Widget Shared Hosting Setup

Repository: https://github.com/tanzir71/tinyblog

This guide targets Namecheap-style cPanel hosting with PHP 8+, PDO, SQLite, and Apache.

## 1. Upload Files

Upload these files and folders to your site root or a subfolder such as `/blog`:

- `tinyblog.php`
- `tinyblog-widget.js`
- `index.html`
- `docs.html`
- `_config.yml` if deploying the static marketing/docs site with GitHub Pages
- `.htaccess`
- `assets/`
- `uploads/`
- `data/.htaccess`
- `.env.example`
- `README.md`, `SETUP.md`, `SECURITY.md` if you want source docs available

## 2. PHP Version

In cPanel:

1. Open "Select PHP Version" or "MultiPHP Manager".
2. Choose PHP 8.0 or newer.
3. Enable `pdo_sqlite`, `fileinfo`, and `sqlite3` if your host makes extensions selectable.

## 3. Permissions

Create or upload these directories:

```text
data/
uploads/
```

Recommended permissions:

```bash
chmod 755 data uploads
chmod 644 tinyblog.php tinyblog-widget.js .htaccess
```

If your host runs PHP as a different user and SQLite cannot create the database, temporarily use:

```bash
chmod 775 data uploads
```

The app auto-creates:

```text
data/tinyblog.db
```

Keep `data/` outside the public web root if your hosting plan allows it. If it must stay in web root, the included `.htaccess` denies direct database downloads.

Optional server-local configuration:

```bash
cp .env.example .env
chmod 600 .env
```

Edit `.env` only if you need custom paths, thresholds, or backend-editable admin credentials:

```text
TB_DB_PATH=/home/USER/private/tinyblog.db
TB_UPLOAD_DIR=/home/USER/public_html/blog/uploads
TB_LOG_FILE=/home/USER/private/tinyblog.log
TB_SESSION_TIMEOUT=1800
TB_LOGIN_RATE_LIMIT=10
TB_ADMIN_EMAIL=owner@example.com
TB_ADMIN_NAME=Owner
TB_ADMIN_PASSWORD=change-this-long-password
```

`TB_ADMIN_EMAIL` and `TB_ADMIN_PASSWORD` let you edit the admin login from cPanel/File Manager like any other private backend file. After saving `.env`, use the known frontend login link `https://blog.example.com/admin`.

## 4. Apache Rewrite

The included `.htaccess` routes clean URLs to `tinyblog.php`:

```apache
Options -Indexes
DirectoryIndex index.html tinyblog.php

<IfModule mod_headers.c>
  Header always set X-Frame-Options "DENY"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
</IfModule>

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /
  RewriteRule ^uploads/ - [L]
  RewriteRule ^assets/ - [L]
  RewriteRule ^tinyblog-widget\.js$ - [L]
  RewriteRule ^index\.html$ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ tinyblog.php [L]
</IfModule>

<FilesMatch "^(.*\.db|.*\.sqlite|.*\.sqlite3|.*\.log|\.env)$">
  Require all denied
</FilesMatch>
```

If installing in `/blog`, set `RewriteBase /blog/` or leave it as-is on most Apache setups.

## 5. First Admin

1. Visit `https://blog.example.com/admin`.
2. Create the first admin account with a strong password, or log in with the `.env` credentials if you set `TB_ADMIN_EMAIL` and `TB_ADMIN_PASSWORD`.
3. Open Settings and set:
   - `Canonical base URL`, for example `https://blog.example.com`
   - `Site id`, for example `store-1`
   - `Allowed widget origins`, one per line, for example `https://store.example.com`
4. Save settings.
5. Use "Load sample posts" if desired.
6. Create, edit, publish, unpublish, and delete posts from `/admin`. cPanel is for installation, backups, and recovery only; normal publishing should stay in the TinyBlog browser UI.
7. Use Settings -> Backup to export/import TinyBlog JSON backups when moving hosts. Media files are referenced by path, so copy `uploads/` alongside the JSON when restoring elsewhere.

## 6. Embed On Another Site

Paste this on the page where the feed should appear:

```html
<script
  src="https://blog.example.com/tinyblog-widget.js"
  data-tb-config='{"site":"store-1","endpoint":"https://blog.example.com/api","widgetType":"feed","maxItems":5}'>
</script>
```

If you enable "Require public siteKey for API reads" in Settings, add:

```json
{"siteKey":"PASTE_PUBLIC_SITE_KEY_FROM_SETTINGS"}
```

## 7. Cron Examples

RSS and sitemap are generated dynamically, but you can warm them and make simple backups:

```cron
# Warm feed and sitemap every hour
0 * * * * wget -q -O /dev/null https://blog.example.com/feed.xml
2 * * * * wget -q -O /dev/null https://blog.example.com/feed.json
5 * * * * wget -q -O /dev/null https://blog.example.com/sitemap.xml

# Daily SQLite backup
20 2 * * * cp /home/USER/public_html/blog/data/tinyblog.db /home/USER/backups/tinyblog-$(date +\%F).db

# Optional: compress old backups weekly
30 2 * * 0 find /home/USER/backups -name 'tinyblog-*.db' -mtime +14 -type f -delete
```

Use your real cPanel home path. Some shared hosts require the full path to `wget`, such as `/usr/bin/wget`.

## 8. Troubleshooting

- 500 error on first run: check PHP error logs and confirm `data/` is writable.
- App log: by default, security/runtime errors go to `data/tinyblog.log`; override with `TB_LOG_FILE`.
- Upload fails: confirm `uploads/` is writable and `fileinfo` is enabled.
- Widget fails on another domain: add that exact origin to Settings, for example `https://www.example.com`.
- Clean URLs fail: confirm Apache `mod_rewrite` is enabled, or use `tinyblog.php?route=/api/posts`.
- SQLite missing: enable `pdo_sqlite` in cPanel or ask hosting support.
