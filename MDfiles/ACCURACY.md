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

Synonyms / tags / corpus change **what is retrieved**. HQ, Deep, and answer length change **how the answer is written**. Adaptive RAG **on** helps once tags and scores are already sharp.

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
| HQ verify excerpt / draft caps | excerpts ≤10000 chars; draft ≤6000; `max_tokens` 900 |
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

- HQ vs Basic — prompt style (+ optional faithfulness LLM), not retrieval scores  
- Deep thinking — model reasoning flag  
- Answer length — prompt instruction only  
- Web / URL / file context — concatenated into the system prompt after retrieval; they do not change chunk \(S\)  
- Sources visibility — UI only  

---

*When changing weights in `Retrieval.php`, update this section in the same PR/session.*
