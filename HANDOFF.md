# Handoff log

Agents append short dated blocks when the user asks. Others read first before multi-step work.

---

## 2026-11-15 — HQ / Deep / faithfulness roles split

**Done**

- Faithfulness runs automatically when grounding exists (docs and/or web/URL/attach); notes `Faithfulness: …`.
- HQ = style only; Deep = DeepSeek API `thinking.enabled` only.
- Help (`index.php`), admin labels, chat.js bubble note updated.
- Docs: ACCURACY roles + §E/E2/F, handover, README, TESTING, ARCHITECTURE, CHANGELOG, this log.

**Product contract**

| Toggle | Behaviour |
|--------|-----------|
| HQ | `hqInstruction` vs Basic explain — no verify |
| Deep | API thinking CoT |
| Faithfulness | Auto auditor via `verifyGroundedAnswer` |

---

## 2026-11-15 — Faithfulness brake hardened + docs

**Done**

- `Retrieval::packHqGrounding` + conservative `verifyGroundedAnswer` (prefer OK, reject shrinks, complement keeps web).
- Mix hints softened (`ragWebMixHqHint`).

**Notes**

- `web_notes` use `Faithfulness: ok|corrected|kept draft|skipped…` (formerly `HQ check:`).

---

## 2026-11-15 — Admin export/import + UI polish + docs sync

**Done**

- `api/export.php`: GET `type=settings|chunks` download; POST import settings (`AppSettings::importSettingsExport`).
- Admin **Export / import** card; staged file upload + **Upload** / Clear; all admin cards collapsible (closed by default).
- Docs refreshed across README, handover, AREA, ARCHITECTURE, DESIGN, SECURITY, DEPLOY, TESTING, CHANGELOG, ACCURACY, ADAPT, CONTRIBUTING, this log.

**API contracts**

- Export GET: `api/export.php?type=settings|chunks` (admin session) → JSON attachment.
- Import POST: `{ type: "settings", data: <export object> }` + CSRF → `{ ok, updated, skipped, effective }`.

**Blocked / next**

- Chunk **import** not built.
- No automated eval harness — `MDfiles/TESTING.md`.
- Optional: surface `web_notes` in chat UI.

**Backlog (do not build until asked)**

1. Admin search synonyms (DB override + UI).
2. Admin tag-inference map (after synonyms).
3. Science lab coordinator — `MDfiles/PLAN-science-lab-coordinator.md`.
4. Chunk import from export JSON (optional).

---

## 2026-11-15 — Accuracy + documentation

**Done**

- Accuracy pipeline wired end-to-end: rewrite, synonyms, auto-tags, hitQuality grounding, HQ `verifyGroundedAnswer`, admin retrieval-test quality display, chat.js uses `done.content` after HQ.
- Full documentation suite: refreshed `README.md` / `handover.md`; added `MDfiles/ACCURACY.md` (feature × accuracy matrix) plus architecture/design/security/deploy/testing/changelog/contributing; root `AGENTS.md`, `AREA.md`, this log.
- WorkCover = RAG + allowlisted web; RAG↔Web mix admin setting; HQ preserves web-supported claims.

**API contracts (stable)**

- Chat POST body: `message`, `mode` (`rag`|`chat`), `hq_mode`/`hq`, `deep_thinking`, `web_search`, `tags`, `context_url`, `extra_context`, `conversation_id`.
- SSE: `delta`, `error`, `title`, `done` (`content`, `citations`, `suggestions`, `web_notes`, …).
- Retrieval test POST: `{ query }` → `search_query`, `rewritten`, `focus_tags`, `quality`, `hits`.

**Backlog (planned — do not build until asked)**

1. **Admin search synonyms** — editable phrase → expansion map (DB override merged with code defaults in `expandQuerySynonyms`); simple Admin UI; validate phrase length; show effect in Retrieval test. Not started.
2. **Optional later:** admin-editable tag-inference map (`inferFocusTags` `$syn`), after synonyms setting ships.
3. **Science lab coordinator** — teacher ↔ lab-tech lesson/prep app (RAG + inventory + request workflow). Full plan: `MDfiles/PLAN-science-lab-coordinator.md`. Not started.
4. **Admin: RAG ↔ Web mix** — **built**. Admin → “RAG ↔ Web mix” (`complement` / `docs_first` / `web_first` / `compete` + degree 0–100). Stored as `settings.rag_web_mix`; applied in `api/chat.php` + HQ via `AppSettings::ragWebMixInstruction` / `ragWebMixHqHint`.
