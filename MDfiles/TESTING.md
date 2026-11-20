# Testing

## Smoke (every deploy)

1. `/install.php` locked; `/` loads; admin login works.
2. Admin page: all cards **collapsed** by default; expand Documents → list loads.
3. Retrieval test with a known Act phrase → matched chunks + quality score.
4. New chat, **Docs & Web** on:
   - Ask a section-level question → Sources appear (if enabled); allowlisted web may enrich
   - Ask a vague follow-up → rewrite should still retrieve sensible chunks
5. Docs & Web **off** → general reply, no Sources; optional **Web only**.
6. Docs & Web (or Web only) answer with grounding → if Faithfulness slider is 0, notes `Faithfulness: off` and the streamed answer stays. At ≥55, notes may show `ok` / `corrected` / `kept draft (shrink|hedge|…)`. HQ = language only. Deep policy default **manual**.
7. Web only **on** (Docs & Web off) with allowed host only; disallowed URL rejected.
8. Admin → Export settings → Import same file → forms reload with same values.
8a. Admin → Export/import → choose **customer-kiosk** (or other) preset → Apply → confirm toggles/branding update; documents unchanged.
8b. Assistant & prompts → save custom **Docs & Web** prompt → chat obeys Admin text even if `config.php` differs (clear field to fall back).
9. Admin → Export chunks → JSON has `documents` + `chunks` counts.
10. Upload: select files → pending list → **Upload** (not auto); paste still **Add & chunk**.
10a. After `alter_rag_structure.sql`: one Act PDF → **Re-parse** → **Continue** until `parsed` → **Re-chunk** → status `ready`; Retrieval test finds section text with `header_path`.
10b. `api/health.php` shows no missing `domain_set` / `pipeline_stage` hints.
11. Admin → Deep thinking: save Auto + max 3; ask a long multi-part Docs & Web question → `Deep: on (auto_on…)`; Reset Deep stats clears counters.
12. Admin → Faithfulness: set 0 → `Faithfulness: off` (stream unchanged). Set 40 → `Faithfulness: ok` but bubble unchanged. Set 80 → surgical `corrected` only if rewrite keeps ≥85% length; hedge/shrink → `kept draft`.
13. Admin → Web settings: Exclude document Source URLs → notes `Doc source URL fetch excluded`; Include + allowlisted Source URL on a hit doc → `Doc source URL: …`.
14. Options → URL / File: compact inline rows; File text `.txt` → Ready · N chars; PDF → Ready · OCR; disallowed host URL rejected.
15. Admin → Chat UI features: change Docs & Web / Web only Default or Show-Hide → per-row hints update live.

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
php -l includes/Chunker.php
php -l includes/Ingest.php
php -l includes/StructureParse.php
```

## UI

- Mobile width: composer toggles usable; clear-input visible
- History off: sidebar hidden; chat still works
- Hide Tags: no tag UI; auto-tags should not apply

## Regression watch

- HQ off still Simple/Basic (not “force plain” legacy); HQ does not gate faithfulness
- Faithfulness + complement must not wipe allowlisted web enrichment; oversized / hedge “corrections” keep the draft
- Faithfulness 1–50 must not replace the streamed bubble; generic “final processing” note must not appear
- Plain chat with no web/URL/attach → no faithfulness rewrite
- Citation numbers must not appear in bubble text
- Pasted ingest appears in Documents list
- Docs & Web on must not hide allowlisted web enrichment; Web only only when Docs & Web off
- Settings import rejects chunks export files; unknown keys ignored
- Deep cap: after max Deep turns in one chat, notes show `Deep: off (capped…)` until a new chat
- Doc Source URL Exclude → no live fetch; Include + allowlisted URL on hit → fetch note
- File attach PDF/Office uses DeepSeek OCR (or Files parse); URL and File are separate inline controls
- Documents stuck in `parsing` need **Continue**; do not Re-chunk until `parsed` if migrating with structure parse
- Legislation answers should keep must/may distinct when L7 grounding applies
