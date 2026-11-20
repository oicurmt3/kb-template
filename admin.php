<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
admin_require();
$csrf = csrf_token();
$appCfg = $config['app'] ?? [];
$appTitle = AppSettings::appDisplayTitle($appCfg);
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
    <section id="onboarding-banner" class="card admin-onboarding" hidden>
      <h2 class="admin-onboarding-title">Setup checklist</h2>
      <ol class="admin-onboarding-list">
        <li>Set your <strong>assistant name</strong> and chat intro below.</li>
        <li>Add <strong>allowed web domains</strong> (if you want live web with documents).</li>
        <li><strong>Upload documents</strong>, then Re-parse / Re-chunk if you use structure parsing.</li>
        <li>Open chat and ask a test question with <strong>Docs &amp; Web</strong> on.</li>
      </ol>
      <div class="inline" style="margin-top:10px">
        <button type="button" id="dismiss-onboarding" class="btn btn-secondary">Dismiss checklist</button>
      </div>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details" open>
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Your assistant &amp; prompts</span>
          <span id="branding-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Customise how the chat looks and how the AI answers. Empty prompt fields use <code>config.php</code> defaults (handy for first install). Export/import settings includes these fields.</p>
          <label class="hint" for="brand-app-title">Display name</label>
          <input type="text" id="brand-app-title" class="settings-input" maxlength="120" placeholder="e.g. Acme Help Desk">
          <label class="hint" for="brand-docs-label" style="margin-top:10px">Docs mode button label</label>
          <input type="text" id="brand-docs-label" class="settings-input" maxlength="60" placeholder="Docs &amp; Web">
          <label class="hint" for="brand-chat-placeholder" style="margin-top:10px">Question box placeholder</label>
          <input type="text" id="brand-chat-placeholder" class="settings-input" maxlength="160" placeholder="Ask a question…">
          <label class="hint" for="brand-about" style="margin-top:10px">Help panel — what is this?</label>
          <textarea id="brand-about" class="settings-textarea" rows="3" placeholder="Short plain-English description for users (Help modal)."></textarea>
          <label class="hint" for="brand-system-prompt" style="margin-top:10px">Plain chat system prompt</label>
          <textarea id="brand-system-prompt" class="settings-textarea" rows="4" placeholder="Used when Docs &amp; Web is off. Leave empty to use config.php."></textarea>
          <label class="hint" for="brand-rag-prompt" style="margin-top:10px">Docs &amp; Web system prompt</label>
          <textarea id="brand-rag-prompt" class="settings-textarea" rows="6" placeholder="Used when document grounding is on. Leave empty to use config.php."></textarea>
          <label class="hint" for="brand-source-hierarchy" style="margin-top:10px">Source hierarchy (grounding)</label>
          <textarea id="brand-source-hierarchy" class="settings-textarea" rows="3" placeholder="e.g. Policy manuals are authoritative; how-to guides are practical only. Leave empty for a neutral default."></textarea>
          <details class="admin-nested-details" style="margin-top:14px">
            <summary class="hint" style="cursor:pointer">Optional prompt overrides (mix, excerpts, related questions)</summary>
            <div style="margin-top:10px">
              <label class="hint" for="brand-grounding-rules">Excerpt rules — docs only (full block)</label>
              <textarea id="brand-grounding-rules" class="settings-textarea" rows="4" placeholder="Leave empty for the built-in default. Replaces the mandatory rules block before excerpt text when live web is off."></textarea>
              <label class="hint" for="brand-grounding-rules-web" style="margin-top:10px">Excerpt rules — docs + allowlisted web (full block)</label>
              <textarea id="brand-grounding-rules-web" class="settings-textarea" rows="4" placeholder="Leave empty for default. Used when Docs mode also fetches allowlisted web."></textarea>
              <label class="hint" for="brand-followups" style="margin-top:10px">Related questions instruction</label>
              <textarea id="brand-followups" class="settings-textarea" rows="4" placeholder="Leave empty for neutral examples. Sent when Related is on."></textarea>
              <p class="hint" style="margin-top:10px">RAG ↔ Web mix lines — use <code>{degree}</code> and <code>{strength}</code>. Leave empty for neutral defaults.</p>
              <label class="hint" for="brand-mix-complement">Mix: complement</label>
              <textarea id="brand-mix-complement" class="settings-textarea" rows="2" spellcheck="false"></textarea>
              <label class="hint" for="brand-mix-docs-first" style="margin-top:8px">Mix: docs first</label>
              <textarea id="brand-mix-docs-first" class="settings-textarea" rows="2" spellcheck="false"></textarea>
              <label class="hint" for="brand-mix-web-first" style="margin-top:8px">Mix: web first</label>
              <textarea id="brand-mix-web-first" class="settings-textarea" rows="2" spellcheck="false"></textarea>
              <label class="hint" for="brand-mix-compete" style="margin-top:8px">Mix: compete</label>
              <textarea id="brand-mix-compete" class="settings-textarea" rows="2" spellcheck="false"></textarea>
            </div>
          </details>
          <div class="inline" style="margin-top:10px">
            <button type="button" id="save-assistant-branding" class="btn btn-primary">Save assistant</button>
            <span id="branding-status" class="hint" aria-live="polite"></span>
          </div>
        </div>
      </details>
    </section>

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

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Export / import</span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Download JSON backups. <strong>Settings</strong> includes effective values plus raw <code>settings</code> table rows. <strong>Import settings</strong> restores from a previous settings export (overwrites current admin settings). <strong>Business preset</strong> applies bundled toggles and prompt overrides (does not change uploaded documents). <strong>Chunks</strong> export only (no import yet).</p>
          <label class="hint" for="apply-preset-select" style="margin-top:10px;display:block">Business preset</label>
          <div class="inline" style="flex-wrap:wrap;align-items:center">
            <select id="apply-preset-select" class="settings-input" style="max-width:320px">
              <option value="">— Choose a preset —</option>
            </select>
            <button type="button" id="apply-preset" class="btn btn-primary">Apply preset</button>
          </div>
          <div class="inline" style="margin-top:10px;flex-wrap:wrap">
            <button type="button" id="export-settings" class="btn btn-secondary">Export all settings</button>
            <button type="button" id="import-settings" class="btn btn-primary">Import settings…</button>
            <input type="file" id="import-settings-file" accept="application/json,.json" hidden>
            <button type="button" id="export-chunks" class="btn btn-secondary">Export all chunks</button>
            <span id="export-status" class="hint" aria-live="polite"></span>
          </div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Web search &amp; fetch domains</span>
          <span id="web-hosts-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">One hostname per line (subdomains allowed). Web search and URL fetch are limited to these domains. Example: <code>www.example.com</code></p>
          <textarea id="web-hosts" class="settings-textarea" rows="5" placeholder="www.example.com" spellcheck="false"></textarea>
          <p class="hint" style="margin-top:14px"><strong>Document Source URLs</strong> — when a retrieved upload has a Source URL on an allowlisted host, fetch that live page as web enrichment (same allowlist). Citation links still work either way.</p>
          <div class="inline" style="margin-top:8px;flex-wrap:wrap" role="group" aria-label="Document Source URL web fetch">
            <label class="mode-toggle"><input type="radio" name="doc-source-url-web" id="doc-source-url-web-on" value="1" checked> <span>Include</span></label>
            <label class="mode-toggle"><input type="radio" name="doc-source-url-web" id="doc-source-url-web-off" value="0"> <span>Exclude</span></label>
          </div>
          <div class="inline" style="margin-top:10px">
            <button type="button" id="save-web-hosts" class="btn btn-primary">Save web settings</button>
            <span id="web-hosts-status" class="hint" aria-live="polite"></span>
          </div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Basic language age</span>
          <span id="simple-age-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Used when <strong>HQ</strong> is off (Basic mode). Prompt becomes “Explain it to me like a <em>N</em> year old.” Range 5–18.</p>
          <div class="inline" style="margin-top:10px">
            <label class="hint" for="simple-explain-age">Age</label>
            <input type="number" id="simple-explain-age" min="5" max="18" step="1" value="10" style="width:5rem">
            <button type="button" id="save-simple-age" class="btn btn-primary">Save age</button>
            <span id="simple-age-status" class="hint" aria-live="polite"></span>
          </div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Answer length</span>
          <span id="answer-length-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Global default for all chat replies (users no longer choose this in the composer). <strong>Short</strong> hard-caps the visible answer (≈2–4 sentences). HQ and Deep must still obey this.</p>
          <div class="inline" style="margin-top:10px" role="group" aria-label="Answer length">
            <label class="mode-toggle"><input type="radio" name="answer-length" id="answer-length-short" value="short"> <span>Short</span></label>
            <label class="mode-toggle"><input type="radio" name="answer-length" id="answer-length-medium" value="medium" checked> <span>Medium</span></label>
            <label class="mode-toggle"><input type="radio" name="answer-length" id="answer-length-long" value="long"> <span>Long</span></label>
            <button type="button" id="save-answer-length" class="btn btn-primary">Save length</button>
            <span id="answer-length-status" class="hint" aria-live="polite"></span>
          </div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Adaptive RAG</span>
          <span id="adaptive-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">When <strong>on</strong>, Docs mode retrieval can shrink context for focused questions (prompt + tags). When <strong>off</strong>, uses the original fixed-size RAG. Chat accuracy also uses query rewrite, synonym expansion, auto focus tags, weak-hit grounding, and an optional faithfulness check — leave Adaptive <strong>on</strong> for best results.</p>
          <div class="inline" style="margin-top:10px" role="group" aria-label="Adaptive RAG">
            <label class="mode-toggle"><input type="radio" name="adaptive-retrieval" id="adaptive-retrieval-off" value="0" checked> <span>Off (legacy)</span></label>
            <label class="mode-toggle"><input type="radio" name="adaptive-retrieval" id="adaptive-retrieval-on" value="1"> <span>On</span></label>
            <button type="button" id="save-adaptive-retrieval" class="btn btn-primary">Save</button>
            <span id="adaptive-retrieval-status" class="hint" aria-live="polite"></span>
          </div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Deep thinking</span>
          <span id="deep-policy-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint"><strong>Manual</strong> (default, recommended with Short answers) — composer Deep toggle only. <strong>Auto</strong> — only for clearly long/multi-part questions (not weak retrieval). Toggle <em>forces on</em>. <strong>Always</strong> — Deep every turn until the per-chat cap. Cap <strong>0</strong> = unlimited. Auto Deep can lengthen answers and invent under thin sources — prefer Manual when Answer length is Short.</p>
          <div class="inline" style="margin-top:10px;flex-wrap:wrap" role="group" aria-label="Deep thinking policy">
            <label class="mode-toggle"><input type="radio" name="deep-policy-mode" id="deep-policy-manual" value="manual" checked> <span>Manual</span></label>
            <label class="mode-toggle"><input type="radio" name="deep-policy-mode" id="deep-policy-auto" value="auto"> <span>Auto</span></label>
            <label class="mode-toggle"><input type="radio" name="deep-policy-mode" id="deep-policy-always" value="always"> <span>Always</span></label>
          </div>
          <div class="inline" style="margin-top:12px;align-items:center;gap:12px;flex-wrap:wrap">
            <label class="hint" for="deep-max-per-chat">Max Deep per chat</label>
            <input type="number" id="deep-max-per-chat" min="0" max="50" step="1" value="3" style="width:5rem" title="0 = unlimited">
            <button type="button" id="save-deep-policy" class="btn btn-primary">Save Deep policy</button>
            <span id="deep-policy-status" class="hint" aria-live="polite"></span>
          </div>
          <p id="deep-stats" class="hint" style="margin-top:12px" aria-live="polite"></p>
          <div class="inline" style="margin-top:8px">
            <button type="button" id="reset-deep-stats" class="btn btn-ghost">Reset Deep stats</button>
          </div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Faithfulness</span>
          <span id="faithfulness-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Post-stream hallucination brake when grounding exists. <strong>0 = off</strong> (default). <strong>1–50</strong> marks ok but <em>never replaces</em> the streamed answer. <strong>≥55</strong> may apply a <em>surgical</em> rewrite only (keeps ≥85% of draft; rejects hedges like “excerpts don’t show”). Orphan scan ≥55; silent cite strip ≥75. Skips DeepSeek Files stub-only SOURCE.</p>
          <div class="inline" style="margin-top:12px;align-items:center;gap:12px">
            <label class="hint" for="faithfulness-degree">Strictness <span id="faithfulness-degree-val">0</span></label>
            <input type="range" id="faithfulness-degree" min="0" max="100" step="5" value="0" style="flex:1;min-width:160px" title="0 = off; ≥55 may surgically rewrite">
            <button type="button" id="save-faithfulness" class="btn btn-primary">Save</button>
            <span id="faithfulness-status" class="hint" aria-live="polite"></span>
          </div>
          <p id="faithfulness-hint" class="hint" style="margin-top:12px" aria-live="polite"></p>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">RAG ↔ Web mix</span>
          <span id="rag-web-mix-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">When <strong>Docs &amp; Web</strong> is on, document mode always runs uploaded search plus allowlisted live web. This setting controls whether they <strong>complement</strong>, prefer <strong>docs first</strong>, prefer <strong>web first</strong>, or <strong>compete</strong> (pick a winner), and how strongly (0–100). The <strong>first pass</strong> writes with these rules; the automatic <strong>faithfulness</strong> QA after streaming uses the <strong>same mode + degree</strong> when deciding what to keep or cut (it does not re-run mix — it audits the draft).</p>
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
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Chat UI &amp; features</span>
          <span id="ui-features-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Intro message for new chats, chat history (turn off for multi-user kiosks), and feature controls. <strong>Docs &amp; Web / HQ / Deep think / Web only</strong>: Default + Show/Hide. <strong>Tags / URL / File / Sources / Related</strong>: Show or Hide only. Each row’s hint updates live from the current Default and Show/Hide. <strong>Faithfulness</strong> (source check) is not listed here — it runs from the Faithfulness slider whenever grounding exists.</p>
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
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Upload documents</span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">
            Text files: local chunking (md/txt/html…).
            PDF/Word/PPT/images: DeepSeek <strong>OCR</strong> (text returned → chunked for Docs) or <strong>Files parse</strong> (file_id attached in chat).
            Excel/epub: Files parse only. Max binary <?= (int)(($config['rag']['max_parser_bytes'] ?? 25000000) / 1000000) ?> MB.
            Choose files, set details, then press <strong>Upload</strong>.
          </p>
          <div id="dropzone" class="dropzone" tabindex="0">
            <p>Drop files here or click to browse</p>
            <input type="file" id="file-input" accept=".md,.txt,.csv,.json,.html,.htm,.pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.gif,.bmp,.tif,.tiff,.xls,.xlsx,.epub" multiple hidden>
          </div>
          <p id="upload-pending" class="hint" aria-live="polite"></p>
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
              <input type="url" id="doc-source-url" placeholder="Source URL (optional)" title="Public page for this doc — citation link + live web fetch when the doc is retrieved (allowlisted hosts)" autocomplete="off">
            </div>
          </div>
          <div class="inline" style="margin-top:10px;flex-wrap:wrap">
            <button type="button" id="upload-submit" class="btn btn-primary">Upload</button>
            <button type="button" id="upload-clear" class="btn btn-ghost">Clear files</button>
            <span id="upload-status" class="hint" aria-live="polite"></span>
          </div>
          <div class="paste-ingest">
            <h3 class="paste-ingest-title">Or paste text</h3>
            <p class="hint">Uses the <strong>Document details</strong> above (title, department, tags, source URL), then chunks for document search.</p>
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
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Documents</span>
          <span id="doc-summary-hint" class="admin-card-summary-hint"></span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Add tags (comma-separated) and an optional <strong>source URL</strong> per document. The URL makes that citation clickable for users (opens in a new tab). After a code upgrade: <strong>Re-parse</strong> → <strong>Continue</strong> (if shown) → <strong>Re-chunk</strong>.</p>
          <p id="docs-status" class="hint" aria-live="polite"></p>
          <div id="doc-list" class="doc-list"></div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card" id="chunks-panel" hidden>
      <details class="admin-card-details" id="chunks-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Chunks</span>
          <span id="chunks-doc-title" class="admin-card-summary-hint muted"></span>
        </summary>
        <div class="admin-card-body">
          <div id="chunks-list" class="chunks-list"></div>
        </div>
      </details>
    </section>

    <section class="card admin-collapse-card">
      <details class="admin-card-details">
        <summary class="admin-card-summary">
          <span class="admin-card-summary-title">Retrieval test</span>
        </summary>
        <div class="admin-card-body">
          <p class="hint">Runs the same rewrite → synonyms → tag inference → scored search path as chat. Shows search query, quality score, and hits.</p>
          <form id="retrieval-form" class="inline">
            <input type="text" id="retrieval-query" placeholder="Test query…" required style="flex:1;min-width:180px">
            <button type="submit" class="btn btn-primary">Search</button>
          </form>
          <div id="retrieval-results" class="retrieval-results"></div>
        </div>
      </details>
    </section>
  </main>
<script>
window.APP_BASE = <?= json_encode(app_base(), JSON_UNESCAPED_SLASHES) ?>;
window.CSRF_TOKEN = <?= json_encode($csrf) ?>;
</script>
<script src="<?= htmlspecialchars(url('assets/admin.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
