# Handover — WANDA

## What this app is

WANDA answers WA workers compensation questions. **WorkCover mode** retrieves chunks from uploaded documents (Act, Regulations, guides, fact sheets) **and** enriches with allowlisted live web; DeepSeek answers are grounded on those sources. Blend behaviour is controlled by **Admin → RAG ↔ Web mix**. Without WorkCover it is a general chat (optional standalone Web) with the configured system prompt.

## Do not break

- **Grounding contract** in `Retrieval::buildContext()` — answers must stay excerpt-bound when WorkCover is on.
- **HQ vs Basic** — HQ = richer/precise language only (`hqInstruction`). HQ off = `simpleExplain` by admin age. Do **not** hang the post-stream faithfulness rewrite on HQ (legacy “Plain” was removed; faithfulness is separate).
- **Faithfulness is automatic** when docs and/or allowlisted web/URL and/or attach grounding exists — prefer `OK`, pack via `packHqGrounding`, reject harmful shrinks, skip DeepSeek Files. Do not strip allowlisted web under complement.
- **Deep** = DeepSeek API `"thinking": {"type":"enabled"}` only — not the faithfulness auditor. Admin policy `manual` / `auto` / `always` + max per chat; auto uses heuristics (toggle forces on).
- **No citation numbers in model text** — sources render in the UI from `citations` / `sources` blocks.
- **Web search is host-restricted** — never enable unrestricted DeepSeek open-web search; use `WebFetch` + allowed hosts.
- **Session isolation** — conversations are keyed by `session_id` cookie; do not leak across sessions.
- **Secrets** stay in `includes/config.php` (gitignored). Never commit API keys or DB dumps.
- **Settings import** only restores whitelisted keys via `AppSettings::importSettingsExport`.

## Accuracy stack (see MDfiles/ACCURACY.md)

1. Query rewrite (follow-ups → standalone search) + WA synonym expansion  
2. Auto focus tags when the user picked none  
3. FULLTEXT + keyword scoring (section/reg bonuses, Act/Regs boost)  
4. Adaptive slim retrieval when admin Adaptive RAG is on  
5. Weak-hit / no-hit prompt hardening  
6. Allowlisted web enrichment + RAG↔Web mix instructions (**first pass**)  
7. Style: HQ (richer) or Basic (simple explain)  
8. DeepSeek API thinking — resolved by admin Deep policy (manual / auto heuristics / always) + per-chat cap; notes `Deep: on|off (…)`  
9. **Faithfulness** after stream when grounding exists — same mix mode+degree policy via `ragWebMixHqHint` (may replace bubble via `done.content`)

See `MDfiles/ACCURACY.md` §F / §2.1 for the process method (independent runs, shared mix rules; prefer OK; shrink guard).

## Key files

| File | Role |
|------|------|
| `api/chat.php` | SSE chat; wires modes, RAG, web, mix, HQ style, Deep thinking, faithfulness |
| `api/export.php` | GET export settings/chunks; POST import settings |
| `includes/Retrieval.php` | Search, rewrite, synonyms, tags, quality, `verifyGroundedAnswer` |
| `includes/Settings.php` | UI features, adaptive RAG, answer length, hosts, mix, Deep policy/stats, import |
| `includes/Ingest.php` / `Chunker.php` | Upload / paste → chunks |
| `includes/DeepSeek.php` | Completions + stream + OCR/Files |
| `includes/WebFetch.php` | Allowed-host fetch/search |
| `assets/chat.js` | Composer toggles, SSE, sources, faithfulness replace on done |
| `assets/admin.js` | Collapsible cards, staged upload, export/import, settings |
| `admin.php` | Admin shell (all cards `<details>`, closed by default) |
| `index.php` | Chat help copy for HQ / Deep / faithfulness |

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
- DeepSeek has **no embeddings API** in this stack — retrieval is lexical/FULLTEXT, not vector hybrid
- Chunks are identifiable (`id`, `document_id`, `chunk_index`, `chunk_key`, `header_path`)
- Settings export JSON round-trips via Import; chunks export is download-only

## Admin knobs that matter most for accuracy

| Setting | Prefer for accuracy |
|---------|---------------------|
| WorkCover default **on**, visible | Docs mode + automatic faithfulness when grounded |
| Adaptive RAG **on** | Focused context, less noise |
| RAG↔Web mix **complement** ~50 | Blend docs + web; use docs_first if Act text must dominate |
| HQ default **off** for public kiosks; **on** for staff | Style only (precise vs Basic) |
| Deep **off** by default; **on** for hard questions | API thinking cost/latency |
| Tags **shown** + docs well-tagged | Better focus + auto-tags |
| Answer length **medium** | Enough room for legal nuance |
| Web hosts = WorkCover domains only | Avoid off-topic / wrong-jurisdiction pages |

## Next risks / debt

- No automated eval suite yet — use Admin → Retrieval test + manual chat scripts (see TESTING.md)
- Query rewrite costs an extra flash call on follow-ups
- Faithfulness costs a second flash call after grounded streams
- DeepSeek API thinking adds CoT cost when Deep is on
- Chunk import not built (export only)
- Admin-editable synonyms / tag-inference maps still backlog (see HANDOFF.md)
