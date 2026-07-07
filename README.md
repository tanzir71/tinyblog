# TinyBlog Widget

Repository: https://github.com/tanzir71/tinyblog

TinyBlog Widget is an embeddable blog MVP: one JavaScript file renders a feed, a single post, or a subscribe box on any site, while a minimal PHP 8 + SQLite backend gives authors a secure browser admin UI for publishing without returning to cPanel for day-to-day content work.

The reader-facing PHP blog, admin, and widget use system fonts and load no external font or tracker services by default; the static marketing pages in this repo are separate from the blog you deploy.

## Security Summary

Top threats are SQL injection, cross-site scripting, cross-site request forgery, unsafe uploads, credential attacks, and unwanted cross-origin data exposure. TinyBlog Widget mitigates them with PDO prepared statements, output escaping, server-side sanitized Markdown, browser-side widget sanitization, CSRF tokens, session timeout, password hashing, CORS origin allowlists, rate limits, and image-only uploads.

## Files

- `tinyblog.php` - backend, admin, public canonical pages, JSON API, RSS, sitemap.
- `tinyblog-widget.js` - dependency-free embed script.
- `index.html` - minimal black-and-white landing page.
- `docs.html` - public HTML documentation page linked from the marketing site.
- `_config.yml` - GitHub Pages/Jekyll publish exclusions for source and runtime files.
- `SETUP.md` - shared hosting and cPanel deployment.
- `SECURITY.md` - safeguards and production checklist.
- `.htaccess` - Apache rewrite and file protection rules.
- `.env.example` - optional server-local config keys for paths, logs, and thresholds.
- `uploads/.htaccess` - denies script execution inside uploads.
- `data/.htaccess` - denies direct database access when `data/` is public.
- `CHANGELOG.md` - dated security hardening notes.
- `tests/smoke.php`, `tests/smoke.ps1`, and `tests/security_scan.ps1` - basic file/security checks.

## Quick Start

1. Upload the project files to a PHP 8+ shared hosting account.
2. Make sure `data/` and `uploads/` are writable by PHP.
3. Open `https://your-domain.example/admin`.
4. Create the first admin account, or set `TB_ADMIN_EMAIL` and `TB_ADMIN_PASSWORD` in the private `.env` file from cPanel/File Manager before visiting `/admin`.
5. In Admin -> Settings, set:
   - Blog title
   - Optional home heading and intro
   - Canonical base URL
   - Site id, for example `store-1`
   - Allowed widget origins, for example `https://your-store.example`
6. Click "Load sample posts" from the dashboard if you want the 3 sample posts.
7. Create, edit, publish, unpublish, and delete posts from `/admin`; cPanel is only needed for install/recovery tasks.
8. Use Admin -> Account to change your own password. Admin users can use Admin -> Users to add editors/admins or remove users without editing server files.

Optional config: copy `.env.example` to `.env` on the server if you need custom database/upload/log paths, rate-limit thresholds, or backend-editable admin credentials. `.env` is ignored by git.

Backend-editable admin credentials:

```text
TB_ADMIN_EMAIL=owner@example.com
TB_ADMIN_NAME=Owner
TB_ADMIN_PASSWORD=change-this-long-password
```

Edit those values in cPanel/File Manager or another private server-side file editor, then log in from the frontend at the known link `/admin`. `TB_ADMIN_PASSWORD_HASH` can be used instead of `TB_ADMIN_PASSWORD` if you prefer pasting a PHP `password_hash()` value.

## Embed Snippets

Feed widget:

```html
<script
  src="https://blog.example.com/tinyblog-widget.js"
  data-tb-config='{"site":"store-1","endpoint":"https://blog.example.com/api","widgetType":"feed","maxItems":5}'>
</script>
```

Single post widget:

```html
<script
  src="https://blog.example.com/tinyblog-widget.js"
  data-tb-config='{"site":"store-1","endpoint":"https://blog.example.com/api","widgetType":"post","slug":"why-tinyblog-widget-exists"}'>
</script>
```

Subscribe widget:

```html
<script
  src="https://blog.example.com/tinyblog-widget.js"
  data-tb-config='{"site":"store-1","endpoint":"https://blog.example.com/api","widgetType":"subscribe"}'>
</script>
```

Manual initialization:

```html
<div id="updates"></div>
<script src="https://blog.example.com/tinyblog-widget.js"></script>
<script>
  TinyBlogWidget.on("loaded", (event) => console.log("TinyBlog loaded", event.type));
  TinyBlogWidget.init({
    container: "#updates",
    endpoint: "https://blog.example.com/api",
    site: "store-1",
    widgetType: "feed",
    maxItems: 3,
    showExcerpt: true,
    accent: "#0a0a0a"
  });
</script>
```

## Widget Config

- `endpoint` - required API base URL, for example `https://blog.example.com/api`.
- `site` - public site id, default `store-1`.
- `siteKey` - optional public key if Admin -> Settings requires it.
- `widgetType` - `feed`, `post`, or `subscribe`.
- `slug` - required for `post` widgets.
- `maxItems` - feed item count, 1 to 50.
- `showExcerpt` - `true` or `false`.
- `theme` - `light` or `dark`; default is minimal black-and-white light.
- `accent` - CSS accent color, default `#0a0a0a`.
- `locale` - date formatting locale, default `en`.
- `container` - optional CSS selector for manual placement.

Feed responses include additive pagination fields (`items`, `page`, and `hasMore`) while keeping the original `posts` array for older embeds. Posts also expose `reading_minutes` and `reading_time` when the body is long enough to make a reading estimate useful.

If the backend returns no posts the feed widget renders an accessible "No posts yet" state. Failed fetches render "Couldn't load posts" and emit the documented `error` event.

The widget exposes:

- `TinyBlogWidget.init(config)`
- `TinyBlogWidget.on("loaded", callback)`
- `TinyBlogWidget.on("error", callback)`
- `TinyBlogWidget.openPost(slug)`

## Routes

Public/canonical:

- `GET /` - public archive listing.
- `GET /post/{slug}` - canonical post page with SEO, social meta, reading time, and BlogPosting JSON-LD.
- `GET /tag/{tag}?page=2` - paginated tag listing.
- `GET /search?q=term&page=2` - paginated prepared-statement search.
- `GET /archive?page=2` - paginated listing route.
- `GET /about` - privacy-friendly about page.
- `GET /feed.xml` - RSS feed.
- `GET /feed.json` - JSON Feed 1.1 feed.
- `GET /sitemap.xml` - sitemap.
- `GET /subscribe/confirm/{token}` - double opt-in confirmation link.
- `GET /unsubscribe/{token}` - one-click unsubscribe link.

JSON API:

- `GET /api/posts?site=SITE&limit=10&page=1`
- `GET /api/posts/{slug}?site=SITE`
- `POST /api/subscribe`
- `GET /api/feed.xml`

Admin:

- `GET /admin` - login/register/dashboard.
- Admin post actions are CSRF-protected form posts.
- `TB_ADMIN_EMAIL` + `TB_ADMIN_PASSWORD` in `.env` create or update an admin account from a backend-editable file; the login link remains `/admin`.
- Posts can be created, edited, published/unpublished, and deleted from the browser admin UI.
- Posts can be saved as `draft` or `published`; published posts with a future publish date stay hidden from public pages, RSS, sitemap, and the JSON API until that UTC time.
- Posts can be pinned, which moves them to the top of the home/API listing.
- Media uploads include alt text and CSRF-protected delete controls.
- Settings includes JSON export/import for posts, settings, confirmed subscribers, and media references.

## Sample Content

Create the first admin, then click "Load sample posts" on the dashboard. It creates:

- Why TinyBlog Widget exists
- A secure default for shared hosting
- Embedding a feed on any page

No seed admin password is included. This is intentional: default credentials are a real production risk.

## Test Plan

Run local smoke checks:

```powershell
powershell -ExecutionPolicy Bypass -File tests\smoke.ps1
powershell -ExecutionPolicy Bypass -File tests\security_scan.ps1
```

On a host or CI machine with PHP:

```bash
php -l tinyblog.php
php tests/smoke.php
```

Create first admin with a browser at `/admin` because CSRF tokens are form-bound. A curl flow is still useful for API checks after sample posts are loaded:

```bash
# Fetch recent posts
curl -H "Origin: https://your-store.example" \
  "https://blog.example.com/api/posts?site=store-1&limit=3&page=1"

# Fetch one post
curl -H "Origin: https://your-store.example" \
  "https://blog.example.com/api/posts/why-tinyblog-widget-exists?site=store-1"

# Subscribe
curl -X POST "https://blog.example.com/api/subscribe" \
  -H "Origin: https://your-store.example" \
  -H "Content-Type: application/json" \
  --data '{"site":"store-1","email":"reader@example.com"}'

# Confirm/unsubscribe links are tokenized URLs copied from Admin -> Subscribers
curl "https://blog.example.com/subscribe/confirm/PASTE_CONFIRM_TOKEN"
curl "https://blog.example.com/unsubscribe/PASTE_UNSUB_TOKEN"

# RSS
curl "https://blog.example.com/feed.xml"

# JSON Feed
curl "https://blog.example.com/feed.json"

# Page through public listings
curl "https://blog.example.com/archive?page=2"

# SQL injection probe: should return normal JSON or no results, not break SQL
curl "https://blog.example.com/search?q=%27%20OR%201%3D1--"

# CSRF probe: admin POST without a token should return 403
curl -i -X POST "https://blog.example.com/admin" \
  -d "admin_action=save_settings&blog_title=Injected"

# Login rate limit probe: after the configured threshold, expect HTTP 429
for i in $(seq 1 12); do
  curl -i -X POST "https://blog.example.com/admin" \
    -d "admin_action=login&csrf_token=PASTE_VALID_TOKEN&email=nope@example.com&password=wrong"
done

# Non-image upload rejection is checked manually in Admin -> Media
# by attempting to upload a .txt or .php file; it should be rejected.

# Conditional GET probe: second request with the returned ETag should be 304
curl -i "https://blog.example.com/api/posts?site=store-1"
curl -i "https://blog.example.com/feed.xml" -H 'If-None-Match: "PASTE_ETAG"'
```

Manual QA checklist:

- Create first admin and log out/log in.
- Set `TB_ADMIN_EMAIL` and `TB_ADMIN_PASSWORD` in `.env`, visit `/admin`, and confirm those backend-edited credentials log in.
- Load sample posts, publish/unpublish, edit slug/title/body/tags, then delete a post entirely from `/admin` without using cPanel.
- Save a draft and a future-dated published post; confirm neither appears in `/`, `/tag/...`, `/search`, `/feed.xml`, `/sitemap.xml`, or `/api/posts` until eligible.
- Create more posts than the configured page size and confirm page 2 has the next slice with no duplicates.
- Confirm reading time appears on long posts and in widget feed rows, but not on very short posts.
- Confirm canonical post pages include valid BlogPosting JSON-LD matching the visible post metadata.
- Confirm a pinned post leads the home listing and related posts share tags without including the current post.
- Search for normal terms and an injection probe such as `' OR 1=1--`; FTS5 hosts should rank results and non-FTS hosts should fall back safely.
- Confirm widget feed renders from an allowed origin.
- Confirm widget empty feeds show "No posts yet" and blocked/failed fetches show "Couldn't load posts" while firing the `error` event.
- Confirm the landing page favicon uses `assets/logo.svg`, social cards use `assets/og.png`, the skip link is first in keyboard tab order, the embed snippet copies, Docs links open `docs.html`, and dark mode remains legible.
- Confirm a blocked Origin receives 403 from `/api/posts`.
- Upload jpg/png/webp/gif with alt text, confirm a `.txt` or `.php` upload is rejected, then delete an uploaded image and confirm the DB row and file are removed.
- Submit subscribe form and verify rate limit after repeated attempts.
- Subscribe, confirm via the token link, then unsubscribe via the one-click link; confirmed subscriber counts and exports should exclude unconfirmed/unsubscribed rows.
- Visit a canonical post twice from the same IP/day and confirm the privacy-friendly view counter increments once; `DNT: 1` and obvious bot UAs should not increment.
- Export JSON, import into a fresh install, and confirm posts/settings/confirmed subscribers/media references are restored.
- Confirm fenced code blocks render as escaped `<pre><code class="language-x">` with a copy button and no external highlighter.
- Confirm `/feed.xml`, `/feed.json`, `/sitemap.xml`, and canonical post meta tags work.
- Confirm `/api/posts` or `/feed.xml` return `304` with matching conditional headers.
- Confirm Markdown raw HTML is escaped, not executed.

## How To Scale

Move to Postgres when multiple authors, higher write volume, or large subscriber lists make SQLite locking noticeable. Move media to object storage when uploads grow beyond a small shared-hosting disk. Move to Node or a headless CMS when you need webhooks, background jobs, multi-site tenancy, or a richer editorial workflow.
