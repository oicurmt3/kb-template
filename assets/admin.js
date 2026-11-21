(() => {
  const base = (typeof window.APP_BASE === 'string' ? window.APP_BASE : '').replace(/\/$/, '');
  const api = (path) => base + '/api/' + path.replace(/^\//, '');
  const csrf = () => window.CSRF_TOKEN || '';

  const $drop = document.getElementById('dropzone');
  const $file = document.getElementById('file-input');
  const $status = document.getElementById('upload-status');
  const $uploadPending = document.getElementById('upload-pending');
  const $uploadSubmit = document.getElementById('upload-submit');
  const $uploadClear = document.getElementById('upload-clear');
  const $docList = document.getElementById('doc-list');
  const $docSummaryHint = document.getElementById('doc-summary-hint');
  const $docsStatus = document.getElementById('docs-status');
  const $chunksPanel = document.getElementById('chunks-panel');
  const $chunksDetails = document.getElementById('chunks-details');
  const $chunksList = document.getElementById('chunks-list');
  const $chunksTitle = document.getElementById('chunks-doc-title');
  /** @type {File[]} */
  let pendingUploadFiles = [];
  const $retrievalForm = document.getElementById('retrieval-form');
  const $retrievalQuery = document.getElementById('retrieval-query');
  const $retrievalResults = document.getElementById('retrieval-results');
  const $webHosts = document.getElementById('web-hosts');
  const $saveWebHosts = document.getElementById('save-web-hosts');
  const $webHostsStatus = document.getElementById('web-hosts-status');
  const $webHostsSummaryHint = document.getElementById('web-hosts-summary-hint');
  const $simpleAge = document.getElementById('simple-explain-age');
  const $saveSimpleAge = document.getElementById('save-simple-age');
  const $simpleAgeStatus = document.getElementById('simple-age-status');
  const $simpleAgeSummaryHint = document.getElementById('simple-age-summary-hint');
  const $saveAnswerLength = document.getElementById('save-answer-length');
  const $answerLengthStatus = document.getElementById('answer-length-status');
  const $answerLengthSummaryHint = document.getElementById('answer-length-summary-hint');
  const $saveAdaptive = document.getElementById('save-adaptive-retrieval');
  const $adaptiveStatus = document.getElementById('adaptive-retrieval-status');
  const $adaptiveSummaryHint = document.getElementById('adaptive-summary-hint');
  const $ragWebDegree = document.getElementById('rag-web-degree');
  const $ragWebDegreeVal = document.getElementById('rag-web-degree-val');
  const $saveRagWebMix = document.getElementById('save-rag-web-mix');
  const $ragWebMixStatus = document.getElementById('rag-web-mix-status');
  const $ragWebMixHint = document.getElementById('rag-web-mix-hint');
  const $ragWebMixSummaryHint = document.getElementById('rag-web-mix-summary-hint');
  const $uiFeaturesSummaryHint = document.getElementById('ui-features-summary-hint');
  const $faithfulnessDegree = document.getElementById('faithfulness-degree');
  const $faithfulnessDegreeVal = document.getElementById('faithfulness-degree-val');
  const $saveFaithfulness = document.getElementById('save-faithfulness');
  const $faithfulnessStatus = document.getElementById('faithfulness-status');
  const $faithfulnessHint = document.getElementById('faithfulness-hint');
  const $faithfulnessSummaryHint = document.getElementById('faithfulness-summary-hint');
  const $deepMaxPerChat = document.getElementById('deep-max-per-chat');
  const $saveDeepPolicy = document.getElementById('save-deep-policy');
  const $deepPolicyStatus = document.getElementById('deep-policy-status');
  const $deepPolicySummaryHint = document.getElementById('deep-policy-summary-hint');
  const $deepStats = document.getElementById('deep-stats');
  const $resetDeepStats = document.getElementById('reset-deep-stats');
  const $uiIntro = document.getElementById('ui-intro');
  const $uiFeaturesList = document.getElementById('ui-features-list');
  const $saveUiFeatures = document.getElementById('save-ui-features');
  const $uiFeaturesStatus = document.getElementById('ui-features-status');
  const $exportSettings = document.getElementById('export-settings');
  const $importSettings = document.getElementById('import-settings');
  const $importSettingsFile = document.getElementById('import-settings-file');
  const $exportChunks = document.getElementById('export-chunks');
  const $exportStatus = document.getElementById('export-status');
  const $applyPresetSelect = document.getElementById('apply-preset-select');
  const $applyPreset = document.getElementById('apply-preset');
  const $brandAppTitle = document.getElementById('brand-app-title');
  const $brandDocsLabel = document.getElementById('brand-docs-label');
  const $brandChatPlaceholder = document.getElementById('brand-chat-placeholder');
  const $brandAbout = document.getElementById('brand-about');
  const $brandSystemPrompt = document.getElementById('brand-system-prompt');
  const $brandRagPrompt = document.getElementById('brand-rag-prompt');
  const $brandSourceHierarchy = document.getElementById('brand-source-hierarchy');
  const $brandGroundingRules = document.getElementById('brand-grounding-rules');
  const $brandGroundingRulesWeb = document.getElementById('brand-grounding-rules-web');
  const $brandFollowups = document.getElementById('brand-followups');
  const $brandMixComplement = document.getElementById('brand-mix-complement');
  const $brandMixDocsFirst = document.getElementById('brand-mix-docs-first');
  const $brandMixWebFirst = document.getElementById('brand-mix-web-first');
  const $brandMixCompete = document.getElementById('brand-mix-compete');
  const $saveBranding = document.getElementById('save-assistant-branding');
  const $brandingStatus = document.getElementById('branding-status');
  const $brandingSummaryHint = document.getElementById('branding-summary-hint');
  const $onboardingBanner = document.getElementById('onboarding-banner');
  const $dismissOnboarding = document.getElementById('dismiss-onboarding');

  const UI_FEATURE_LABELS = {
    workcover: 'Docs',
    hq: 'HQ',
    deep_thinking: 'Deep think',
    web_search: 'Web',
    tags: 'Focus tags',
    url_context: 'URL context',
    file_context: 'File attach',
    sources: 'Sources (citations)',
    related_questions: 'Related questions',
  };
  /** These only have Show/Hide — no Default checkbox */
  const UI_VISIBILITY_ONLY = {
    tags: true,
    url_context: true,
    file_context: true,
    sources: true,
    related_questions: true,
  };

  function uiFeatureLabelHtml(key) {
    switch (key) {
      case 'workcover':
        return '<span class="feat-kw feat-kw-docs">Docs</span>';
      case 'hq':
        return '<span class="feat-kw feat-kw-hq">HQ</span>';
      case 'deep_thinking':
        return '<span class="feat-kw feat-kw-deep">Deep think</span>';
      case 'web_search':
        return '<span class="feat-kw feat-kw-web">Web</span>';
      case 'tags':
        return '<span class="feat-kw feat-kw-tags">Focus tags</span>';
      case 'url_context':
        return '<span class="feat-kw feat-kw-url">URL</span> context';
      case 'file_context':
        return '<span class="feat-kw feat-kw-file">File</span> attach';
      case 'sources':
        return '<span class="feat-kw feat-kw-sources">Sources</span> (citations)';
      case 'related_questions':
        return '<span class="feat-kw feat-kw-related">Related questions</span>';
      default:
        return UI_FEATURE_LABELS[key] || key;
    }
  }

  function readUiFeatureState(row) {
    const key = row && row.dataset.feature;
    if (!key) return null;
    const vis = row.querySelector('.ui-feat-visibility');
    const defCb = row.querySelector('.ui-feat-default');
    const visibilityOnly = !!UI_VISIBILITY_ONLY[key];
    return {
      key,
      visibility: (vis && vis.value) || 'show',
      defaultOn: visibilityOnly ? true : !!(defCb && defCb.checked),
      visibilityOnly,
    };
  }

  function uiFeatureHintText(state, all) {
    if (!state) return '';
    const show = state.visibility === 'show';
    const on = !!state.defaultOn;

    switch (state.key) {
      case 'workcover':
        if (show && on) {
          return 'Chat shows Docs; starts on. On = uploaded docs (RAG) only. Turn Web on separately for allowlisted live web.';
        }
        if (show && !on) {
          return 'Chat shows Docs; starts off. Turning Docs on searches uploaded documents (no web unless Web is also on).';
        }
        if (!show && on) {
          return 'Docs locked on — users always get document retrieval. Web remains an independent toggle.';
        }
        return 'Docs locked off — no document retrieval. Allowlisted web only via Web (if enabled below).';

      case 'hq':
        if (show && on) {
          return 'HQ starts on (richer wording). Off = Basic plain language. Style only — does not change facts.';
        }
        if (show && !on) {
          return 'HQ starts off (Basic). Users can turn on richer wording. Style only — not a source check.';
        }
        if (!show && on) {
          return 'HQ locked on — always richer wording. No toggle.';
        }
        return 'HQ locked off — always Basic plain language. No toggle.';

      case 'deep_thinking':
        if (show && on) {
          return 'Deep think starts on (DeepSeek API thinking; slower). Admin Deep policy may still auto-enable on hard questions.';
        }
        if (show && !on) {
          return 'Deep think starts off. User toggle forces thinking on; Admin → Deep policy (Auto/Always) may still enable it.';
        }
        if (!show && on) {
          return 'Deep think toggle hidden and locked on (still subject to per-chat Deep cap / policy).';
        }
        return 'Deep think toggle hidden and locked off — only Admin → Deep policy (Auto/Always) can enable thinking.';

      case 'web_search':
        if (show && on) {
          return 'Web starts on. Allowlisted hosts only — not open internet. Works alone or with Docs.';
        }
        if (show && !on) {
          return 'Web starts off. Users can turn on allowlisted live web with or without Docs.';
        }
        if (!show && on) {
          return 'Web locked on — allowlisted live web always runs. Toggle hidden.';
        }
        return 'Web locked off — no allowlisted live web (Docs can still run alone).';

      case 'tags':
        return show
          ? 'Users can narrow Docs retrieval with focus tags (More menu / pills).'
          : 'Focus tags hidden from chat.';

      case 'url_context':
        return show
          ? 'Separate URL field in Options. Host must be on Admin → Web allowlist — not any internet URL.'
          : 'URL context field hidden.';

      case 'file_context':
        return show
          ? 'Inline File picker in Options. Text files read locally; PDF/Office/images use DeepSeek OCR (or Files parse for spreadsheets/ebooks). This turn only.'
          : 'File attach hidden.';

      case 'sources':
        return show
          ? 'Citation / sources list shown under answers when available.'
          : 'Sources / citations hidden from chat.';

      case 'related_questions':
        return show
          ? 'Related follow-up questions shown after answers when available.'
          : 'Related questions hidden.';

      default:
        return show
          ? (on ? 'Shown in chat; default on.' : 'Shown in chat; default off.')
          : (on ? 'Hidden; locked on.' : 'Hidden; locked off.');
    }
  }

  function updateUiFeatureHints() {
    if (!$uiFeaturesList) return;
    const all = {};
    $uiFeaturesList.querySelectorAll('.ui-feature-row').forEach((row) => {
      const st = readUiFeatureState(row);
      if (st) all[st.key] = st;
    });
    $uiFeaturesList.querySelectorAll('.ui-feature-row').forEach((row) => {
      const st = readUiFeatureState(row);
      const hint = row.querySelector('.ui-feat-hint');
      if (hint && st) hint.textContent = uiFeatureHintText(st, all);
    });
    updateUiFeaturesSummaryHint();
  }
  const $chatSummaryHint = document.getElementById('chat-summary-hint');
  const $chatList = document.getElementById('chat-list');
  const $chatTotal = document.getElementById('chat-total');
  const $chatLimitsStatus = document.getElementById('chat-limits-status');
  const $chatMaxSession = document.getElementById('chat-max-session');
  const $chatMaxGlobal = document.getElementById('chat-max-global');
  const $chatRetention = document.getElementById('chat-retention');
  const $chatListLimit = document.getElementById('chat-list-limit');
  const $saveChatLimits = document.getElementById('save-chat-limits');
  const $pruneChats = document.getElementById('prune-chats');
  const $deleteAllChats = document.getElementById('delete-all-chats');

  async function refreshCsrf() {
    try {
      const r = await fetch(api('auth.php?action=csrf')).then((x) => x.json());
      if (r.token) window.CSRF_TOKEN = r.token;
    } catch { /* ignore */ }
  }

  function setStatus(msg, isErr) {
    if (!$status) return;
    $status.textContent = msg || '';
    $status.classList.toggle('err', !!isErr);
    $status.classList.toggle('ok', !!msg && !isErr);
  }

  /** Status line on the Documents card (and mirror to upload status). */
  function setDocsStatus(msg, isErr) {
    if ($docsStatus) {
      $docsStatus.textContent = msg || '';
      $docsStatus.classList.toggle('err', !!isErr);
      $docsStatus.classList.toggle('ok', !!msg && !isErr);
      if (msg) {
        $docsStatus.classList.remove('docs-status-flash');
        // reflow so animation can replay
        void $docsStatus.offsetWidth;
        $docsStatus.classList.add('docs-status-flash');
        try {
          $docsStatus.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch { /* ignore */ }
      }
    }
    setStatus(msg, isErr);
  }

  function batchLabel(res) {
    if (res && res.batch && res.batches_total) {
      return ' (batch ' + res.batch + '/' + res.batches_total + ')';
    }
    return '';
  }

  /** Map API parse result → user-facing notice. */
  function noticeFromParseResult(id, res) {
    const stage = (res && res.pipeline_stage) || '';
    const batch = batchLabel(res);
    if (res && res.need_continue) {
      return {
        msg: 'Doc #' + id + ': still parsing' + batch + ' — click Continue',
        err: false,
      };
    }
    if (stage === 'parsed') {
      return {
        msg: 'Doc #' + id + ': PARSE COMPLETE' + batch + ' — click Re-chunk',
        err: false,
      };
    }
    if (stage === 'ready') {
      return {
        msg: 'Doc #' + id + ': ' + ((res && res.message) || 'ready') + batch,
        err: false,
      };
    }
    if (res && res.message) {
      return { msg: 'Doc #' + id + ': ' + res.message + batch, err: false };
    }
    return {
      msg: 'Doc #' + id + ': stage ' + (stage || 'unknown') + batch,
      err: false,
    };
  }

  function renderPendingUploads() {
    if (!$uploadPending) return;
    if (!pendingUploadFiles.length) {
      $uploadPending.textContent = 'No files selected.';
      return;
    }
    const names = pendingUploadFiles.map((f) => f.name);
    const shown = names.slice(0, 8).join(', ');
    const more = names.length > 8 ? ' (+' + (names.length - 8) + ' more)' : '';
    $uploadPending.textContent =
      names.length + ' file' + (names.length === 1 ? '' : 's') + ' ready: ' + shown + more;
  }

  function stageUploadFiles(fileList) {
    const incoming = Array.from(fileList || []);
    if (!incoming.length) return;
    const byKey = new Map();
    pendingUploadFiles.forEach((f) => {
      byKey.set(f.name + ':' + f.size + ':' + f.lastModified, f);
    });
    incoming.forEach((f) => {
      byKey.set(f.name + ':' + f.size + ':' + f.lastModified, f);
    });
    pendingUploadFiles = Array.from(byKey.values());
    renderPendingUploads();
    setStatus(pendingUploadFiles.length ? 'Ready to upload — press Upload' : '');
  }

  function clearPendingUploads() {
    pendingUploadFiles = [];
    if ($file) $file.value = '';
    renderPendingUploads();
  }

  async function loadDocuments() {
    if (!$docList) return;
    const r = await fetch(api('documents.php')).then((x) => x.json());
    $docList.innerHTML = '';
    if (r.error) {
      $docList.textContent = r.error;
      if ($docSummaryHint) $docSummaryHint.textContent = '';
      setDocsStatus(r.error, true);
      return;
    }
    const docs = Array.isArray(r.documents) ? r.documents : [];
    let readyN = 0;
    let parsingN = 0;
    let awaitChunkN = 0;
    let failN = 0;
    let otherN = 0;
    docs.forEach((d) => {
      const stage = d.pipeline_stage || '';
      if (d.status === 'ready') readyN += 1;
      else if (d.status === 'failed' || stage === 'failed') failN += 1;
      else if (stage === 'parsed') awaitChunkN += 1;
      else if (stage === 'parsing' || stage === 'intake_done' || stage === 'ocr_done') parsingN += 1;
      else if (d.status === 'processing') parsingN += 1;
      else otherN += 1;
    });
    if ($docSummaryHint) {
      if (!docs.length) {
        $docSummaryHint.textContent = '';
      } else {
        let hint = docs.length + ' doc' + (docs.length === 1 ? '' : 's');
        if (parsingN || awaitChunkN || failN || otherN) {
          hint += ' · ' + readyN + ' ready';
          if (parsingN) hint += ' · ' + parsingN + ' parsing';
          if (awaitChunkN) hint += ' · ' + awaitChunkN + ' awaiting Re-chunk';
          if (failN) hint += ' · ' + failN + ' failed';
          if (otherN) hint += ' · ' + otherN + ' other';
        }
        $docSummaryHint.textContent = hint;
      }
    }
    if (!docs.length) {
      $docList.innerHTML = '<p class="hint">No documents yet.</p>';
      return;
    }
    let renderErrors = 0;
    docs.forEach((d) => {
      try {
        $docList.appendChild(buildDocRow(d));
      } catch (err) {
        renderErrors += 1;
        const fallback = document.createElement('div');
        fallback.className = 'doc-row';
        fallback.innerHTML =
          '<div class="doc-main"><strong>' +
          escapeHtml(d.title || d.filename || 'Document #' + d.id) +
          '</strong> <span class="badge err">render error</span>' +
          '<div class="hint mono">' +
          escapeHtml(err && err.message ? err.message : 'Could not render row') +
          '</div></div>';
        $docList.appendChild(fallback);
      }
    });
    if (renderErrors) {
      setDocsStatus('List loaded with ' + renderErrors + ' row render error(s) — see badges', true);
    }
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildDocRow(d) {
    const row = document.createElement('div');
    row.className = 'doc-row doc-row-tags';
    row.setAttribute('data-doc-id', String(d.id));
    row.setAttribute('data-doc-title', d.title || d.filename || 'Document');

    const main = document.createElement('div');
    main.className = 'doc-main';
    const head = document.createElement('div');
    const strong = document.createElement('strong');
    strong.textContent = d.title || d.filename || ('Document #' + d.id);
    const stage = d.pipeline_stage || '';
    const badge = document.createElement('span');
    badge.className = 'badge';
    const modeLabel =
      d.ingest_mode === 'deepseek' ? 'Files parse' :
      d.ingest_mode === 'ocr' ? 'OCR' : 'local';
    let statusLabel = d.status || 'unknown';
    if (stage === 'parsed' && d.status !== 'ready') {
      statusLabel = 'parsed — awaiting Re-chunk';
    } else if ((stage === 'parsing' || stage === 'intake_done') && d.status !== 'ready') {
      statusLabel = 'parsing';
    } else if (stage === 'failed' || d.status === 'failed') {
      statusLabel = 'failed';
    }
    badge.textContent = statusLabel + ' · ' + modeLabel + ' · ' + (d.chunk_count || 0) + ' chunks';
    if (d.status === 'ready') badge.classList.add('ok');
    else if (d.status === 'failed' || stage === 'failed') badge.classList.add('err');
    else if (stage === 'parsed') badge.classList.add('warn');
    head.append(strong, document.createTextNode(' '), badge);

    if (stage && stage !== 'ready' && stage !== 'parsed') {
      const st = document.createElement('span');
      st.className = 'hint';
      st.textContent = ' · ' + stage + (d.domain_set ? ' · ' + d.domain_set : '');
      head.appendChild(st);
    } else if (d.domain_set && stage === 'parsed') {
      const st = document.createElement('span');
      st.className = 'hint';
      st.textContent = ' · ' + d.domain_set;
      head.appendChild(st);
    }

    const mono = document.createElement('div');
    mono.className = 'hint mono';
    let monoText = d.filename || '';
    if (d.department) monoText += ' · ' + d.department;
    if (d.deepseek_file_id) monoText += ' · ' + d.deepseek_file_id;
    if (d.pipeline_error) monoText += ' — ' + d.pipeline_error;
    else if (d.error_message) monoText += ' — ' + d.error_message;
    mono.textContent = monoText;

    const nextStep = document.createElement('div');
    nextStep.className = 'doc-next-step';
    nextStep.hidden = true;
    if (stage === 'parsed' && d.status !== 'ready') {
      nextStep.hidden = false;
      nextStep.classList.add('doc-next-step-parsed');
      nextStep.textContent = 'Parse complete — click Re-chunk to make this searchable in chat';
    } else if (stage === 'parsing' || stage === 'intake_done') {
      nextStep.hidden = false;
      nextStep.classList.add('doc-next-step-parsing');
      nextStep.textContent = 'Still parsing — click Continue until parse completes';
    } else if (d.status === 'failed' || stage === 'failed') {
      nextStep.hidden = false;
      nextStep.classList.add('doc-next-step-failed');
      nextStep.textContent = 'Failed — fix the error below, then Re-parse';
    }

    const busy = document.createElement('div');
    busy.className = 'hint doc-busy';
    busy.hidden = true;
    busy.setAttribute('data-doc-busy', '1');

    const tagsWrap = document.createElement('div');
    tagsWrap.className = 'doc-tags-wrap';
    const chips = document.createElement('div');
    chips.className = 'tag-chips';
    const tagList = Array.isArray(d.tag_list)
      ? d.tag_list
      : String(d.tags || '').split(/[,;]+/).map((t) => t.trim()).filter(Boolean);
    tagList.forEach((t) => {
      const chip = document.createElement('span');
      chip.className = 'tag-chip';
      chip.textContent = t;
      chips.appendChild(chip);
    });
    if (!tagList.length) {
      const empty = document.createElement('span');
      empty.className = 'hint';
      empty.textContent = 'No tags';
      chips.appendChild(empty);
    }

    const tagEdit = document.createElement('div');
    tagEdit.className = 'inline tag-edit';
    const tagInput = document.createElement('input');
    tagInput.type = 'text';
    tagInput.className = 'tag-input';
    tagInput.placeholder = 'Tags (comma-separated)';
    tagInput.value = tagList.join(', ');
    tagInput.setAttribute('aria-label', 'Tags for ' + (d.title || d.filename || d.id));

    const urlInput = document.createElement('input');
    urlInput.type = 'url';
    urlInput.className = 'tag-input source-url-input';
    urlInput.placeholder = 'Source URL (https://…) — also live web when retrieved';
    urlInput.value = d.source_url || '';
    urlInput.title = 'Citation link and live allowlisted fetch when this document is retrieved in chat';
    urlInput.setAttribute('aria-label', 'Source URL for ' + (d.title || d.filename || d.id));

    const btnSaveMeta = document.createElement('button');
    btnSaveMeta.type = 'button';
    btnSaveMeta.className = 'btn btn-primary';
    btnSaveMeta.textContent = 'Save';
    btnSaveMeta.onclick = () => saveDocMeta(d.id, tagInput.value, urlInput.value);
    tagEdit.append(tagInput, urlInput, btnSaveMeta);

    if (d.source_url) {
      const urlHint = document.createElement('div');
      urlHint.className = 'hint mono';
      const a = document.createElement('a');
      try {
        a.href = d.source_url;
      } catch {
        a.removeAttribute('href');
      }
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = d.source_url;
      urlHint.append(document.createTextNode('Link: '), a);
      tagsWrap.append(chips, urlHint, tagEdit);
    } else {
      tagsWrap.append(chips, tagEdit);
    }
    main.append(head, mono, nextStep, busy, tagsWrap);

    const actions = document.createElement('div');
    actions.className = 'doc-actions';
    const btnChunks = document.createElement('button');
    btnChunks.className = 'btn btn-secondary';
    btnChunks.type = 'button';
    btnChunks.textContent = d.ingest_mode === 'deepseek' ? 'Stub' : 'Chunks';
    btnChunks.onclick = () => openChunks(d.id, d.title || d.filename);

    const btnReparse = document.createElement('button');
    btnReparse.className = 'btn btn-ghost';
    btnReparse.type = 'button';
    btnReparse.textContent = 'Re-parse';
    btnReparse.title = 'OCR/structure parse first; then Re-chunk';
    btnReparse.onclick = () => reparseDoc(d.id);

    const btnCont = document.createElement('button');
    btnCont.className = 'btn btn-secondary';
    btnCont.type = 'button';
    btnCont.textContent = 'Continue';
    btnCont.onclick = () => continuePipeline(d.id);
    if (stage !== 'parsing' && stage !== 'intake_done' && stage !== 'ocr_done') {
      btnCont.hidden = true;
    }

    const btnRe = document.createElement('button');
    btnRe.className = 'btn btn-ghost';
    btnRe.type = 'button';
    btnRe.textContent = 'Re-chunk';
    btnRe.title = 'Chunk from current markdown (run Re-parse first for best quality)';
    btnRe.onclick = () => rechunk(d.id);
    if (stage === 'parsing' || stage === 'intake_done' || stage === 'ocr_done') {
      btnRe.disabled = true;
      btnRe.title = 'Finish Continue until parsed, then Re-chunk';
    }
    if (stage === 'parsed') {
      btnRe.classList.add('btn-primary');
      btnRe.classList.remove('btn-ghost');
      btnRe.title = 'Parse is done — Re-chunk to index for chat';
    }

    const btnDel = document.createElement('button');
    btnDel.className = 'btn btn-destructive';
    btnDel.type = 'button';
    btnDel.textContent = 'Delete';
    btnDel.onclick = () => deleteDoc(d.id);

    actions.append(btnChunks, btnReparse, btnCont, btnRe, btnDel);
    row.append(main, actions);
    return row;
  }

  function setDocRowBusy(id, msg) {
    const row = $docList && $docList.querySelector('[data-doc-id="' + id + '"]');
    if (!row) return;
    const busy = row.querySelector('[data-doc-busy]');
    if (busy) {
      busy.hidden = !msg;
      busy.textContent = msg || '';
    }
    row.querySelectorAll('button').forEach((btn) => {
      btn.disabled = !!msg;
    });
  }

  async function saveDocMeta(id, tagsText, sourceUrl) {
    await refreshCsrf();
    setDocsStatus('Saving tags/URL for doc #' + id + '…');
    setDocRowBusy(id, 'Saving…');
    try {
      const r = await fetch(api('documents.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({
          action: 'update_meta',
          id,
          tags_text: tagsText,
          source_url: sourceUrl == null ? '' : String(sourceUrl),
        }),
      }).then((x) => x.json());
      if (r.error) throw new Error(r.error);
      const n = (r.document?.tag_list || []).length;
      const hasUrl = !!(r.document && r.document.source_url);
      setDocsStatus(
        'Saved doc #' + id + ' (' + n + ' tag' + (n === 1 ? '' : 's') + (hasUrl ? ', URL set' : '') + ')'
      );
      await loadDocuments();
    } catch (e) {
      setDocRowBusy(id, '');
      setDocsStatus(e.message || 'Save failed', true);
    }
  }

  function docMetaFields() {
    return {
      title: (document.getElementById('doc-title')?.value || '').trim(),
      department: (document.getElementById('doc-department')?.value || '').trim(),
      tags: (document.getElementById('doc-tags')?.value || '').trim(),
      source_url: (document.getElementById('doc-source-url')?.value || '').trim(),
    };
  }

  async function uploadOne(file, extraFields) {
    const fd = new FormData();
    fd.append('file', file);
    const meta = docMetaFields();
    const parsePurpose = document.getElementById('parse-purpose')?.value || '';
    const ingestMethod = document.getElementById('ingest-method')?.value || 'auto';
    if (meta.title) fd.append('title', meta.title);
    if (meta.department) fd.append('department', meta.department);
    if (meta.tags) fd.append('tags', meta.tags);
    if (meta.source_url) fd.append('source_url', meta.source_url);
    if (parsePurpose) fd.append('parse_purpose', parsePurpose);
    if (ingestMethod && ingestMethod !== 'auto') fd.append('ingest_method', ingestMethod);
    if (extraFields && typeof extraFields === 'object') {
      Object.keys(extraFields).forEach((k) => {
        if (extraFields[k] != null && extraFields[k] !== '') fd.set(k, extraFields[k]);
      });
    }
    const res = await fetch(api('upload.php'), {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf() },
      body: fd,
    });
    let r = null;
    try {
      r = await res.json();
    } catch {
      throw new Error('Upload failed (invalid server response)');
    }
    if (!res.ok || r.error || !r.document) {
      throw new Error(r?.error || ('Upload failed (' + res.status + ')'));
    }
    return r.document;
  }

  async function uploadFiles(fileList) {
    const files = Array.from(fileList || []);
    if (!files.length) {
      setStatus('Choose files first', true);
      return;
    }
    await refreshCsrf();
    let lastDoc = null;
    let failed = 0;
    for (const file of files) {
      setStatus('Uploading ' + file.name + '…');
      try {
        lastDoc = await uploadOne(file);
        setStatus('Uploaded ' + file.name + ' (' + (lastDoc.chunk_count || 0) + ' chunks)');
      } catch (e) {
        failed += 1;
        setStatus(file.name + ': ' + e.message, true);
      }
    }
    clearPendingUploads();
    await loadDocuments();
    if (lastDoc && lastDoc.id) {
      highlightDoc(lastDoc.id);
    }
    if (!failed && lastDoc) {
      setStatus('Upload complete');
    }
  }

  function highlightDoc(id) {
    const row = $docList.querySelector('[data-doc-id="' + id + '"]');
    if (!row) return;
    row.classList.add('doc-row-new');
    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const title = row.getAttribute('data-doc-title') || 'Document';
    openChunks(id, title);
  }

  async function ingestPastedText() {
    const $ta = document.getElementById('paste-text');
    const text = ($ta?.value || '').trim();
    if (!text) {
      setStatus('Paste some text first', true);
      return;
    }
    await refreshCsrf();
    setStatus('Chunking pasted text…');
    const meta = docMetaFields();
    const format = (document.getElementById('paste-format')?.value || 'txt') === 'md' ? 'md' : 'txt';
    const base = (meta.title || 'pasted-text')
      .replace(/[^\w\-]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .slice(0, 80) || 'pasted-text';
    const filename = base + '.' + format;
    const mime = format === 'md' ? 'text/markdown' : 'text/plain';
    const file = new File([text], filename, { type: mime });
    const extra = {};
    if (!meta.title) extra.auto_title = '1';
    try {
      const doc = await uploadOne(file, extra);
      setStatus('Pasted text added (' + (doc.chunk_count || 0) + ' chunks)');
      if ($ta) $ta.value = '';
      await loadDocuments();
      if (doc.id) highlightDoc(doc.id);
    } catch (e) {
      setStatus(e.message || 'Paste ingest failed', true);
    }
  }

  async function deleteDoc(id) {
    if (!confirm('Delete this document and its chunks?')) return;
    await refreshCsrf();
    const r = await fetch(api('documents.php?id=' + id), {
      method: 'DELETE',
      headers: { 'X-CSRF-Token': csrf() },
    }).then((x) => x.json());
    if (r.error) {
      setStatus(r.error, true);
      return;
    }
    $chunksPanel.hidden = true;
    loadDocuments();
  }

  async function rechunk(id) {
    await refreshCsrf();
    setDocsStatus('Re-chunking doc #' + id + ' from markdown…');
    setDocRowBusy(id, 'Re-chunking…');
    try {
      const r = await fetch(api('documents.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({ action: 'rechunk', id }),
      }).then((x) => x.json());
      if (r.error) throw new Error(r.error);
      const n = r.result?.chunk_count || 0;
      const stage = r.result?.pipeline_stage || 'ready';
      setDocsStatus('Doc #' + id + ' re-chunked → ' + n + ' chunks · stage ' + stage);
      await loadDocuments();
    } catch (e) {
      setDocRowBusy(id, '');
      setDocsStatus(e.message || 'Re-chunk failed', true);
      await loadDocuments();
    }
  }

  async function reparseDoc(id) {
    await refreshCsrf();
    setDocsStatus('Re-parsing doc #' + id + ' (OCR + structure)… this can take a minute');
    setDocRowBusy(id, 'Re-parsing (OCR + structure)…');
    try {
      const r = await fetch(api('documents.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({ action: 'reparse', id }),
      }).then((x) => x.json());
      if (r.error) throw new Error(r.error);
      const res = r.result || {};
      const notice = noticeFromParseResult(id, res);
      setDocsStatus(notice.msg, notice.err);
      await loadDocuments();
    } catch (e) {
      setDocRowBusy(id, '');
      setDocsStatus(e.message || 'Re-parse failed', true);
      await loadDocuments();
    }
  }

  async function continuePipeline(id) {
    await refreshCsrf();
    setDocsStatus('Continuing parse for doc #' + id + '…');
    setDocRowBusy(id, 'Parsing batches…');
    let guard = 0;
    try {
      while (guard < 40) {
        guard += 1;
        const r = await fetch(api('documents.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ action: 'continue_pipeline', id, auto_chunk: false }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        const res = r.result || {};
        if (res.need_continue) {
          const notice = noticeFromParseResult(id, res);
          setDocsStatus(notice.msg, notice.err);
          setDocRowBusy(id, 'Parsing…' + batchLabel(res));
          await refreshCsrf();
          continue;
        }
        const notice = noticeFromParseResult(id, res);
        setDocsStatus(notice.msg, notice.err);
        await loadDocuments();
        return;
      }
      setDocsStatus('Doc #' + id + ': stopped after many batches — click Continue again', true);
      await loadDocuments();
    } catch (e) {
      setDocRowBusy(id, '');
      setDocsStatus(e.message || 'Continue failed', true);
      await loadDocuments();
    }
  }

  async function openChunks(docId, title) {
    $chunksPanel.hidden = false;
    if ($chunksDetails) $chunksDetails.open = true;
    if ($chunksTitle) $chunksTitle.textContent = title || '';
    $chunksList.innerHTML = '<p class="hint">Loading…</p>';
    $chunksPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const r = await fetch(api('chunks.php?document_id=' + docId)).then((x) => x.json());
    if (r.error) {
      $chunksList.textContent = r.error;
      return;
    }
    $chunksList.innerHTML = '';
    (r.chunks || []).forEach((c) => {
      const el = document.createElement('div');
      el.className = 'chunk-card';
      const head = document.createElement('div');
      head.className = 'chunk-head';
      head.textContent = '#' + c.chunk_index + (c.header_path ? ' · ' + c.header_path : '') + ' · ~' + c.token_count + ' tok';
      const ta = document.createElement('textarea');
      ta.rows = 6;
      ta.value = c.text;
      const actions = document.createElement('div');
      actions.className = 'inline';
      const save = document.createElement('button');
      save.type = 'button';
      save.className = 'btn btn-primary';
      save.textContent = 'Save';
      save.onclick = async () => {
        await refreshCsrf();
        const res = await fetch(api('chunks.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ id: c.id, text: ta.value }),
        }).then((x) => x.json());
        setStatus(res.error ? res.error : 'Chunk saved', !!res.error);
      };
      actions.appendChild(save);
      el.append(head, ta, actions);
      $chunksList.appendChild(el);
    });
  }

  const $pasteIngest = document.getElementById('paste-ingest');
  if ($pasteIngest) {
    $pasteIngest.addEventListener('click', () => { ingestPastedText(); });
  }

  if ($drop && $file) {
    $drop.addEventListener('click', () => $file.click());
    $drop.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        $file.click();
      }
    });
    $file.addEventListener('change', () => {
      stageUploadFiles($file.files);
      $file.value = '';
    });
    ['dragenter', 'dragover'].forEach((ev) => {
      $drop.addEventListener(ev, (e) => {
        e.preventDefault();
        $drop.classList.add('dragover');
      });
    });
    ['dragleave', 'drop'].forEach((ev) => {
      $drop.addEventListener(ev, (e) => {
        e.preventDefault();
        $drop.classList.remove('dragover');
      });
    });
    $drop.addEventListener('drop', (e) => {
      stageUploadFiles(e.dataTransfer?.files);
    });
  }

  if ($uploadSubmit) {
    $uploadSubmit.addEventListener('click', () => uploadFiles(pendingUploadFiles));
  }
  if ($uploadClear) {
    $uploadClear.addEventListener('click', () => {
      clearPendingUploads();
      setStatus('Cleared selected files');
    });
  }
  renderPendingUploads();

  $retrievalForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const query = $retrievalQuery.value.trim();
    if (!query) return;
    await refreshCsrf();
    $retrievalResults.innerHTML = '<p class="hint">Searching…</p>';
    const r = await fetch(api('retrieval-test.php'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf(),
      },
      body: JSON.stringify({ query }),
    }).then((x) => x.json());
    if (r.error) {
      $retrievalResults.innerHTML = '<p class="err">' + r.error + '</p>';
      return;
    }
    const hits = r.hits || [];
    const q = r.quality || {};
    const metaBits = [];
    if (r.search_query && r.search_query !== r.query) {
      metaBits.push('Search query: ' + r.search_query);
    }
    if (r.rewritten) metaBits.push('Rewritten');
    if ((r.focus_tags || []).length) metaBits.push('Tags: ' + r.focus_tags.join(', '));
    if (typeof q.max_score === 'number') {
      metaBits.push(
        'Quality: score ' + Number(q.max_score).toFixed(2) +
        (q.weak ? ' · weak' : ' · ok') +
        ' · hits ' + (q.hit_count || hits.length)
      );
    }
    $retrievalResults.innerHTML = '';
    if (metaBits.length) {
      const meta = document.createElement('p');
      meta.className = 'hint';
      meta.textContent = metaBits.join(' · ');
      $retrievalResults.appendChild(meta);
    }
    if (!hits.length) {
      const empty = document.createElement('p');
      empty.className = 'hint';
      empty.textContent = 'No hits.';
      $retrievalResults.appendChild(empty);
      return;
    }
    hits.forEach((h, i) => {
      const el = document.createElement('div');
      el.className = 'hit-card';
      const title = document.createElement('div');
      title.className = 'chunk-head';
      title.textContent =
        '[' + (i + 1) + '] ' + (h.title || h.filename || 'Doc') +
        (h.header_path ? ' — ' + h.header_path : '') +
        ' · score ' + Number(h.score).toFixed(2);
      const pre = document.createElement('pre');
      pre.textContent = h.text;
      el.append(title, pre);
      $retrievalResults.appendChild(el);
    });
  });

  function setHostsStatus(msg, isErr) {
    if (!$webHostsStatus) return;
    $webHostsStatus.textContent = msg || '';
    $webHostsStatus.classList.toggle('err', !!isErr);
    $webHostsStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function applyDocSourceUrlWeb(on) {
    const el = document.getElementById(on ? 'doc-source-url-web-on' : 'doc-source-url-web-off');
    if (el) el.checked = true;
    updateWebHostsSummaryHint();
  }

  function selectedDocSourceUrlWeb() {
    const el = document.querySelector('input[name="doc-source-url-web"]:checked');
    return el ? el.value === '1' : true;
  }

  function countWebHostsText(text) {
    return String(text || '')
      .split(/\r?\n/)
      .map((l) => l.trim())
      .filter((l) => l !== '' && !l.startsWith('#')).length;
  }

  function updateWebHostsSummaryHint() {
    if (!$webHostsSummaryHint) return;
    const n = countWebHostsText($webHosts ? $webHosts.value : '');
    const src = selectedDocSourceUrlWeb() ? 'Source URLs on' : 'Source URLs off';
    $webHostsSummaryHint.textContent =
      (n === 0 ? 'no domains' : n + ' domain' + (n === 1 ? '' : 's')) + ' · ' + src;
  }

  function setSimpleAgeStatus(msg, isErr) {
    if (!$simpleAgeStatus) return;
    $simpleAgeStatus.textContent = msg || '';
    $simpleAgeStatus.classList.toggle('err', !!isErr);
    $simpleAgeStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function updateSimpleAgeSummaryHint() {
    if (!$simpleAgeSummaryHint) return;
    let age = $simpleAge ? parseInt($simpleAge.value, 10) : 10;
    if (!Number.isFinite(age)) age = 10;
    $simpleAgeSummaryHint.textContent = 'age ' + age;
  }

  function setAnswerLengthStatus(msg, isErr) {
    if (!$answerLengthStatus) return;
    $answerLengthStatus.textContent = msg || '';
    $answerLengthStatus.classList.toggle('err', !!isErr);
    $answerLengthStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function updateAnswerLengthSummaryHint() {
    if (!$answerLengthSummaryHint) return;
    $answerLengthSummaryHint.textContent = selectedAnswerLength();
  }

  function applyAnswerLength(len) {
    const v = ['short', 'medium', 'long'].includes(len) ? len : 'medium';
    const el = document.getElementById('answer-length-' + v);
    if (el) el.checked = true;
    updateAnswerLengthSummaryHint();
  }

  function selectedAnswerLength() {
    const el = document.querySelector('input[name="answer-length"]:checked');
    return el ? el.value : 'medium';
  }

  function setAdaptiveStatus(msg, isErr) {
    if (!$adaptiveStatus) return;
    $adaptiveStatus.textContent = msg || '';
    $adaptiveStatus.classList.toggle('err', !!isErr);
    $adaptiveStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function updateAdaptiveSummaryHint() {
    if (!$adaptiveSummaryHint) return;
    $adaptiveSummaryHint.textContent = selectedAdaptiveRetrieval() ? 'On' : 'Off';
  }

  function applyAdaptiveRetrieval(on) {
    const el = document.getElementById(on ? 'adaptive-retrieval-on' : 'adaptive-retrieval-off');
    if (el) el.checked = true;
    updateAdaptiveSummaryHint();
  }

  function selectedAdaptiveRetrieval() {
    const el = document.querySelector('input[name="adaptive-retrieval"]:checked');
    return el ? el.value === '1' : false;
  }

  function updateRagWebMixSummaryHint() {
    if (!$ragWebMixSummaryHint) return;
    const mix = selectedRagWebMix();
    $ragWebMixSummaryHint.textContent = (mix.mode || 'complement') + ' · ' + (mix.degree ?? 50);
  }

  function updateUiFeaturesSummaryHint() {
    if (!$uiFeaturesSummaryHint) return;
    const histOn = document.getElementById('ui-chat-history-on');
    const history = histOn && histOn.checked ? 'History on' : 'History off';
    let shown = 0;
    let total = 0;
    if ($uiFeaturesList) {
      $uiFeaturesList.querySelectorAll('.ui-feature-row').forEach((row) => {
        total += 1;
        const vis = row.querySelector('.ui-feat-visibility');
        if (vis && vis.value === 'show') shown += 1;
      });
    }
    const feat = total
      ? shown + '/' + total + ' shown'
      : 'features';
    $uiFeaturesSummaryHint.textContent = history + ' · ' + feat;
  }

  function setRagWebMixStatus(msg, isErr) {
    if (!$ragWebMixStatus) return;
    $ragWebMixStatus.textContent = msg || '';
    $ragWebMixStatus.classList.toggle('err', !!isErr);
    $ragWebMixStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function setFaithfulnessStatus(msg, isErr) {
    if (!$faithfulnessStatus) return;
    $faithfulnessStatus.textContent = msg || '';
    $faithfulnessStatus.classList.toggle('err', !!isErr);
    $faithfulnessStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function faithfulnessBand(degree) {
    const d = Number.isFinite(degree) ? degree : 0;
    if (d <= 0) return 'off';
    if (d < 55) return 'light';
    if (d <= 70) return 'balanced';
    return 'strict';
  }

  function faithfulnessLaymanHint(degree) {
    const d = Number.isFinite(degree) ? degree : 0;
    const band = faithfulnessBand(d);
    if (band === 'off') {
      return 'Off (recommended default): no post-stream rewrite. You keep the streamed answer.';
    }
    if (band === 'light') {
      return (
        'Light (degree ' +
        d +
        '): records Faithfulness: ok but never replaces the streamed answer. Safe if you want the feature on without risk of gutting drafts.'
      );
    }
    if (band === 'balanced') {
      return (
        'Balanced (degree ' +
        d +
        '): may apply a surgical rewrite only (≥85% of draft kept). Rejects hedge rewrites (“excerpts don’t show…”). Orphan scan on.'
      );
    }
    return (
      'Strict (degree ' +
      d +
      '): surgical rewrites + orphan scan' +
      (d >= 75 ? ' + silent cite strip' : '') +
      '. Still rejects heavy trims and hedge language. Use only if invented section numbers are a real problem.'
    );
  }

  function updateFaithfulnessHint() {
    const degree = $faithfulnessDegree ? parseInt($faithfulnessDegree.value, 10) : 0;
    const d = Number.isFinite(degree) ? degree : 0;
    if ($faithfulnessDegreeVal) $faithfulnessDegreeVal.textContent = String(d);
    if ($faithfulnessHint) $faithfulnessHint.textContent = faithfulnessLaymanHint(d);
    if ($faithfulnessSummaryHint) {
      const band = faithfulnessBand(d);
      $faithfulnessSummaryHint.textContent = band === 'off' ? 'off' : band + ' · ' + d;
    }
  }

  function applyFaithfulness(policy) {
    const p = policy && typeof policy === 'object' ? policy : {};
    let degree = typeof p.degree === 'number' ? p.degree : parseInt(p.degree, 10);
    if (!Number.isFinite(degree)) degree = 0;
    degree = Math.max(0, Math.min(100, degree));
    if ($faithfulnessDegree) $faithfulnessDegree.value = String(degree);
    updateFaithfulnessHint();
  }

  function selectedFaithfulness() {
    let degree = $faithfulnessDegree ? parseInt($faithfulnessDegree.value, 10) : 0;
    if (!Number.isFinite(degree)) degree = 0;
    return { degree: Math.max(0, Math.min(100, degree)) };
  }

  function setDeepPolicyStatus(msg, isErr) {
    if (!$deepPolicyStatus) return;
    $deepPolicyStatus.textContent = msg || '';
    $deepPolicyStatus.classList.toggle('err', !!isErr);
    $deepPolicyStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function applyDeepPolicy(policy) {
    const p = policy && typeof policy === 'object' ? policy : {};
    const mode = ['manual', 'auto', 'always'].includes(p.mode) ? p.mode : 'manual';
    const el = document.getElementById('deep-policy-' + mode);
    if (el) el.checked = true;
    if ($deepMaxPerChat) {
      const max = Number.isFinite(Number(p.max_per_chat)) ? Number(p.max_per_chat) : 3;
      $deepMaxPerChat.value = String(Math.max(0, Math.min(50, max)));
    }
    if ($deepPolicySummaryHint) {
      const max = Number($deepMaxPerChat && $deepMaxPerChat.value) || 0;
      $deepPolicySummaryHint.textContent =
        mode + (max > 0 ? ' · max ' + max + '/chat' : ' · unlimited');
    }
  }

  function selectedDeepPolicy() {
    const el = document.querySelector('input[name="deep-policy-mode"]:checked');
    const mode = el ? el.value : 'manual';
    let max = $deepMaxPerChat ? parseInt($deepMaxPerChat.value, 10) : 3;
    if (!Number.isFinite(max)) max = 3;
    max = Math.max(0, Math.min(50, max));
    return { mode, max_per_chat: max };
  }

  function renderDeepStats(stats) {
    if (!$deepStats) return;
    const s = stats && typeof stats === 'object' ? stats : {};
    const n = (k) => (Number.isFinite(Number(s[k])) ? Number(s[k]) : 0);
    const total = n('total');
    if (total <= 0) {
      $deepStats.textContent = 'Stats: no Deep decisions recorded yet.';
      return;
    }
    $deepStats.textContent =
      'Stats: ' +
      n('on') +
      ' on / ' +
      n('off') +
      ' off (total ' +
      total +
      ') — auto on ' +
      n('auto_on') +
      ', auto off ' +
      n('auto_off') +
      ', toggle force ' +
      n('user_force') +
      ', manual on ' +
      n('manual_on') +
      ', always ' +
      n('always') +
      ', capped ' +
      n('capped');
  }

  function degreeBand(degree) {
    if (degree <= 25) return 'light';
    if (degree <= 60) return 'medium';
    return 'strong';
  }

  /** Plain-English explanation of the current mix for admins. */
  function ragWebMixLaymanHint(mode, degree) {
    const d = Number.isFinite(degree) ? degree : 50;
    const band = degreeBand(d);
    const strength =
      band === 'light'
        ? 'only a little'
        : band === 'medium'
          ? 'a fair amount'
          : 'a lot';

    if (mode === 'docs_first') {
      return (
        'In plain terms: trust your uploaded Act, Regulations, and guides first. ' +
        'The live website is used ' +
        strength +
        ' (degree ' +
        d +
        ') only when the documents are thin or silent — for example current how-to pages. ' +
        'Web should not override what the Act says. Good default when legal accuracy matters most.'
      );
    }
    if (mode === 'web_first') {
      return (
        'In plain terms: lead with what the live allowlisted website says today. ' +
        'Your uploaded documents still supply ' +
        strength +
        ' of the legal backbone (degree ' +
        d +
        ') — section numbers and duties from the Act/Regs. ' +
        'Useful when the site is the day-to-day guide and the PDFs are the rulebook.'
      );
    }
    if (mode === 'compete') {
      return (
        'In plain terms: do not blend both sides into one soft answer. ' +
        'Pick the clearer winner for the question. ' +
        (band === 'light'
          ? 'At this degree (' + d + '), the uploaded documents usually win when both look plausible.'
          : band === 'medium'
            ? 'At this degree (' + d + '), either side can win — whichever fits the question better.'
            : 'At this degree (' + d + '), the live website is favoured when both sides look plausible (Act/Regs still win clear statute questions).') +
        ' Use this when you want a decisive answer, not a merge.'
      );
    }
    // complement
    return (
      'In plain terms: use your uploaded documents and the live website together as one answer. ' +
      'The site adds ' +
      strength +
      ' of extra detail and up-to-date wording (degree ' +
      d +
      '), while Act/Regulations stay the legal authority. ' +
      'Web content that the PDFs omit should be kept, not thrown away. ' +
      'Best everyday setting when Docs and Web are both on.'
    );
  }

  function updateRagWebMixHint() {
    if ($ragWebMixHint) {
      const mix = selectedRagWebMix();
      $ragWebMixHint.textContent = ragWebMixLaymanHint(mix.mode, mix.degree);
    }
    updateRagWebMixSummaryHint();
  }

  function applyRagWebMix(mix) {
    const mode = (mix && mix.mode) || 'complement';
    const degree = mix && typeof mix.degree === 'number' ? mix.degree : 50;
    const map = {
      complement: 'rag-web-complement',
      docs_first: 'rag-web-docs-first',
      web_first: 'rag-web-web-first',
      compete: 'rag-web-compete',
    };
    const id = map[mode] || map.complement;
    const el = document.getElementById(id);
    if (el) el.checked = true;
    if ($ragWebDegree) $ragWebDegree.value = String(degree);
    if ($ragWebDegreeVal) $ragWebDegreeVal.textContent = String(degree);
    updateRagWebMixHint();
  }

  function selectedRagWebMix() {
    const el = document.querySelector('input[name="rag-web-mode"]:checked');
    const mode = el ? el.value : 'complement';
    const degree = $ragWebDegree ? parseInt($ragWebDegree.value, 10) : 50;
    return {
      mode,
      degree: Number.isFinite(degree) ? degree : 50,
    };
  }

  if ($ragWebDegree) {
    $ragWebDegree.addEventListener('input', () => {
      if ($ragWebDegreeVal) $ragWebDegreeVal.textContent = $ragWebDegree.value;
      updateRagWebMixHint();
    });
  }
  document.querySelectorAll('input[name="rag-web-mode"]').forEach((radio) => {
    radio.addEventListener('change', updateRagWebMixHint);
  });
  updateRagWebMixHint();

  function setUiFeaturesStatus(msg, isErr) {
    if (!$uiFeaturesStatus) return;
    $uiFeaturesStatus.textContent = msg || '';
    $uiFeaturesStatus.classList.toggle('err', !!isErr);
    $uiFeaturesStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function renderUiFeatures(ui) {
    if (!$uiFeaturesList) return;
    const features = (ui && ui.features) || {};
    $uiFeaturesList.innerHTML = '';
    Object.keys(UI_FEATURE_LABELS).forEach((key) => {
      const f = features[key] || { default: false, visibility: 'show' };
      const row = document.createElement('div');
      row.className = 'ui-feature-row';
      row.dataset.feature = key;
      if (UI_VISIBILITY_ONLY[key]) row.dataset.visibilityOnly = '1';

      const controls = document.createElement('div');
      controls.className = 'ui-feature-controls';

      const lab = document.createElement('span');
      lab.className = 'ui-feature-label';
      lab.innerHTML = uiFeatureLabelHtml(key);

      const vis = document.createElement('select');
      vis.className = 'ui-feat-visibility';
      vis.setAttribute('aria-label', 'Visibility for ' + UI_FEATURE_LABELS[key]);
      [
        ['show', 'Show'],
        ['hide', 'Hide'],
      ].forEach(([val, label]) => {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = label;
        const savedVis = (f.visibility === 'force') ? 'hide' : (f.visibility || 'show');
        if (savedVis === val) opt.selected = true;
        vis.appendChild(opt);
      });
      vis.addEventListener('change', updateUiFeatureHints);

      if (UI_VISIBILITY_ONLY[key]) {
        controls.append(lab, vis);
      } else {
        const defLabel = document.createElement('label');
        defLabel.className = 'mode-toggle';
        const defCb = document.createElement('input');
        defCb.type = 'checkbox';
        defCb.className = 'ui-feat-default';
        defCb.checked = !!f.default;
        defCb.addEventListener('change', updateUiFeatureHints);
        defLabel.append(defCb, document.createTextNode(' Default on'));
        controls.append(lab, defLabel, vis);
      }

      const hint = document.createElement('p');
      hint.className = 'hint ui-feat-hint';
      hint.setAttribute('aria-live', 'polite');

      row.append(controls, hint);
      $uiFeaturesList.appendChild(row);
    });
    updateUiFeatureHints();
    updateUiFeaturesSummaryHint();
  }

  function collectUiConfig() {
    const features = {};
    if ($uiFeaturesList) {
      $uiFeaturesList.querySelectorAll('.ui-feature-row').forEach((row) => {
        const key = row.dataset.feature;
        if (!key) return;
        const vis = row.querySelector('.ui-feat-visibility');
        const visibilityOnly = !!UI_VISIBILITY_ONLY[key];
        const defCb = row.querySelector('.ui-feat-default');
        features[key] = {
          default: visibilityOnly ? true : !!(defCb && defCb.checked),
          visibility: (vis && vis.value) || 'show',
        };
      });
    }
    const histOn = document.getElementById('ui-chat-history-on');
    return {
      intro: ($uiIntro && $uiIntro.value) || '',
      chat_history: !!(histOn && histOn.checked),
      features,
    };
  }

  function setBrandingStatus(msg, isErr) {
    if (!$brandingStatus) return;
    $brandingStatus.textContent = msg || '';
    $brandingStatus.classList.toggle('err', !!isErr);
    $brandingStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function applyDocsModeLabel(label) {
    const t = (label || '').trim() || 'Docs';
    UI_FEATURE_LABELS.workcover = t;
    updateUiFeaturesSummaryHint();
  }

  function updateBrandingSummaryHint(b) {
    if (!$brandingSummaryHint || !b) return;
    const title = (b.app_title || '').trim() || '(config title)';
    $brandingSummaryHint.textContent = title + ' · ' + ((b.docs_mode_label || 'Docs').trim() || 'Docs');
  }

  function brandingFieldValue(stored, effective) {
    const s = (stored || '').trim();
    return s !== '' ? stored : effective || '';
  }

  function setBrandingOverrideField(el, stored, effective) {
    if (!el) return;
    const eff = (effective || '').trim();
    const val = brandingFieldValue(stored, effective);
    el.value = val || eff;
    el.dataset.builtinDefault = eff || val;
  }

  function collectBrandingOverrideField(el) {
    if (!el) return '';
    const v = el.value || '';
    const builtin = el.dataset.builtinDefault || '';
    if (builtin && v.trim() === builtin.trim()) {
      return '';
    }
    return v;
  }

  function applyAssistantBranding(b) {
    if (!b) return;
    if ($brandAppTitle) $brandAppTitle.value = b.app_title || '';
    if ($brandDocsLabel) $brandDocsLabel.value = b.docs_mode_label || '';
    if ($brandChatPlaceholder) $brandChatPlaceholder.value = b.chat_placeholder || '';
    if ($brandAbout) $brandAbout.value = b.about_blurb || '';
    if ($brandSystemPrompt) $brandSystemPrompt.value = b.system_prompt || '';
    if ($brandRagPrompt) $brandRagPrompt.value = b.rag_system_prompt || '';
    if ($brandSourceHierarchy) $brandSourceHierarchy.value = b.source_hierarchy || '';
    setBrandingOverrideField(
      $brandGroundingRules,
      b.grounding_rules,
      b.grounding_rules_effective
    );
    setBrandingOverrideField(
      $brandGroundingRulesWeb,
      b.grounding_rules_web,
      b.grounding_rules_web_effective
    );
    setBrandingOverrideField(
      $brandFollowups,
      b.followups_instruction,
      b.followups_instruction_effective
    );
    if ($brandMixComplement) $brandMixComplement.value = b.mix_complement || '';
    if ($brandMixDocsFirst) $brandMixDocsFirst.value = b.mix_docs_first || '';
    if ($brandMixWebFirst) $brandMixWebFirst.value = b.mix_web_first || '';
    if ($brandMixCompete) $brandMixCompete.value = b.mix_compete || '';
    applyDocsModeLabel(b.docs_mode_label);
    updateBrandingSummaryHint(b);
    if ($onboardingBanner) {
      $onboardingBanner.hidden = !!b.onboarding_dismissed;
    }
  }

  function collectAssistantBranding(extra) {
    const base = {
      app_title: ($brandAppTitle && $brandAppTitle.value) || '',
      docs_mode_label: ($brandDocsLabel && $brandDocsLabel.value) || '',
      chat_placeholder: ($brandChatPlaceholder && $brandChatPlaceholder.value) || '',
      about_blurb: ($brandAbout && $brandAbout.value) || '',
      system_prompt: ($brandSystemPrompt && $brandSystemPrompt.value) || '',
      rag_system_prompt: ($brandRagPrompt && $brandRagPrompt.value) || '',
      source_hierarchy: ($brandSourceHierarchy && $brandSourceHierarchy.value) || '',
      grounding_rules: collectBrandingOverrideField($brandGroundingRules),
      grounding_rules_web: collectBrandingOverrideField($brandGroundingRulesWeb),
      followups_instruction: collectBrandingOverrideField($brandFollowups),
      mix_complement: ($brandMixComplement && $brandMixComplement.value) || '',
      mix_docs_first: ($brandMixDocsFirst && $brandMixDocsFirst.value) || '',
      mix_web_first: ($brandMixWebFirst && $brandMixWebFirst.value) || '',
      mix_compete: ($brandMixCompete && $brandMixCompete.value) || '',
    };
    return Object.assign(base, extra || {});
  }

  function applyUiConfig(ui) {
    if (!ui) return;
    if ($uiIntro && typeof ui.intro === 'string') $uiIntro.value = ui.intro;
    const on = document.getElementById(ui.chat_history === false ? 'ui-chat-history-off' : 'ui-chat-history-on');
    if (on) on.checked = true;
    renderUiFeatures(ui);
    updateUiFeaturesSummaryHint();
  }

  async function loadAssistantBranding() {
    if (
      !$brandAppTitle &&
      !$brandGroundingRules &&
      !$saveBranding
    ) {
      return;
    }
    try {
      const r = await fetch(api('settings.php'), { credentials: 'same-origin' }).then((x) => x.json());
      if (r.error) {
        setBrandingStatus(r.error, true);
        return;
      }
      if (r.assistant_branding) {
        applyAssistantBranding(r.assistant_branding);
        setBrandingStatus(
          r.assistant_branding.source === 'admin' ? 'Loaded (admin)' : 'Loaded (defaults)'
        );
      }
    } catch (e) {
      setBrandingStatus(e.message || 'Failed to load assistant', true);
    }
  }

  async function loadWebHosts() {
    if (
      !$webHosts &&
      !$simpleAge &&
      !$saveAnswerLength &&
      !$saveAdaptive &&
      !$saveDeepPolicy &&
      !$saveFaithfulness &&
      !$saveUiFeatures &&
      !$saveBranding
    ) {
      return;
    }
    try {
      const r = await fetch(api('settings.php')).then((x) => x.json());
      if (r.error) {
        setHostsStatus(r.error, true);
        setSimpleAgeStatus(r.error, true);
        setAnswerLengthStatus(r.error, true);
        setAdaptiveStatus(r.error, true);
        setDeepPolicyStatus(r.error, true);
        setFaithfulnessStatus(r.error, true);
        setUiFeaturesStatus(r.error, true);
        return;
      }
      if ($webHosts) {
        $webHosts.value = r.hosts_text || (r.allowed_hosts || []).join('\n');
        const docWeb =
          typeof r.doc_source_url_web === 'boolean' ? r.doc_source_url_web : true;
        applyDocSourceUrlWeb(docWeb);
        updateWebHostsSummaryHint();
        setHostsStatus(
          (r.source === 'admin' ? 'Loaded (admin override)' : 'Loaded (config default)') +
          ' · Source URLs ' +
          (docWeb ? 'included' : 'excluded') +
          (r.doc_source_url_web_source ? ' (' + r.doc_source_url_web_source + ')' : '')
        );
      }
      if ($simpleAge && r.simple_explain_age != null) {
        $simpleAge.value = r.simple_explain_age;
        updateSimpleAgeSummaryHint();
        setSimpleAgeStatus(
          r.simple_explain_age_source === 'admin'
            ? 'Loaded (admin)'
            : 'Loaded (default ' + r.simple_explain_age + ')'
        );
      }
      if (r.answer_length) {
        applyAnswerLength(r.answer_length);
        setAnswerLengthStatus(
          r.answer_length_source === 'admin'
            ? 'Loaded (admin): ' + r.answer_length
            : 'Loaded (default): ' + r.answer_length
        );
      }
      if (typeof r.adaptive_retrieval === 'boolean') {
        applyAdaptiveRetrieval(r.adaptive_retrieval);
        setAdaptiveStatus(
          (r.adaptive_retrieval ? 'On' : 'Off') +
          ' (' + (r.adaptive_retrieval_source || 'default') + ')'
        );
      }
      if (r.rag_web_mix) {
        applyRagWebMix(r.rag_web_mix);
        setRagWebMixStatus(
          (r.rag_web_mix.mode || 'complement') +
          ' · degree ' +
          (r.rag_web_mix.degree ?? 50) +
          ' (' +
          (r.rag_web_mix.source || 'default') +
          ')'
        );
      }
      if (r.deep_thinking_policy) {
        applyDeepPolicy(r.deep_thinking_policy);
        setDeepPolicyStatus(
          (r.deep_thinking_policy.mode || 'auto') +
          ' · max ' +
          (r.deep_thinking_policy.max_per_chat ?? 3) +
          '/chat (' +
          (r.deep_thinking_policy.source || 'default') +
          ')'
        );
      }
      renderDeepStats(r.deep_thinking_stats);
      if (r.faithfulness) {
        applyFaithfulness(r.faithfulness);
        setFaithfulnessStatus(
          (r.faithfulness.band || faithfulnessBand(r.faithfulness.degree)) +
          ' · ' +
          (r.faithfulness.degree ?? 0) +
          ' (' +
          (r.faithfulness.source || 'default') +
          ')'
        );
      }
      if (r.assistant_branding) {
        applyAssistantBranding(r.assistant_branding);
        setBrandingStatus(
          r.assistant_branding.source === 'admin' ? 'Loaded (admin)' : 'Loaded (defaults)'
        );
      }
      if (r.ui) {
        applyUiConfig(r.ui);
        setUiFeaturesStatus(r.ui_source === 'admin' ? 'Loaded (admin)' : 'Loaded (defaults)');
      } else {
        applyUiConfig(null);
        renderUiFeatures({ features: {} });
      }
    } catch (e) {
      setHostsStatus(e.message || 'Failed to load', true);
      setSimpleAgeStatus(e.message || 'Failed to load', true);
      setAnswerLengthStatus(e.message || 'Failed to load', true);
      setAdaptiveStatus(e.message || 'Failed to load', true);
      setRagWebMixStatus(e.message || 'Failed to load', true);
      setDeepPolicyStatus(e.message || 'Failed to load', true);
      setFaithfulnessStatus(e.message || 'Failed to load', true);
      setUiFeaturesStatus(e.message || 'Failed to load', true);
    }
  }

  if ($saveWebHosts) {
    $saveWebHosts.addEventListener('click', async () => {
      await refreshCsrf();
      setHostsStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({
            hosts_text: $webHosts.value,
            doc_source_url_web: selectedDocSourceUrlWeb(),
          }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        $webHosts.value = r.hosts_text || (r.allowed_hosts || []).join('\n');
        const docWeb =
          typeof r.doc_source_url_web === 'boolean'
            ? r.doc_source_url_web
            : selectedDocSourceUrlWeb();
        applyDocSourceUrlWeb(docWeb);
        updateWebHostsSummaryHint();
        setHostsStatus(
          'Saved ' +
            (r.allowed_hosts || []).length +
            ' domain(s); Source URLs ' +
            (docWeb ? 'included' : 'excluded')
        );
      } catch (e) {
        setHostsStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($webHosts) {
    $webHosts.addEventListener('input', updateWebHostsSummaryHint);
  }
  document.querySelectorAll('input[name="doc-source-url-web"]').forEach((radio) => {
    radio.addEventListener('change', updateWebHostsSummaryHint);
  });

  if ($saveSimpleAge) {
    $saveSimpleAge.addEventListener('click', async () => {
      await refreshCsrf();
      setSimpleAgeStatus('Saving…');
      try {
        const age = parseInt($simpleAge.value, 10);
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ simple_explain_age: age }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        $simpleAge.value = r.simple_explain_age;
        updateSimpleAgeSummaryHint();
        setSimpleAgeStatus('Saved: explain like a ' + r.simple_explain_age + ' year old');
      } catch (e) {
        setSimpleAgeStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($simpleAge) {
    $simpleAge.addEventListener('input', updateSimpleAgeSummaryHint);
  }

  if ($saveAnswerLength) {
    $saveAnswerLength.addEventListener('click', async () => {
      await refreshCsrf();
      setAnswerLengthStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ answer_length: selectedAnswerLength() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        applyAnswerLength(r.answer_length);
        setAnswerLengthStatus('Saved: ' + r.answer_length);
      } catch (e) {
        setAnswerLengthStatus(e.message || 'Save failed', true);
      }
    });
  }

  document.querySelectorAll('input[name="answer-length"]').forEach((radio) => {
    radio.addEventListener('change', updateAnswerLengthSummaryHint);
  });

  if ($saveAdaptive) {
    $saveAdaptive.addEventListener('click', async () => {
      await refreshCsrf();
      setAdaptiveStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ adaptive_retrieval: selectedAdaptiveRetrieval() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        applyAdaptiveRetrieval(!!r.adaptive_retrieval);
        setAdaptiveStatus('Saved: ' + (r.adaptive_retrieval ? 'On' : 'Off (legacy)'));
      } catch (e) {
        setAdaptiveStatus(e.message || 'Save failed', true);
      }
    });
  }

  document.querySelectorAll('input[name="adaptive-retrieval"]').forEach((radio) => {
    radio.addEventListener('change', updateAdaptiveSummaryHint);
  });

  document.querySelectorAll('input[name="ui-chat-history"]').forEach((radio) => {
    radio.addEventListener('change', updateUiFeaturesSummaryHint);
  });

  if ($saveRagWebMix) {
    $saveRagWebMix.addEventListener('click', async () => {
      await refreshCsrf();
      setRagWebMixStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ rag_web_mix: selectedRagWebMix() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        applyRagWebMix(r.rag_web_mix || selectedRagWebMix());
        const m = r.rag_web_mix || {};
        setRagWebMixStatus('Saved: ' + (m.mode || 'complement') + ' · degree ' + (m.degree ?? 50));
      } catch (e) {
        setRagWebMixStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($faithfulnessDegree) {
    $faithfulnessDegree.addEventListener('input', updateFaithfulnessHint);
    updateFaithfulnessHint();
  }

  if ($saveFaithfulness) {
    $saveFaithfulness.addEventListener('click', async () => {
      await refreshCsrf();
      setFaithfulnessStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ faithfulness: selectedFaithfulness() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        applyFaithfulness(r.faithfulness || selectedFaithfulness());
        const f = r.faithfulness || {};
        setFaithfulnessStatus(
          'Saved: ' + (f.band || faithfulnessBand(f.degree)) + ' · ' + (f.degree ?? 0)
        );
      } catch (e) {
        setFaithfulnessStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($saveDeepPolicy) {
    $saveDeepPolicy.addEventListener('click', async () => {
      await refreshCsrf();
      setDeepPolicyStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ deep_thinking_policy: selectedDeepPolicy() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        applyDeepPolicy(r.deep_thinking_policy || selectedDeepPolicy());
        const p = r.deep_thinking_policy || {};
        setDeepPolicyStatus(
          'Saved: ' + (p.mode || 'auto') + ' · max ' + (p.max_per_chat ?? 3) + '/chat'
        );
        if (r.deep_thinking_stats) renderDeepStats(r.deep_thinking_stats);
      } catch (e) {
        setDeepPolicyStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($resetDeepStats) {
    $resetDeepStats.addEventListener('click', async () => {
      await refreshCsrf();
      setDeepPolicyStatus('Resetting stats…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ reset_deep_thinking_stats: true }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        renderDeepStats(r.deep_thinking_stats);
        setDeepPolicyStatus('Deep stats reset');
      } catch (e) {
        setDeepPolicyStatus(e.message || 'Reset failed', true);
      }
    });
  }

  if ($saveBranding) {
    $saveBranding.addEventListener('click', async () => {
      await refreshCsrf();
      setBrandingStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ assistant_branding: collectAssistantBranding() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        if (r.assistant_branding) applyAssistantBranding(r.assistant_branding);
        setBrandingStatus('Saved assistant settings');
      } catch (e) {
        setBrandingStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($dismissOnboarding) {
    $dismissOnboarding.addEventListener('click', async () => {
      await refreshCsrf();
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({
            assistant_branding: collectAssistantBranding({ onboarding_dismissed: true }),
          }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        if ($onboardingBanner) $onboardingBanner.hidden = true;
      } catch {
        if ($onboardingBanner) $onboardingBanner.hidden = true;
      }
    });
  }

  if ($saveUiFeatures) {
    $saveUiFeatures.addEventListener('click', async () => {
      await refreshCsrf();
      setUiFeaturesStatus('Saving…');
      try {
        const r = await fetch(api('settings.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ ui: collectUiConfig() }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        if (r.ui) applyUiConfig(r.ui);
        setUiFeaturesStatus('Saved chat UI settings');
      } catch (e) {
        setUiFeaturesStatus(e.message || 'Save failed', true);
      }
    });
  }

  function setChatStatus(msg, isErr) {
    if (!$chatLimitsStatus) return;
    $chatLimitsStatus.textContent = msg || '';
    $chatLimitsStatus.classList.toggle('err', !!isErr);
    $chatLimitsStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function applyChatLimits(limits) {
    if (!limits) return;
    if ($chatMaxSession) $chatMaxSession.value = limits.max_per_session ?? 50;
    if ($chatMaxGlobal) $chatMaxGlobal.value = limits.max_global ?? 500;
    if ($chatRetention) $chatRetention.value = limits.retention_days ?? 0;
    if ($chatListLimit) $chatListLimit.value = limits.list_limit ?? 100;
  }

  function shortSid(sid) {
    const s = String(sid || '');
    if (s.length <= 12) return s;
    return s.slice(0, 8) + '…';
  }

  async function loadChats() {
    if (!$chatList) return;
    try {
      const r = await fetch(api('admin-chats.php')).then((x) => x.json());
      if (r.error) {
        setChatStatus(r.error, true);
        return;
      }
      applyChatLimits(r.limits);
      const shown = (r.conversations || []).length;
      const total = r.total || 0;
      if ($chatTotal) {
        $chatTotal.textContent =
          'Showing ' + shown + ' of ' + total + ' chat(s)' +
          (r.limits?.source === 'admin' ? ' · limits: admin' : ' · limits: config');
      }
      if ($chatSummaryHint) {
        $chatSummaryHint.textContent = total ? total + ' chat' + (total === 1 ? '' : 's') : '';
      }
      $chatList.innerHTML = '';
      const rows = r.conversations || [];
      if (!rows.length) {
        $chatList.innerHTML = '<p class="hint">No chats yet.</p>';
        return;
      }
      rows.forEach((c) => {
        const row = document.createElement('div');
        row.className = 'doc-row';
        const main = document.createElement('div');
        main.className = 'doc-main';
        const strong = document.createElement('strong');
        strong.textContent = c.title || ('Chat #' + c.id);
        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = (c.message_count || 0) + ' msgs';
        const meta = document.createElement('div');
        meta.className = 'hint mono';
        meta.textContent =
          '#' + c.id + ' · session ' + shortSid(c.session_id) +
          ' · updated ' + (c.updated_at || '');
        main.append(strong, badge, meta);

        const actions = document.createElement('div');
        actions.className = 'doc-actions';
        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.className = 'btn btn-destructive';
        btnDel.textContent = 'Delete';
        btnDel.onclick = () => deleteChat(c.id);
        actions.appendChild(btnDel);
        row.append(main, actions);
        $chatList.appendChild(row);
      });
    } catch (e) {
      setChatStatus(e.message || 'Failed to load chats', true);
    }
  }

  async function deleteChat(id) {
    if (!confirm('Delete this chat and all its messages?')) return;
    await refreshCsrf();
    const r = await fetch(api('admin-chats.php?id=' + id), {
      method: 'DELETE',
      headers: { 'X-CSRF-Token': csrf() },
    }).then((x) => x.json());
    if (r.error) {
      setChatStatus(r.error, true);
      return;
    }
    setChatStatus('Chat deleted');
    loadChats();
  }

  if ($saveChatLimits) {
    $saveChatLimits.addEventListener('click', async () => {
      await refreshCsrf();
      setChatStatus('Saving…');
      try {
        const r = await fetch(api('admin-chats.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({
            action: 'save_limits',
            max_per_session: Number($chatMaxSession.value),
            max_global: Number($chatMaxGlobal.value),
            retention_days: Number($chatRetention.value),
            list_limit: Number($chatListLimit.value),
          }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        applyChatLimits(r.limits);
        setChatStatus('Limits saved');
        loadChats();
      } catch (e) {
        setChatStatus(e.message || 'Save failed', true);
      }
    });
  }

  if ($pruneChats) {
    $pruneChats.addEventListener('click', async () => {
      if (!confirm('Prune chats by retention and global cap now?')) return;
      await refreshCsrf();
      setChatStatus('Pruning…');
      try {
        const r = await fetch(api('admin-chats.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ action: 'prune' }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        setChatStatus('Pruned ' + (r.deleted || 0) + ' chat(s)');
        loadChats();
      } catch (e) {
        setChatStatus(e.message || 'Prune failed', true);
      }
    });
  }

  if ($deleteAllChats) {
    $deleteAllChats.addEventListener('click', async () => {
      if (!confirm('Delete ALL chats for every session? This cannot be undone.')) return;
      if (!confirm('Really delete every conversation?')) return;
      await refreshCsrf();
      setChatStatus('Deleting…');
      try {
        const r = await fetch(api('admin-chats.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf(),
          },
          body: JSON.stringify({ action: 'delete_all' }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        setChatStatus('Deleted ' + (r.deleted || 0) + ' chat(s)');
        loadChats();
      } catch (e) {
        setChatStatus(e.message || 'Delete failed', true);
      }
    });
  }

  function setExportStatus(msg, isErr) {
    if (!$exportStatus) return;
    $exportStatus.textContent = msg || '';
    $exportStatus.classList.toggle('err', !!isErr);
    $exportStatus.classList.toggle('ok', !!msg && !isErr);
  }

  async function downloadExport(type, label) {
    setExportStatus('Preparing ' + label + '…');
    try {
      const r = await fetch(api('export.php?type=' + encodeURIComponent(type)), {
        credentials: 'same-origin',
      });
      const cd = r.headers.get('Content-Disposition') || '';
      const isAttachment = /attachment/i.test(cd);
      if (!r.ok || !isAttachment) {
        let err = 'Export failed (' + r.status + ')';
        try {
          const j = await r.json();
          if (j && j.error) err = j.error;
        } catch (_) { /* ignore */ }
        throw new Error(err);
      }
      let filename = 'wanda-' + type + '.json';
      const m = /filename="?([^";]+)"?/i.exec(cd);
      if (m && m[1]) filename = m[1].trim();
      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      setExportStatus('Downloaded ' + filename);
    } catch (e) {
      setExportStatus(e.message || 'Export failed', true);
    }
  }

  if ($exportSettings) {
    $exportSettings.addEventListener('click', () => downloadExport('settings', 'settings'));
  }
  if ($exportChunks) {
    $exportChunks.addEventListener('click', () => {
      if (!confirm('Export all documents and chunks? Large libraries may take a moment.')) return;
      downloadExport('chunks', 'chunks');
    });
  }

  async function postSettingsImport(data, confirmLabel) {
    if (!data || typeof data !== 'object' || Array.isArray(data)) {
      throw new Error('Settings payload must be a JSON object');
    }
    if (data.export === 'chunks') {
      throw new Error('That file is a chunks export — pick a settings export');
    }
    if (!confirm(confirmLabel)) {
      setExportStatus('');
      return false;
    }
    await refreshCsrf();
    setExportStatus('Importing…');
    const r = await fetch(api('export.php'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ type: 'settings', data }),
    }).then((x) => x.json());
    if (r.error) throw new Error(r.error);
    const bits = (r.updated || []).join(', ') || 'none';
    let msg = 'Imported: ' + bits;
    if ((r.skipped || []).length) msg += ' · skipped ' + r.skipped.join(', ');
    setExportStatus(msg);
    if (r.effective) {
      applySettingsPayload(r.effective);
    }
    await loadAssistantBranding();
    await loadWebHosts();
    await loadChats();
    return true;
  }

  function applySettingsPayload(r) {
    if (!r || typeof r !== 'object') return;
    if (r.assistant_branding) {
      applyAssistantBranding(r.assistant_branding);
      setBrandingStatus('Updated from preset or import');
    }
    if (typeof r.doc_source_url_web === 'boolean') {
      applyDocSourceUrlWeb(r.doc_source_url_web);
    }
    if (r.answer_length) applyAnswerLength(r.answer_length);
    if (typeof r.adaptive_retrieval === 'boolean') applyAdaptiveRetrieval(r.adaptive_retrieval);
    if (r.rag_web_mix) applyRagWebMix(r.rag_web_mix);
    if (r.deep_thinking_policy) applyDeepPolicy(r.deep_thinking_policy);
    if (r.faithfulness) applyFaithfulness(r.faithfulness);
    if (r.ui) {
      applyUiConfig(r.ui);
      updateUiFeaturesSummaryHint();
    }
  }

  async function importSettingsFromFile(file) {
    if (!file) return;
    setExportStatus('Reading ' + file.name + '…');
    try {
      const text = await file.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (_) {
        throw new Error('File is not valid JSON');
      }
      await postSettingsImport(data, 'Import settings from “' + file.name + '”? This overwrites current admin settings.');
    } catch (e) {
      setExportStatus(e.message || 'Import failed', true);
    } finally {
      if ($importSettingsFile) $importSettingsFile.value = '';
    }
  }

  async function loadPresetsList() {
    if (!$applyPresetSelect) return;
    try {
      const r = await fetch(api('presets.php'), { credentials: 'same-origin' }).then((x) => x.json());
      if (r.error) throw new Error(r.error);
      const keep = $applyPresetSelect.value;
      $applyPresetSelect.innerHTML = '<option value="">— Choose a preset —</option>';
      (r.presets || []).forEach((p) => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.label || p.id;
        $applyPresetSelect.appendChild(opt);
      });
      if (keep) $applyPresetSelect.value = keep;
    } catch (_) {
      /* non-fatal */
    }
  }

  async function applyBusinessPreset() {
    if (!$applyPresetSelect || !$applyPreset) return;
    const id = ($applyPresetSelect.value || '').trim();
    if (!id) {
      setExportStatus('Choose a preset first', true);
      return;
    }
    const label = $applyPresetSelect.selectedOptions[0]
      ? $applyPresetSelect.selectedOptions[0].textContent
      : id;
    try {
      setExportStatus('Loading preset…');
      const r = await fetch(api('presets.php?id=' + encodeURIComponent(id)), {
        credentials: 'same-origin',
      }).then((x) => x.json());
      if (r.error) throw new Error(r.error);
      const preset = r.preset;
      const effective =
        preset && typeof preset.effective === 'object' && preset.effective !== null
          ? preset.effective
          : preset;
      await postSettingsImport(
        { effective },
        'Apply preset “' + label + '”? This overwrites matching admin settings (not your document library).'
      );
    } catch (e) {
      setExportStatus(e.message || 'Preset failed', true);
    }
  }

  if ($importSettings && $importSettingsFile) {
    $importSettings.addEventListener('click', () => $importSettingsFile.click());
    $importSettingsFile.addEventListener('change', () => {
      const f = $importSettingsFile.files && $importSettingsFile.files[0];
      importSettingsFromFile(f);
    });
  }

  if ($applyPreset) {
    $applyPreset.addEventListener('click', () => applyBusinessPreset());
  }

  loadChats();
  loadAssistantBranding();
  loadWebHosts();
  loadDocuments();
  loadPresetsList();
})();
