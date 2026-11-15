# Contributing

## Stack constraints

Plain PHP 8+, PDO, vanilla JS/CSS. No frameworks, no Node, no package manager.

## Branches

- Day-to-day: edit on your working branch; keep `main` deployable.
- Parallel agents: separate branches/worktrees per area (see root `AGENTS.md` / `AREA.md`).

## Commits

- Commit only when asked (or when you own the release).
- Message = why, not a file list.
- Never commit `includes/config.php`, dumps, logs, or live uploads you care about keeping private.

## Before calling done

1. `php -l` on touched PHP
2. Smoke path in [TESTING.md](TESTING.md)
3. If accuracy behaviour changed, update [ACCURACY.md](ACCURACY.md) / [handover.md](../handover.md) when the user wants docs updated
4. If admin export/settings change, keep `api/export.php` and [SECURITY.md](SECURITY.md) in sync

## Do not

- Add embedding/vector dependencies without an explicit product decision (DeepSeek has no embeddings here)
- Enable unrestricted open-web search
- Put secrets in the repo
- Import arbitrary settings keys without the whitelist in `AppSettings::importSettingsExport`
