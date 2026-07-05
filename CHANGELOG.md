# Changelog

Repository: https://github.com/tanzir71/tinyblog

## 2026-07-06

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
