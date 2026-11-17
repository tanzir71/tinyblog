# Changelog

Repository: https://github.com/tanzir71/tinyblog

## 2026-11-17 (v0.2.0)

Upgrade plan phases 0-4: a professional landing page, a new identity, and an admin rebuilt around the jobs small businesses actually do (set up, write, publish, put it on the site).

Brand and landing
- New identity: lowercase `tinyblog` wordmark with an ember i-dot (`assets/wordmark.svg`) and a "b." app icon (`assets/logo.svg`, `apple-touch-icon.png`), plus a new 1200x630 `og.png`.
- Warm paper/ink/ember palette shared by the landing page, PHP app, admin, and widget, with matching dark mode. The PHP app still uses system fonts and loads nothing external.
- Rewrote `index.html` and `assets/site.css` from the Figma design: audience, how it works, setup, widget modes, features, real admin screenshots (now including Add to site), security, compare, scope, FAQ, and quick start. Kept every previous claim and link.
- Corrected DropInBlog pricing everywhere: Solo is $99/mo monthly or $79/mo billed yearly (checked November 2026).
- "Buy me a coffee" support callout at the top of every marketing page footer (https://buymeacoffee.com/tanzir).

Admin (Phase 1-3)
- Sidebar navigation by job: Home, Posts, Media, Subscribers, Add to site, Settings, with account and logout in the footer and a compact top bar on phones.
- Home: dismissible "Finish setting up" checklist, drafts and scheduled posts, 30-day views, confirmed subscribers, and top posts.
- Posts: All / Published / Scheduled / Drafts filters, title search, pagination, inline Publish now / Unpublish.
- Editor: Draft / Publish now / Schedule (entered in your local time), save keeps you in the editor with a clear status line, unique slugs instead of errors, validation errors keep your text, Write / Preview / Split with a server-rendered preview, featured image and inline images from a media picker, excerpt counter, search preview, and a "restore unsaved changes" banner instead of silent restores.
- Add to site: allowed websites with "Seen ... ago" status, refused requests with one-click Allow, layout/theme/accent options, platform steps for WordPress, Shopify, Squarespace, Webflow, Wix, and PHP include, live preview, copy button, and verification.
- Subscribers: Confirmed / Waiting / Unsubscribed filters, CSV export of confirmed subscribers, copy confirm link, delete.
- Settings: General / Email / Users / Embed & sites / Backup & health tabs; each tab saves only its own fields; color picker; time zone; From address; "Send a test confirmation to me"; site id change that moves existing content.
- Media: alt text is now required; dimensions and sizes shown; clearer empty and error states.

Backend
- New `embed_seen` table (schema v4) records which origins load the widget, throttled and capped. Migrations now run once per schema version instead of on every request.
- Upgrading installs that still use the old default accent (`#2436d4`) switch to ember `#c2410c`; any other accent you picked is kept.
- CORS refusals return `code: origin_not_allowed`; unknown site and bad siteKey return `unknown_site` / `invalid_site_key`.
- Security: the subscribe API no longer returns the confirmation link to the caller.
- Caching: ETags change when a scheduled post goes live or a post is deleted.
- `GET /embed/feed.html`: sanitized, cacheable HTML fragment for server-side includes.
- `php -S localhost:8080 tinyblog.php` works for local development and refuses private files.
- Public blog: readable dates with `<time>`, a reader-facing sidebar (search, RSS, JSON Feed, archive) instead of the API endpoint, skip link.

Widget
- `theme: "inherit"` uses the host page's fonts and colors; `widgetType: "badge"` adds a "What's new" button with an unread count.
- Failed loads log the cause to the console (origin not allowed, unknown site, missing siteKey, unreachable endpoint).
- New palette defaults (`accent: "#15130f"`, ember in dark mode). Size budget is now 23 KB unminified.

## 2026-07-07

- Added the Vercel marketing-demo artifact with `/api/posts`, `vercel.json`, GitHub Pages exclusions, and README deploy steps.
- Added styled 404/server-error pages with search and recent-post links, and made unknown routes return 404.
- Added media width/height storage, lazy dimensioned post rendering, and backup/widget support for image dimensions.
- Added a read-only Settings health panel for PHP, SQLite, FTS5, writable directories, HTTPS, `.env`, and `mail()` checks.
- Added the widget `theme: "card"` variant with a soft panel treatment and README configuration docs.
- Added month-filtered archive pages with a right-rail month list and month-aware pagination metadata.
- Added `/llms.txt` with a plain-text blog summary and published-post list for LLM crawlers.
- Added a landing-page admin screenshots section with optimized dashboard, editor, and media PNG assets.
- Swept static landing/docs/comparison pages for single-h1 structure, absolute social images, focus-visible coverage, and labeled demo-region semantics.
- Replaced landing-page filler section labels and duplicated stat claims with verifiable proof copy plus a direct source link.
- Added a DropInBlog comparison page and promoted it as the primary embed-product comparison, with the older Disqus page reframed as third-party embed widgets.
- Added a landing-page hero demo that mounts the real TinyBlog widget against static demo JSON.
- Added account password changes and admin user management for adding/deleting editor or admin users without server-file edits.
- Added global admin focus rings, notice semantics, table captions, button hover states, and centered auth card screens.
- Replaced the admin media table with a responsive thumbnail grid, Markdown copy buttons, and drag-over upload highlighting.
- Added the two-pane admin editor layout with Markdown toolbar buttons, live preview debounce, post settings rail, and Cmd/Ctrl+S save.
- Added dashboard stat cells, status badges, relative dates, empty state, and quick publish/draft row actions.
- Reworked the admin shell with a product top bar, text navigation, active section state, and a constrained content column.
- Added guarded favicon and light/dark theme-color metadata to public and admin PHP pages.
- Added configurable home-page heading and intro settings, with the blog title as the default hero heading.
- Added shared-token `prefers-color-scheme` dark mode for public and admin PHP surfaces.
- Ported the marketing design tokens into the PHP app and widget defaults so public, admin, and embed surfaces share one palette.
- Removed Google Fonts from the PHP app/admin, tightened CSP font/style sources, and documented the system-font/no-tracker deployment claim.

## 2026-07-06

- Documented auth-flow caveats on the landing docs and setup surface: first-admin timing, plaintext `.env` password risk, recovery credential cleanup, and HTTPS enforcement.
- Added optional `.env` admin credential sync (`TB_ADMIN_EMAIL`, `TB_ADMIN_PASSWORD`, and `TB_ADMIN_PASSWORD_HASH`) so credentials can be edited from a private backend file and used at `/admin`.
- Added CSRF-protected browser post deletion to complete the frontend create/read/update/publish/unpublish/delete content loop without cPanel.
- Updated README and SETUP guidance to frame cPanel as install/recovery-only for normal publishing workflows.

## 2026-07-04

- Added landing-page polish: real SVG favicon, PNG Open Graph image, touch icon, skip link, dark color-scheme tokens, live widget demo hook, and copy-to-clipboard for the embed snippet.
- Added a public `docs.html` implementation guide and retargeted public Docs/Security/Deployment/Changelog links away from raw Markdown files.
- Added GitHub Pages publish exclusions so the static site artifact omits backend source, maintainer Markdown, tests, and runtime folders.
- Added scheduled publishing visibility checks, paginated public/API listings, reading-time metadata, widget empty/error states, and BlogPosting JSON-LD for canonical posts.
- Added double opt-in subscriber confirmation, one-click unsubscribe, optional PHP `mail()` confirmation delivery, FTS5 search with LIKE fallback, related posts, pinned posts, media alt text/delete, privacy-friendly view counting, JSON Feed, JSON backup import/export, code-block copy buttons, and API/RSS conditional GET headers.
- Updated smoke checks, README manual QA, and security notes for the new publishing and embed behavior.

## 2026-05-14

- Added focused PHP security hardening: CSP/security headers, `.env`-style configuration, file logging, admin role checks, object-scoped post editing, and site-scoped subscriber listing.
- Updated docs, setup, security checklist, repo links, and RoughCut-inspired landing page.
- Added smoke/security scan helpers and curl-based verification plan.
