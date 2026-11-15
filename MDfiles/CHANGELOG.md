# Changelog

## 2026-11-15 — Accuracy stack + docs

- Query rewrite for follow-ups, WA synonym expansion, auto focus tags
- Stronger section/reg scoring; weak-hit / no-hit grounding prompts
- HQ post-stream faithfulness check; client replaces bubble from `done.content`
- Admin Retrieval test shows search query + quality
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
