# TinyBlog — Codex Handoff v2: UI Refinement & Confidence Plan

Repository: https://github.com/tanzir71/tinyblog
Supersedes: `PLAN.md` (v1 — its backlog B0–B14 is already implemented; see CHANGELOG.md and README.md)

---

## 0. How to run this file in a loop

This file is designed for autonomous iteration. Each run:

1. Read **§2 Guardrails** (never skip).
2. Find the first unchecked `[ ]` task in phase order (Phase 1 → 2 → 3 → 4 → 5).
3. Do ONLY that task. Small diffs. One task per commit.
4. Run the **verification gate** (§9). If anything fails, fix before moving on.
5. Check the box `[x]`, append a one-line dated entry to `CHANGELOG.md`, commit with message `phase-N: <task-id> <short description>`.
6. Stop. Next loop iteration picks up the next task.

If a task is ambiguous, pick the smallest interpretation that satisfies its acceptance criteria. Never expand scope mid-task.

### Local dev (works on Windows/macOS/Linux, no config)

```bash
php -S localhost:8000 tinyblog.php   # tinyblog.php acts as its own router
# open http://localhost:8000/admin → create first admin → Load sample posts
php -l tinyblog.php                  # lint
php tests/smoke.php                  # smoke tests
```

---

## 1. Context — what this app is

TinyBlog is an **embeddable, self-hosted blog**: one PHP 8 file (`tinyblog.php`, ~2,400 lines) + SQLite + one dependency-free embed script (`tinyblog-widget.js`). It serves:

- **Marketing site**: `index.html`, `docs.html`, `compare.html`, 4× `tinyblog-vs-*.html` (static, GitHub Pages).
- **Public blog**: `/`, `/post/{slug}`, `/tag/{tag}`, `/search`, `/archive`, `/about`, `/feed.xml`, `/feed.json`, `/sitemap.xml` — all rendered by `render_page()` + `css_base()` inside `tinyblog.php`.
- **Admin**: `/admin` — login, dashboard, post editor, media, subscribers, settings. Rendered by `render_admin()` + `admin_head()`.
- **JSON API**: `/api/posts`, `/api/posts/{slug}`, `/api/subscribe` — consumed by the widget.

Functionally the app is complete (drafts, scheduling, pagination, FTS5 search, reading time, JSON-LD, double opt-in, export/import, view counter, ETags). **The problem is presentation and trust**: the UI looks unfinished, the marketing site and the app don't share a design language, and the landing page shows no proof the product works.

### The core problem, stated bluntly

- **Three different design systems**: `assets/site.css` (marketing: warm paper `#f4f3ee`, JetBrains Mono labels, blue accent `#2436d4`) vs `css_base()` in `tinyblog.php` (public blog + admin: stark white, pure black, Inter only) vs widget CSS in `tinyblog-widget.js` (a third variant). A visitor who clicks from the landing page into a demo blog experiences a jarring downgrade.
- **The admin looks like an unstyled prototype**: bare tables, a column of identical black rectangle buttons, a plain `<textarea>` editor, no visual hierarchy, no status colors, no empty states, no dark mode.
- **Integrity bug**: the landing page and README promise "no external fonts on your blog / 0 trackers", but `render_page()` and `admin_head()` load **Google Fonts** on every public and admin page (lines ~1301–1302 and ~1855). This directly contradicts the product's #1 claim and is a GDPR problem in the EU.
- **No proof of life on the landing page**: static fake code cards, no screenshots, no live demo, no link to a running blog. "SYS · 01" section labels read as template filler.
- **Placeholder copy in the product**: the blog home hero is hardcoded to "Small posts, clean embeds." for every installation (line ~1415).

---

## 2. Guardrails (inherit from v1 — non-negotiable)

- **One file stays one file.** Backend logic lives in `tinyblog.php`. No Composer, no new PHP deps.
- **No build step, no framework, no CDN runtime deps.** Vanilla JS/CSS only. The widget stays dependency-free.
- **No cPanel-dependent publishing.** All daily content work happens in `/admin`.
- **Migrations are additive + idempotent**, guarded inside `migrate_schema()` with `column_exists()` checks.
- **Security invariants**: every write CSRF-protected (`require_csrf()`), all SQL prepared, all output through `htmlEscape()`, uploads image-only, public routes respect CORS allowlist + `rate_limit()`.
- **Backward-compatible API**: additive JSON fields only. Never rename keys the widget reads (`data.posts`, etc.).
- **Docs in the same commit**: README routes/config, SECURITY.md when the surface changes, dated CHANGELOG entry.
- **Explicit non-goals** (unchanged): multi-tenant admin, WYSIWYG engine, comments, cron/queue dependency, Postgres/MySQL, npm build, external analytics, newsletter blasting.
- **New in v2 — design tokens are law.** After Phase 1 lands, every color/space/type value in any surface must come from the shared token set (§3). No new hex values outside tokens.

---

## 3. Competitor analysis → what the plan must achieve

Researched July 2026. Two competitor groups matter.

### Group A — minimal blogging platforms (the aesthetic bar)

| Product | Model | What they nail (and TinyBlog must match) |
| --- | --- | --- |
| **Bear Blog** (bearblog.dev) | Hosted, free / ~$5 mo premium | Radical speed + "no JS, no tracking" story told *consistently* — the product itself is the proof. Landing page IS a Bear blog. |
| **Mataroa** (mataroa.blog) | Hosted $9/yr, open-source, self-hostable | Zero-decoration honesty: system fonts, dark mode, exports everywhere. Trust through austerity, applied everywhere. |
| **Pika** (pika.page) | Hosted, free tier | Warm, personable design; live example blogs linked from the landing page. |
| **Chyrp Lite / Bludit / HTMLy** (self-hosted PHP) | Free, OSS | Working **live demos** and admin **screenshots** on their sites; one-click installs via Softaculous. |

**Lesson A:** in this niche, credibility = *consistency* (claims match behavior on every page) + *proof* (a live demo you can click). Nobody in this group wins on feature count.

### Group B — embeddable blog widgets (the commercial wedge)

| Product | Price | Positioning |
| --- | --- | --- |
| **DropInBlog** | from **$49/mo** | "Embed a blog into your website in 3 minutes" — script tag + div, SEO-friendly. |
| **Superblog** | from $29/mo | Reverse-proxy blog for any stack; auto-SEO; `llms.txt` for AI discovery. |
| **inblog** | from $39/mo | Subdirectory hosting, SEO focus. |
| **Bloggle / Elfsight-style widgets** | ~$25/mo | Shopify/no-code embeds, often with third-party tracking. |

**Lesson B:** TinyBlog's direct commercial comparable charges **$49/month for the exact same job**. "The $0, self-hosted DropInBlog with no trackers" is the sharpest positioning available and the landing page barely says it. The `compare.html` pages target the wrong giants (Substack/Ghost/WordPress); the money comparison is **vs DropInBlog** (a stub exists as `tinyblog-vs-disqus-embed.html` — wrong target, Disqus is comments).

### Derived requirements (these drive the phases below)

1. Claims must be true on every surface → kill Google Fonts in the app (Phase 1).
2. One design language everywhere → shared tokens (Phase 1).
3. Live proof on the landing page → real widget demo + admin screenshots (Phase 2, Phase 5).
4. The admin must look like a product someone maintains (Phase 3).
5. Add a "vs DropInBlog" comparison and lead with the price wedge (Phase 2).
6. Cheap SEO/AI-discovery parity: `llms.txt` (Phase 4).

---

## 4. Phase 1 — Integrity & design-token unification (P0, do first)

### T1.1 — Remove Google Fonts from the PHP app `[x]`
**Files:** `tinyblog.php` (`render_page()` ~line 1298–1302, `admin_head()` ~line 1855).
**Do:** Delete all `fonts.googleapis.com` / `fonts.gstatic.com` references (4 occurrences across the two functions). Change `css_base()` font stack to system-first: `font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif`. Do NOT self-host font files (adds repo weight, violates "tiny").
**Accept:** `grep -c "googleapis" tinyblog.php` returns 0. Public pages and admin render with system fonts. README "no external fonts" claim is now true. Note: `index.html`/`docs.html` (marketing, served from GitHub Pages, not the user's blog) MAY keep Google Fonts — the claim is about the reader-facing blog. Add one clarifying sentence to README.

### T1.2 — Single design-token set shared by all three surfaces `[x]`
**Files:** `tinyblog.php` (`css_base()`), `assets/site.css`, `tinyblog-widget.js` (style block ~line 174).
**Do:** Adopt the marketing palette as canonical and port it into `css_base()` and the widget defaults:

```css
--paper:#f4f3ee; --panel:#faf9f5; --ink:#0a0a0a; --ink-soft:#2b2a27;
--muted:#6c6a62; --line:#dcd9d0; --line-strong:#0a0a0a;
--accent:#2436d4; --accent-soft:#eceaf9;
/* dark */
--paper:#131210; --panel:#1a1917; --ink:#f2f1ec; --muted:#9d9a90;
--line:#2c2a26; --accent:#9aa6ff; --accent-soft:#1e2033;
```

Keep the admin "accent color" setting working: it overrides `--accent` only (validate hex as today). Widget default `accent` changes from `#000000` to `--ink` equivalent; the `data-theme='dark'` block maps to the dark tokens.
**Accept:** Visual spot-check: landing page → public blog → admin → embedded widget all read as one product. No hex literals outside the token blocks (grep for `#[0-9a-f]{6}` in style strings and verify each is a token definition or the validated accent).

### T1.3 — `prefers-color-scheme` dark mode on public blog + admin `[x]`
**Files:** `tinyblog.php` (`css_base()`).
**Do:** Wrap the dark token overrides from T1.2 in `@media (prefers-color-scheme: dark){:root{...}}`. Audit every rule that hardcodes `#fff`/`#222`/`#202020` (e.g. `.panel`, `.excerpt`, `[data-copy-code]`, blockquote) and switch to tokens.
**Accept:** Both public post page and admin dashboard fully legible in OS dark mode; borders visible; code blocks readable; no white flash panels.

### T1.4 — Configurable blog-home hero (kill the hardcoded tagline) `[x]`
**Files:** `tinyblog.php` (`render_home()` ~line 1415, `render_settings_admin()`, `save_settings()`).
**Do:** Add settings `home_heading` (default: blog title) and `home_intro` (default: empty → hide the `<p>`). Render via `htmlEscape()`. Add both fields to Settings with helper text.
**Accept:** Fresh install shows the blog title as hero, not "Small posts, clean embeds." Settings round-trips both values. Empty intro renders no empty element.

### T1.5 — Favicon + basic head parity on app pages `[x]`
**Files:** `tinyblog.php` (`render_page()`, `admin_head()`).
**Do:** Emit `<link rel="icon" href="/assets/logo.svg">` and `<meta name="theme-color">` (paper color, both schemes) on public + admin pages. Guard: only when `assets/logo.svg` exists (`is_file(__DIR__ . '/assets/logo.svg')`).
**Accept:** Browser tab shows the TB mark on `/`, `/post/*`, `/admin`.

---

## 5. Phase 2 — Landing page: from claims to proof

### T2.1 — Live widget demo in the hero `[x]`
**Files:** `index.html`, new `demo/posts.json` (static), `tinyblog-widget.js` (no changes expected).
**Do:** Replace the static "code card" *adjacent* hero panel (keep the code card itself — it's good) with a real mounted widget: load `tinyblog-widget.js` from the repo and `TinyBlogWidget.init({ container:"#live-demo", endpoint:"demo", widgetType:"feed", maxItems:3 })` won't work against static hosting because the widget fetches `{endpoint}/posts`. So: add a tiny fetch-shim OR (preferred, zero widget changes) host `demo/posts` as a static JSON file and set `endpoint:"demo"` → verify the widget's `apiUrl()` produces `demo/posts?...` and GitHub Pages serves extensionless files; if it doesn't, name the file `demo/posts` with a `_headers`-equivalent or fall back to a 10-line inline mock of `window.fetch` scoped to the demo URL. Caption it: "This is the actual widget, rendering the actual JSON."
**Accept:** Landing page hero area renders a real feed via `tinyblog-widget.js` with 3 demo posts; JS console clean; graceful static fallback (`<noscript>` + error state) if fetch fails.

### T2.2 — Admin screenshots section `[x]`
**Files:** `index.html`, `assets/shot-admin-*.png` (Codex: generate by running `php -S`, seeding samples, and capturing at 1440px, light + dark).
**Do:** Add a "What the admin looks like" section after Features: 2–3 real screenshots (dashboard, editor with preview, media) in a bordered figure with mono captions. Lazy-load (`loading="lazy"`), width/height attributes set. NOTE: do this task AFTER Phase 3 lands so the screenshots show the refined admin.
**Accept:** Screenshots are of the real refined admin, crisp at 2x, < 150 KB each (use `optipng`/`pngquant` equivalent or export at quality that hits budget).

### T2.3 — "vs DropInBlog" comparison page + reposition compare row `[x]`
**Files:** new `tinyblog-vs-dropinblog.html` (copy structure from `tinyblog-vs-ghost.html`), `index.html` compare section, `compare.html`, footer links.
**Do:** Honest table: DropInBlog $49–$99/mo hosted vs TinyBlog $0 self-hosted; their managed convenience/support vs your ownership/no-tracker/no-per-seat. Make DropInBlog the FIRST card in the landing compare grid (it's the same product category; Substack/Ghost/WordPress follow). Keep the Disqus page but retitle its framing to "vs third-party embed widgets".
**Accept:** New page matches the design system, factually accurate (prices as of mid-2026, say "from $49/mo"), linked from landing + compare + footer.

### T2.4 — Proof strip + honest numbers `[x]`
**Files:** `index.html`.
**Do:** Replace the "SYS · 0N" filler labels with plain section labels ("How it works", "Setup", …) in the same mono style. In the stats strip, replace "$0 / MIT" duplication with verifiable facts: file count (`2 files to deploy`), backend size (`~110 KB, no deps`), `0 external requests on your blog` (true after T1.1), `5-min cPanel install`. Add a quiet link "Read the source — it's one file" → GitHub blob link.
**Accept:** Every number on the page is verifiable from the repo; no invented stats, no fake testimonials.

### T2.5 — Landing a11y + meta sweep `[x]`
**Files:** `index.html`, `docs.html`, compare pages.
**Do:** One pass: heading order (single `h1`), `:focus-visible` on all interactive elements, color-contrast check of `--muted` on `--paper` (must be ≥ 4.5:1 — current `#6c6a62` on `#f4f3ee` is ~4.6:1, verify), `aria-label` on the demo region, canonical URLs consistent (decide: GitHub Pages URL until a domain exists), `og:image` absolute URL (scrapers require absolute).
**Accept:** Lighthouse a11y ≥ 95 on index.html; OG validator renders card correctly.

---

## 6. Phase 3 — Admin UI: make it look maintained

Design direction: same spec-sheet aesthetic as the landing page — paper background, hairline borders, mono labels, one accent. Not a SaaS dashboard cosplay; a precise tool.

### T3.1 — Admin shell: header + nav + layout `[x]`
**Files:** `tinyblog.php` (`admin_head()`, `render_admin()`, `css_base()` admin rules).
**Do:** Replace the button-pile sidebar with a proper shell: top bar (TB mark + blog title + "View site" link + logout), left nav with *text links* + active state (underline/accent, `aria-current="page"`), content column max-width. Nav buttons stop looking like primary CTAs — reserve the solid-ink button style for the one primary action per screen.
**Accept:** Exactly one visually-primary button per admin screen; active nav item obvious; keyboard navigable; works at 360px (nav collapses to horizontal scroll row or simple stacked links — no JS hamburger needed).

### T3.2 — Dashboard: stats + status badges + relative dates `[x]`
**Files:** `tinyblog.php` (`render_dashboard_admin()`).
**Do:** Add a 3–4 cell stat strip (published count, drafts, confirmed subscribers, views 30d — all from existing tables). Posts table gets: status as a small badge (`draft` = outline, `published` = solid, `scheduled` = accent outline when `publish_at` future), pinned indicator, relative dates ("2d ago", title attr = full UTC), row hover, and per-row quick actions (Edit / View / Unpublish-Publish as small links). Empty state when no posts: bordered panel with "Write your first post" primary button + "Load sample posts" secondary.
**Accept:** Fresh install shows the empty state; seeded install shows badges/relative dates; all quick actions CSRF-protected forms or plain GET links to existing routes; no new SQL beyond simple prepared aggregates.

### T3.3 — Editor: two-pane preview + writing ergonomics `[x]`
**Files:** `tinyblog.php` (`render_post_form()` + admin CSS).
**Do:** At ≥1000px, editor becomes side-by-side textarea | live preview (existing preview JS already renders; make it live-on-input with 300ms debounce instead of toggle-only; keep toggle for narrow screens). Add above the textarea: a compact mono toolbar with B / I / code / link / image buttons that wrap selection with Markdown (pure JS, ~30 lines). Move Excerpt/hero/tags/date/status/pinned into a collapsible right rail or `<details>` "Post settings" so writing is the default focus. Autosave status stays. Add `Ctrl/Cmd+S` → submit form.
**Accept:** Typing updates preview live; toolbar inserts correct Markdown around selection; Cmd+S saves; mobile layout unbroken; no external editor libs.

### T3.4 — Media grid + upload polish `[x]`
**Files:** `tinyblog.php` (`render_media_admin()`).
**Do:** Replace the media table with a responsive thumbnail grid (square crop via CSS `object-fit`), each cell showing filename, alt text, a "Copy Markdown" button (`![alt](url)` to clipboard), and delete-with-confirm. Upload form gets a drag-over highlight (CSS `:has()`-free, simple JS class toggle) — still a normal `<input type=file>` POST underneath, no XHR required.
**Accept:** Grid at 3–4 cols desktop / 2 mobile; Copy Markdown works; delete still removes file + row; upload still image-only server-side.

### T3.5 — Forms, notices, focus states — global pass `[x]`
**Files:** `tinyblog.php` (`css_base()` + admin markup).
**Do:** Consistent field spacing, `:focus-visible` accent ring on every input/button/link, success notice (accent-soft bg) vs error notice (distinct, with `role="alert"`), table `<caption>` or aria-labels, button hover states, `aria-busy` on preview while rendering. Login/create-first-admin screens get the same shell treatment (centered narrow card, product mark, link back to site).
**Accept:** Tab through every admin screen — focus always visible; errors announced; login screen looks like the same product as the landing page.

### T3.6 — Account self-service: change password + manage users `[x]`
**Files:** `tinyblog.php` (`render_admin()` actions, new `render_account_admin()` / `render_users_admin()`), README.
**Why:** The login error says "Ask an admin to add users" but **no add-user UI exists**, and password change currently requires `.env` editing via cPanel — contradicting the no-cPanel promise. This is the one *functional* gap in this plan.
**Do:** Add "Account" (all roles): change own password (verify current, min 12, `password_hash()`, `session_regenerate_id()`). Add "Users" (admin only): list users, add user (email/name/role/temp password), delete user (not self, not last admin). All CSRF-protected prepared statements. Rate-limit password change attempts.
**Accept:** Password change works and old password stops working; second user can log in and sees no Settings/Subscribers nav (role checks already exist via `require_admin()`); deleting last admin is blocked; smoke test extended.

---

## 7. Phase 4 — Small features (each ≤ 1 loop iteration, minimal-stack safe)

- **T4.1 `[x]` `llms.txt`** — serve `GET /llms.txt` from `tinyblog.php`: blog title, description, canonical URL, and a plain list of post titles + URLs (published only). Competitor parity (Superblog markets this). *Accept:* valid llms.txt format, respects visibility rules, linked in README routes.
- **T4.2 `[x]` Archive by month** — `/archive` gains a right-rail month list (`strftime('%Y-%m', publish_at)` GROUP BY) linking to `/archive?month=2026-07` (validated `YYYY-MM`). *Accept:* prepared statements, pagination still works, `rel=prev/next` correct.
- **T4.3 `[x]` Widget "card" theme variant** — third built-in look (`theme:"card"`): soft panel bg, spacing per the token set; still CSS-variable overridable. *Accept:* README config table updated; no size bloat beyond +1 KB.
- **T4.4 `[x]` Settings health panel** — read-only checks on the Settings page: PHP version, SQLite version, FTS5 available?, `data/` & `uploads/` writable?, HTTPS on?, `.env` present?, mail() available?. Green/amber dot per row. *Accept:* no secrets displayed; renders fast; helps the shared-hosting beginner self-diagnose.
- **T4.5 `[ ]` Image niceties** — on upload, record width/height (`getimagesize()`), emit them on `<img>` in rendered posts (CLS fix) + `loading="lazy"` everywhere images render (public, admin grid, widget). *Accept:* additive `media` columns via `migrate_schema()`; old rows tolerate NULL dims.
- **T4.6 `[ ]` 404 + error pages match the design** — styled not-found page with search box and recent posts. *Accept:* correct 404 status code preserved.

---

## 8. Phase 5 — Deployment artifact: demo site on Vercel

**Reality check (important):** the PHP backend **cannot** run statefully on Vercel — serverless filesystems are ephemeral, so SQLite writes vanish. Do NOT attempt to deploy `tinyblog.php` to Vercel. The correct split:

- **Product hosting** (real users): any PHP shared host — that's the product's whole point. `SETUP.md` already covers it.
- **Vercel** (this phase): the *marketing site + live demo*, so the project has a fast public URL with a working widget demo — the "proof" pillar from §3.

### T5.1 — Create the Vercel demo artifact `[ ]`

Create these files at repo root (they're inert on shared hosting; `.htaccess` and `_config.yml` already exclude patterns — add these to `_config.yml` excludes):

**`vercel.json`**
```json
{
  "cleanUrls": true,
  "headers": [
    {
      "source": "/api/posts",
      "headers": [
        { "key": "Access-Control-Allow-Origin", "value": "*" },
        { "key": "Cache-Control", "value": "public, max-age=300" }
      ]
    }
  ]
}
```

**`api/posts.js`** (Vercel serverless function — demo data for the landing-page widget; this is the ONLY JS-runtime file in the repo and it never ships to shared hosting)
```js
// Demo endpoint: mirrors the shape of tinyblog.php /api/posts
// Verified against tinyblog-widget.js: feed rows read data.title, data.posts[],
// and per post: title, slug, canonical_url, hero_image_url, published_at,
// excerpt, reading_minutes.
const posts = [
  {
    title: "Why TinyBlog exists",
    slug: "why-tinyblog-exists",
    canonical_url: "https://github.com/tanzir71/tinyblog#readme",
    excerpt: "One PHP file, SQLite, and a one-line embed. The blog your shared host can actually run.",
    published_at: "2026-07-01T09:00:00Z",
    tags: ["updates"],
    reading_minutes: 3,
    reading_time: "3 min read"
  },
  {
    title: "Secure defaults on cheap hosting",
    slug: "secure-defaults",
    canonical_url: "https://github.com/tanzir71/tinyblog/blob/main/SECURITY.md",
    excerpt: "CSRF everywhere, prepared statements, image-only uploads, CORS allowlists.",
    published_at: "2026-06-24T09:00:00Z",
    tags: ["security"],
    reading_minutes: 4,
    reading_time: "4 min read"
  },
  {
    title: "Embed a feed on any page",
    slug: "embed-a-feed",
    canonical_url: "https://github.com/tanzir71/tinyblog#embed-snippets",
    excerpt: "Feed, single post, or subscribe box — one script tag, zero dependencies.",
    published_at: "2026-06-15T09:00:00Z",
    tags: ["how-to"],
    reading_minutes: 2,
    reading_time: "2 min read"
  }
];

export default function handler(req, res) {
  res.status(200).json({ title: "TinyBlog demo", posts, items: posts, page: 1, hasMore: false });
}
```

Then point the T2.1 hero demo at `endpoint: "/api"` when served on Vercel (and keep the static-JSON fallback for GitHub Pages — detect via `location.hostname`).

**Deploy steps for a beginner (put verbatim in README under "Demo site"):**
1. Fork the repo → vercel.com → "Add New Project" → import the fork.
2. Framework preset: **Other**. No build command. Output dir: root. Deploy.
3. Done — `index.html` is the site, `/api/posts` serves the demo feed.

**Accept:** `vercel.json` + `api/posts.js` committed; landing demo works on both GitHub Pages (static JSON) and Vercel (function); README documents both paths and explicitly says "the PHP app itself deploys to PHP hosting, not Vercel"; `_config.yml` excludes `api/` from the Pages build.

---

## 9. Verification gate (run every iteration)

```bash
php -l tinyblog.php                       # must be clean
php tests/smoke.php                       # must pass; EXTEND it when you touch backend behavior
node --check tinyblog-widget.js           # syntax check (or: php -r on Windows-less envs, skip if node absent)
grep -c "googleapis" tinyblog.php         # must be 0 after T1.1
```

Manual spot-checks (rotate, minimum two per iteration):
- `php -S localhost:8000 tinyblog.php` → create admin → seed → click through dashboard/editor/media/settings in light AND dark mode.
- Injection probe on `/search?q=%27%20OR%201%3D1--` returns safe results.
- Admin POST without CSRF token → 403.
- Widget demo on `index.html` renders and consoles clean.
- Keyboard-only pass on the screen you just touched.

Definition of done per task = its **Accept** block + this gate + docs/CHANGELOG updated + one commit.

---

## 10. Sequencing summary

1. **Phase 1** (T1.1→T1.5): integrity + tokens. Everything else builds on these.
2. **Phase 3** (T3.1→T3.6): admin refinement. Do BEFORE T2.2 screenshots.
3. **Phase 2** (T2.1, T2.3–T2.5, then T2.2 last): landing proof.
4. **Phase 4**: feature nibbles, any order.
5. **Phase 5**: Vercel demo artifact.

Progress: `[ ]` = todo · `[x]` = done. Codex: update this file's checkboxes as part of each commit.
