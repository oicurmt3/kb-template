# Handoff log

Agents append short dated blocks when the user asks. Others read first before multi-step work.

---

## 2026-11-20 — Presets + admin prompt overrides

**Done**

- `assistant_branding` extended: grounding_rules, grounding_rules_web, followups_instruction, mix_complement/docs_first/web_first/compete
- Neutral default mix/HQ/follow-ups in PHP; WA copy in `data/presets/wa-workers-comp.json`
- `api/presets.php` + Admin Apply preset; `data/presets/.htaccess` deny web
- Docs: CHANGELOG, README, handover, ADAPT, ARCHITECTURE, TESTING, AREA, this log

**Ops**

- Deploy `includes/Settings.php`, `Retrieval.php`, `helpers.php`, `admin.php`, `assets/admin.js`, `api/presets.php`, `data/`

## 2026-11-17 — Structure parse / chunk / search_text pipeline

**Done**

- Schema: `sql/alter_rag_structure.sql` (documents domain/version/pipeline + chunk metadata)
- PHP structure-first `Chunker` (atomic tables/lists/code; legislation overlap 0; contextual `search_text` templates)
- G1/G2 prompts + `StructureParse` / `PromptLibrary`; resumable OCR→parse batches
- Admin: **Re-parse** → **Continue** → **Re-chunk** (migration: parse all before chunk)
- Retrieval: L7 grounding when most hits are `legislation`; filter superseded + `exclude_from_retrieval`
- Config: `structure_parse`, `pages_per_batch`, `chunk_target_tokens` / `chunk_max_tokens`; `model_chunker`/`enrich_chunks`/`audit_chunks` default off

**Ops**

1. Run `sql/alter_rag_structure.sql` on live MariaDB
2. Deploy `includes/*`, `api/documents.php`, `api/health.php`, `assets/admin.js`, `includes/prompts/`
3. Per doc: Re-parse → Continue until `parsed` → Re-chunk (docs stay out of search while `processing`)
4. Check `api/health.php` for schema hints

**Not yet**

- G5 enrich / G6 audit / G8 eval harness wired on by default
- Medical domain prompts dormant

## 2026-11-16 — Docs & Web labels, UI hints, File OCR, sync

**Done**

- UI rename: Docs & Web / HQ / Deep think / Web only; admin coloured keywords + live feature hints
- Compact inline URL/File Options; File attach via DeepSeek OCR/Files for non-text
- Docs + code pushed to GitHub

**Ops**

- Deploy `api/context.php`, `api/chat.php`, `assets/*`, `index.php`, `admin.php`
- Confirm Faithfulness still **0** on live if older saves had 70

## 2026-11-16 — Docs sync + Faithfulness keep-draft behaviour

**Done**

- Faithfulness default **0**; 1–50 never replace stream; ≥55 surgical (≥85%, reject hedges); stub skip; silent cite strip
- Document Source URL live fetch + Web settings Include/Exclude
- MD suite updated (ACCURACY, TESTING, README, handover, ARCHITECTURE, CHANGELOG)

**Ops**

- If Admin still has Faithfulness saved at 70 from earlier, set to **0** and Save (or ≥55 only if surgical rewrites are wanted).

## 2026-11-15 — Faithfulness strictness slider

**Done**

- Admin slider `faithfulness.degree` 0–100 (later retargeted: default 0; rewrite only ≥55)
- Chat notes include `@degree band`; settings export/import

## 2026-11-15 — Faithfulness hallucination brake

**Done**

- OK only when legal claims sourced; orphan section/reg scan → forced rewrite + strip fallback
- Mix hints no longer soft “prefer OK” on inventions

## 2026-11-15 — Short length + Deep regression fix

**Done**

- Closing length reminder; HQ length-aware; Deep default manual; auto no longer on weak/hits
- Deep-on grounding lock; Short faithfulness may trim padding

**Ops**

- If Admin still shows Deep=Auto from an earlier save, switch to **Manual** and Save.

## 2026-11-15 — Auto Deep + admin stats

**Done**

- `AppSettings::resolveDeepThinking` / `shouldAutoDeepThinking` / stats + cap
- Admin Deep card: mode, max/chat, stats + reset; settings GET/POST; export/import policy
- Chat notes `Deep: on|off (reason; policy=…; chat=used/max)`

**Policy**

| Mode | Behaviour |
|------|-----------|
| manual (default) | Toggle only |
| auto | Strict multi-part/long only; toggle forces on |
| always | Every turn until cap |

---

## 2026-11-15 — Faithfulness follows mix mode + degree

**Done**

- `ragWebMixHqHint(mode, degree)` — auditor lean matches first-pass mix.
- ACCURACY process method §F / §2.1; admin mix card + handover/CHANGELOG.

**Process**

1. First pass writes with `ragWebMixInstruction`  
2. Faithfulness later audits with same mix principles (not during mix)  
3. Higher degree → trust primary side more when keeping/cutting; does not mean “scrutinize web harder”

---

## 2026-11-15 — HQ / Deep / faithfulness roles split

**Done**

- Faithfulness runs automatically when grounding exists; notes `Faithfulness: …`.
- HQ = style only; Deep = DeepSeek API thinking only.
- Docs + help/admin labels updated.

**Product contract**

| Toggle | Behaviour |
|--------|-----------|
| HQ | Style only |
| Deep | API thinking CoT |
| Faithfulness | Auto auditor; shared mix mode+degree with first pass |

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
- Docs & Web = RAG + allowlisted web; RAG↔Web mix admin setting; HQ preserves web-supported claims.

**API contracts (stable)**

- Chat POST body: `message`, `mode` (`rag`|`chat`), `hq_mode`/`hq`, `deep_thinking`, `web_search`, `tags`, `context_url`, `extra_context`, `conversation_id`.
- SSE: `delta`, `error`, `title`, `done` (`content`, `citations`, `suggestions`, `web_notes`, …).
- Retrieval test POST: `{ query }` → `search_query`, `rewritten`, `focus_tags`, `quality`, `hits`.

**Backlog (planned — do not build until asked)**

1. **Admin search synonyms** — editable phrase → expansion map (DB override merged with code defaults in `expandQuerySynonyms`); simple Admin UI; validate phrase length; show effect in Retrieval test. Not started.
2. **Optional later:** admin-editable tag-inference map (`inferFocusTags` `$syn`), after synonyms setting ships.
3. **Science lab coordinator** — teacher ↔ lab-tech lesson/prep app (RAG + inventory + request workflow). Full plan: `MDfiles/PLAN-science-lab-coordinator.md`. Not started.
4. **Admin: RAG ↔ Web mix** — **built**. Admin → “RAG ↔ Web mix” (`complement` / `docs_first` / `web_first` / `compete` + degree 0–100). Stored as `settings.rag_web_mix`; applied in `api/chat.php` + HQ via `AppSettings::ragWebMixInstruction` / `ragWebMixHqHint`.
