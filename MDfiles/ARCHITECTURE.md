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
        → if Docs (mode=rag): Retrieval → system prompt excerpts
        → if Web: WebFetch allowlisted search (independent of Docs)
        → optional turn File attach (api/context.php: local text / OCR / Files file_id)
        → RAG↔Web mix instructions when Docs + Web (or URL / doc-source web)
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
| `documents` | Corpus metadata: tags, source_url, ingest_mode, `domain_set`, version/as-at/`status_text`, `pipeline_stage` / `pipeline_state`, intake/sidecar JSON |
| `chunks` | Verbatim `text` + `header_path` + FULLTEXT `search_text`; optional content_type/flags/extra, denorm version/domain |
| `settings` | Admin JSON/string overrides (UI, adaptive RAG, RAG↔Web mix, hosts, caps, …) |

Each chunk row is uniquely identifiable (`id` + `document_id` + `chunk_index` / `chunk_key`).  
`chunks.embedding` BLOB is unused — DeepSeek has no embeddings API here; “embed” = contextual `search_text`.

## Ingest pipeline (corpus)

```
Upload / paste
  → OCR (DeepSeek) or local Markdown
  → optional G1 intake + G2 structure parse (page batches, resumable)
  → storage/markdown/*.md (+ sidecar on document)
  → Chunker: RAG:CHUNK markers OR header→size atomic split
     (optional model G4 when rag.model_chunker)
  → insert chunks + search_text templates → status ready
```

Admin migration: **Re-parse** (OCR + G2) → **Continue** until `parsed` → **Re-chunk** (markdown only).  
While `status=processing`, chunks are out of retrieval.

## Storage on disk

| Path | Role |
|------|------|
| `storage/uploads/` | Original uploads |
| `storage/markdown/` | Parsed Markdown used for chunking |
| `storage/pipeline/` | Temporary OCR page JSON for G2 resume (gitignored) |
| `storage/ratelimit/` | IP rate-limit counters |
| `storage/install.lock` | Locks installer |
| `includes/config.php` | Secrets (gitignored) |
| `includes/prompts/` | G*/L* ASCII prompt templates |

## Modules

| Module | Responsibility |
|--------|----------------|
| `Retrieval` | Query prep, search, scoring, context, L7/G7 grounding, HQ verify |
| `Ingest` / `Chunker` | File/paste → documents + structure-aware chunks |
| `StructureParse` / `PromptLibrary` | G1/G2 DeepSeek parse + prompt loader |
| `SchemaCompat` | Optional column detection after alters |
| `DeepSeekClient` | API wrappers |
| `WebFetch` | Host-restricted search/fetch + length instructions |
| `AppSettings` | Feature flags, mix, chat limits, assistant branding, settings import |

## Prompt resolution (chat)

```
Admin assistant_branding (non-empty fields)
    → config.php app.system_prompt / rag_system_prompt
    → Settings.php fallback strings
+ runtime append: buildContext(), mix/HQ/length/web blocks, optional Admin grounding_rules / mix_* / followups
```

Ingest/faithfulness/parse system prompts remain under `includes/prompts/` and `Retrieval.php` (not Admin UI).

## Admin export / import / presets

| Endpoint / path | Role |
|-----------------|------|
| `GET api/export.php?type=settings` | JSON download: `effective` + `raw` settings rows |
| `GET api/export.php?type=chunks` | JSON download: all documents + all chunks |
| `POST api/export.php` | Import settings only (`type=settings`, body `data` or preset `effective`) |
| `GET api/presets.php` | List bundled presets (`_*.json` hidden) |
| `GET api/presets.php?id=` | Load one preset JSON (`extends` merge) |
| `data/presets/` | Preset files (web-denied via `.htaccess`) |

## Modes

- `chat` — general assistant (`app.system_prompt`); optional **Web**
- `rag` — **Docs** on (`app.rag_system_prompt` + retrieved excerpts); **Web** may also be on (allowlisted search + mix)

| Docs | Web | Result |
|------|-----|--------|
| Off | Off | Plain chat |
| On | Off | Docs only (RAG) |
| Off | On | Web only (allowlisted) |
| On | On | Docs + Web (mix applies) |

See [ACCURACY.md](ACCURACY.md) for the RAG accuracy path.
