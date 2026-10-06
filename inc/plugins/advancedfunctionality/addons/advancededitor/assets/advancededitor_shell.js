(function (window, document) {
  'use strict';
  if (window.afAdvancedEditorShell) return;
  var P = window.afAePayload || window.afAdvancedEditorPayload || {};
  var registry = Object.assign(Object.create(null), P.capabilities || {}, window.afAeCapabilities || {});
  var buttons = Object.create(null), states = Object.create(null), assets = Object.create(null);
  (P.available || []).concat(window.afAeButtons || []).forEach(function (b) { buttons[b.cmd] = b; });

  Object.keys(registry).forEach(function(id) { states[id] = { state: 'unloaded', promise: null }; });

  function loadAsset(url, type) {
    var resolved = new URL(url, document.baseURI);
    if (P.assetVer && !resolved.searchParams.has('v')) resolved.searchParams.set('v', String(P.assetVer));
    var absolute = resolved.href;
    var key = type + ':' + absolute;
    if (assets[key]) return assets[key];
    var found = Array.prototype.some.call(document.querySelectorAll(type === 'js' ? 'script[src]' : 'link[rel="stylesheet"]'), function (el) {
      return (type === 'js' ? el.src : el.href) === absolute && !el.hasAttribute('data-af-loading');
    });
    if (found) return (assets[key] = Promise.resolve());
    assets[key] = new Promise(function (resolve, reject) {
      var el = document.createElement(type === 'js' ? 'script' : 'link');
      var timer;
      el.setAttribute('data-af-loading', '1');
      if (type === 'js') { el.src = absolute; el.async = false; }
      else { el.rel = 'stylesheet'; el.href = absolute; }
      function finish(error) {
        clearTimeout(timer);
        el.onload = el.onerror = null;
        el.removeAttribute('data-af-loading');
        if (error) { el.remove(); delete assets[key]; reject(error); }
        else resolve();
      }
      el.onload = function () { finish(); };
      el.onerror = function () { finish(new Error('Asset failed: ' + absolute)); };
      timer = setTimeout(function () { finish(new Error('Asset timed out: ' + absolute)); }, 20000);
      document.head.appendChild(el);
    });
    return assets[key];
  }

  function validateDependencies(id, path) {
    if (path.indexOf(id) !== -1) throw new Error('Capability dependency cycle: ' + id);
    if (!registry[id]) throw new Error('Unknown capability: ' + id);
    (registry[id].requires || []).forEach(function (dependency) { validateDependencies(dependency, path.concat(id)); });
  }

  function loadCapability(id, path) {
    path = path || [];
    if (path.indexOf(id) !== -1) return Promise.reject(new Error('Capability dependency cycle: ' + id));
    var definition = registry[id];
    if (!definition) return Promise.reject(new Error('Unknown capability: ' + id));
    var state = states[id] || (states[id] = { state: 'unloaded', promise: null });
    if (state.state === 'loading' || state.state === 'loaded') return state.promise;
    state.state = 'loading';
    state.promise = Promise.resolve().then(function () {
      validateDependencies(id, []);
      return Promise.all((definition.requires || []).map(function (dependency) { return loadCapability(dependency, path.concat(id)); }));
    }).then(function () {
      return Promise.all((definition.css || []).map(function (url) { return loadAsset(url, 'css'); }));
    }).then(function () {
      return (definition.js || []).reduce(function (chain, url) {
        return chain.then(function () { return loadAsset(url, 'js'); });
      }, Promise.resolve());
    }).then(function () {
      state.state = 'loaded';
      document.dispatchEvent(new CustomEvent('af:capability-ready', { detail: { capability: id } }));
    }).catch(function (error) { state.state = 'failed'; throw error; });
    return state.promise;
  }

  function insert(ta, open, close) {
    open = String(open || ''); close = String(close || '');
    var start = ta.selectionStart, end = ta.selectionEnd, scroll = ta.scrollTop;
    var selection = ta.value.slice(start, end);
    ta.setRangeText(open + selection + close, start, end, 'preserve');
    ta.focus();
    ta.setSelectionRange(start + open.length, start + open.length + selection.length);
    ta.scrollTop = scroll;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }


  // Drafts are a safety feature of the initial textarea, not an optional
  // toolbar runtime. No SCEditor, timers or network requests are required.
  var draftPrefix = 'af_ae_draft_v2:';
  function draftKey(ta) {
    if (!ta || ta.name !== 'message' || !ta.form) return '';
    var form = ta.form;
    var findId = function (name) {
      var el = form.querySelector('input[name="' + name + '"]');
      return el && /^\d+$/.test(el.value) ? el.value : '';
    };
    var query = new URLSearchParams(window.location.search);
    var tid = findId('tid') || ( /^\d+$/.test(query.get('tid') || '') ? query.get('tid') : '');
    var fid = findId('fid') || ( /^\d+$/.test(query.get('fid') || '') ? query.get('fid') : '');
    var uid = (window.MyBBSettings && window.MyBBSettings.uid) || 'guest';
    var page = location.pathname.split('/').pop();
    var context = tid ? 'thread:' + tid : fid ? 'forum:' + fid + ':' + page : 'page:' + location.pathname + location.search;
    return draftPrefix + String(uid) + ':' + context;
  }
  function editorBbcode(ta) {
    try {
      var ed = currentEditor(ta);
      if (ed && !ed.__afAeSourceAdapter && typeof ed.val === 'function') return String(ed.val() || '');
    } catch (e) {}
    return String(ta.value || '');
  }
  function setupDraft(ta, signal) {
    var key = draftKey(ta);
    if (!key) return;
    var form = ta.form;
    var pendingKey = key + ':pending';
    var pending = '';
    try { pending = sessionStorage.getItem(pendingKey) || ''; } catch (e) {}
    var last = '';
    try {
      // Only a confirmed post-redirect identifies successful submission.
      var query = new URLSearchParams(location.search);
      var anchor = location.hash;
      var posted = /(?:^|\/)showthread\.php$/.test(location.pathname)
        && (/^(?:#pid_?\d+|#post\d+)$/i.test(anchor)
          || /^\d+$/.test(query.get('pid') || ''));
      var submittedFrom = '';
      try { submittedFrom = JSON.parse(pending).from || ''; } catch (e) {}
      if (pending && posted && submittedFrom && submittedFrom !== location.href
          && !ta.value.trim() && !document.querySelector('.error, .error_message, .alert--error')) {
        localStorage.removeItem(key);
        sessionStorage.removeItem(pendingKey);
      } else {
        var saved = localStorage.getItem(key);
        if (saved && !ta.value.trim()) {
          ta.value = saved;
          ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }
      last = editorBbcode(ta);
    } catch (e) { last = editorBbcode(ta); }
    function save() {
      var value = editorBbcode(ta);
      if (value === last) return;
      last = value;
      try {
        if (value) localStorage.setItem(key, value);
        else if (!pending) localStorage.removeItem(key);
      } catch (e) {}
    }
    function flush() {
      try { if (ta.isConnected) save(); } catch (e) {}
    }
    // A successful reply must clear both storage and the *live* editing
    // surface. Native redirects are handled on the next page; AJAX replies
    // are confirmed by a new post entering MyBB's actual #posts container.
    function finishPublished() {
      try { localStorage.removeItem(key); sessionStorage.removeItem(pendingKey); } catch (e) {}
      pending = '';
      last = '';
      try {
        var ed = currentEditor(ta);
        if (ed && !ed.__afAeSourceAdapter && typeof ed.val === 'function') {
          ed.val('');
          if (typeof ed.updateOriginal === 'function') ed.updateOriginal();
        }
        ta.value = '';
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      } catch (e) { ta.value = ''; }
      last = '';
      if (ta.__afAeCountUpdate) ta.__afAeCountUpdate();
    }
    var posts = document.querySelector('#posts');
    var knownPostIds = Object.create(null);
    function recordPostIds() {
      if (!posts) return;
      Array.prototype.forEach.call(posts.querySelectorAll('[id^="pid"], [id^="post_"]'), function (node) {
        if (/^(?:pid_?\d+|post_\d+)$/.test(node.id)) knownPostIds[node.id] = true;
      });
    }
    recordPostIds();
    var waitingForPost = false;
    var submitStartedAt = 0;
    function markSubmission() {
      flush();
      recordPostIds();
      waitingForPost = true;
      submitStartedAt = Date.now();
      pending = '1';
      try {
        sessionStorage.setItem(pendingKey, JSON.stringify({
          from: location.href, at: submitStartedAt
        }));
      } catch (e) {}
    }
    if (posts && typeof MutationObserver !== 'undefined') {
      var postObserver = new MutationObserver(function (records) {
        if (!waitingForPost || Date.now() - submitStartedAt > 120000) return;
        for (var i = 0; i < records.length; i++) {
          var nodes = records[i].addedNodes;
          for (var j = 0; j < nodes.length; j++) {
            var node = nodes[j];
            if (node.nodeType !== 1) continue;
            var candidates = [];
            if (node.id && /^(?:pid_?\d+|post_\d+)$/.test(node.id)) candidates.push(node);
            if (node.querySelectorAll) {
              Array.prototype.push.apply(candidates, node.querySelectorAll('[id^="pid"], [id^="post_"]'));
            }
            for (var k = 0; k < candidates.length; k++) {
              var candidate = candidates[k];
              if (!/^(?:pid_?\d+|post_\d+)$/.test(candidate.id) || knownPostIds[candidate.id]) continue;
              recordPostIds();
              waitingForPost = false;
              finishPublished();
              return;
            }
          }
        }
      });
      postObserver.observe(posts, { childList: true, subtree: true });
      signal.addEventListener('abort', function () { postObserver.disconnect(); }, { once: true });
    }
    ta.addEventListener('input', flush, { signal: signal });
    ta.addEventListener('change', flush, { signal: signal });
    window.addEventListener('pagehide', flush, { signal: signal });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') flush();
    }, { signal: signal });
    // MyBB quick reply is often AJAX. Its click handler can prevent a native
    // submit event, so capture the *send* action as well as normal submits.
    form.addEventListener('click', function (event) {
      var control = event.target.closest('button, input[type="submit"]');
      if (!control || control.form !== form || control.name === 'previewpost') return;
      if (control.id === 'quick_reply_submit' || control.type === 'submit') {
        markSubmission();
      }
    }, { capture: true, signal: signal });
    form.addEventListener('submit', function (event) {
      if (event.submitter && event.submitter.name === 'previewpost') return;
      markSubmission();
    }, { signal: signal });
    // SCEditor updates an iframe instead of the original textarea. Bind after
    // its real instance exists, and preserve the same localStorage entry.
    document.addEventListener('af:editor-ready', function (event) {
      if (!event.detail || event.detail.textarea !== ta) return;
      var ed = event.detail.instance;
      if (!ed || ed.__afAeSourceAdapter || typeof ed.bind !== 'function') return;
      if (ed.__afAeDraftBound) return;
      ed.__afAeDraftBound = true;
      ed.bind('valuechanged', flush);
      try {
        var body = ed.getBody && ed.getBody();
        if (body) body.addEventListener('input', flush, { signal: signal });
        var source = ed.getSourceEditor && ed.getSourceEditor();
        if (source && source.jquery) source = source[0];
        if (source && source.addEventListener) source.addEventListener('input', flush, { signal: signal });
      } catch (e) {}
    }, { signal: signal });
    // A confirmed native/AJAX success may notify listeners explicitly.
    document.addEventListener('af:post-published', function (event) {
      var detail = event.detail || {};
      if (detail.form && detail.form !== form) return;
      waitingForPost = false;
      finishPublished();
    }, { signal: signal });
  }

  // Existing pack dialogs use this source-mode API without requiring SCEditor.
  function adapter(ta, wrapper) {
    var popup = null;
    var api = {
      textarea: ta, sourceEditor: ta, __afAeSourceAdapter: true,
      inSourceMode: function () { return true; }, sourceMode: function () { return true; },
      val: function (value) { if (arguments.length) { ta.value = value; ta.dispatchEvent(new Event('input', { bubbles: true })); } return ta.value; },
      insert: function (open, close) { insert(ta, open, close); },
      insertText: function (open, close) { insert(ta, open, close); },
      focus: function () { ta.focus(); }, updateOriginal: function () {},
      getContainer: function () { return wrapper; }, getBody: function () { return null; },
      getRangeHelper: function () { return { selectedRange: function () { return null; } }; },
      getSourceEditorValue: function () { return ta.value; },
      getSourceEditor: function () { return ta; },
      bind: function () {},
      closeDropDown: function () { if (popup) popup.remove(); popup = null; },
      createDropDown: function (caller, name, content) {
        api.closeDropDown();
        popup = document.createElement('div');
        popup.className = 'sceditor-dropdown ' + name;
        popup.id = 'sceditor-' + name;
        popup.appendChild(content.jquery ? content[0] : content);
        document.body.appendChild(popup);
        var bounds = (caller || wrapper).getBoundingClientRect();
        popup.style.position = 'fixed'; popup.style.zIndex = '2147483000';
        popup.style.maxHeight = 'min(60vh, 420px)';
        popup.style.overflowY = 'auto';
        popup.style.left = Math.max(8, Math.min(bounds.left, window.innerWidth - popup.offsetWidth - 8)) + 'px';
        popup.style.top = Math.max(8, bounds.bottom + popup.offsetHeight + 8 > window.innerHeight
          ? bounds.top - popup.offsetHeight - 4 : bounds.bottom + 4) + 'px';
      },
      destroy: function () { api.closeDropDown(); }
    };
    return api;
  }

  function currentEditor(ta) {
    try { if (window.jQuery && window.jQuery.fn.sceditor) return window.jQuery(ta).sceditor('instance') || ta.__afAeAdapter; } catch (e) {}
    return ta.__afAeAdapter;
  }

  function report(wrapper, b, error) {
    var notice = wrapper.querySelector('.af-ae-shell-error');
    if (!notice) { notice = document.createElement('div'); notice.className = 'af-ae-shell-error'; notice.setAttribute('role', 'status'); wrapper.appendChild(notice); }
    notice.textContent = (b.title || b.cmd) + ': не удалось загрузить. Нажмите кнопку ещё раз для повтора.';
    console.warn('[AdvancedEditor]', error);
  }

  function runHandler(editor, b, caller) {
    var fn = window['af_ae_' + b.handler + '_exec'];
    if (typeof fn === 'function') return fn(editor, b, caller);
    var handlers = window.afAeBuiltinHandlers || {};
    fn = handlers[b.handler] || handlers[b.cmd];
    if (typeof fn === 'function') return fn(editor, caller);
    throw new Error('Handler did not register: ' + (b.handler || b.cmd));
  }

  function requiresCapability(id, dependency, seen) {
    if (id === dependency) return true;
    seen = seen || [];
    if (seen.indexOf(id) !== -1 || !registry[id]) return false;
    return (registry[id].requires || []).some(function (child) { return requiresCapability(child, dependency, seen.concat(id)); });
  }

  // Keep the shell's single visible editing surface in sync with SCEditor.
  // The library toggles its own iframe/source textarea; theme rules must not
  // reveal the inactive surface when switching modes.
  function syncEditorSurface(ta, editor) {
    var shell = ta && ta.__afAeShell;
    if (!shell || !editor || editor.__afAeSourceAdapter) return;
    var source = false;
    try {
      if (typeof editor.sourceMode === 'function') source = !!editor.sourceMode();
      else if (typeof editor.inSourceMode === 'function') source = !!editor.inSourceMode();
    } catch (e) { return; }
    shell.setAttribute('data-af-ae-active-surface', source ? 'source' : 'wysiwyg');
  }

  function bindEditorSurface(ta, editor) {
    if (!editor || editor.__afAeSourceAdapter) return;
    if (!editor.__afAeShellSurfaceBound) {
      editor.__afAeShellSurfaceBound = true;
      ['toggleSourceMode', 'sourceMode'].forEach(function (method) {
        var original = editor[method];
        if (typeof original !== 'function') return;
        editor[method] = function () {
          var result = original.apply(this, arguments);
          if (method === 'toggleSourceMode' || arguments.length > 0) syncEditorSurface(ta, this);
          return result;
        };
      });
    }
    syncEditorSurface(ta, editor);
  }

  function ensureWysiwyg(ta) {
    var editor = currentEditor(ta);
    if (editor && !editor.__afAeSourceAdapter) {
      bindEditorSurface(ta, editor);
      return editor;
    }
    if (!window.afAdvancedEditorWysiwyg) throw new Error('WYSIWYG runtime did not register');
    ta.__afAeRequestedMode = (P.cfg || {}).wysiwygMode === 'full' ? 'full' : 'partial';
    if (!window.afAdvancedEditorWysiwyg.init(ta)) throw new Error('WYSIWYG initialization failed');
    // The original textarea is a hidden data source after SCEditor activation.
    // The lightweight shell must never display it next to the editor widget.
    ta.setAttribute('data-af-ae-wys-active', '1');
    // SCEditor owns its own source textarea. Hide only the ORIGINAL field,
    // including when a theme overrides SCEditor's native visibility rules.
    if (!ta.__afAeOriginalDisplay) ta.__afAeOriginalDisplay = {
      value: ta.style.getPropertyValue('display'), priority: ta.style.getPropertyPriority('display')
    };
    ta.classList.add('af-ae-original-textarea');
    ta.style.setProperty('display', 'none', 'important');
    editor = currentEditor(ta);
    bindEditorSurface(ta, editor);
    applySurfaceMetrics(ta, ta.__afAeShell);
    try {
      if (editor && typeof editor.resizeTo === 'function' && ta.__afAeSurfaceMetrics) {
        editor.resizeTo('100%', ta.__afAeSurfaceMetrics.height);
      }
    } catch (e) {}
    return editor;
  }

  function activate(ta, b, caller) {
    var wrapper = ta.__afAeShell, editor = currentEditor(ta);
    if (!wrapper || !ta.isConnected) return Promise.resolve();
    var before = ta.value, start = ta.selectionStart, end = ta.selectionEnd;
    var capability = b.capability;
    var pending = capability ? loadCapability(capability) : Promise.resolve();
    caller.setAttribute('aria-busy', 'true');
    return pending.then(function () {
      if (!ta.isConnected || !ta.__afAeShell) return;
      var notice = wrapper.querySelector('.af-ae-shell-error'); if (notice) notice.remove();
      if (ta.value === before) ta.setSelectionRange(start, end);
      editor = currentEditor(ta);
      if (capability === 'wysiwyg') {
        if (editor && !editor.__afAeSourceAdapter) { editor.toggleSourceMode(); return; }
        ensureWysiwyg(ta);
        return;
      }
      if (capability && requiresCapability(capability, 'wysiwyg')) editor = ensureWysiwyg(ta);
      if (b.handler) return runHandler(editor, b, caller);
      // Source accepts BBCode; WYSIWYG must receive editable HTML, not
      // literal [align] strings (otherwise the tags appear in the iframe).
      if (!editor.__afAeSourceAdapter && /^(left|center|right|justify)$/.test(b.cmd)
          && typeof editor.sourceMode === 'function' && !editor.sourceMode()) {
        // Use the original Align pack: it edits selected block nodes and
        // registers the [align=...] HTML/BBCode round-trip converter.
        var handler = window.afAeBuiltinHandlers && window.afAeBuiltinHandlers[b.cmd];
        if (typeof handler === 'function' && handler(editor)) return;
        var commandName = {left:'justifyLeft', center:'justifyCenter',
          right:'justifyRight', justify:'justifyFull'}[b.cmd];
        try {
          var body = editor.getBody && editor.getBody();
          if (body && body.ownerDocument) {
            editor.focus();
            body.ownerDocument.execCommand(commandName, false, null);
          }
        } catch (e) {}
        return;
      }
      if (b.opentag || b.closetag) {
        // WYSIWYG inserts HTML for known visual commands; the SCEditor BBCode
        // plugin handles serialisation. Keep direct BBCode insertion in Source.
        if (!editor.__afAeSourceAdapter && typeof editor.sourceMode === 'function' &&
            !editor.sourceMode() && typeof editor.execCommand === 'function' &&
            /^(bold|italic|underline|strike)$/.test(b.cmd)) {
          editor.execCommand(b.cmd); return;
        }
        editor.insert(b.opentag || '', b.closetag || ''); return;
      }
      if (!editor.__afAeSourceAdapter && typeof editor.execCommand === 'function' && b.cmd !== 'maximize' && b.cmd !== 'af_formathelp') { editor.execCommand(b.cmd); return; }
      if (b.cmd === 'maximize') {
        var maximized = wrapper.classList.toggle('af-ae-shell-maximized');
        document.documentElement.classList.toggle('af-ae-shell-fullscreen-active', maximized);
        if (editor && !editor.__afAeSourceAdapter && typeof editor.resizeTo === 'function') {
          // The SCEditor container must track the shell, including its iframe.
          try { editor.resizeTo('100%', maximized ? Math.max(200, innerHeight - 95) : 180); } catch (e) {}
        }
        caller.setAttribute('aria-pressed', String(maximized));
        return;
      }
      if (b.cmd === 'undo' || b.cmd === 'redo') {
        if (!editor.__afAeSourceAdapter && editor.execCommand) editor.execCommand(b.cmd);
        else { var history = ta.__afAeHistory, next = history.index + (b.cmd === 'undo' ? -1 : 1);
          if (next >= 0 && next < history.values.length) { history.index = next; history.restoring = true; ta.value = history.values[next]; ta.dispatchEvent(new Event('input', { bubbles: true })); history.restoring = false; ta.focus(); } }
        return;
      }
      if (b.cmd === 'pastetext') { var text = window.prompt('Вставить текст', ''); if (text !== null) editor.insertText(text); return; }
      if (b.cmd === 'removeformat' || b.cmd === 'unlink') {
        var from = ta.selectionStart, to = ta.selectionEnd;
        var pattern = b.cmd === 'unlink' ? /\[\/?url(?:=[^\]]*)?\]/gi : /\[\/?[a-z][^\]]*\]/gi;
        ta.setRangeText(ta.value.slice(from, to).replace(pattern, ''), from, to, 'select');
        ta.dispatchEvent(new Event('input', { bubbles: true })); ta.focus(); return;
      }
      if (b.cmd === 'emoticon') { var smile = window.prompt('Смайл', ':)'); if (smile !== null) editor.insertText(smile); return; }
      if (b.cmd === 'af_formathelp') {
        var modal = document.createElement('div'); modal.className = 'sceditor-dropdown af-ae-format-help';
        modal.innerHTML = (P.formatHelp || {}).content || ''; editor.createDropDown(caller, 'af-ae-format-help', modal); return;
      }
      throw new Error('Command has no runtime: ' + b.cmd);
    }).catch(function (error) { report(wrapper, b, error); }).finally(function () { caller.removeAttribute('aria-busy'); });
  }

  function isQuickEdit(ta) {
    var match = String(ta.id || '').match(/^quickedit_(\d+)$/);
    if (!match || ta.name !== 'value' || !/(?:^|\/)showthread\.php$/.test(location.pathname)) return false;
    var host = ta.closest('#pid_' + match[1]), post = ta.closest('.post');
    return !!(host && post && ta.form && host.contains(ta.form) && post.contains(host));
  }
  function eligible(ta) {
    if (!ta || ta.tagName !== 'TEXTAREA' || ta.getAttribute('data-af-ae-skip') === '1') return false;
    // A native MyBB textarea may retain the SCEditor class even after its eager
    // runtime was stripped. Do not disable the lightweight editor for that class.
    if (ta.classList.contains('sceditor-textarea') && currentEditor(ta) && !currentEditor(ta).__afAeSourceAdapter) return false;
    return isQuickEdit(ta) || ta.matches((P.cfg || {}).editorSelector || 'textarea[name="message"]');
  }
  function captureSurfaceMetrics(ta) {
    if (!ta || ta.__afAeSurfaceMetrics) return;
    try {
      var cs = window.getComputedStyle(ta);
      var rect = ta.getBoundingClientRect();
      var height = Math.max(1, Math.round(rect.height || ta.offsetHeight || 180));
      ta.__afAeSurfaceMetrics = {
        height: height,
        fontFamily: cs.fontFamily || 'Verdana, Arial, Helvetica, sans-serif',
        fontSize: cs.fontSize || '14px',
        fontWeight: cs.fontWeight || '400',
        lineHeight: cs.lineHeight && cs.lineHeight !== 'normal' ? cs.lineHeight : '1.25',
        letterSpacing: cs.letterSpacing || 'normal'
      };
    } catch (e) {
      ta.__afAeSurfaceMetrics = {
        height: 180,
        fontFamily: 'Verdana, Arial, Helvetica, sans-serif',
        fontSize: '14px',
        fontWeight: '400',
        lineHeight: '1.25',
        letterSpacing: 'normal'
      };
    }
  }

  function applySurfaceMetrics(ta, wrapper) {
    var m = ta && ta.__afAeSurfaceMetrics;
    if (!m || !wrapper) return;
    wrapper.style.setProperty('--af-ae-surface-height', m.height + 'px');
    wrapper.style.setProperty('--af-ae-surface-font-family', m.fontFamily);
    wrapper.style.setProperty('--af-ae-surface-font-size', m.fontSize);
    wrapper.style.setProperty('--af-ae-surface-font-weight', m.fontWeight);
    wrapper.style.setProperty('--af-ae-surface-line-height', m.lineHeight);
    wrapper.style.setProperty('--af-ae-surface-letter-spacing', m.letterSpacing);
  }

  function init(ta) {
    if (!eligible(ta) || ta.__afAeShell) return;
    captureSurfaceMetrics(ta);
    var wrapper = ta.parentElement;
    if (!wrapper || !wrapper.hasAttribute('data-af-editor-shell')) {
      wrapper = document.createElement('div'); wrapper.className = 'af-ae-shell'; wrapper.setAttribute('data-af-editor-shell', '1');
      wrapper.innerHTML = P.shellToolbar || '';
      ta.parentNode.insertBefore(wrapper, ta); wrapper.appendChild(ta);
      if (P.counterHtml) wrapper.insertAdjacentHTML('beforebegin', P.counterHtml);
    }
    applySurfaceMetrics(ta, wrapper);
    ta.__afAeShellAbort = new AbortController();
    var signal = ta.__afAeShellAbort.signal;
    ta.__afAeHistory = { values: [ta.value], index: 0, restoring: false };
    ta.addEventListener('input', function () {
      var h = ta.__afAeHistory; if (!h || h.restoring || h.values[h.index] === ta.value) return;
      h.values.splice(h.index + 1); h.values.push(ta.value); if (h.values.length > 100) h.values.shift(); h.index = h.values.length - 1;
    }, { signal: signal });
    ta.__afAeShell = wrapper; ta.__afAeAdapter = adapter(ta, wrapper);
    setupDraft(ta, signal);
    wrapper.__afAeTextarea = ta;
    ta.__afAeForm = ta.form; ta.__afAePost = ta.closest('.post');
    var counter = wrapper.previousElementSibling;
    if (counter && counter.classList.contains('af-ccp-wrap')) {
      var update = function () {
        var text = currentEditor(ta).val();
        if (!P.countBbcode) text = text.replace(/\[mask\b[\s\S]*?\[\/mask\]/gi, '').replace(/\[img\b[^\]]*\][\s\S]*?\[\/img\]/gi, '').replace(/\[(\/?)[^\]\s=]+(?:=[^\]]+)?\]/g, '');
        counter.querySelector('.af-ccp-value').textContent = String(Array.from(text).length);
      };
      ta.addEventListener('input', update, { signal: signal }); update(); ta.__afAeCountUpdate = update;
    }
    if (ta.form && !ta.form.__afAeTriggersBound) {
      ta.form.__afAeTriggersBound = true;
      ta.form.addEventListener('click', function (e) {
        var target = e.target.closest('button, input[type=submit]'); if (!target || !target.name) return;
        var capability = Object.keys(registry).find(function (id) { return (registry[id].triggers || []).indexOf(target.name) !== -1; });
        if (!capability || (states[capability] && states[capability].state === 'loaded')) return;
        e.preventDefault(); e.stopImmediatePropagation();
        loadCapability(capability).then(function () {
          if (!ta.isConnected || !target.isConnected) return;
          document.dispatchEvent(new CustomEvent('af:capability-activate', { detail: { capability: capability, textarea: ta, instance: currentEditor(ta) } }));
          target.click();
        }).catch(function (error) { report(wrapper, {cmd: capability, title: target.name}, error); });
      }, { capture: true, signal: signal });
    }
    // External addons publish metadata under their own frontend permission gate.
    (window.afAeButtons || []).forEach(function (b) {
      if (b.cmd === 'af_formathelp' || wrapper.querySelector('[data-af-command="' + b.cmd + '"]')) return;
      var a = document.createElement('a'); a.href = '#'; a.className = 'sceditor-button sceditor-button-' + b.cmd;
      a.setAttribute('role', 'button'); a.setAttribute('data-af-command', b.cmd); a.title = b.title; a.setAttribute('aria-label', b.title);
      var visual = document.createElement('div'); visual.textContent = b.label || b.title; a.appendChild(visual);
      wrapper.querySelector('.sceditor-toolbar').appendChild(a);
    });
    wrapper.addEventListener('mousedown', function (e) { if (e.target.closest('[data-af-command]')) e.preventDefault(); });
    wrapper.addEventListener('click', function (e) {
      var caller = e.target.closest('[data-af-command]'); if (!caller || !wrapper.contains(caller)) return;
      e.preventDefault(); var cmd = caller.getAttribute('data-af-command');
      if (/^af_menu_dropdown\d+$/.test(cmd)) {
        var menu = wrapper.querySelector('[data-af-menu="' + cmd + '"]');
        if (menu) {
          var opening = menu.hidden;
          wrapper.querySelectorAll('.af-ae-shell-menu').forEach(function (other) { other.hidden = true; });
          menu.hidden = !opening;
          caller.setAttribute('aria-expanded', String(opening));
          if (opening) {
            var rect = caller.getBoundingClientRect();
            menu.style.left = Math.max(8, Math.min(rect.left, innerWidth - 260)) + 'px';
            menu.style.top = (rect.bottom + 260 > innerHeight && rect.top > 270
              ? Math.max(8, rect.top - Math.min(260, menu.scrollHeight || 260))
              : rect.bottom + 4) + 'px';
          }
        }
        return;
      }
      wrapper.querySelectorAll('.af-ae-shell-menu').forEach(function (menu) { menu.hidden = true; });
      if (buttons[cmd]) activate(ta, buttons[cmd], caller);
    });
    var quick = isQuickEdit(ta);
    if (quick) {
      ta.style.width = '100%'; ta.style.maxWidth = '100%'; ta.style.boxSizing = 'border-box';
      ta.form.classList.add('atf-editor', 'atf-editor--quick-edit', 'atf-quick-edit');
      var count = ta.closest('.post').querySelector('.af-ccp-postcount');
      if (count && !count.hasAttribute('data-af-ae-was-hidden')) { count.setAttribute('data-af-ae-was-hidden', count.hidden ? '1' : '0'); count.hidden = true; }
    }
    document.dispatchEvent(new CustomEvent('af:editor-ready', { detail: { textarea: ta, instance: ta.__afAeAdapter, quickEdit: quick } }));
  }
  function destroy(ta) {
    if (!ta.__afAeShell) return;
    var wrapper = ta.__afAeShell;
    if (ta.__afAeShellAbort) ta.__afAeShellAbort.abort();
    wrapper.removeAttribute('data-af-ae-active-surface');
    ta.removeAttribute('data-af-ae-wys-active');
    if (ta.__afAeOriginalDisplay) {
      ta.style.setProperty('display', ta.__afAeOriginalDisplay.value, ta.__afAeOriginalDisplay.priority);
      ta.__afAeOriginalDisplay = null;
    }
    ta.classList.remove('af-ae-original-textarea');
    var post = ta.__afAePost || wrapper.closest('.post');
    if (post) { var count = post.querySelector('.af-ccp-postcount[data-af-ae-was-hidden]'); if (count) { count.hidden = count.getAttribute('data-af-ae-was-hidden') === '1'; count.removeAttribute('data-af-ae-was-hidden'); } }
    var editor = currentEditor(ta); if (editor && typeof editor.destroy === 'function') editor.destroy();
    if (ta.__afAeAdapter && editor !== ta.__afAeAdapter) ta.__afAeAdapter.destroy();
    document.dispatchEvent(new CustomEvent('af:editor-destroyed', { detail: { textarea: ta } }));
    if (ta.__afAeForm) { ta.__afAeForm.__afAeTriggersBound = false; ta.__afAeForm.classList.remove('atf-editor', 'atf-editor--quick-edit', 'atf-quick-edit'); }
    ta.__afAeReadyAnnounced = ta.__afAeInited = false;
    ta.__afAeShell = ta.__afAeAdapter = ta.__afAeHistory = ta.__afAeForm = ta.__afAePost = null;
  }
  function scan(root, action) {
    if (root.nodeType === 1 && root.tagName === 'TEXTAREA') action(root);
    if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll('textarea'), action);
  }
  function boot() {
    scan(document, init);
    var observer = new MutationObserver(function (records) {
      records.forEach(function (record) {
        if (record.type === 'attributes') { init(record.target); return; }
        Array.prototype.forEach.call(record.addedNodes, function (node) { if (node.nodeType === 1) scan(node, init); });
        Array.prototype.forEach.call(record.removedNodes, function (node) { if (node.nodeType === 1) scan(node, function (ta) { if (!ta.isConnected) destroy(ta); }); });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['id'] });
    document.addEventListener('mousedown', function (e) { document.querySelectorAll('.af-ae-shell').forEach(function (wrapper) { if (!wrapper.contains(e.target) && !e.target.closest('.sceditor-dropdown')) { var ta = wrapper.__afAeTextarea; if (ta && ta.__afAeAdapter) ta.__afAeAdapter.closeDropDown(); } }); });
    document.addEventListener('af:editor-ready', function(e) {
      var detail = e.detail || {}; if (detail.instance && !detail.instance.__afAeSourceAdapter && detail.textarea.__afAeCountUpdate) detail.instance.bind('valuechanged', detail.textarea.__afAeCountUpdate);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.af-ae-shell').forEach(function (w) { if (w.__afAeTextarea) w.__afAeTextarea.__afAeAdapter.closeDropDown(); }); });
    Object.keys(registry).forEach(function (id) { if (registry[id].activation === 'initial') loadCapability(id).catch(function (error) { console.warn('[AdvancedEditor]', error); }); });
  }
  window.afAdvancedEditorShell = { draftsManaged: true, loadCapability: loadCapability, states: states, registry: registry, insert: insert, init: init, destroy: destroy, activate: activate };
  window.afAeIsSourceMode = function (ed) { return !!(ed && typeof ed.inSourceMode === 'function' && ed.inSourceMode()); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window, document);
