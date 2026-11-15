# Architecture

## Stack

- PHP 8+ procedural endpoints under `api/`
- MariaDB via PDO (`includes/db.php`)
- Vanilla JS/CSS (`assets/`)
- DeepSeek HTTP APIs (chat stream, flash complete, OCR, Files parse, search/fetch)

No frameworks, no Node build.

## Request flow (chat)

```
Browser (index.php + chat.js)
    → POST api/chat.php (JSON)
        → session cookie + rate limit
        → resolve UI features (Settings)
        → if WorkCover: Retrieval → system prompt excerpts
        → optional WebFetch / extra context
        → DeepSeek chatStream → SSE deltas
        → optional HQ verify
        → persist assistant message + done event
```

## Data model (high level)

| Table | Role |
|-------|------|
| `conversations` | Per-session chat threads |
| `messages` | User/assistant turns; `blocks` JSON for citations/suggestions |
| `documents` | Uploaded/pasted corpus metadata, tags, source_url, ingest_mode |
| `chunks` | Searchable text + header_path + FULLTEXT |
| `settings` | Admin JSON/string overrides (UI, adaptive RAG, hosts, caps) |

## Storage on disk

| Path | Role |
|------|------|
| `storage/uploads/` | Original uploads |
| `storage/ratelimit/` | IP rate-limit counters |
| `storage/install.lock` | Locks installer |
| `includes/config.php` | Secrets (gitignored) |

## Modules

| Module | Responsibility |
|--------|----------------|
| `Retrieval` | Query prep, search, scoring, context, HQ verify |
| `Ingest` / `Chunker` | File/paste → documents + chunks |
| `DeepSeekClient` | API wrappers |
| `WebFetch` | Host-restricted search/fetch + length instructions |
| `AppSettings` | Feature flags and admin overrides |

## Modes

- `chat` — general assistant (`app.system_prompt`)
- `rag` — WorkCover (`app.rag_system_prompt` + retrieved excerpts)

See [ACCURACY.md](ACCURACY.md) for the RAG accuracy path.
