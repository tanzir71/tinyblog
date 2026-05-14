# TinyBlog Widget

Repository: https://github.com/tanzir71/tinyblog-widget

TinyBlog Widget is an embeddable blog MVP: one JavaScript file renders a feed, a single post, or a subscribe box on any site, while a minimal PHP 8 + SQLite backend gives authors a secure admin UI for publishing.

## Security Summary

Top threats are SQL injection, cross-site scripting, cross-site request forgery, unsafe uploads, credential attacks, and unwanted cross-origin data exposure. TinyBlog Widget mitigates them with PDO prepared statements, output escaping, server-side sanitized Markdown, browser-side widget sanitization, CSRF tokens, session timeout, password hashing, CORS origin allowlists, rate limits, and image-only uploads.

## Files

- `tinyblog.php` - backend, admin, public canonical pages, JSON API, RSS, sitemap.
- `tinyblog-widget.js` - dependency-free embed script.
- `index.html` - minimal black-and-white landing page.
- `SETUP.md` - shared hosting and cPanel deployment.
- `SECURITY.md` - safeguards and production checklist.
- `.htaccess` - Apache rewrite and file protection rules.
- `uploads/.htaccess` - denies script execution inside uploads.
- `data/.htaccess` - denies direct database access when `data/` is public.
- `tests/smoke.php` and `tests/smoke.ps1` - basic file/security smoke checks.

## Quick Start

1. Upload the project files to a PHP 8+ shared hosting account.
2. Make sure `data/` and `uploads/` are writable by PHP.
3. Open `https://your-domain.example/admin`.
4. Create the first admin account. No default password is shipped.
5. In Admin -> Settings, set:
   - Blog title
   - Canonical base URL
   - Site id, for example `store-1`
   - Allowed widget origins, for example `https://your-store.example`
6. Click "Load sample posts" from the dashboard if you want the 3 sample posts.

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
    accent: "#000000"
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
- `accent` - CSS accent color, default `#000000`.
- `locale` - date formatting locale, default `en`.
- `container` - optional CSS selector for manual placement.

The widget exposes:

- `TinyBlogWidget.init(config)`
- `TinyBlogWidget.on("loaded", callback)`
- `TinyBlogWidget.on("error", callback)`
- `TinyBlogWidget.openPost(slug)`

## Routes

Public/canonical:

- `GET /` - public archive listing.
- `GET /post/{slug}` - canonical post page with SEO and social meta.
- `GET /tag/{tag}` - tag listing.
- `GET /search?q=term` - prepared-statement search.
- `GET /archive` - listing route.
- `GET /about` - privacy-friendly about page.
- `GET /feed.xml` - RSS feed.
- `GET /sitemap.xml` - sitemap.

JSON API:

- `GET /api/posts?site=SITE&limit=10`
- `GET /api/posts/{slug}?site=SITE`
- `POST /api/subscribe`
- `GET /api/feed.xml`

Admin:

- `GET /admin` - login/register/dashboard.
- Admin post actions are CSRF-protected form posts.

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
  "https://blog.example.com/api/posts?site=store-1&limit=3"

# Fetch one post
curl -H "Origin: https://your-store.example" \
  "https://blog.example.com/api/posts/why-tinyblog-widget-exists?site=store-1"

# Subscribe
curl -X POST "https://blog.example.com/api/subscribe" \
  -H "Origin: https://your-store.example" \
  -H "Content-Type: application/json" \
  --data '{"site":"store-1","email":"reader@example.com"}'

# RSS
curl "https://blog.example.com/feed.xml"

# SQL injection probe: should return normal JSON or no results, not break SQL
curl "https://blog.example.com/search?q=%27%20OR%201%3D1--"

# Non-image upload rejection is checked manually in Admin -> Media
# by attempting to upload a .txt or .php file; it should be rejected.
```

Manual QA checklist:

- Create first admin and log out/log in.
- Load sample posts, publish/unpublish, edit slug/title/body/tags.
- Confirm widget feed renders from an allowed origin.
- Confirm a blocked Origin receives 403 from `/api/posts`.
- Upload jpg/png/webp/gif and confirm a `.txt` or `.php` upload is rejected.
- Submit subscribe form and verify rate limit after repeated attempts.
- Confirm `/feed.xml`, `/sitemap.xml`, and canonical post meta tags work.
- Confirm Markdown raw HTML is escaped, not executed.

## How To Scale

Move to Postgres when multiple authors, higher write volume, or large subscriber lists make SQLite locking noticeable. Move media to object storage when uploads grow beyond a small shared-hosting disk. Move to Node or a headless CMS when you need webhooks, background jobs, multi-site tenancy, or a richer editorial workflow.
