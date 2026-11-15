# Plan: Science lesson ↔ lab prep coordinator

**Status:** Planned — do not build until explicitly asked.  
**Related product:** Sibling to WANDA (same PHP/vanilla/cPanel/DeepSeek stack; different domain UI).  
**Reuse from WANDA when building:** collapsible admin cards, staged upload + submit, settings JSON export/import (`api/export.php`), RAG↔Web mix patterns, chunk identity (`document_id` / `chunk_index`).  
**Problem:** Science teachers plan class activities while lab technicians prepare resources — today that handoff is email/tribal knowledge and breaks under lead time, stock, and safety constraints.

---

## 1. Goal

Give teachers and lab techs one place to:

1. Plan a lesson/activity grounded in **curriculum + local prep sheets + risk assessments**.  
2. Turn a plan into a **resource / set-out request** the tech can confirm.  
3. Track status through prep day.  
4. Optionally ask a **grounded assistant** (“what can we run with what we stock?”).

Success = fewer last-minute scrambles, fewer wrong reagents, shared written truth.

---

## 2. Assumptions (confirm before build)

| Topic | Assumed default |
|-------|-----------------|
| **Deploy** | cPanel PHP + MariaDB (same as WANDA); not Live App Postgres |
| **Who uses it** | Department staff only (teachers + lab techs + optional HOD) — not students |
| **Auth** | Shared app logins or roles: `teacher`, `tech`, `admin` (passwords/bcrypt; CSRF) |
| **Devices** | Desktop-first for planning; phone OK for tech checklist on the lab floor |
| **Agents** | Desktop agent for code; Cloud only if asked to deploy |
| **Scope v1** | One school/department, one inventory list, no purchasing integration |

If any assumption is wrong, adjust before Phase 1 code.

---

## 3. Users & jobs-to-be-done

| Role | Jobs |
|------|------|
| **Teacher** | Pick date/class/topic; get activity options that fit time & curriculum; submit resource request; see tech confirmation |
| **Lab tech** | See incoming requests; edit quantities; check stock/hazards; mark prepped; print/set-out list |
| **Admin / HOD** | Upload corpus; manage users; inventory; term calendar; audit risky requests |

---

## 4. Product shape (not “chat only”)

```
┌─────────────┐     ┌──────────────┐     ┌─────────────────┐
│  Curriculum │     │  Inventory   │     │  Risk / prep    │
│  + past     │────►│  (structured)│◄────│  sheets (RAG)   │
│  plans RAG  │     └──────┬───────┘     └────────┬────────┘
└──────┬──────┘            │                      │
       │                   ▼                      │
       │            Lesson request                │
       │            + AI draft list               │
       │                   │                      │
       │                   ▼                      │
       │            Tech review → Prepped         │
       └──────────► Optional grounded chat ───────┘
```

- **Primary UI:** request board + lesson form + checklist (Kanban or list by date).  
- **Secondary UI:** RAG assistant (WANDA-like) for planning questions.  
- **Not in v1:** student portal, automatic chemical ordering, full timetable sync.

---

## 5. Core data model (v1)

| Entity | Key fields |
|--------|------------|
| `users` | role (teacher/tech/admin), name, email |
| `classes` / `cohorts` | year level, size default |
| `rooms` | lab name, capacity, fume cupboard flag |
| `inventory_items` | name, aliases, unit, qty_on_hand, hazard flags, location |
| `documents` / `chunks` | same RAG pattern as WANDA (prep sheets, RA, outlines) |
| `lesson_requests` | date, period, teacher_id, year_level, topic, duration_min, class_size, room_id, status |
| `lesson_request_lines` | item_id or free text, qty, notes, tech_adjusted_qty |
| `activity_suggestions` | optional cache of AI draft (text + citations) |
| `settings` | lead_time_hours, term dates, feature flags |

**Statuses:** `draft` → `submitted` → `in_review` → `approved` → `prepped` → `completed` / `cancelled`.

---

## 6. AI / RAG usage (what is grounded vs calculated)

| Feature | Mechanism |
|---------|-----------|
| “What pracs fit this topic/time?” | RAG over curriculum + past plans + prep sheets |
| Draft equipment list | RAG + prompt constrained to inventory names when possible |
| Safety notes | RAG over risk assessments / SOPs only |
| Stock sufficiency | **DB math** on `inventory_items` — not RAG |
| “Explain this spike in requests” | Later analytics — out of v1 |

Reuse WANDA patterns: synonym map (student/teacher slang → doc terms), tags (`year-10`, `acids`, `demo`, `class-prac`), weak-hit refuse, Sources chips, HQ for staff precision.

**Hard rules in prompts:**

- Do not invent chemicals or concentrations not in prep sheets / inventory.  
- Prefer microscale / existing stock when docs allow.  
- Always surface hazard excerpts when RA chunks are retrieved.  
- Say when materials don’t cover the request.

---

## 7. Phased delivery

### Phase 0 — Discovery (no code)

- List 10 recurring practicals + their prep sheets.  
- Agree lead time (e.g. 48 business hours).  
- Decide who owns inventory accuracy.  
- Copyright check on curriculum PDFs.

### Phase 1 — MVP workflow (ship value without chat)

- Auth + roles.  
- CRUD inventory (simple).  
- Lesson request form + status board.  
- Tech can edit lines and mark prepped.  
- Email or on-screen notify (optional: simple in-app only first).  

**Exit criteria:** Teacher and tech complete one real practical via the app.

### Phase 2 — Corpus + grounded assistant

- Ingest outlines, prep methods, RAs (OCR/chunk like WANDA).  
- Tags + Retrieval test.  
- “Suggest activity & draft list” button on the request form (RAG → editable lines).  
- Chat panel: planning Q&A, WorkCover-equivalent = “Department docs” mode.

**Exit criteria:** Suggestions cite real prep sheets; tech rarely starts from blank lists.

### Phase 3 — Lab-floor polish

- Mobile-friendly set-out checklist.  
- Print/PDF set-out.  
- Low-stock warnings on submit.  
- Duplicate last year’s request.  
- Basic stats: requests/week, late submissions, cancellations.

### Phase 4 — Optional later

- Timetable import.  
- Purchase/order flag when stock low.  
- Multi-campus.  
- Student-facing curriculum bot (separate instance — see earlier science-department FAQ idea).  
- Admin-editable synonyms (WANDA backlog pattern).

---

## 8. UX outline

**Teacher home**

- Upcoming classes / “New lesson request”.  
- Form: when, who, topic, wet/dry, constraints.  
- Button: **Suggest from department materials** → activity blurb + draft lines + sources.  
- Submit for tech.

**Tech home**

- Queue sorted by date / urgent.  
- Request detail: edit qty, substitute item, add hazard note.  
- Checklist mode: tick lines while setting out.  
- Mark prepped / completed.

**Admin**

- Users, inventory, document ingest, Adaptive RAG / answer settings (clone from WANDA admin patterns).

---

## 9. Stack & reuse from WANDA

| Reuse | New |
|-------|-----|
| DeepSeek client, OCR ingest, chunker | Roles + lesson_requests schema |
| Retrieval + grounding + HQ verify | Inventory + stock checks |
| Admin doc UI patterns | Request board + checklist UI |
| CSRF, rate limits, session cookies | Teacher/tech notifications |
| cPanel deploy docs pattern | Science synonym/tag maps |

**Repo strategy (decide at kickoff):**

- **A:** New repo (e.g. `lab-coordinator`) — cleaner product boundary.  
- **B:** Folder/sibling under a monorepo.  
- **Not recommended:** Bolt onto WANDA WorkCover UI (confuses domains).

---

## 10. Security & safety

- Staff-only auth; no public student access in v1.  
- Audit log of who approved hazardous practicals (phase 3+).  
- AI never overrides tech confirmation for wet labs.  
- Disclaimers: assistant supports planning; **tech + teacher remain responsible** for safety compliance.  
- Secrets in `config.php`; no API keys in browser.

---

## 11. Risks

| Risk | Mitigation |
|------|------------|
| Stale inventory | Tech owns weekly stock update; warn if inventory older than N days |
| Hallucinated reagents | Grounding + inventory allowlist in draft lines; tech must approve |
| Copyrighted textbooks | Only department-owned / licensed materials |
| Teachers bypass lead time | Config minimum notice; HOD override |
| Scope creep into full LMS | Park timetable/LMS until Phase 4 |

---

## 12. Effort sketch (order of magnitude)

| Phase | Rough effort |
|-------|----------------|
| Phase 1 MVP workflow | Small–medium webapp (forms + roles + statuses) |
| Phase 2 RAG suggest + chat | Medium (mostly WANDA port + prompts/tags) |
| Phase 3 polish | Small |

Exact days depend on inventory complexity and how clean prep PDFs are.

---

## 13. Kickoff checklist (before coding)

1. Confirm assumptions in §2 (deploy, auth, devices).  
2. Choose repo strategy (§9).  
3. Name the product.  
4. Collect sample prep sheet + RA + unit outline for a pilot subject (e.g. Year 10 Chemistry).  
5. Define lead time and wet-lab approval rule.  
6. Explicit “build Phase 1” instruction.

---

## 14. Out of scope (v1)

- Diagnosing student work / marking  
- Replacing official risk-assessment sign-off processes  
- Live integration with supplier catalogues  
- Embeddings / vector DB (same stance as WANDA — tune corpus/tags first)

---

*When build starts: create `README.md` / `handover.md` in the new app repo; link back to this plan; do not implement inside the WorkCover WANDA product unless the user asks for a monorepo.*
