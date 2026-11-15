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
