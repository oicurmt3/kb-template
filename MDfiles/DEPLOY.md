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
8. Admin → expand **Upload documents** → stage files → **Upload** Act/Regs/guides → Retrieval test → smoke chat with Docs & Web on.
9. Optional: **Export all settings** / **Export all chunks** after a good baseline so you can restore settings on another host.

## Existing DB upgrade (structure parse)

1. Run `sql/alter_rag_structure.sql` once (phpMyAdmin / mysql).
2. Deploy new `includes/` (Chunker, Ingest, StructureParse, PromptLibrary, SchemaCompat, `prompts/`), `api/documents.php`, `api/health.php`, `assets/admin.js`.
3. Confirm `api/health.php` has no `alter_rag_structure` hints.
4. For each corpus doc: Admin **Re-parse** → **Continue** until stage `parsed` → **Re-chunk** (docs are `processing` / out of search until Re-chunk).
5. Copy any new `rag.*` keys from `config.example.php` into live `config.php` if missing (`structure_parse`, token targets, etc.).

## SSE tips

If streaming stalls:

- Disable gzip / buffering for `api/chat.php` if the host compresses SSE
- Confirm `X-Accel-Buffering: no` is respected (already set in chat.php)

## Cron (optional)

Point a single cron at a small PHP script if you later automate retention pruning; today pruning also runs on new conversation creation via `AppSettings`.

## After code changes

Upload changed PHP/JS/CSS; hard-refresh the browser for assets. No build step.

## Backups

- Settings: Admin → Export all settings (JSON). Import on a fresh install after DB + config exist.
- Corpus: Export all chunks is a readable backup; re-ingest originals or restore DB for a full restore (chunk import not built).
- Always keep `includes/config.php` and DB dumps off public git.
