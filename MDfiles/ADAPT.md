# Adapting WANDA to another business or entity

This app is a **document-grounded chatbot shell**. The WA workers compensation branding and legal tuning are layers on top. You can retarget it to a club, school, council, clinic, retailer, franchise, or any SMB that answers questions from a trusted document set.

Related: [ACCURACY.md](ACCURACY.md) (how grounding works), [DEPLOY.md](DEPLOY.md) (hosting), [SECURITY.md](SECURITY.md).

---

## 1. What stays vs what you change

| Keep (engine) | Replace (domain skin) |
|---------------|------------------------|
| PHP chat + SSE streaming | App name, intro, logos/copy |
| MariaDB conversations / docs / chunks | Uploaded corpus (policies, manuals, FAQs) |
| Retrieval, Adaptive RAG, weak-hit grounding | Synonym map, tag synonyms, source hierarchy wording |
| Automatic faithfulness check + HQ style / Deep thinking | `system_prompt` / `rag_system_prompt` |
| Admin ingest, tags, Retrieval test, settings export/import | Web allowlist hosts |
| Feature toggles (Default / Show-Hide), RAG↔Web mix | Label “WorkCover” → your product mode name (optional code tweak) |

You do **not** need embeddings, Node, or a rewrite of the architecture for a typical SMB knowledge base.

---

## 2. Decision checklist (before you touch code)

1. **Who asks?** Staff only, customers, public kiosk, or members?
2. **What is authoritative?** Policies? Contracts? Product manuals? Website only?
3. **Must answers stay inside docs?** Almost always yes → keep RAG (“WorkCover”) default **on**.
4. **Tone?** Plain language (HQ off) vs precise / technical (HQ on).
5. **Live web?** Only if official pages must stay current; lock hosts tightly.
6. **Shared device?** Chat history **off** for kiosks.
7. **Jurisdiction / location** for date/context reminders (timezone, city, language).

---

## 3. Zero-code / admin-first adaptation

Do these first; many deployments need little else.

### 3.1 Fresh install

1. Copy the project; create DB; copy `includes/config.example.php` → `includes/config.php`.
2. Set DeepSeek API key + admin password hash; run `install.php`; lock installer.
3. Do **not** copy WANDA’s Act/Regs uploads unless that is your domain.
4. Optional: after tuning one environment, **Export all settings** and **Import settings** on the new host (admin card). Chunk export is backup-only (no import yet) — re-upload your own corpus.

### 3.2 Branding in config

Edit `includes/config.php` → `app`:

| Key | Example for a cafe franchise |
|-----|------------------------------|
| `title` | `BeanBook Assistant` |
| `system_prompt` | General chat persona (RAG off) |
| `rag_system_prompt` | “You are BeanBook’s store assistant. Answer only from the provided handbook excerpts…” |
| `timezone` / `user_location` / `user_language` | Store region + language |

**`rag_system_prompt` pattern (copy and rewrite):**

1. Who you are (entity name + role).  
2. Plain text only; no Markdown; no `[1]` citations in the reply.  
3. **Source hierarchy** for *their* docs (e.g. “Franchise Operations Manual overrides store tip sheets; tip sheets are guidance only”).  
4. How to answer: direct answer → cite the policy/section name if present → note exceptions → say what’s missing.  
5. Refuse inventing rules not in the excerpts.

### 3.3 Admin → Chat UI

| Setting | Typical SMB choice |
|---------|-------------------|
| Intro message | Welcome in their voice; say what docs the bot uses |
| WorkCover (RAG) | Default **on**, Show (or Hide + default on for kiosks) |
| HQ | Off for public/simple; On for staff/compliance |
| Deep / Web | Off unless needed |
| Tags / Sources / Related | Show |
| Chat history | Off on shared tablets |
| Adaptive RAG | **On** |
| RAG ↔ Web mix | **Complement** (or docs_first if policy PDFs must dominate live web) |
| Answer length | Medium |
| Simple explain age | 10–14 for public; ignore if HQ default on |

### 3.4 Web domains

Admin → allowed hosts: **only** their official site(s) (and maybe a parent company help centre). Empty/wrong hosts → wrong answers or useless web noise.

### 3.5 Corpus

Upload or paste:

- Policies, SOPs, handbooks, contracts summaries, product sheets, FAQs  
- Prefer **OCR → chunks** for PDFs you need to search  
- Set **title**, **tags**, **source URL** on every doc  

**Tag ideas (examples):**

| Entity | Example tags |
|--------|----------------|
| Retail / franchise | `returns`, `roster`, `food-safety`, `pos`, `opening` |
| School / club | `enrolment`, `fees`, `safety`, `events`, `bylaws` |
| Clinic | `booking`, `privacy`, `fees`, `aftercare` |
| Council / NFP | `permits`, `grants`, `venues`, `volunteer` |
| HR internal | `leave`, `conduct`, `whs`, `payroll` |

Run **Admin → Retrieval test** with real customer questions until top hits look right.

---

## 4. Light code skin (recommended for a clean product)

Still small diffs; keeps “WorkCover” from confusing users.

| Area | Files | Change |
|------|-------|--------|
| Composer label | `assets/admin.js` (`workcover: '…'`), `assets/chat.js` placeholders | e.g. `Knowledge base`, `Handbook`, `Ask Docs` |
| Conversation prefix | `api/chat.php` (`[WorkCover]`) | e.g. `[Docs]` |
| Admin hints | `admin.php` copy | Remove Act/Regs-specific wording |
| Default intro | `Settings.php` → `defaultUiConfig()['intro']` | New brand welcome |
| Page titles | `index.php` / `login.php` use `app.title` | Usually automatic |

Optional CSS: accent colour in `assets/style.css` variables to match brand (keep light-grey base).

---

## 5. Domain tuning (accuracy for *their* language)

Without this, retrieval still works but misses slang and house terms. Use the same **corpus → tags → synonyms → tag inference** loop as [ACCURACY.md §7](ACCURACY.md); score math is in [ACCURACY.md §9](ACCURACY.md).

### 5.1 Synonym map

File: `includes/Retrieval.php` → `expandQuerySynonyms()`.

Replace the WA workers-comp map with phrases customers actually say:

```text
"how long for refund" → "returns refund period"
"open late"           → "trading hours closing time"
"sick day"            → "personal leave unplanned absence"
```

Keep expansions in the **same vocabulary as the PDFs**.

### 5.2 Tag inference synonyms

Same file → `inferFocusTags()` `$syn` array. Keys should match **tags you put on documents**. Map everyday words → those tags.

### 5.3 Document kind / hierarchy (if you have “law vs guide” equivalents)

Today `documentKind()` knows Act / Regulations / guidance for WCIMA. For another entity either:

- **Simple path:** encode hierarchy only in `rag_system_prompt` (“Manual beats FAQ”), and use tags (`policy`, `faq`), or  
- **Code path:** retarget `documentKind()`, Act/Regs quota, and ×1.25 boosts to *your* primary vs secondary doc types (e.g. `policy` vs `faq`).

If you skip the code path, rely on tags + Adaptive RAG + clear prompts.

### 5.4 Grounding blurb

`Retrieval::buildContext()` speaks about Act/Regulations. For a non-legal SMB, rewrite that block to:

- Answer only from excerpts  
- Name policy/section titles when present  
- Your hierarchy (manual > FAQ, etc.)  
- Do not invent prices, dates, or obligations  

Keep the “plain text / no citation numbers” rules.

### 5.5 HQ + Basic instructions

`Settings.php` → `hqInstruction()` / `simpleExplainInstruction()` — swap “legal/technical” wording for “policy/product” if needed. Automatic faithfulness (`verifyGroundedAnswer`) stays valuable for any entity: prefers `OK`, packs docs+web+attach with a reserved web budget, rejects harmful shrinks, skips when DeepSeek Files are attached. Deep = optional API thinking only.

---

## 6. Suggested profiles

### Customer-facing (cafe, shop, clinic)

- RAG default on; HQ off; Web off; history off on counter tablet  
- Short/medium answers; Sources on  
- Synonyms for refunds, hours, booking slang  

### Staff / internal (HR, franchise ops)

- RAG on; HQ on; Adaptive on; Web = intranet host only if needed  
- Tags heavily used; Deep on for long multi-policy questions  

### Membership org / school

- Bylaws + fee schedule as authoritative; newsletters as guidance only (prompt hierarchy)  
- Related questions on; strong enrolments/fees tags  

---

## 7. What not to expect without more build work

| Need | Gap |
|------|-----|
| Live orders / stock / bookings | Needs APIs; this bot is document RAG |
| Multi-tenant “one install, many businesses” | Not built; use separate installs or DB+config per tenant |
| Millions of chunks / fuzzy semantic search | Lexical/FULLTEXT only (no embeddings) |
| Mobile native apps | Responsive web only |
| Unrestricted open web answers | Intentionally blocked; allowlist only |

---

## 8. Migration recipe (WANDA → NewCo)

1. New hosting DB + `config.php` (new title, prompts, location).  
2. Empty corpus; ingest NewCo docs with tags + source URLs.  
3. Admin UI intro + feature defaults.  
4. Replace synonym + tag-inference maps.  
5. Soft-rename WorkCover label in UI strings.  
6. Adjust `buildContext` / `rag_system_prompt` hierarchy.  
7. Retrieval test 20 real questions; fix tags/synonyms until scores are strong.  
8. Smoke: RAG on/off, Faithfulness notes, HQ style, Deep thinking, Sources, kiosk history off.  
9. Lock installer; rotate admin password; confirm `config.php` not in git.

---

## 9. Naming the product mode

Users see a toggle that today means “search our uploaded docs.” Call it whatever fits:

| Domain | Label ideas |
|--------|-------------|
| Any SMB | Knowledge base / Ask our docs |
| Franchise | Handbook |
| School | School info |
| Legal/compliance | still “WorkCover” / “Compliance docs” if accurate |

Server mode stays `rag` vs `chat` in `api/chat.php`; only labels and prompts need to match the business story.

---

## 10. Success criteria

You have adapted successfully when:

1. Retrieval test returns the **right handbook section** for top customer questions.  
2. With RAG on, the bot **refuses** or hedges when the answer is not in the docs.  
3. Sources chips point at the correct titles (and URLs if set).  
4. Staff agree the tone matches the entity (Basic vs HQ).  
5. No WA workers-comp phrasing remains in customer-visible UI (unless that *is* the product).

The accuracy pipeline (rewrite → synonyms → tags → scored retrieval → grounded prompt → optional HQ verify) transfers as-is; you are teaching it a new library, not inventing a new engine.
