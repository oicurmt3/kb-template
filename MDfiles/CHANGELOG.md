# Changelog

## 2026-11-17 — Structure parse, chunker, lexical search_text

- Documents/chunks schema for domain, version/as-at, pipeline stage, chunk flags/extra
- Structure-first Markdown chunker + contextual `search_text` (no vector embeddings)
- Resumable G1/G2 structure parse after OCR; admin Re-parse → Continue → Re-chunk
- Legislation L7 answer grounding when hits are mostly `domain_set=legislation`
- Retrieval filters: superseded `status_text`, `exclude_from_retrieval` chunk flags
- Prompts under `includes/prompts/`; `PromptLibrary`, `StructureParse`, `SchemaCompat`

## 2026-11-16 — UI labels, feature hints, File OCR, docs sync

- Composer/admin names: **Docs & Web**, **HQ**, **Deep think**, **Web only**
- Admin Chat UI: coloured keywords + live status hints for every feature row
- Options URL/File: compact inline rows (separate controls)
- Chat File attach: local text; DeepSeek OCR for PDF/Office/images; Files parse for spreadsheets/ebooks (`api/context.php` + `context_file_id`)
- MD suite + code sync to GitHub

## 2026-11-16 — Docs sync (Faithfulness + Source URL)

- ACCURACY / TESTING / README / handover / ARCHITECTURE / HANDOFF aligned with keep-draft Faithfulness and doc Source URL Include/Exclude

## 2026-11-16 — Faithfulness no longer guts streamed answers

- Default Faithfulness **0 (off)**; 1–50 never replaces the draft; ≥55 surgical rewrite only (≥85% length)
- Rejects hedge rewrites (“excerpts don’t show…”); silent cite strip (no “not clearly shown” text)
- Skips stub-only DeepSeek Files SOURCE; Prefer-OK prompt; Short no longer disables shrink guard

## 2026-11-16 — Toggle document Source URL web fetch

- Admin **Web search & fetch domains**: Include / Exclude document Source URLs as live web (`doc_source_url_web`, default Include)
- When excluded, citation links remain; no live page fetch for those URLs

## 2026-11-15 — Document Source URL as live web

- Admin document **Source URL** (`source-url-input`) is fetched as allowlisted live web when that doc appears in RAG hits (max 3/turn)
- Feeds system prompt + faithfulness web grounding + RAG↔Web mix; notes `Doc source URL: …`

## 2026-11-15 — Faithfulness strictness slider

- Admin **Faithfulness** card: 0–100 slider (0 = off; default 70)
- Maps to light / balanced / strict: prefer-OK vs hard OK, orphan scan (≥40), strip (≥60), shrink ratio
- Notes: `Faithfulness: ok|corrected (@degree band)`; export/import included

## 2026-11-15 — Stronger faithfulness hallucination brake

- Faithfulness: OK only when every legal claim is sourced; rewrite when unsure
- Local scan for section/reg numbers missing from SOURCE → forced second pass + strip fallback
- Mix auditor hints no longer “prefer OK over trimming inventions”

## 2026-11-15 — Short length + Deep hallucination fix

- Short answer length hardened (≤4 sentences) + closing reminder after HQ/mix so it wins
- HQ instruction is length-aware (Short no longer says “thorough / cover related points”)
- Deep default → **manual**; auto heuristics tightened (no auto-Deep on weak/many hits)
- Deep-on turns get grounding + length lock; faithfulness may trim padding in Short mode

## 2026-11-15 — Auto Deep thinking + admin stats

- Admin **Deep thinking** policy: `manual` / `auto` (default) / `always`, plus **max Deep per chat** (0 = unlimited)
- Auto heuristics: long/multi-part questions, weak retrieval, many hits, rewritten follow-ups; composer toggle forces on under auto
- Per-chat cap counted from assistant `blocks.deep_thinking`; SSE notes `Deep: on|off (reason; …)`
- Usage stats in settings (`deep_thinking_stats`) with Admin reset; export/import includes policy
- ACCURACY §E2 / toggles updated

## 2026-11-15 — Mix degree in faithfulness + process docs

- `ragWebMixHqHint` now includes mode **and** degree (lightly/moderately/strongly) so the auditor follows the same mix lean as the first pass
- ACCURACY §F / §2.1: process method — first pass applies mix → faithfulness QA after; independent runs, shared rules; keep vs cut table
- Admin RAG↔Web mix hint + handover updated

## 2026-11-15 — Split HQ / Deep / automatic faithfulness

- **HQ** = richer language only (no post-stream rewrite)
- **Deep** = DeepSeek API thinking / CoT only
- **Faithfulness** = automatic whenever docs and/or web/URL and/or attach grounding exists (`Faithfulness: ok|corrected|…`)
- Help + admin labels updated; ACCURACY roles table + pipeline; handover / TESTING / ARCHITECTURE / README / HANDOFF

## 2026-11-15 — Faithfulness brake hardening (then retargeted off HQ)

- `packHqGrounding`: docs + reserved web/URL budget + file/extra attach
- Softer verify prompt (prefer `OK`); mix hints prefer OK over aggressive trimming
- Reject corrections that shrink a long draft to &lt;50% length; skip when DeepSeek Files attached

## 2026-11-15 — Admin export/import + collapsible UI

- Admin **Export / import**: download all settings JSON; import settings (whitelisted keys); export all chunks (no import yet) via `api/export.php`
- All admin cards collapsible (`<details>`), closed by default; Chunks auto-opens when viewing a document
- Upload documents: stage files (browse/drop) then **Upload** / **Clear files** (no auto-upload on select)
- Full MD suite refreshed to match current admin + Docs-mode behaviour

## 2026-11-15 — Accuracy stack + docs

- Query rewrite for follow-ups, WA synonym expansion, auto focus tags
- Stronger section/reg scoring; weak-hit / no-hit grounding prompts
- HQ post-stream faithfulness check; client replaces bubble from `done.content`
- Admin Retrieval test shows search query + quality (matched chunks)
- Adaptive RAG hint updated; full MD documentation suite added (`MDfiles/ACCURACY.md` etc.)
- `ACCURACY.md` §7: how to tune synonyms / tags / corpus; pointers from `ADAPT.md` / `TESTING.md`
- Backlog (not built): admin-editable search synonyms setting — see `HANDOFF.md`
- Plan only: science teacher ↔ lab-tech coordinator — `MDfiles/PLAN-science-lab-coordinator.md`
- Fix: RAG/HQ no longer wipe allowlisted web/URL answers; HQ checks docs+web; UI note when answer replaced
- Combine: WorkCover mode = RAG + live allowlisted web as one pipeline; Web toggle only when WorkCover off; HQ preserves web content
- Admin RAG↔Web mix setting (complement / docs_first / web_first / compete + degree 0–100) wired into chat + HQ

## Earlier (same project arc)

- WorkCover RAG with Adaptive retrieval
- Chat UI admin: intro, history, Default/Show-Hide features (HQ replaces Plain)
- Paste-text ingest + AI title; shared document details
- Sources with chunk counts; strip `[metadata]` from user bubbles
- URL/file visibility CSS (`is-feature-hidden`)
- DeepSeek OCR / Files parse ingest paths
- Host-restricted web search/fetch
