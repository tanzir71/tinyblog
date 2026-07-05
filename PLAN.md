# TinyBlog — Polish & Feature Plan (Codex Handoff)

Repository: https://github.com/tanzir71/tinyblog

## Purpose

This is an execution plan for Codex. It has two parts: (A) landing-page polish
and (B) a prioritized feature backlog. The north star is **tiny, minimal, but
feature-rich**: every addition must stay inside a single-file PHP + SQLite app
that runs on cheap shared hosting with no build step and no third-party runtime
dependencies. If a task needs npm, a framework, or a background worker, it does
not belong here — push it to the "Explicit non-goals" list.

The key product experience is **frontend content CRUD without cPanel**. After
the one-time install, an author must be able to create, read, update, publish,
unpublish, and delete content from TinyBlog's browser UI. cPanel, phpMyAdmin,
the hosting file manager, and direct SQLite edits are only acceptable for
installation/recovery chores, never for day-to-day publishing.

## Repo map (what exists today)

| File | Role |
| --- | --- |
| `tinyblog.php` | Backend, admin UI, public canonical pages, JSON API, RSS, sitemap. One file. |
| `tinyblog-widget.js` | Dependency-free embed script (feed / post / subscribe modes). |
| `index.html` | Marketing landing page (just restyled — see Part A). |
| `assets/` | `logo.svg`, `og-placeholder.svg`. |
| `data/`, `uploads/` | Runtime SQLite DB + media, protected by `.htaccess`. |
| `SETUP.md`, `SECURITY.md`, `README.md`, `CHANGELOG.md` | Docs. |
| `tests/` | `smoke.php`, `smoke.ps1`, `security_scan.ps1`. |

Key backend functions already present (grep `tinyblog.php`): `init_db`,
`sanitize_markdown`, `slugify`, `handle_api`, `render_home`, `render_post`,
`render_tag`, `render_search`, `render_rss`, `render_sitemap`, `render_admin`,
`save_post`, `upload_media`, `save_settings`, `seed_samples`, `rate_limit`,
`send_cors_headers`, `csrf_token`. Build on these; do not duplicate them.

---

# Part A — Landing page polish

The type scale and spacing were just reworked in `index.html`: a single spacing
scale (`--s-1`…`--s-9`), a fluid type scale (`--fs-display`, `--fs-h2`, …),
tightened display tracking, real hover/focus states, and the removal of the
`ch`-based mobile hacks. The sharp monochrome / hairline-border aesthetic is
intact. Remaining polish, in priority order:

1. **Replace the emoji favicon with the real mark.** `assets/logo.svg` already
   exists; wire it as `<link rel="icon" href="assets/logo.svg">` and add an
   apple-touch-icon. *Acceptance:* no emoji favicon; SVG mark shows in tab.
2. **Ship a real OG image.** `assets/og-placeholder.svg` is a placeholder and
   many scrapers ignore SVG. Export a 1200×630 PNG (`assets/og.png`) and point
   `og:image` / add `twitter:card`, `twitter:image` at it. *Acceptance:* valid
   preview in a card validator.
3. **Add a skip-to-content link** (`<a class="skip" href="#overview">`) and a
   visible `:focus-visible` ring on nav/buttons (ring is done; add the skip
   link). *Acceptance:* keyboard tab order starts with "Skip to content".
4. **Live widget demo.** The hero "Review Surface" is static markup. Replace it
   with an actual `tinyblog-widget.js` feed mounted against a demo endpoint (or
   a mocked local JSON) so the page dogfoods the product. Gate behind a
   `data-demo-endpoint`; fall back to the static markup if unset. *Acceptance:*
   real widget renders when an endpoint is configured, static fallback otherwise.
5. **Copy-to-clipboard on the embed snippet.** Small button on the `.code`
   block; pure JS, no deps. *Acceptance:* click copies, shows a 1s "Copied".
6. **`prefers-color-scheme` dark variant** of the landing page using the same
   token names (flip `--bg`/`--text`/`--line` under a media query). Keep it
   monochrome. *Acceptance:* legible in dark mode, borders still visible.

---

# Part B — Feature backlog

Each item lists **why**, **files**, **approach**, and **acceptance criteria**.
Priorities: **P0** = high value + low risk, do first; **P1** = strong value,
moderate work; **P2** = nice-to-have / larger. Keep each PR small and shippable.

## P0 — do first

### B0. Frontend content CRUD without cPanel
- **Why:** This is the core promise: a non-technical site owner should run the
  blog from TinyBlog's own browser UI after install, without logging into
  cPanel or editing files/database rows by hand.
- **Files:** `tinyblog.php` (`render_admin`, post form/actions, delete actions,
  media admin, settings admin), `README.md`, `SETUP.md`, `tests/smoke.php`.
- **Approach:** Audit the existing admin surface and close any CRUD gaps. Post
  create/edit/delete/publish/unpublish, media upload/delete, and basic settings
  changes must all be reachable from authenticated frontend routes with CSRF
  protection and clear success/error states. Keep all writes inside
  `tinyblog.php`; do not add a separate admin framework or require server-panel
  access. Docs should position cPanel as install-only and recovery-only.
- **Acceptance:** From a fresh install, an authenticated author can create a
  post, edit it, publish/unpublish it, delete it, upload media, delete media,
  and change site settings entirely in the browser. The README/SETUP flow never
  tells authors to use cPanel for normal content work. Smoke/manual QA covers
  the full frontend CRUD loop.

### B1. Draft vs. publish + scheduled publishing
- **Why:** Core blogging need; today posts appear to be immediately public.
- **Files:** `tinyblog.php` (`init_db` migration, `save_post`, `render_home`,
  `render_post`, `handle_api`).
- **Approach:** Add `status` (`draft|published`) and nullable `publish_at`
  (UTC) columns via an additive migration guarded by a schema-version check in
  `init_db`. Public/API queries filter `status='published' AND (publish_at IS
  NULL OR publish_at <= now())`. Admin post form gets a status select + datetime
  field. No cron needed — filtering on read is enough for shared hosting.
- **Acceptance:** Draft never appears in home/tag/search/RSS/API; a future
  `publish_at` post is hidden until its time; existing rows default to
  `published` with `publish_at = created_at`.

### B2. Pagination on home / tag / search
- **Why:** Listings currently render everything; unbounded as content grows.
- **Files:** `tinyblog.php` (`render_home`, `render_tag`, `render_search`,
  `handle_api` posts).
- **Approach:** `?page=N` with a fixed page size (setting, default 10) using
  `LIMIT/OFFSET` on prepared statements. Emit `rel="prev"/"next"` links for SEO.
  API returns `{items, page, hasMore}`.
- **Acceptance:** Page 2 shows the next slice; no duplicate/missing posts;
  prev/next links correct at boundaries.

### B3. Reading time + auto excerpt polish
- **Why:** Cheap UX wins for readers and the widget.
- **Files:** `tinyblog.php` (`excerpt_from_markdown`, `post_row`, `render_post`,
  API serializer), `tinyblog-widget.js`.
- **Approach:** Compute word count on save (store `reading_minutes`) or on
  render; expose in canonical page meta and API payload; widget shows it under
  the title when present.
- **Acceptance:** "~4 min read" appears on canonical page and feed rows; hidden
  when word count is trivial.

### B4. Widget: empty + error states, and `theme:"dark"` parity
- **Why:** README documents `theme: dark` and error events; the rendered states
  should be first-class, not blank.
- **Files:** `tinyblog-widget.js`.
- **Approach:** Add an accessible empty state ("No posts yet") and error state
  ("Couldn't load posts") with `role="status"`. Ensure the dark theme is a
  complete monochrome inversion driven by the existing CSS-variable overrides.
- **Acceptance:** Blocked/failed fetch shows the error state and fires the
  `error` event; empty feed shows the empty state; dark theme legible.

### B5. JSON-LD structured data on canonical post pages
- **Why:** Better search/social rendering, no visual change, low risk.
- **Files:** `tinyblog.php` (`render_post`, `render_page` head).
- **Approach:** Inject `Article`/`BlogPosting` JSON-LD (headline, datePublished,
  dateModified, author, image, mainEntityOfPage) built from existing fields and
  properly escaped.
- **Acceptance:** Rich-results test passes; no PHP notices; values match the
  page's OG tags.

## P1 — strong value, moderate work

### B6. Subscriber double opt-in + one-click unsubscribe
- **Why:** Deliverability and compliance; today subscribe is single-step.
- **Files:** `tinyblog.php` (`init_db`, subscribe API, new confirm/unsubscribe
  routes, `render_subscribers_admin`).
- **Approach:** Add `confirm_token`, `confirmed_at`, `unsub_token`. On subscribe,
  store unconfirmed + send a confirm link (PHP `mail()` behind a settings
  toggle; if mail disabled, surface the link in admin for manual send).
  `GET /subscribe/confirm/{token}` and `GET /unsubscribe/{token}` are tokened,
  no login. Exports and counts only count confirmed.
- **Acceptance:** New subscribers are `unconfirmed` until the link is used;
  unsubscribe works without login and is idempotent; tokens are single-purpose
  and unguessable.

### B7. FTS5 search with graceful fallback
- **Why:** Current search is `LIKE`-based; FTS5 is faster and ranks better.
- **Files:** `tinyblog.php` (`init_db`, `render_search`, API search).
- **Approach:** Detect FTS5 (`PRAGMA compile_options`); if present, maintain a
  contentless FTS table via triggers on the posts table and query with `MATCH`
  + `bm25()`. Fall back to the existing prepared `LIKE` path when FTS5 is
  unavailable (common on locked-down shared hosts).
- **Acceptance:** Identical results contract in both modes; injection probe
  (`' OR 1=1--`) stays safe; no error when FTS5 is missing.

### B8. Related posts + pinned posts
- **Why:** Increases session depth on a tiny site with almost no cost.
- **Files:** `tinyblog.php` (`render_post`, `render_home`, `init_db`).
- **Approach:** "Related" = posts sharing the most tags, excluding self, limit 3.
  Add a `pinned` boolean; pinned posts sort to the top of home above date order.
- **Acceptance:** Related list is tag-relevant and never includes the current
  post; pinned post leads the home listing.

### B9. Admin editor upgrades: live Markdown preview + media alt text + delete
- **Why:** The writing surface is the product's daily-driver; small ergonomics
  compound. Also closes an a11y gap (images need alt text).
- **Files:** `tinyblog.php` (`render_post_form`, `render_media_admin`,
  `upload_media`, `save_post`).
- **Approach:** Client-side preview pane reusing the same sanitizer contract
  (render via API or a JS mirror of the allowed subset — server remains the
  source of truth on save). Add an `alt` field to uploads; store and emit it.
  Add CSRF-protected media delete (unlink file + row) with a confirm.
- **Acceptance:** Preview matches saved output for the supported Markdown subset;
  images carry alt text end-to-end; deleting media is CSRF-protected and removes
  both file and DB row.

### B10. Privacy-friendly server-side view counter
- **Why:** Authors want "which posts land" without third-party analytics.
- **Files:** `tinyblog.php` (`init_db`, `render_post`, dashboard).
- **Approach:** Increment a per-post counter on canonical GET, de-duped by a
  short-lived hashed IP+day token (reuse `client_ip_hash`). Never store raw IPs.
  Show top posts on the dashboard. Exclude bots by simple UA/`DNT` heuristics.
- **Acceptance:** Counts increment once per visitor/day; no PII stored; bot
  hits largely excluded; dashboard shows a top-N list.

## P2 — nice-to-have / larger

### B11. JSON Feed (`/feed.json`) alongside RSS
- **Files:** `tinyblog.php` (`render_rss` sibling, route, head `<link>`).
- **Approach:** Emit jsonfeed.org v1.1 from the same query as RSS.
- **Acceptance:** Validates against the JSON Feed spec; linked from `<head>`.

### B12. Full data export / import (backup)
- **Files:** `tinyblog.php` (admin action), docs.
- **Approach:** Admin-only export of posts + settings + subscribers as a single
  JSON (media referenced by path). Import validates and upserts. This is the
  "how to move hosts" story without a DB client.
- **Acceptance:** Export → fresh install → import reproduces content; CSRF- and
  admin-gated; large exports stream without exhausting memory.

### B13. Syntax highlighting + copy button in rendered posts
- **Files:** `tinyblog.php` (`sanitize_markdown` fenced-code handling), small CSS.
- **Approach:** Server-side language class on `<pre><code class="language-x">`
  and a tiny, self-hosted highlighter (or CSS-only token styling to stay
  dependency-free). No CDN. Add a copy button like the landing page.
- **Acceptance:** Code blocks are readable and escaped (never executed); copy
  works; no external network calls.

### B14. Optional ETag / conditional GET on API + RSS
- **Files:** `tinyblog.php` (`handle_api`, `render_rss`).
- **Approach:** Emit `ETag`/`Last-Modified` from max post `updated_at`; honor
  `If-None-Match`/`If-Modified-Since` with `304`. Cuts bandwidth for embeds.
- **Acceptance:** Unchanged content returns `304`; changed content returns `200`
  with a new ETag; CORS behavior unchanged.

---

## Suggested sequencing

1. **Landing polish A1–A3** (favicon, OG, a11y) — trivial, ship immediately.
2. **B0 → B1 → B2 → B3** — the authoring backbone (frontend CRUD,
   status/schedule, pagination, reading time). Do B0/B1 first; several later
   items assume the admin CRUD loop and `status`.
3. **B4 + B5** — widget robustness and structured data.
4. **B6, B7, B8, B9, B10** — pick per appetite; B9 is the biggest UX lever.
5. **P2** as capacity allows.

## Conventions for Codex (guardrails)

- **One file stays one file.** New backend logic goes in `tinyblog.php` as small
  functions near their peers. No new PHP dependencies, no Composer.
- **No cPanel-dependent publishing workflow.** Daily content operations must be
  exposed through TinyBlog's authenticated frontend UI. cPanel/file-manager/DB
  access can be documented for install, migration, or recovery only.
- **No build step, no framework, no CDN runtime deps** in the widget or pages.
  Vanilla JS only; keep `tinyblog-widget.js` dependency-free.
- **Migrations are additive and idempotent.** Guard every schema change with a
  version/column-exists check inside `init_db` so re-running is safe. Default
  new columns so existing rows keep working.
- **Security is non-negotiable.** All writes stay CSRF-protected; all SQL stays
  in prepared statements; all output stays escaped; uploads stay image-only;
  new public routes respect the CORS allowlist and `rate_limit`. New tokens
  (confirm/unsub) must be single-purpose, random, and unguessable.
- **Every feature ships with a test hook.** Extend `tests/smoke.php` and the
  README "Manual QA checklist"; add a curl example for any new route.
- **Backward-compatible API.** Additive JSON fields only; never rename or remove
  existing keys the widget relies on.
- **Update docs in the same PR.** README routes/config, SECURITY.md if the
  surface changes, and a dated `CHANGELOG.md` entry.

## Explicit non-goals (keep it tiny)

Multi-tenant admin, WYSIWYG rich-text engine, comment system, background job
queue/cron dependency, Postgres/MySQL, npm/webpack build, external analytics or
tracking, and email-newsletter blasting beyond simple opt-in confirmation. If a
request pulls in any of these, it belongs in the README's "How To Scale"
section, not in this app.

## Definition of done (per task)

`php -l tinyblog.php` clean · `tests/smoke.php` passes · manual QA item added
for browser-based content CRUD where relevant · no new dependencies · docs +
CHANGELOG updated · CSRF/CORS/prepared-statement invariants verified for any
new route.
