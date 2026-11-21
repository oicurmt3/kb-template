# Design

## Theme

Modern light-grey base with CSS variables (`--surface`, `--muted`, `--primary`, `--danger`, etc.) in `assets/style.css`.

## Buttons

Shared shapes/sizes by function:

- **Primary** — main actions (Send, Save, Search)
- **Secondary** — alternate actions
- **Destructive** — delete
- **Ghost** — low-emphasis / toolbar

Touch targets ~44px minimum on mobile.

## Chat layout

- Sidebar: conversation list (when history enabled)
- Main: messages + composer
- Composer toolbar: **Docs** / **HQ** / **Deep think** / **Web** (independent Docs vs Web; + tags when shown)
- Waiting for first SSE tokens: italic rotating wait lines (`.msg-wait`), then normal stream cursor
- Options sheet: compact **inline** `URL` + field and `File` + Choose (separate controls; coloured keyword labels)
- Admin Chat UI features: coloured keywords + live per-row status hints from Default / Show-Hide
- Sources and Related questions chips below assistant messages when enabled
- Optional note under assistant bubble when faithfulness revises the streamed draft
- Clear-input control sits left of the textarea scrollbar (no heavy background)

## Responsive

- Desktop: horizontal control row where space allows
- Narrow: stacked / icon-friendly controls
- Icon-only SVG buttons acceptable on mobile to save space

## Admin

- Every admin section is a **collapsible card** (`<details class="admin-card-details">`), **closed by default**
- Summary row uses `.admin-card-summary` with a small chevron; optional hint text (e.g. chat/doc counts)
- Upload: dropzone stages files; primary **Upload** submits; ghost **Clear files**
- Export / import sits near the top of admin for backup/restore

## Content rules

- Model answers are **plain text** (no Markdown styling expected in bubbles)
- **App display name** comes from Admin branding / `config.php` title (per deployment)
- Prefer calm professional UI over dense “dashboard” chrome on the public chat
