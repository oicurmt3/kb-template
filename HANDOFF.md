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
4. **Admin: RAG ↔ Web relationship** (not built) — setting for how Docs mode mixes corpus + allowlisted web:
   - **Mode:** `complement` (default, current behaviour) | `docs_first` (web only fills gaps) | `web_first` (web leads; docs for WC law) | `compete` / pick-winner (model chooses one side; or score-based).
   - **Degree (0–100):** how strongly to weight web vs docs in prompts + HQ (e.g. 0 = docs only even if web ran; 50 = equal combine; 100 = prefer web enrichment).
   - **HQ:** always preserve sources allowed by the chosen mode (never silently drop the non-preferred side’s supported claims unless mode is docs_only).
   - Store in `settings` via Admin (next to Adaptive RAG / web hosts); document in `ACCURACY.md` when built.
