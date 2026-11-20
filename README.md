# WANDA — WA Workers Compensation Chatbot

WANDA is a plain PHP + vanilla JS chatbot that answers Western Australian workers compensation questions. With **Docs & Web** on, answers are grounded in uploaded Act, Regulations, and WorkCover guidance via RAG (retrieval-augmented generation) through DeepSeek, plus allowlisted live web.

## Stack

- PHP 8+ (procedural), PDO / MariaDB-MySQL
- Vanilla JavaScript + custom CSS (no build step, no Node)
- DeepSeek Chat Completions (streaming SSE)
- Shared cPanel hosting over HTTPS

## Quick setup

1. Create a MySQL/MariaDB database.
2. Copy project files into the webroot.
3. Copy `includes/config.example.php` → `includes/config.php` and fill DB, DeepSeek API key, and admin password hash.
4. Visit `/install.php` — create tables, test API key, generate password hash, lock installer.
5. Chat at `/`. Admin at `/login.php` → `/admin.php`.

## What users see

| Control | Role |
|---------|------|
| **Docs & Web** | Docs mode: uploaded RAG **plus** allowlisted live web (blend via admin RAG↔Web mix) |
| **HQ** | Richer / more precise language only (off = Basic / simple explain) |
| **Deep think** | DeepSeek API thinking (chain-of-thought) before the answer |
| **Web only** | Allowlisted live search/fetch — only when Docs & Web is **off** |
| **Tags** | Focus retrieval on document tags |
| **URL / File** | Inline Options: allowlisted URL; File = local text or DeepSeek OCR/Files for PDF/Office |
| **Sources** | Show citation chips after RAG answers |
| **Related** | Follow-up suggestion chips |

Accuracy pipeline (rewrite → synonyms → tags → scored retrieval → grounded prompt → optional **Faithfulness** audit) is documented in **[MDfiles/ACCURACY.md](MDfiles/ACCURACY.md)**.

**Roles:** HQ = style · Deep think = API thinking · Faithfulness = admin-slidered source audit (default **off**; surgical only at high degree) · Docs & Web = RAG + allowlisted web.

### Admin (summary)

- Collapsible cards (closed by default): chats, export/import, settings, upload, documents, chunks, retrieval test
- **Your assistant & prompts:** display copy plus plain / Docs & Web system prompts (Admin overrides `config.php` when non-empty); optional excerpt rules, follow-ups, mix-line overrides
- **Business presets** (`data/presets/`) apply bundled settings via Export/import; does not change uploaded documents
- **Export / import settings** JSON; **export all chunks** (no chunk import yet)
- Upload: stage files → **Upload** button; paste → **Add & chunk**
- Documents: **Re-parse** → **Continue** → **Re-chunk** (structure Markdown then chunk; run `sql/alter_rag_structure.sql` on existing DBs)
- Document **Source URL**: citation link; optional live fetch (Web settings Include/Exclude)
- Faithfulness slider, RAG↔Web mix, Adaptive RAG, Deep policy, UI features, hosts — see Accuracy / handover docs

## Docs

| File | Purpose |
|------|---------|
| [handover.md](handover.md) | Decisions, quirks, do-not-break |
| [MDfiles/ACCURACY.md](MDfiles/ACCURACY.md) | How answers stay accurate; every setting’s effect |
| [MDfiles/ADAPT.md](MDfiles/ADAPT.md) | Retarget this shell to any other business or entity |
| [MDfiles/PLAN-science-lab-coordinator.md](MDfiles/PLAN-science-lab-coordinator.md) | Plan: teacher ↔ lab-tech lesson/prep coordinator (not built) |
| [MDfiles/ARCHITECTURE.md](MDfiles/ARCHITECTURE.md) | Stack, data flow, storage |
| [MDfiles/DESIGN.md](MDfiles/DESIGN.md) | Theme and UI patterns |
| [MDfiles/SECURITY.md](MDfiles/SECURITY.md) | Auth, CSRF, rate limits |
| [MDfiles/DEPLOY.md](MDfiles/DEPLOY.md) | cPanel deploy |
| [MDfiles/TESTING.md](MDfiles/TESTING.md) | How to verify |
| [MDfiles/CHANGELOG.md](MDfiles/CHANGELOG.md) | Session-level changes |
| [MDfiles/CONTRIBUTING.md](MDfiles/CONTRIBUTING.md) | Branches / commits |
| [AGENTS.md](AGENTS.md) | Agent workflow for this repo |
| [AREA.md](AREA.md) | Folder ownership |
| [HANDOFF.md](HANDOFF.md) | Short session log |

## Key paths

| Path | Purpose |
|------|---------|
| `index.php` | Chat UI |
| `admin.php` | Collapsible admin: ingest, docs, retrieval test, settings, export |
| `api/chat.php` | Streaming chat + RAG |
| `api/export.php` | Admin export settings/chunks; import settings |
| `api/presets.php` | List / load business preset JSON (admin) |
| `data/presets/` | Bundled preset files for Apply preset |
| `includes/Retrieval.php` | Search, rewrite, quality, grounding, HQ verify |
| `includes/Ingest.php` / `Chunker.php` / `StructureParse.php` | OCR → structure parse → chunks + `search_text` |
| `includes/prompts/` | G*/L* parse/chunk/answer prompt templates |
| `includes/Settings.php` | Admin-editable feature flags, mix, limits, import |
| `includes/config.php` | Secrets (gitignored) |
| `sql/alter_rag_structure.sql` | Existing-DB columns for structure pipeline |

## Security (summary)

- Secrets only in gitignored `config.php`
- CSRF on POSTs; per-IP rate limits on login/chat
- `includes/`, `sql/`, `storage/` blocked by `.htaccess`
- Conversations scoped to opaque session cookie
