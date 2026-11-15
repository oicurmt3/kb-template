# Changelog

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
