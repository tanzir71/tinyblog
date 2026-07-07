# Changelog

Repository: https://github.com/tanzir71/tinyblog

## 2026-07-07

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
