# Changelog

Repository: https://github.com/tanzir71/tinyblog

## 2026-07-04

- Added landing-page polish: real SVG favicon, PNG Open Graph image, touch icon, skip link, dark color-scheme tokens, live widget demo hook, and copy-to-clipboard for the embed snippet.
- Added a public `docs.html` implementation guide and retargeted public Docs/Security/Deployment/Changelog links away from raw Markdown files.
- Added scheduled publishing visibility checks, paginated public/API listings, reading-time metadata, widget empty/error states, and BlogPosting JSON-LD for canonical posts.
- Added double opt-in subscriber confirmation, one-click unsubscribe, optional PHP `mail()` confirmation delivery, FTS5 search with LIKE fallback, related posts, pinned posts, media alt text/delete, privacy-friendly view counting, JSON Feed, JSON backup import/export, code-block copy buttons, and API/RSS conditional GET headers.
- Updated smoke checks, README manual QA, and security notes for the new publishing and embed behavior.

## 2026-05-14

- Added focused PHP security hardening: CSP/security headers, `.env`-style configuration, file logging, admin role checks, object-scoped post editing, and site-scoped subscriber listing.
- Updated docs, setup, security checklist, repo links, and RoughCut-inspired landing page.
- Added smoke/security scan helpers and curl-based verification plan.
