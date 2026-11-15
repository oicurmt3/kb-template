(() => {
  const base = (typeof window.APP_BASE === 'string' ? window.APP_BASE : '').replace(/\/$/, '');
  const api = (path) => base + '/api/' + path.replace(/^\//, '');

  const $messages  = document.getElementById('messages');
  const $form      = document.getElementById('chat-form');
  const $input     = document.getElementById('input');
  const $send      = document.getElementById('send');
  const $convList  = document.getElementById('conv-list');
  const $newChat   = document.getElementById('new-chat');
  const $headerNewChat = document.getElementById('header-new-chat');
  const $modeRag   = document.getElementById('mode-rag');
  const $webSearch = document.getElementById('web-search');
  const $deepThinking = document.getElementById('deep-thinking');
  const $modeHq = document.getElementById('mode-hq');
  const $contextUrl = document.getElementById('context-url');
  const $contextFile = document.getElementById('context-file');
  const $clearContext = document.getElementById('clear-context');
  const $clearInput = document.getElementById('clear-input');
  const $contextStatus = document.getElementById('context-status');
  const $tagPills = document.getElementById('tag-pills');
  const $tagPillsList = document.getElementById('tag-pills-list');
  const $tagPillsCount = document.getElementById('tag-pills-count');
  const $sidebar = document.getElementById('sidebar');
  const $sidebarBackdrop = document.getElementById('sidebar-backdrop');
  const $openHistory = document.getElementById('open-history');
  const $closeHistory = document.getElementById('close-history');
  const $composerOptions = document.getElementById('composer-options');
  const $optionsBackdrop = document.getElementById('options-backdrop');
  const $openOptions = document.getElementById('open-options');
  const $closeOptions = document.getElementById('close-options');
  const $openHelp = document.getElementById('open-help');
  const $closeHelp = document.getElementById('close-help');
  const $helpModal = document.getElementById('help-modal');
  const $helpBackdrop = document.getElementById('help-backdrop');
  const $toolBtns = Array.from(document.querySelectorAll('.tool-btn[data-for]'));
  const INPUT_MAX_PX = () => Math.min(Math.round(window.innerHeight * 0.5), 360);

  let conversationId = null;
  let streaming = false;
  let pendingContext = { text: '', label: '', url: '' };
  let availableTags = [];
  let selectedTags = new Set();

  const MODE_KEY = 'dscb_mode';
  const WEB_KEY = 'dscb_web';
  const THINK_KEY = 'dscb_think';
  const HQ_KEY = 'dscb_hq';
  const TAGS_KEY = 'dscb_tags';

  const CHECKBOX_FEATURE = {
    'mode-rag': 'workcover',
    'mode-hq': 'hq',
    'deep-thinking': 'deep_thinking',
    'web-search': 'web_search',
  };

  let uiConfig = (typeof window.UI_CONFIG === 'object' && window.UI_CONFIG) ? window.UI_CONFIG : null;

  function featureMeta(key) {
    const f = uiConfig && uiConfig.features && uiConfig.features[key];
    if (!f || typeof f !== 'object') {
      return { default: true, visibility: 'show' };
    }
    let vis = f.visibility;
    // Legacy force → hide (default on)
    if (vis === 'force') {
      return { default: true, visibility: 'hide' };
    }
    return {
      default: !!f.default,
      visibility: vis === 'hide' ? 'hide' : 'show',
    };
  }

  function featureVisible(key) {
    return featureMeta(key).visibility !== 'hide';
  }

  function featureDefaultOn(key) {
    return !!featureMeta(key).default;
  }

  function chatHistoryOn() {
    return !(uiConfig && uiConfig.chat_history === false);
  }

  function setElHidden(el, hide) {
    if (!el) return;
    el.hidden = !!hide;
    el.classList.toggle('is-feature-hidden', !!hide);
  }

  function applyFeatureVisibility() {
    const urlShow = featureVisible('url_context');
    const fileShow = featureVisible('file_context');
    const tagsShow = featureVisible('tags');

    setElHidden(document.getElementById('tool-workcover'), !featureVisible('workcover'));
    setElHidden(document.getElementById('tool-hq'), !featureVisible('hq'));
    setElHidden(document.getElementById('tool-think'), !featureVisible('deep_thinking'));
    // Web is folded into WorkCover/Docs mode; standalone Web only when WorkCover is off
    const ragOn = featureVisible('workcover')
      ? !!(document.getElementById('mode-rag') && document.getElementById('mode-rag').checked)
      : featureDefaultOn('workcover');
    setElHidden(
      document.getElementById('tool-web'),
      ragOn || !featureVisible('web_search')
    );
    setElHidden($tagPills, !tagsShow);

    const urlWrap = $contextUrl ? ($contextUrl.closest('.field-wrap') || $contextUrl) : null;
    const fileWrap = $contextFile
      ? ($contextFile.closest('label.file-btn') || $contextFile.closest('label') || $contextFile)
      : null;
    setElHidden(urlWrap, !urlShow);
    setElHidden(fileWrap, !fileShow);

    const ctx = document.querySelector('.composer-context');
    setElHidden(ctx, !urlShow && !fileShow);
    if ($contextStatus && !urlShow && !fileShow) {
      setElHidden($contextStatus, true);
    }

    const moreUseful = tagsShow || urlShow || fileShow;
    setElHidden($openOptions, !moreUseful);

    document.body.classList.toggle('app-no-history', !chatHistoryOn());
    if ($openHistory) $openHistory.hidden = !chatHistoryOn();
    if ($headerNewChat) $headerNewChat.hidden = chatHistoryOn();
  }

  function applyFeatureDefaults() {
    // Always start from admin defaults; localStorage may override only when shown
    if ($modeRag) $modeRag.checked = featureDefaultOn('workcover');
    if ($modeHq) $modeHq.checked = featureDefaultOn('hq');
    if ($deepThinking) $deepThinking.checked = featureDefaultOn('deep_thinking');
    if ($webSearch) $webSearch.checked = featureDefaultOn('web_search');

    try {
      if (featureVisible('workcover')) {
        const savedMode = localStorage.getItem(MODE_KEY);
        if (savedMode === 'rag' || savedMode === 'chat') {
          $modeRag.checked = savedMode === 'rag';
        }
      }
      if (featureVisible('web_search')) {
        const w = localStorage.getItem(WEB_KEY);
        if (w === '1' || w === '0') $webSearch.checked = w === '1';
      }
      if (featureVisible('deep_thinking') && $deepThinking) {
        const t = localStorage.getItem(THINK_KEY);
        if (t === '1' || t === '0') $deepThinking.checked = t === '1';
      }
      if (featureVisible('hq') && $modeHq) {
        const h = localStorage.getItem(HQ_KEY);
        if (h === '1' || h === '0') {
          $modeHq.checked = h === '1';
        } else {
          // Migrate old Plain toggle: Plain on ⇒ HQ off
          const legacyPlain = localStorage.getItem('dscb_plain');
          if (legacyPlain === '1') $modeHq.checked = false;
          else if (legacyPlain === '0') $modeHq.checked = true;
        }
      }
      if (featureVisible('tags')) {
        const savedTags = JSON.parse(localStorage.getItem(TAGS_KEY) || '[]');
        if (Array.isArray(savedTags)) {
          savedTags.forEach((t) => {
            if (typeof t === 'string' && t.trim()) selectedTags.add(t.trim());
          });
        }
      } else {
        selectedTags.clear();
      }
    } catch { /* ignore */ }
  }

  applyFeatureVisibility();
  applyFeatureDefaults();

  function currentMode() {
    if (!featureVisible('workcover')) {
      return featureDefaultOn('workcover') ? 'rag' : 'chat';
    }
    return $modeRag && $modeRag.checked ? 'rag' : 'chat';
  }

  function syncToolButtons() {
    $toolBtns.forEach((btn) => {
      const id = btn.getAttribute('data-for');
      const feat = CHECKBOX_FEATURE[id] || null;
      if (feat && !featureVisible(feat)) {
        btn.hidden = true;
        return;
      }
      // Folded into WorkCover — hide separate Web control while Docs mode is on
      if (id === 'web-search' && currentMode() === 'rag') {
        btn.hidden = true;
        return;
      }
      if (id === 'web-search') btn.hidden = false;
      const el = id ? document.getElementById(id) : null;
      const on = !!(el && el.checked);
      btn.classList.toggle('is-on', on);
      btn.classList.remove('is-forced');
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  $toolBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-for');
      const feat = CHECKBOX_FEATURE[id] || null;
      if (feat && !featureVisible(feat)) return;
      const el = id ? document.getElementById(id) : null;
      if (!el) return;
      el.checked = !el.checked;
      el.dispatchEvent(new Event('change', { bubbles: true }));
      syncToolButtons();
    });
  });
  syncToolButtons();

  function updateClearInput() {
    if (!$clearInput || !$input) return;
    $clearInput.hidden = !$input.value.trim();
  }

  function updateClearContextBtn() {
    if (!$clearContext) return;
    const has = !!(pendingContext.text || ($contextUrl && $contextUrl.value.trim()));
    $clearContext.hidden = !has;
  }

  function persistSelectedTags() {
    try { localStorage.setItem(TAGS_KEY, JSON.stringify([...selectedTags])); } catch { /* ignore */ }
  }

  function renderTagPills() {
    if (!$tagPills || !$tagPillsList) return;
    $tagPillsList.innerHTML = '';
    if (!featureVisible('tags') || currentMode() !== 'rag' || !availableTags.length) {
      setElHidden($tagPills, true);
      return;
    }
    setElHidden($tagPills, false);
    // Drop selections that no longer exist
    selectedTags.forEach((t) => {
      if (!availableTags.some((a) => a.toLowerCase() === t.toLowerCase())) selectedTags.delete(t);
    });
    if ($tagPillsCount) {
      const n = selectedTags.size;
      if (n > 0) {
        $tagPillsCount.hidden = false;
        $tagPillsCount.textContent = n + ' selected';
      } else {
        $tagPillsCount.hidden = true;
        $tagPillsCount.textContent = '';
      }
    }
    availableTags.forEach((tag) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'focus-tag-pill';
      btn.textContent = tag;
      const on = [...selectedTags].some((t) => t.toLowerCase() === tag.toLowerCase());
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.title = on ? 'Remove focus: ' + tag : 'Focus: ' + tag;
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const existing = [...selectedTags].find((t) => t.toLowerCase() === tag.toLowerCase());
        if (existing) selectedTags.delete(existing);
        else selectedTags.add(tag);
        persistSelectedTags();
        renderTagPills();
      });
      $tagPillsList.appendChild(btn);
    });
  }

  function resizeInput() {
    if (!$input) return;
    $input.style.height = 'auto';
    $input.style.height = Math.min($input.scrollHeight, INPUT_MAX_PX()) + 'px';
  }

  async function loadTags() {
    if (!featureVisible('tags')) {
      availableTags = [];
      selectedTags.clear();
      renderTagPills();
      return;
    }
    try {
      const r = await fetch(api('tags.php')).then((x) => x.json());
      availableTags = Array.isArray(r.tags) ? r.tags : [];
      renderTagPills();
    } catch {
      availableTags = [];
      renderTagPills();
    }
  }

  $modeRag.addEventListener('change', () => {
    try { localStorage.setItem(MODE_KEY, currentMode()); } catch { /* ignore */ }
    updatePlaceholder();
    renderTagPills();
    syncToolButtons();
  });
  $webSearch.addEventListener('change', () => {
    try { localStorage.setItem(WEB_KEY, $webSearch.checked ? '1' : '0'); } catch { /* ignore */ }
    syncToolButtons();
  });
  if ($deepThinking) {
    $deepThinking.addEventListener('change', () => {
      try { localStorage.setItem(THINK_KEY, $deepThinking.checked ? '1' : '0'); } catch { /* ignore */ }
      syncToolButtons();
    });
  }
  if ($modeHq) {
    $modeHq.addEventListener('change', () => {
      try { localStorage.setItem(HQ_KEY, $modeHq.checked ? '1' : '0'); } catch { /* ignore */ }
      const hqBtn = document.getElementById('tool-hq');
      if (hqBtn) {
        hqBtn.title = $modeHq.checked ? 'HQ mode on' : 'HQ mode off (Basic)';
      }
      syncToolButtons();
    });
  }

  function updatePlaceholder() {
    $input.placeholder = currentMode() === 'rag'
      ? 'Ask about WorkCover docs + live site — follow-ups are fine…'
      : 'Send a message…';
  }
  updatePlaceholder();
  renderTagPills();

  function showContextStatus(msg) {
    if (!msg) {
      $contextStatus.hidden = true;
      $contextStatus.textContent = '';
      updateClearContextBtn();
      return;
    }
    $contextStatus.hidden = false;
    $contextStatus.textContent = msg;
    updateClearContextBtn();
  }

  function clearPendingContext() {
    pendingContext = { text: '', label: '', url: '' };
    if ($contextFile) $contextFile.value = '';
    if ($contextUrl) $contextUrl.value = '';
    showContextStatus('');
    updateClearContextBtn();
  }

  if ($clearContext) {
    $clearContext.addEventListener('click', clearPendingContext);
  }
  if ($clearInput) {
    $clearInput.addEventListener('click', () => {
      $input.value = '';
      resizeInput();
      updateClearInput();
      $input.focus();
    });
  }

  if ($contextFile) $contextFile.addEventListener('change', async () => {
    if (!featureVisible('file_context')) return;
    const file = $contextFile.files && $contextFile.files[0];
    if (!file) return;
    showContextStatus('Reading ' + file.name + '…');
    try {
      const fd = new FormData();
      fd.append('file', file);
      const r = await fetch(api('context.php'), { method: 'POST', body: fd }).then((x) => x.json());
      if (r.error) throw new Error(r.error);
      pendingContext.text = r.text || '';
      pendingContext.label = r.name || file.name;
      pendingContext.url = '';
      showContextStatus('Attached: ' + pendingContext.label + ' (' + (r.chars || 0) + ' chars)');
    } catch (e) {
      pendingContext.text = '';
      pendingContext.label = '';
      showContextStatus('Attach failed: ' + e.message);
    }
  });

  if ($contextUrl) {
    $contextUrl.addEventListener('input', updateClearContextBtn);
    $contextUrl.addEventListener('change', updateClearContextBtn);
  }

  // ---- Mobile drawers (history + options) ----
  function syncDrawerBodyLock() {
    const helpOpen = $helpModal && !$helpModal.hidden;
    const anyOpen = ($sidebar && $sidebar.classList.contains('is-open'))
      || ($composerOptions && $composerOptions.classList.contains('is-open'))
      || helpOpen;
    document.body.classList.toggle('drawer-open', !!anyOpen);
  }

  function setHelpOpen(open) {
    if (!$helpModal) return;
    $helpModal.hidden = !open;
    if ($helpBackdrop) $helpBackdrop.hidden = !open;
    if ($openHelp) $openHelp.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      setHistoryOpen(false);
      setOptionsOpen(false);
      if ($closeHelp) $closeHelp.focus();
    }
    syncDrawerBodyLock();
  }

  function setHistoryOpen(open) {
    if (!$sidebar) return;
    $sidebar.classList.toggle('is-open', open);
    if ($sidebarBackdrop) $sidebarBackdrop.hidden = !open;
    if ($openHistory) $openHistory.setAttribute('aria-expanded', open ? 'true' : 'false');
    syncDrawerBodyLock();
  }

  function isMobileLayout() {
    return window.matchMedia('(max-width: 700px)').matches;
  }

  function setOptionsOpen(open) {
    if (!$composerOptions) return;
    if (!isMobileLayout()) {
      $composerOptions.classList.remove('is-open');
      if ($optionsBackdrop) $optionsBackdrop.hidden = true;
      if ($openOptions) $openOptions.setAttribute('aria-expanded', 'false');
      syncDrawerBodyLock();
      return;
    }
    $composerOptions.classList.toggle('is-open', open);
    if ($optionsBackdrop) $optionsBackdrop.hidden = !open;
    if ($openOptions) $openOptions.setAttribute('aria-expanded', open ? 'true' : 'false');
    syncDrawerBodyLock();
  }

  if ($openHistory) {
    $openHistory.addEventListener('click', () => {
      setOptionsOpen(false);
      setHistoryOpen(true);
    });
  }
  if ($closeHistory) $closeHistory.addEventListener('click', () => setHistoryOpen(false));
  if ($sidebarBackdrop) $sidebarBackdrop.addEventListener('click', () => setHistoryOpen(false));
  if ($openOptions) {
    $openOptions.addEventListener('click', () => {
      setHistoryOpen(false);
      setOptionsOpen(true);
    });
  }
  if ($closeOptions) $closeOptions.addEventListener('click', () => setOptionsOpen(false));
  if ($optionsBackdrop) $optionsBackdrop.addEventListener('click', () => setOptionsOpen(false));
  if ($openHelp) {
    $openHelp.addEventListener('click', () => setHelpOpen(true));
  }
  if ($closeHelp) $closeHelp.addEventListener('click', () => setHelpOpen(false));
  if ($helpBackdrop) $helpBackdrop.addEventListener('click', () => setHelpOpen(false));
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    setHelpOpen(false);
    setHistoryOpen(false);
    setOptionsOpen(false);
  });

  // ---- Conversations sidebar ----
  async function loadConversations() {
    if (!chatHistoryOn()) {
      if ($convList) $convList.innerHTML = '';
      return;
    }
    try {
      const r = await fetch(api('conversations.php')).then(res => res.json());
      $convList.innerHTML = '';
      (r.conversations || []).forEach(c => {
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'conv-item' + (c.id === conversationId ? ' active' : '');
        a.textContent = c.title || 'Untitled';
        a.onclick = (e) => { e.preventDefault(); openConversation(c.id); };
        $convList.appendChild(a);
      });
    } catch {
      // ignore
    }
  }

  async function openConversation(id) {
    if (!chatHistoryOn()) return;
    const r = await fetch(api('conversations.php?id=' + id)).then(res => res.json());
    if (r.error) return;
    conversationId = id;
    $messages.innerHTML = '';
    (r.messages || []).forEach(m => {
      let citations = null;
      let suggestions = null;
      let content = m.content || '';
      if (m.blocks) {
        try {
          const b = typeof m.blocks === 'string' ? JSON.parse(m.blocks) : m.blocks;
          if (b && Array.isArray(b.citations)) citations = uniqueSources(b.citations);
          if (b && Array.isArray(b.suggestions)) suggestions = b.suggestions;
        } catch { /* ignore */ }
      }
      if (m.role === 'assistant' && (!suggestions || !suggestions.length)) {
        const parsed = parseFollowupsClient(content);
        if (parsed.suggestions.length) {
          suggestions = parsed.suggestions;
          content = parsed.content;
        }
      }
      if (m.role === 'user') content = stripUserMessageMeta(content);
      const el = addMessage(m.role, content, citations);
      if (m.role === 'assistant' && suggestions && suggestions.length) {
        renderSuggestions(el, suggestions);
      }
    });
    setHistoryOpen(false);
    loadConversations();
    scrollBottom();
  }

  function showIntroMessage() {
    const text = (uiConfig && typeof uiConfig.intro === 'string') ? uiConfig.intro.trim() : '';
    if (!text || !$messages) return;
    const el = addMessage('assistant', text, null);
    el.classList.add('msg-intro');
  }

  function newChat() {
    conversationId = null;
    $messages.innerHTML = '';
    selectedTags.clear();
    persistSelectedTags();
    renderTagPills();
    setHistoryOpen(false);
    setOptionsOpen(false);
    setHelpOpen(false);
    showIntroMessage();
    if ($input) $input.focus();
    if (chatHistoryOn()) loadConversations();
    else if ($convList) $convList.innerHTML = '';
  }

  /** Hide composer metadata formerly shown in user bubbles (tags, toggles, attachments). */
  function stripUserMessageMeta(content) {
    return String(content || '')
      .replace(
        /\n\n\[(?:Attached(?: context)?:[^\]]*|Attached URL:[^\]]*|URL:[^\]]*|Web search on|Deep thinking on|Plain language:[^\]]*|Tags:[^\]]*)\]/gi,
        ''
      )
      .replace(/\s+$/, '');
  }

  function uniqueSources(list) {
    const byKey = new Map();
    (list || []).forEach((c) => {
      const key = String(
        (c.document_id && c.document_id !== 0 ? 'd' + c.document_id : '') ||
        c.filename ||
        c.title ||
        ''
      );
      if (!key) return;
      let url = (c.source_url && String(c.source_url).trim()) || '';
      if (!url && c.filename && /^https?:\/\//i.test(String(c.filename))) {
        url = String(c.filename);
      }
      const hasAgg = c.chunk_count != null && Number(c.chunk_count) > 0;
      const add = hasAgg ? Number(c.chunk_count) : 1;
      if (byKey.has(key)) {
        const prev = byKey.get(key);
        if (!prev.fromAgg) {
          prev.chunk_count += hasAgg ? add : 1;
        } else if (hasAgg) {
          prev.chunk_count = Math.max(prev.chunk_count, add);
        }
        if (url && !prev.source_url) prev.source_url = url;
        return;
      }
      byKey.set(key, {
        document_id: c.document_id,
        title: c.title || c.filename || 'Document',
        filename: c.filename || null,
        source_url: url || null,
        chunk_count: add,
        fromAgg: hasAgg,
      });
    });
    return Array.from(byKey.values()).map(({ fromAgg, ...rest }) => rest);
  }

  function renderCitations(container, citations) {
    if (!featureVisible('sources')) return;
    const list = uniqueSources(citations);
    if (!list.length) return;
    let cites = container.querySelector('.citations');
    if (!cites) {
      cites = document.createElement('details');
      cites.className = 'citations';
      const summary = document.createElement('summary');
      summary.className = 'citations-summary';
      const body = document.createElement('div');
      body.className = 'citations-body';
      cites.append(summary, body);
      container.appendChild(cites);
    }
    const summary = cites.querySelector('summary');
    const body = cites.querySelector('.citations-body') || cites;
    if (summary) {
      summary.textContent = 'Sources';
    }
    body.innerHTML = '';
    list.forEach((c) => {
      const base = c.title || c.filename || 'Document';
      const n = Number(c.chunk_count) || 1;
      const label = base + ' (' + n + ')';
      const url = c.source_url && /^https?:\/\//i.test(String(c.source_url))
        ? String(c.source_url)
        : '';
      let chip;
      if (url) {
        chip = document.createElement('a');
        chip.href = url;
        chip.target = '_blank';
        chip.rel = 'noopener noreferrer';
        chip.className = 'cite cite-link';
        chip.title = 'Open source: ' + url + ' · ' + n + ' excerpt' + (n === 1 ? '' : 's');
      } else {
        chip = document.createElement('span');
        chip.className = 'cite';
        chip.title = n + ' excerpt' + (n === 1 ? '' : 's');
      }
      chip.textContent = label;
      body.appendChild(chip);
    });
  }

  /** Hide follow-up marker block from the visible answer while streaming. */
  function visibleAnswer(text) {
    const marker = '<<<FOLLOWUPS>>>';
    const idx = text.indexOf(marker);
    if (idx === -1) return text;
    return text.slice(0, idx).replace(/\s+$/, '');
  }

  function pasteSuggestion(q) {
    const text = String(q || '').trim();
    if (!text || !$input) return;
    $input.value = text;
    $input.focus();
    resizeInput();
    updateClearInput();
    try {
      $input.setSelectionRange(text.length, text.length);
    } catch { /* ignore */ }
  }

  function parseFollowupsClient(text) {
    const raw = String(text || '');
    let block = null;
    let content = raw;
    const full = raw.match(/<<<FOLLOWUPS>>>\s*([\s\S]*?)\s*<<<ENDFOLLOWUPS>>>/i);
    if (full) {
      block = full[1];
      content = raw.replace(/<<<FOLLOWUPS>>>\s*[\s\S]*?\s*<<<ENDFOLLOWUPS>>>/i, '').trim();
    } else {
      const idx = raw.indexOf('<<<FOLLOWUPS>>>');
      if (idx !== -1) {
        block = raw.slice(idx + '<<<FOLLOWUPS>>>'.length);
        content = raw.slice(0, idx).trim();
      }
    }
    const suggestions = [];
    if (block) {
      block.split(/\r?\n/).forEach((line) => {
        let t = line.trim();
        if (!t || t.startsWith('<<<')) return;
        t = t.replace(/^[\-\*\d\.\)\]]+\s*/, '').trim().replace(/^["']|["']$/g, '');
        if (t.length >= 8 && t.length <= 200) suggestions.push(t);
      });
    }
    return { content, suggestions: [...new Set(suggestions)].slice(0, 4) };
  }

  function renderSuggestions(container, suggestions) {
    if (!featureVisible('related_questions')) return;
    const list = (suggestions || []).map((s) => String(s || '').trim()).filter(Boolean);
    if (!list.length || !container) return;
    let wrap = container.querySelector('.suggestions');
    if (!wrap) {
      wrap = document.createElement('details');
      wrap.className = 'suggestions';
      const summary = document.createElement('summary');
      summary.className = 'suggestions-summary';
      const body = document.createElement('div');
      body.className = 'suggestions-body';
      wrap.append(summary, body);
      container.appendChild(wrap);
    }
    const summary = wrap.querySelector('summary');
    const body = wrap.querySelector('.suggestions-body') || wrap;
    if (summary) {
      summary.textContent = 'Related questions';
    }
    body.innerHTML = '';
    list.forEach((q) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'suggestion-btn';
      btn.textContent = q;
      btn.setAttribute('data-suggestion', q);
      btn.setAttribute('aria-label', 'Use suggested question: ' + q);
      body.appendChild(btn);
    });
  }

  // Event delegation — reliable even after re-renders
  $messages.addEventListener('click', (e) => {
    const btn = e.target && e.target.closest ? e.target.closest('.suggestion-btn') : null;
    if (!btn || !$messages.contains(btn)) return;
    e.preventDefault();
    e.stopPropagation();
    pasteSuggestion(btn.getAttribute('data-suggestion') || btn.textContent || '');
  });

  function addMessage(role, text, citations) {
    const el = document.createElement('div');
    el.className = 'msg ' + role;
    const body = document.createElement('div');
    body.className = 'msg-body';
    body.textContent = text;
    el.appendChild(body);
    if (citations && citations.length) renderCitations(el, citations);
    $messages.appendChild(el);
    return el;
  }

  function setMessageText(el, text) {
    const body = el.querySelector('.msg-body') || el;
    body.textContent = text;
  }

  /** Note under an assistant bubble (e.g. HQ replaced the streamed draft). */
  function setMessageNote(el, text) {
    let note = el.querySelector('.msg-note');
    if (!text) {
      if (note) note.remove();
      return;
    }
    if (!note) {
      note = document.createElement('p');
      note.className = 'msg-note hint';
      const body = el.querySelector('.msg-body');
      if (body && body.nextSibling) el.insertBefore(note, body.nextSibling);
      else el.appendChild(note);
    }
    note.textContent = text;
  }

  function scrollBottom() {
    $messages.scrollTop = $messages.scrollHeight;
  }

  async function resolveUrlContext() {
    if (!featureVisible('url_context') || !$contextUrl) return { url: '', note: '' };
    const url = ($contextUrl.value || '').trim();
    if (!url) return { url: '', note: '' };
    return { url, note: url };
  }

  async function send(message) {
    if (streaming) return;
    streaming = true;
    $send.disabled = true;

    addMessage('user', message);
    $input.value = '';
    resizeInput();
    updateClearInput();

    const assistantEl = addMessage('assistant', '');
    assistantEl.classList.add('streaming');

    let accumulated = '';
    let citations = null;
    let suggestions = null;
    const urlInfo = await resolveUrlContext();

    const payload = {
      conversation_id: conversationId,
      message,
      mode: currentMode(),
      // WorkCover/Docs always includes allowlisted web on the server; toggle only for chat mode
      web_search: currentMode() === 'rag'
        ? true
        : (featureVisible('web_search')
          ? !!($webSearch && $webSearch.checked)
          : featureDefaultOn('web_search')),
      deep_thinking: featureVisible('deep_thinking')
        ? !!($deepThinking && $deepThinking.checked)
        : featureDefaultOn('deep_thinking'),
      hq_mode: featureVisible('hq')
        ? !!($modeHq && $modeHq.checked)
        : featureDefaultOn('hq'),
      tags: (featureVisible('tags') && currentMode() === 'rag') ? [...selectedTags] : [],
      extra_context: featureVisible('file_context')
        ? (pendingContext.text || '')
        : '',
      context_label: featureVisible('file_context') ? (pendingContext.label || '') : '',
      context_url: featureVisible('url_context') ? (urlInfo.url || '') : '',
    };

    try {
      const response = await fetch(api('chat.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (!response.ok && !response.body) {
        throw new Error('HTTP ' + response.status);
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let sseBuffer = '';

      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        sseBuffer += decoder.decode(value, { stream: true });

        let idx;
        while ((idx = sseBuffer.indexOf('\n\n')) !== -1) {
          const raw = sseBuffer.slice(0, idx);
          sseBuffer = sseBuffer.slice(idx + 2);
          handleEvent(raw);
        }
      }

      // Clear one-shot attachments after successful send
      clearPendingContext();
    } catch (e) {
      setMessageText(assistantEl, 'Error: ' + e.message);
    } finally {
      assistantEl.classList.remove('streaming');
      // Fallback: parse follow-ups from streamed text if server list is empty
      if ((!suggestions || !suggestions.length) && accumulated) {
        const parsed = parseFollowupsClient(accumulated);
        if (parsed.suggestions.length) {
          suggestions = parsed.suggestions;
          setMessageText(assistantEl, parsed.content);
        } else {
          setMessageText(assistantEl, visibleAnswer(accumulated));
        }
      }
      if (citations && citations.length) {
        renderCitations(assistantEl, citations);
      }
      if (suggestions && suggestions.length) {
        renderSuggestions(assistantEl, suggestions);
      }
      streaming = false;
      $send.disabled = false;
      scrollBottom();
      loadConversations();
    }

    function handleEvent(raw) {
      let event = 'message';
      let data = '';
      for (const line of raw.split('\n')) {
        if (line.startsWith('event: ')) event = line.slice(7).trim();
        else if (line.startsWith('data: ')) data += line.slice(6);
      }
      if (!data) return;
      let obj;
      try { obj = JSON.parse(data); } catch { return; }

      if (event === 'meta') {
        if (obj.conversation_id) conversationId = obj.conversation_id;
        if (obj.citations) citations = obj.citations;
      } else if (event === 'delta' && obj.content) {
        accumulated += obj.content;
        setMessageText(assistantEl, visibleAnswer(accumulated));
        scrollBottom();
      } else if (event === 'error') {
        setMessageText(assistantEl, visibleAnswer(accumulated) + '\n[error] ' + (obj.message || 'unknown'));
      } else if (event === 'title' && obj.title) {
        // Sidebar refreshes on done; title is already saved server-side
      } else if (event === 'done') {
        if (obj.citations) citations = obj.citations;
        if (Array.isArray(obj.suggestions)) suggestions = obj.suggestions;
        const notes = Array.isArray(obj.web_notes) ? obj.web_notes : [];
        const hqCorrected = notes.some((n) => /HQ check:\s*corrected/i.test(String(n || '')));
        if (typeof obj.content === 'string' && obj.content.length) {
          const prevVisible = visibleAnswer(accumulated);
          accumulated = obj.content;
          const parsed = parseFollowupsClient(accumulated);
          if (parsed.suggestions.length && (!suggestions || !suggestions.length)) {
            suggestions = parsed.suggestions;
          }
          const nextVisible = visibleAnswer(parsed.content || accumulated);
          setMessageText(assistantEl, nextVisible);
          if (hqCorrected || (prevVisible && nextVisible && prevVisible !== nextVisible)) {
            setMessageNote(
              assistantEl,
              hqCorrected
                ? 'Updated after HQ check: the draft was revised so claims match your documents and any allowlisted web/URL sources used this turn.'
                : 'Answer updated after final processing.'
            );
          }
        } else {
          setMessageText(assistantEl, visibleAnswer(accumulated));
        }
        if (obj.title) {
          // force sidebar refresh with new conversation title
          loadConversations();
        }
      }
    }
  }

  $form.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = $input.value.trim();
    if (v) send(v);
  });

  $input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      $form.requestSubmit();
    }
  });

  $input.addEventListener('input', () => {
    resizeInput();
    updateClearInput();
  });
  window.addEventListener('resize', () => {
    resizeInput();
    if (window.matchMedia('(min-width: 701px)').matches) {
      setHistoryOpen(false);
      setOptionsOpen(false);
      // keep help usable on desktop resize
    }
  });
  resizeInput();
  updateClearInput();
  updateClearContextBtn();

  if ($newChat) $newChat.addEventListener('click', newChat);
  if ($headerNewChat) $headerNewChat.addEventListener('click', newChat);
  loadTags();
  newChat();
})();
