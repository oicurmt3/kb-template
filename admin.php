<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
admin_require();
$csrf = csrf_token();
$appTitle = (string)($config['app']['title'] ?? 'WANDA');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($appTitle) ?> Admin</title>
<link rel="stylesheet" href="<?= htmlspecialchars(url('assets/style.css')) ?>">
</head>
<body class="admin-page">
  <header class="admin-header">
    <h1><?= htmlspecialchars($appTitle) ?> Admin</h1>
    <nav>
      <a href="<?= htmlspecialchars(url()) ?>">← Back to chat</a> ·
      <a href="<?= htmlspecialchars(url('api/auth.php?action=logout')) ?>">Log out</a>
    </nav>
  </header>
  <main>
    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Chats</span>
          <span id="chat-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Manage all conversations across sessions. Caps apply when a new chat is created. Use <strong>0</strong> for unlimited (except list size).</p>
          <div class="inline meta-fields chat-limits-form">
            <label class="hint" for="chat-max-session">Max per session</label>
            <input type="number" id="chat-max-session" min="0" max="5000" step="1" value="50">
            <label class="hint" for="chat-max-global">Max global</label>
            <input type="number" id="chat-max-global" min="0" max="100000" step="1" value="500">
            <label class="hint" for="chat-retention">Retention days</label>
            <input type="number" id="chat-retention" min="0" max="3650" step="1" value="0" title="0 = keep forever">
            <label class="hint" for="chat-list-limit">List size</label>
            <input type="number" id="chat-list-limit" min="10" max="500" step="1" value="100">
          </div>
          <div class="inline" style="margin-top:10px">
            <button type="button" id="save-chat-limits" class="btn btn-primary">Save limits</button>
            <button type="button" id="prune-chats" class="btn btn-secondary">Prune now</button>
            <button type="button" id="delete-all-chats" class="btn btn-destructive">Delete all chats</button>
            <span id="chat-limits-status" class="hint" aria-live="polite"></span>
          </div>
          <p id="chat-total" class="hint" style="margin-top:12px"></p>
          <div id="chat-list" class="doc-list"></div>
        </div>
      </details>
    </section>

    <section class="card">
      <h2>Web search &amp; fetch domains</h2>
      <p class="hint">One hostname per line (subdomains allowed). Web search and URL fetch are limited to these domains. Example: <code>workcover.wa.gov.au</code></p>
      <textarea id="web-hosts" class="settings-textarea" rows="5" placeholder="workcover.wa.gov.au" spellcheck="false"></textarea>
      <div class="inline" style="margin-top:10px">
        <button type="button" id="save-web-hosts" class="btn btn-primary">Save domains</button>
        <span id="web-hosts-status" class="hint" aria-live="polite"></span>
      </div>
    </section>

    <section class="card">
      <h2>Basic language age</h2>
      <p class="hint">Used when <strong>HQ</strong> is off (Basic mode). Prompt becomes “Explain it to me like a <em>N</em> year old.” Range 5–18.</p>
      <div class="inline" style="margin-top:10px">
        <label class="hint" for="simple-explain-age">Age</label>
        <input type="number" id="simple-explain-age" min="5" max="18" step="1" value="10" style="width:5rem">
        <button type="button" id="save-simple-age" class="btn btn-primary">Save age</button>
        <span id="simple-age-status" class="hint" aria-live="polite"></span>
      </div>
    </section>

    <section class="card">
      <h2>Answer length</h2>
      <p class="hint">Global default for all chat replies (users no longer choose this in the composer).</p>
      <div class="inline" style="margin-top:10px" role="group" aria-label="Answer length">
        <label class="mode-toggle"><input type="radio" name="answer-length" id="answer-length-short" value="short"> <span>Short</span></label>
        <label class="mode-toggle"><input type="radio" name="answer-length" id="answer-length-medium" value="medium" checked> <span>Medium</span></label>
        <label class="mode-toggle"><input type="radio" name="answer-length" id="answer-length-long" value="long"> <span>Long</span></label>
        <button type="button" id="save-answer-length" class="btn btn-primary">Save length</button>
        <span id="answer-length-status" class="hint" aria-live="polite"></span>
      </div>
    </section>

    <section class="card">
      <h2>Adaptive RAG</h2>
      <p class="hint">When <strong>on</strong>, WorkCover retrieval can shrink context for focused questions (prompt + tags). When <strong>off</strong>, uses the original fixed-size RAG. Chat accuracy also uses query rewrite, synonym expansion, auto focus tags, weak-hit grounding, and an HQ faithfulness check — leave Adaptive <strong>on</strong> for best results.</p>
      <div class="inline" style="margin-top:10px" role="group" aria-label="Adaptive RAG">
        <label class="mode-toggle"><input type="radio" name="adaptive-retrieval" id="adaptive-retrieval-off" value="0" checked> <span>Off (legacy)</span></label>
        <label class="mode-toggle"><input type="radio" name="adaptive-retrieval" id="adaptive-retrieval-on" value="1"> <span>On</span></label>
        <button type="button" id="save-adaptive-retrieval" class="btn btn-primary">Save</button>
        <span id="adaptive-retrieval-status" class="hint" aria-live="polite"></span>
      </div>
    </section>

    <section class="card">
      <h2>RAG ↔ Web mix</h2>
      <p class="hint">When <strong>WorkCover</strong> is on, Docs mode always runs uploaded RAG plus allowlisted live web. This setting controls whether they <strong>complement</strong>, prefer <strong>docs first</strong>, prefer <strong>web first</strong>, or <strong>compete</strong> (pick a winner), and how strongly (0–100).</p>
      <div class="inline" style="margin-top:10px;flex-wrap:wrap" role="group" aria-label="RAG Web mix mode">
        <label class="mode-toggle"><input type="radio" name="rag-web-mode" id="rag-web-complement" value="complement" checked> <span>Complement</span></label>
        <label class="mode-toggle"><input type="radio" name="rag-web-mode" id="rag-web-docs-first" value="docs_first"> <span>Docs first</span></label>
        <label class="mode-toggle"><input type="radio" name="rag-web-mode" id="rag-web-web-first" value="web_first"> <span>Web first</span></label>
        <label class="mode-toggle"><input type="radio" name="rag-web-mode" id="rag-web-compete" value="compete"> <span>Compete</span></label>
      </div>
      <div class="inline" style="margin-top:12px;align-items:center;gap:12px">
        <label class="hint" for="rag-web-degree">Degree <span id="rag-web-degree-val">50</span></label>
        <input type="range" id="rag-web-degree" min="0" max="100" step="5" value="50" style="flex:1;min-width:160px" title="0 = prefer primary side strongly; 100 = push the other side hard">
        <button type="button" id="save-rag-web-mix" class="btn btn-primary">Save mix</button>
        <span id="rag-web-mix-status" class="hint" aria-live="polite"></span>
      </div>
      <p id="rag-web-mix-hint" class="hint rag-web-mix-hint" style="margin-top:12px" aria-live="polite"></p>
    </section>

    <section class="card">
      <h2>Chat UI &amp; features</h2>
      <p class="hint">Intro message for new chats, chat history (turn off for multi-user kiosks), and feature controls. <strong>WorkCover / HQ / Deep / Web</strong>: Default + Show/Hide. HQ Default off = Basic language; Default on = HQ. <strong>Tags / URL / File / Sources / Related</strong>: Show or Hide only.</p>
      <label class="hint" for="ui-intro">Intro message</label>
      <textarea id="ui-intro" class="settings-textarea" rows="3" placeholder="Welcome message shown at the start of every new chat"></textarea>
      <div class="inline" style="margin-top:10px" role="group" aria-label="Chat history">
        <label class="mode-toggle"><input type="radio" name="ui-chat-history" id="ui-chat-history-on" value="1" checked> <span>History on</span></label>
        <label class="mode-toggle"><input type="radio" name="ui-chat-history" id="ui-chat-history-off" value="0"> <span>History off</span></label>
      </div>
      <div id="ui-features-list" class="ui-features-list" style="margin-top:12px"></div>
      <div class="inline" style="margin-top:10px">
        <button type="button" id="save-ui-features" class="btn btn-primary">Save chat UI</button>
        <span id="ui-features-status" class="hint" aria-live="polite"></span>
      </div>
    </section>

    <section class="card">
      <h2>Upload documents</h2>
      <p class="hint">
        Text files: local chunking (md/txt/html…).
        PDF/Word/PPT/images: DeepSeek <strong>OCR</strong> (text returned → chunked for Docs) or <strong>Files parse</strong> (file_id attached in chat).
        Excel/epub: Files parse only. Max binary <?= (int)(($config['rag']['max_parser_bytes'] ?? 25000000) / 1000000) ?> MB.
      </p>
      <div id="dropzone" class="dropzone" tabindex="0">
        <p>Drop files here or click to browse</p>
        <input type="file" id="file-input" accept=".md,.txt,.csv,.json,.html,.htm,.pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.gif,.bmp,.tif,.tiff,.xls,.xlsx,.epub" multiple hidden>
      </div>
      <div class="inline meta-fields">
        <label class="hint" for="ingest-method">Method</label>
        <select id="ingest-method" title="How to process binary documents">
          <option value="auto" selected>Auto (OCR for PDF/images)</option>
          <option value="ocr">DeepSeek OCR → chunks</option>
          <option value="deepseek">DeepSeek Files parse → chat attach</option>
        </select>
        <label class="hint" for="parse-purpose">Purpose</label>
        <select id="parse-purpose" title="OCR / Files recognition purpose">
          <option value="text_recognition" selected>Text only</option>
          <option value="rich_layout_recognition">Text + figures / layout</option>
        </select>
      </div>
      <div class="doc-meta-shared">
        <h3 class="paste-ingest-title">Document details</h3>
        <p class="hint">Applies to <strong>file uploads</strong> and <strong>pasted text</strong>. Leave <strong>Title</strong> empty when pasting to let AI name it; a filled title overrides the AI (and is used as-is for uploads).</p>
        <div class="inline meta-fields">
          <input type="text" id="doc-title" placeholder="Title (optional)" title="Used for uploads and paste. Empty on paste → AI suggests a name.">
          <input type="text" id="doc-department" placeholder="Department (optional)" title="Used for uploads and paste">
          <input type="text" id="doc-tags" placeholder="Tags: act, worker, return-to-work" title="Comma-separated tags for retrieval — used for uploads and paste">
          <input type="url" id="doc-source-url" placeholder="Source URL (optional)" title="Public citation link — used for uploads and paste" autocomplete="off">
        </div>
      </div>
      <div class="paste-ingest">
        <h3 class="paste-ingest-title">Or paste text</h3>
        <p class="hint">Uses the <strong>Document details</strong> above (title, department, tags, source URL), then chunks for WorkCover RAG.</p>
        <textarea id="paste-text" class="settings-textarea paste-text" rows="8" placeholder="Paste Act excerpts, guidance notes, or any text to index…" spellcheck="true"></textarea>
        <div class="inline meta-fields" style="margin-top:8px">
          <label class="hint" for="paste-format">Format</label>
          <select id="paste-format" title="How to interpret pasted content">
            <option value="txt" selected>Plain text</option>
            <option value="md">Markdown</option>
          </select>
          <button type="button" id="paste-ingest" class="btn btn-primary">Add &amp; chunk</button>
        </div>
      </div>
      <p id="upload-status" class="hint" aria-live="polite"></p>
    </section>

    <section class="card">
      <h2>Documents</h2>
      <p class="hint">Add tags (comma-separated) and an optional <strong>source URL</strong> per document. The URL makes that citation clickable for users (opens in a new tab).</p>
      <div id="doc-list" class="doc-list"></div>
    </section>

    <section class="card" id="chunks-panel" hidden>
      <h2>Chunks <span id="chunks-doc-title" class="muted"></span></h2>
      <div id="chunks-list" class="chunks-list"></div>
    </section>

    <section class="card">
      <h2>Retrieval test</h2>
      <p class="hint">Runs the same rewrite → synonyms → tag inference → scored search path as chat. Shows search query, quality score, and hits.</p>
      <form id="retrieval-form" class="inline">
        <input type="text" id="retrieval-query" placeholder="Test query…" required style="flex:1;min-width:180px">
        <button type="submit" class="btn btn-primary">Search</button>
      </form>
      <div id="retrieval-results" class="retrieval-results"></div>
    </section>
  </main>
<script>
window.APP_BASE = <?= json_encode(app_base(), JSON_UNESCAPED_SLASHES) ?>;
window.CSRF_TOKEN = <?= json_encode($csrf) ?>;
</script>
<script src="<?= htmlspecialchars(url('assets/admin.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
