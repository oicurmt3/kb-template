# Security

## Secrets

- Live only in gitignored `includes/config.php` (or host env if you wire that later)
- Never commit API keys, password hashes of real admins into example files with real values, DB dumps, or `.env`
- DeepSeek key used server-side only

## Auth

- Admin: bcrypt `password_hash` / `password_verify` via `login.php` / `api/auth.php`
- Public chat: opaque session cookie (`HttpOnly`, `Secure` on HTTPS, sensible `SameSite`)
- Conversations filtered by `session_id` — no cross-session reads

## CSRF & rate limits

- CSRF tokens on admin and sensitive POSTs (including settings import)
- Per-IP rate limits: login, chat, web_search, web_fetch, admin export/import (files under `storage/ratelimit/`)

## Output & input

- Escape dynamic HTML in PHP-rendered pages
- Chat UI uses `textContent` for user-influenced strings where possible
- Message length capped; upload size capped in config

## Web fetch

- Only hosts in admin/config allowlist
- Do not enable unrestricted DeepSeek open-web search tools

## Admin export / import

- Export/import requires admin session (`admin_require`)
- Settings import only writes **whitelisted** keys through `AppSettings::importSettingsExport` (validated save helpers) — unknown keys ignored
- Chunks export can be large; treat downloads as sensitive corpus backups
- Do not add unauthenticated download links

## Filesystem hardening

`.htaccess` denies direct web access to:

- `includes/`
- `sql/`
- `storage/`

Keep uploads non-executable; serve through controlled paths only.

## Live App / publish note

If publishing elsewhere: no secrets in the bundle, no live data dumps under `storage/` or `data/`.
