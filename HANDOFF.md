# Handoff log

Agents append short dated blocks when the user asks. Others read first before multi-step work.

---

## 2026-11-15 — Accuracy + documentation

**Done**

- Accuracy pipeline wired end-to-end: rewrite, synonyms, auto-tags, hitQuality grounding, HQ `verifyGroundedAnswer`, admin retrieval-test quality display, chat.js uses `done.content` after HQ.
- Full documentation suite: refreshed `README.md` / `handover.md`; added `MDfiles/ACCURACY.md` (feature × accuracy matrix) plus architecture/design/security/deploy/testing/changelog/contributing; root `AGENTS.md`, `AREA.md`, this log.

**API contracts (stable)**

- Chat POST body: `message`, `mode` (`rag`|`chat`), `hq_mode`/`hq`, `deep_thinking`, `web_search`, `tags`, `context_url`, `extra_context`, `conversation_id`.
- SSE: `delta`, `error`, `title`, `done` (`content`, `citations`, `suggestions`, `web_notes`, …).
- Retrieval test POST: `{ query }` → `search_query`, `rewritten`, `focus_tags`, `quality`, `hits`.

**Blocked / next**

- No automated eval harness yet — manual scripts in `MDfiles/TESTING.md`.
- Optional: surface `web_notes` in the chat UI for transparency.

**Backlog (planned — do not build until asked)**

1. **Admin search synonyms** — editable phrase → expansion map (DB override merged with code defaults in `expandQuerySynonyms`); simple Admin UI; validate phrase length; show effect in Retrieval test. Not started.
2. **Optional later:** admin-editable tag-inference map (`inferFocusTags` `$syn`), after synonyms setting ships.
3. **Science lab coordinator** — teacher ↔ lab-tech lesson/prep app (RAG + inventory + request workflow). Full plan: `MDfiles/PLAN-science-lab-coordinator.md`. Not started.
