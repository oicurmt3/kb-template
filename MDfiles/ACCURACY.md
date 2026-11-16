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
    ├─► Style layer: answer length + HQ (richer language) or Basic (simple explain)
    │
    ├─► Optional: URL fetch / file paste / web search (allowed hosts)
    │
    ├─► Stream answer (DeepSeek) ── optional Deep (API thinking / CoT)
    │
    └─► If any grounding (docs and/or web/URL and/or attach):
         verifyGroundedAnswer (faithfulness) → may replace final text
         Client applies done.content (replaces streamed bubble)
```

### Roles at a glance (do not confuse)

| Control | What it does | What it does **not** do |
|---------|----------------|-------------------------|
| **HQ** | Richer / more precise wording in the first answer | Post-stream rewrite; faithfulness |
| **Deep** | DeepSeek API `thinking: enabled` (chain-of-thought before answer) | Source faithfulness audit |
| **Faithfulness** | Automatic second flash call vs packed sources when grounding exists | Style / “make language fancier” |
| **WorkCover** | RAG + allowlisted web (Docs mode) | — |

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

- Query order into the merger: **`alsoQuery` first** (weight 1.15), then rewritten/expanded query (weight 0.9) if different — so the raw latest phrasing is slightly preferred.
- Multiple search channels merge into one chunk score (see **§9** for every weight).
- Focus tags multiply/add score; Adaptive RAG may slim to activated docs.
- Diversification caps chunks per document (`max_per_document` / slim variants).

There is **no embedding / vector** path — DeepSeek does not expose embeddings here. Relevance is lexical + structure. Full formulas: **§9**.

#### C. Quality gate (`hitQuality`)

Returns `weak`, `max_score`, `hit_count`, `has_legal_ref`.

**Weak when** (any):

\[
\text{weak} \iff n=0 \;\lor\; (S_{\max}<4 \land \neg R) \;\lor\; (S_{\max}<2.5)
\]

where \(S_{\max}\) = max hit score, \(R\) = a legal-ref needle from the query appears in some hit header/text, \(n\) = hit count.

| Signal | Effect on the model |
|--------|---------------------|
| Not weak | Normal grounded prompt + “Retrieval: ok” note |
| Weak | Extra instruction: only state what excerpts clearly support; say if docs don’t answer; do not invent rules |
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

#### E. Style layer (after retrieval) — HQ / Basic

Always applied:

- **Answer length** (admin: short / medium / long) → length instruction.
- **HQ on** → precise / richer legal wording (`hqInstruction`) only — **no** post-stream rewrite.
- **HQ off** → “explain like a {age} year old” Basic mode (admin age 5–18).
- **Related questions** (if visible) → follow-up chip instruction; stripped from stored/display text.

#### E2. Deep thinking (during stream; admin policy)

When Deep is **enabled for the turn**, the chat request sets DeepSeek API `"thinking": { "type": "enabled" }` ([Thinking Mode](https://api-docs.deepseek.com/guides/thinking_mode/)). The model produces chain-of-thought (`reasoning_content`) then the user answer (`content`). WANDA streams the answer content to the UI. Deep improves careful reasoning; it does **not** replace the faithfulness audit below. Deep can still lengthen answers or invent under thin sources — prefer **Manual** when Answer length is **Short**.

Admin **Deep thinking** policy (`deep_thinking_policy` in settings):

| Mode | Behaviour |
|------|-----------|
| **manual** (default) | Composer Deep toggle only |
| **auto** | Only clearly long / multi-part questions; toggle **forces on**. Does **not** auto-on for weak retrieval or many hits (that increased hallucinations) |
| **always** | Deep every turn until the per-chat cap |

**Max Deep per chat** (0 = unlimited, default 3) caps how many assistant turns in one conversation may use Deep. Decisions are counted in `deep_thinking_stats` (on/off, auto_on, user_force, capped, …) and shown in Admin. SSE / notes: `Deep: on|off (reason; policy=…; chat=used/max)`. When Deep is on, a grounding + length reminder is appended to the system prompt.

#### E2b. Answer length enforcement

Admin **Short / Medium / Long** is applied twice: early instruction + **closing reminder after** HQ/mix/web so length wins over “be thorough”. HQ wording is length-aware (Short = precision without expansion). Faithfulness in Short mode may trim unsupported padding.

#### F. Faithfulness check (`verifyGroundedAnswer`) — automatic QA after the mix

Runs after the stream when grounding exists — unless Admin **Faithfulness** degree is **0** (off, **default**). **Not** gated on HQ or Deep.

| Degree | Behaviour |
|--------|-----------|
| 0 | Off — keep streamed answer |
| 1–50 | Light: may note `ok` but **never replaces** the draft |
| ≥55 | May apply a **surgical** rewrite only (≥85% of draft kept); rejects hedge language |
| ≥75 | + silent orphan cite strip (no “not clearly shown” hedges) |

Skips when SOURCE is mostly DeepSeek Files stubs. Notes: `Faithfulness: ok|corrected|kept draft (shrink|hedge|…)`.

Who runs it: WANDA PHP calls DeepSeek **flash** (`chatComplete`) with packed sources + draft.

##### Process method (order matters)

```
1. Retrieval + allowlisted web/URL/attach  →  grounding texts
2. FIRST PASS (stream)
      - Writes the answer
      - Mix applied HERE via ragWebMixInstruction(mode + degree)
      - Optional Deep = API thinking during this call only
      - HQ/Basic = wording style only
3. User already sees the streamed draft
4. FAITHFULNESS (second flash call) — separate run, later
      - Does NOT re-run mix or retrieval
      - Audits the finished draft vs packed SOURCE MATERIAL
      - Uses the SAME mix principles via ragWebMixHqHint(mode + degree)
      - Prefer OK; rewrite only clear inventions; shrink-guard keeps good drafts
5. done.content may replace the bubble if corrected
```

**Independent runs, shared mix rules:** first pass and faithfulness are two API calls. Mix does not “happen inside” faithfulness. Faithfulness re-applies the same blend **policy** when deciding what to keep or cut.

##### What faithfulness lets through vs stops

| Lets through (keep first pass) | Stops (or tries to) |
|--------------------------------|---------------------|
| Claims supported by docs **or** web/URL/attach under the active mix | Invented section/reg numbers, duties, rules, facts not in SOURCE MATERIAL |
| Minor wording differences | Unsupported embroidery beyond packed sources |
| Complement web enrichment that appears in the web pack | Over-aggressive “corrections” that shrink a ≥200-char draft to &lt;50% length → **keep draft** |
| Mix-aligned blends (docs_first gap-fill web, web_first process + statute, etc.) | Weaker-side inventions under **compete** when they contradict sources |

##### Mix mode + degree in the check

Admin **RAG ↔ Web mix** is one setting used twice:

| | First pass (`ragWebMixInstruction`) | Faithfulness (`ragWebMixHqHint`) |
|--|-------------------------------------|----------------------------------|
| **Mode** | How to write the blend | What to treat as allowed when auditing |
| **Degree 0–100** | lightly / moderately / strongly lean | Same lean language in the auditor prompt — higher degree = trust the primary side more when keeping/cutting |

| Mode | Faithfulness keep/cut principle |
|------|--------------------------------|
| **complement** | Keep docs **or** web; never strip web-backed enrichment in SOURCE MATERIAL; do not undo the first-pass blend |
| **docs_first** | Prefer docs; keep web as gap-fill / process detail; higher degree → allow more web gap-fill |
| **web_first** | Prefer web; keep Act/Regs for legal duties; higher degree → lean harder on web for process |
| **compete** | Prefer OK if draft already chose a winner; only drop weaker side if invented/contradictory; degree tilts which winner is more tolerable |

Important: “more web weight” means the check **protects web more** (treats web as allowed), **not** that it scrutinizes web more harshly.

##### Packing / skip / outcomes

- **SOURCE MATERIAL** packed by `Retrieval::packHqGrounding()` (budget ~14k chars): docs + reserved web (~35%) + attach (~20%) so long RAG cannot crowd web out of the auditor’s window.
- Draft sent to checker ≤ ~6k chars; `max_tokens` 900; temperature 0.1.
- Prefer exact `OK`; rewrite only clear inventions.
- If correction shrinks a ≥200-char draft to &lt;50% → keep streamed draft (`Faithfulness: kept draft`).
- **Skipped** when DeepSeek Files are attached (file body not in grounding window).
- **Skipped** for plain general LLM chat with no docs/web/URL/attach grounding.
- Notes: `Faithfulness: ok` | `corrected` | `kept draft` | `skipped…`
- Corrected text → SSE `done.content`; UI may show an update note.

Cost: one extra non-streaming flash call when not skipped.

**Note:** Live web still only hits admin-allowlisted hosts. WorkCover mode always tries to enrich RAG with that web (when `web.enabled` in config).

---

### 2.1 RAG ↔ Web mix + faithfulness (principles summary)

1. Mix is applied when **writing** the first answer.  
2. Faithfulness runs **after**, as QA.  
3. Same admin mode + degree steers both write and audit.  
4. Faithfulness is optional only when there is **no** grounding; it is not a user toggle.  
5. HQ and Deep are orthogonal (style / API CoT).  

---

## 3. Feature settings → accuracy impact

Admin controls live under collapsible cards: **Chat UI & features**, **Adaptive RAG**, **RAG ↔ Web mix**, **Answer length**, **Basic language age**, and **Web search & fetch domains**. Composer toggles send client flags; the server resolves them with `AppSettings::uiFeatureActive`.

### 3.1 Toggle features (Default + Show/Hide)

When **Hide**: user cannot flip the control; server uses the admin **Default**.  
When **Show**: user’s toggle wins for that request.

| Feature | Default meaning | Effect on accuracy / relevance |
|---------|-----------------|--------------------------------|
| **WorkCover** | On → `mode=rag` **plus** allowlisted live web enrichment; Off → general chat | **Largest switch.** On = uploaded docs RAG **combined with** live search of admin-allowed hosts. Off = no doc retrieval. Standalone **Web** toggle only appears when WorkCover is off. Triggers faithfulness when excerpts (and/or web) exist. |
| **HQ** | On = richer language; Off = Basic | **Style only** (`hqInstruction` vs simple explain). Does **not** run the post-stream faithfulness check. |
| **Deep thinking** | Composer toggle + admin policy (manual / auto / always) + per-chat cap | DeepSeek API thinking / CoT when the turn resolves on. Extra latency/cost. Does **not** replace faithfulness. Auto mode turns Deep on for hard / weak-retrieval turns; toggle forces on under auto. |
| **Web** | Only when WorkCover is **off** | Allowlisted live search without RAG. Still provides grounding → faithfulness runs. When WorkCover is on, that same search is automatic inside Docs mode. |
| **Faithfulness** | Admin slider 0–100 (not a chat toggle) | Automatic when grounding exists unless degree = 0. Higher = stricter rewrite / orphan section-reg scan / strip. Mix mode+degree still steers docs vs web keep/cut. Skip DeepSeek Files. |

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
| **RAG ↔ Web mix** | `complement` / `docs_first` / `web_first` / `compete` + degree 0–100. Changes how Docs mode blends excerpts with allowlisted web (and HQ). Prefer **complement** ~50 unless statute text must dominate (**docs_first**). |
| **Answer length** short / medium / long | Short answers may omit exceptions and related sections → lower *completeness*. Long may pad. **Medium** is the accuracy sweet spot for legal Q&A. |
| **Simple explain age** (5–18) | Only applies when HQ is off. Lower ages → simpler words; meaning must still match excerpts. Too low can oversimplify obligations — keep ≥10 for workplace use. |
| **Web allowed hosts** | Wrong hosts → wrong jurisdiction or junk pages. Keep WA WorkCover (and trusted official hosts only). |
| **Chat caps / retention** | Ops, not accuracy — except pruning mid-investigation can drop history the rewrite relied on. |
| **Export / import settings** | Ops — backup/restore admin knobs. Does not change accuracy until values differ after import. |

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

- WorkCover: Default **on** (faithfulness automatic)  
- HQ: Default **on** (precise language)  
- Deep: **on** for hard multi-instrument questions (API thinking)  
- Web: **on** with tight hosts for site procedures  
- Tags: **Show**; keep corpus tags tidy  
- Adaptive RAG: **on**  
- Answer length: **medium** or **long**  

### Maximum anti-hallucination

1. WorkCover **on** (faithfulness runs on docs+web grounding)  
2. Adaptive RAG **on**  
3. HQ **on** for precise terms (optional style)  
4. Deep **on** for hard questions (optional API thinking)  
5. Well-tagged Act/Regs corpus  
6. Retrieval test until top scores are strong for sample questions  
7. Sources **shown** so humans can verify  

---

## 5. What each mode combination does

| WorkCover | HQ | Deep | Standalone Web | Result |
|-----------|----|------|----------------|--------|
| Off | * | * | Off | General chat; no faithfulness (no grounding) |
| Off | * | * | On | Web grounding + **faithfulness** on web text |
| On | Off | Off | *(folded in)* | RAG+web, Basic language, **faithfulness** |
| On | On | Off | *(folded in)* | RAG+web, precise style, **faithfulness** |
| On | * | On | *(folded in)* | Same + DeepSeek API thinking, then **faithfulness** |

Faithfulness is independent of HQ/Deep whenever grounding exists.

---

## 6. Debugging accuracy

1. **Admin → Retrieval test** — same rewrite → synonyms → tags → search path; inspect search query, quality score, weak flag, and each **matched chunk** (title, header_path, score, text).  
2. Chat with WorkCover on — expand Sources; check titles and excerpt counts.  
3. Read SSE / UI notes when exposed (`Query rewrite`, `Auto tags`, `Retrieval: weak`, `Faithfulness: ok|corrected|kept draft|skipped`).  
4. If wrong section: improve document tags/titles, or ask with section number (legal-ref bonus).  
5. If invented law: confirm WorkCover (or web grounding) was on; check weak/no-hit notes; confirm `Faithfulness:` ran; check `Deep:` note / admin Deep policy (auto may already be on for hard questions).  
6. If follow-up missed context: ensure chat history messages exist (same conversation); rewrite needs prior user turns.

For systematic fixes, use **§7** (tune corpus → tags → synonyms → tag inference). Prefer that over adding embeddings.

---

## 7. Tuning synonyms, tags, and corpus

Lexical retrieval improves most when the **right text exists**, is **tagged**, and everyday questions **expand into document vocabulary**. Use this loop before changing score weights (§9).

### 7.1 The loop

1. Collect **20–40 real questions** in everyday words (not Act jargon).  
2. Run each in **Admin → Retrieval test** (same path as chat).  
3. For each miss, fix **one** layer — in order: **corpus → tags → synonyms → tag inference**.  
4. Re-test until the top hit is the right Act/Regs/guide section.  
5. Spot-check the same prompts in chat with WorkCover on and Sources on.

### 7.2 Corpus (biggest lever)

| Do | Why |
|----|-----|
| Prefer **OCR → chunks** for Act/Regs PDFs | Search needs chunk text; Files-parse stubs rank poorly |
| Clear **titles** (`WCIM Act 2023`, not `scan_final2`) | Title hits score +4; `documentKind` uses title/filename/tags |
| Set **source URL** | Transparency in Sources chips |
| Keep Act and Regulations as **separate** ready docs | Quota + ×1.25 kind boost need both kinds |
| Preserve headings when pasting/chunking | `header_path` drives section-ref bonuses |
| Confirm status **ready** and skim chunks after ingest | Bad OCR = bad retrieval |

**Failure sign:** weak/no hits, or a long guide ranks above the Act rule → missing/mis-chunked Act text or title doesn’t look like an act.

### 7.3 Tags (admin, no code)

**Where:** upload/paste Document details, or edit tags on each Documents row.

- Keep a **small fixed vocabulary** (~10–20), lowercase, hyphenated — e.g. `act`, `regulations`, `weekly-payments`, `return-to-work`, `claim`, `employer`, `worker`, `insurer`, `medical`, `dispute`.  
- Apply **multiple tags** when a doc spans topics.  
- Keep Chat UI **Tags = Show** so users can focus; auto-tags only work if those strings exist on ready docs.  
- Hidden Tags feature forces `focusTags` empty (no user tags **and** no auto-tags).

**Failure sign:** RTW-style question hits random docs → missing `return-to-work` (or similar) on the right documents.

### 7.4 Synonyms (code)

**Where:** `includes/Retrieval.php` → `expandQuerySynonyms()` `$map`.

| Step | Action |
|------|--------|
| 1 | Note the user phrase that failed Retrieval test |
| 2 | Open the **correct chunk** and copy 2–5 words that actually appear there |
| 3 | Map phrase → those words (document vocabulary, not a new paraphrase) |
| 4 | Prefer multi-word keys (`time off work`) over ultra-short ones (`pay`) that fire too often |

Example shape already in code: `'light duties' => 'return to work suitable employment'`.

**Failure sign:** “Search query” barely differs from the raw question and hits miss the section → add a synonym that injects Act/Regs wording.

### 7.5 Tag inference (code, pairs with tags)

**Where:** `includes/Retrieval.php` → `inferFocusTags()` `$syn` array.

- Keys must match **tags on documents** (e.g. `return-to-work`).  
- Values = everyday phrases that should select that tag.  
- New tag `journey-claims` needs a `$syn` entry **or** the literal tag text in the question.

**Failure sign:** Retrieval test shows no/wrong `Tags: …` on a clear topic → tag absent on docs, or no `$syn` entry for that key.

### 7.6 What “good” looks like (Retrieval test)

- **Search query** longer / more legal than the raw question (synonyms and/or rewrite)  
- **Quality: ok** (not weak) — roughly \(S_{\max} \ge 4\), or a strong legal-ref hit (see §9.11)  
- Top hit **title/header** is the section you expect  
- Focus tags sensible when the topic is clear  

Then in chat: Sources match that doc; nonsense questions stay weak/hedged.

### 7.7 Weekly practice

| Step | Action |
|------|--------|
| Log | ~5 production questions that felt wrong |
| Classify | Wrong doc / right doc wrong chunk / no hits / invented answer |
| Fix | Corpus → tags → synonym → inference |
| Verify | Retrieval test + one live chat |
| Avoid | Dumping dozens of vague synonyms at once; re-test after each small batch |

### 7.8 Don’t confuse with toggles

Synonyms / tags / corpus change **what is retrieved**. HQ and answer length change **how the answer is written**. Deep changes **how hard the model thinks** while writing. Faithfulness **audits** grounded answers after the stream. Adaptive RAG **on** helps once tags and scores are already sharp.

For retargeting the same loop to another business, see [ADAPT.md](ADAPT.md).

---

## 8. Limits (honest)

- Lexical retrieval can miss paraphrases that never appear in the Act text even after synonyms.  
- Rewrite / HQ verify depend on DeepSeek flash quality and cost extra latency.  
- Guides can still rank if Act chunks are poorly titled or untagged.  
- Web pages are not law — model instructions prefer Act/Regs, but mixed context can confuse.  
- This is **not** a substitute for legal advice; grounding reduces inventing, it does not guarantee completeness of the corpus.  
- Prefer §7 tuning over adding embedding/vector search for this corpus size and cPanel stack.

---

## 9. Mathematical logic & weights

Source of truth: `includes/Retrieval.php` (plus config defaults in `includes/config.example.php`). All scores are **relative** — they rank chunks; they are not calibrated probabilities.

### 9.1 Configurable size caps (clamped in constructor)

| Symbol | Config key | Default | Clamp |
|--------|------------|---------|-------|
| \(K\) | `rag.top_k` | 14 | 1…40 |
| \(M\) | `rag.max_per_document` | 5 | 1…20 |
| \(K_s\) | `rag.top_k_slim` | 8 | 2…40 |
| \(K_{st}\) | `rag.top_k_slim_tagged` | 6 | 2…40 |
| \(M_s\) | `rag.max_per_document_slim` | 3 | 1…20 |
| \(A\) | `rag.activate_docs_slim` | 3 | 1…20 |

| Adaptive mode | Effective \(K_{\text{eff}}\) | Effective \(M_{\text{eff}}\) |
|---------------|------------------------------|------------------------------|
| `legacy` / Adaptive off | \(K\) | \(M\) |
| `full` (Adaptive on, unfocused) | \(K\) | \(M\) |
| `slim` | \(K_s\) | \(M_s\) |
| `slim_tagged` | \(K_{st}\) | \(M_s\) |

Other caps:

| Limit | Value |
|-------|-------|
| Conversational query length | last 1200 chars of combined recent users (+ last assistant snippet ≤280 chars); last 4 user turns |
| Synonym-expanded / rewritten query | ≤1400 chars |
| Infer focus tags | max 3 tags |
| Keyword candidate pool before diversify | \(K \times 8\) |
| FULLTEXT NL / boolean LIMIT | \(K \times 6\) |
| Section-ref LIKE per needle | LIMIT 30 |
| Broad fallback | only if ready chunk count \(1…400\); score seed 0.2 |
| HQ verify packing / draft caps | packed SOURCE ≤14000 (docs + reserved web ~35% + extra ~20%); draft ≤6000; `max_tokens` 900; reject rewrite if new length &lt; 50% of draft (≥200 chars) |
| Query rewrite | `max_tokens` 180, temperature 0.1; history ≤8 turns × 400 chars |
| Chat history into model | `rag.max_history_messages` default 24 |
| Chunking (ingest) | `chunk_max_chars` 3200, `chunk_overlap` 200 |

### 9.2 Query preparation triggers

**Synonym expansion** — if any map phrase is a substring of the lowercased query, append the expansion tokens (deduped). Map includes e.g. `time off`→`incapacity weekly payments`, `light duties`→`return to work suitable employment`, `stress`→`psychological injury mental`, etc. (full map in `expandQuerySynonyms`).

**LLM rewrite** runs when DeepSeek config is present **and**:

- prior user turns in history ≥ 2, **or**
- latest message matches follow-up regex  
  `(what about|and |also |how about|those|that|it\b|they\b|the same|more on|explain|why|when|who|how long|ok but)`  
  **or** latest message length \(< 48\) chars.

Returned tags are intersected with corpus tags; empty → inferred tags.

**Infer focus tags** — for each available document tag \(t\), score = count of synonym needles found in the query (min needle length 3). Take top \(max(1,\min(5,3))\) = top 3 tags by that count.

### 9.3 Multi-channel merge

Queries considered (order):

1. `alsoQuery` (raw user + synonyms) — query-index weight \(w_q = 1.15\)
2. Prepared/rewritten query if different — \(w_q = 0.9\)
3. If both empty but tags exist — tags joined as the only query

For each query, channels contribute with channel weight \(w_c\), then **effective weight** \(w = w_c \cdot w_q\):

| Channel | \(w_c\) | Notes |
|---------|---------|--------|
| `sectionRefSearch` | 2.4 | Legal section/reg/part needles |
| `keywordSearch` | 1.5 | Hand-tuned lexical score (below) |
| `crossDocumentBoost` | 1.35 | (once, on primary; no \(w_q\)) |
| `naturalLanguageSearch` | 1.2 | MySQL `MATCH … NATURAL LANGUAGE` raw score |
| `booleanOrSearch` | 1.0 | `MATCH … BOOLEAN` OR of stemmed terms `term*` (max 12) |
| `focusTagDocumentHits` | 1.8 | (once; no \(w_q\)) first 3 chunks of tagged docs |
| `broadFallback` | 0.3 | Only if merge still empty |

**Merge rule** for chunk id \(i\) receiving candidate score \(s\) with weight \(w\):

\[
s' = s \cdot w
\]

\[
S_i \leftarrow
\begin{cases}
s' & \text{first time}\\
\max(S_i, s') + 0.15\, s' & \text{already present}
\end{cases}
\]

So duplicate evidence across channels **raises** the score (max + 15% of the new weighted score), not pure replace.

### 9.4 Section-reference base scores (`sectionRefSearch`)

Before merge weight, each matching row gets:

| Condition | Base \(s\) |
|-----------|------------|
| Default text/search match | 8 |
| Needle also in `header_path` | 18 |
| Header matches `\bsection NUM\b` | 24 |
| Header/text matches `\bregulations? NUM\b` | 22 |

After merge with \(w_c=2.4\) and primary \(w_q=1.15\): e.g. exact section heading ≈ \(24 \times 2.4 \times 1.15 \approx 66.2\) before later tag boosts.

### 9.5 Keyword score (`keywordSearch`)

Haystack = lowercase `title + tags + header_path + search_text + text`.

For each query term \(t\) with occurrence count \(c_t\) in haystack (\(c_t>0\)):

\[
\text{lenBoost}(t) = 1 + \min\!\bigl(3,\; |t|/4\bigr)
\]

\[
S \mathrel{+}= c_t \cdot \text{lenBoost}(t)
\]

Plus fixed bonuses **per matching term** (once each if that field contains \(t\)):

| Field contains \(t\) | Add |
|----------------------|-----|
| title | +4 |
| tags | +6 |
| header_path | +3 |

Legal-ref (first matching needle per ref):

| Event | Add |
|-------|-----|
| Needle in haystack | +14 |
| …and also in header_path | +12 more |

Phrase / coverage:

| Event | Add |
|-------|-----|
| Full compacted query (len ≥ 6) substring of haystack | +14 |
| Term coverage | \(10 \times (\text{matchedTerms} / |\text{terms}|)\) |

Document-kind multiplier (applied last to keyword \(S\)):

| `documentKind` | Multiplier |
|----------------|------------|
| `act` or `regulations` | × **1.25** |
| `guide` or `factsheet` | × 0.92 *(intended; see note)* |
| else | × 1 |

**Note:** `documentKind()` currently returns `act | regulations | guidance | other`. The ×0.92 branch keys off `guide`/`factsheet`, so **guidance docs are not down-weighted today**; Act/Regs still get ×1.25. Prefer tags/titles so Act/Regs win via that boost + quota.

Rows with \(S \le 0\) dropped; keep top \(K\times 8\).

### 9.6 Cross-document & focus-tag seeding

**`crossDocumentBoost`** (per ready doc whose title/filename/tags hit terms): best chunk score ≈  
\(\sum_t \text{count}(t) + 3\cdot\mathbf{1}_{\text{header}} + 2\cdot(\#\text{matched title/tag terms})\), floored at 1; title-only stub score 2.5. Then × merge weight 1.35.

**`focusTagDocumentHits`**: for each doc overlapping focus tags,

\[
s_i = 6 + 3\cdot|\text{overlap}| - 0.5\cdot i,\quad i=0,1,2
\]

(first three chunks by index). Merge weight 1.8.

**`boostByFocusTags`** (after merge, on every merged row):

Let \(h\) = number of focus tags found in the doc’s tags string.

\[
S \leftarrow S\cdot(1 + 0.5\,h) + 10\,h
\]

Example: \(h=1\) → \(S' = 1.5S + 10\); \(h=2\) → \(S' = 2S + 20\).

### 9.7 Document activation score (Adaptive)

For each document:

\[
S_{\text{doc}} = \sum_{\text{chunks}} S_{\text{chunk}}
\;+\;
\begin{aligned}[t]
&5 \cdot (\#\text{primary terms in title/filename}) \\
&+\; 6 \cdot (\#\text{primary terms in tags}) \\
&+\; 8 \cdot (\#\text{focus-tag hits on doc tags}) \\
&+\; 15 \cdot \mathbf{1}_{\text{legal ref in title/filename/tags}} \\
&+\; 0.25 \cdot S_{\text{best chunk}}
\end{aligned}
\]

(`tag_hit` / `ref_hit` flags set when those bonuses fire.)

### 9.8 Adaptive mode decision (`decideAdaptiveMode`)

Let \(T = S_{\text{doc}}^{(1)}\), \(U = S_{\text{doc}}^{(2)}\).  
\(\text{nearTop}\) = count of docs with \(S_{\text{doc}} \ge 0.8\,T\).

**Tags disagree → force `full`:** focus tags set, but among top-5 docs none has `tag_hit` with \(S_{\text{doc}} \ge \max(1,\,0.5\,T)\).

**Clear focus** (eligible for slim) if any of:

- query has extracted legal refs, or  
- **sharp**: \(T > 0\) and \((U \le 0.01\) or \(T \ge 2\,U)\), or  
- **tagsAgree**: some doc in top \(A\) has `tag_hit` and score > 0  

**Clear cancelled** if:

- \(\text{nearTop} \ge 4\) and no refs and not tagsAgree, or  
- \(T < 1\) and no refs  

If not clear → mode **`full`**, no activation list.

If clear → activate up to \(A\) docs in score order, stopping when a later doc has \(S < 0.35\,T\) and no `ref_hit`. Always add every `ref_hit` doc. Then:

- tagsAgree and focus tags nonempty → **`slim_tagged`** (\(K_{st}\), \(M_s\))  
- else → **`slim`** (\(K_s\), \(M_s\))

### 9.9 Soft filter on activated docs

Reserve outsider slots:

\[
R = \max\!\bigl(1,\;\lceil 0.25\, K_{\text{eff}} \rceil\bigr),\quad
\text{main} = \max(1,\, K_{\text{eff}} - R)
\]

Take up to `main` hits from activated docs (focus-tag preferred order), then up to \(R\) from non-activated; re-sort by score. Then `diversifyHits`.

### 9.10 Diversify, Act/Regs quota, neighbours

**`diversifyHits`:** walk score-desc; accept chunk if that doc still has \(< M_{\text{eff}}\) chunks in the pick; stop at \(K_{\text{eff}}\); if short, fill from deferred (over-cap) scores.

**`ensureActRegsQuota`:** if corpus has both act + regulations kinds, and picked set lacks either, insert best pool (or first chunk of that kind, score 3.0). If full, replace lowest-scoring non-Act/Regs hit. Runs always in `legacy`/`full`; in slim modes only if refs present or query/tags mention act/section/reg/part/division.

**`expandNeighborChunks`:** for each picked chunk, try index ±1; neighbor score =

\[
\max\!\bigl(0.5,\; 0.72 \cdot S_{\text{parent}}\bigr)
\]

Legacy/full: fill up to \(K_{\text{eff}}\). Slim: at most **one** extra neighbor if under capacity.

### 9.11 Quality gate (repeat)

\[
\text{weak} =
(n=0)
\;\lor\;
(S_{\max} < 4 \land \neg R)
\;\lor\;
(S_{\max} < 2.5)
\]

Interpretation: even with a legal-ref hit in excerpts, \(S_{\max} < 2.5\) is still weak; between 2.5 and 4 needs a ref hit to be “ok”.

### 9.12 Worked micro-example (keyword only)

Query term `incapacity` (len 10) once in text, once in header of an Act chunk; no full-phrase match; 1 of 1 terms matched.

\[
\begin{align*}
S &= 1\cdot\bigl(1+\min(3,10/4)\bigr) + 3 &&\text{(count + header)}\\
 &= 1\cdot(1+2.5) + 3 = 6.5\\
S &\leftarrow 6.5 \times 1.25 = 8.125 &&\text{(act kind)}
\end{align*}
\]

After merge with keyword \(w_c=1.5\) and alsoQuery \(w_q=1.15\): \(S \approx 8.125 \times 1.725 \approx 14.0\). One focus-tag hit then: \(S \leftarrow 14\cdot 1.5 + 10 = 31\). That clears the weak gate (\(S_{\max} \ge 4\)).

### 9.13 What is *not* weighted numerically

- HQ vs Basic — prompt style only, not retrieval scores and not faithfulness
- Deep thinking — API CoT; admin policy manual/auto/always + max per chat + stats
- Faithfulness — automatic post-stream source check when grounding exists
- Answer length — prompt instruction only  
- Web / URL / file context — concatenated into the system prompt after retrieval; they do not change chunk \(S\)  
- Sources visibility — UI only  

---

*When changing weights in `Retrieval.php`, update this section in the same PR/session.*
