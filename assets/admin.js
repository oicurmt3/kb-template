(() => {
  const base = (typeof window.APP_BASE === 'string' ? window.APP_BASE : '').replace(/\/$/, '');
  const api = (path) => base + '/api/' + path.replace(/^\//, '');
  const csrf = () => window.CSRF_TOKEN || '';

  const $drop = document.getElementById('dropzone');
  const $file = document.getElementById('file-input');
  const $status = document.getElementById('upload-status');
  const $docList = document.getElementById('doc-list');
  const $chunksPanel = document.getElementById('chunks-panel');
  const $chunksList = document.getElementById('chunks-list');
  const $chunksTitle = document.getElementById('chunks-doc-title');
  const $retrievalForm = document.getElementById('retrieval-form');
  const $retrievalQuery = document.getElementById('retrieval-query');
  const $retrievalResults = document.getElementById('retrieval-results');
  const $webHosts = document.getElementById('web-hosts');
  const $saveWebHosts = document.getElementById('save-web-hosts');
  const $webHostsStatus = document.getElementById('web-hosts-status');
  const $simpleAge = document.getElementById('simple-explain-age');
  const $saveSimpleAge = document.getElementById('save-simple-age');
  const $simpleAgeStatus = document.getElementById('simple-age-status');
  const $saveAnswerLength = document.getElementById('save-answer-length');
  const $answerLengthStatus = document.getElementById('answer-length-status');
  const $saveAdaptive = document.getElementById('save-adaptive-retrieval');
  const $adaptiveStatus = document.getElementById('adaptive-retrieval-status');
  const $uiIntro = document.getElementById('ui-intro');
  const $uiFeaturesList = document.getElementById('ui-features-list');
  const $saveUiFeatures = document.getElementById('save-ui-features');
  const $uiFeaturesStatus = document.getElementById('ui-features-status');

  const UI_FEATURE_LABELS = {
    workcover: 'WorkCover (docs + live allowlisted web)',
    hq: 'HQ mode (off = Basic)',
    deep_thinking: 'Deep thinking',
    web_search: 'Web only (when WorkCover off)',
    tags: 'Focus tags',
    url_context: 'URL context',
    file_context: 'File attach context',
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
    $status.textContent = msg || '';
    $status.classList.toggle('err', !!isErr);
    $status.classList.toggle('ok', !!msg && !isErr);
  }

  async function loadDocuments() {
    const r = await fetch(api('documents.php')).then((x) => x.json());
    $docList.innerHTML = '';
    if (r.error) {
      $docList.textContent = r.error;
      return;
    }
    const docs = r.documents || [];
    if (!docs.length) {
      $docList.innerHTML = '<p class="hint">No documents yet.</p>';
      return;
    }
    docs.forEach((d) => {
      const row = document.createElement('div');
      row.className = 'doc-row doc-row-tags';
      row.setAttribute('data-doc-id', String(d.id));
      row.setAttribute('data-doc-title', d.title || d.filename || 'Document');

      const main = document.createElement('div');
      main.className = 'doc-main';
      const head = document.createElement('div');
      const strong = document.createElement('strong');
      strong.textContent = d.title || d.filename;
      const badge = document.createElement('span');
      badge.className = 'badge';
      const modeLabel =
        d.ingest_mode === 'deepseek' ? 'Files parse' :
        d.ingest_mode === 'ocr' ? 'OCR' : 'local';
      badge.textContent = d.status + ' · ' + modeLabel + ' · ' + (d.chunk_count || 0) + ' chunks';
      badge.classList.add(d.status === 'ready' ? 'ok' : d.status === 'failed' ? 'err' : '');
      head.append(strong, document.createTextNode(' '), badge);

      const mono = document.createElement('div');
      mono.className = 'hint mono';
      let monoText = d.filename;
      if (d.department) monoText += ' · ' + d.department;
      if (d.deepseek_file_id) monoText += ' · ' + d.deepseek_file_id;
      if (d.error_message) monoText += ' — ' + d.error_message;
      mono.textContent = monoText;

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
      tagInput.setAttribute('aria-label', 'Tags for ' + (d.title || d.filename));

      const urlInput = document.createElement('input');
      urlInput.type = 'url';
      urlInput.className = 'tag-input source-url-input';
      urlInput.placeholder = 'Source URL (https://…)';
      urlInput.value = d.source_url || '';
      urlInput.setAttribute('aria-label', 'Source URL for ' + (d.title || d.filename));

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
        a.href = d.source_url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = d.source_url;
        urlHint.append(document.createTextNode('Link: '), a);
        tagsWrap.append(chips, urlHint, tagEdit);
      } else {
        tagsWrap.append(chips, tagEdit);
      }
      main.append(head, mono, tagsWrap);

      const actions = document.createElement('div');
      actions.className = 'doc-actions';
      const btnChunks = document.createElement('button');
      btnChunks.className = 'btn btn-secondary';
      btnChunks.type = 'button';
      btnChunks.textContent = d.ingest_mode === 'deepseek' ? 'Stub' : 'Chunks';
      btnChunks.onclick = () => openChunks(d.id, d.title || d.filename);

      const btnRe = document.createElement('button');
      btnRe.className = 'btn btn-ghost';
      btnRe.type = 'button';
      btnRe.textContent =
        d.ingest_mode === 'deepseek' ? 'Re-parse' :
        d.ingest_mode === 'ocr' ? 'Re-OCR' : 'Re-chunk';
      btnRe.onclick = () => rechunk(d.id);

      const btnDel = document.createElement('button');
      btnDel.className = 'btn btn-destructive';
      btnDel.type = 'button';
      btnDel.textContent = 'Delete';
      btnDel.onclick = () => deleteDoc(d.id);

      actions.append(btnChunks, btnRe, btnDel);
      row.append(main, actions);
      $docList.appendChild(row);
    });
  }

  async function saveDocMeta(id, tagsText, sourceUrl) {
    await refreshCsrf();
    setStatus('Saving…');
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
      setStatus('Saved (' + n + ' tag' + (n === 1 ? '' : 's') + (hasUrl ? ', URL set' : '') + ')');
      loadDocuments();
    } catch (e) {
      setStatus(e.message || 'Save failed', true);
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
    if (!files.length) return;
    await refreshCsrf();
    let lastDoc = null;
    for (const file of files) {
      setStatus('Uploading ' + file.name + '…');
      try {
        lastDoc = await uploadOne(file);
        setStatus('Uploaded ' + file.name + ' (' + (lastDoc.chunk_count || 0) + ' chunks)');
      } catch (e) {
        setStatus(file.name + ': ' + e.message, true);
      }
    }
    await loadDocuments();
    if (lastDoc && lastDoc.id) {
      highlightDoc(lastDoc.id);
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
    setStatus('Re-chunking…');
    const r = await fetch(api('documents.php'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf(),
      },
      body: JSON.stringify({ action: 'rechunk', id }),
    }).then((x) => x.json());
    if (r.error) {
      setStatus(r.error, true);
      return;
    }
    setStatus('Re-chunked (' + (r.result?.chunk_count || 0) + ' chunks)');
    loadDocuments();
  }

  async function openChunks(docId, title) {
    $chunksPanel.hidden = false;
    $chunksTitle.textContent = '— ' + title;
    $chunksList.innerHTML = '<p class="hint">Loading…</p>';
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
      uploadFiles($file.files);
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
      uploadFiles(e.dataTransfer?.files);
    });
  }

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

  function setSimpleAgeStatus(msg, isErr) {
    if (!$simpleAgeStatus) return;
    $simpleAgeStatus.textContent = msg || '';
    $simpleAgeStatus.classList.toggle('err', !!isErr);
    $simpleAgeStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function setAnswerLengthStatus(msg, isErr) {
    if (!$answerLengthStatus) return;
    $answerLengthStatus.textContent = msg || '';
    $answerLengthStatus.classList.toggle('err', !!isErr);
    $answerLengthStatus.classList.toggle('ok', !!msg && !isErr);
  }

  function applyAnswerLength(len) {
    const v = ['short', 'medium', 'long'].includes(len) ? len : 'medium';
    const el = document.getElementById('answer-length-' + v);
    if (el) el.checked = true;
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

  function applyAdaptiveRetrieval(on) {
    const el = document.getElementById(on ? 'adaptive-retrieval-on' : 'adaptive-retrieval-off');
    if (el) el.checked = true;
  }

  function selectedAdaptiveRetrieval() {
    const el = document.querySelector('input[name="adaptive-retrieval"]:checked');
    return el ? el.value === '1' : false;
  }

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

      const lab = document.createElement('span');
      lab.className = 'ui-feature-label';
      lab.textContent = UI_FEATURE_LABELS[key];

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

      if (UI_VISIBILITY_ONLY[key]) {
        row.append(lab, vis);
      } else {
        const defLabel = document.createElement('label');
        defLabel.className = 'mode-toggle';
        const defCb = document.createElement('input');
        defCb.type = 'checkbox';
        defCb.className = 'ui-feat-default';
        defCb.checked = !!f.default;
        defLabel.append(defCb, document.createTextNode(' Default on'));
        row.append(lab, defLabel, vis);
      }
      $uiFeaturesList.appendChild(row);
    });
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

  function applyUiConfig(ui) {
    if (!ui) return;
    if ($uiIntro && typeof ui.intro === 'string') $uiIntro.value = ui.intro;
    const on = document.getElementById(ui.chat_history === false ? 'ui-chat-history-off' : 'ui-chat-history-on');
    if (on) on.checked = true;
    renderUiFeatures(ui);
  }

  async function loadWebHosts() {
    if (!$webHosts && !$simpleAge && !$saveAnswerLength && !$saveAdaptive && !$saveUiFeatures) return;
    try {
      const r = await fetch(api('settings.php')).then((x) => x.json());
      if (r.error) {
        setHostsStatus(r.error, true);
        setSimpleAgeStatus(r.error, true);
        setAnswerLengthStatus(r.error, true);
        setAdaptiveStatus(r.error, true);
        setUiFeaturesStatus(r.error, true);
        return;
      }
      if ($webHosts) {
        $webHosts.value = r.hosts_text || (r.allowed_hosts || []).join('\n');
        setHostsStatus(r.source === 'admin' ? 'Loaded (admin override)' : 'Loaded (config default)');
      }
      if ($simpleAge && r.simple_explain_age != null) {
        $simpleAge.value = r.simple_explain_age;
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
          body: JSON.stringify({ hosts_text: $webHosts.value }),
        }).then((x) => x.json());
        if (r.error) throw new Error(r.error);
        $webHosts.value = r.hosts_text || (r.allowed_hosts || []).join('\n');
        setHostsStatus('Saved ' + (r.allowed_hosts || []).length + ' domain(s)');
      } catch (e) {
        setHostsStatus(e.message || 'Save failed', true);
      }
    });
  }

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
        setSimpleAgeStatus('Saved: explain like a ' + r.simple_explain_age + ' year old');
      } catch (e) {
        setSimpleAgeStatus(e.message || 'Save failed', true);
      }
    });
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

  loadChats();
  loadWebHosts();
  loadDocuments();
})();
