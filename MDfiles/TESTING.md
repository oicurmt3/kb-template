# Testing

## Smoke (every deploy)

1. `/install.php` locked; `/` loads; admin login works.
2. Admin page: all cards **collapsed** by default; expand Documents → list loads.
3. Retrieval test with a known Act phrase → matched chunks + quality score.
4. New chat, WorkCover **on**:
   - Ask a section-level question → Sources appear (if enabled); allowlisted web may enrich
   - Ask a vague follow-up → rewrite should still retrieve sensible chunks
5. WorkCover **off** → general reply, no Sources; optional standalone Web.
6. WorkCover (or standalone Web) answer with grounding → notes may show `Faithfulness: ok` / `corrected` / `kept draft` / `skipped (DeepSeek Files…)`; web-supported claims should remain under complement. HQ only changes language richness; Deep only enables API thinking.
7. Web **on** (WorkCover off) with allowed host only; disallowed URL rejected.
8. Admin → Export settings → Import same file → forms reload with same values.
9. Admin → Export chunks → JSON has `documents` + `chunks` counts.
10. Upload: select files → pending list → **Upload** (not auto); paste still **Add & chunk**.

## Accuracy checklist

Use [ACCURACY.md](ACCURACY.md) profiles. When hits are wrong, tune with [ACCURACY.md §7](ACCURACY.md) (corpus → tags → synonyms → tag inference) via Admin → Retrieval test — do not jump to embeddings.

Sample prompts:

| Prompt | Expect |
|--------|--------|
| “What is weekly payments under the Act?” | Act/Regs excerpts; section language |
| “time off work after injury” | Synonyms → incapacity / weekly payments hits |
| After a claim answer: “what about light duties?” | Follow-up rewrite keeps RTW / suitable employment |
| Nonsense / out-of-corpus | Weak/no-hit hedging; no invented sections |

## PHP syntax

```bash
php -l api/chat.php
php -l includes/Retrieval.php
php -l includes/Settings.php
```

## UI

- Mobile width: composer toggles usable; clear-input visible
- History off: sidebar hidden; chat still works
- Hide Tags: no tag UI; auto-tags should not apply

## Regression watch

- HQ off still Simple/Basic (not “force plain” legacy); HQ does not gate faithfulness
- Faithfulness + complement must not wipe allowlisted web enrichment; oversized “corrections” keep the draft
- Plain chat with no web/URL/attach → no `Faithfulness:` note
- Citation numbers must not appear in bubble text
- Pasted ingest appears in Documents list
- WorkCover on must not hide allowlisted web enrichment; standalone Web only when WorkCover off
- Settings import rejects chunks export files; unknown keys ignored
