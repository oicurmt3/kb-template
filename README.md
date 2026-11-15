# WANDA — WA Workers Compensation Chatbot

WANDA is a plain PHP + vanilla JS chatbot that answers Western Australian workers compensation questions. With **WorkCover** on, answers are grounded in uploaded Act, Regulations, and WorkCover guidance via RAG (retrieval-augmented generation) through DeepSeek.

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
| **WorkCover** | RAG against uploaded docs (Act/Regs/guides) |
| **HQ** | High-quality precise language (off = Basic / simple explain) |
| **Deep** | Extra reasoning pass before the answer |
| **Web** | Live search/fetch limited to admin-allowed hosts |
| **Tags** | Focus retrieval on document tags |
| **URL / File** | Attach one URL or pasted file text for this turn |
| **Sources** | Show citation chips after RAG answers |
| **Related** | Follow-up suggestion chips |

Accuracy pipeline (rewrite → synonyms → tags → scored retrieval → grounded prompt → optional HQ check) is documented in **[MDfiles/ACCURACY.md](MDfiles/ACCURACY.md)**.

## Docs

| File | Purpose |
|------|---------|
| [handover.md](handover.md) | Decisions, quirks, do-not-break |
| [MDfiles/ACCURACY.md](MDfiles/ACCURACY.md) | How answers stay accurate; every setting’s effect |
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
| `admin.php` | Ingest, docs, retrieval test, UI/settings |
| `api/chat.php` | Streaming chat + RAG |
| `includes/Retrieval.php` | Search, rewrite, quality, grounding, HQ verify |
| `includes/Settings.php` | Admin-editable feature flags and limits |
| `includes/config.php` | Secrets (gitignored) |

## Security (summary)

- Secrets only in gitignored `config.php`
- CSRF on POSTs; per-IP rate limits on login/chat
- `includes/`, `sql/`, `storage/` blocked by `.htaccess`
- Conversations scoped to opaque session cookie
