# Testing

## Smoke (every deploy)

1. `/install.php` locked; `/` loads; admin login works.
2. Admin → Documents list loads.
3. Retrieval test with a known Act phrase → hits + quality score.
4. New chat, WorkCover **on**:
   - Ask a section-level question → Sources appear (if enabled)
   - Ask a vague follow-up → rewrite should still retrieve sensible chunks
5. WorkCover **off** → general reply, no Sources.
6. HQ **on** after a WorkCover answer → note may show `HQ check: ok` / corrected text if exposed.
7. Web **on** with allowed host only; disallowed URL rejected.

## Accuracy checklist

Use [ACCURACY.md](ACCURACY.md) profiles. Sample prompts:

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

- HQ off still Simple/Basic (not “force plain” legacy)
- Citation numbers must not appear in bubble text
- Pasted ingest appears in Documents list
