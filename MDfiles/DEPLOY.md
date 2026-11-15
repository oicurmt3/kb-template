# Deploy (cPanel)

## Target

Shared cPanel hosting over HTTPS. Webroot = project root (contains `index.php`, `.htaccess`, `api/`, `assets/`, `includes/`).

## Steps

1. Create MariaDB database + user; note credentials.
2. Upload project files (FTP/File Manager/Git deploy).
3. On the server: copy `includes/config.example.php` → `includes/config.php`; set DB, DeepSeek key, admin hash.
4. Ensure PHP 8+ and `pdo_mysql` are enabled.
5. Visit `https://your-domain/install.php`:
   - Create/verify tables
   - Test DeepSeek key
   - Generate admin hash if needed
   - Lock installer
6. Confirm `.htaccess` is active (Apache + `AllowOverride`).
7. Open `/` and `/login.php` over HTTPS.
8. Admin → upload Act/Regs/guides → Retrieval test → smoke chat with WorkCover on.

## SSE tips

If streaming stalls:

- Disable gzip / buffering for `api/chat.php` if the host compresses SSE
- Confirm `X-Accel-Buffering: no` is respected (already set in chat.php)

## Cron (optional)

Point a single cron at a small PHP script if you later automate retention pruning; today pruning also runs on new conversation creation via `AppSettings`.

## After code changes

Upload changed PHP/JS/CSS; hard-refresh the browser for assets. No build step.
