# TinyBlog Widget Security Notes

Repository: https://github.com/tanzir71/tinyblog

TinyBlog Widget is intentionally small, but it still treats publishing, embeds, uploads, and subscriber data as security-sensitive.

## Implemented Safeguards

- SQL injection mitigation: all runtime database reads and writes use PDO prepared statements.
- XSS mitigation: `htmlEscape()` is used for user-generated text in HTML, Markdown is rendered through a limited safe renderer, and the widget sanitizes `content_html` again before insertion.
- CSRF mitigation: all admin POST actions require a session CSRF token.
- Authentication: first-admin registration only when no users exist, `password_hash()` for credentials, `password_verify()` on login, and `session_regenerate_id()` after login.
- Session safety: HTTP-only cookies, `SameSite=Lax`, secure cookies on HTTPS, and a 30-minute idle timeout.
- CORS: API requests with an `Origin` header must match the same origin or an Admin-configured allowlist.
- Public site key: optional `siteKey` enforcement for API reads can be enabled in Settings.
- Rate limiting: admin login and subscribe endpoints are rate-limited by hashed IP.
- Subscriber consent: new subscribers are unconfirmed until they use a random confirmation token; one-click unsubscribe uses a separate random token and is idempotent.
- Upload safety: only jpg, png, gif, and webp images are allowed; MIME type is checked with `fileinfo`; filenames are randomized; upload size is limited to 2 MB.
- Media management: uploaded images carry alt text and delete actions are admin-only, CSRF-protected, and constrained to the configured upload directory.
- Upload execution guard: `uploads/.htaccess` denies common script extensions and disables PHP execution where supported.
- Security headers: HTML/API responses include CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy`.
- Authorization: settings, subscriber lists, and sample seeding require admin role; post editing is scoped to the configured site.
- Publication safety: public pages, RSS, sitemap, and API reads use the same prepared-statement visibility clause so drafts and future-dated posts stay private until eligible.
- Search safety: FTS5 is detected at runtime and fed with sanitized terms; hosts without FTS5 keep the prepared-statement `LIKE` fallback.
- Config hygiene: `.env` is optional, `.env.example` documents safe keys, and `.gitignore` excludes `.env`, logs, SQLite databases, and uploaded media.
- Privacy: no third-party analytics or trackers are included by default. Subscriber records store email, opt-in time, status, and a hashed IP for abuse reduction.
- View counts: canonical/API post views are counted with a short-lived hashed IP/day token, never raw IPs; `DNT: 1` and obvious bot user agents are ignored.
- Structured data: canonical post JSON-LD is emitted from escaped server-side values using JSON hex escaping; it does not execute user Markdown or raw HTML.
- Backups: JSON export/import is admin-only and CSRF-protected; imported post HTML is regenerated from Markdown instead of trusted from backup files.
- Conditional GET: API/RSS/JSON feed `ETag` and `Last-Modified` headers are derived from post update timestamps and do not weaken CORS checks.
- Widget resilience: empty and failed feed states render accessible status text instead of leaving embeds blank, and failed requests emit the documented `error` event.
- Error handling: user-facing API errors are generic; PHP errors go to `TB_LOG_FILE` and the app log table when possible.

## Production Checklist

- Enable HTTPS before creating the first admin.
- Use a unique admin password of at least 12 characters.
- If you set backend-editable credentials in `.env`, keep the file private, protected by `.htaccess` or outside the public web root, and use `TB_ADMIN_PASSWORD_HASH` instead of plaintext when practical.
- Move `data/` outside the public web root when your host allows it.
- Keep `.htaccess` and `uploads/.htaccess` in place.
- Restrict Admin -> Settings -> Allowed widget origins to exact domains.
- Set `Canonical base URL` to the HTTPS URL users should see.
- Keep draft and scheduled-post checks in the smoke suite before changing listing/API queries.
- Back up `data/tinyblog.db` and `uploads/` regularly.
- Keep PHP patched and remove unused hosting apps from the same account.
- Consider enabling the public `siteKey` requirement if the widget is embedded in a limited set of controlled sites.
- Add a real captcha or email verification if subscribe attempts become noisy.

## Rotating Keys

- Public site key: in Admin -> Settings, disable "Require public siteKey", save, then manually update the `public_site_key` value in the SQLite `settings` table with a new random value and re-enable the setting. Update embeds that include `siteKey`.
- Admin credentials: edit `TB_ADMIN_EMAIL` and `TB_ADMIN_PASSWORD` in the private `.env` file, then log in at `/admin`. For stronger file hygiene, set `TB_ADMIN_PASSWORD_HASH` to a PHP `password_hash()` value instead of storing plaintext.
- CORS origins: rotate by removing old origins from Admin -> Settings -> Allowed widget origins and saving.

## Logging

Runtime/security errors are written to `data/tinyblog.log` by default. To move or reduce exposure, copy `.env.example` to `.env` and set:

```text
TB_LOG_FILE=/home/USER/private/tinyblog.log
TB_ADMIN_EMAIL=owner@example.com
TB_ADMIN_PASSWORD_HASH=<paste-output-of-password_hash>
```

To disable extra app-file logging, set `TB_LOG_FILE` to a path handled by your host's private logs and keep PHP display errors disabled. Do not enable public stack traces in production.

## Markdown Sanitization

Raw HTML entered in Markdown is escaped. The renderer emits only simple content elements such as paragraphs, links, emphasis, lists, images with safe URLs, blockquotes, and code. The widget repeats sanitization in the browser before inserting post HTML.

This is intentionally minimal. If you need a richer Markdown dialect, use a well-maintained server-side Markdown library plus an HTML sanitizer with a strict allowlist.

## File Upload Guidance

- Keep the 2 MB limit unless you add image resizing.
- Do not allow SVG uploads unless you sanitize them with a dedicated SVG sanitizer.
- Do not serve uploads from a directory that can execute PHP.
- Periodically review uploaded media and delete unused files.

## CORS Guidance

TinyBlog Widget rejects cross-origin API calls unless the request origin matches the backend origin or appears in Settings. Add origins exactly:

```text
https://store.example.com
https://www.store.example.com
```

Do not use wildcard origins for production. The project intentionally does not ship with `Access-Control-Allow-Origin: *`.

## Backups

Back up:

- `data/tinyblog.db`
- `uploads/`
- `.htaccess`
- any local configuration changes

Store backups outside the public web root and test restoration occasionally.

## Moving Beyond SQLite

SQLite is excellent for a small blog feed. Move to Postgres or managed hosting when you need concurrent editorial workflows, high subscribe volume, audit logs, queues, webhooks, team permissions, or multi-tenant sites. At that point, keep the widget API contract stable and replace the storage layer behind it.
