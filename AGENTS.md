# Agents — WANDA repo

## Default

- **Desktop agent** for day-to-day code edits.
- **Cloud agent** only when asked for deploy/publish-style work (this app’s primary target is **cPanel**, not Cursor Live App Postgres).

## Areas

Respect `AREA.md`. One agent owns one area per session unless the user asks for integration.

## Coordination

- Agents do not auto-chat. Use GitHub/commits + `HANDOFF.md` when the user wants a session log.
- Read `README.md`, `handover.md`, and `MDfiles/ACCURACY.md` before changing chat/retrieval behaviour.

## Merge policy

- Prefer small focused PRs/commits.
- Do not force-push `main` unless the user explicitly requests it.
- Do not commit secrets or `config.php`.

## Docs

- Do not invent new `.md` files mid-task unless the user asks.
- When accuracy/settings change, flag stale docs in the chat summary if docs were not updated in that turn.
