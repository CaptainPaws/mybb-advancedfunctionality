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
        popup.style.position = 'absolute'; popup.style.zIndex = '2147483000';
        popup.style.left = Math.max(8, Math.min(bounds.left + window.scrollX, window.scrollX + window.innerWidth - popup.offsetWidth - 8)) + 'px';
        popup.style.top = bounds.bottom + window.scrollY + 'px';
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

  function ensureWysiwyg(ta) {
    var editor = currentEditor(ta);
    if (editor && !editor.__afAeSourceAdapter) return editor;
    if (!window.afAdvancedEditorWysiwyg) throw new Error('WYSIWYG runtime did not register');
    ta.__afAeRequestedMode = (P.cfg || {}).wysiwygMode === 'full' ? 'full' : 'partial';
    if (!window.afAdvancedEditorWysiwyg.init(ta)) throw new Error('WYSIWYG initialization failed');
    return currentEditor(ta);
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
      if (b.opentag || b.closetag) { editor.insert(b.opentag || '', b.closetag || ''); return; }
      if (!editor.__afAeSourceAdapter && typeof editor.execCommand === 'function' && b.cmd !== 'maximize' && b.cmd !== 'af_formathelp') { editor.execCommand(b.cmd); return; }
      if (b.cmd === 'maximize') { wrapper.classList.toggle('af-ae-shell-maximized'); return; }
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
  function init(ta) {
    if (!eligible(ta) || ta.__afAeShell) return;
    var wrapper = ta.parentElement;
    if (!wrapper || !wrapper.hasAttribute('data-af-editor-shell')) {
      wrapper = document.createElement('div'); wrapper.className = 'sceditor-container af-ae-shell'; wrapper.setAttribute('data-af-editor-shell', '1');
      wrapper.innerHTML = P.shellToolbar || '';
      ta.parentNode.insertBefore(wrapper, ta); wrapper.appendChild(ta);
      if (P.counterHtml) wrapper.insertAdjacentHTML('beforebegin', P.counterHtml);
    }
    ta.__afAeShellAbort = new AbortController();
    var signal = ta.__afAeShellAbort.signal;
    ta.__afAeHistory = { values: [ta.value], index: 0, restoring: false };
    ta.addEventListener('input', function () {
      var h = ta.__afAeHistory; if (!h || h.restoring || h.values[h.index] === ta.value) return;
      h.values.splice(h.index + 1); h.values.push(ta.value); if (h.values.length > 100) h.values.shift(); h.index = h.values.length - 1;
    }, { signal: signal });
    ta.__afAeShell = wrapper; ta.__afAeAdapter = adapter(ta, wrapper);
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
      if (wrapper.querySelector('[data-af-command="' + b.cmd + '"]')) return;
      var a = document.createElement('a'); a.href = '#'; a.className = 'sceditor-button sceditor-button-' + b.cmd;
      a.setAttribute('role', 'button'); a.setAttribute('data-af-command', b.cmd); a.title = b.title; a.setAttribute('aria-label', b.title);
      var visual = document.createElement('div'); visual.textContent = b.label || b.title; a.appendChild(visual);
      wrapper.querySelector('.sceditor-toolbar').appendChild(a);
    });
    wrapper.addEventListener('mousedown', function (e) { if (e.target.closest('[data-af-command]')) e.preventDefault(); });
    wrapper.addEventListener('click', function (e) {
      var caller = e.target.closest('[data-af-command]'); if (!caller || !wrapper.contains(caller)) return;
      e.preventDefault(); var cmd = caller.getAttribute('data-af-command');
      if (/^af_menu_dropdown\d+$/.test(cmd)) { var menu = wrapper.querySelector('[data-af-menu="' + cmd + '"]'); if (menu) { menu.hidden = !menu.hidden; caller.setAttribute('aria-expanded', String(!menu.hidden)); } return; }
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
  window.afAdvancedEditorShell = { loadCapability: loadCapability, states: states, registry: registry, insert: insert, init: init, destroy: destroy, activate: activate };
  window.afAeIsSourceMode = function (ed) { return !!(ed && typeof ed.inSourceMode === 'function' && ed.inSourceMode()); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window, document);
