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
        → optional WebFetch (always with WorkCover when web.enabled; else standalone Web)
        → RAG↔Web mix instructions
        → DeepSeek chatStream → SSE deltas
        → optional DeepSeek API thinking (Deep policy + toggle)
        → optional Faithfulness if slider > 0 and grounding (default off; ≥55 surgical rewrite only; skip Files stubs / attached Files)
        → persist assistant message + done event
```

## Data model (high level)

| Table | Role |
|-------|------|
| `conversations` | Per-session chat threads |
| `messages` | User/assistant turns; `blocks` JSON for citations/suggestions |
| `documents` | Uploaded/pasted corpus metadata, tags, source_url, ingest_mode |
| `chunks` | Searchable text + `chunk_index` / `chunk_key` / `header_path` + FULLTEXT |
| `settings` | Admin JSON/string overrides (UI, adaptive RAG, RAG↔Web mix, hosts, caps, …) |

Each chunk row is uniquely identifiable (`id` + `document_id` + `chunk_index` / `chunk_key`).

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
| `AppSettings` | Feature flags, mix, chat limits, settings import |

## Admin export / import

| Endpoint | Role |
|----------|------|
| `GET api/export.php?type=settings` | JSON download: `effective` + `raw` settings rows |
| `GET api/export.php?type=chunks` | JSON download: all documents + all chunks |
| `POST api/export.php` | Import settings only (`type=settings`, body `data`) |

## Modes

- `chat` — general assistant (`app.system_prompt`); optional standalone Web
- `rag` — WorkCover Docs mode (`app.rag_system_prompt` + retrieved excerpts + allowlisted web + mix)

See [ACCURACY.md](ACCURACY.md) for the RAG accuracy path.
