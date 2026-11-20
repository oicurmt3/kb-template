# Handover — kb-template

## What this app is

**kb-template** is a document-grounded chatbot shell for any business. **Docs & Web** (composer / internal feature key `workcover`) retrieves chunks from uploaded documents **and** enriches with allowlisted live web; DeepSeek answers are grounded on those sources. Blend behaviour is controlled by **Admin → RAG ↔ Web mix**. Without Docs & Web it is a general chat (optional **Web only**) with the configured system prompt. Vertical copy (e.g. WA workers comp) belongs in **presets** / Admin branding, not hardcoded defaults.

## Do not break

- **Grounding contract** in `Retrieval::buildContext()` — answers must stay excerpt-bound when Docs & Web is on.
- **Chat File attach** (`api/context.php`) — text-like files read locally; PDF/Office/images → DeepSeek OCR into turn text; spreadsheets/ebooks → Files `file_id` on the turn. Not the same as Admin corpus ingest.
- **Admin UI feature rows** — short coloured names + live status hints from Default / Show-Hide (Docs & Web vs Web only interaction).
- **HQ vs Basic** — HQ = richer/precise language only (`hqInstruction`). HQ off = `simpleExplain` by admin age. Do **not** hang the post-stream faithfulness rewrite on HQ (legacy “Plain” was removed; faithfulness is separate).
- **Faithfulness** admin slider (default **0 = off**). 1–50 never replaces the stream; ≥55 surgical only (≥85% length, reject hedges); skip stub-only Files SOURCE. Do not strip allowlisted web under complement.
- **Deep** = DeepSeek API `"thinking": {"type":"enabled"}` only — not the faithfulness auditor. Admin policy `manual` (default) / `auto` / `always` + max per chat. Prefer Manual with Short answers; auto must not fire on weak retrieval.
- **Answer length Short** must win over HQ “thorough” — closing length reminder after mix; HQ is length-aware.
- **No citation numbers in model text** — sources render in the UI from `citations` / `sources` blocks.
- **Web search is host-restricted** — never enable unrestricted DeepSeek open-web search; use `WebFetch` + allowed hosts.
- **Document Source URL** (admin) is a citation link; live web fetch when that doc is retrieved is gated by **Web settings → Include/Exclude** (`doc_source_url_web`, default include; allowlisted hosts only; max 3/turn).
- **Session isolation** — conversations are keyed by `session_id` cookie; do not leak across sessions.
- **Secrets** stay in `includes/config.php` (gitignored). Never commit API keys or DB dumps.
- **Settings import** only restores whitelisted keys via `AppSettings::importSettingsExport`.
- **Chat system prompts:** Admin `assistant_branding.system_prompt` / `rag_system_prompt` wins over `config.php` when non-empty; optional `source_hierarchy`, `grounding_rules`, `grounding_rules_web`, `followups_instruction`, `mix_*` override neutral PHP defaults.
- **Business presets** import `effective` settings only (merge `assistant_branding` keys present in JSON; corpus unchanged).

## Accuracy stack (see MDfiles/ACCURACY.md)

1. Query rewrite (follow-ups → standalone search) + WA synonym expansion  
2. Auto focus tags when the user picked none  
3. FULLTEXT + keyword scoring (section/reg bonuses, Act/Regs boost)  
4. Adaptive slim retrieval when admin Adaptive RAG is on  
5. Weak-hit / no-hit prompt hardening  
6. Allowlisted web enrichment + RAG↔Web mix instructions (**first pass**)  
7. Style: HQ (richer) or Basic (simple explain)  
8. DeepSeek API thinking — resolved by admin Deep policy (manual / auto heuristics / always) + per-chat cap; notes `Deep: on|off (…)`  
9. **Faithfulness** after stream when grounding exists and slider &gt; 0 — default **off**. Degrees 1–50 never replace the stream; ≥55 may apply a surgical rewrite only (≥85% length, reject hedges). `done.content` only when a rewrite actually applied.

See `MDfiles/ACCURACY.md` §F / §2.1 for the process method (independent runs, shared mix rules; prefer keep draft).

## Key files

| File | Role |
|------|------|
| `api/chat.php` | SSE chat; wires modes, RAG, web, mix, HQ style, Deep thinking, faithfulness |
| `api/export.php` | GET export settings/chunks; POST import settings |
| `includes/Retrieval.php` | Search, rewrite, synonyms, tags, quality, `verifyGroundedAnswer` |
| `includes/Settings.php` | UI features, adaptive RAG, answer length, hosts, mix, Deep policy/stats, assistant branding, import |
| `api/presets.php` | Admin list/load `data/presets/*.json` |
| `data/presets/` | Business preset bundles (import via Admin) |
| `includes/Ingest.php` / `Chunker.php` | Upload / paste → structure parse → chunks + `search_text` |
| `includes/StructureParse.php` / `PromptLibrary.php` | G1/G2 parse batches; prompts in `includes/prompts/` |
| `includes/DeepSeek.php` | Completions + stream + OCR/Files |
| `includes/WebFetch.php` | Allowed-host fetch/search |
| `assets/chat.js` | Composer toggles, SSE, sources, faithfulness replace on done |
| `assets/admin.js` | Collapsible cards, staged upload, export/import, settings |
| `admin.php` | Admin shell (all cards `<details>`, closed by default) |
| `api/context.php` | Turn-only URL fetch + File attach (local / OCR / Files parse) |
| `index.php` | Chat help + compact inline URL/File Options UI |

## Secrets & config

- Copy `includes/config.example.php` → `includes/config.php`
- Admin password: bcrypt via `install.php` hash tool
- DeepSeek key: `deepseek.api_key`
- Optional overrides live in DB `settings` table (UI features, adaptive RAG, RAG↔Web mix, hosts, chat caps, etc.)

## Ops quirks

- SSE on cPanel: disable gzip on the chat endpoint; `output_buffering Off` helps
- Rate-limit files under `storage/ratelimit/`
- Lock installer with `storage/install.lock` or remove `install.php`
- Paste ingest uses the same Document details fields as file upload; empty title → AI title
- File upload is **staged** (drop/browse) then **Upload** button — not auto-upload on select
- DeepSeek has **no embeddings API** in this stack — retrieval is lexical/FULLTEXT; contextual address goes in `search_text` templates, verbatim law stays in `text`
- Structure-first chunking (headers + atomic tables/lists); legislation uses **zero overlap**
- Corpus migration: Admin **Re-parse** → **Continue** until `parsed` → **Re-chunk** (do not rechunk old OCR alone if you want structure parse)
- Run `sql/alter_rag_structure.sql` once on existing DBs; `api/health.php` hints if columns missing
- Chunks are identifiable (`id`, `document_id`, `chunk_index`, `chunk_key`, `header_path`)
- Settings export JSON round-trips via Import; chunks export is download-only

## Admin knobs that matter most for accuracy

| Setting | Prefer for accuracy |
|---------|---------------------|
| Docs & Web default **on**, visible | Docs mode + automatic faithfulness when grounded |
| Adaptive RAG **on** | Focused context, less noise |
| RAG↔Web mix **complement** ~50 | Blend docs + web; use docs_first if Act text must dominate |
| HQ default **off** for public kiosks; **on** for staff | Style only (precise vs Basic) |
| Deep **off** by default; **on** for hard questions | API thinking cost/latency |
| Tags **shown** + docs well-tagged | Better focus + auto-tags |
| Answer length **medium** | Enough room for legal nuance |
| Web hosts = your official domains only | Avoid off-topic or untrusted pages |

## Next risks / debt

- G5 enrich / G6 audit / G8 eval still optional (`model_chunker` / `enrich_chunks` / `audit_chunks` default off)
- No automated eval suite yet — use Admin → Retrieval test + manual chat scripts (see TESTING.md)
- Query rewrite costs an extra flash call on follow-ups
- Faithfulness (when degree ≥55) may cost a second flash call; lower degrees do not rewrite the stream
- DeepSeek API thinking adds CoT cost when Deep is on
- Chunk import not built (export only)
- Admin-editable synonyms / tag-inference maps still backlog (see HANDOFF.md)
