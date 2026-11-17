# TinyBlog Widget

Repository: https://github.com/tanzir71/tinyblog

TinyBlog Widget is a self-hosted blog you add to any site with one script tag: one JavaScript file renders a feed, a single post, a subscribe box, or a "What's new" badge, while a minimal PHP 8 + SQLite backend gives authors a secure browser admin for writing, scheduling, and putting the blog on their site without returning to cPanel.

The reader-facing PHP blog, admin, and widget use system fonts and load no external font or tracker services by default; the static marketing pages in this repo are separate from the blog you deploy.

## Architecture

Two deployable pieces — a backend you host once, and a widget you paste anywhere:

```
 Your store / any website                Your PHP hosting (cPanel etc.)
┌─────────────────────────┐            ┌─────────────────────────────────┐
│  <script                 │   JSON     │  tinyblog.php — everything:      │
│   src=tinyblog-widget.js>│ ◄────────► │  ├─ /api      JSON API (CORS     │
│  renders: feed · single  │  API       │  │            origin allowlist)  │
│  post · subscribe · badge│            │  ├─ /admin    author UI (CSRF,   │
│  (dependency-free,       │            │  │            sessions, hashing) │
│  sanitizes everything)   │            │  ├─ public canonical post pages  │
└─────────────────────────┘            │  ├─ RSS + sitemap                │
                                        │  └─ image-only uploads           │
 Readers can also visit                 │        │                         │
 canonical pages directly ────────────► │        ▼                         │
                                        │  SQLite in data/  ·  uploads/    │
                                        │  (.htaccess denies direct access)│
                                        └─────────────────────────────────┘
```

The widget is display-only and sanitizes what it renders; the PHP backend is the source of truth and defends itself (prepared statements, sanitized Markdown, CSRF tokens, rate limits, origin allowlists). One backend can serve multiple sites via `site` ids with per-site allowed origins.

## Security Summary

Top threats are SQL injection, cross-site scripting, cross-site request forgery, unsafe uploads, credential attacks, and unwanted cross-origin data exposure. TinyBlog Widget mitigates them with PDO prepared statements, output escaping, server-side sanitized Markdown, browser-side widget sanitization, CSRF tokens, session timeout, password hashing, CORS origin allowlists, rate limits, and image-only uploads.

## Files

- `tinyblog.php` - backend, admin, public canonical pages, JSON API, RSS, sitemap.
- `tinyblog-widget.js` - dependency-free embed script.
- `index.html` - marketing landing page (static; Inter + JetBrains Mono for the marketing site only).
- `assets/` - `site.css`, the `logo.svg` icon, the `wordmark.svg` logo, social image, and admin screenshots.
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
5. Follow the "Finish setting up" checklist on Admin -> Home:
   - Admin -> Settings: blog name, optional home heading and intro, blog address (canonical base URL), time zone, and accent color.
   - Admin -> Add to site: allow your website (for example `https://your-store.example`), pick a layout, choose your platform (any HTML, WordPress, Shopify, Squarespace, Webflow, Wix, or a PHP include), and copy the snippet. The page shows "Seen on your-store.example" once the embed loads, and lists refused requests with a one-click Allow.
   - Admin -> Settings -> Email (optional): turn on double opt-in emails and send yourself a test.
6. Click "Load sample posts" on Home if you want the 3 sample posts.
7. Write, schedule, publish, unpublish, and delete posts from Admin -> Posts; cPanel is only needed for install/recovery tasks.
8. Use Admin -> Account to change your own password. Admin users can use Admin -> Users to add editors/admins or remove users without editing server files.

Optional config: copy `.env.example` to `.env` on the server if you need custom database/upload/log paths, rate-limit thresholds, or backend-editable admin credentials. `.env` is ignored by git.

Backend-editable admin credentials:

```text
TB_ADMIN_EMAIL=owner@example.com
TB_ADMIN_NAME=Owner
TB_ADMIN_PASSWORD=change-this-long-password
```

Edit those values in cPanel/File Manager or another private server-side file editor, then log in from the frontend at the known link `/admin`. `TB_ADMIN_PASSWORD_HASH` can be used instead of `TB_ADMIN_PASSWORD` if you prefer pasting a PHP `password_hash()` value.

## Demo site

Use PHP hosting for the backend: the PHP app itself deploys to PHP hosting, not Vercel. Vercel is only for the marketing site and live demo feed in this repo: `index.html` is static, and `/api/posts` serves demo JSON for the landing-page widget.

1. Fork the repo -> vercel.com -> "Add New Project" -> import the fork.
2. Framework preset: **Other**. No build command. Output dir: root. Deploy.
3. Done - `index.html` is the site, `/api/posts` serves the demo feed.

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

"What's new" badge (a small button with an unread count; the panel lists recent posts and remembers what this browser has seen in `localStorage`):

```html
<script
  src="https://blog.example.com/tinyblog-widget.js"
  data-tb-config='{"site":"store-1","endpoint":"https://blog.example.com/api","widgetType":"badge","maxItems":5}'>
</script>
```

Admin -> Add to site builds these snippets for you and adds a `<noscript>` link to your blog, so crawlers and no-JavaScript readers still reach your posts:

```html
<noscript><a href="https://blog.example.com/">Read our blog</a></noscript>
```

Server-rendered include (no JavaScript; posts arrive in the page HTML). Fetch the sanitized `/embed/feed.html` fragment from any PHP page:

```php
<?php
$tinyblog = @file_get_contents('https://blog.example.com/embed/feed.html?site=store-1&limit=5');
echo $tinyblog !== false ? $tinyblog : '';
?>
```

Add `&css=0` to drop the small built-in styles, or `&excerpt=0` to show titles and dates only. Responses carry `ETag` and `Cache-Control: public, max-age=300`.

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
    theme: "inherit"
  });
</script>
```

## Widget Config

- `endpoint` - required API base URL, for example `https://blog.example.com/api`.
- `site` - public site id, default `store-1`.
- `siteKey` - optional public key if Admin -> Settings requires it.
- `widgetType` - `feed`, `post`, `subscribe`, or `badge`.
- `slug` - required for `post` widgets.
- `maxItems` - feed item count, 1 to 50.
- `showExcerpt` - `true` or `false`.
- `theme` - `light`, `dark`, or `card`, plus `inherit` to use your page's own font and colors with no box; default is `light`.
- `accent` - hex accent color for buttons, the badge count, and focus rings; default is ink `#15130f` (dark theme uses ember `#f97316`).
- `locale` - date formatting locale, default `en`.
- `container` - optional CSS selector for manual placement.

Feed responses include additive pagination fields (`items`, `page`, and `hasMore`) while keeping the original `posts` array for older embeds. Posts also expose `reading_minutes` and `reading_time` when the body is long enough to make a reading estimate useful.

If the backend returns no posts the feed widget renders an accessible "No posts yet" state. Failed fetches render "Couldn't load posts", emit the documented `error` event, and log the cause to the browser console for whoever installs it, for example `TinyBlog: this website is not allowed yet. In Admin -> Add to site, allow https://your-store.example`. Refused API calls return `{"code":"origin_not_allowed"}` (or `unknown_site` / `invalid_site_key`) so the widget can explain them.

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
- `GET /archive?month=2026-07` - month-filtered archive listing.
- `GET /about` - privacy-friendly about page.
- `GET /feed.xml` - RSS feed.
- `GET /feed.json` - JSON Feed 1.1 feed.
- `GET /sitemap.xml` - sitemap.
- `GET /llms.txt` - plain-text blog summary and published post list for LLM crawlers.
- `GET /embed/feed.html?site=SITE&limit=5` - sanitized HTML fragment of recent posts for server-side includes (`excerpt=0`, `css=0` optional).
- `GET /subscribe/confirm/{token}` - double opt-in confirmation link.
- `GET /unsubscribe/{token}` - one-click unsubscribe link.

JSON API:

- `GET /api/posts?site=SITE&limit=10&page=1`
- `GET /api/posts/{slug}?site=SITE`
- `POST /api/subscribe`
- `GET /api/feed.xml`

Admin:

- `GET /admin` - login/register, then Home (setup checklist, drafts and scheduled posts, stats, top posts).
- `GET /admin?action=posts` - all posts with All / Published / Scheduled / Drafts filters and title search.
- `GET /admin?action=edit[&id=N]` - editor: Write / Preview / Split views with a server-rendered preview (the same sanitizer readers get), Draft / Publish now / Schedule in your local time, featured image and inline images from a media picker, excerpt counter, search preview, and a "restore unsaved changes" banner.
- `GET /admin?action=embed` - Add to site: allowed websites, refused requests with one-click Allow, layout options, platform steps, copy-ready snippet, live preview, and "Seen on ..." verification.
- `GET /admin?action=subscribers` - Confirmed / Waiting / Unsubscribed filters, CSV export of confirmed subscribers, copy confirm link, delete.
- `GET /admin?action=settings[&tab=email|backup]` - General, Email (with a test email), Users, Embed & sites, Backup & health.
- Admin post actions are CSRF-protected form posts.
- `TB_ADMIN_EMAIL` + `TB_ADMIN_PASSWORD` in `.env` create or update an admin account from a backend-editable file; the login link remains `/admin`.
- Posts can be created, edited, published/unpublished, and deleted from the browser admin UI.
- Posts are saved as a draft, published now, or scheduled. Scheduled posts stay hidden from public pages, RSS, sitemap, the fragment, and the JSON API until their time; no cron is needed, and caches revalidate when a scheduled post goes live. Duplicate titles get a unique slug (`my-post-2`) instead of an error, and saving keeps you in the editor.
- Posts can be pinned, which moves them to the top of the home/API listing.
- Media uploads require alt text and have CSRF-protected delete controls.
- Settings includes JSON export/import for posts, settings, confirmed subscribers, and media references.

## Sample Content

Create the first admin, then click "Load sample posts" on Home. It creates:

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
node --check tinyblog-widget.js
```

Local development with PHP's built-in server (serves the widget, assets, and uploads; refuses `data/`, `.env`, dotfiles, and tests):

```bash
php -S localhost:8080 tinyblog.php
# then open http://localhost:8080/admin
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
- Confirm a blocked Origin receives 403 with `"code":"origin_not_allowed"` from `/api/posts`, shows under "Refused requests" in Admin -> Add to site, and loads after clicking Allow.
- Schedule a post a few minutes ahead in your local time; confirm it appears on the blog, feeds, widget, and `/embed/feed.html` once the time passes.
- Upload jpg/png/webp/gif with alt text (uploads without alt text are refused), confirm a `.txt` or `.php` upload is rejected, then delete an uploaded image and confirm the DB row and file are removed.
- Submit subscribe form and verify rate limit after repeated attempts.
- Subscribe, confirm via the token link (copy it from Admin -> Subscribers when emails are off), then unsubscribe via the one-click link; confirmed subscriber counts, CSV export, and JSON export should exclude unconfirmed/unsubscribed rows. The subscribe API never returns the confirm link to the browser.
- Visit a canonical post twice from the same IP/day and confirm the privacy-friendly view counter increments once; `DNT: 1` and obvious bot UAs should not increment.
- Export JSON, import into a fresh install, and confirm posts/settings/confirmed subscribers/media references are restored.
- Confirm fenced code blocks render as escaped `<pre><code class="language-x">` with a copy button and no external highlighter.
- Confirm `/feed.xml`, `/feed.json`, `/sitemap.xml`, and canonical post meta tags work.
- Confirm `/api/posts` or `/feed.xml` return `304` with matching conditional headers.
- Confirm Markdown raw HTML is escaped, not executed.

## How To Scale

Move to Postgres when multiple authors, higher write volume, or large subscriber lists make SQLite locking noticeable. Move media to object storage when uploads grow beyond a small shared-hosting disk. Move to Node or a headless CMS when you need webhooks, background jobs, multi-site tenancy, or a richer editorial workflow.
