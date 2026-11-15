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
- Composer toolbar: WorkCover / HQ / Deep / Web (+ tags, URL, file when shown)
- Sources and related chips below assistant messages when enabled
- Clear-input control sits left of the textarea scrollbar (no heavy background)

## Responsive

- Desktop: horizontal control row where space allows
- Narrow: stacked / icon-friendly controls
- Icon-only SVG buttons acceptable on mobile to save space

## Content rules

- Model answers are **plain text** (no Markdown styling expected in bubbles)
- Brand name **WANDA** is the product identity in intro / titles
- Prefer calm professional UI over dense “dashboard” chrome on the public chat
