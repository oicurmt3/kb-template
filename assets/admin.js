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
  const $simpleAge = document.getElementById('simple-explain-age');
  const $saveSimpleAge = document.getElementById('save-simple-age');
  const $simpleAgeStatus = document.getElementById('simple-age-status');
  const $saveAnswerLength = document.getElementById('save-answer-length');
  const $answerLengthStatus = document.getElementById('answer-length-status');
  const $saveAdaptive = document.getElementById('save-adaptive-retrieval');
  const $adaptiveStatus = document.getElementById('adaptive-retrieval-status');
  const $ragWebDegree = document.getElementById('rag-web-degree');
  const $ragWebDegreeVal = document.getElementById('rag-web-degree-val');
  const $saveRagWebMix = document.getElementById('save-rag-web-mix');
  const $ragWebMixStatus = document.getElementById('rag-web-mix-status');
  const $ragWebMixHint = document.getElementById('rag-web-mix-hint');
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

  const UI_FEATURE_LABELS = {
    workcover: 'WorkCover (docs + live allowlisted web)',
    hq: 'HQ language (off = Basic; style only)',
    deep_thinking: 'Deep thinking toggle (policy may auto-on)',
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
    if (!$status) return;
    $status.textContent = msg || '';
    $status.classList.toggle('err', !!isErr);
    $status.classList.toggle('ok', !!msg && !isErr);
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
    const r = await fetch(api('documents.php')).then((x) => x.json());
    $docList.innerHTML = '';
    if (r.error) {
      $docList.textContent = r.error;
      if ($docSummaryHint) $docSummaryHint.textContent = '';
      return;
    }
    const docs = r.documents || [];
    if ($docSummaryHint) {
      $docSummaryHint.textContent = docs.length
        ? docs.length + ' doc' + (docs.length === 1 ? '' : 's')
        : '';
    }
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
      urlInput.placeholder = 'Source URL (https://…) — also live web when retrieved';
      urlInput.value = d.source_url || '';
      urlInput.title = 'Citation link and live allowlisted fetch when this document is retrieved in chat';
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
    const d = Number.isFinite(degree) ? degree : 70;
    if (d <= 0) return 'off';
    if (d <= 35) return 'light';
    if (d <= 70) return 'balanced';
    return 'strict';
  }

  function faithfulnessLaymanHint(degree) {
    const d = Number.isFinite(degree) ? degree : 70;
    const band = faithfulnessBand(d);
    if (band === 'off') {
      return 'Off: no post-stream source check. The streamed draft is kept as-is (faster, more risk of invented sections).';
    }
    if (band === 'light') {
      return (
        'Light (degree ' +
        d +
        '): prefers OK; rewrites only clear inventions. No orphan section/reg strip yet. Good if faithfulness was over-trimming.'
      );
    }
    if (band === 'balanced') {
      return (
        'Balanced (degree ' +
        d +
        '): OK only when legal claims look sourced' +
        (d >= 40 ? '; orphan section/reg scan on' : '') +
        (d >= 60 ? '; local strip fallback on' : '') +
        '. Default-ish everyday brake.'
      );
    }
    return (
      'Strict (degree ' +
      d +
      '): when unsure, rewrite; orphan scan + strip; allows deeper cuts of unsupported text. Best when hallucinations are the main problem.'
    );
  }

  function updateFaithfulnessHint() {
    const degree = $faithfulnessDegree ? parseInt($faithfulnessDegree.value, 10) : 70;
    const d = Number.isFinite(degree) ? degree : 70;
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
    if (!Number.isFinite(degree)) degree = 70;
    degree = Math.max(0, Math.min(100, degree));
    if ($faithfulnessDegree) $faithfulnessDegree.value = String(degree);
    updateFaithfulnessHint();
  }

  function selectedFaithfulness() {
    let degree = $faithfulnessDegree ? parseInt($faithfulnessDegree.value, 10) : 70;
    if (!Number.isFinite(degree)) degree = 70;
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
      'Best everyday setting for WorkCover Docs mode.'
    );
  }

  function updateRagWebMixHint() {
    if (!$ragWebMixHint) return;
    const mix = selectedRagWebMix();
    $ragWebMixHint.textContent = ragWebMixLaymanHint(mix.mode, mix.degree);
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
    if (
      !$webHosts &&
      !$simpleAge &&
      !$saveAnswerLength &&
      !$saveAdaptive &&
      !$saveDeepPolicy &&
      !$saveFaithfulness &&
      !$saveUiFeatures
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
          (r.faithfulness.degree ?? 70) +
          ' (' +
          (r.faithfulness.source || 'default') +
          ')'
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
          'Saved: ' + (f.band || faithfulnessBand(f.degree)) + ' · ' + (f.degree ?? 70)
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
      if (!data || typeof data !== 'object' || Array.isArray(data)) {
        throw new Error('Settings file must be a JSON object');
      }
      if (data.export === 'chunks') {
        throw new Error('That file is a chunks export — pick a settings export');
      }
      if (!confirm('Import settings from “' + file.name + '”? This overwrites current admin settings.')) {
        setExportStatus('');
        return;
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
      await loadWebHosts();
      await loadChats();
    } catch (e) {
      setExportStatus(e.message || 'Import failed', true);
    } finally {
      if ($importSettingsFile) $importSettingsFile.value = '';
    }
  }

  if ($importSettings && $importSettingsFile) {
    $importSettings.addEventListener('click', () => $importSettingsFile.click());
    $importSettingsFile.addEventListener('change', () => {
      const f = $importSettingsFile.files && $importSettingsFile.files[0];
      importSettingsFromFile(f);
    });
  }

  loadChats();
  loadWebHosts();
  loadDocuments();
})();
