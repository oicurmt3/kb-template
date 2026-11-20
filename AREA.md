# Area ownership

| Area | Paths | Notes |
|------|-------|-------|
| Chat UI | `index.php`, `assets/chat.js`, chat-related CSS in `assets/style.css` | Composer, SSE client, sources/related |
| Chat API / RAG | `api/chat.php`, `includes/Retrieval.php` | Accuracy pipeline — see `MDfiles/ACCURACY.md` |
| Admin UI | `admin.php`, `assets/admin.js` | Collapsible cards, staged upload, docs, retrieval test, settings, export/import |
| Settings / auth | `includes/Settings.php`, `includes/auth.php`, `login.php`, `api/auth.php`, `api/settings.php`, `api/ui-config.php`, `api/export.php`, `api/presets.php`, `data/presets/` | Feature flags, caps, hosts, mix, assistant branding, presets, settings import |
| Ingest | `api/upload.php`, `includes/Ingest.php`, `includes/Chunker.php`, `api/documents.php`, `api/chunks.php` | Upload/paste/OCR/Files; chunks export via `api/export.php` |
| Web tools | `includes/WebFetch.php` | Allowed-host fetch/search |
| DeepSeek client | `includes/DeepSeek.php` | Shared API |
| Docs | `README.md`, `handover.md`, `MDfiles/*`, `AGENTS.md`, `AREA.md`, `HANDOFF.md` | User-requested only for edits |

Integration work (wiring chat ↔ settings ↔ retrieval) should be explicit in the user request.
