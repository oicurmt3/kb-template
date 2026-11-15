# How WANDA keeps answers accurate and relevant

This doc explains the full path from a user question to a grounded answer, and **how every admin / composer setting changes that path**.

Code anchors: `api/chat.php`, `includes/Retrieval.php`, `includes/Settings.php`, `includes/WebFetch.php`.

---

## 1. Goal

When **WorkCover** is on, WANDA should:

1. Find the **most relevant** chunks from the uploaded corpus (Act, Regulations, guides, fact sheets).
2. Answer **only** from those excerpts (and optional attached DeepSeek-parsed files / URL / paste).
3. Prefer **Act/Regulations** over guides when both appear.
4. Say clearly when the documents do **not** cover the question — never invent sections, time limits, or duties.

When WorkCover is off, there is **no document grounding** — the model uses the general system prompt and chat history only.

---

## 2. End-to-end pipeline (WorkCover on)

```
User message
    │
    ├─► History (last N turns) ──► conversationalQuery / LLM rewrite
    │                              + synonym expansion
    │                              + infer focus tags
    │
    ├─► Retrieval search (FULLTEXT + keyword + section/reg boost)
    │        └─ Adaptive slim sizing (if Adaptive RAG on)
    │
    ├─► hitQuality → weak / ok / no hits
    │
    ├─► buildContext → DOCUMENT EXCERPTS + mandatory grounding rules
    │
    ├─► Style layer: answer length + HQ or Basic (simple explain)
    │
    ├─► Optional: URL fetch / file paste / web search (allowed hosts)
    │
    ├─► Stream answer (DeepSeek) ── optional Deep thinking
    │
    └─► If HQ on: verifyGroundedAnswer → may replace final text
         Client applies done.content (replaces streamed bubble)
```

### Stage details

#### A. Query preparation (`Retrieval::prepareRetrievalQuery`)

| Step | What it does | Why it helps accuracy |
|------|----------------|----------------------|
| `conversationalQuery` | Builds a search string from the latest question + recent history | Follow-ups like “what about light duties?” keep prior claim context |
| Synonym expansion | Maps everyday phrases → legal search terms (`time off` → `incapacity weekly payments`, etc.) | Lexical search finds Act language users don’t know |
| Infer focus tags | Matches question wording to **existing document tags** | Narrows retrieval toward the right doc family |
| LLM rewrite (flash) | When history looks like a follow-up, rewrites one standalone search query + optional tags | Stops retrieval from running on pronouns / short fragments |

Rewrite **fails soft**: if the API fails or JSON is bad, the synonym-expanded baseline is used.

#### B. Retrieval (`searchWithMeta`)

- PRIMARY query = rewritten/expanded string.
- SECONDARY (`alsoQuery`) = synonym-expanded **raw** user message (catches terms rewrite dropped).
- Focus tags (user-picked or auto) bias / slim the candidate set when Adaptive RAG is on.
- Scoring boosts: tag hits, header/path hits, **section/reg needles** (+large bonus), full-phrase match, term coverage; **Act/Regs ×1.25**, guides ×0.92.
- Diversification caps how many chunks come from one document (`max_per_document`).

There is **no embedding / vector** path — DeepSeek does not expose embeddings here. Relevance is lexical + structure.

#### C. Quality gate (`hitQuality`)

Returns `weak`, `max_score`, `hit_count`, `has_legal_ref`.

| Signal | Effect on the model |
|--------|---------------------|
| Good score / legal ref found | Normal grounded prompt + “Retrieval: ok” note |
| Weak (low max score, no ref) | Extra instruction: only state what excerpts clearly support; say if docs don’t answer; do not invent rules |
| No hits | Explicit: do not invent Act/Regs content; suggest rewording or tags |

This does **not** hard-block the model from answering; it changes the system prompt so refusal / hedging is preferred over hallucination.

#### D. Context packing (`buildContext`)

Injects excerpts with **mandatory grounding rules**:

- Answer ONLY from excerpts / attached DeepSeek files.
- Partial coverage → answer covered part + say what’s missing.
- Nothing relevant → say so; do not invent law.
- Act/Regs first; guides as practical guidance only.
- Prefer naming section/regulation/part/division when supported.
- Plain text only; no `[1]` citation markers in the reply.

Citations for the UI are built here (`sources` with `chunk_count` for “Title (n)” style chips).

#### E. Style layer (after retrieval)

Always applied:

- **Answer length** (admin: short / medium / long) → length instruction.
- **HQ on** → precise legal wording instruction + (later) faithfulness check.
- **HQ off** → “explain like a {age} year old” Basic mode (admin age 5–18).
- **Related questions** (if visible) → follow-up chip instruction; stripped from stored/display text.

#### F. HQ faithfulness check (`verifyGroundedAnswer`)

Runs **only when** WorkCover + HQ are both effectively on, after the stream finishes.

- Flash model sees DOCUMENT EXCERPTS + DRAFT ANSWER.
- Replies `OK` or a corrected plain-text answer.
- Corrected text is saved and sent as SSE `done.content`; `chat.js` replaces the assistant bubble.

Cost: one extra non-streaming call. Benefit: catches unsupported claims that slipped through.

---

## 3. Feature settings → accuracy impact

Admin controls live under **Chat UI & features**, **Adaptive RAG**, **Answer length**, **Simple explain age**, and **Web domains**. Composer toggles send client flags; the server resolves them with `AppSettings::uiFeatureActive`.

### 3.1 Toggle features (Default + Show/Hide)

When **Hide**: user cannot flip the control; server uses the admin **Default**.  
When **Show**: user’s toggle wins for that request.

| Feature | Default meaning | Effect on accuracy / relevance |
|---------|-----------------|--------------------------------|
| **WorkCover** | On → `mode=rag`; Off → general chat | **Largest switch.** Off = no retrieval, no grounding, no HQ verify, no auto-tags. On = full accuracy pipeline. For a public workers-comp kiosk, keep Default **on**. |
| **HQ** | On = high-quality; Off = Basic | **Does not change which chunks are retrieved.** Changes answer style and whether the post-stream faithfulness check runs. HQ on → more precise terms, section numbers, related exceptions; HQ off → simpler language (still must stay grounded when WorkCover is on). For maximum legal fidelity, Default **on**. For public plain-language, Default **off**. |
| **Deep thinking** | Extra reasoning before answer | Can improve structure and careful reading of long excerpts; also increases latency/cost. Does not replace retrieval. Leave off unless answers feel shallow on complex multi-section questions. |
| **Web** | Live search/fetch on allowed hosts | Adds **current website** text. Helps relevance for “what does WorkCover’s site say today?” Risk: can dilute Act/Regs focus if pages are marketing/overview. Keep hosts tight (e.g. `workcover.wa.gov.au`). Prefer WorkCover RAG for law; use Web as supplement. |

### 3.2 Visibility-only features (Show / Hide only)

When **Hide**, the capability is **forced off** (no default-on bypass).

| Feature | Shown | Hidden |
|---------|-------|--------|
| **Tags** | User can pick tags; auto-tags still apply if none picked | No user tags; **auto-tags also disabled** (`focusTags` forced empty). Retrieval is broader/noisier. For accuracy, keep **Show** and tag documents well. |
| **URL context** | User may attach one URL (allowed host) for this turn | No URL injection. Safer/simpler; less “page says X” grounding. |
| **File context** | User may paste/upload text for this turn only | No ephemeral file text. Corpus RAG only. |
| **Sources** | Citation chips after RAG answers | Citations cleared before `done` — model still saw excerpts; user just doesn’t see which docs. Hiding does **not** reduce hallucination risk; it reduces transparency. Prefer **Show**. |
| **Related questions** | Model asked for follow-ups; chips shown | No follow-up instruction; chips off. Neutral for factual accuracy; slightly shorter prompts. |

### 3.3 Other admin settings

| Setting | Accuracy / relevance effect |
|---------|----------------------------|
| **Intro message** | UX only — not injected into model retrieval. |
| **Chat history on/off** | Off → multi-user/kiosk safer (no sidebar history). Retrieval rewrite still uses **in-conversation** message rows for the active chat while the session lasts. Turning history off does not wipe the current thread mid-chat. |
| **Adaptive RAG on** | Slimmer `top_k` / fewer docs when the question is focused or tagged. **Usually better relevance** (less conflicting excerpts). Off = legacy fixed-size pool — more recall, more noise. Prefer **on**. |
| **Answer length** short / medium / long | Short answers may omit exceptions and related sections → lower *completeness*. Long may pad. **Medium** is the accuracy sweet spot for legal Q&A. |
| **Simple explain age** (5–18) | Only applies when HQ is off. Lower ages → simpler words; meaning must still match excerpts. Too low can oversimplify obligations — keep ≥10 for workplace use. |
| **Web allowed hosts** | Wrong hosts → wrong jurisdiction or junk pages. Keep WA WorkCover (and trusted official hosts only). |
| **Chat caps / retention** | Ops, not accuracy — except pruning mid-investigation can drop history the rewrite relied on. |

### 3.4 Document / ingest choices (admin, not composer)

These often matter **more** than toggles:

| Practice | Why |
|----------|-----|
| Upload Act + Regulations with clear titles/tags (`act`, `regulations`) | Kind detection boosts Act/Regs in scoring and hierarchy instructions |
| Tag guides/fact sheets distinctly | Focus tags + auto-tags work |
| Set **source URL** on docs | User can open the official page from Sources |
| Prefer **OCR → chunks** for PDFs used as law | Chunks are searchable; Files-parse-only docs attach as `file_id` and help less in lexical search |
| Paste clean Act excerpts with good title/tags | Same as upload; empty title → AI title |
| Use **Retrieval test** after ingest | Confirms rewrite + quality score before trusting chat |

### 3.5 Config (`includes/config.php`) knobs

| Key | Effect |
|-----|--------|
| `rag.top_k` / `max_per_document` | Recall vs noise when Adaptive is off (or as ceilings) |
| `rag.top_k_slim*` / `activate_docs_slim` | Adaptive focused retrieval size |
| `rag.max_history_messages` | How much chat context rewrite + model see |
| `rag.chunk_max_chars` / `chunk_overlap` | Chunk granularity — too large = noisy hits; too small = broken sections |
| `app.rag_system_prompt` | Base RAG personality / hierarchy (overrides defaults if set) |
| `deepseek.flash_model` | Used for rewrite, titles, web tools, HQ verify — keep a reliable flash model |

---

## 4. Recommended profiles

### Public kiosk (workers / employers)

- WorkCover: Default **on**, Show  
- HQ: Default **off**, Show (Basic language)  
- Deep: Default **off**, Hide or Show  
- Web: Default **off**, Show optional  
- Tags / Sources / Related: **Show**  
- Adaptive RAG: **on**  
- Answer length: **medium**  
- Chat history: **off** if shared device  

### Staff / legal support desk

- WorkCover: Default **on**  
- HQ: Default **on** (faithfulness check + precise terms)  
- Deep: **on** for hard multi-instrument questions  
- Web: **on** with tight hosts for site procedures  
- Tags: **Show**; keep corpus tags tidy  
- Adaptive RAG: **on**  
- Answer length: **medium** or **long**  

### Maximum anti-hallucination

1. WorkCover **on**  
2. Adaptive RAG **on**  
3. HQ **on** (faithfulness pass)  
4. Web **off** unless needed  
5. Well-tagged Act/Regs corpus  
6. Retrieval test until top scores are strong for sample questions  
7. Sources **shown** so humans can verify  

---

## 5. What each mode combination does

| WorkCover | HQ | Web | Result |
|-----------|----|-----|--------|
| Off | * | Off | General chat; no docs |
| Off | * | On | General chat + allowed-host web text |
| On | Off | Off | Grounded RAG + Basic language; no HQ verify |
| On | On | Off | Grounded RAG + precise style + HQ verify |
| On | On | On | Above + web snippets (watch for dilution) |

Deep thinking can combine with any row; it does not change retrieval.

---

## 6. Debugging accuracy

1. **Admin → Retrieval test** — same rewrite → synonyms → tags → search path; inspect search query, quality score, weak flag, hit headers.  
2. Chat with WorkCover on — expand Sources; check titles and excerpt counts.  
3. Read SSE / UI notes when exposed (`Query rewrite`, `Auto tags`, `Retrieval: weak`, `HQ check: corrected`).  
4. If wrong section: improve document tags/titles, or ask with section number (legal-ref bonus).  
5. If invented law: confirm WorkCover was on; check weak/no-hit notes; turn HQ on and re-ask.  
6. If follow-up missed context: ensure chat history messages exist (same conversation); rewrite needs prior user turns.

---

## 7. Limits (honest)

- Lexical retrieval can miss paraphrases that never appear in the Act text even after synonyms.  
- Rewrite / HQ verify depend on DeepSeek flash quality and cost extra latency.  
- Guides can still rank if Act chunks are poorly titled or untagged.  
- Web pages are not law — model instructions prefer Act/Regs, but mixed context can confuse.  
- This is **not** a substitute for legal advice; grounding reduces inventing, it does not guarantee completeness of the corpus.
