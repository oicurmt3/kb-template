<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ensure_session_cookie();
$loggedIn = admin_is_logged_in();
$appCfg = $config['app'] ?? [];
$appTitle = AppSettings::appDisplayTitle($appCfg);
$uiBranding = AppSettings::publicUiBranding($appCfg);
$docsModeLabel = (string)($uiBranding['docs_mode_label'] ?? 'Docs');
if ($docsModeLabel === '') {
    $docsModeLabel = 'Docs';
}
$simpleExplainAge = AppSettings::simpleExplainAge();
$uiConfig = AppSettings::uiConfig();
$uiConfig['simple_explain_age'] = $simpleExplainAge;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($appTitle) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(url('assets/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<div class="app">
  <header class="app-header" aria-label="App header">
    <button type="button" id="open-history" class="btn btn-secondary mobile-bar-btn header-chats-btn" aria-expanded="false" aria-controls="sidebar">
      Chats
    </button>
    <button type="button" id="header-new-chat" class="btn btn-secondary mobile-bar-btn header-new-chat-btn" hidden>
      New chat
    </button>
    <div class="mobile-bar-brand"><?= htmlspecialchars($appTitle) ?></div>
    <button type="button" id="open-help" class="btn btn-secondary mobile-bar-btn" aria-expanded="false" aria-controls="help-modal">
      Help
    </button>
  </header>

  <div id="help-backdrop" class="drawer-backdrop help-backdrop" hidden></div>
  <div id="help-modal" class="help-modal" role="dialog" aria-modal="true" aria-labelledby="help-title" hidden>
    <div class="help-modal-panel">
      <div class="help-modal-head">
        <h2 id="help-title">Help</h2>
        <button type="button" id="close-help" class="btn btn-ghost help-close" aria-label="Close help">×</button>
      </div>
      <div class="help-modal-body">
        <section>
          <h3>What is <?= htmlspecialchars($appTitle) ?>?</h3>
          <p><?= htmlspecialchars($appTitle) ?> answers from your organisation’s uploaded documents when <strong><?= htmlspecialchars($docsModeLabel) ?></strong> is on. Turn on <strong>Web</strong> separately to include allowlisted live web pages.</p>
        </section>
        <section>
          <h3>Toolbar icons</h3>
          <ul class="help-icon-list">
            <li>
              <span class="help-icon tool-btn is-on" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zM8 12h8v2H8v-2zm0 4h8v2H8v-2z"/></svg>
              </span>
              <span><strong><?= htmlspecialchars($docsModeLabel) ?></strong> — search your uploaded documents (policies, handbooks, guides). Independent of Web.</span>
            </li>
            <li>
              <span class="help-icon tool-btn" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M19 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2m-8 11H9.5v-2h-2v2H6V9h1.5v2.5h2V9H11zm7-1c0 .55-.45 1-1 1h-.75v1.5h-1.5V15H14c-.55 0-1-.45-1-1v-4c0-.55.45-1 1-1h3c.55 0 1 .45 1 1zm-3.5-.5h2v-3h-2z"/></svg>
              </span>
              <span><strong>HQ</strong> — off = Basic (plain language); on = richer, more precise wording. Style only — does not change facts after the answer.</span>
            </li>
            <li>
              <span class="help-icon tool-btn is-on" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M9 21h6v-1.5H9V21zm3-19C7.03 2 3.2 5.6 3.02 10.5c-.1 2.7 1.1 5.1 3.1 6.6.6.45.9 1.1.9 1.9V19h10v-.01c0-.8.3-1.44.9-1.89 2-1.5 3.2-3.9 3.1-6.6C20.8 5.6 16.97 2 12 2zm0 15c-.55 0-1-.45-1-1h2c0 .55-.45 1-1 1zm4.05-3.35c-.7.52-1.2 1.2-1.4 1.95h-5.3c-.2-.75-.7-1.43-1.4-1.95C6.6 12.5 5.9 11 6 9.35 6.15 6.4 8.7 4 12 4s5.85 2.4 6 5.35c.1 1.65-.6 3.15-1.95 4.3z"/></svg>
              </span>
              <span><strong>Deep think</strong> — DeepSeek API thinking before the answer; slower, often more careful. Admin may auto-enable it on hard questions; your toggle forces it on.</span>
            </li>
            <li>
              <span class="help-icon tool-btn is-on" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
              </span>
              <span><strong>Web</strong> — live search of admin-allowed domains only (not the open internet). Works alone or together with Docs.</span>
            </li>
            <li>
              <span class="help-icon tool-btn" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z"/></svg>
              </span>
              <span><strong>More</strong> (phones) — focus tags, URL (allowlisted), and File (text locally; PDF/Office via DeepSeek).</span>
            </li>
          </ul>
        </section>
        <section>
          <h3>Focus tags</h3>
          <p>When <strong><?= htmlspecialchars($docsModeLabel) ?></strong> is on, open <strong>More</strong> (or use Focus tags on desktop) to narrow retrieval to document tags your admin set (e.g. policy, faq, hr).</p>
        </section>
        <section>
          <h3>Faithfulness check</h3>
          <p>When the answer used uploaded documents, allowlisted web/URL, or attached file text, the assistant can run a second source check after streaming. Admin Faithfulness slider defaults to off. Low settings never replace your streamed answer; only high strictness may apply a small surgical fix. This is separate from HQ and Deep.</p>
        </section>
        <section>
          <h3>Sources &amp; related questions</h3>
          <p>After an answer, expand <strong>Sources</strong> to see which documents were used. Linked titles open in a new tab when a source URL is set. Expand <strong>Related questions</strong> to paste a follow-up into the box.</p>
        </section>
        <section>
          <h3>Chat history</h3>
          <p>Use <strong>Chats</strong> (phones) or the left sidebar (desktop) to open past conversations or start a new chat. New chat clears focus tags.</p>
        </section>
        <section>
          <h3>Tips</h3>
          <ul>
            <li>Ask follow-ups in the same chat — history is used for context.</li>
            <li>Enter sends; Shift+Enter starts a new line.</li>
            <li>URL context and File attach are separate — clear each with its own control.</li>
            <li>Answers are based on your documents and configured sources — not professional advice unless your organisation says otherwise.</li>
          </ul>
        </section>
      </div>
    </div>
  </div>

  <div id="sidebar-backdrop" class="drawer-backdrop" hidden></div>
  <aside class="sidebar" id="sidebar" aria-label="Chat history">
    <div class="sidebar-head">
      <div class="app-brand"><?= htmlspecialchars($appTitle) ?></div>
      <button type="button" id="close-history" class="btn btn-ghost sidebar-close" aria-label="Close chats">×</button>
    </div>
    <button id="new-chat" class="new-chat btn btn-secondary" type="button">+ New chat</button>
    <nav id="conv-list" class="conv-list"></nav>
    <div class="sidebar-footer">
      <?php if ($loggedIn): ?>
        <a href="<?= htmlspecialchars(url('admin.php')) ?>">Admin</a> · <a href="<?= htmlspecialchars(url('api/auth.php?action=logout')) ?>">Log out</a>
      <?php else: ?>
        <a href="<?= htmlspecialchars(url('login.php')) ?>">Admin login</a>
      <?php endif; ?>
    </div>
  </aside>

  <main class="main">
    <div id="messages" class="messages"></div>
    <form id="chat-form" class="composer">
      <div id="options-backdrop" class="drawer-backdrop" hidden></div>
      <div id="composer-options" class="composer-options" role="region" aria-label="Chat options">
        <div class="options-sheet-head">
          <strong>Options</strong>
          <button type="button" id="close-options" class="btn btn-primary">Done</button>
        </div>
        <!-- Hidden state checkboxes driven by icon toolbar -->
        <div class="composer-toggles-sr" hidden>
          <input type="checkbox" id="mode-rag" checked>
          <input type="checkbox" id="mode-hq">
          <input type="checkbox" id="deep-thinking">
          <input type="checkbox" id="web-search">
        </div>
        <details id="tag-pills" class="tag-pills" hidden>
          <summary class="tag-pills-summary">
            <span class="tag-pills-label">Focus tags</span>
            <span id="tag-pills-count" class="tag-pills-count" hidden></span>
          </summary>
          <div id="tag-pills-list" class="tag-pills-list"></div>
        </details>
        <?php
          $ctxLocal = $config['rag']['allowed_ext'] ?? ['md', 'txt', 'csv', 'json', 'html', 'htm'];
          $ctxOcr = $config['rag']['ocr_ext'] ?? [];
          $ctxParse = $config['rag']['parser_ext'] ?? [];
          $ctxAccept = array_values(array_unique(array_merge(
              array_map(static fn($e) => '.' . ltrim(strtolower((string)$e), '.'), $ctxLocal),
              array_map(static fn($e) => '.' . ltrim(strtolower((string)$e), '.'), $ctxOcr),
              array_map(static fn($e) => '.' . ltrim(strtolower((string)$e), '.'), $ctxParse)
          )));
          $ctxAcceptAttr = htmlspecialchars(implode(',', $ctxAccept));
        ?>
        <div class="composer-context">
          <div class="context-inline" id="context-slot-url">
            <label class="context-inline-label" for="context-url"><span class="feat-kw feat-kw-url">URL</span></label>
            <div class="field-wrap context-inline-field">
              <input type="url" id="context-url" placeholder="https://… (allowlisted)" autocomplete="off">
              <button type="button" id="clear-url-context" class="field-clear" hidden aria-label="Clear URL">×</button>
            </div>
          </div>
          <p id="url-context-status" class="hint context-status" hidden></p>
          <div class="context-inline" id="context-slot-file">
            <span class="context-inline-label"><span class="feat-kw feat-kw-file">File</span></span>
            <div class="context-file-row">
              <label class="file-btn btn btn-secondary" for="context-file">Choose</label>
              <input type="file" id="context-file" accept="<?= $ctxAcceptAttr ?>" hidden>
              <span id="context-file-name" class="context-file-name" hidden></span>
              <button type="button" id="clear-file-context" class="field-clear context-file-x" hidden aria-label="Clear file">×</button>
            </div>
          </div>
          <p id="file-context-status" class="hint context-status" hidden></p>
        </div>
      </div>
      <div class="composer-row">
        <div class="field-wrap input-wrap">
          <textarea id="input" placeholder="Send a message…" rows="1" autofocus></textarea>
          <button type="button" id="clear-input" class="field-clear" hidden aria-label="Clear message">×</button>
        </div>
        <button type="submit" id="send" class="btn btn-primary" aria-label="Send">➤</button>
      </div>
      <div class="composer-tools" role="toolbar" aria-label="Chat modes">
        <button type="button" class="tool-btn" id="tool-workcover" data-for="mode-rag" aria-pressed="true" title="<?= htmlspecialchars($docsModeLabel) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zM8 12h8v2H8v-2zm0 4h8v2H8v-2z"/></svg>
          <span class="tool-label"><?= htmlspecialchars($docsModeLabel) ?></span>
        </button>
        <button type="button" class="tool-btn" id="tool-hq" data-for="mode-hq" aria-pressed="false" title="HQ (off = Basic)">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2m-8 11H9.5v-2h-2v2H6V9h1.5v2.5h2V9H11zm7-1c0 .55-.45 1-1 1h-.75v1.5h-1.5V15H14c-.55 0-1-.45-1-1v-4c0-.55.45-1 1-1h3c.55 0 1 .45 1 1zm-3.5-.5h2v-3h-2z"/></svg>
          <span class="tool-label">HQ</span>
        </button>
        <button type="button" class="tool-btn" id="tool-think" data-for="deep-thinking" aria-pressed="false" title="Deep think (DeepSeek API)">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21h6v-1.5H9V21zm3-19C7.03 2 3.2 5.6 3.02 10.5c-.1 2.7 1.1 5.1 3.1 6.6.6.45.9 1.1.9 1.9V19h10v-.01c0-.8.3-1.44.9-1.89 2-1.5 3.2-3.9 3.1-6.6C20.8 5.6 16.97 2 12 2zm0 15c-.55 0-1-.45-1-1h2c0 .55-.45 1-1 1zm4.05-3.35c-.7.52-1.2 1.2-1.4 1.95h-5.3c-.2-.75-.7-1.43-1.4-1.95C6.6 12.5 5.9 11 6 9.35 6.15 6.4 8.7 4 12 4s5.85 2.4 6 5.35c.1 1.65-.6 3.15-1.95 4.3z"/></svg>
          <span class="tool-label">Deep think</span>
        </button>
        <button type="button" class="tool-btn" id="tool-web" data-for="web-search" aria-pressed="false" title="Web (allowlisted)">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
          <span class="tool-label">Web</span>
        </button>
        <button type="button" class="tool-btn tool-more" id="open-options" aria-expanded="false" aria-controls="composer-options" title="More options">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z"/></svg>
          <span class="tool-label">More</span>
        </button>
      </div>
    </form>
  </main>
</div>
<script>
window.APP_BASE = <?= json_encode(app_base(), JSON_UNESCAPED_SLASHES) ?>;
window.SIMPLE_EXPLAIN_AGE = <?= (int)$simpleExplainAge ?>;
window.UI_CONFIG = <?= json_encode($uiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(url('assets/chat.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/chat.js') ?>-rc2"></script>
</body>
</html>
